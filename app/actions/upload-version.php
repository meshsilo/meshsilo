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
    jsonError('No model specified');
}

if (!isset($_FILES['version_file']) || $_FILES['version_file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('No file uploaded');
}

// Verify model exists
$db = getDB();
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    jsonError('Model not found');
}

// Check permission
if ($model['user_id'] !== null && (int)$model['user_id'] !== (int)$user['id'] && !$user['is_admin']) {
    jsonError('Permission denied');
}

$file = $_FILES['version_file'];
$allowedTypes = ['stl', '3mf', 'gcode'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedTypes)) {
    jsonError('Invalid file type. Only STL, 3MF, and GCODE allowed.');
}

// Plugin hook: before_upload gate (quota, malware-scan plugins). Fails closed.
if (class_exists('PluginManager')) {
    $allowed = PluginManager::applyGate('before_upload', true, [
        'type' => 'version',
        'filename' => $file['name'],
        'path' => $file['tmp_name'],
        'size' => (int)$file['size'],
        'model_id' => $modelId,
    ]);
    if ($allowed !== true) {
        jsonError(is_string($allowed) ? $allowed : 'Upload blocked by plugin');
    }
}

// Calculate hash. The uploaded temp file has no extension, so calculateContentHash()
// (which derives the type from the path) would hash a 3MF as a raw ZIP. Branch on the
// real extension so 3MF versions are content-hashed like the other upload paths.
$hash = ($ext === '3mf')
    ? calculate3mfContentHash($file['tmp_name'])
    : calculateContentHash($file['tmp_name']);

// Create version directory if needed
$versionDir = __DIR__ . '/../../storage/assets/versions/' . $modelId;
if (!is_dir($versionDir)) {
    mkdir($versionDir, 0755, true);
}

// Save new version file - sanitize filename to prevent path traversal
$safeFileName = basename($file['name']);
$safeFileName = preg_replace('/[^\w\-. ]/', '_', $safeFileName);

// Persist the archive, the new version row, and the model pointer atomically.
// A write-locked transaction serializes the MAX(version_number)+1 allocation so
// two concurrent uploads cannot claim the same version number.
$pdo = $db->getPDO();
$useImmediate = ($db->getType() !== 'mysql');

if ($useImmediate) {
    // SQLite: BEGIN IMMEDIATE takes the write lock up front so the allocation
    // below is serialized. The Database wrapper exposes no beginTransaction();
    // transactions go through the raw PDO handle (see dedup.php deleteIfOrphaned).
    $db->exec('BEGIN IMMEDIATE');
} else {
    $pdo->beginTransaction();
}

$fullPath = null;
try {
    // Get next version number inside the transaction to prevent races
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

    // Include a unique token in the file name so two uploads that race to the
    // same version number cannot overwrite each other's vN_<name> file.
    $uniqueToken = bin2hex(random_bytes(4));
    $versionFilename = 'v' . $nextVersion . '_' . $uniqueToken . '_' . $safeFileName;
    $versionPath = 'versions/' . $modelId . '/' . $versionFilename;
    $fullPath = $versionDir . '/' . $versionFilename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Failed to save file');
    }

    // Add version record - must succeed or the whole upload is rolled back so
    // the v0 archive row above is never committed on its own.
    $result = addModelVersion($modelId, $versionPath, $file['size'], $hash, $changelog, $user['id']);
    if (!$result) {
        throw new RuntimeException('Failed to create version record');
    }

    // Update main model to point to new file (inside the transaction so the
    // pointer commits atomically with the version row).
    $stmt = $db->prepare('UPDATE models SET file_path = :file_path, file_size = :file_size, file_hash = :file_hash, file_type = :file_type, current_version = :version WHERE id = :id');
    $stmt->bindValue(':file_path', $versionPath, PDO::PARAM_STR);
    $stmt->bindValue(':file_size', $file['size'], PDO::PARAM_INT);
    $stmt->bindValue(':file_hash', $hash, PDO::PARAM_STR);
    $stmt->bindValue(':file_type', $ext, PDO::PARAM_STR);
    $stmt->bindValue(':version', $result, PDO::PARAM_INT);
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
    // Clean up the moved file if it was created before the failure.
    if ($fullPath !== null && is_file($fullPath)) {
        unlink($fullPath);
    }
    jsonError($e->getMessage() ?: 'Failed to create version record');
}

logActivity('upload_version', 'model', $modelId, $model['name'] . ' v' . $result);

// Plugin hook: version uploads are uploads too (virus scan, indexing, notifications)
if (class_exists('PluginManager')) {
    PluginManager::doAction('after_upload', $modelId, [
        'name' => $model['name'],
        'file_type' => $ext,
        'user_id' => $user['id'] ?? null,
        'version' => $result,
    ]);
}

jsonSuccess([
    'version' => $result,
    'message' => 'Version ' . $result . ' uploaded successfully'
]);
