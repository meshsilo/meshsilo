<?php
require_once '../includes/config.php';
require_once '../includes/dedup.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = getCurrentUser();
$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$changelog = trim($_POST['changelog'] ?? '');

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'No model specified']);
    exit;
}

if (!isset($_FILES['version_file']) || $_FILES['version_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

// Verify model exists
$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// Check permission
if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$file = $_FILES['version_file'];
$allowedTypes = ['stl', '3mf', 'gcode'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only STL, 3MF, and GCODE allowed.']);
    exit;
}

// Calculate hash
$hash = calculateContentHash($file['tmp_name'], $ext);

// Create version directory if needed
$versionDir = __DIR__ . '/../assets/versions/' . $modelId;
if (!is_dir($versionDir)) {
    mkdir($versionDir, 0755, true);
}

// Get next version number
$stmt = $db->prepare('SELECT MAX(version_number) as max_ver FROM model_versions WHERE model_id = :model_id');
$stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$nextVersion = ($row && $row['max_ver']) ? $row['max_ver'] + 1 : 1;

// If this is version 1, archive the current file first
if ($nextVersion === 1 && $model['file_path']) {
    $currentPath = getAbsoluteFilePath($model);
    if ($currentPath && file_exists($currentPath)) {
        $currentHash = calculateContentHash($currentPath, $model['file_type']);
        $archivePath = 'versions/' . $modelId . '/v0_' . basename($model['file_path']);

        // Copy current file to versions
        copy($currentPath, $versionDir . '/v0_' . basename($model['file_path']));

        // Add version 0 (original)
        $stmt = $db->prepare('INSERT INTO model_versions (model_id, version_number, file_path, file_size, file_hash, changelog, created_by, created_at) VALUES (:model_id, 0, :file_path, :file_size, :file_hash, :changelog, :created_by, :created_at)');
        $stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
        $stmt->bindValue(':file_path', $archivePath, SQLITE3_TEXT);
        $stmt->bindValue(':file_size', $model['file_size'], SQLITE3_INTEGER);
        $stmt->bindValue(':file_hash', $currentHash, SQLITE3_TEXT);
        $stmt->bindValue(':changelog', 'Original version', SQLITE3_TEXT);
        $stmt->bindValue(':created_by', $model['user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':created_at', $model['created_at'], SQLITE3_TEXT);
        $stmt->execute();
    }
}

// Save new version file
$versionFilename = 'v' . $nextVersion . '_' . $file['name'];
$versionPath = 'versions/' . $modelId . '/' . $versionFilename;
$fullPath = $versionDir . '/' . $versionFilename;

if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Add version record
$result = addModelVersion($modelId, $versionPath, $file['size'], $hash, $changelog, $user['id']);

if ($result) {
    // Update main model to point to new file
    $stmt = $db->prepare('UPDATE models SET file_path = :file_path, file_size = :file_size, file_hash = :file_hash, file_type = :file_type, current_version = :version WHERE id = :id');
    $stmt->bindValue(':file_path', $versionPath, SQLITE3_TEXT);
    $stmt->bindValue(':file_size', $file['size'], SQLITE3_INTEGER);
    $stmt->bindValue(':file_hash', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':file_type', $ext, SQLITE3_TEXT);
    $stmt->bindValue(':version', $result, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
    $stmt->execute();

    logActivity($user['id'], 'upload_version', 'model', $modelId, $model['name'] . ' v' . $result);

    echo json_encode([
        'success' => true,
        'version' => $result,
        'message' => 'Version ' . $result . ' uploaded successfully'
    ]);
} else {
    // Clean up file on failure
    unlink($fullPath);
    echo json_encode(['success' => false, 'error' => 'Failed to create version record']);
}
