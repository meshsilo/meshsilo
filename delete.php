<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';

// Require delete permission
requirePermission(PERM_DELETE);

$db = getDB();

// Get model ID and optional part ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$partId = isset($_GET['part_id']) ? (int)$_GET['part_id'] : 0;

if (!$modelId) {
    header('Location: index.php');
    exit;
}

// Get model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
$result = $stmt->execute();
$model = $result->fetchArray(SQLITE3_ASSOC);

if (!$model) {
    $_SESSION['error'] = 'Model not found.';
    header('Location: index.php');
    exit;
}

// If this is a child model accessed directly, redirect to parent
if ($model['parent_id']) {
    header('Location: model.php?id=' . $model['parent_id']);
    exit;
}

// If deleting a specific part, get part details
$part = null;
if ($partId) {
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id = :parent_id');
    $stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
    $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $part = $result->fetchArray(SQLITE3_ASSOC);

    if (!$part) {
        $_SESSION['error'] = 'Part not found.';
        header('Location: model.php?id=' . $modelId);
        exit;
    }
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: model.php?id=' . $modelId);
        exit;
    }

    try {
        if ($part) {
            // Delete individual part
            $filePath = __DIR__ . '/' . $part['file_path'];
            $dedupPath = !empty($part['dedup_path']) ? __DIR__ . '/' . $part['dedup_path'] : null;

            // Check if this part uses a deduplicated file
            $canDeleteDedup = $dedupPath && canDeleteDedupFile($part['dedup_path']);

            // Delete from database
            $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
            $stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
            $stmt->execute();

            // Update parent's part count
            $stmt = $db->prepare('UPDATE models SET part_count = part_count - 1 WHERE id = :id');
            $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();

            // Delete file from disk
            if ($dedupPath) {
                // File is deduplicated - only delete if no other parts reference it
                if ($canDeleteDedup && file_exists($dedupPath)) {
                    unlink($dedupPath);
                }
            } elseif (file_exists($filePath)) {
                // Non-deduplicated file
                unlink($filePath);

                // Check if folder is empty and clean up
                $folder = dirname($filePath);
                if (is_dir($folder) && count(scandir($folder)) === 2) {
                    rmdir($folder);
                }
            }

            logInfo('Part deleted', [
                'part_id' => $partId,
                'part_name' => $part['name'],
                'model_id' => $modelId,
                'user_id' => $_SESSION['user_id'] ?? null
            ]);

            $_SESSION['success'] = 'Part "' . $part['name'] . '" has been deleted.';
            header('Location: model.php?id=' . $modelId);
            exit;

        } else {
            // Delete entire model
            $filesToDelete = [];
            $dedupFilesToCheck = []; // Track dedup files that may need deletion

            // If multi-part model, get all parts first
            if ($model['part_count'] > 0) {
                $stmt = $db->prepare('SELECT file_path, dedup_path FROM models WHERE parent_id = :parent_id');
                $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    if (!empty($row['dedup_path'])) {
                        // Track dedup path for later check
                        $dedupFilesToCheck[$row['dedup_path']] = true;
                    } elseif ($row['file_path']) {
                        $filesToDelete[] = __DIR__ . '/' . $row['file_path'];
                    }
                }

                // Delete child models from database
                $stmt = $db->prepare('DELETE FROM models WHERE parent_id = :parent_id');
                $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
                $stmt->execute();
            } else {
                // Single model - add its file
                if (!empty($model['dedup_path'])) {
                    $dedupFilesToCheck[$model['dedup_path']] = true;
                } elseif ($model['file_path']) {
                    $filesToDelete[] = __DIR__ . '/' . $model['file_path'];
                }
            }

            // Delete category associations
            $stmt = $db->prepare('DELETE FROM model_categories WHERE model_id = :model_id');
            $stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();

            // Delete parent/main model from database
            $stmt = $db->prepare('DELETE FROM models WHERE id = :id');
            $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();

            // Delete non-deduplicated files from disk
            $foldersToCheck = [];
            foreach ($filesToDelete as $filePath) {
                if (file_exists($filePath)) {
                    unlink($filePath);
                    // Track parent folder for cleanup
                    $folder = dirname($filePath);
                    if (!in_array($folder, $foldersToCheck)) {
                        $foldersToCheck[] = $folder;
                    }
                }
            }

            // Delete deduplicated files only if no other parts reference them
            foreach (array_keys($dedupFilesToCheck) as $dedupPath) {
                if (canDeleteDedupFile($dedupPath)) {
                    $fullPath = __DIR__ . '/' . $dedupPath;
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
            }

            // Clean up empty folders
            foreach ($foldersToCheck as $folder) {
                if (is_dir($folder) && count(scandir($folder)) === 2) { // Only . and ..
                    rmdir($folder);
                }
            }

            logInfo('Model deleted', [
                'model_id' => $modelId,
                'name' => $model['name'],
                'user_id' => $_SESSION['user_id'] ?? null
            ]);

            $_SESSION['success'] = 'Model "' . $model['name'] . '" has been deleted.';
            header('Location: index.php');
            exit;
        }

    } catch (Exception $e) {
        logException($e, ['action' => $part ? 'delete_part' : 'delete_model', 'model_id' => $modelId, 'part_id' => $partId]);
        $_SESSION['error'] = 'Failed to delete ' . ($part ? 'part' : 'model') . '.';
        header('Location: model.php?id=' . $modelId);
        exit;
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = $part ? 'Delete Part' : 'Delete ' . $model['name'];
$activePage = 'browse';

require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="delete-confirm">
                <?php if ($part): ?>
                <h1>Delete Part</h1>

                <div class="alert alert-error">
                    <strong>Warning:</strong> This action cannot be undone.
                </div>

                <div class="delete-model-info">
                    <h2><?= htmlspecialchars($part['name']) ?></h2>
                    <p class="text-muted">from <?= htmlspecialchars($model['name']) ?></p>
                </div>

                <form method="POST" class="delete-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="confirm_delete" value="1">

                    <div class="form-actions">
                        <a href="model.php?id=<?= $modelId ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-danger">Delete Part</button>
                    </div>
                </form>

                <?php else: ?>
                <h1>Delete Model</h1>

                <div class="alert alert-error">
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
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="confirm_delete" value="1">

                    <div class="form-actions">
                        <a href="model.php?id=<?= $modelId ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-danger">Delete Model</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
