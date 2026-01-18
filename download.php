<?php
/**
 * Download handler for model files
 * Handles both regular and deduplicated files
 */
require_once 'includes/config.php';
require_once 'includes/dedup.php';

$partId = (int)($_GET['id'] ?? 0);

if (!$partId) {
    http_response_code(400);
    die('Invalid request');
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
$result = $stmt->execute();
$part = $result->fetchArray(SQLITE3_ASSOC);

if (!$part) {
    http_response_code(404);
    die('File not found');
}

// Get the real file path (handles deduplicated files)
$filePath = __DIR__ . '/' . getRealFilePath($part);

if (!file_exists($filePath)) {
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

// Send file
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');

readfile($filePath);
exit;
