<?php
/**
 * File Integrity Checking for Silo
 *
 * Verifies that uploaded files haven't been corrupted or tampered with.
 * Stores checksums on upload and verifies them periodically.
 *
 * Usage:
 *   Integrity::hashFile('/path/to/file');       // Generate hash
 *   Integrity::verify($modelId);                 // Verify a model
 *   Integrity::verifyAll();                      // Verify all files
 */

class Integrity {
    // Hash algorithm to use
    private const ALGORITHM = 'sha256';

    // Chunk size for hashing large files (8MB)
    private const CHUNK_SIZE = 8 * 1024 * 1024;

    /**
     * Calculate hash for a file
     *
     * @param string $filePath Path to file
     * @return string|null SHA-256 hash or null on failure
     */
    public static function hashFile(string $filePath): ?string {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Use streaming hash for large files
        $context = hash_init(self::ALGORITHM);
        $handle = fopen($filePath, 'rb');

        if (!$handle) {
            return null;
        }

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            hash_update($context, $chunk);
        }

        fclose($handle);

        return hash_final($context);
    }

    /**
     * Store hash for a model/part
     *
     * @param int $modelId Model or part ID
     * @param string|null $hash Hash to store (calculated if null)
     * @return bool Success
     */
    public static function storeHash(int $modelId, ?string $hash = null): bool {
        if (!function_exists('getDB')) {
            return false;
        }

        $db = getDB();

        // Get the file path
        $stmt = $db->prepare('SELECT file_path, dedup_path FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $model = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$model) {
            return false;
        }

        $filePath = self::resolveFilePath($model);
        if (!$filePath) {
            return false;
        }

        // Calculate hash if not provided
        if ($hash === null) {
            $hash = self::hashFile($filePath);
            if ($hash === null) {
                return false;
            }
        }

        // Store the hash
        $updateStmt = $db->prepare('
            UPDATE models SET
                integrity_hash = :hash,
                integrity_checked_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $updateStmt->bindValue(':hash', $hash, PDO::PARAM_STR);
        $updateStmt->bindValue(':id', $modelId, PDO::PARAM_INT);

        return (bool)$updateStmt->execute();
    }

    /**
     * Verify integrity of a model/part
     *
     * @param int $modelId Model or part ID
     * @return array Verification result
     */
    public static function verify(int $modelId): array {
        if (!function_exists('getDB')) {
            return ['status' => 'error', 'message' => 'Database not available'];
        }

        $db = getDB();

        // Get model info
        $stmt = $db->prepare('
            SELECT id, name, file_path, dedup_path, integrity_hash, integrity_checked_at
            FROM models WHERE id = :id
        ');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $model = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$model) {
            return ['status' => 'error', 'message' => 'Model not found'];
        }

        $filePath = self::resolveFilePath($model);

        if (!$filePath || !file_exists($filePath)) {
            self::logIntegrityIssue($modelId, 'missing', 'File not found');
            return [
                'status' => 'missing',
                'message' => 'File not found',
                'model_id' => $modelId,
                'model_name' => $model['name']
            ];
        }

        // Calculate current hash
        $currentHash = self::hashFile($filePath);

        if ($currentHash === null) {
            return ['status' => 'error', 'message' => 'Could not read file'];
        }

        // No stored hash - store it now
        if (empty($model['integrity_hash'])) {
            self::storeHash($modelId, $currentHash);
            return [
                'status' => 'initialized',
                'message' => 'Hash stored for future verification',
                'model_id' => $modelId,
                'hash' => $currentHash
            ];
        }

        // Compare hashes
        if (hash_equals($model['integrity_hash'], $currentHash)) {
            // Update last checked time
            $updateStmt = $db->prepare('
                UPDATE models SET integrity_checked_at = CURRENT_TIMESTAMP WHERE id = :id
            ');
            $updateStmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $updateStmt->execute();

            return [
                'status' => 'valid',
                'message' => 'File integrity verified',
                'model_id' => $modelId,
                'model_name' => $model['name'],
                'hash' => $currentHash
            ];
        }

        // Hash mismatch - file has been modified or corrupted
        self::logIntegrityIssue($modelId, 'corrupted', 'Hash mismatch', [
            'expected' => $model['integrity_hash'],
            'actual' => $currentHash
        ]);

        if (class_exists('Events')) {
            Events::emit('integrity.failure', [
                'model_id' => $modelId,
                'model_name' => $model['name'],
                'expected_hash' => $model['integrity_hash'],
                'actual_hash' => $currentHash
            ]);
        }

        return [
            'status' => 'corrupted',
            'message' => 'File has been modified or corrupted',
            'model_id' => $modelId,
            'model_name' => $model['name'],
            'expected_hash' => $model['integrity_hash'],
            'actual_hash' => $currentHash
        ];
    }

    /**
     * Verify all files
     *
     * @param int $limit Maximum files to check (0 = all)
     * @param bool $prioritizeUnchecked Check files without hashes first
     * @return array Summary of results
     */
    public static function verifyAll(int $limit = 0, bool $prioritizeUnchecked = true): array {
        if (!function_exists('getDB')) {
            return ['error' => 'Database not available'];
        }

        $db = getDB();

        // Build query
        $sql = 'SELECT id FROM models WHERE file_path IS NOT NULL';

        if ($prioritizeUnchecked) {
            $sql .= ' ORDER BY integrity_hash IS NULL DESC, integrity_checked_at ASC';
        } else {
            $sql .= ' ORDER BY integrity_checked_at ASC';
        }

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $result = $db->query($sql);

        $summary = [
            'total' => 0,
            'valid' => 0,
            'corrupted' => 0,
            'missing' => 0,
            'initialized' => 0,
            'errors' => 0,
            'issues' => []
        ];

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $summary['total']++;

            $verification = self::verify($row['id']);

            switch ($verification['status']) {
                case 'valid':
                    $summary['valid']++;
                    break;
                case 'corrupted':
                    $summary['corrupted']++;
                    $summary['issues'][] = $verification;
                    break;
                case 'missing':
                    $summary['missing']++;
                    $summary['issues'][] = $verification;
                    break;
                case 'initialized':
                    $summary['initialized']++;
                    break;
                default:
                    $summary['errors']++;
                    break;
            }
        }

        // Emit summary event
        if (class_exists('Events')) {
            Events::emit('integrity.scan_complete', $summary);
        }

        return $summary;
    }

    /**
     * Get integrity status summary
     */
    public static function getStatus(): array {
        if (!function_exists('getDB')) {
            return [];
        }

        $db = getDB();
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));

        $total = $db->querySingle('SELECT COUNT(*) FROM models WHERE file_path IS NOT NULL');
        $withHash = $db->querySingle('SELECT COUNT(*) FROM models WHERE integrity_hash IS NOT NULL');
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM models
            WHERE integrity_checked_at > :cutoff
        ");
        $stmt->bindValue(':cutoff', $sevenDaysAgo, PDO::PARAM_STR);
        $result = $stmt->execute();
        $recentlyChecked = $result->fetchArray()[0];

        // Get recent issues
        $stmt = $db->prepare("
            SELECT * FROM integrity_log
            WHERE created_at > :cutoff
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->bindValue(':cutoff', $thirtyDaysAgo, PDO::PARAM_STR);
        $issuesResult = $stmt->execute();

        $issues = [];
        if ($issuesResult) {
            while ($row = $issuesResult->fetchArray(PDO::FETCH_ASSOC)) {
                $issues[] = $row;
            }
        }

        return [
            'total_files' => (int)$total,
            'files_with_hash' => (int)$withHash,
            'files_without_hash' => (int)$total - (int)$withHash,
            'recently_checked' => (int)$recentlyChecked,
            'coverage_percent' => $total > 0 ? round(($withHash / $total) * 100, 1) : 0,
            'recent_issues' => $issues
        ];
    }

    /**
     * Get files with integrity issues
     */
    public static function getIssues(int $limit = 50): array {
        if (!function_exists('getDB')) {
            return [];
        }

        $db = getDB();

        $stmt = $db->prepare('
            SELECT il.*, m.name as model_name
            FROM integrity_log il
            LEFT JOIN models m ON il.model_id = m.id
            ORDER BY il.created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $result = $stmt->execute();

        $issues = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $issues[] = $row;
        }

        return $issues;
    }

    /**
     * Resolve file path, handling deduplication
     */
    private static function resolveFilePath(array $model): ?string {
        $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../assets/';

        // Prefer dedup path if available
        if (!empty($model['dedup_path'])) {
            $path = $uploadPath . $model['dedup_path'];
            if (file_exists($path)) {
                return $path;
            }
        }

        // Fall back to regular file path
        if (!empty($model['file_path'])) {
            $path = $uploadPath . $model['file_path'];
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Log an integrity issue
     */
    private static function logIntegrityIssue(int $modelId, string $status, string $message, array $details = []): void {
        if (!function_exists('getDB')) {
            return;
        }

        try {
            $db = getDB();

            // Check if table exists
            $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='integrity_log'");
            if (!$tableCheck) {
                return;
            }

            $stmt = $db->prepare('
                INSERT INTO integrity_log (model_id, status, message, details, created_at)
                VALUES (:model_id, :status, :message, :details, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':message', $message, PDO::PARAM_STR);
            $stmt->bindValue(':details', json_encode($details), PDO::PARAM_STR);
            $stmt->execute();
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Mark an issue as resolved
     */
    public static function resolveIssue(int $issueId, string $resolution): bool {
        if (!function_exists('getDB')) {
            return false;
        }

        $db = getDB();
        $stmt = $db->prepare('
            UPDATE integrity_log SET
                resolved = 1,
                resolution = :resolution,
                resolved_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->bindValue(':resolution', $resolution, PDO::PARAM_STR);
        $stmt->bindValue(':id', $issueId, PDO::PARAM_INT);

        return (bool)$stmt->execute();
    }

    /**
     * Recalculate and update hash for a model (after repair)
     */
    public static function rehash(int $modelId): array {
        $result = self::verify($modelId);

        if ($result['status'] === 'corrupted') {
            // File exists but hash doesn't match - update to new hash
            $db = getDB();

            $stmt = $db->prepare('
                UPDATE models SET
                    integrity_hash = :hash,
                    integrity_checked_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ');
            $stmt->bindValue(':hash', $result['actual_hash'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            return [
                'status' => 'rehashed',
                'message' => 'Hash updated to current file state',
                'new_hash' => $result['actual_hash']
            ];
        }

        return $result;
    }

    /**
     * Calculate hashes for all files that don't have one
     */
    public static function initializeHashes(int $batchSize = 100): array {
        if (!function_exists('getDB')) {
            return ['error' => 'Database not available'];
        }

        $db = getDB();

        $stmt = $db->prepare('
            SELECT id FROM models
            WHERE file_path IS NOT NULL AND integrity_hash IS NULL
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $result = $stmt->execute();

        $processed = 0;
        $errors = 0;

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            if (self::storeHash($row['id'])) {
                $processed++;
            } else {
                $errors++;
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors
        ];
    }
}

// Register integrity check as a scheduled task
if (class_exists('Scheduler')) {
    Scheduler::register(
        Scheduler::TASK_INTEGRITY_CHECK,
        '0 4 * * *', // Daily at 4am
        function() {
            $result = Integrity::verifyAll(500); // Check up to 500 files per run
            return sprintf(
                'Checked %d files: %d valid, %d corrupted, %d missing',
                $result['total'],
                $result['valid'],
                $result['corrupted'],
                $result['missing']
            );
        },
        [
            'description' => 'Verify file integrity checksums',
            'timeout' => 600 // 10 minutes
        ]
    );
}
