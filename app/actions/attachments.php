<?php
/**
 * Model Attachments Actions
 * Upload, delete, and manage document/image attachments
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isFeatureEnabled('attachments')) {
    echo json_encode(['success' => false, 'error' => 'Attachments feature is disabled']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if (!canEdit()) {
    echo json_encode(['success' => false, 'error' => 'Edit permission required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['upload', 'delete'])) {
    if (!Csrf::check()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request token']);
        exit;
    }
}

switch ($action) {
    case 'upload':
        uploadAttachment();
        break;
    case 'delete':
        deleteAttachment();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Upload an attachment (image or PDF)
 */
function uploadAttachment() {
    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $file = $_FILES['attachment'];

    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $pdfTypes = ['application/pdf'];
    $textTypes = ['text/plain', 'text/markdown', 'text/x-markdown'];
    $allowedTypes = array_merge($imageTypes, $pdfTypes, $textTypes);

    // Also allow by extension for text files (MIME detection can be unreliable)
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $textExtensions = ['txt', 'md'];
    $isTextByExt = in_array($ext, $textExtensions);

    if (!in_array($mimeType, $allowedTypes) && !$isTextByExt) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP, PDF, TXT, MD']);
        return;
    }

    $isImage = in_array($mimeType, $imageTypes);
    $isPdf = in_array($mimeType, $pdfTypes);
    $isText = in_array($mimeType, $textTypes) || $isTextByExt;

    // Max file size: 5MB for images, 20MB for PDFs, 1MB for text
    $maxSize = $isImage ? 5 * 1024 * 1024 : ($isText ? 1 * 1024 * 1024 : 20 * 1024 * 1024);
    if ($file['size'] > $maxSize) {
        $maxMB = $maxSize / (1024 * 1024);
        echo json_encode(['success' => false, 'error' => "File too large (max {$maxMB}MB)"]);
        return;
    }

    // Get model to create attachment directory
    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, user_id FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) {
        echo json_encode(['success' => false, 'error' => 'Model not found']);
        return;
    }

    // Verify ownership - user must own the model or be an admin
    $user = getCurrentUser();
    if (!$user['is_admin'] && (!empty($model['user_id']) && $model['user_id'] != $user['id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authorized to modify this model']);
        return;
    }

    // Create attachments directory
    $modelHash = substr(md5($model['name'] . $model['id']), 0, 12);
    $attachDir = UPLOAD_PATH . $modelHash . '/attachments';
    if (!is_dir($attachDir)) {
        mkdir($attachDir, 0755, true);
    }

    $originalFilename = $file['name'];
    $fileType = $isImage ? 'image' : ($isText ? 'text' : 'pdf');
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

    // Whitelist allowed extensions (defense-in-depth)
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md'];
    if (!in_array($ext, $allowedExtensions)) {
        $ext = $isImage ? 'png' : ($isText ? 'txt' : 'pdf'); // Fallback to safe extension
    }

    // Generate unique filename
    $baseFilename = $fileType . '_' . $modelId . '_' . time();
    $filename = $baseFilename . '.' . $ext;
    $filePath = $attachDir . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
        return;
    }

    $finalFilename = $filename;
    $finalMimeType = $mimeType;
    $finalFileSize = filesize($filePath);

    // Convert images to WebP
    if ($isImage && $mimeType !== 'image/webp') {
        $webpResult = convertToWebP($filePath);
        if ($webpResult['success']) {
            $finalFilename = $baseFilename . '.webp';
            $finalMimeType = 'image/webp';
            $finalFileSize = $webpResult['size'];
        }
    }

    // Store relative path
    $relativePath = $modelHash . '/attachments/' . $finalFilename;

    // Insert into database
    $stmt = $db->prepare('INSERT INTO model_attachments (model_id, filename, file_path, file_type, mime_type, file_size, original_filename, display_order)
                          VALUES (:model_id, :filename, :file_path, :file_type, :mime_type, :file_size, :original_filename,
                                  (SELECT COALESCE(MAX(display_order), 0) + 1 FROM model_attachments WHERE model_id = :model_id2))');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $stmt->bindValue(':model_id2', $modelId, PDO::PARAM_INT);
    $stmt->bindValue(':filename', $finalFilename, PDO::PARAM_STR);
    $stmt->bindValue(':file_path', $relativePath, PDO::PARAM_STR);
    $stmt->bindValue(':file_type', $fileType, PDO::PARAM_STR);
    $stmt->bindValue(':mime_type', $finalMimeType, PDO::PARAM_STR);
    $stmt->bindValue(':file_size', $finalFileSize, PDO::PARAM_INT);
    $stmt->bindValue(':original_filename', $originalFilename, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $attachmentId = $db->lastInsertId();

        // Log activity
        if (function_exists('logActivity')) {
            logActivity(getCurrentUser()['id'], 'attachment_upload', 'model', $modelId, $originalFilename);
        }

        echo json_encode([
            'success' => true,
            'attachment_id' => $attachmentId,
            'filename' => $finalFilename,
            'file_path' => $relativePath,
            'file_type' => $fileType,
            'file_size' => $finalFileSize,
            'original_filename' => $originalFilename
        ]);
    } else {
        // Clean up file on database error
        @unlink(UPLOAD_PATH . $relativePath);
        echo json_encode(['success' => false, 'error' => 'Failed to save attachment record']);
    }
}

/**
 * Delete an attachment
 */
function deleteAttachment() {
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);

    if (!$attachmentId) {
        echo json_encode(['success' => false, 'error' => 'Attachment ID required']);
        return;
    }

    $db = getDB();

    // Get attachment info
    $stmt = $db->prepare('SELECT * FROM model_attachments WHERE id = :id');
    $stmt->bindValue(':id', $attachmentId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $attachment = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$attachment) {
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        return;
    }

    // Verify ownership - user must own the parent model or be an admin
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
    $stmt->bindValue(':id', $attachment['model_id'], PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    $user = getCurrentUser();
    if ($model && !$user['is_admin'] && (!empty($model['user_id']) && $model['user_id'] != $user['id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authorized to modify this model']);
        return;
    }

    // Delete file (with path traversal protection)
    $relativePath = $attachment['file_path'];
    $filePath = UPLOAD_PATH . $relativePath;
    // Verify resolved path is within UPLOAD_PATH to prevent directory traversal
    $realFilePath = realpath($filePath);
    $realUploadPath = realpath(UPLOAD_PATH);
    if ($realFilePath && $realUploadPath && str_starts_with($realFilePath, $realUploadPath)) {
        unlink($realFilePath);
    }

    // Delete database record
    $stmt = $db->prepare('DELETE FROM model_attachments WHERE id = :id');
    $stmt->bindValue(':id', $attachmentId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Log activity
        if (function_exists('logActivity')) {
            logActivity(getCurrentUser()['id'], 'attachment_delete', 'model', $attachment['model_id'], $attachment['original_filename']);
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete attachment']);
    }
}

/**
 * Convert image to WebP format
 */
function convertToWebP($filePath, $quality = 85) {
    $webpPath = preg_replace('/\.(png|jpe?g|gif)$/i', '.webp', $filePath);

    // Get image info
    $imageInfo = @getimagesize($filePath);
    if (!$imageInfo) {
        return ['success' => false, 'error' => 'Invalid image file'];
    }

    try {
        // Try Imagick first (better quality)
        if (extension_loaded('imagick') && in_array('WEBP', \Imagick::queryFormats())) {
            $imagick = new \Imagick($filePath);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($webpPath);
            $imagick->destroy();
        }
        // Fall back to GD
        elseif (function_exists('imagewebp')) {
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($filePath);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($filePath);
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($filePath);
                    break;
                default:
                    return ['success' => false, 'error' => 'Unsupported image type'];
            }

            if (!$image) {
                return ['success' => false, 'error' => 'Could not load image'];
            }

            imagewebp($image, $webpPath, $quality);
            imagedestroy($image);
        } else {
            // No WebP support, keep original
            return ['success' => false, 'error' => 'WebP not supported'];
        }

        if (file_exists($webpPath)) {
            // Remove original, keep WebP
            unlink($filePath);
            return [
                'success' => true,
                'path' => $webpPath,
                'size' => filesize($webpPath)
            ];
        }

        return ['success' => false, 'error' => 'WebP file not created'];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
