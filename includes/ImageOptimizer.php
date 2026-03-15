<?php

/**
 * Image Optimizer
 *
 * Provides image optimization and WebP conversion for thumbnails.
 * Benefits:
 * - WebP images are 25-35% smaller than JPEG/PNG
 * - Automatic format conversion on-the-fly
 * - Caches converted images for subsequent requests
 * - Falls back to original format if WebP not supported
 */

class ImageOptimizer
{
    private static ?self $instance = null;
    private string $cachePath;
    private int $quality = 80;
    private bool $webpEnabled = true;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->cachePath = dirname(__DIR__) . '/storage/cache/images/';

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        // Check if GD supports WebP
        $this->webpEnabled = function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
    }

    /**
     * Configure the optimizer
     */
    public function configure(array $options): self
    {
        if (isset($options['quality'])) {
            $this->quality = max(1, min(100, (int)$options['quality']));
        }
        if (isset($options['cache_path'])) {
            $this->cachePath = rtrim($options['cache_path'], '/') . '/';
        }
        if (isset($options['webp_enabled'])) {
            $this->webpEnabled = (bool)$options['webp_enabled'] && function_exists('imagewebp');
        }
        return $this;
    }

    /**
     * Get optimized image URL (WebP if supported)
     */
    public function url(string $originalPath, bool $forceWebp = false): string
    {
        if (!$this->webpEnabled && !$forceWebp) {
            return $originalPath;
        }

        // Check if browser supports WebP
        if (!$forceWebp && !$this->browserSupportsWebp()) {
            return $originalPath;
        }

        $webpPath = $this->getWebpPath($originalPath);
        if ($webpPath) {
            return $webpPath;
        }

        return $originalPath;
    }

    /**
     * Convert an image to WebP format
     */
    public function toWebp(string $sourcePath, ?string $destPath = null, ?int $quality = null): ?string
    {
        if (!$this->webpEnabled) {
            return null;
        }

        $quality = $quality ?? $this->quality;
        $fullSourcePath = $this->getFullPath($sourcePath);

        if (!file_exists($fullSourcePath)) {
            return null;
        }

        // Determine destination path
        if ($destPath === null) {
            $hash = md5($sourcePath . filemtime($fullSourcePath));
            $destPath = $this->cachePath . $hash . '.webp';
        }

        // Return cached version if exists and is newer than source
        if (file_exists($destPath) && filemtime($destPath) >= filemtime($fullSourcePath)) {
            return $this->getRelativePath($destPath);
        }

        // Get source image type
        $imageInfo = @getimagesize($fullSourcePath);
        if (!$imageInfo) {
            return null;
        }

        $sourceImage = null;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($fullSourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($fullSourcePath);
                // Preserve transparency
                if ($sourceImage) {
                    imagepalettetotruecolor($sourceImage);
                    imagealphablending($sourceImage, true);
                    imagesavealpha($sourceImage, true);
                }
                break;
            case IMAGETYPE_GIF:
                $sourceImage = @imagecreatefromgif($fullSourcePath);
                break;
            case IMAGETYPE_WEBP:
                // Already WebP, just copy
                copy($fullSourcePath, $destPath);
                return $this->getRelativePath($destPath);
        }

        if (!$sourceImage) {
            return null;
        }

        // Convert to WebP
        $success = imagewebp($sourceImage, $destPath, $quality);
        imagedestroy($sourceImage);

        if ($success) {
            return $this->getRelativePath($destPath);
        }

        return null;
    }

    /**
     * Resize and convert image
     */
    public function resize(string $sourcePath, int $maxWidth, int $maxHeight, bool $webp = true): ?string
    {
        $fullSourcePath = $this->getFullPath($sourcePath);

        if (!file_exists($fullSourcePath)) {
            return null;
        }

        $imageInfo = @getimagesize($fullSourcePath);
        if (!$imageInfo) {
            return null;
        }

        $origWidth = $imageInfo[0];
        $origHeight = $imageInfo[1];

        // Calculate new dimensions
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
        if ($ratio >= 1) {
            // Image is already smaller than target
            return $webp ? $this->toWebp($sourcePath) : $sourcePath;
        }

        $newWidth = (int)($origWidth * $ratio);
        $newHeight = (int)($origHeight * $ratio);

        // Generate cache key
        $hash = md5($sourcePath . $maxWidth . 'x' . $maxHeight . filemtime($fullSourcePath));
        $ext = $webp && $this->webpEnabled ? 'webp' : pathinfo($sourcePath, PATHINFO_EXTENSION);
        $destPath = $this->cachePath . $hash . '.' . $ext;

        if (file_exists($destPath)) {
            return $this->getRelativePath($destPath);
        }

        // Create source image
        $sourceImage = null;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($fullSourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($fullSourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = @imagecreatefromgif($fullSourcePath);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = @imagecreatefromwebp($fullSourcePath);
                break;
        }

        if (!$sourceImage) {
            return null;
        }

        // Create resized image
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        if ($imageInfo[2] === IMAGETYPE_PNG || $ext === 'png') {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($sourceImage);

        // Save resized image
        $success = false;
        if ($ext === 'webp' && $this->webpEnabled) {
            $success = imagewebp($resizedImage, $destPath, $this->quality);
        } elseif ($ext === 'png') {
            $success = imagepng($resizedImage, $destPath, 9);
        } else {
            $success = imagejpeg($resizedImage, $destPath, $this->quality);
        }

        imagedestroy($resizedImage);

        return $success ? $this->getRelativePath($destPath) : null;
    }

    /**
     * Generate responsive srcset
     */
    public function srcset(string $sourcePath, array $widths = [320, 640, 1024, 1280]): string
    {
        $srcset = [];

        foreach ($widths as $width) {
            $resized = $this->resize($sourcePath, $width, $width * 2, true);
            if ($resized) {
                $srcset[] = $resized . ' ' . $width . 'w';
            }
        }

        return implode(', ', $srcset);
    }

    /**
     * Check if browser supports WebP
     */
    private function browserSupportsWebp(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'image/webp') !== false;
    }

    /**
     * Get cached WebP path for an image
     */
    private function getWebpPath(string $originalPath): ?string
    {
        $fullPath = $this->getFullPath($originalPath);
        if (!file_exists($fullPath)) {
            return null;
        }

        $hash = md5($originalPath . filemtime($fullPath));
        $webpPath = $this->cachePath . $hash . '.webp';

        if (file_exists($webpPath)) {
            return $this->getRelativePath($webpPath);
        }

        // Convert on-the-fly
        return $this->toWebp($originalPath, $webpPath);
    }

    /**
     * Get full filesystem path
     */
    private function getFullPath(string $path): string
    {
        if (strpos($path, '/') === 0) {
            return dirname(__DIR__) . $path;
        }
        return dirname(__DIR__) . '/' . $path;
    }

    /**
     * Get relative path for URL
     */
    private function getRelativePath(string $fullPath): string
    {
        $basePath = dirname(__DIR__);
        if (strpos($fullPath, $basePath) === 0) {
            return substr($fullPath, strlen($basePath));
        }
        return $fullPath;
    }

    /**
     * Clear image cache
     */
    public function clearCache(): int
    {
        $count = 0;
        $files = glob($this->cachePath . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get cache statistics
     */
    public function stats(): array
    {
        $files = glob($this->cachePath . '*');
        $totalSize = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                $totalSize += filesize($file);
            }
        }

        return [
            'cached_images' => count($files),
            'cache_size' => $totalSize,
            'cache_size_human' => $this->formatBytes($totalSize),
            'webp_enabled' => $this->webpEnabled,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Get optimized image URL (WebP if supported)
 */
if (!function_exists('optimized_image')) {
    function optimized_image(string $path): string
    {
        return ImageOptimizer::getInstance()->url($path);
    }
}

/**
 * Get resized image URL
 */
if (!function_exists('resized_image')) {
    function resized_image(string $path, int $width, int $height = 0): ?string
    {
        return ImageOptimizer::getInstance()->resize($path, $width, $height ?: $width);
    }
}

/**
 * Generate responsive srcset
 */
if (!function_exists('image_srcset')) {
    function image_srcset(string $path, array $widths = [320, 640, 1024]): string
    {
        return ImageOptimizer::getInstance()->srcset($path, $widths);
    }
}

/**
 * Render optimized image tag with WebP and srcset
 */
if (!function_exists('optimized_img')) {
    function optimized_img(string $src, string $alt, array $attrs = []): string
    {
        $optimizer = ImageOptimizer::getInstance();

        // Get WebP version
        $webpSrc = $optimizer->url($src, true);

        // Build attributes
        $attrStr = '';
        foreach ($attrs as $key => $value) {
            $attrStr .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }

        // If WebP is available, use picture element
        if ($webpSrc !== $src) {
            return '<picture>
                <source srcset="' . htmlspecialchars($webpSrc) . '" type="image/webp">
                <img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"' . $attrStr . ' loading="lazy">
            </picture>';
        }

        return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"' . $attrStr . ' loading="lazy" decoding="async">';
    }
}
