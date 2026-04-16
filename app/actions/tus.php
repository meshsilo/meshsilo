<?php
/**
 * Tus Protocol Action Handler
 *
 * Routes tus protocol HTTP methods to the TusServer class.
 * Handles authentication and response sending.
 */

require_once __DIR__ . '/../../includes/TusServer.php';

// Auth check — every tus request must have a valid session
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Permission check
requirePermission(PERM_UPLOAD);

// Ensure staging directory exists
$tusDir = dirname(__DIR__, 2) . '/storage/uploads/tus';
if (!is_dir($tusDir)) {
    mkdir($tusDir, 0755, true);
}

$server = new TusServer($tusDir);
$method = $_SERVER['REQUEST_METHOD'];

// Extract upload ID from URL path (e.g., /actions/tus/abc123def456)
$uploadId = $_GET['id'] ?? null;

// Base URL for Location headers
$baseUrl = '/actions/tus';

$response = null;

switch ($method) {
    case 'OPTIONS':
        $response = $server->handleOptions();
        break;

    case 'POST':
        // Create new upload
        $uploadLength = (int)($_SERVER['HTTP_UPLOAD_LENGTH'] ?? 0);

        // Parse Upload-Metadata header (key base64val,key base64val,...)
        $metadata = [];
        $metadataHeader = $_SERVER['HTTP_UPLOAD_METADATA'] ?? '';
        if ($metadataHeader) {
            foreach (explode(',', $metadataHeader) as $pair) {
                $pair = trim($pair);
                $parts = explode(' ', $pair, 2);
                $key = $parts[0];
                $value = $parts[1] ?? '';
                $metadata[$key] = $value;
            }
        }

        $maxFileSize = defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 0;
        $response = $server->handleCreate($uploadLength, $metadata, $baseUrl, $maxFileSize);
        break;

    case 'PATCH':
        if (!$uploadId) {
            $response = ['status' => 400, 'headers' => [], 'body' => 'Missing upload ID'];
            break;
        }

        $offset = (int)($_SERVER['HTTP_UPLOAD_OFFSET'] ?? 0);
        $data = file_get_contents('php://input');
        $response = $server->handlePatch($uploadId, $data, $offset);

        // If upload is complete, dispatch background processing
        if (!empty($response['complete'])) {
            dispatchProcessing($server, $response['upload_id']);
        }
        break;

    case 'HEAD':
        if (!$uploadId) {
            $response = ['status' => 400, 'headers' => [], 'body' => 'Missing upload ID'];
            break;
        }
        $response = $server->handleHead($uploadId);
        break;

    case 'DELETE':
        if (!$uploadId) {
            $response = ['status' => 400, 'headers' => [], 'body' => 'Missing upload ID'];
            break;
        }
        $response = $server->handleDelete($uploadId);
        break;

    default:
        $response = ['status' => 405, 'headers' => [], 'body' => 'Method not allowed'];
        break;
}

// Send response
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header("$name: $value");
}

// For completed uploads, return JSON with model_id
if (!empty($response['complete'])) {
    header('Content-Type: application/json');
    echo json_encode(['model_id' => $GLOBALS['_tus_model_id'] ?? null]);
} elseif (!empty($response['body'])) {
    echo $response['body'];
}

exit;

/**
 * Dispatch background processing for a completed upload.
 *
 * Creates a parent model row (pending_upload) and queues the ProcessUpload job.
 * This function is defined here rather than in TusServer to keep TusServer
 * dependency-free (no DB, no Queue).
 */
function dispatchProcessing(TusServer $server, string $uploadId): void
{
    $info = $server->getUploadInfo($uploadId);
    if (!$info) return;

    $metadata = $info['metadata'] ?? [];
    $filename = isset($metadata['filename']) ? base64_decode($metadata['filename']) : 'unknown';

    $db = getDB();

    // Decode metadata
    $modelName = isset($metadata['name']) ? base64_decode($metadata['name']) : pathinfo($filename, PATHINFO_FILENAME);
    $description = isset($metadata['description']) ? base64_decode($metadata['description']) : '';
    $creator = isset($metadata['creator']) ? base64_decode($metadata['creator']) : '';
    $collection = isset($metadata['collection']) ? base64_decode($metadata['collection']) : '';
    $sourceUrl = isset($metadata['source_url']) ? base64_decode($metadata['source_url']) : '';
    $categories = isset($metadata['categories']) ? json_decode(base64_decode($metadata['categories']), true) : [];

    // Create parent model row with pending_upload status
    $folderId = uniqid();
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $fileType = $extension === 'zip' ? 'parent' : $extension;

    $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, description, creator, collection, source_url, part_count, upload_status, user_id) VALUES (:name, :filename, :file_path, :file_size, :file_type, :description, :creator, :collection, :source_url, 0, :upload_status, :user_id)');
    $stmt->bindValue(':name', $modelName, PDO::PARAM_STR);
    $stmt->bindValue(':filename', $folderId, PDO::PARAM_STR);
    $stmt->bindValue(':file_path', 'assets/' . $folderId, PDO::PARAM_STR);
    $stmt->bindValue(':file_size', $info['length'], PDO::PARAM_INT);
    $stmt->bindValue(':file_type', $fileType, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    $stmt->bindValue(':creator', $creator, PDO::PARAM_STR);
    $stmt->bindValue(':collection', $collection, PDO::PARAM_STR);
    $stmt->bindValue(':source_url', $sourceUrl, PDO::PARAM_STR);
    $stmt->bindValue(':upload_status', 'pending_upload', PDO::PARAM_STR);
    $stmt->bindValue(':user_id', getCurrentUser()['id'] ?? null, PDO::PARAM_INT);
    $stmt->execute();

    $modelId = (int)$db->lastInsertRowID();

    // Link categories
    if (!empty($categories)) {
        foreach ($categories as $catId) {
            $catStmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:mid, :cid)');
            $catStmt->bindValue(':mid', $modelId, PDO::PARAM_INT);
            $catStmt->bindValue(':cid', (int)$catId, PDO::PARAM_INT);
            $catStmt->execute();
        }
    }

    // Add collection if new
    if (!empty($collection)) {
        try {
            $stmt = $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (:name)');
            $stmt->bindValue(':name', $collection, PDO::PARAM_STR);
            $stmt->execute();
        } catch (\Exception $e) {
            // Ignore
        }
    }

    // Queue the processing job
    require_once dirname(__DIR__, 2) . '/includes/Queue.php';
    Queue::push('ProcessUpload', [
        'upload_id' => $uploadId,
        'model_id' => $modelId,
        'filename' => $filename,
        'folder_id' => $folderId,
        'user_id' => getCurrentUser()['id'] ?? null,
    ], 'uploads', maxAttempts: 2);

    logInfo('Tus upload complete, processing queued', [
        'upload_id' => $uploadId,
        'model_id' => $modelId,
        'filename' => $filename,
    ]);

    // Store model_id in response for the frontend redirect
    $GLOBALS['_tus_model_id'] = $modelId;
}
