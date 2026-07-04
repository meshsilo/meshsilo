<?php

/**
 * Statistics Service
 *
 * Encapsulates the statistics-gathering queries and the destructive
 * maintenance operations that back the admin Statistics page. Display
 * logic lives in app/admin/stats.php; this class owns the data access
 * and the DELETE/UPDATE/unlink/queue side effects.
 *
 * The SQL and guards here are moved verbatim from the original inline
 * implementation in app/admin/stats.php to preserve exact behavior.
 */
class StatsService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? getDB();
    }

    // =====================================================================
    // Maintenance operations (destructive)
    // =====================================================================

    /**
     * Delete a single missing file entry from the database.
     * Returns true when a deletion was performed (i.e. a valid id was given).
     */
    public function deleteMissing(int $modelId): bool
    {
        if (!$modelId) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $stmt->execute();
        logInfo('Removed missing file from database', ['model_id' => $modelId]);
        return true;
    }

    /**
     * Delete all missing file entries (parts with missing files).
     * Processes in batches to avoid memory exhaustion. Returns the count deleted.
     */
    public function deleteAllMissing(): int
    {
        $batchSize = 100;
        $offset = 0;
        $deletedCount = 0;

        while (true) {
            // Use prepared statement with parameter binding for LIMIT/OFFSET to prevent SQL injection
            $stmt = $this->db->prepare("SELECT id, file_path, dedup_path, file_type, part_count FROM models WHERE file_path IS NOT NULL LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $result = $stmt->execute();
            $hasRows = false;
            $idsToDelete = [];

            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $hasRows = true;
                // Skip parent models (ZIP containers) - they don't have actual files
                if ($row['file_type'] === 'zip' && $row['part_count'] > 0) {
                    continue;
                }
                $filePath = getAbsoluteFilePath($row);
                if (!file_exists($filePath) || !is_file($filePath)) {
                    $idsToDelete[] = $row['id'];
                }
            }

            // Delete the missing entries in this batch
            foreach ($idsToDelete as $id) {
                // Get parent_id to update part count later
                $stmt = $this->db->prepare('SELECT parent_id FROM models WHERE id = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $parentResult = $stmt->execute();
                $parentRow = $parentResult->fetchArray(PDO::FETCH_ASSOC);

                $stmt = $this->db->prepare('DELETE FROM models WHERE id = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();

                // Update parent's part count if this was a child
                if ($parentRow && $parentRow['parent_id']) {
                    $countStmt = $this->db->prepare('SELECT COUNT(*) FROM models WHERE parent_id = :pid');
                    $countStmt->bindValue(':pid', $parentRow['parent_id'], PDO::PARAM_INT);
                    $countStmt->execute();
                    $partCount = (int)$countStmt->fetchColumn();
                    $stmt = $this->db->prepare('UPDATE models SET part_count = :count WHERE id = :id');
                    $stmt->bindValue(':count', $partCount, PDO::PARAM_INT);
                    $stmt->bindValue(':id', $parentRow['parent_id'], PDO::PARAM_INT);
                    $stmt->execute();
                }

                $deletedCount++;
            }

            if (!$hasRows) break;
            // Only advance offset by rows NOT deleted - deleted rows shift subsequent rows
            $offset += $batchSize - count($idsToDelete);
        }

        logInfo('Removed all missing files from database', ['count' => $deletedCount]);
        return $deletedCount;
    }

    /**
     * Delete a single orphaned file from disk.
     * Returns true only when a file was actually removed (guards preserved).
     */
    public function deleteOrphan(string $filename): bool
    {
        if ($filename && !str_contains($filename, '..') && !str_contains($filename, '/')) {
            $filePath = UPLOAD_PATH . $filename;
            if (file_exists($filePath) && is_file($filePath)) {
                unlink($filePath);
                logInfo('Deleted orphaned file', ['filename' => $filename]);
                return true;
            }
        }
        return false;
    }

    /**
     * Delete all orphaned files from disk.
     * Returns the count deleted, or null when the assets path is unavailable
     * (matching the original behavior where no message was shown in that case).
     */
    public function deleteAllOrphans(): ?int
    {
        // Load all known filenames into a set first, then compare against disk
        $assetsPath = realpath(UPLOAD_PATH);
        if ($assetsPath && is_dir($assetsPath)) {
            $knownFiles = [];
            $stmt = $this->db->query('SELECT DISTINCT filename FROM models WHERE filename IS NOT NULL');
            while ($row = $stmt->fetchArray(PDO::FETCH_ASSOC)) {
                $knownFiles[$row['filename']] = true;
            }

            $deletedCount = 0;
            $iterator = new DirectoryIterator($assetsPath);
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                    if (!isset($knownFiles[$file->getFilename()])) {
                        unlink($file->getPathname());
                        $deletedCount++;
                    }
                }
            }
            logInfo('Deleted all orphaned files', ['count' => $deletedCount]);
            return $deletedCount;
        }
        return null;
    }

    /**
     * Calculate missing file hashes.
     * Returns ['calculated' => int, 'errors' => int].
     */
    public function calculateHashes(): array
    {
        $result = calculateMissingHashes();
        $calculated = is_array($result) ? ($result['calculated'] ?? 0) : (int)$result;
        $errors = is_array($result) ? ($result['errors'] ?? 0) : 0;
        return ['calculated' => $calculated, 'errors' => $errors];
    }

    /**
     * Recalculate 3MF hashes using content-based hashing.
     * Returns the number of 3MF files reprocessed.
     */
    public function recalculate3mfHashes(): int
    {
        // Calls the global recalculate3mfHashes() from dedup.php.
        return recalculate3mfHashes();
    }

    /**
     * Run the deduplication scan. Returns the global scan result array.
     */
    public function runDeduplicationScan(): array
    {
        // Calls the global runDeduplicationScan() from dedup.php.
        return runDeduplicationScan();
    }

    /**
     * Run the cleanup scan (migrate single-reference files back).
     * Returns the global cleanup result array.
     */
    public function runDedupCleanupScan(): array
    {
        // Calls the global runDedupCleanupScan() from dedup.php.
        return runDedupCleanupScan();
    }

    /**
     * Retroactively dispatch OptimizePdf jobs for every PDF attachment that
     * hasn't already been compressed. Returns the number of jobs queued.
     */
    public function queueAllPdfCompression(): int
    {
        // The job itself handles the "skip if already flagged" check, but we
        // filter here too to keep the queue slim.
        $queued = 0;
        $stmt = $this->db->prepare("SELECT id FROM model_attachments WHERE file_type = 'pdf' AND (pdf_compressed IS NULL OR pdf_compressed = 0)");
        $result = $stmt->execute();
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            Queue::push('OptimizePdf', ['id' => (int)$row['id']], 'pdfs');
            $queued++;
        }
        return $queued;
    }

    /**
     * Retroactively dispatch OptimizeImage jobs for all unconverted JPEG/PNG
     * attachments and model thumbnails. Returns the number of jobs queued.
     */
    public function queueAllImageWebpConversion(): int
    {
        $queued = 0;

        // Image attachments still in PNG/JPG/JPEG format
        $stmt = $this->db->prepare("SELECT id FROM model_attachments WHERE file_type = 'image' AND (LOWER(file_path) LIKE '%.png' OR LOWER(file_path) LIKE '%.jpg' OR LOWER(file_path) LIKE '%.jpeg')");
        $result = $stmt->execute();
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            Queue::push('OptimizeImage', ['type' => 'attachment', 'id' => (int)$row['id']], 'images');
            $queued++;
        }

        // Model thumbnails still in PNG/JPG/JPEG format
        $stmt = $this->db->prepare("SELECT id FROM models WHERE thumbnail_path IS NOT NULL AND (LOWER(thumbnail_path) LIKE '%.png' OR LOWER(thumbnail_path) LIKE '%.jpg' OR LOWER(thumbnail_path) LIKE '%.jpeg')");
        $result = $stmt->execute();
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            Queue::push('OptimizeImage', ['type' => 'thumbnail', 'model_id' => (int)$row['id']], 'images');
            $queued++;
        }

        return $queued;
    }

    // =====================================================================
    // Display statistics (read-only)
    // =====================================================================

    /**
     * Gather every statistic the Statistics page renders.
     * Returns an associative array keyed by the variable names the page uses.
     */
    public function getDisplayStats(): array
    {
        $db = $this->db;

        // Combined model/part/storage stats in a single query
        $coreStats = $db->query("
            SELECT
                SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END) as model_count,
                SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END) as part_count,
                SUM(CASE WHEN (parent_id IS NOT NULL OR (parent_id IS NULL AND part_count = 0))
                          AND file_type != 'parent' AND file_path IS NOT NULL AND file_path != ''
                         THEN file_size ELSE 0 END) as actual_storage,
                SUM(CASE WHEN (parent_id IS NOT NULL OR (parent_id IS NULL AND part_count = 0))
                          AND file_type != 'parent' AND file_path IS NOT NULL AND file_path != ''
                         THEN 1 ELSE 0 END) as file_count,
                SUM(CASE WHEN parent_id IS NULL AND NOT EXISTS (
                    SELECT 1 FROM model_categories mc WHERE mc.model_id = models.id
                ) THEN 1 ELSE 0 END) as uncategorized_count,
                SUM(CASE WHEN parent_id IS NULL AND (source_url IS NULL OR source_url = '')
                         THEN 1 ELSE 0 END) as no_source_count
            FROM models
        ")->fetchArray(PDO::FETCH_ASSOC);

        $modelCount = (int)($coreStats['model_count'] ?? 0);
        $totalParts = (int)($coreStats['part_count'] ?? 0);
        $actualStorage = (int)($coreStats['actual_storage'] ?? 0);
        $fileCount = (int)($coreStats['file_count'] ?? 0);
        $uncategorizedCount = (int)($coreStats['uncategorized_count'] ?? 0);
        $noSourceCount = (int)($coreStats['no_source_count'] ?? 0);
        $assetsPath = realpath(UPLOAD_PATH);

        // Average model size based on actual file count
        $avgModelSize = $fileCount > 0 ? $actualStorage / $fileCount : 0;

        // Get disk space info
        $diskFree = disk_free_space($assetsPath ?: '.');
        $diskTotal = disk_total_space($assetsPath ?: '.');
        $diskUsedPercent = $diskTotal > 0 ? (($diskTotal - $diskFree) / $diskTotal) * 100 : 0;

        // Get database file size
        if (method_exists($db, 'getType') && $db->getType() === 'mysql') {
            try {
                $result = $db->querySingle("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()");
                $dbSize = $result ? (int)$result : 0;
            } catch (Exception $e) {
                $dbSize = 0;
            }
        } else {
            $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
        }

        // Get user stats
        $result = $db->query('SELECT COUNT(*) as total, SUM(is_admin) as admins FROM users');
        $userStats = $result->fetchArray(PDO::FETCH_ASSOC);
        $totalUsers = $userStats['total'] ?? 0;
        $adminUsers = $userStats['admins'] ?? 0;

        // Get models by file type - only count actual files (child parts and
        // standalone models), not parent container rows which don't have files
        // on disk and would double-count file_size.
        $result = $db->query('SELECT file_type, COUNT(*) as count, SUM(file_size) as size FROM models WHERE file_type != "parent" AND parent_id IS NOT NULL OR (parent_id IS NULL AND part_count = 0) GROUP BY file_type ORDER BY count DESC');
        $fileTypes = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $fileTypes[] = $row;
        }

        // Get models by category
        $result = $db->query('
            SELECT c.name, COUNT(mc.model_id) as count
            FROM categories c
            LEFT JOIN model_categories mc ON c.id = mc.category_id
            GROUP BY c.id
            ORDER BY count DESC
        ');
        $categoryStats = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $categoryStats[] = $row;
        }

        // Get models by collection
        $result = $db->query('
            SELECT collection, COUNT(*) as count, SUM(file_size) as size
            FROM models
            WHERE collection IS NOT NULL AND collection != ""
            GROUP BY collection
            ORDER BY count DESC
            LIMIT 10
        ');
        $collectionStats = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $collectionStats[] = $row;
        }

        // Get top creators
        $result = $db->query('
            SELECT creator, COUNT(*) as count, SUM(file_size) as size
            FROM models
            WHERE creator IS NOT NULL AND creator != ""
            GROUP BY creator
            ORDER BY count DESC
            LIMIT 10
        ');
        $creatorStats = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $creatorStats[] = $row;
        }

        // Get recent uploads (last 7 days)
        $dbType = $db->getType();
        if ($dbType === 'mysql') {
            $result = $db->query('
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM models
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ');
        } else {
            $result = $db->query('
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM models
                WHERE created_at >= datetime("now", "-7 days")
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ');
        }
        $recentUploads = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $recentUploads[] = $row;
        }

        // Get monthly upload trends (last 12 months)
        if ($dbType === 'mysql') {
            $result = $db->query('
                SELECT DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count, SUM(file_size) as size
                FROM models
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, "%Y-%m")
                ORDER BY month DESC
            ');
        } else {
            $result = $db->query('
                SELECT strftime("%Y-%m", created_at) as month, COUNT(*) as count, SUM(file_size) as size
                FROM models
                WHERE created_at >= datetime("now", "-12 months")
                GROUP BY strftime("%Y-%m", created_at)
                ORDER BY month DESC
            ');
        }
        $monthlyUploads = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $monthlyUploads[] = $row;
        }

        // Get conversion statistics
        $result = $db->query('
            SELECT
                COUNT(*) as converted_count,
                SUM(original_size) as total_original_size,
                SUM(file_size) as total_converted_size,
                SUM(original_size - file_size) as total_savings
            FROM models
            WHERE original_size IS NOT NULL AND original_size > 0
        ');
        $conversionStats = $result->fetchArray(PDO::FETCH_ASSOC);

        // Get count of STL files that could be converted
        $result = $db->query('SELECT COUNT(*) as count FROM models WHERE file_type = "stl"');
        $stlCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

        // Get oldest and newest models
        $result = $db->query('SELECT name, created_at FROM models ORDER BY created_at ASC LIMIT 1');
        $oldestModel = $result->fetchArray(PDO::FETCH_ASSOC);

        $result = $db->query('SELECT name, created_at FROM models ORDER BY created_at DESC LIMIT 1');
        $newestModel = $result->fetchArray(PDO::FETCH_ASSOC);

        // Get largest models
        $result = $db->query('SELECT name, file_size, file_type FROM models ORDER BY file_size DESC LIMIT 5');
        $largestModels = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $largestModels[] = $row;
        }

        // Check for missing files (files in DB but not on disk)
        // Limit to first 100 to avoid memory issues on large databases
        $result = $db->query('SELECT id, name, filename, file_path, dedup_path, file_type, part_count FROM models WHERE file_path IS NOT NULL LIMIT 100');
        $missingFiles = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            // Skip parent models (ZIP containers) - they don't have actual files
            if ($row['file_type'] === 'zip' && $row['part_count'] > 0) {
                continue;
            }
            $filePath = getAbsoluteFilePath($row);
            if (!file_exists($filePath) || !is_file($filePath)) {
                $missingFiles[] = $row;
            }
        }

        // Check for orphaned files (files on disk but not in DB)
        // Limit to first 100 to avoid memory issues
        $orphanedFiles = [];
        if ($assetsPath && is_dir($assetsPath)) {
            $iterator = new DirectoryIterator($assetsPath);
            $count = 0;
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                    // Check if file exists in database
                    $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE filename = :filename');
                    $stmt->bindValue(':filename', $file->getFilename(), PDO::PARAM_STR);
                    $result = $stmt->execute();
                    $row = $result->fetchArray(PDO::FETCH_ASSOC);

                    if ($row['count'] == 0) {
                        $orphanedFiles[] = [
                            'filename' => $file->getFilename(),
                            'size' => $file->getSize()
                        ];
                        $count++;
                        if ($count >= 100) break; // Limit to 100 orphaned files
                    }
                }
            }
        }

        // Get deduplication statistics
        $dedupStats = getDeduplicationStats();

        // Count files without hashes
        $result = $db->query('SELECT COUNT(*) as count FROM models WHERE (file_hash IS NULL OR file_hash = "") AND file_path IS NOT NULL');
        $filesWithoutHash = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

        // Image Optimization (WebP) counts
        $unconvertedAttachments = (int)$db->querySingle("SELECT COUNT(*) FROM model_attachments WHERE file_type = 'image' AND (LOWER(file_path) LIKE '%.png' OR LOWER(file_path) LIKE '%.jpg' OR LOWER(file_path) LIKE '%.jpeg')");
        $unconvertedThumbnails = (int)$db->querySingle("SELECT COUNT(*) FROM models WHERE thumbnail_path IS NOT NULL AND (LOWER(thumbnail_path) LIKE '%.png' OR LOWER(thumbnail_path) LIKE '%.jpg' OR LOWER(thumbnail_path) LIKE '%.jpeg')");
        $totalUnconverted = $unconvertedAttachments + $unconvertedThumbnails;

        // PDF Compression counts
        $uncompressedPdfs = (int)$db->querySingle("SELECT COUNT(*) FROM model_attachments WHERE file_type = 'pdf' AND (pdf_compressed IS NULL OR pdf_compressed = 0)");
        $compressedPdfs = (int)$db->querySingle("SELECT COUNT(*) FROM model_attachments WHERE file_type = 'pdf' AND pdf_compressed = 1");

        return [
            'modelCount' => $modelCount,
            'totalParts' => $totalParts,
            'actualStorage' => $actualStorage,
            'fileCount' => $fileCount,
            'uncategorizedCount' => $uncategorizedCount,
            'noSourceCount' => $noSourceCount,
            'avgModelSize' => $avgModelSize,
            'diskFree' => $diskFree,
            'diskTotal' => $diskTotal,
            'diskUsedPercent' => $diskUsedPercent,
            'dbSize' => $dbSize,
            'totalUsers' => $totalUsers,
            'adminUsers' => $adminUsers,
            'fileTypes' => $fileTypes,
            'categoryStats' => $categoryStats,
            'collectionStats' => $collectionStats,
            'creatorStats' => $creatorStats,
            'recentUploads' => $recentUploads,
            'monthlyUploads' => $monthlyUploads,
            'conversionStats' => $conversionStats,
            'stlCount' => $stlCount,
            'oldestModel' => $oldestModel,
            'newestModel' => $newestModel,
            'largestModels' => $largestModels,
            'missingFiles' => $missingFiles,
            'orphanedFiles' => $orphanedFiles,
            'dedupStats' => $dedupStats,
            'filesWithoutHash' => $filesWithoutHash,
            'unconvertedAttachments' => $unconvertedAttachments,
            'unconvertedThumbnails' => $unconvertedThumbnails,
            'totalUnconverted' => $totalUnconverted,
            'uncompressedPdfs' => $uncompressedPdfs,
            'compressedPdfs' => $compressedPdfs,
        ];
    }
}
