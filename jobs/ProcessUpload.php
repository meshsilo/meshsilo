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

        if (!$uploadId || !$modelId) {
            throw new \Exception('ProcessUpload: missing upload_id or model_id');
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
