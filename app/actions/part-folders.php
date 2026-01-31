<?php
/**
 * Part Folders AJAX endpoint
 *
 * Manages virtual subfolders for model parts by updating original_path
 */
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!canEdit()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action = $_POST['action'] ?? '';
$modelId = (int)($_POST['model_id'] ?? 0);

if (!$modelId && $action !== 'move') {
    echo json_encode(['success' => false, 'error' => 'Model ID required']);
    exit;
}

$db = getDB();

switch ($action) {
    case 'create':
        // Validate folder name
        $folderName = trim($_POST['folder_name'] ?? '');
        if ($folderName === '' || $folderName === 'Root' || strpos($folderName, '/') !== false || strpos($folderName, '\\') !== false) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder name']);
            exit;
        }

        // Check that the parent model exists
        $stmt = $db->prepare('SELECT id FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        if (!$result->fetchArray()) {
            echo json_encode(['success' => false, 'error' => 'Model not found']);
            exit;
        }

        // Folder is virtual — it exists once parts are moved into it
        // Return success so the UI can show the empty folder group
        echo json_encode(['success' => true, 'folder' => $folderName]);
        break;

    case 'rename':
        $oldFolder = trim($_POST['old_folder'] ?? '');
        $newFolder = trim($_POST['new_folder'] ?? '');

        if ($oldFolder === '' || $newFolder === '' || $newFolder === 'Root') {
            echo json_encode(['success' => false, 'error' => 'Invalid folder name']);
            exit;
        }
        if (strpos($newFolder, '/') !== false || strpos($newFolder, '\\') !== false) {
            echo json_encode(['success' => false, 'error' => 'Folder name cannot contain slashes']);
            exit;
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
            $updated++;
        }

        echo json_encode(['success' => true, 'updated' => $updated]);
        break;

    case 'delete':
        $folderName = trim($_POST['folder_name'] ?? '');
        if ($folderName === '' || $folderName === 'Root') {
            echo json_encode(['success' => false, 'error' => 'Invalid folder name']);
            exit;
        }

        // Move parts from this folder to root (strip folder prefix)
        $prefix = $folderName . '/';

        $stmt = $db->prepare('SELECT id, original_path FROM models WHERE parent_id = :model_id AND original_path LIKE :pattern');
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern', $prefix . '%', PDO::PARAM_STR);
        $result = $stmt->execute();

        $updated = 0;
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $basename = basename($row['original_path']);
            $updateStmt = $db->prepare('UPDATE models SET original_path = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $basename, PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
            $updateStmt->execute();
            $updated++;
        }

        echo json_encode(['success' => true, 'updated' => $updated]);
        break;

    case 'move':
        $partIds = $_POST['part_ids'] ?? [];
        $targetFolder = trim($_POST['target_folder'] ?? '');

        if (empty($partIds)) {
            echo json_encode(['success' => false, 'error' => 'No parts specified']);
            exit;
        }
        if (strpos($targetFolder, '/') !== false || strpos($targetFolder, '\\') !== false) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder name']);
            exit;
        }

        $isRoot = ($targetFolder === '' || $targetFolder === 'Root');
        $updated = 0;

        foreach ($partIds as $partId) {
            $partId = (int)$partId;
            if (!$partId) continue;

            // Get current original_path
            $stmt = $db->prepare('SELECT id, original_path FROM models WHERE id = :id AND parent_id IS NOT NULL');
            $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $result = $stmt->execute();
            $part = $result->fetchArray(PDO::FETCH_ASSOC);

            if (!$part) continue;

            $basename = basename($part['original_path'] ?: $part['id'] . '.stl');
            $newPath = $isRoot ? $basename : $targetFolder . '/' . $basename;

            $updateStmt = $db->prepare('UPDATE models SET original_path = :new_path WHERE id = :id');
            $updateStmt->bindValue(':new_path', $newPath, PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $updateStmt->execute();
            $updated++;
        }

        echo json_encode(['success' => true, 'updated' => $updated]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
