<?php
/**
 * Model Attachments Actions
 * Upload, delete, and manage document/image attachments
 */

// Suppress HTML error output — this is a JSON endpoint
ini_set('display_errors', '0');
ini_set('html_errors', '0');

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

try {

if (!isFeatureEnabled('attachments')) {
    jsonError('Attachments feature is disabled');
}

if (!isLoggedIn()) {
    jsonError('Not logged in');
}

if (!canEdit()) {
    jsonError('Edit permission required');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['upload', 'delete'])) {
    if (!Csrf::check()) {
        jsonError('Invalid request token', 403);
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
        jsonError('Invalid action');
}

} catch (Throwable $e) {
    if (function_exists('logException')) {
        logException($e, ['action' => 'attachments']);
    }
    http_response_code(500);
    jsonError($e->getMessage());
}

/**
 * Upload an attachment (image or PDF)
 */
function uploadAttachment() {
    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        jsonError('Model ID required');
        return;
    }

    if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        jsonError('No file uploaded');
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
        jsonError('Invalid file type. Allowed: JPG, PNG, GIF, WebP, PDF, TXT, MD');
        return;
    }

    $isImage = in_array($mimeType, $imageTypes);
    $isPdf = in_array($mimeType, $pdfTypes);
    $isText = in_array($mimeType, $textTypes) || $isTextByExt;

    // Max file size: 5MB for images, 20MB for PDFs, 1MB for text
    $maxSize = $isImage ? 5 * 1024 * 1024 : ($isText ? 1 * 1024 * 1024 : 20 * 1024 * 1024);
    if ($file['size'] > $maxSize) {
        $maxMB = $maxSize / (1024 * 1024);
        jsonError("File too large (max {$maxMB}MB)");
        return;
    }

    // Get model to create attachment directory
    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, file_path, user_id FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) {
        jsonError('Model not found');
        return;
    }

    // Verify ownership - user must own the model or be an admin
    $user = getCurrentUser();
    if (!$user['is_admin'] && (!empty($model['user_id']) && $model['user_id'] != $user['id'])) {
        jsonError('Not authorized to modify this model');
        return;
    }

    // Store attachments in the model's own asset folder
    // file_path is "assets/{folderId}" or "assets/{folderId}/filename" — extract the folder
    $modelFilePath = $model['file_path'] ?? '';
    $modelFolder = preg_replace('#^assets/#', '', $modelFilePath);
    // For parent models, file_path is "assets/{folderId}" (no filename)
    // For single models, file_path is "assets/{folderId}/filename.ext"
    if (str_contains($modelFolder, '/')) {
        $modelFolder = dirname($modelFolder);
    }
    $attachDir = UPLOAD_PATH . $modelFolder . '/attachments';
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
        jsonError('Failed to save file');
        return;
    }

    $finalFilename = $filename;
    $finalMimeType = $mimeType;
    $finalFileSize = filesize($filePath);

    // Store relative path (in its original format; OptimizeImage job converts
    // JPEG/PNG → WebP asynchronously after insert)
    $relativePath = $modelFolder . '/attachments/' . $finalFilename;

    // Get next display order
    $orderStmt = $db->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM model_attachments WHERE model_id = :model_id');
    $orderStmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $orderStmt->execute();
    $nextOrder = (int)$orderStmt->fetchColumn();

    // Insert into database
    $stmt = $db->prepare('INSERT INTO model_attachments (model_id, filename, file_path, file_type, mime_type, file_size, original_filename, display_order)
                          VALUES (:model_id, :filename, :file_path, :file_type, :mime_type, :file_size, :original_filename, :display_order)');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $stmt->bindValue(':display_order', $nextOrder, PDO::PARAM_INT);
    $stmt->bindValue(':filename', $finalFilename, PDO::PARAM_STR);
    $stmt->bindValue(':file_path', $relativePath, PDO::PARAM_STR);
    $stmt->bindValue(':file_type', $fileType, PDO::PARAM_STR);
    $stmt->bindValue(':mime_type', $finalMimeType, PDO::PARAM_STR);
    $stmt->bindValue(':file_size', $finalFileSize, PDO::PARAM_INT);
    $stmt->bindValue(':original_filename', $originalFilename, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $attachmentId = $db->lastInsertId();

        // Dispatch WebP conversion job for image attachments. The job is
        // a no-op for already-WebP files and files whose extension isn't
        // in ImageConverter::CONVERTIBLE_EXTENSIONS.
        if ($isImage && class_exists('Queue')) {
            Queue::push('OptimizeImage', ['type' => 'attachment', 'id' => (int)$attachmentId]);
        }

        // Log activity
        if (function_exists('logActivity')) {
            logActivity('attachment_upload', 'model', $modelId, $originalFilename);
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
        jsonError('Failed to save attachment record');
    }
}

/**
 * Delete an attachment
 */
function deleteAttachment() {
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);

    if (!$attachmentId) {
        jsonError('Attachment ID required');
        return;
    }

    $db = getDB();

    // Get attachment info
    $stmt = $db->prepare('SELECT * FROM model_attachments WHERE id = :id');
    $stmt->bindValue(':id', $attachmentId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $attachment = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$attachment) {
        jsonError('Attachment not found');
        return;
    }

    // Verify ownership - user must own the parent model or be an admin
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
    $stmt->bindValue(':id', $attachment['model_id'], PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    $user = getCurrentUser();
    if ($model && !$user['is_admin'] && (!empty($model['user_id']) && $model['user_id'] != $user['id'])) {
        jsonError('Not authorized to modify this model');
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
            logActivity('attachment_delete', 'model', $attachment['model_id'], $attachment['original_filename']);
        }

        jsonSuccess();
    } else {
        jsonError('Failed to delete attachment');
    }
}

// WebP conversion moved to includes/ImageConverter.php + jobs/OptimizeImage.php
// Dispatched from uploadAttachment() above after a successful INSERT.
