<?php
/**
 * Bulk Upload Actions
 * Upload multiple files as separate models
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if (!hasPermission(PERM_UPLOAD)) {
    echo json_encode(['success' => false, 'error' => 'Upload permission required']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? '';

// CSRF validation
if (!Csrf::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

switch ($action) {
    case 'upload':
        bulkUpload();
        break;
    case 'validate':
        validateFiles();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Upload multiple files as separate models
 */
function bulkUpload() {
    global $user;

    if (empty($_FILES['files'])) {
        echo json_encode(['success' => false, 'error' => 'No files uploaded']);
        return;
    }

    $files = $_FILES['files'];
    $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
    $tags = $_POST['tags'] ?? '';
    $collection = trim($_POST['collection'] ?? '');

    // Parse tags
    $tagNames = [];
    if ($tags) {
        $tagNames = array_map('trim', explode(',', $tags));
    }

    $results = [
        'success' => [],
        'failed' => [],
        'total' => count($files['name'])
    ];

    $db = getDB();
    $allowedExtensions = getAllowedExtensions();

    for ($i = 0; $i < count($files['name']); $i++) {
        $filename = $files['name'][$i];
        $tmpPath = $files['tmp_name'][$i];
        $error = $files['error'][$i];
        $size = $files['size'][$i];

        // Check for upload errors
        if ($error !== UPLOAD_ERR_OK) {
            $results['failed'][] = [
                'filename' => $filename,
                'error' => getUploadErrorMessage($error)
            ];
            continue;
        }

        // Validate extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            $results['failed'][] = [
                'filename' => $filename,
                'error' => 'File type not allowed'
            ];
            continue;
        }

        // Create unique folder
        $folderId = uniqid();
        $uploadDir = UPLOAD_PATH . $folderId;
        if (!mkdir($uploadDir, 0755, true)) {
            $results['failed'][] = [
                'filename' => $filename,
                'error' => 'Could not create upload directory'
            ];
            continue;
        }

        // Move file
        $destPath = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            rmdir($uploadDir);
            $results['failed'][] = [
                'filename' => $filename,
                'error' => 'Failed to save file'
            ];
            continue;
        }

        // Calculate hash
        $fileHash = hash_file('sha256', $destPath);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Insert into database
        try {
            $stmt = $db->prepare('
                INSERT INTO models (name, filename, file_path, file_size, file_type, file_hash,
                                   original_size, collection, uploaded_by, created_at, updated_at)
                VALUES (:name, :filename, :file_path, :file_size, :file_type, :file_hash,
                        :original_size, :collection, :uploaded_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                ':name' => $name,
                ':filename' => $filename,
                ':file_path' => $folderId . '/' . $filename,
                ':file_size' => $size,
                ':file_type' => $extension,
                ':file_hash' => $fileHash,
                ':original_size' => $size,
                ':collection' => $collection ?: null,
                ':uploaded_by' => $user['id']
            ]);

            $modelId = $db->lastInsertId();

            // Add category
            if ($categoryId) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                $stmt->execute([':model_id' => $modelId, ':category_id' => $categoryId]);
            }

            // Add tags
            foreach ($tagNames as $tagName) {
                if (!empty($tagName)) {
                    $tagId = getOrCreateTag($tagName);
                    if ($tagId) {
                        addTagToModel($modelId, $tagId);
                    }
                }
            }

            logActivity('upload', 'model', $modelId, $name, ['via' => 'bulk_upload']);

            // Trigger webhook
            triggerWebhook('model.created', [
                'model_id' => $modelId,
                'name' => $name,
                'file_type' => $extension,
                'file_size' => $size
            ]);

            $results['success'][] = [
                'filename' => $filename,
                'model_id' => $modelId,
                'name' => $name
            ];

        } catch (Exception $e) {
            // Clean up on failure
            unlink($destPath);
            rmdir($uploadDir);
            logException($e, ['action' => 'bulk_upload', 'filename' => $filename]);

            $results['failed'][] = [
                'filename' => $filename,
                'error' => 'Failed to save. Please try again.'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
        'uploaded' => count($results['success']),
        'failed' => count($results['failed'])
    ]);
}

/**
 * Validate files before upload
 */
function validateFiles() {
    if (empty($_FILES['files'])) {
        echo json_encode(['success' => false, 'error' => 'No files provided']);
        return;
    }

    $files = $_FILES['files'];
    $allowedExtensions = getAllowedExtensions();
    $maxSize = getMaxUploadSize();

    $results = [];
    for ($i = 0; $i < count($files['name']); $i++) {
        $filename = $files['name'][$i];
        $size = $files['size'][$i];
        $error = $files['error'][$i];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $valid = true;
        $issues = [];

        if ($error !== UPLOAD_ERR_OK) {
            $valid = false;
            $issues[] = getUploadErrorMessage($error);
        }

        if (!in_array($extension, $allowedExtensions)) {
            $valid = false;
            $issues[] = 'File type not allowed';
        }

        if ($size > $maxSize) {
            $valid = false;
            $issues[] = 'File too large (max ' . formatBytes($maxSize) . ')';
        }

        $results[] = [
            'filename' => $filename,
            'size' => $size,
            'extension' => $extension,
            'valid' => $valid,
            'issues' => $issues
        ];
    }

    echo json_encode([
        'success' => true,
        'files' => $results,
        'valid_count' => count(array_filter($results, fn($r) => $r['valid'])),
        'invalid_count' => count(array_filter($results, fn($r) => !$r['valid']))
    ]);
}

function getUploadErrorMessage($error) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
        UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
    ];
    return $messages[$error] ?? 'Unknown upload error';
}

function getMaxUploadSize() {
    $maxUpload = convertToBytes(ini_get('upload_max_filesize'));
    $maxPost = convertToBytes(ini_get('post_max_size'));
    return min($maxUpload, $maxPost);
}

// convertToBytes and formatBytes are defined in includes/helpers.php
