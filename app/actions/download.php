<?php
/**
 * Download handler for model files
 * Handles both regular and deduplicated files
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

// Get the original filename for download
$filename = $part['filename'] ?? basename($part['file_path']);

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

// Increment download count (for both the part and its parent if it has one)
incrementDownloadCount($partId);
if ($part['parent_id']) {
    incrementDownloadCount($part['parent_id']);
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

readfile($filePath);
exit;
