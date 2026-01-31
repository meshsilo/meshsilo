<?php
/**
 * PWA Icon Generator
 * Generates PNG icons from SVG or creates simple geometric icons using GD
 *
 * Run this script once to generate the required PNG icons:
 * php generate-icons.php
 *
 * Or access via browser: /images/generate-icons.php
 */

// Prevent web access in production
if (php_sapi_name() !== 'cli' && !isset($_GET['generate'])) {
    die('Add ?generate=1 to URL to run icon generation');
}

$sizes = [192, 512];
$outputDir = __DIR__;

// Theme colors
$primaryColor = [59, 130, 246];  // #3b82f6
$darkColor = [29, 78, 216];      // #1d4ed8
$white = [255, 255, 255];

foreach ($sizes as $size) {
    $image = imagecreatetruecolor($size, $size);

    // Enable alpha blending
    imagealphablending($image, true);
    imagesavealpha($image, true);

    // Allocate colors
    $bgColor = imagecolorallocate($image, $primaryColor[0], $primaryColor[1], $primaryColor[2]);
    $bgDark = imagecolorallocate($image, $darkColor[0], $darkColor[1], $darkColor[2]);
    $whiteColor = imagecolorallocate($image, $white[0], $white[1], $white[2]);
    $whiteTransparent = imagecolorallocatealpha($image, $white[0], $white[1], $white[2], 50);

    // Fill background with gradient effect (approximated with rectangles)
    for ($y = 0; $y < $size; $y++) {
        $ratio = $y / $size;
        $r = (int)($primaryColor[0] + ($darkColor[0] - $primaryColor[0]) * $ratio);
        $g = (int)($primaryColor[1] + ($darkColor[1] - $primaryColor[1]) * $ratio);
        $b = (int)($primaryColor[2] + ($darkColor[2] - $primaryColor[2]) * $ratio);
        $lineColor = imagecolorallocate($image, $r, $g, $b);
        imageline($image, 0, $y, $size, $y, $lineColor);
    }

    // Draw rounded corners (by drawing filled arcs in background color of corners)
    $cornerRadius = (int)($size * 0.125); // 64/512 = 0.125
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

    // Create a mask for rounded corners
    $mask = imagecreatetruecolor($size, $size);
    $maskBg = imagecolorallocate($mask, 0, 0, 0);
    $maskFg = imagecolorallocate($mask, 255, 255, 255);
    imagefill($mask, 0, 0, $maskBg);

    // Draw rounded rectangle on mask
    imagefilledrectangle($mask, $cornerRadius, 0, $size - $cornerRadius, $size, $maskFg);
    imagefilledrectangle($mask, 0, $cornerRadius, $size, $size - $cornerRadius, $maskFg);
    imagefilledellipse($mask, $cornerRadius, $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $maskFg);
    imagefilledellipse($mask, $size - $cornerRadius, $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $maskFg);
    imagefilledellipse($mask, $cornerRadius, $size - $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $maskFg);
    imagefilledellipse($mask, $size - $cornerRadius, $size - $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $maskFg);

    // Apply mask (make corners transparent)
    for ($x = 0; $x < $size; $x++) {
        for ($y = 0; $y < $size; $y++) {
            $maskPixel = imagecolorat($mask, $x, $y);
            if ($maskPixel == $maskBg) {
                imagesetpixel($image, $x, $y, $transparent);
            }
        }
    }
    imagedestroy($mask);

    // Calculate hexagon dimensions
    $centerX = $size / 2;
    $centerY = $size / 2;
    $hexRadius = $size * 0.27; // Scale factor for hexagon
    $strokeWidth = max(2, (int)($size * 0.031)); // Line thickness

    // Hexagon points (rotated 90 degrees to have flat top)
    $hexPoints = [];
    for ($i = 0; $i < 6; $i++) {
        $angle = deg2rad(60 * $i - 90);
        $hexPoints[] = $centerX + $hexRadius * cos($angle);
        $hexPoints[] = $centerY + $hexRadius * sin($angle);
    }

    // Draw hexagon outline with thick lines
    imagesetthickness($image, $strokeWidth);
    for ($i = 0; $i < 6; $i++) {
        $x1 = $hexPoints[$i * 2];
        $y1 = $hexPoints[$i * 2 + 1];
        $x2 = $hexPoints[(($i + 1) % 6) * 2];
        $y2 = $hexPoints[(($i + 1) % 6) * 2 + 1];
        imageline($image, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $whiteColor);
    }

    // Draw inner lines for 3D effect (thinner, semi-transparent)
    $innerStroke = max(1, (int)($size * 0.016));
    imagesetthickness($image, $innerStroke);

    // Vertical line
    imageline($image, (int)$centerX, (int)($centerY - $hexRadius),
              (int)$centerX, (int)($centerY + $hexRadius), $whiteTransparent);

    // Diagonal lines
    imageline($image, (int)$hexPoints[10], (int)$hexPoints[11],
              (int)$hexPoints[4], (int)$hexPoints[5], $whiteTransparent);
    imageline($image, (int)$hexPoints[2], (int)$hexPoints[3],
              (int)$hexPoints[8], (int)$hexPoints[9], $whiteTransparent);

    // Draw center dot
    $dotRadius = max(3, (int)($size * 0.039));
    imagefilledellipse($image, (int)$centerX, (int)$centerY, $dotRadius * 2, $dotRadius * 2, $whiteColor);

    // Save as PNG
    $filename = $outputDir . '/icon-' . $size . '.png';
    imagepng($image, $filename);
    imagedestroy($image);

    echo "Generated: icon-{$size}.png\n";
}

echo "\nIcon generation complete!\n";

// Also create a favicon.ico (16x16 and 32x32)
$faviconSizes = [16, 32];
foreach ($faviconSizes as $size) {
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    // Simple solid background for small sizes
    $bgColor = imagecolorallocate($image, $primaryColor[0], $primaryColor[1], $primaryColor[2]);
    $whiteColor = imagecolorallocate($image, 255, 255, 255);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

    // Fill with primary color
    imagefill($image, 0, 0, $bgColor);

    // For small icons, just draw a simple triangle/hexagon shape
    $centerX = $size / 2;
    $centerY = $size / 2;
    $hexRadius = $size * 0.35;

    // Draw simple hexagon
    $points = [];
    for ($i = 0; $i < 6; $i++) {
        $angle = deg2rad(60 * $i - 90);
        $points[] = (int)($centerX + $hexRadius * cos($angle));
        $points[] = (int)($centerY + $hexRadius * sin($angle));
    }

    imagesetthickness($image, max(1, (int)($size / 16)));
    for ($i = 0; $i < 6; $i++) {
        $x1 = $points[$i * 2];
        $y1 = $points[$i * 2 + 1];
        $x2 = $points[(($i + 1) % 6) * 2];
        $y2 = $points[(($i + 1) % 6) * 2 + 1];
        imageline($image, $x1, $y1, $x2, $y2, $whiteColor);
    }

    // Center dot
    imagefilledellipse($image, (int)$centerX, (int)$centerY, max(2, (int)($size / 8)), max(2, (int)($size / 8)), $whiteColor);

    $filename = $outputDir . '/favicon-' . $size . '.png';
    imagepng($image, $filename);
    imagedestroy($image);

    echo "Generated: favicon-{$size}.png\n";
}
