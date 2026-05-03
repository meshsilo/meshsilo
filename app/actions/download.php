<?php
/**
 * Download handler for model files
 * Handles both regular and deduplicated files
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

// Helper function for error responses
function downloadError(int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// Require authentication
if (!isLoggedIn()) {
    downloadError(401, 'Authentication required');
}

$partId = (int)($_GET['id'] ?? 0);

if (!$partId) {
    downloadError(400, 'Invalid request: missing file ID');
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, PDO::PARAM_INT);
$result = $stmt->execute();
$part = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$part) {
    downloadError(404, 'File not found');
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
    downloadError(403, 'Access denied');
}

// Plugin hook: before_download - access control, quota checks, download analytics
if (class_exists('PluginManager')) {
    $allowed = PluginManager::applyFilter('before_download', true, $part, getCurrentUser());
    if ($allowed !== true) {
        http_response_code(403);
        echo is_string($allowed) ? $allowed : 'Download blocked';
        exit;
    }
}

// Get the real file path (handles deduplicated files)
$filePath = getAbsoluteFilePath($part);

// Plugin hook: download_file_path - S3/remote storage, CDN integration
if (class_exists('PluginManager')) {
    $filePath = PluginManager::applyFilter('download_file_path', $filePath, $part);
}

if (!$filePath || !is_file($filePath)) {
    logError('Download failed: file not found on disk', [
        'part_id' => $partId,
        'expected_path' => $part['file_path'] ?? 'unknown'
    ]);
    downloadError(404, 'File not found on disk');
}

// Get the original filename for download and sanitize for header use
$filename = $part['filename'] ?? basename($part['file_path']);
// Sanitize filename: use basename to prevent path traversal, then remove header injection chars
$filename = basename($filename);
$filename = preg_replace('/[\r\n\t"\\\\]/', '', $filename);
// Replace any remaining special characters
$filename = preg_replace('/[^\x20-\x7E]/', '_', $filename);

// Determine content type
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentTypes = [
    'stl' => 'model/stl',
    '3mf' => 'model/3mf',
    'obj' => 'model/obj',
    'step' => 'model/step',
    'stp' => 'model/step',
    'zip' => 'application/zip'
];
$contentType = $contentTypes[$extension] ?? 'application/octet-stream';

// Increment download count (part + parent in single query when applicable)
if ($part['parent_id']) {
    try {
        $db->prepare('UPDATE models SET download_count = download_count + 1 WHERE id IN (:id1, :id2)')
            ->execute([':id1' => $partId, ':id2' => $part['parent_id']]);
    } catch (Exception $e) { /* non-critical */ }
} else {
    incrementDownloadCount($partId);
}

// Log the download activity
$modelName = $part['name'];
if ($part['parent_id']) {
    // Get parent model name for better context
    $parentStmt = $db->prepare('SELECT name FROM models WHERE id = :id');
    $parentStmt->bindValue(':id', $part['parent_id'], PDO::PARAM_INT);
    $parentResult = $parentStmt->execute();
    $parent = $parentResult->fetchArray(PDO::FETCH_ASSOC);
    if ($parent) {
        $modelName = $parent['name'] . ' / ' . $part['name'];
    }
}
logActivity('download', 'model', $part['parent_id'] ?? $partId, $modelName);

// Send file
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');

// Use X-Accel-Redirect in Docker (nginx serves the file directly)
if (getenv('MESHSILO_DOCKER') === 'true' && defined('UPLOAD_PATH')) {
    $relativePath = str_replace(realpath(UPLOAD_PATH), '', realpath($filePath));
    header('X-Accel-Redirect: /internal-assets' . $relativePath);
} else {
    readfile($filePath);
}
exit;
