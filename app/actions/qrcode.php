<?php
/**
 * QR Code Generation
 * Generates QR codes for model pages
 */

require_once __DIR__ . '/../../includes/config.php';

$modelId = (int)($_GET['model_id'] ?? 0);
$size = min(500, max(100, (int)($_GET['size'] ?? 200)));
$format = $_GET['format'] ?? 'png';

if (!$modelId) {
    http_response_code(400);
    die('Model ID required');
}

// Build URL for the model
$siteUrl = rtrim(getSetting('site_url', ''), '/');
if (empty($siteUrl)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $siteUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
}
$modelUrl = $siteUrl . '/model.php?id=' . $modelId;

// Generate QR code using Google Charts API (simple approach)
// For production, consider using a PHP QR code library like phpqrcode or bacon/bacon-qr-code
$qrApiUrl = 'https://chart.googleapis.com/chart?' . http_build_query([
    'cht' => 'qr',
    'chs' => $size . 'x' . $size,
    'chl' => $modelUrl,
    'choe' => 'UTF-8',
    'chld' => 'M|2'
]);

// Alternatively, generate QR code locally using simple algorithm
// This is a basic implementation - for production use a proper library

if ($format === 'svg') {
    header('Content-Type: image/svg+xml');
    header('Content-Disposition: inline; filename="model-' . $modelId . '-qr.svg"');
    echo generateQRCodeSVG($modelUrl, $size);
} else {
    // Redirect to Google Charts for PNG (or implement locally)
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="model-' . $modelId . '-qr.png"');

    // Fetch from Google Charts API
    $ch = curl_init($qrApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $image = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $image) {
        echo $image;
    } else {
        // Fallback: generate a simple placeholder
        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        imagestring($img, 3, 10, $size/2 - 10, 'QR Generation Failed', $black);
        imagepng($img);
        imagedestroy($img);
    }
}

/**
 * Generate a simple SVG QR code representation
 * Note: This is a simplified version - for production use a proper QR library
 */
function generateQRCodeSVG($data, $size) {
    // This generates a redirect to use JS-based QR on frontend instead
    // For proper server-side SVG QR, install a library like chillerlan/php-qrcode

    $encoded = htmlspecialchars($data);
    $moduleSize = $size / 25; // Approximate module size

    return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="$size" height="$size" viewBox="0 0 $size $size">
    <rect width="100%" height="100%" fill="white"/>
    <text x="50%" y="50%" text-anchor="middle" font-family="monospace" font-size="10">
        QR: $encoded
    </text>
    <text x="50%" y="60%" text-anchor="middle" font-family="sans-serif" font-size="8" fill="#666">
        (Use PNG format for scannable QR)
    </text>
</svg>
SVG;
}
