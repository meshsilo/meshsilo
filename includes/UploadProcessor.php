<?php

declare(strict_types=1);

/**
 * Upload Processor
 *
 * Reusable model import logic for both the upload page and the background
 * ProcessUpload job. Handles single-file and zip uploads.
 *
 * Extracted from app/pages/upload.php to avoid duplication between the
 * synchronous upload path and the tus-based async path.
 */
class UploadProcessor
{
    /**
     * Process a single model file upload.
     *
     * Creates a parent model + one child part (matching the current upload.php pattern
     * where even single files get a parent/child structure).
     *
     * @param string $filePath     Absolute path to the uploaded file on disk
     * @param string $originalName Original filename (e.g., "model.stl")
     * @param array  $metadata     Keys: name, description, creator, collection, source_url, categories, user_id
     * @return array ['success' => bool, 'parent_id' => int, 'part_count' => int, 'error' => string]
     */
    public static function processSingleFile(string $filePath, string $originalName, array $metadata, ?int $existingParentId = null, ?string $existingFolderId = null): array
    {
        $db = getDB();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!function_exists('isModelExtension') || !isModelExtension($extension)) {
            return ['success' => false, 'parent_id' => 0, 'part_count' => 0, 'error' => 'Invalid file type'];
        }

        $fileSize = filesize($filePath);
        $folderId = $existingFolderId ?? self::createModelFolder();

        if ($existingParentId) {
            // Reuse existing model row (e.g., placeholder from tus upload)
            $parentId = $existingParentId;
            self::updateParentModelMetadata($db, $parentId, $metadata, $folderId, $fileSize);
        } else {
            $parentId = self::createParentModel($db, $metadata, $fileSize, $folderId);
        }
        if (!$parentId) {
            self::cleanupFolder($folderId);
            return ['success' => false, 'parent_id' => 0, 'part_count' => 0, 'error' => 'Failed to create model entry'];
        }

        $partName = pathinfo($originalName, PATHINFO_FILENAME);
        $partId = self::saveModelFile($db, $filePath, $originalName, $partName, $parentId, $originalName, $folderId);

        if (!$partId) {
            $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
            $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
            $stmt->execute();
            self::cleanupFolder($folderId);
            return ['success' => false, 'parent_id' => 0, 'part_count' => 0, 'error' => 'Failed to save uploaded file'];
        }

        self::updateParentModel($db, $parentId, 1, $fileSize);
        self::fireAfterUploadHook($parentId, $metadata['name'] ?? '', $extension);

        return ['success' => true, 'parent_id' => $parentId, 'part_count' => 1, 'error' => ''];
    }

    /**
     * Process a zip file upload.
     *
     * Extracts model files, images, and attachments. Creates parent model with
     * child parts. Images become thumbnails + attachments, text/PDFs become attachments.
     *
     * @param string $zipPath   Absolute path to the zip file on disk
     * @param array  $metadata  Keys: name, description, creator, collection, source_url, categories, user_id
     * @return array ['success' => bool, 'parent_id' => int, 'part_count' => int, 'error' => string]
     */
    public static function processZip(string $zipPath, array $metadata, ?int $existingParentId = null, ?string $existingFolderId = null): array
    {
        $db = getDB();

        // Open once to validate + count entries for diagnostics
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'parent_id' => 0, 'part_count' => 0, 'error' => 'Failed to open ZIP file'];
        }
        $zipEntryCount = $zip->numFiles;
        $zip->close();

        $extractDir = sys_get_temp_dir() . '/silo_' . uniqid();
        mkdir($extractDir, 0755, true);

        // Prefer the `unzip` binary (InfoZIP) — it handles every compression
        // method PHP's libzip does, plus Deflate64 (method 9, common in
        // Windows-made zips), LZMA (14), and more. Fall back to
        // ZipArchive::extractTo only when the binary isn't installed (rare:
        // Docker image includes it; Linux/macOS dev boxes usually have it).
        $extractMethod = null;
        $extractError = null;
        if (self::unzipBinaryAvailable()) {
            $result = self::extractWithUnzipBinary($zipPath, $extractDir);
            $extractMethod = 'unzip';
            if (!$result['success']) {
                $extractError = "unzip binary failed (exit {$result['exit_code']}): {$result['stderr']}";
            }
        } else {
            $zip = new \ZipArchive();
            $zip->open($zipPath);
            $ok = @$zip->extractTo($extractDir);
            $extractMethod = 'ZipArchive::extractTo';
            if (!$ok) {
                $extractError = 'ZipArchive::extractTo returned false: ' . $zip->getStatusString();
            }
            $zip->close();
        }

        if ($extractError && function_exists('logWarning')) {
            logWarning('ZIP extraction error', [
                'method' => $extractMethod,
                'error' => $extractError,
                'zip_entries' => $zipEntryCount,
            ]);
        }

        // Scan extracted files
        $modelFiles = [];
        $imageFiles = [];
        $attachmentFiles = [];
        $imageExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        $textExtensions = ['txt', 'md'];
        $pdfExtensions = ['pdf'];

        // Track every extension we see for diagnostics — when we can't find
        // model files, this tells the user what was actually in their zip vs.
        // what the system recognizes as a model.
        $extensionsSeen = [];
        $totalFilesScanned = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $totalFilesScanned++;
            $fileExt = strtolower($fileInfo->getExtension());
            $extensionsSeen[$fileExt] = ($extensionsSeen[$fileExt] ?? 0) + 1;

            if (function_exists('isModelExtension') && isModelExtension($fileExt)) {
                $relativePath = str_replace($extractDir . '/', '', $fileInfo->getPathname());
                $modelFiles[] = [
                    'path' => $fileInfo->getPathname(),
                    'filename' => $fileInfo->getFilename(),
                    'relative_path' => $relativePath,
                    'size' => $fileInfo->getSize(),
                ];
            } elseif (in_array($fileExt, $imageExtensions)) {
                $imageFiles[] = ['path' => $fileInfo->getPathname(), 'filename' => $fileInfo->getFilename(), 'extension' => $fileExt];
                $attachmentFiles[] = ['path' => $fileInfo->getPathname(), 'filename' => $fileInfo->getFilename(), 'extension' => $fileExt, 'type' => 'image'];
            } elseif (in_array($fileExt, array_merge($textExtensions, $pdfExtensions))) {
                $attachmentFiles[] = [
                    'path' => $fileInfo->getPathname(),
                    'filename' => $fileInfo->getFilename(),
                    'extension' => $fileExt,
                    'type' => in_array($fileExt, $textExtensions) ? 'text' : 'pdf',
                ];
            }
        }

        usort($modelFiles, fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));

        if (empty($modelFiles)) {
            // Build a diagnostic summary: what did we actually find?
            $allowedModels = function_exists('getModelExtensions') ? getModelExtensions() : [];
            $seen = $extensionsSeen
                ? implode(', ', array_map(fn($e, $n) => ($e ?: '(no extension)') . " ({$n})", array_keys($extensionsSeen), $extensionsSeen))
                : '(none)';
            $allowed = $allowedModels ? implode(', ', $allowedModels) : '(none configured)';

            if (function_exists('logWarning')) {
                logWarning('ZIP upload: no model files found', [
                    'zip_path' => $zipPath,
                    'zip_entries' => $zipEntryCount,
                    'extracted' => $totalFilesScanned,
                    'extract_method' => $extractMethod,
                    'extract_error' => $extractError,
                    'extensions_seen' => $extensionsSeen,
                    'allowed_model_extensions' => $allowedModels,
                ]);
            }

            self::cleanupExtractDir($extractDir);
            return [
                'success' => false,
                'parent_id' => 0,
                'part_count' => 0,
                'error' => "No valid 3D model or slicer files found in the ZIP archive. "
                    . "Zip contained {$zipEntryCount} entries; extracted {$totalFilesScanned}"
                    . ($extractError ? " ({$extractMethod} error: {$extractError})" : '')
                    . ". Found extensions: {$seen}. "
                    . "Recognized model extensions: {$allowed}.",
            ];
        }

        $totalSize = array_sum(array_column($modelFiles, 'size'));
        $folderId = $existingFolderId ?? self::createModelFolder();

        if ($existingParentId) {
            $parentId = $existingParentId;
            self::updateParentModelMetadata($db, $parentId, $metadata, $folderId, $totalSize);
        } else {
            $parentId = self::createParentModel($db, $metadata, $totalSize, $folderId);
        }

        if (!$parentId) {
            self::cleanupExtractDir($extractDir);
            self::cleanupFolder($folderId);
            return ['success' => false, 'parent_id' => 0, 'part_count' => 0, 'error' => 'Failed to create model entry'];
        }

        // Save parts in transaction
        $uploadedCount = 0;
        $db->exec('BEGIN');
        try {
            foreach ($modelFiles as $modelFile) {
                $partName = pathinfo($modelFile['filename'], PATHINFO_FILENAME);
                if (self::saveModelFile($db, $modelFile['path'], $modelFile['filename'], $partName, $parentId, $modelFile['relative_path'], $folderId)) {
                    $uploadedCount++;
                }
            }
            self::updateParentModel($db, $parentId, $uploadedCount, $totalSize);
            $db->exec('COMMIT');
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            if (function_exists('logException')) logException($e, ['action' => 'zip_extraction']);
            self::cleanupExtractDir($extractDir);
            return ['success' => false, 'parent_id' => $parentId, 'part_count' => 0, 'error' => 'Failed during extraction: ' . $e->getMessage()];
        }

        // Thumbnail from first image
        if (!empty($imageFiles)) {
            self::saveThumbnailFromImage($db, $parentId, $imageFiles[0], $folderId);
        }

        // Attachments
        if (!empty($attachmentFiles) && function_exists('isFeatureEnabled') && isFeatureEnabled('attachments')) {
            self::saveAttachments($db, $parentId, $attachmentFiles, $folderId);
        }

        self::cleanupExtractDir($extractDir);
        self::fireAfterUploadHook($parentId, $metadata['name'] ?? '', 'zip');

        if (function_exists('logInfo')) {
            logInfo('ZIP extraction complete', ['parent_id' => $parentId, 'parts' => $uploadedCount, 'folder' => $folderId]);
        }

        return ['success' => true, 'parent_id' => $parentId, 'part_count' => $uploadedCount, 'error' => ''];
    }

    /**
     * Extract a zip and add each model file inside as a new child part of an
     * existing parent model. Does not create a new parent.
     *
     * Used by the "Add Parts" flow on the model page when the user uploads a
     * zip instead of individual model files.
     *
     * @param string $zipPath         Absolute path to the zip file
     * @param int    $parentModelId   Existing parent model id (must exist)
     * @return array ['success' => bool, 'parent_id' => int, 'part_count' => int, 'error' => string]
     */
    public static function addPartsFromZip(string $zipPath, int $parentModelId): array
    {
        $db = getDB();

        // Look up parent
        $stmt = $db->prepare('SELECT id, file_path, filename, file_size, part_count FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $parentModelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $parent = $result->fetchArray(PDO::FETCH_ASSOC);
        if (!$parent) {
            return ['success' => false, 'parent_id' => $parentModelId, 'part_count' => 0, 'error' => 'Parent model not found'];
        }

        // Derive folder id from file_path ("assets/<folder>") or filename
        $folderId = null;
        if (!empty($parent['file_path']) && strpos($parent['file_path'], 'assets/') === 0) {
            $folderId = trim(substr($parent['file_path'], strlen('assets/')), '/');
        }
        if (!$folderId && !empty($parent['filename'])) {
            $folderId = $parent['filename'];
        }
        if (!$folderId) {
            $folderId = self::createModelFolder();
        } elseif (!is_dir(UPLOAD_PATH . $folderId)) {
            // Ensure the folder exists on disk (may not if parent was created but never populated)
            mkdir(UPLOAD_PATH . $folderId, 0755, true);
        }

        // Open zip and extract to temp
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'parent_id' => $parentModelId, 'part_count' => 0, 'error' => 'Failed to open ZIP file'];
        }

        $extractDir = sys_get_temp_dir() . '/silo_addparts_' . uniqid();
        mkdir($extractDir, 0755, true);
        $realExtractDir = realpath($extractDir);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (substr($filename, -1) === '/') continue;

            $targetPath = $extractDir . '/' . $filename;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

            $realTargetPath = realpath($targetDir) . '/' . basename($filename);
            if (strpos($realTargetPath, $realExtractDir) !== 0) {
                if (function_exists('logWarning')) logWarning('ZIP path traversal blocked', ['filename' => $filename]);
                continue;
            }

            $stream = $zip->getStream($filename);
            if ($stream !== false) {
                $outFile = fopen($realTargetPath, 'w');
                if ($outFile !== false) {
                    stream_copy_to_stream($stream, $outFile);
                    fclose($outFile);
                }
                fclose($stream);
            }
        }
        $zip->close();

        // Collect model files
        $modelFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $ext = strtolower($fileInfo->getExtension());
            if (function_exists('isModelExtension') && isModelExtension($ext)) {
                $relativePath = str_replace($extractDir . '/', '', $fileInfo->getPathname());
                $modelFiles[] = [
                    'path' => $fileInfo->getPathname(),
                    'filename' => $fileInfo->getFilename(),
                    'relative_path' => $relativePath,
                    'size' => $fileInfo->getSize(),
                ];
            }
        }
        usort($modelFiles, fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));

        if (empty($modelFiles)) {
            self::cleanupExtractDir($extractDir);
            return ['success' => false, 'parent_id' => $parentModelId, 'part_count' => 0, 'error' => 'No valid 3D model or slicer files found in the ZIP archive'];
        }

        // Save each as a child of the existing parent
        $addedCount = 0;
        $addedSize = 0;
        $db->exec('BEGIN');
        try {
            foreach ($modelFiles as $f) {
                $partName = pathinfo($f['filename'], PATHINFO_FILENAME);
                if (self::saveModelFile($db, $f['path'], $f['filename'], $partName, $parentModelId, $f['relative_path'], $folderId)) {
                    $addedCount++;
                    $addedSize += $f['size'];
                }
            }
            // Recompute parent's part_count from actual DB state (handles legacy inconsistencies)
            $countStmt = $db->prepare('SELECT COUNT(*) FROM models WHERE parent_id = :pid');
            $countStmt->bindValue(':pid', $parentModelId, PDO::PARAM_INT);
            $countStmt->execute();
            $newPartCount = (int)$countStmt->fetchColumn();

            $newSize = (int)($parent['file_size'] ?? 0) + $addedSize;
            self::updateParentModel($db, $parentModelId, $newPartCount, $newSize);
            $db->exec('COMMIT');
        } catch (\Exception $e) {
            $db->exec('ROLLBACK');
            if (function_exists('logException')) logException($e, ['action' => 'add_parts_from_zip']);
            self::cleanupExtractDir($extractDir);
            return ['success' => false, 'parent_id' => $parentModelId, 'part_count' => 0, 'error' => 'Failed during extraction: ' . $e->getMessage()];
        }

        self::cleanupExtractDir($extractDir);

        if (function_exists('logInfo')) {
            logInfo('Added parts from ZIP', ['parent_id' => $parentModelId, 'added' => $addedCount]);
        }

        return ['success' => true, 'parent_id' => $parentModelId, 'part_count' => $addedCount, 'error' => ''];
    }

    // ─── Shared helpers (extracted from upload.php) ─────────────────────────

    public static function createModelFolder(?string $folderName = null): string
    {
        $folderId = $folderName ?? uniqid();
        $folderPath = UPLOAD_PATH . $folderId . '/';
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        return $folderId;
    }

    /**
     * Save a single model file to storage and insert DB row.
     *
     * @return int|false  Model ID on success, false on failure
     */
    public static function saveModelFile($db, string $tmpPath, string $originalName, string $name, ?int $parentId = null, ?string $originalPath = null, ?string $folderId = null)
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (function_exists('isModelExtension') && !isModelExtension($extension)) {
            return false;
        }

        $fileSize = filesize($tmpPath);
        $fileHash = function_exists('calculateContentHash') ? calculateContentHash($tmpPath) : md5_file($tmpPath);

        if (!$folderId) {
            $folderId = self::createModelFolder();
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $folderPath = UPLOAD_PATH . $folderId . '/';

        $subDir = '';
        if ($originalPath && dirname($originalPath) !== '.') {
            $subDir = preg_replace('/[^a-zA-Z0-9._\/-]/', '_', dirname($originalPath));
            $subDir = trim($subDir, '/') . '/';
            $folderPath .= $subDir;
        }

        $filePath = $folderPath . $filename;
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        $moved = @rename($tmpPath, $filePath);
        if (!$moved) {
            $moved = copy($tmpPath, $filePath);
            if ($moved) @unlink($tmpPath);
        }

        if (!$moved) {
            if (function_exists('logError')) logError('Failed to copy uploaded file', ['source' => $tmpPath, 'destination' => $filePath]);
            return false;
        }

        try {
            $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, file_hash, description, creator, collection, source_url, parent_id, original_path) VALUES (:name, :filename, :file_path, :file_size, :file_type, :file_hash, :description, :creator, :collection, :source_url, :parent_id, :original_path)');
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
            $stmt->bindValue(':file_path', 'assets/' . $folderId . '/' . $subDir . $filename, PDO::PARAM_STR);
            $stmt->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
            $stmt->bindValue(':file_type', $extension, PDO::PARAM_STR);
            $stmt->bindValue(':file_hash', $fileHash, PDO::PARAM_STR);
            $stmt->bindValue(':description', $parentId ? '' : '', PDO::PARAM_STR);
            $stmt->bindValue(':creator', '', PDO::PARAM_STR);
            $stmt->bindValue(':collection', '', PDO::PARAM_STR);
            $stmt->bindValue(':source_url', '', PDO::PARAM_STR);
            $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
            $stmt->bindValue(':original_path', $originalPath, PDO::PARAM_STR);
            $stmt->execute();

            $modelId = (int)$db->lastInsertRowID();

            if (function_exists('logInfo')) {
                logInfo('Model uploaded', ['model_id' => $modelId, 'name' => $name, 'file' => $filename]);
            }

            // Queue background jobs
            if (class_exists('Queue')) {
                if ($extension === 'stl' && function_exists('getSetting') && getSetting('auto_convert_stl', '0') === '1') {
                    Queue::push('ConvertStlTo3mf', ['model_id' => $modelId]);
                }
                if (in_array($extension, ['3mf', 'stl'])) {
                    Queue::push('GenerateThumbnail', ['model_id' => $modelId]);
                }
            }

            return $modelId;
        } catch (\Exception $e) {
            if (function_exists('logException')) logException($e, ['action' => 'save_model', 'name' => $name]);
            @unlink($filePath);
            return false;
        }
    }

    /**
     * Create a parent model row for multi-part uploads.
     *
     * @return int|false  Parent model ID on success, false on failure
     */
    public static function createParentModel($db, array $metadata, int $totalSize, string $folderId)
    {
        try {
            $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, description, creator, collection, source_url, part_count) VALUES (:name, :filename, :file_path, :file_size, :file_type, :description, :creator, :collection, :source_url, 0)');
            $stmt->bindValue(':name', $metadata['name'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':filename', $folderId, PDO::PARAM_STR);
            $stmt->bindValue(':file_path', 'assets/' . $folderId, PDO::PARAM_STR);
            $stmt->bindValue(':file_size', $totalSize, PDO::PARAM_INT);
            $stmt->bindValue(':file_type', 'parent', PDO::PARAM_STR);
            $stmt->bindValue(':description', $metadata['description'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':creator', $metadata['creator'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':collection', $metadata['collection'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':source_url', $metadata['source_url'] ?? '', PDO::PARAM_STR);
            $stmt->execute();

            $parentId = (int)$db->lastInsertRowID();

            // Link categories
            $categories = $metadata['categories'] ?? [];
            if (!empty($categories)) {
                foreach ($categories as $categoryId) {
                    $catStmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                    $catStmt->bindValue(':model_id', $parentId, PDO::PARAM_INT);
                    $catStmt->bindValue(':category_id', (int)$categoryId, PDO::PARAM_INT);
                    $catStmt->execute();
                }
            }

            return $parentId;
        } catch (\Exception $e) {
            if (function_exists('logException')) logException($e, ['action' => 'create_parent_model']);
            return false;
        }
    }

    /**
     * Update an existing parent model row to set metadata and folder info.
     * Used when the tus endpoint creates a placeholder row and the background
     * job needs to fill in the real details without creating a new row.
     */
    public static function updateParentModelMetadata($db, int $parentId, array $metadata, string $folderId, int $totalSize): void
    {
        $stmt = $db->prepare('UPDATE models SET name = :name, filename = :filename, file_path = :file_path, file_size = :file_size, file_type = :file_type, description = :description, creator = :creator, collection = :collection, source_url = :source_url WHERE id = :id');
        $stmt->bindValue(':name', $metadata['name'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':filename', $folderId, PDO::PARAM_STR);
        $stmt->bindValue(':file_path', 'assets/' . $folderId, PDO::PARAM_STR);
        $stmt->bindValue(':file_size', $totalSize, PDO::PARAM_INT);
        $stmt->bindValue(':file_type', 'parent', PDO::PARAM_STR);
        $stmt->bindValue(':description', $metadata['description'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':creator', $metadata['creator'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':collection', $metadata['collection'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':source_url', $metadata['source_url'] ?? '', PDO::PARAM_STR);
        $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function updateParentModel($db, int $parentId, int $partCount, int $totalSize): bool
    {
        try {
            $stmt = $db->prepare('UPDATE models SET part_count = :part_count, file_size = :file_size WHERE id = :id');
            $stmt->bindValue(':part_count', $partCount, PDO::PARAM_INT);
            $stmt->bindValue(':file_size', $totalSize, PDO::PARAM_INT);
            $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            if (function_exists('logException')) logException($e, ['action' => 'update_parent_model', 'parent_id' => $parentId]);
            return false;
        }
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    /**
     * Cached check for the InfoZIP `unzip` binary. Cached because this can
     * be called more than once per upload and shelling out to `command -v`
     * isn't free.
     */
    private static ?bool $unzipAvailable = null;
    private static function unzipBinaryAvailable(): bool
    {
        if (self::$unzipAvailable !== null) {
            return self::$unzipAvailable;
        }
        $output = [];
        $code = 1;
        @exec('command -v unzip 2>/dev/null', $output, $code);
        self::$unzipAvailable = ($code === 0 && !empty($output));
        return self::$unzipAvailable;
    }

    /**
     * Extract a zip archive using the InfoZIP `unzip` binary.
     *
     * Used when ZipArchive (libzip) can't read the archive — most commonly
     * because the zip uses Deflate64 (compression method 9), which Windows
     * zip tools produce for larger entries and which libzip does not
     * support.
     *
     * InfoZIP has built-in ZIP Slip protection (refuses absolute paths and
     * ../ components), so we don't need per-entry path validation here.
     *
     * @return array{success: bool, exit_code: int, stderr: string}
     */
    private static function extractWithUnzipBinary(string $zipPath, string $extractDir): array
    {
        $cmd = sprintf(
            'unzip -o -q -- %s -d %s 2>&1',
            escapeshellarg($zipPath),
            escapeshellarg($extractDir)
        );
        $output = [];
        $exitCode = 1;
        @exec($cmd, $output, $exitCode);

        // unzip exit codes: 0 = success, 1 = success with warnings, 2+ = error.
        // Warnings typically come from ZIP Slip attempts being refused — the
        // archive is still usable, so treat 0 and 1 as success.
        $success = ($exitCode === 0 || $exitCode === 1);
        return [
            'success' => $success,
            'exit_code' => $exitCode,
            'stderr' => implode("\n", array_slice($output, 0, 20)),
        ];
    }

    private static function saveThumbnailFromImage($db, int $parentId, array $img, string $folderId): void
    {
        $thumbDir = 'thumbnails';
        $thumbFullDir = UPLOAD_PATH . $thumbDir;
        if (!is_dir($thumbFullDir)) mkdir($thumbFullDir, 0755, true);

        $thumbFilename = $parentId . '_' . time() . '.' . $img['extension'];
        $thumbDest = $thumbFullDir . '/' . $thumbFilename;

        if (copy($img['path'], $thumbDest)) {
            $thumbRelative = $thumbDir . '/' . $thumbFilename;
            $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
            $stmt->bindValue(':path', $thumbRelative, PDO::PARAM_STR);
            $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
            $stmt->execute();

            // Dispatch WebP conversion for JPEG/PNG thumbnails pulled from zip.
            // No-op for already-WebP/GIF files.
            if (class_exists('Queue')) {
                Queue::push('OptimizeImage', ['type' => 'thumbnail', 'model_id' => $parentId]);
            }
        }
    }

    private static function saveAttachments($db, int $parentId, array $attachmentFiles, string $folderId): void
    {
        $attachDir = UPLOAD_PATH . $folderId . '/attachments';
        if (!is_dir($attachDir)) mkdir($attachDir, 0755, true);

        $mimeMap = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
            'txt' => 'text/plain', 'md' => 'text/markdown',
        ];

        foreach ($attachmentFiles as $attFile) {
            $attFilename = $attFile['type'] . '_' . $parentId . '_' . time() . '_' . mt_rand(100, 999) . '.' . $attFile['extension'];
            $attDest = $attachDir . '/' . $attFilename;

            if (!copy($attFile['path'], $attDest)) continue;

            $attRelative = $folderId . '/attachments/' . $attFilename;
            $attMime = $mimeMap[$attFile['extension']] ?? 'application/octet-stream';

            $orderStmt = $db->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM model_attachments WHERE model_id = :model_id');
            $orderStmt->bindValue(':model_id', $parentId, PDO::PARAM_INT);
            $orderStmt->execute();
            $nextOrder = (int)$orderStmt->fetchColumn();

            $stmt = $db->prepare('INSERT INTO model_attachments (model_id, filename, file_path, file_type, mime_type, file_size, original_filename, display_order) VALUES (:model_id, :filename, :file_path, :file_type, :mime_type, :file_size, :original_filename, :display_order)');
            $stmt->bindValue(':model_id', $parentId, PDO::PARAM_INT);
            $stmt->bindValue(':filename', $attFilename, PDO::PARAM_STR);
            $stmt->bindValue(':file_path', $attRelative, PDO::PARAM_STR);
            $stmt->bindValue(':file_type', $attFile['type'], PDO::PARAM_STR);
            $stmt->bindValue(':mime_type', $attMime, PDO::PARAM_STR);
            $stmt->bindValue(':file_size', filesize($attDest), PDO::PARAM_INT);
            $stmt->bindValue(':original_filename', $attFile['filename'], PDO::PARAM_STR);
            $stmt->bindValue(':display_order', $nextOrder, PDO::PARAM_INT);
            $stmt->execute();

            // Dispatch background optimization for this attachment.
            if (class_exists('Queue')) {
                $attachmentId = (int)$db->lastInsertRowID();
                if ($attFile['type'] === 'image') {
                    Queue::push('OptimizeImage', ['type' => 'attachment', 'id' => $attachmentId]);
                } elseif ($attFile['type'] === 'pdf') {
                    Queue::push('OptimizePdf', ['id' => $attachmentId]);
                }
            }
        }

        if (function_exists('logInfo')) {
            logInfo('Saved attachments from ZIP', ['parent_id' => $parentId, 'count' => count($attachmentFiles)]);
        }
    }

    private static function fireAfterUploadHook(int $parentId, string $name, string $fileType): void
    {
        if (class_exists('PluginManager')) {
            PluginManager::applyFilter('after_upload', null, $parentId, [
                'name' => $name,
                'file_type' => $fileType,
                'user_id' => function_exists('isLoggedIn') && isLoggedIn() ? (getCurrentUser()['id'] ?? null) : null,
            ]);
        }
    }

    private static function cleanupFolder(string $folderId): void
    {
        $dir = UPLOAD_PATH . $folderId;
        if (is_dir($dir)) @rmdir($dir);
    }

    public static function cleanupExtractDir(string $extractDir): void
    {
        if (!is_dir($extractDir)) return;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getRealPath());
            } else {
                @unlink($fileInfo->getRealPath());
            }
        }
        @rmdir($extractDir);
    }
}
