<?php
/**
 * Part Folders AJAX endpoint
 *
 * Manages virtual subfolders for model parts by updating original_path
 * and moving physical files to match the folder structure.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

header('Content-Type: application/json');

/**
 * Move a part's physical file to match its new original_path folder.
 * Updates file_path in the database.
 *
 * @param Database $db
 * @param int $partId
 * @param string $newOriginalPath New original_path value (e.g. "SubFolder/file.stl" or just "file.stl")
 */
function movePartFile($db, int $partId, string $newOriginalPath): void
{
    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(dirname(__DIR__));

    // Get current file_path
    $stmt = $db->prepare('SELECT file_path, dedup_path FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $part = $result->fetchArray(PDO::FETCH_ASSOC);
    if (!$part || !$part['file_path']) return;

    // Don't move deduplicated files — they live in _dedup/
    if (!empty($part['dedup_path'])) return;

    $currentFilePath = $part['file_path'];
    $currentFilename = basename($currentFilePath);

    // Find the model's base directory (assets/{hash})
    // file_path is like "assets/{hash}/file.stl" or "assets/{hash}/sub/file.stl"
    $parts = explode('/', $currentFilePath);
    // Base dir is always the first two segments: "assets/{hash}"
    if (count($parts) < 2) return;
    $baseDir = $parts[0] . '/' . $parts[1];

    // Build the new file_path based on the new original_path's folder
    $newDir = dirname($newOriginalPath);
    if ($newDir === '.') {
        // Moving to root
        $newFilePath = $baseDir . '/' . $currentFilename;
    } else {
        $newFilePath = $baseDir . '/' . $newDir . '/' . $currentFilename;
    }

    // Skip if already in the right place
    if ($newFilePath === $currentFilePath) return;

    // Resolve physical paths
    $oldDiskPath = $basePath . '/storage/' . $currentFilePath;
    $newDiskDir = $basePath . '/storage/' . dirname($newFilePath);
    $newDiskPath = $basePath . '/storage/' . $newFilePath;

    // Only move if source exists
    if (!file_exists($oldDiskPath)) return;

    // Create target directory
    if (!is_dir($newDiskDir)) {
        mkdir($newDiskDir, 0755, true);
    }

    // Move the file
    if (rename($oldDiskPath, $newDiskPath)) {
        $updateStmt = $db->prepare('UPDATE models SET file_path = :new_path WHERE id = :id');
        $updateStmt->bindValue(':new_path', $newFilePath, PDO::PARAM_STR);
        $updateStmt->bindValue(':id', $partId, PDO::PARAM_INT);
        $updateStmt->execute();

        // Clean up empty source directory
        $oldDir = dirname($oldDiskPath);
        $baseAbsDir = $basePath . '/storage/' . $baseDir;
        if ($oldDir !== $baseAbsDir && is_dir($oldDir) && count(glob($oldDir . '/*')) === 0) {
            @rmdir($oldDir);
        }
    }
}

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

if (!canEdit()) {
    jsonError('Permission denied', 403);
}

$action = $_POST['action'] ?? '';
$modelId = (int)($_POST['model_id'] ?? 0);

if (!$modelId && $action !== 'move') {
    jsonError('Model ID required');
}

$db = getDB();
$user = getCurrentUser();

// CSRF protection
if (!Csrf::check()) {
    jsonError('Invalid CSRF token', 403);
}

// Verify model ownership (user must own the model or be admin)
if ($modelId) {
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id AND parent_id IS NULL');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) {
        jsonError('Model not found');
    }

    if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
        jsonError('You do not own this model', 403);
    }
}

switch ($action) {
    case 'create':
        // Validate folder name (allow / for nested folders, block \)
        $folderName = trim($_POST['folder_name'] ?? '');
        $folderName = trim($folderName, '/');
        if ($folderName === '' || $folderName === 'Root' || strpos($folderName, '\\') !== false || strpos($folderName, '..') !== false) {
            jsonError('Invalid folder name');
        }

        // Folder is virtual — it exists once parts are moved into it
        // Return success so the UI can show the empty folder group
        jsonSuccess(['folder' => $folderName]);
        break;

    case 'rename':
        $oldFolder = trim($_POST['old_folder'] ?? '');
        $newFolder = trim($_POST['new_folder'] ?? '');

        $newFolder = trim($newFolder, '/');
        if ($oldFolder === '' || $newFolder === '' || $newFolder === 'Root') {
            jsonError('Invalid folder name');
        }
        if (strpos($newFolder, '\\') !== false || strpos($newFolder, '..') !== false) {
            jsonError('Invalid folder name');
        }

        // Update original_path for all parts in this folder
        $oldPrefix = $oldFolder . '/';
        $newPrefix = $newFolder . '/';

        $stmt = $db->prepare('SELECT id, original_path FROM models WHERE parent_id = :model_id AND original_path LIKE :pattern');
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern', $oldPrefix . '%', PDO::PARAM_STR);
        $result = $stmt->execute();

        $updated = 0;
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $newPath = $newPrefix . substr($row['original_path'], strlen($oldPrefix));
            $updateStmt = $db->prepare('UPDATE models SET original_path = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $newPath, PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
            $updateStmt->execute();

            // Move the physical file to match
            movePartFile($db, $row['id'], $newPath);
            $updated++;
        }

        jsonSuccess(['updated' => $updated]);
        break;

    case 'delete':
        $folderName = trim($_POST['folder_name'] ?? '');
        $folderName = trim($folderName, '/');
        if ($folderName === '' || $folderName === 'Root') {
            jsonError('Invalid folder name');
        }

        // Move parts up one level — strip this folder's segment from the path
        // e.g. "A/B/file.stl" deleting folder "A/B" → "file.stl" (to parent A or root)
        $prefix = $folderName . '/';
        $parentFolder = dirname($folderName);
        $parentPrefix = ($parentFolder !== '.') ? $parentFolder . '/' : '';

        $stmt = $db->prepare('SELECT id, original_path FROM models WHERE parent_id = :model_id AND original_path LIKE :pattern');
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern', $prefix . '%', PDO::PARAM_STR);
        $result = $stmt->execute();

        $updated = 0;
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            // Strip only this folder level, preserving any nested subfolders below it
            $remainder = substr($row['original_path'], strlen($prefix));
            $newPath = $parentPrefix . $remainder;
            $updateStmt = $db->prepare('UPDATE models SET original_path = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $newPath, PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
            $updateStmt->execute();

            // Move the physical file to match
            movePartFile($db, $row['id'], $newPath);
            $updated++;
        }

        jsonSuccess(['updated' => $updated]);
        break;

    case 'move':
        $partIds = $_POST['part_ids'] ?? [];
        $targetFolder = trim($_POST['target_folder'] ?? '');

        if (empty($partIds)) {
            jsonError('No parts specified');
        }
        $targetFolder = trim($targetFolder, '/');
        if (strpos($targetFolder, '\\') !== false || strpos($targetFolder, '..') !== false) {
            jsonError('Invalid folder name');
        }

        $isRoot = ($targetFolder === '' || $targetFolder === 'Root');
        $updated = 0;

        foreach ($partIds as $partId) {
            $partId = (int)$partId;
            if (!$partId) continue;

            // Get current original_path and verify ownership via parent model
            $stmt = $db->prepare('
                SELECT p.id, p.original_path, p.parent_id, m.user_id
                FROM models p
                JOIN models m ON p.parent_id = m.id
                WHERE p.id = :id AND p.parent_id IS NOT NULL
            ');
            $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $result = $stmt->execute();
            $part = $result->fetchArray(PDO::FETCH_ASSOC);

            if (!$part) continue;

            // Verify ownership of the parent model
            if ($part['user_id'] != $user['id'] && !$user['is_admin']) {
                continue; // Skip parts the user doesn't own
            }

            $basename = basename($part['original_path'] ?: $part['id'] . '.stl');
            $newPath = $isRoot ? $basename : $targetFolder . '/' . $basename;

            $updateStmt = $db->prepare('UPDATE models SET original_path = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $newPath, PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $updateStmt->execute();

            // Move the physical file to match
            movePartFile($db, $partId, $newPath);
            $updated++;
        }

        jsonSuccess(['updated' => $updated]);
        break;

    case 'set_print_type':
        $folderName = trim($_POST['folder_name'] ?? '');
        $printType = trim($_POST['print_type'] ?? '');

        if ($printType !== '' && !in_array($printType, ['fdm', 'sla'])) {
            jsonError('Invalid print type');
        }

        $printValue = $printType === '' ? null : $printType;

        // Find all parts in this folder
        if ($folderName === '' || $folderName === 'Root') {
            // Root folder: parts with no folder prefix or just a filename in original_path
            $stmt = $db->prepare('
                SELECT id FROM models
                WHERE parent_id = :model_id
                AND (original_path IS NULL OR original_path NOT LIKE \'%/%\')
            ');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        } else {
            $prefix = $folderName . '/';
            $stmt = $db->prepare('
                SELECT id FROM models
                WHERE parent_id = :model_id
                AND original_path LIKE :pattern
            ');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $stmt->bindValue(':pattern', $prefix . '%', PDO::PARAM_STR);
        }

        $result = $stmt->execute();
        $updated = 0;

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $updateStmt = $db->prepare('UPDATE models SET print_type = :print_type WHERE id = :id');
            if ($printValue === null) {
                $updateStmt->bindValue(':print_type', null, PDO::PARAM_NULL);
            } else {
                $updateStmt->bindValue(':print_type', $printValue, PDO::PARAM_STR);
            }
            $updateStmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
            $updateStmt->execute();
            $updated++;
        }

        logInfo('Folder print type updated', [
            'model_id' => $modelId,
            'folder' => $folderName ?: 'Root',
            'print_type' => $printType ?: 'cleared',
            'parts_updated' => $updated
        ]);

        jsonSuccess(['updated' => $updated]);
        break;

    default:
        jsonError('Invalid action');
        break;
}
