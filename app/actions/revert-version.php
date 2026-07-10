<?php
/**
 * Revert Model to Previous Version
 *
 * Creates a new version with the contents of a previous version
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

requireCsrfJson();

if (!canEdit()) {
    jsonError('Permission denied', 403);
}

$user = getCurrentUser();
$modelId = isset($input['model_id']) ? (int)$input['model_id'] : 0;
$versionNumber = isset($input['version_number']) ? (int)$input['version_number'] : 0;
$changelog = trim($input['changelog'] ?? '');

if (!$modelId || !$versionNumber) {
    jsonError('Model ID and version number are required');
}

$db = getDB();

// Get model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    jsonError('Model not found');
}

// Check permission
if (!userCanModifyModel($model, $user)) {
    jsonError('Permission denied', 403);
}

// Get the version to revert to
$stmt = $db->prepare('SELECT * FROM model_versions WHERE model_id = :model_id AND version_number = :version');
$stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
$stmt->bindValue(':version', $versionNumber, PDO::PARAM_INT);
$result = $stmt->execute();
$targetVersion = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$targetVersion) {
    jsonError('Version not found');
}

// Check if already at this version
$currentVersion = $model['current_version'] ?? 0;
if ($currentVersion == $versionNumber) {
    jsonError('Already at this version');
}

// Get source file path
$sourceFilePath = __DIR__ . '/../../storage/assets/' . $targetVersion['file_path'];
if (!is_file($sourceFilePath)) {
    jsonError('Version file not found');
}

// Calculate next version number
$stmt = $db->prepare('SELECT MAX(version_number) as max_ver FROM model_versions WHERE model_id = :model_id');
$stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
$row = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);
$nextVersion = ($row && $row['max_ver']) ? $row['max_ver'] + 1 : 1;

// Create version directory if needed
$versionDir = __DIR__ . '/../../storage/assets/versions/' . $modelId;
if (!is_dir($versionDir)) {
    mkdir($versionDir, 0755, true);
}

// Copy the old version file to a new version
$ext = pathinfo($targetVersion['file_path'], PATHINFO_EXTENSION);
$newFilename = 'v' . $nextVersion . '_revert_from_v' . $versionNumber . '.' . $ext;
$newFilePath = 'versions/' . $modelId . '/' . $newFilename;
$fullNewPath = $versionDir . '/' . $newFilename;

// Persist the copied file, the new version row, and the model pointer atomically.
$pdo = $db->getPDO();
$useImmediate = ($db->getType() !== 'mysql');
$fileType = strtolower($ext);

if ($useImmediate) {
    // SQLite: BEGIN IMMEDIATE takes the write lock up front. The Database wrapper
    // exposes no beginTransaction(); transactions go through the raw PDO handle.
    $db->exec('BEGIN IMMEDIATE');
} else {
    $pdo->beginTransaction();
}

$copied = false;
try {
    if (!copy($sourceFilePath, $fullNewPath)) {
        throw new RuntimeException('Failed to copy version file');
    }
    $copied = true;

    // Get file info
    $fileSize = filesize($fullNewPath);
    $fileHash = $targetVersion['file_hash'];
    if (!$fileHash) {
        $fileHash = calculateContentHash($fullNewPath, $ext);
    }

    // Default changelog if not provided
    if (empty($changelog)) {
        $changelog = 'Reverted to version ' . $versionNumber;
    }

    // Add new version record
    $newVersionId = addModelVersion($modelId, $newFilePath, $fileSize, $fileHash, $changelog, $user['id']);
    if (!$newVersionId) {
        throw new RuntimeException('Failed to create version record');
    }

    // Update main model to point to new version. file_type is included so it
    // matches the extension of the file the model now points to.
    $stmt = $db->prepare('
        UPDATE models
        SET file_path = :file_path,
            file_size = :file_size,
            file_hash = :file_hash,
            file_type = :file_type,
            current_version = :version
        WHERE id = :id
    ');
    $stmt->bindValue(':file_path', $newFilePath, PDO::PARAM_STR);
    $stmt->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
    $stmt->bindValue(':file_hash', $fileHash, PDO::PARAM_STR);
    $stmt->bindValue(':file_type', $fileType, PDO::PARAM_STR);
    $stmt->bindValue(':version', $newVersionId, PDO::PARAM_INT);
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $stmt->execute();

    if ($useImmediate) {
        $db->exec('COMMIT');
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($useImmediate) {
        $db->exec('ROLLBACK');
    } else {
        $pdo->rollBack();
    }
    // Remove the copied file if it was created before the failure.
    if ($copied && is_file($fullNewPath)) {
        unlink($fullNewPath);
    }
    jsonError($e->getMessage() ?: 'Failed to revert version');
}

// Log activity
logActivity('revert_version', 'model', $modelId, $model['name'] . ' reverted to v' . $versionNumber);

echo json_encode([
    'success' => true,
    'new_version' => $newVersionId,
    'message' => 'Successfully reverted to version ' . $versionNumber . ' (now v' . $newVersionId . ')'
]);
