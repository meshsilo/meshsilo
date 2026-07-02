<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

/**
 * Delete a file from disk, handling both regular and deduplicated files.
 * Cleans up empty parent directories.
 *
 * @param string|null $filePath Regular file path (relative to project root)
 * @param string|null $dedupPath Deduplicated file path (relative to project root)
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
 * @param array $filesToDelete List of non-deduplicated file paths
 * @param array $dedupFilesToCheck Map of dedup paths to check
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
 * (UPLOAD_PATH), such as a model version file or a thumbnail. Uses realpath
 * containment so a corrupt or crafted path can never unlink anything outside
 * storage/assets.
 *
 * @param string|null $relativePath Path relative to UPLOAD_PATH (no 'assets/' prefix)
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

// Require delete permission
requirePermission(PERM_DELETE);

$db = getDB();

// Get model ID and optional part ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$partId = isset($_GET['part_id']) ? (int)$_GET['part_id'] : 0;

if (!$modelId) {
    header('Location: ../index.php');
    exit;
}

// Get model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    $_SESSION['error'] = 'Model not found.';
    header('Location: ../index.php');
    exit;
}

// Ownership check: only the owner or an admin may delete
$user = getCurrentUser();
if ($model['user_id'] !== null && (int)$model['user_id'] !== (int)$user['id'] && !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not own this model']);
    exit;
}

// If this is a child model accessed directly, redirect to parent
if ($model['parent_id']) {
    header('Location: ../model.php?id=' . $model['parent_id']);
    exit;
}

// If deleting a specific part, get part details
$part = null;
if ($partId) {
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id = :parent_id');
    $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
    $stmt->bindValue(':parent_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $part = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$part) {
        $_SESSION['error'] = 'Part not found.';
        header('Location: ../model.php?id=' . $modelId);
        exit;
    }
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    // Verify CSRF token using timing-safe comparison
    if (!Csrf::check()) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ../model.php?id=' . $modelId);
        exit;
    }

    // Plugin hook: allow plugins to prevent deletion or perform pre-deletion cleanup
    if (class_exists('PluginManager')) {
        $deleteTarget = $part ?? $model;
        $allowed = PluginManager::applyFilter('before_delete', true, $deleteTarget, getCurrentUser());
        if ($allowed !== true) {
            $_SESSION['error'] = is_string($allowed) ? $allowed : 'Deletion blocked';
            header('Location: ../model.php?id=' . $modelId);
            exit;
        }
    }

    try {
        if ($part) {
            // Delete individual part

            // Delete physical version files while the rows still exist (cascade
            // removes the model_versions rows when the part row is deleted)
            deleteModelVersionFiles($db, $partId);

            // Delete from database first
            $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
            $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $stmt->execute();

            // Update parent's part count (recount for accuracy)
            $countStmt = $db->prepare('SELECT COUNT(*) FROM models WHERE parent_id = :pid');
            $countStmt->bindValue(':pid', $modelId, PDO::PARAM_INT);
            $countStmt->execute();
            $partCount = (int)$countStmt->fetchColumn();
            $stmt = $db->prepare('UPDATE models SET part_count = :count WHERE id = :id');
            $stmt->bindValue(':count', $partCount, PDO::PARAM_INT);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            // Delete file from disk using helper function
            deleteModelFile($part['file_path'], $part['dedup_path']);

            // Delete the part's thumbnail file (thumbnails are never deduplicated)
            safeUnlinkAssetFile($part['thumbnail_path'] ?? null);

            logInfo('Part deleted', [
                'part_id' => $partId,
                'part_name' => $part['name'],
                'model_id' => $modelId,
                'user_id' => $_SESSION['user_id'] ?? null
            ]);

            // Plugin hook: notify plugins after successful part deletion
            if (class_exists('PluginManager')) {
                PluginManager::applyFilter('after_delete', null, $part, getCurrentUser());
            }

            $_SESSION['success'] = 'Part "' . $part['name'] . '" has been deleted.';
            header('Location: ../model.php?id=' . $modelId);
            exit;

        } else {
            // Delete entire model
            $filesToDelete = [];
            $dedupFilesToCheck = [];
            $thumbnailsToDelete = [];

            // The model's own thumbnail (parts add theirs below)
            if (!empty($model['thumbnail_path'])) {
                $thumbnailsToDelete[] = $model['thumbnail_path'];
            }

            // Collect files from parts (if multi-part model)
            if ($model['part_count'] > 0) {
                $stmt = $db->prepare('SELECT id, file_path, dedup_path, thumbnail_path FROM models WHERE parent_id = :parent_id');
                $stmt->bindValue(':parent_id', $modelId, PDO::PARAM_INT);
                $result = $stmt->execute();
                $partIds = [];
                while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                    $partIds[] = (int)$row['id'];
                    if (!empty($row['thumbnail_path'])) {
                        $thumbnailsToDelete[] = $row['thumbnail_path'];
                    }
                    if (!empty($row['dedup_path'])) {
                        $dedupFilesToCheck[$row['dedup_path']] = true;
                    } elseif ($row['file_path']) {
                        $filesToDelete[] = getAbsoluteFilePath($row);
                    }
                }

                // Delete each part's physical version files before the cascade removes the rows
                foreach ($partIds as $pid) {
                    deleteModelVersionFiles($db, $pid);
                }

                // Delete child models from database
                $stmt = $db->prepare('DELETE FROM models WHERE parent_id = :parent_id');
                $stmt->bindValue(':parent_id', $modelId, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                // Single model - collect its file
                if (!empty($model['dedup_path'])) {
                    $dedupFilesToCheck[$model['dedup_path']] = true;
                } elseif ($model['file_path']) {
                    $filesToDelete[] = getAbsoluteFilePath($model);
                }
            }

            // Delete model attachments (images and PDFs)
            try {
                $stmt = $db->prepare('SELECT file_path FROM model_attachments WHERE model_id = :model_id');
                $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
                $result = $stmt->execute();
                while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                    if ($row['file_path']) {
                        $attachPath = __DIR__ . '/../../storage/assets/' . $row['file_path'];
                        if (file_exists($attachPath)) {
                            unlink($attachPath);
                        }
                    }
                }

                $stmt = $db->prepare('DELETE FROM model_attachments WHERE model_id = :model_id');
                $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
                $stmt->execute();
            } catch (Throwable $e) {
                // model_attachments table may not exist yet - continue with deletion
            }

            // Delete category associations
            $stmt = $db->prepare('DELETE FROM model_categories WHERE model_id = :model_id');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            // Delete the parent model's physical version files while its rows
            // still exist (cascade removes model_versions when the row is deleted)
            deleteModelVersionFiles($db, $modelId);

            // Delete parent/main model from database
            $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            // Clean up files using helper function
            cleanupModelFiles($filesToDelete, $dedupFilesToCheck);

            // Delete thumbnail files (model + parts); these are never deduplicated
            foreach ($thumbnailsToDelete as $thumbPath) {
                safeUnlinkAssetFile($thumbPath);
            }

            logInfo('Model deleted', [
                'model_id' => $modelId,
                'name' => $model['name'],
                'user_id' => $_SESSION['user_id'] ?? null
            ]);

            // Plugin hook: notify plugins after successful model deletion
            if (class_exists('PluginManager')) {
                PluginManager::applyFilter('after_delete', null, $model, getCurrentUser());
            }

            $_SESSION['success'] = 'Model "' . $model['name'] . '" has been deleted.';
            header('Location: ../index.php');
            exit;
        }

    } catch (Exception $e) {
        logException($e, ['action' => $part ? 'delete_part' : 'delete_model', 'model_id' => $modelId, 'part_id' => $partId]);
        $_SESSION['error'] = 'Failed to delete ' . ($part ? 'part' : 'model') . '.';
        header('Location: ../model.php?id=' . $modelId);
        exit;
    }
}

// CSRF token is now managed by the Csrf class

$pageTitle = $part ? 'Delete Part' : 'Delete ' . $model['name'];
$activePage = 'browse';

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="page-container">
            <div class="delete-confirm">
                <?php if ($part): ?>
                <h1>Delete Part</h1>

                <div role="alert" class="alert alert-error">
                    <strong>Warning:</strong> This action cannot be undone.
                </div>

                <div class="delete-model-info">
                    <h2><?= htmlspecialchars($part['name']) ?></h2>
                    <p class="text-muted">from <?= htmlspecialchars($model['name']) ?></p>
                </div>

                <form method="POST" class="delete-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm_delete" value="1">

                    <div class="form-actions">
                        <a href="../model.php?id=<?= $modelId ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-danger">Delete Part</button>
                    </div>
                </form>

                <?php else: ?>
                <h1>Delete Model</h1>

                <div role="alert" class="alert alert-error">
                    <strong>Warning:</strong> This action cannot be undone.
                </div>

                <div class="delete-model-info">
                    <h2><?= htmlspecialchars($model['name']) ?></h2>
                    <?php if ($model['part_count'] > 0): ?>
                    <p>This model contains <strong><?= $model['part_count'] ?> parts</strong> that will also be deleted.</p>
                    <?php endif; ?>
                    <?php if ($model['creator']): ?>
                    <p class="text-muted">by <?= htmlspecialchars($model['creator']) ?></p>
                    <?php endif; ?>
                </div>

                <form method="POST" class="delete-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm_delete" value="1">

                    <div class="form-actions">
                        <a href="../model.php?id=<?= $modelId ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-danger">Delete Model</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
