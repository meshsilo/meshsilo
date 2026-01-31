<?php
/**
 * Preview handler for model files
 * Serves files inline for 3D viewer previews
 * This is needed when direct asset access is blocked by server config
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

$partId = (int)($_GET['id'] ?? 0);

if (!$partId) {
    http_response_code(400);
    die('Invalid request');
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part) {
    http_response_code(404);
    die('File not found');
}

// Get the real file path (handles deduplicated files)
$filePath = getAbsoluteFilePath($part);

if (!$filePath || !is_file($filePath)) {
    http_response_code(404);
    die('File not found on disk');
}

// Get the original filename
$filename = $part['filename'] ?? basename($part['file_path']);

// Determine content type
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentTypes = [
    'stl' => 'model/stl',
    '3mf' => 'model/3mf',
    'obj' => 'model/obj',
    'ply' => 'model/ply',
    'gltf' => 'model/gltf+json',
    'glb' => 'model/gltf-binary',
    'fbx' => 'application/octet-stream',
    'dae' => 'model/vnd.collada+xml',
    '3ds' => 'application/x-3ds',
    'step' => 'model/step',
    'stp' => 'model/step',
];
$contentType = $contentTypes[$extension] ?? 'application/octet-stream';

// Enable caching - use file size as version
$etag = md5($part['id'] . '-' . $part['file_size']);
$lastModified = strtotime($part['updated_at'] ?? $part['created_at']);

// Check if client has cached version
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}

if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastModified) {
    http_response_code(304);
    exit;
}

// Send file inline (not as attachment)
header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: public, max-age=604800'); // 1 week cache
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');

// CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD');

readfile($filePath);
exit;
