<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

requireCsrfJson();

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
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// Check permission
if ($model['user_id'] !== null && (int)$model['user_id'] !== (int)$user['id'] && !$user['is_admin']) {
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
$versionDir = __DIR__ . '/../../storage/assets/versions/' . $modelId;
if (!is_dir($versionDir)) {
    mkdir($versionDir, 0755, true);
}

// Get next version number
$stmt = $db->prepare('SELECT MAX(version_number) as max_ver FROM model_versions WHERE model_id = :model_id');
$stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
$row = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);
$nextVersion = ($row && $row['max_ver']) ? $row['max_ver'] + 1 : 1;

// If this is version 1, archive the current file first
if ($nextVersion === 1 && $model['file_path']) {
    $currentPath = getAbsoluteFilePath($model);
    if ($currentPath && is_file($currentPath)) {
        $currentHash = calculateContentHash($currentPath, $model['file_type']);
        $archivePath = 'versions/' . $modelId . '/v0_' . basename($model['file_path']);

        // Copy current file to versions
        copy($currentPath, $versionDir . '/v0_' . basename($model['file_path']));

        // Add version 0 (original)
        $stmt = $db->prepare('INSERT INTO model_versions (model_id, version_number, file_path, file_size, file_hash, changelog, created_by, created_at) VALUES (:model_id, 0, :file_path, :file_size, :file_hash, :changelog, :created_by, :created_at)');
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $stmt->bindValue(':file_path', $archivePath, PDO::PARAM_STR);
        $stmt->bindValue(':file_size', $model['file_size'], PDO::PARAM_INT);
        $stmt->bindValue(':file_hash', $currentHash, PDO::PARAM_STR);
        $stmt->bindValue(':changelog', 'Original version', PDO::PARAM_STR);
        $stmt->bindValue(':created_by', $model['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $model['created_at'], PDO::PARAM_STR);
        $stmt->execute();
    }
}

// Save new version file - sanitize filename to prevent path traversal
$safeFileName = basename($file['name']);
$safeFileName = preg_replace('/[^\w\-. ]/', '_', $safeFileName);
$versionFilename = 'v' . $nextVersion . '_' . $safeFileName;
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
    $stmt->bindValue(':file_path', $versionPath, PDO::PARAM_STR);
    $stmt->bindValue(':file_size', $file['size'], PDO::PARAM_INT);
    $stmt->bindValue(':file_hash', $hash, PDO::PARAM_STR);
    $stmt->bindValue(':file_type', $ext, PDO::PARAM_STR);
    $stmt->bindValue(':version', $result, PDO::PARAM_INT);
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
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
