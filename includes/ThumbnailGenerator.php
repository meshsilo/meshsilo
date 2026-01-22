<?php
/**
 * Thumbnail Generator for 3D Models
 *
 * Extracts or generates thumbnail images for 3D model files
 * Primary method: Extract from 3MF files
 * Fallback: OpenSCAD CLI rendering (if available)
 */

class ThumbnailGenerator {
    // Thumbnail storage directory (relative to assets)
    const THUMBNAIL_DIR = 'thumbnails';

    // Supported thumbnail sizes
    const SIZE_SMALL = 128;
    const SIZE_MEDIUM = 256;
    const SIZE_LARGE = 512;

    /**
     * Generate thumbnail for a model
     *
     * @param array $model Model record from database
     * @param int $size Desired size (width/height)
     * @return string|null Path to thumbnail, or null on failure
     */
    public static function generateThumbnail($model, $size = self::SIZE_MEDIUM) {
        $filePath = getAbsoluteFilePath($model);
        if (!$filePath || !file_exists($filePath)) {
            return null;
        }

        $fileType = strtolower($model['file_type'] ?? pathinfo($filePath, PATHINFO_EXTENSION));

        // Try extraction from 3MF first
        if ($fileType === '3mf') {
            $thumbnail = self::extractFrom3MF($filePath, $model['id'], $size);
            if ($thumbnail) {
                self::updateThumbnailPath($model['id'], $thumbnail);
                return $thumbnail;
            }
        }

        // Try OpenSCAD rendering for STL files
        if ($fileType === 'stl' && self::isOpenSCADAvailable()) {
            $thumbnail = self::renderWithOpenSCAD($filePath, $model['id'], $size);
            if ($thumbnail) {
                self::updateThumbnailPath($model['id'], $thumbnail);
                return $thumbnail;
            }
        }

        return null;
    }

    /**
     * Extract thumbnail from 3MF file
     *
     * 3MF files are ZIP archives that may contain thumbnail images
     * Common locations: Thumbnails/thumbnail.png, /Metadata/thumbnail.png
     */
    public static function extractFrom3MF($filePath, $modelId, $size = self::SIZE_MEDIUM) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $thumbnailData = null;

        // Look for thumbnail in common locations
        $possiblePaths = [
            'Thumbnails/thumbnail.png',
            'Metadata/thumbnail.png',
            '_rels/.rels', // Check relationships for thumbnail
        ];

        // First try direct paths
        foreach (['Thumbnails/thumbnail.png', 'Metadata/thumbnail.png'] as $path) {
            $data = $zip->getFromName($path);
            if ($data !== false) {
                $thumbnailData = $data;
                break;
            }
        }

        // If not found, search for any PNG in common thumbnail directories
        if (!$thumbnailData) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/^(Thumbnails|Metadata)\/.*\.png$/i', $name)) {
                    $thumbnailData = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        // Also check for JPEG thumbnails
        if (!$thumbnailData) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/^(Thumbnails|Metadata)\/.*\.(jpg|jpeg)$/i', $name)) {
                    $thumbnailData = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if (!$thumbnailData) {
            return null;
        }

        // Save and resize thumbnail
        return self::saveAndResizeThumbnail($thumbnailData, $modelId, $size);
    }

    /**
     * Render thumbnail using OpenSCAD
     *
     * Requires OpenSCAD to be installed and available in PATH
     */
    public static function renderWithOpenSCAD($stlPath, $modelId, $size = self::SIZE_MEDIUM) {
        if (!self::isOpenSCADAvailable()) {
            return null;
        }

        $thumbnailDir = self::ensureThumbnailDir();
        $outputPath = $thumbnailDir . '/' . $modelId . '.png';
        $tempScad = sys_get_temp_dir() . '/silo_render_' . $modelId . '.scad';

        // Create temporary OpenSCAD file to import and render the STL
        $scadContent = sprintf(
            'import("%s");',
            addslashes(realpath($stlPath))
        );
        file_put_contents($tempScad, $scadContent);

        // Render with OpenSCAD
        $command = sprintf(
            'openscad -o %s --imgsize=%d,%d --camera=0,0,0,55,0,25,500 --colorscheme=Tomorrow %s 2>&1',
            escapeshellarg($outputPath),
            $size,
            $size,
            escapeshellarg($tempScad)
        );

        exec($command, $output, $returnCode);

        // Clean up temp file
        @unlink($tempScad);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            return null;
        }

        return self::THUMBNAIL_DIR . '/' . $modelId . '.png';
    }

    /**
     * Save thumbnail data and resize to target size
     */
    private static function saveAndResizeThumbnail($imageData, $modelId, $size) {
        $thumbnailDir = self::ensureThumbnailDir();

        // Load image from data
        $image = @imagecreatefromstring($imageData);
        if (!$image) {
            return null;
        }

        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        // Calculate new dimensions maintaining aspect ratio
        if ($origWidth > $origHeight) {
            $newWidth = $size;
            $newHeight = (int)($origHeight * ($size / $origWidth));
        } else {
            $newHeight = $size;
            $newWidth = (int)($origWidth * ($size / $origHeight));
        }

        // Create resized image
        $resized = imagecreatetruecolor($size, $size);

        // Fill with transparent background
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        // Center the image
        $offsetX = (int)(($size - $newWidth) / 2);
        $offsetY = (int)(($size - $newHeight) / 2);

        imagecopyresampled(
            $resized, $image,
            $offsetX, $offsetY, 0, 0,
            $newWidth, $newHeight, $origWidth, $origHeight
        );

        // Save as PNG
        $outputPath = $thumbnailDir . '/' . $modelId . '.png';
        imagepng($resized, $outputPath, 9);

        imagedestroy($image);
        imagedestroy($resized);

        return self::THUMBNAIL_DIR . '/' . $modelId . '.png';
    }

    /**
     * Ensure thumbnail directory exists
     */
    private static function ensureThumbnailDir() {
        $dir = rtrim(UPLOAD_PATH, '/') . '/' . self::THUMBNAIL_DIR;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Check if OpenSCAD is available
     */
    public static function isOpenSCADAvailable() {
        static $available = null;

        if ($available === null) {
            exec('which openscad 2>/dev/null', $output, $returnCode);
            $available = $returnCode === 0;
        }

        return $available;
    }

    /**
     * Update model's thumbnail path in database
     */
    private static function updateThumbnailPath($modelId, $thumbnailPath) {
        $db = getDB();

        // Ensure column exists
        self::ensureThumbnailColumn($db);

        $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
        $stmt->bindValue(':path', $thumbnailPath, SQLITE3_TEXT);
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    /**
     * Ensure the thumbnail_path column exists
     */
    private static function ensureThumbnailColumn($db) {
        static $checked = false;
        if ($checked) return;

        try {
            if ($db->getType() === 'mysql') {
                $db->exec('ALTER TABLE models ADD COLUMN thumbnail_path VARCHAR(255) DEFAULT NULL');
            } else {
                $db->exec('ALTER TABLE models ADD COLUMN thumbnail_path TEXT DEFAULT NULL');
            }
        } catch (Exception $e) {
            // Column probably already exists
        }

        $checked = true;
    }

    /**
     * Get thumbnail URL for a model
     *
     * @param array $model Model record
     * @return string|null URL to thumbnail, or null if not available
     */
    public static function getThumbnailUrl($model) {
        if (!empty($model['thumbnail_path'])) {
            return 'assets/' . $model['thumbnail_path'];
        }

        return null;
    }

    /**
     * Delete thumbnail for a model
     */
    public static function deleteThumbnail($modelId) {
        $thumbnailDir = rtrim(UPLOAD_PATH, '/') . '/' . self::THUMBNAIL_DIR;
        $path = $thumbnailDir . '/' . $modelId . '.png';

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Batch generate thumbnails for models without them
     *
     * @param int $limit Max models to process
     * @return array Results summary
     */
    public static function batchGenerate($limit = 100) {
        $db = getDB();

        // Ensure column exists
        self::ensureThumbnailColumn($db);

        // Get models without thumbnails, prefer 3MF files
        $stmt = $db->prepare("
            SELECT id, name, file_path, file_type
            FROM models
            WHERE (thumbnail_path IS NULL OR thumbnail_path = '')
            AND parent_id IS NULL
            AND file_type IN ('3mf', 'stl')
            ORDER BY CASE WHEN file_type = '3mf' THEN 0 ELSE 1 END, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0
        ];

        while ($model = $result->fetchArray(SQLITE3_ASSOC)) {
            $results['processed']++;

            $thumbnail = self::generateThumbnail($model);

            if ($thumbnail) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }
}
