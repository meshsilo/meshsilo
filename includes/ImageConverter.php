<?php

declare(strict_types=1);

/**
 * Image Converter
 *
 * Converts JPEG and PNG images to WebP. Used by:
 *   - Upload action handlers (attachments, thumbnails) — dispatch OptimizeImage
 *     job after successful upload, which calls this class
 *   - UploadProcessor — converts images extracted from zip uploads
 *   - Admin retroactive conversion — batch-convert existing images
 *
 * Prefers Imagick (better quality, more formats) and falls back to GD.
 * Preserves PNG transparency when using GD.
 */
class ImageConverter
{
    private const CONVERTIBLE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /**
     * Check whether WebP conversion is enabled in admin settings.
     * Default ON when the setting is missing.
     */
    public static function isEnabled(): bool
    {
        if (!function_exists('getSetting')) {
            return true;
        }
        return getSetting('convert_images_webp', '1') === '1';
    }

    /**
     * Check whether a file path has an extension we convert to WebP.
     * Doesn't touch the filesystem.
     */
    public static function isConvertible(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::CONVERTIBLE_EXTENSIONS, true);
    }

    /**
     * Convert a JPEG or PNG file to WebP.
     *
     * @param string      $sourcePath  Absolute path to an existing image file
     * @param int         $quality     WebP quality 1-100 (default 80)
     * @param string|null $destPath    Output path. Defaults to the source path
     *                                 with its extension replaced by `.webp`.
     * @return string|null Path to the written WebP file on success, null on failure
     */
    public static function toWebp(string $sourcePath, int $quality = 80, ?string $destPath = null): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return null;
        }

        if ($destPath === null) {
            $destPath = preg_replace('/\.(jpe?g|png|gif)$/i', '', $sourcePath) . '.webp';
        }

        $quality = max(1, min(100, $quality));

        // Prefer Imagick — better quality, handles ICC profiles, faster
        if (extension_loaded('imagick')) {
            try {
                $formats = \Imagick::queryFormats();
                if (in_array('WEBP', $formats, true)) {
                    $imagick = new \Imagick($sourcePath);
                    $imagick->setImageFormat('webp');
                    $imagick->setImageCompressionQuality($quality);
                    $imagick->writeImage($destPath);
                    $imagick->destroy();
                    return file_exists($destPath) ? $destPath : null;
                }
            } catch (\Throwable $e) {
                // Fall through to GD
            }
        }

        if (!function_exists('imagewebp')) {
            return null;
        }

        $source = null;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($sourcePath);
                if ($source) {
                    // Preserve transparency in the WebP output
                    imagepalettetotruecolor($source);
                    imagealphablending($source, false);
                    imagesavealpha($source, true);
                }
                break;
            default:
                return null;
        }

        if ($source === false || $source === null) {
            return null;
        }

        $success = @imagewebp($source, $destPath, $quality);
        imagedestroy($source);

        return ($success && file_exists($destPath)) ? $destPath : null;
    }
}
