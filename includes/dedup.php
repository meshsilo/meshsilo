<?php
/**
 * File Deduplication System
 *
 * Handles deduplication of identical files based on SHA256 hash.
 * Deduplicated files are stored in assets/_dedup/ folder.
 */

define('DEDUP_FOLDER', 'assets/_dedup/');

/**
 * Get the real file path for a model (handles deduplicated files)
 * Returns the path as stored in database (includes assets/ prefix)
 * Use this for URLs
 */
function getRealFilePath($model) {
    if (!empty($model['dedup_path'])) {
        return $model['dedup_path'];
    }
    return $model['file_path'];
}

/**
 * Get the absolute filesystem path for a model file
 * Use this for file operations (file_exists, unlink, etc.)
 */
function getAbsoluteFilePath($model) {
    $relativePath = getRealFilePath($model);
    // Paths in DB include 'assets/' prefix, construct absolute path
    return __DIR__ . '/../' . $relativePath;
}

/**
 * Find all files with duplicate hashes
 * @return array Array of hashes that have duplicates, with count
 */
function findDuplicateHashes() {
    $db = getDB();
    $result = $db->query('
        SELECT file_hash, COUNT(*) as count, SUM(file_size) as total_size
        FROM models
        WHERE file_hash IS NOT NULL
          AND file_hash != ""
          AND parent_id IS NOT NULL
        GROUP BY file_hash
        HAVING COUNT(*) > 1
        ORDER BY count DESC
    ');

    $duplicates = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $duplicates[] = $row;
    }
    return $duplicates;
}

/**
 * Get all parts with a specific hash
 */
function getPartsByHash($hash) {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT m.*, p.name as parent_name
        FROM models m
        LEFT JOIN models p ON m.parent_id = p.id
        WHERE m.file_hash = :hash
        ORDER BY m.id
    ');
    $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
    $result = $stmt->execute();

    $parts = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $parts[] = $row;
    }
    return $parts;
}

/**
 * Deduplicate files with a specific hash
 * Keeps the first file and points all others to it
 * @return array Result with success status and details
 */
function deduplicateByHash($hash) {
    $db = getDB();
    $parts = getPartsByHash($hash);

    if (count($parts) < 2) {
        return ['success' => false, 'error' => 'Not enough duplicates to deduplicate'];
    }

    // Ensure dedup folder exists
    $dedupFolder = __DIR__ . '/../' . DEDUP_FOLDER;
    if (!file_exists($dedupFolder)) {
        mkdir($dedupFolder, 0755, true);
    }

    // Find the first part with an existing file to use as the master
    $masterPart = null;
    $masterFilePath = null;

    foreach ($parts as $part) {
        $filePath = __DIR__ . '/../' . getRealFilePath($part);
        if (file_exists($filePath)) {
            $masterPart = $part;
            $masterFilePath = $filePath;
            break;
        }
    }

    if (!$masterPart) {
        return ['success' => false, 'error' => 'No valid file found for this hash'];
    }

    // Create deduplicated file path using hash
    $extension = pathinfo($masterPart['filename'], PATHINFO_EXTENSION);
    $dedupFilename = $hash . '.' . $extension;
    $dedupPath = DEDUP_FOLDER . $dedupFilename;
    $dedupFullPath = __DIR__ . '/../' . $dedupPath;

    // Copy file to dedup folder if not already there
    if (!file_exists($dedupFullPath)) {
        if (!copy($masterFilePath, $dedupFullPath)) {
            return ['success' => false, 'error' => 'Failed to copy file to dedup folder'];
        }
    }

    // Update all parts to point to the deduplicated file
    $deletedCount = 0;
    $spaceSaved = 0;

    foreach ($parts as $part) {
        $originalPath = __DIR__ . '/../' . $part['file_path'];

        // Update database to point to dedup file
        $stmt = $db->prepare('UPDATE models SET dedup_path = :dedup_path WHERE id = :id');
        $stmt->bindValue(':dedup_path', $dedupPath, PDO::PARAM_STR);
        $stmt->bindValue(':id', $part['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Delete original file if it exists and is not the dedup file
        if (file_exists($originalPath) && realpath($originalPath) !== realpath($dedupFullPath)) {
            $spaceSaved += filesize($originalPath);
            unlink($originalPath);
            $deletedCount++;

            // Try to remove empty parent folder
            $parentFolder = dirname($originalPath);
            if (is_dir($parentFolder) && count(glob($parentFolder . '/*')) === 0) {
                @rmdir($parentFolder);
            }
        }
    }

    logInfo('Deduplicated files by hash', [
        'hash' => $hash,
        'parts_count' => count($parts),
        'files_deleted' => $deletedCount,
        'space_saved' => $spaceSaved
    ]);

    return [
        'success' => true,
        'parts_count' => count($parts),
        'files_deleted' => $deletedCount,
        'space_saved' => $spaceSaved,
        'dedup_path' => $dedupPath
    ];
}

/**
 * Run full deduplication scan
 * @return array Summary of deduplication results
 */
function runDeduplicationScan() {
    $duplicates = findDuplicateHashes();

    $totalSpaceSaved = 0;
    $totalFilesDeleted = 0;
    $hashesProcessed = 0;

    foreach ($duplicates as $dup) {
        $result = deduplicateByHash($dup['file_hash']);
        if ($result['success']) {
            $totalSpaceSaved += $result['space_saved'];
            $totalFilesDeleted += $result['files_deleted'];
            $hashesProcessed++;
        }
    }

    logInfo('Deduplication scan complete', [
        'hashes_processed' => $hashesProcessed,
        'files_deleted' => $totalFilesDeleted,
        'space_saved' => $totalSpaceSaved
    ]);

    return [
        'success' => true,
        'hashes_processed' => $hashesProcessed,
        'files_deleted' => $totalFilesDeleted,
        'space_saved' => $totalSpaceSaved
    ];
}

/**
 * Check if a deduplicated file can be safely deleted
 * (only if no other parts reference it)
 */
function canDeleteDedupFile($dedupPath) {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE dedup_path = :path');
    $stmt->bindValue(':path', $dedupPath, PDO::PARAM_STR);
    $result = $stmt->execute();
    $row = $result->fetchArray(PDO::FETCH_ASSOC);

    return $row['count'] <= 1;
}

/**
 * Get the count of references to a deduplicated file
 */
function getDedupReferenceCount($dedupPath) {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE dedup_path = :path');
    $stmt->bindValue(':path', $dedupPath, PDO::PARAM_STR);
    $result = $stmt->execute();
    $row = $result->fetchArray(PDO::FETCH_ASSOC);

    return $row['count'];
}

/**
 * Migrate a deduplicated file back to its original location
 * Used when a file only has one reference left
 */
function migrateDedupBack($modelId) {
    $db = getDB();

    // Get the model
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model || empty($model['dedup_path'])) {
        return ['success' => false, 'error' => 'Model not found or not deduplicated'];
    }

    $dedupPath = __DIR__ . '/../' . $model['dedup_path'];
    $originalPath = __DIR__ . '/../' . $model['file_path'];

    // Check if this is the only reference
    if (getDedupReferenceCount($model['dedup_path']) > 1) {
        return ['success' => false, 'error' => 'File still has multiple references'];
    }

    // Ensure original folder exists
    $originalFolder = dirname($originalPath);
    if (!file_exists($originalFolder)) {
        mkdir($originalFolder, 0755, true);
    }

    // Move file back
    if (file_exists($dedupPath)) {
        if (rename($dedupPath, $originalPath)) {
            // Clear dedup_path in database
            $stmt = $db->prepare('UPDATE models SET dedup_path = NULL WHERE id = :id');
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            logInfo('Migrated dedup file back', ['model_id' => $modelId]);
            return ['success' => true];
        }
    }

    return ['success' => false, 'error' => 'Failed to migrate file'];
}

/**
 * Run cleanup scan - migrate files back that only have one reference
 */
function runDedupCleanupScan() {
    $db = getDB();

    // Find dedup paths with only one reference
    $result = $db->query('
        SELECT dedup_path, COUNT(*) as count, MIN(id) as model_id
        FROM models
        WHERE dedup_path IS NOT NULL
        GROUP BY dedup_path
        HAVING COUNT(*) = 1
    ');

    $migratedCount = 0;
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $result2 = migrateDedupBack($row['model_id']);
        if ($result2['success']) {
            $migratedCount++;
        }
    }

    logInfo('Dedup cleanup scan complete', ['migrated' => $migratedCount]);

    return [
        'success' => true,
        'migrated' => $migratedCount
    ];
}

/**
 * Get deduplication statistics
 */
function getDeduplicationStats() {
    $db = getDB();

    // Count deduplicated files
    $result = $db->query('SELECT COUNT(DISTINCT dedup_path) as count FROM models WHERE dedup_path IS NOT NULL');
    $dedupFileCount = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

    // Count parts using deduplicated files
    $result = $db->query('SELECT COUNT(*) as count FROM models WHERE dedup_path IS NOT NULL');
    $dedupPartCount = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

    // Calculate space saved (parts using dedup - actual dedup files)
    $result = $db->query('
        SELECT SUM(file_size) as total
        FROM models
        WHERE dedup_path IS NOT NULL
    ');
    $virtualSize = $result->fetchArray(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get actual size of dedup folder
    $dedupFolder = __DIR__ . '/../' . DEDUP_FOLDER;
    $actualSize = 0;
    if (is_dir($dedupFolder)) {
        $iterator = new DirectoryIterator($dedupFolder);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $actualSize += $file->getSize();
            }
        }
    }

    $spaceSaved = $virtualSize - $actualSize;

    // Find potential duplicates (not yet deduplicated)
    $duplicates = findDuplicateHashes();
    $potentialSavings = 0;
    foreach ($duplicates as $dup) {
        // Space saved would be (count - 1) * average file size
        $avgSize = $dup['total_size'] / $dup['count'];
        $potentialSavings += ($dup['count'] - 1) * $avgSize;
    }

    return [
        'dedup_file_count' => $dedupFileCount,
        'dedup_part_count' => $dedupPartCount,
        'space_saved' => max(0, $spaceSaved),
        'potential_duplicates' => count($duplicates),
        'potential_savings' => $potentialSavings
    ];
}

/**
 * Calculate content-based hash for a file
 * For 3MF files, extracts and hashes the actual model content (ignoring ZIP metadata)
 * For other files, uses standard file hash
 */
function calculateContentHash($filePath) {
    if (!file_exists($filePath) || !is_file($filePath)) {
        return null;
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // For 3MF files, hash the content inside the ZIP archive
    if ($extension === '3mf') {
        return calculate3mfContentHash($filePath);
    }

    // For other files, use standard file hash
    return hash_file('sha256', $filePath);
}

/**
 * Calculate content-based hash for 3MF files
 * 3MF files are ZIP archives - we hash the actual model content, not the ZIP metadata
 */
function calculate3mfContentHash($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        // If can't open as ZIP, fall back to file hash
        return hash_file('sha256', $filePath);
    }

    $contentToHash = '';

    // Get list of all files in the archive, sorted for consistency
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[] = $zip->getNameIndex($i);
    }
    sort($files);

    // Hash the content of each file (excluding metadata-only files)
    foreach ($files as $fileName) {
        // Skip files that only contain metadata
        $lowerName = strtolower($fileName);
        if (strpos($lowerName, '_rels/') === 0) continue;
        if ($lowerName === '[content_types].xml') continue;

        $content = $zip->getFromName($fileName);
        if ($content !== false) {
            // Add filename and content to hash input for consistency
            $contentToHash .= $fileName . ':' . strlen($content) . ':' . $content;
        }
    }

    $zip->close();

    if (empty($contentToHash)) {
        // Fallback if no content found
        return hash_file('sha256', $filePath);
    }

    return hash('sha256', $contentToHash);
}

/**
 * Calculate and store hashes for all files that don't have one
 */
function calculateMissingHashes() {
    $db = getDB();

    $result = $db->query('
        SELECT id, file_path, dedup_path
        FROM models
        WHERE (file_hash IS NULL OR file_hash = "")
          AND file_path IS NOT NULL
    ');

    $updated = 0;
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $filePath = __DIR__ . '/../' . getRealFilePath($row);

        if (file_exists($filePath) && is_file($filePath)) {
            $hash = calculateContentHash($filePath);
            if ($hash) {
                $stmt = $db->prepare('UPDATE models SET file_hash = :hash WHERE id = :id');
                $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
                $stmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
                $stmt->execute();
                $updated++;
            }
        }
    }

    logInfo('Calculated missing hashes', ['count' => $updated]);
    return $updated;
}

/**
 * Recalculate hashes for all 3MF files using content-based hashing
 * Use this to update existing hashes after implementing content-based hashing
 */
function recalculate3mfHashes() {
    $db = getDB();

    $result = $db->query('
        SELECT id, file_path, dedup_path
        FROM models
        WHERE file_path IS NOT NULL
          AND (file_path LIKE "%.3mf" OR dedup_path LIKE "%.3mf")
    ');

    $updated = 0;
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $filePath = __DIR__ . '/../' . getRealFilePath($row);

        if (file_exists($filePath) && is_file($filePath)) {
            $hash = calculateContentHash($filePath);
            if ($hash) {
                $stmt = $db->prepare('UPDATE models SET file_hash = :hash WHERE id = :id');
                $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
                $stmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
                $stmt->execute();
                $updated++;
            }
        }
    }

    logInfo('Recalculated 3MF hashes', ['count' => $updated]);
    return $updated;
}

/**
 * Check for duplicate files before upload
 * Returns array of existing models that match the given file hash
 */
function findExistingByHash($hash) {
    if (empty($hash)) {
        return [];
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT m.*, p.name as parent_name, p.id as parent_model_id
        FROM models m
        LEFT JOIN models p ON m.parent_id = p.id
        WHERE m.file_hash = :hash
        ORDER BY m.created_at DESC
        LIMIT 10
    ');
    $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
    $result = $stmt->execute();

    $models = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $models[] = $row;
    }
    return $models;
}

/**
 * Check a temp file for duplicates before processing upload
 * @param string $tempPath Path to the temporary uploaded file
 * @return array ['is_duplicate' => bool, 'hash' => string, 'existing' => array]
 */
function checkUploadForDuplicates($tempPath) {
    if (!file_exists($tempPath)) {
        return ['is_duplicate' => false, 'hash' => null, 'existing' => []];
    }

    $hash = calculateContentHash($tempPath);
    if (!$hash) {
        return ['is_duplicate' => false, 'hash' => null, 'existing' => []];
    }

    $existing = findExistingByHash($hash);

    return [
        'is_duplicate' => !empty($existing),
        'hash' => $hash,
        'existing' => $existing
    ];
}

/**
 * Find duplicate models by name (loose matching)
 * @param string $name Model name to check
 * @return array Existing models with similar names
 */
function findSimilarByName($name) {
    if (empty($name)) {
        return [];
    }

    $db = getDB();
    // Normalize name for comparison
    $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', ' ', $name)));
    $words = array_filter(explode(' ', $normalized));

    if (empty($words)) {
        return [];
    }

    // Search for models containing the main words
    $where = [];
    $params = [];
    $i = 0;
    foreach (array_slice($words, 0, 3) as $word) { // Use first 3 significant words
        if (strlen($word) >= 3) {
            $where[] = "LOWER(name) LIKE :word$i";
            $params[":word$i"] = '%' . $word . '%';
            $i++;
        }
    }

    if (empty($where)) {
        return [];
    }

    $sql = '
        SELECT id, name, creator, created_at
        FROM models
        WHERE parent_id IS NULL AND (' . implode(' OR ', $where) . ')
        ORDER BY created_at DESC
        LIMIT 5
    ';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $result = $stmt->execute();

    $models = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $models[] = $row;
    }
    return $models;
}
