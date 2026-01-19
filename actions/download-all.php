<?php
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$modelId) {
    header('Location: ../index.php');
    exit;
}

// Get parent model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND part_count > 0');
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    header('Location: ../index.php');
    exit;
}

// Get all parts
$stmt = $db->prepare('SELECT * FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC');
$stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$parts = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $parts[] = $row;
}

if (empty($parts)) {
    header('Location: ../model.php?id=' . $modelId);
    exit;
}

// Create ZIP file
$zipFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $model['name']) . '.zip';
$zipPath = sys_get_temp_dir() . '/' . uniqid('silo_download_') . '.zip';

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    logError('Failed to create download ZIP', ['model_id' => $modelId]);
    header('Location: ../model.php?id=' . $modelId);
    exit;
}

foreach ($parts as $part) {
    $filePath = __DIR__ . '/../' . $part['file_path'];
    if (file_exists($filePath)) {
        // Use original path structure if available, otherwise just filename
        $zipEntryPath = $part['original_path'] ?? $part['filename'];
        $zip->addFile($filePath, $zipEntryPath);
    }
}

$zip->close();

// Send the ZIP file
if (file_exists($zipPath)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache, must-revalidate');

    readfile($zipPath);

    // Clean up temp file
    unlink($zipPath);

    logInfo('Download all parts', ['model_id' => $modelId, 'parts' => count($parts)]);
    exit;
} else {
    logError('Download ZIP not found', ['model_id' => $modelId, 'zip_path' => $zipPath]);
    header('Location: ../model.php?id=' . $modelId);
    exit;
}
