<?php

declare(strict_types=1);

/**
 * Tus 1.0.0 Protocol Server
 *
 * Lightweight implementation of the tus resumable upload protocol.
 * Supports: Core protocol, Creation extension, Termination extension.
 *
 * @see https://tus.io/protocols/resumable-upload
 */
class TusServer
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Handle OPTIONS — advertise tus capabilities.
     */
    public function handleOptions(): array
    {
        return [
            'status' => 204,
            'headers' => $this->tusHeaders([
                'Tus-Extension' => 'creation,termination',
                'Tus-Max-Size' => (string)(defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 10 * 1024 * 1024 * 1024),
            ]),
            'body' => '',
        ];
    }

    /**
     * Handle POST — create a new upload resource.
     *
     * @param int    $uploadLength  Total file size in bytes (from Upload-Length header)
     * @param array  $metadata      Key-value pairs from Upload-Metadata header (values are base64-encoded)
     * @param string $baseUrl       Base URL for Location header (e.g., '/actions/tus')
     * @param int    $maxFileSize   Max allowed file size in bytes (0 = no limit)
     */
    public function handleCreate(int $uploadLength, array $metadata, string $baseUrl, int $maxFileSize = 0): array
    {
        if ($uploadLength <= 0) {
            return [
                'status' => 400,
                'headers' => $this->tusHeaders(),
                'body' => 'Upload-Length is required and must be > 0',
            ];
        }

        if ($maxFileSize > 0 && $uploadLength > $maxFileSize) {
            return [
                'status' => 413,
                'headers' => $this->tusHeaders(),
                'body' => 'Upload exceeds maximum file size',
            ];
        }

        $uploadId = $this->generateUploadId();

        // Write metadata + upload info to JSON sidecar
        $info = [
            'id' => $uploadId,
            'length' => $uploadLength,
            'offset' => 0,
            'metadata' => $metadata,
            'created_at' => time(),
        ];
        file_put_contents(
            $this->infoPath($uploadId),
            json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Create empty data file
        touch($this->dataPath($uploadId));

        $location = rtrim($baseUrl, '/') . '/' . $uploadId;

        return [
            'status' => 201,
            'headers' => $this->tusHeaders([
                'Location' => $location,
                'Upload-Offset' => '0',
            ]),
            'body' => '',
            'upload_id' => $uploadId,
        ];
    }

    /**
     * Handle PATCH — receive a chunk of upload data.
     *
     * @param string $uploadId   Upload identifier
     * @param string $data       Raw chunk data
     * @param int    $offset     Expected offset (from Upload-Offset header)
     */
    public function handlePatch(string $uploadId, string $data, int $offset): array
    {
        $info = $this->loadInfo($uploadId);
        if ($info === null) {
            return [
                'status' => 404,
                'headers' => $this->tusHeaders(),
                'body' => 'Upload not found',
            ];
        }

        // Verify offset matches current position
        if ($offset !== $info['offset']) {
            return [
                'status' => 409,
                'headers' => $this->tusHeaders([
                    'Upload-Offset' => (string)$info['offset'],
                ]),
                'body' => 'Offset mismatch',
            ];
        }

        // Append chunk to data file
        $dataPath = $this->dataPath($uploadId);
        $expectedBytes = strlen($data);
        $written = file_put_contents($dataPath, $data, FILE_APPEND | LOCK_EX);
        if ($written === false || $written !== $expectedBytes) {
            return [
                'status' => 500,
                'headers' => $this->tusHeaders(),
                'body' => 'Failed to write chunk',
            ];
        }

        // Update offset using actual bytes written
        $newOffset = $info['offset'] + $written;
        $info['offset'] = $newOffset;
        file_put_contents(
            $this->infoPath($uploadId),
            json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $complete = ($newOffset >= $info['length']);

        return [
            'status' => 204,
            'headers' => $this->tusHeaders([
                'Upload-Offset' => (string)$newOffset,
            ]),
            'body' => '',
            'complete' => $complete,
            'upload_id' => $uploadId,
        ];
    }

    /**
     * Handle HEAD — return current upload offset.
     */
    public function handleHead(string $uploadId): array
    {
        $info = $this->loadInfo($uploadId);
        if ($info === null) {
            return [
                'status' => 404,
                'headers' => $this->tusHeaders(),
                'body' => 'Upload not found',
            ];
        }

        return [
            'status' => 200,
            'headers' => $this->tusHeaders([
                'Upload-Offset' => (string)$info['offset'],
                'Upload-Length' => (string)$info['length'],
                'Cache-Control' => 'no-store',
            ]),
            'body' => '',
        ];
    }

    /**
     * Handle DELETE — terminate and remove an upload.
     */
    public function handleDelete(string $uploadId): array
    {
        $info = $this->loadInfo($uploadId);
        if ($info === null) {
            return [
                'status' => 404,
                'headers' => $this->tusHeaders(),
                'body' => 'Upload not found',
            ];
        }

        @unlink($this->dataPath($uploadId));
        @unlink($this->infoPath($uploadId));

        return [
            'status' => 204,
            'headers' => $this->tusHeaders(),
            'body' => '',
        ];
    }

    /**
     * Get decoded metadata for an upload.
     */
    public function getMetadata(string $uploadId): ?array
    {
        $info = $this->loadInfo($uploadId);
        return $info['metadata'] ?? null;
    }

    /**
     * Get full upload info (id, length, offset, metadata, created_at).
     */
    public function getUploadInfo(string $uploadId): ?array
    {
        return $this->loadInfo($uploadId);
    }

    /**
     * Get the path to the assembled data file for a completed upload.
     */
    public function getDataPath(string $uploadId): string
    {
        return $this->dataPath($uploadId);
    }

    /**
     * Delete staging files for an upload (after processing).
     */
    public function cleanup(string $uploadId): void
    {
        @unlink($this->dataPath($uploadId));
        @unlink($this->infoPath($uploadId));
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function generateUploadId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function dataPath(string $id): string
    {
        return $this->storageDir . '/' . $id . '.bin';
    }

    private function infoPath(string $id): string
    {
        return $this->storageDir . '/' . $id . '.json';
    }

    private function loadInfo(string $id): ?array
    {
        // Validate ID format — prevent path traversal
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            return null;
        }

        $path = $this->infoPath($id);
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function tusHeaders(array $extra = []): array
    {
        return array_merge([
            'Tus-Resumable' => '1.0.0',
            'Tus-Version' => '1.0.0',
        ], $extra);
    }
}
