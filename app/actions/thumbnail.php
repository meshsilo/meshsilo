<?php
/**
 * Custom Thumbnail Upload Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not logged in');
}

// Note: each handler function below calls getCurrentUser() itself rather
// than reading a top-level $user via `global $user`. The Router loads this
// file with `require` inside a method, so top-level variables in this file
// are NOT in the true global scope and `global $user` inside the handlers
// would find nothing.
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['upload', 'delete', 'generate', 'set_from_attachment'])) {
    if (!Csrf::check()) {
        jsonError('Invalid request token', 403);
    }
}

switch ($action) {
    case 'upload':
        uploadThumbnail();
        break;
    case 'delete':
        deleteThumbnail();
        break;
    case 'generate':
        generateThumbnail();
        break;
    case 'set_from_attachment':
        setFromAttachment();
        break;
    default:
        jsonError('Invalid action');
}

function uploadThumbnail() {
    // Router loads this file via `require` inside a method, so top-level
    // variables don't reach the true global scope — `global $user` here would
    // find nothing. Call getCurrentUser() directly so the function gets a
    // fresh, rehydrated user record regardless of scope games.
    $user = getCurrentUser();
    if (!$user) {
        jsonError('Session expired — please log in again', 401);
    }

    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        jsonError('Model ID required');
        return;
    }

    // Verify model ownership
    $db = getDB();
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model) {
        jsonError('Model not found');
        return;
    }

    $ownerId = $model['user_id'] ?? null;
    if ($ownerId !== null && (int)$ownerId !== (int)$user['id'] && !$user['is_admin'] && !canEdit()) {
        jsonError('Permission denied - not model owner');
        return;
    }

    if (empty($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
        jsonError('No thumbnail uploaded');
        return;
    }

    $file = $_FILES['thumbnail'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonError('Invalid image type');
        return;
    }

    // Max 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonError('File too large (max 5MB)');
        return;
    }

    // Create thumbnails directory
    $thumbDir = UPLOAD_PATH . 'thumbnails';
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0755, true);
    }

    // Generate filename - derive extension from MIME type, not client filename
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $mimeToExt[$mimeType] ?? 'jpg';
    $filename = 'thumb_' . $modelId . '_' . time() . '.' . $ext;
    $filePath = $thumbDir . '/' . $filename;
    $relativePath = 'thumbnails/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        jsonError('Failed to save file');
        return;
    }

    // Resize to max 800px
    resizeThumbnail($filePath, 800);

    // Delete old thumbnail if exists
    $db = getDB();
    $stmt = $db->prepare('SELECT thumbnail_path FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if ($model && $model['thumbnail_path']) {
        $oldPath = UPLOAD_PATH . $model['thumbnail_path'];
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }

    // Update model
    $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
    $stmt->execute([':path' => $relativePath, ':id' => $modelId]);

    echo json_encode([
        'success' => true,
        'thumbnail_path' => $relativePath
    ]);
}

function deleteThumbnail() {
    // Router loads this file via `require` inside a method, so top-level
    // variables don't reach the true global scope — `global $user` here would
    // find nothing. Call getCurrentUser() directly so the function gets a
    // fresh, rehydrated user record regardless of scope games.
    $user = getCurrentUser();
    if (!$user) {
        jsonError('Session expired — please log in again', 401);
    }

    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        jsonError('Model ID required');
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT thumbnail_path, user_id FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model) {
        jsonError('Model not found');
        return;
    }

    // Verify model ownership
    $ownerId = $model['user_id'] ?? null;
    if ($ownerId !== null && (int)$ownerId !== (int)$user['id'] && !$user['is_admin'] && !canEdit()) {
        jsonError('Permission denied - not model owner');
        return;
    }

    if ($model['thumbnail_path']) {
        $oldPath = UPLOAD_PATH . $model['thumbnail_path'];
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }

    $stmt = $db->prepare('UPDATE models SET thumbnail_path = NULL WHERE id = :id');
    $stmt->execute([':id' => $modelId]);

    jsonSuccess();
}

function generateThumbnail() {
    // Router loads this file via `require` inside a method, so top-level
    // variables don't reach the true global scope — `global $user` here would
    // find nothing. Call getCurrentUser() directly so the function gets a
    // fresh, rehydrated user record regardless of scope games.
    $user = getCurrentUser();
    if (!$user) {
        jsonError('Session expired — please log in again', 401);
    }

    require_once __DIR__ . '/../../includes/ThumbnailGenerator.php';

    $modelId = (int)($_POST['model_id'] ?? $_GET['model_id'] ?? 0);

    if (!$modelId) {
        jsonError('Model ID required');
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$model) {
        jsonError('Model not found');
        return;
    }

    // Verify model ownership
    $ownerId = $model['user_id'] ?? null;
    if ($ownerId !== null && (int)$ownerId !== (int)$user['id'] && !$user['is_admin'] && !canEdit()) {
        jsonError('Permission denied - not model owner');
        return;
    }

    // Generate thumbnail
    $thumbnailPath = ThumbnailGenerator::generateThumbnail($model);

    if ($thumbnailPath) {
        echo json_encode([
            'success' => true,
            'thumbnail_path' => $thumbnailPath,
            'thumbnail_url' => 'assets/' . $thumbnailPath
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Could not generate thumbnail. Only 3MF files with embedded thumbnails are supported.'
        ]);
    }
}

function setFromAttachment() {
    // Router loads this file via `require` inside a method, so top-level
    // variables don't reach the true global scope — `global $user` here would
    // find nothing. Call getCurrentUser() directly so the function gets a
    // fresh, rehydrated user record regardless of scope games.
    $user = getCurrentUser();
    if (!$user) {
        jsonError('Session expired — please log in again', 401);
    }

    $modelId = (int)($_POST['model_id'] ?? 0);
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);

    if (!$modelId || !$attachmentId) {
        jsonError('Model ID and attachment ID required');
        return;
    }

    $db = getDB();

    // Verify model ownership
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id AND parent_id IS NULL');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model) {
        jsonError('Model not found');
        return;
    }

    $ownerId = $model['user_id'] ?? null;
    if ($ownerId !== null && (int)$ownerId !== (int)$user['id'] && !$user['is_admin'] && !canEdit()) {
        jsonError('Permission denied');
        return;
    }

    // Get the attachment
    $stmt = $db->prepare('SELECT file_path, file_type FROM model_attachments WHERE id = :id AND model_id = :model_id');
    $stmt->execute([':id' => $attachmentId, ':model_id' => $modelId]);
    $att = $stmt->fetch();

    if (!$att) {
        jsonError('Attachment not found');
        return;
    }

    if ($att['file_type'] !== 'image') {
        jsonError('Only image attachments can be used as thumbnails');
        return;
    }

    $sourcePath = UPLOAD_PATH . $att['file_path'];
    if (!file_exists($sourcePath)) {
        jsonError('Attachment file not found on disk');
        return;
    }

    // Create thumbnails directory
    $thumbDir = UPLOAD_PATH . 'thumbnails';
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0755, true);
    }

    // Determine extension from source file
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $ext = 'jpg';
    }

    $filename = 'thumb_' . $modelId . '_' . time() . '.' . $ext;
    $destPath = $thumbDir . '/' . $filename;
    $relativePath = 'thumbnails/' . $filename;

    // Copy the attachment to thumbnails dir
    if (!copy($sourcePath, $destPath)) {
        jsonError('Failed to copy image');
        return;
    }

    // Resize to max 800px
    resizeThumbnail($destPath, 800);

    // Delete old thumbnail if exists
    $stmt = $db->prepare('SELECT thumbnail_path FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $existing = $stmt->fetch();

    if ($existing && $existing['thumbnail_path']) {
        $oldPath = UPLOAD_PATH . $existing['thumbnail_path'];
        if (file_exists($oldPath)) {
            @unlink($oldPath);
        }
    }

    // Update model
    $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
    $stmt->execute([':path' => $relativePath, ':id' => $modelId]);

    echo json_encode([
        'success' => true,
        'thumbnail_path' => $relativePath
    ]);
}

function resizeThumbnail($filePath, $maxDimension) {
    $info = getimagesize($filePath);
    if (!$info) return;

    $width = $info[0];
    $height = $info[1];

    if ($width <= $maxDimension && $height <= $maxDimension) {
        return; // No resize needed
    }

    $mime = $info['mime'];

    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $image = imagecreatefrompng($filePath);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($filePath);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($filePath);
            break;
        default:
            return;
    }

    // Calculate new dimensions
    $ratio = min($maxDimension / $width, $maxDimension / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);

    $resized = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
    }

    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($resized, $filePath, 85);
            break;
        case 'image/png':
            imagepng($resized, $filePath, 8);
            break;
        case 'image/gif':
            imagegif($resized, $filePath);
            break;
        case 'image/webp':
            imagewebp($resized, $filePath, 85);
            break;
    }

    imagedestroy($image);
    imagedestroy($resized);
}
