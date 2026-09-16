<?php

/**
 * Shared model/part file-cleanup helpers.
 *
 * Extracted from app/actions/delete.php so every delete path (the web delete
 * page, the REST API, and the mass/batch actions) removes the same set of
 * physical artifacts: the model file (regular or deduplicated), thumbnails,
 * version files, and empty directories. Pure function definitions with no
 * side effects on include; loaded via includes/db.php.
 *
 * Depends on getAbsoluteFilePath()/deleteIfOrphaned() from includes/dedup.php
 * and the UPLOAD_PATH constant from includes/config.php.
 */

/**
 * Delete a file from disk, handling both regular and deduplicated files.
 * Cleans up empty parent directories.
 *
 * @param string|null $filePath Regular file path (stored form, e.g. assets/...)
 * @param string|null $dedupPath Deduplicated file path (stored form)
 * @return void
 */
function deleteModelFile(?string $filePath, ?string $dedupPath): void {
    if (!empty($dedupPath)) {
        // File is deduplicated - atomically check reference count and delete
        deleteIfOrphaned($dedupPath);
    } elseif (!empty($filePath)) {
        // Non-deduplicated file
        $fullPath = getAbsoluteFilePath(['file_path' => $filePath, 'dedup_path' => null]);
        if (file_exists($fullPath)) {
            unlink($fullPath);

            // Check if folder is empty and clean up
            $folder = dirname($fullPath);
            if (is_dir($folder) && count(scandir($folder)) === 2) {
                rmdir($folder);
            }
        }
    }
}

/**
 * Collect files for deletion and clean up empty folders.
 *
 * @param array $filesToDelete List of absolute non-deduplicated file paths
 * @param array $dedupFilesToCheck Map of dedup paths (stored form) to check
 * @return void
 */
function cleanupModelFiles(array $filesToDelete, array $dedupFilesToCheck): void {
    $foldersToCheck = [];

    // Delete non-deduplicated files
    foreach ($filesToDelete as $filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
            $folder = dirname($filePath);
            if (!in_array($folder, $foldersToCheck)) {
                $foldersToCheck[] = $folder;
            }
        }
    }

    // Delete deduplicated files only if no other parts reference them (atomic check+delete)
    foreach (array_keys($dedupFilesToCheck) as $dedupPath) {
        deleteIfOrphaned($dedupPath);
    }

    // Clean up empty folders
    foreach ($foldersToCheck as $folder) {
        if (is_dir($folder) && count(scandir($folder)) === 2) {
            rmdir($folder);
        }
    }
}

/**
 * Safely delete a file identified by a path stored relative to the assets base
 * (UPLOAD_PATH), such as a model version file, a thumbnail, or an attachment.
 * Uses realpath containment so a corrupt or crafted path can never unlink
 * anything outside storage/assets.
 *
 * Canonical model/version file_paths carry an "assets/" prefix, but UPLOAD_PATH
 * already points at storage/assets, so a leading "assets/" is stripped to avoid
 * a doubled prefix. Thumbnail/attachment paths have no such prefix and are
 * unaffected.
 *
 * @param string|null $relativePath Path relative to UPLOAD_PATH (an optional
 *                                  leading 'assets/' is tolerated)
 * @return void
 */
function safeUnlinkAssetFile(?string $relativePath): void {
    if (empty($relativePath)) {
        return;
    }
    $base = realpath(UPLOAD_PATH);
    if ($base === false) {
        return;
    }
    // Strip a leading 'assets/' (UPLOAD_PATH already includes it) so canonical
    // version/model paths like 'assets/versions/5/vX.stl' resolve correctly.
    $relativePath = preg_replace('#^assets/#', '', $relativePath);
    $candidate = rtrim(UPLOAD_PATH, '/\\') . '/' . ltrim($relativePath, '/\\');
    $real = realpath($candidate);
    if ($real === false) {
        return; // File does not exist
    }
    // Containment: the resolved path must live under the assets base
    if ($real !== $base && strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    unlink($real);
}

/**
 * Delete all physical version files for a model and remove the now-empty
 * per-model versions directory. The model_versions rows themselves are removed
 * by the ON DELETE CASCADE when the model row is deleted; this only removes the
 * files left on disk. Must be called while the rows still exist.
 *
 * @param mixed $db      Database wrapper
 * @param int   $modelId
 * @return void
 */
function deleteModelVersionFiles($db, int $modelId): void {
    try {
        $stmt = $db->prepare('SELECT file_path FROM model_versions WHERE model_id = :model_id');
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            if (!empty($row['file_path'])) {
                safeUnlinkAssetFile($row['file_path']);
            }
        }
    } catch (Throwable $e) {
        // model_versions table may not exist yet - nothing to clean up
        return;
    }

    // Remove the per-model versions directory if it is now empty
    $versionDir = rtrim(UPLOAD_PATH, '/\\') . '/versions/' . $modelId;
    if (is_dir($versionDir) && count(scandir($versionDir)) === 2) {
        rmdir($versionDir);
    }
}
