<?php
/**
 * WebP Thumbnail Generation Actions
 * Convert and optimize thumbnails to WebP format
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not logged in');
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    jsonError('Invalid CSRF token');
}

switch ($action) {
    case 'convert':
        convertToWebP();
        break;
    case 'convert_all':
        convertAllToWebP();
        break;
    case 'check_support':
        checkWebPSupport();
        break;
    case 'get_stats':
        getConversionStats();
        break;
    case 'revert':
        revertFromWebP();
        break;
    default:
        jsonError('Invalid action');
}

/**
 * Convert single model thumbnail to WebP
 */
function convertToWebP() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);
    $quality = (int)($_POST['quality'] ?? 80);

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

    // Check permission
    if (!$user['is_admin'] && $model['user_id'] != $user['id']) {
        jsonError('Not authorized');
        return;
    }

    if (empty($model['thumbnail_path'])) {
        jsonError('No thumbnail to convert');
        return;
    }

    // Already WebP?
    if (pathinfo($model['thumbnail_path'], PATHINFO_EXTENSION) === 'webp') {
        jsonError('Already WebP format');
        return;
    }

    $result = convertImageToWebP($model['thumbnail_path'], $quality);

    if ($result['success']) {
        // Update database
        $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
        $stmt->execute([':path' => $result['webp_path'], ':id' => $modelId]);

        echo json_encode([
            'success' => true,
            'original_size' => $result['original_size'],
            'webp_size' => $result['webp_size'],
            'savings_percent' => $result['savings_percent'],
            'new_path' => $result['webp_path']
        ]);
    } else {
        jsonError($result['error']);
    }
}

/**
 * Convert all thumbnails to WebP
 */
function convertAllToWebP() {
    global $user;

    if (!$user['is_admin']) {
        jsonError('Admin access required');
        return;
    }

    $quality = (int)($_POST['quality'] ?? 80);
    $limit = (int)($_POST['limit'] ?? 100); // Process in batches

    $db = getDB();

    // Get thumbnails that aren't WebP
    $stmt = $db->prepare("
        SELECT id, thumbnail_path FROM models
        WHERE thumbnail_path IS NOT NULL
        AND thumbnail_path != ''
        AND thumbnail_path NOT LIKE '%.webp'
        LIMIT :limit
    ");
    $stmt->execute([':limit' => $limit]);

    $converted = 0;
    $failed = 0;
    $totalSaved = 0;
    $errors = [];

    while ($row = $stmt->fetch()) {
        $result = convertImageToWebP($row['thumbnail_path'], $quality);

        if ($result['success']) {
            $updateStmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
            $updateStmt->execute([':path' => $result['webp_path'], ':id' => $row['id']]);
            $converted++;
            $totalSaved += ($result['original_size'] - $result['webp_size']);
        } else {
            $failed++;
            $errors[] = "Model {$row['id']}: {$result['error']}";
        }
    }

    // Count remaining
    $stmt = $db->query("
        SELECT COUNT(*) FROM models
        WHERE thumbnail_path IS NOT NULL
        AND thumbnail_path != ''
        AND thumbnail_path NOT LIKE '%.webp'
    ");
    $remaining = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'converted' => $converted,
        'failed' => $failed,
        'remaining' => $remaining,
        'total_saved_bytes' => $totalSaved,
        'total_saved_formatted' => formatBytes($totalSaved),
        'errors' => array_slice($errors, 0, 10) // Limit error list
    ]);
}

/**
 * Check if WebP is supported
 */
function checkWebPSupport() {
    $gdInfo = gd_info();
    $webpSupport = isset($gdInfo['WebP Support']) && $gdInfo['WebP Support'];
    $imagickSupport = extension_loaded('imagick') &&
                      in_array('WEBP', \Imagick::queryFormats());

    echo json_encode([
        'success' => true,
        'gd_webp_support' => $webpSupport,
        'imagick_webp_support' => $imagickSupport,
        'recommended_library' => $imagickSupport ? 'imagick' : ($webpSupport ? 'gd' : 'none'),
        'can_convert' => $webpSupport || $imagickSupport
    ]);
}

/**
 * Get conversion statistics
 */
function getConversionStats() {
    $db = getDB();

    // Count by format
    $stmt = $db->query("
        SELECT
            COUNT(CASE WHEN thumbnail_path LIKE '%.webp' THEN 1 END) as webp_count,
            COUNT(CASE WHEN thumbnail_path LIKE '%.png' THEN 1 END) as png_count,
            COUNT(CASE WHEN thumbnail_path LIKE '%.jpg' OR thumbnail_path LIKE '%.jpeg' THEN 1 END) as jpg_count,
            COUNT(CASE WHEN thumbnail_path IS NULL OR thumbnail_path = '' THEN 1 END) as no_thumbnail,
            COUNT(*) as total
        FROM models WHERE parent_id IS NULL
    ");
    $counts = $stmt->fetch();

    // Estimate potential savings (estimate 40% reduction for non-webp)
    $stmt = $db->query("
        SELECT SUM(file_size) as total_size
        FROM models
        WHERE thumbnail_path IS NOT NULL
        AND thumbnail_path != ''
        AND thumbnail_path NOT LIKE '%.webp'
    ");
    $nonWebpSize = (int)$stmt->fetchColumn();
    $estimatedSavings = (int)($nonWebpSize * 0.40);

    echo json_encode([
        'success' => true,
        'stats' => [
            'webp' => (int)$counts['webp_count'],
            'png' => (int)$counts['png_count'],
            'jpg' => (int)$counts['jpg_count'],
            'no_thumbnail' => (int)$counts['no_thumbnail'],
            'total' => (int)$counts['total'],
            'convertible' => (int)$counts['png_count'] + (int)$counts['jpg_count'],
            'estimated_savings' => formatBytes($estimatedSavings)
        ]
    ]);
}

/**
 * Revert from WebP (restore original if backup exists)
 */
function revertFromWebP() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);

    if (!$modelId) {
        jsonError('Model ID required');
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT thumbnail_path, user_id FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model || !$user['is_admin'] && $model['user_id'] != $user['id']) {
        jsonError('Not authorized');
        return;
    }

    if (pathinfo($model['thumbnail_path'], PATHINFO_EXTENSION) !== 'webp') {
        jsonError('Not a WebP thumbnail');
        return;
    }

    // Check for backup file
    $webpPath = UPLOAD_PATH . $model['thumbnail_path'];
    $basePath = preg_replace('/\.webp$/', '', $webpPath);

    $originalPath = null;
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        if (file_exists($basePath . $ext . '.backup')) {
            $originalPath = $basePath . $ext;
            break;
        }
    }

    if (!$originalPath) {
        jsonError('No backup found');
        return;
    }

    // Restore backup
    rename($originalPath . '.backup', $originalPath);
    unlink($webpPath);

    $relativePath = str_replace(UPLOAD_PATH, '', $originalPath);
    $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
    $stmt->execute([':path' => $relativePath, ':id' => $modelId]);

    jsonSuccess(['restored_path' => $relativePath]);
}

/**
 * Convert image file to WebP
 */
function convertImageToWebP($relativePath, $quality = 80) {
    $fullPath = UPLOAD_PATH . $relativePath;

    if (!file_exists($fullPath)) {
        return ['success' => false, 'error' => 'File not found'];
    }

    $originalSize = filesize($fullPath);
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $webpPath = preg_replace('/\.(png|jpe?g|gif)$/i', '.webp', $fullPath);

    // Determine image type
    $imageInfo = @getimagesize($fullPath);
    if (!$imageInfo) {
        return ['success' => false, 'error' => 'Invalid image file'];
    }

    try {
        // Try Imagick first (better quality)
        if (extension_loaded('imagick') && in_array('WEBP', \Imagick::queryFormats())) {
            $imagick = new \Imagick($fullPath);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($webpPath);
            $imagick->destroy();
        }
        // Fall back to GD
        elseif (function_exists('imagewebp')) {
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($fullPath);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($fullPath);
                    // Preserve transparency
                    imagepalettetotruecolor($image);
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($fullPath);
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
            return ['success' => false, 'error' => 'WebP conversion not supported'];
        }

        if (!file_exists($webpPath)) {
            return ['success' => false, 'error' => 'WebP file not created'];
        }

        $webpSize = filesize($webpPath);
        $savings = $originalSize - $webpSize;
        $savingsPercent = ($originalSize > 0) ? round(($savings / $originalSize) * 100, 1) : 0;

        // Keep original as backup if significantly smaller
        if ($savings > 0) {
            rename($fullPath, $fullPath . '.backup');
        } else {
            // WebP is larger, keep original
            unlink($webpPath);
            return ['success' => false, 'error' => 'WebP would be larger, skipped'];
        }

        return [
            'success' => true,
            'webp_path' => str_replace(UPLOAD_PATH, '', $webpPath),
            'original_size' => $originalSize,
            'webp_size' => $webpSize,
            'savings_bytes' => $savings,
            'savings_percent' => $savingsPercent
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// formatBytes is defined in includes/helpers.php
