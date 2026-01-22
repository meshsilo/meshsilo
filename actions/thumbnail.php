<?php
/**
 * Custom Thumbnail Upload Actions
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'upload':
        uploadThumbnail();
        break;
    case 'delete':
        deleteThumbnail();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function uploadThumbnail() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    if (empty($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No thumbnail uploaded']);
        return;
    }

    $file = $_FILES['thumbnail'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type']);
        return;
    }

    // Max 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 5MB)']);
        return;
    }

    // Create thumbnails directory
    $thumbDir = UPLOAD_PATH . 'thumbnails';
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0755, true);
    }

    // Generate filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'thumb_' . $modelId . '_' . time() . '.' . $ext;
    $filePath = $thumbDir . '/' . $filename;
    $relativePath = 'thumbnails/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
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
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

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

    $stmt = $db->prepare('UPDATE models SET thumbnail_path = NULL WHERE id = :id');
    $stmt->execute([':id' => $modelId]);

    echo json_encode(['success' => true]);
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
