<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/dedup.php';
require_once __DIR__ . '/../includes/UploadProcessor.php';

/**
 * Process a completed tus upload.
 *
 * Reads the assembled file from the tus staging area, runs zip extraction or
 * single-file import via UploadProcessor, updates the model's upload_status,
 * and cleans up the staging files.
 */
class ProcessUpload extends Job
{
    public int $maxAttempts = 2;
    public int $timeout = 1800; // 30 minutes

    public function handle(array $data): void
    {
        $uploadId = $data['upload_id'] ?? null;
        $modelId = $data['model_id'] ?? null;
        $filename = $data['filename'] ?? 'unknown';
        $folderId = $data['folder_id'] ?? null;
        $isAddPart = !empty($data['add_part']);

        if (!$uploadId || !$modelId) {
            throw new \Exception('ProcessUpload: missing upload_id or model_id');
        }

        // Handle add-part uploads separately
        if ($isAddPart) {
            $this->handleAddPart($data);
            return;
        }

        $db = getDB();
        $tusDir = dirname(__DIR__) . '/storage/uploads/tus';
        $tusServer = new \TusServer($tusDir);

        // Update status to processing
        $stmt = $db->prepare('UPDATE models SET upload_status = :status WHERE id = :id');
        $stmt->bindValue(':status', 'processing', PDO::PARAM_STR);
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $stmt->execute();

        $dataPath = $tusServer->getDataPath($uploadId);
        if (!file_exists($dataPath)) {
            $this->failModel($db, $modelId, 'Upload file not found in staging area');
            throw new \Exception("ProcessUpload: tus data file not found: $dataPath");
        }

        // Get metadata from tus info
        $info = $tusServer->getUploadInfo($uploadId);
        $metadata = $info['metadata'] ?? [];

        // Decode base64 metadata values
        $meta = [
            'name' => isset($metadata['name']) ? base64_decode($metadata['name']) : pathinfo($filename, PATHINFO_FILENAME),
            'description' => isset($metadata['description']) ? base64_decode($metadata['description']) : '',
            'creator' => isset($metadata['creator']) ? base64_decode($metadata['creator']) : '',
            'collection' => isset($metadata['collection']) ? base64_decode($metadata['collection']) : '',
            'source_url' => isset($metadata['source_url']) ? base64_decode($metadata['source_url']) : '',
            'categories' => isset($metadata['categories']) ? json_decode(base64_decode($metadata['categories']), true) : [],
            'user_id' => $data['user_id'] ?? null,
        ];

        // Verify assembled file size matches expected length
        $expectedLength = $info['length'] ?? 0;
        $actualSize = filesize($dataPath);
        if ($expectedLength > 0 && $actualSize !== $expectedLength) {
            $this->failModel($db, $modelId, "File size mismatch: expected $expectedLength, got $actualSize");
            throw new \Exception("ProcessUpload: assembled file size mismatch ($actualSize vs $expectedLength)");
        }

        // Determine file type
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            // Pass the existing placeholder model_id and folder_id so
            // UploadProcessor reuses the row instead of creating a new one.
            // This prevents the placeholder-delete-404 bug where the user
            // is redirected to a model_id that gets deleted.
            if ($extension === 'zip') {
                $result = UploadProcessor::processZip($dataPath, $meta, $modelId, $folderId);
            } else {
                $result = UploadProcessor::processSingleFile($dataPath, $filename, $meta, $modelId, $folderId);
            }

            if (!$result['success']) {
                $this->failModel($db, $modelId, $result['error']);
                throw new \Exception('ProcessUpload failed: ' . $result['error']);
            }

            // Mark the model as ready — UploadProcessor reused the existing
            // model_id so no placeholder replacement is needed
            $stmt = $db->prepare('UPDATE models SET upload_status = :status WHERE id = :id');
            $stmt->bindValue(':status', 'ready', PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            // Clean up tus staging files
            $tusServer->cleanup($uploadId);

            logInfo('ProcessUpload complete', [
                'upload_id' => $uploadId,
                'model_id' => $modelId,
                'parts' => $result['part_count'],
            ]);
        } catch (\Throwable $e) {
            $this->failModel($db, $modelId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle adding a part to an existing model from a TUS upload.
     */
    private function handleAddPart(array $data): void
    {
        $uploadId = $data['upload_id'];
        $parentId = $data['parent_id'];
        $filename = $data['filename'] ?? 'unknown';
        $folder = $data['folder'] ?? '';

        $db = getDB();
        $tusDir = dirname(__DIR__) . '/storage/uploads/tus';
        $tusServer = new \TusServer($tusDir);

        $dataPath = $tusServer->getDataPath($uploadId);
        if (!file_exists($dataPath)) {
            throw new \Exception("ProcessUpload add-part: tus data file not found: $dataPath");
        }

        // Get parent model
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $parent = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$parent) {
            $tusServer->cleanup($uploadId);
            throw new \Exception("ProcessUpload add-part: parent model $parentId not found");
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExtensions = getAllowedExtensions();
        if (!in_array($extension, $allowedExtensions)) {
            $tusServer->cleanup($uploadId);
            throw new \Exception("ProcessUpload add-part: invalid file type: $extension");
        }

        // Handle ZIP uploads
        if ($extension === 'zip') {
            $result = UploadProcessor::addPartsFromZip($dataPath, $parentId);
            $tusServer->cleanup($uploadId);

            if (!$result['success']) {
                throw new \Exception('ProcessUpload add-part ZIP failed: ' . ($result['error'] ?? 'unknown'));
            }

            logInfo('ProcessUpload add-part (ZIP) complete', [
                'upload_id' => $uploadId,
                'parent_id' => $parentId,
                'parts_added' => $result['part_count'] ?? 0,
            ]);
            return;
        }

        // Single file — create directory and move file
        $relativeDir = 'assets/' . substr(md5($parent['name'] . $parent['id']), 0, 12);
        $modelDir = dirname(__DIR__) . '/' . $relativeDir;
        if (!is_dir($modelDir)) {
            mkdir($modelDir, 0755, true);
        }

        // Handle folder path
        $folder = preg_replace('/[^a-zA-Z0-9._\/ -]/', '_', $folder);
        $folder = str_replace(['../', '..\\', '..'], '', trim($folder, '/'));
        $subDir = '';
        if ($folder !== '' && $folder !== 'Root') {
            $subDir = $folder . '/';
            if (!is_dir($modelDir . '/' . $subDir)) {
                mkdir($modelDir . '/' . $subDir, 0755, true);
            }
        }

        // Handle filename collision
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $destPath = $modelDir . '/' . $subDir . $filename;
        $relativePath = $relativeDir . '/' . $subDir . $filename;
        $counter = 1;
        while (file_exists($destPath)) {
            $newFilename = $baseName . '_' . $counter . '.' . $extension;
            $destPath = $modelDir . '/' . $subDir . $newFilename;
            $relativePath = $relativeDir . '/' . $subDir . $newFilename;
            $filename = $newFilename;
            $counter++;
        }

        // Move from tus staging to final location
        if (!rename($dataPath, $destPath)) {
            $tusServer->cleanup($uploadId);
            throw new \Exception("ProcessUpload add-part: failed to move file to $destPath");
        }

        // Calculate file hash
        $fileHash = null;
        if ($extension === '3mf') {
            $fileHash = calculate3mfContentHash($destPath);
        }
        if (!$fileHash) {
            $fileHash = hash_file('sha256', $destPath);
        }

        $originalPath = $subDir . pathinfo($filename, PATHINFO_BASENAME);
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare('
            INSERT INTO models (name, filename, file_path, file_size, file_type, parent_id, file_hash, original_path, created_at)
            VALUES (:name, :filename, :file_path, :file_size, :file_type, :parent_id, :file_hash, :original_path, :created_at)
        ');
        $stmt->bindValue(':name', pathinfo($filename, PATHINFO_FILENAME), PDO::PARAM_STR);
        $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
        $stmt->bindValue(':file_path', $relativePath, PDO::PARAM_STR);
        $stmt->bindValue(':file_size', filesize($destPath), PDO::PARAM_INT);
        $stmt->bindValue(':file_type', $extension, PDO::PARAM_STR);
        $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
        $stmt->bindValue(':file_hash', $fileHash, PDO::PARAM_STR);
        $stmt->bindValue(':original_path', $originalPath, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->execute();

        // Update parent's part count
        $countStmt = $db->prepare('SELECT COUNT(*) FROM models WHERE parent_id = :id');
        $countStmt->bindValue(':id', $parentId, PDO::PARAM_INT);
        $countStmt->execute();
        $partCount = (int)$countStmt->fetchColumn();
        $stmt = $db->prepare('UPDATE models SET part_count = :count WHERE id = :id');
        $stmt->bindValue(':count', $partCount, PDO::PARAM_INT);
        $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
        $stmt->execute();

        // Clean up tus staging
        $tusServer->cleanup($uploadId);

        logInfo('ProcessUpload add-part complete', [
            'upload_id' => $uploadId,
            'parent_id' => $parentId,
            'filename' => $filename,
        ]);
    }

    private function failModel($db, int $modelId, string $error): void
    {
        try {
            $stmt = $db->prepare('UPDATE models SET upload_status = :status WHERE id = :id');
            $stmt->bindValue(':status', 'failed', PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable $e) {
            logError('ProcessUpload: failed to update model status', ['model_id' => $modelId, 'error' => $e->getMessage()]);
        }
        logError('ProcessUpload failed', ['model_id' => $modelId, 'error' => $error]);
    }
}
