<?php
require_once 'includes/config.php';

// Require delete permission
requirePermission(PERM_DELETE);

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

// If this is a child model, redirect to parent
if ($model['parent_id']) {
    header('Location: model.php?id=' . $model['parent_id']);
    exit;
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
        // Start collecting files to delete
        $filesToDelete = [];

        // If multi-part model, get all parts first
        if ($model['part_count'] > 0) {
            $stmt = $db->prepare('SELECT file_path FROM models WHERE parent_id = :parent_id');
            $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['file_path']) {
                    $filesToDelete[] = __DIR__ . '/' . $row['file_path'];
                }
            }

            // Delete child models from database
            $stmt = $db->prepare('DELETE FROM models WHERE parent_id = :parent_id');
            $stmt->bindValue(':parent_id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();
        } else {
            // Single model - add its file
            if ($model['file_path']) {
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

        // Delete files from disk
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

    } catch (Exception $e) {
        logException($e, ['action' => 'delete_model', 'model_id' => $modelId]);
        $_SESSION['error'] = 'Failed to delete model.';
        header('Location: model.php?id=' . $modelId);
        exit;
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Delete ' . $model['name'];
$activePage = 'browse';

require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="delete-confirm">
                <h1>Delete Model</h1>

                <div class="alert alert-error">
                    <strong>Warning:</strong> This action cannot be undone.
                </div>

                <div class="delete-model-info">
                    <h2><?= htmlspecialchars($model['name']) ?></h2>
                    <?php if ($model['part_count'] > 0): ?>
                    <p>This model contains <strong><?= $model['part_count'] ?> parts</strong> that will also be deleted.</p>
                    <?php endif; ?>
                    <?php if ($model['author']): ?>
                    <p class="text-muted">by <?= htmlspecialchars($model['author']) ?></p>
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
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
