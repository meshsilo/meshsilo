<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

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
    $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
}
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $models[] = $row;
}

if (empty($models)) {
    http_response_code(404);
    echo 'No models found';
    exit;
}

// Filter to only models the user owns (unless admin)
if (!isAdmin()) {
    $models = array_filter($models, function ($m) use ($user) {
        $ownerId = $m['user_id'] ?? null;
        return $ownerId === null || (int)$ownerId === (int)$user['id'];
    });
    $models = array_values($models);
    if (empty($models)) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
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
        $partStmt->bindValue(':parent_id', $model['id'], PDO::PARAM_INT);
        $partResult = $partStmt->execute();

        while ($part = $partResult->fetchArray(PDO::FETCH_ASSOC)) {
            $filePath = getAbsoluteFilePath($part);
            if ($filePath && is_file($filePath)) {
                $fileName = basename($part['original_path'] ?: $part['file_path']);
                $zip->addFile($filePath, $modelFolder . '/' . $fileName);
            }
        }
    } else {
        // Single file model
        $filePath = getAbsoluteFilePath($model);
        if ($filePath && is_file($filePath)) {
            $fileName = basename($model['original_path'] ?: $model['file_path']);
            $zip->addFile($filePath, $modelFolder . '/' . $fileName);
        }
    }

    // Increment download count
    incrementDownloadCount($model['id']);
    logActivity('batch_download', 'model', $model['id'], $model['name']);
}

$zip->close();

// Check if ZIP was created successfully
if (!file_exists($zipPath) || filesize($zipPath) === 0) {
    if (file_exists($zipPath)) unlink($zipPath);
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
