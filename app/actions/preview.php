<?php
/**
 * Preview handler for model files
 * Serves files inline for 3D viewer previews
 * This is needed when direct asset access is blocked by server config
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

// Require authentication
if (!isLoggedIn()) {
    http_response_code(401);
    exit('Not authenticated');
}

$partId = (int)($_GET['id'] ?? 0);

if (!$partId) {
    http_response_code(400);
    exit('Invalid request');
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part) {
    http_response_code(404);
    exit('File not found');
}

// Check ownership - user must own the model or be admin
// Models with NULL user_id are accessible to all authenticated users (backward compatibility)
$user = getCurrentUser();
$ownerId = $part['user_id'] ?? null;

// If this is a child part, check the parent model's ownership
if ($part['parent_id']) {
    $parentStmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
    $parentStmt->bindValue(':id', $part['parent_id'], PDO::PARAM_INT);
    $parentResult = $parentStmt->execute();
    $parentModel = $parentResult->fetchArray(PDO::FETCH_ASSOC);
    if ($parentModel) {
        $ownerId = $parentModel['user_id'] ?? null;
    }
}

// Deny access if model has an owner and current user is not the owner or admin
// Cast to int to handle PDO returning strings depending on configuration
if ($ownerId !== null && (int)$ownerId !== (int)$user['id'] && !isAdmin()) {
    http_response_code(403);
    exit('Access denied');
}

// Get the real file path (handles deduplicated files)
$filePath = getAbsoluteFilePath($part);

// Plugin hook: preview_file_path - S3/remote storage for 3D viewer file serving
if (class_exists('PluginManager')) {
    $filePath = PluginManager::applyFilter('preview_file_path', $filePath, $part);
}

if (!$filePath || !is_file($filePath)) {
    http_response_code(404);
    exit('File not found on disk');
}

// Get the original filename and sanitize for header use
$filename = $part['filename'] ?? basename($part['file_path']);
// Remove any characters that could cause header injection
$filename = preg_replace('/[\r\n\t"\\\\]/', '', $filename);

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

// CORS headers for cross-origin requests (configurable via settings)
$allowedOrigins = getSetting('cors_allowed_origins', '');
if (!empty($allowedOrigins)) {
    // Check if the request origin is in the allowed list
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedList = array_map('trim', explode(',', $allowedOrigins));

    if ($origin && in_array($origin, $allowedList, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, HEAD');
        header('Vary: Origin');
    } elseif (in_array('*', $allowedList, true)) {
        // Explicit wildcard configuration
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD');
    }
    // If origin not in list and no wildcard, don't send CORS headers (same-origin only)
} else {
    // Default: same-origin only (no CORS headers sent)
}

// Only send body for GET requests (not HEAD)
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    // Use X-Accel-Redirect in Docker (nginx serves the file directly, bypassing PHP)
    if (getenv('MESHSILO_DOCKER') === 'true' && defined('UPLOAD_PATH')) {
        $relativePath = str_replace(realpath(UPLOAD_PATH), '', realpath($filePath));
        header('X-Accel-Redirect: /assets' . $relativePath);
    } else {
        readfile($filePath);
    }
}
exit;
