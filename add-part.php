<?php
/**
 * Add parts to an existing model
 */
require_once 'includes/config.php';
require_once 'includes/dedup.php';

// Require upload permission
if (!canUpload()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get model ID
$modelId = (int)($_POST['model_id'] ?? $_GET['model_id'] ?? 0);

if (!$modelId) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        exit;
    }
    header('Location: index.php');
    exit;
}

// Verify model exists and is a parent model
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Model not found']);
        exit;
    }
    header('Location: index.php');
    exit;
}

// Handle AJAX upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['part_file'])) {
    header('Content-Type: application/json');

    $file = $_FILES['part_file'];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];
        echo json_encode(['success' => false, 'error' => $errors[$file['error']] ?? 'Upload failed']);
        exit;
    }

    // Validate file extension
    $allowedExtensions = array_map('trim', explode(',', getSetting('allowed_extensions', 'stl,3mf')));
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExtensions)]);
        exit;
    }

    // Create directory for model if it doesn't exist
    $modelDir = 'assets/' . substr(md5($model['name'] . $model['id']), 0, 12);
    if (!file_exists($modelDir)) {
        mkdir($modelDir, 0755, true);
    }

    // Generate unique filename
    $filename = $file['name'];
    $baseName = pathinfo($filename, PATHINFO_FILENAME);
    $destPath = $modelDir . '/' . $filename;

    // Handle filename collision
    $counter = 1;
    while (file_exists($destPath)) {
        $destPath = $modelDir . '/' . $baseName . '_' . $counter . '.' . $extension;
        $counter++;
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
        exit;
    }

    // Calculate file hash
    $fileHash = null;
    if ($extension === '3mf') {
        $fileHash = calculate3mfContentHash($destPath);
    }
    if (!$fileHash) {
        $fileHash = hash_file('sha256', $destPath);
    }

    // Insert part into database
    try {
        $stmt = $db->prepare('
            INSERT INTO models (name, filename, file_path, file_size, file_type, parent_id, file_hash, created_at)
            VALUES (:name, :filename, :file_path, :file_size, :file_type, :parent_id, :file_hash, datetime("now"))
        ');
        $stmt->bindValue(':name', pathinfo($filename, PATHINFO_FILENAME), SQLITE3_TEXT);
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':file_path', $destPath, SQLITE3_TEXT);
        $stmt->bindValue(':file_size', filesize($destPath), SQLITE3_INTEGER);
        $stmt->bindValue(':file_type', $extension, SQLITE3_TEXT);
        $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
        $stmt->bindValue(':file_hash', $fileHash, SQLITE3_TEXT);
        $stmt->execute();

        $partId = $db->lastInsertRowID();

        // Update parent model's part count
        $stmt = $db->prepare('UPDATE models SET part_count = (SELECT COUNT(*) FROM models WHERE parent_id = :id) WHERE id = :id');
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
        $stmt->execute();

        // Get new part count
        $stmt = $db->prepare('SELECT part_count FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
        $newPartCount = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['part_count'];

        logUpload('Part added to model', [
            'model_id' => $modelId,
            'model_name' => $model['name'],
            'part_id' => $partId,
            'filename' => $filename,
            'size' => filesize($destPath)
        ]);

        echo json_encode([
            'success' => true,
            'part_id' => $partId,
            'filename' => $filename,
            'file_type' => $extension,
            'file_size' => filesize($destPath),
            'part_count' => $newPartCount
        ]);
    } catch (Exception $e) {
        // Clean up file on error
        @unlink($destPath);
        logException($e, ['action' => 'add_part', 'model_id' => $modelId]);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// If not POST, redirect to model page
header('Location: model.php?id=' . $modelId);
exit;
