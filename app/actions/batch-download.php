<?php
require_once '../includes/config.php';
require_once '../includes/dedup.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Not authenticated';
    exit;
}

$user = getCurrentUser();
$ids = isset($_GET['ids']) ? array_filter(array_map('intval', explode(',', $_GET['ids']))) : [];

if (empty($ids)) {
    http_response_code(400);
    echo 'No models specified';
    exit;
}

$db = getDB();

// Get models info
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT * FROM models WHERE id IN ($placeholders) AND parent_id IS NULL");
foreach ($ids as $i => $id) {
    $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
}
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $models[] = $row;
}

if (empty($models)) {
    http_response_code(404);
    echo 'No models found';
    exit;
}

// Create ZIP file
$zipName = 'silo-models-' . date('Y-m-d-His') . '.zip';
$zipPath = sys_get_temp_dir() . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP file';
    exit;
}

foreach ($models as $model) {
    $modelFolder = preg_replace('/[^a-zA-Z0-9_-]/', '_', $model['name']);

    // Check if model has parts
    if ($model['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT * FROM models WHERE parent_id = :parent_id ORDER BY sort_order ASC, original_path ASC');
        $partStmt->bindValue(':parent_id', $model['id'], SQLITE3_INTEGER);
        $partResult = $partStmt->execute();

        while ($part = $partResult->fetchArray(SQLITE3_ASSOC)) {
            $filePath = getAbsoluteFilePath($part);
            if ($filePath && file_exists($filePath)) {
                $fileName = basename($part['original_path'] ?: $part['file_path']);
                $zip->addFile($filePath, $modelFolder . '/' . $fileName);
            }
        }
    } else {
        // Single file model
        $filePath = getAbsoluteFilePath($model);
        if ($filePath && file_exists($filePath)) {
            $fileName = basename($model['original_path'] ?: $model['file_path']);
            $zip->addFile($filePath, $modelFolder . '/' . $fileName);
        }
    }

    // Increment download count
    incrementDownloadCount($model['id']);
    logActivity($user['id'], 'batch_download', 'model', $model['id'], $model['name']);
}

$zip->close();

// Check if ZIP was created successfully
if (!file_exists($zipPath) || filesize($zipPath) === 0) {
    http_response_code(500);
    echo 'Failed to create ZIP file';
    exit;
}

// Send file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipPath);

// Clean up
unlink($zipPath);
exit;
