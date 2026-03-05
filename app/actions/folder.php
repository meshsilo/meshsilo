<?php
/**
 * Folder Management Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['create', 'update', 'delete']) && !Csrf::check()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

switch ($action) {
    case 'create':
        createFolder();
        break;
    case 'update':
        updateFolder();
        break;
    case 'delete':
        deleteFolder();
        break;
    case 'list':
        listFolders();
        break;
    case 'get':
        getFolder();
        break;
    case 'move_model':
        moveModelToFolder();
        break;
    case 'tree':
        getFolderTree();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function createFolder() {
    global $user;

    $name = trim($_POST['name'] ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '');
    $color = $_POST['color'] ?? null;

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Folder name required']);
        return;
    }

    $db = getDB();

    // Verify parent exists if provided
    if ($parentId) {
        $stmt = $db->prepare('SELECT id FROM folders WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $parentId, ':user_id' => $user['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Parent folder not found']);
            return;
        }
    }

    // Get max sort order at this level
    $stmt = $db->prepare('SELECT MAX(sort_order) FROM folders WHERE user_id = :user_id AND parent_id IS :parent_id');
    $stmt->execute([':user_id' => $user['id'], ':parent_id' => $parentId]);
    $maxSort = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('
        INSERT INTO folders (user_id, parent_id, name, description, color, sort_order)
        VALUES (:user_id, :parent_id, :name, :description, :color, :sort_order)
    ');
    $stmt->execute([
        ':user_id' => $user['id'],
        ':parent_id' => $parentId,
        ':name' => $name,
        ':description' => $description,
        ':color' => $color,
        ':sort_order' => $maxSort + 1
    ]);

    $folderId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'folder_id' => $folderId
    ]);
}

function updateFolder() {
    global $user;

    $folderId = (int)($_POST['folder_id'] ?? 0);
    if (!$folderId) {
        echo json_encode(['success' => false, 'error' => 'Folder ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);
    $folder = $stmt->fetch();

    if (!$folder || ($folder['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $updates = [];
    $params = [':id' => $folderId];

    if (isset($_POST['name'])) {
        $updates[] = 'name = :name';
        $params[':name'] = trim($_POST['name']);
    }
    if (isset($_POST['description'])) {
        $updates[] = 'description = :description';
        $params[':description'] = trim($_POST['description']);
    }
    if (isset($_POST['color'])) {
        $updates[] = 'color = :color';
        $params[':color'] = $_POST['color'];
    }
    if (isset($_POST['parent_id'])) {
        $newParentId = (int)$_POST['parent_id'] ?: null;
        // Prevent circular reference
        if ($newParentId !== $folderId) {
            $updates[] = 'parent_id = :parent_id';
            $params[':parent_id'] = $newParentId;
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        return;
    }

    $sql = 'UPDATE folders SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
}

function deleteFolder() {
    global $user;

    $folderId = (int)($_POST['folder_id'] ?? 0);
    $moveModelsTo = (int)($_POST['move_models_to'] ?? 0) ?: null;

    if (!$folderId) {
        echo json_encode(['success' => false, 'error' => 'Folder ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);
    $folder = $stmt->fetch();

    if (!$folder || ($folder['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    // Move models out of folder if specified
    if ($moveModelsTo !== null) {
        $stmt = $db->prepare('UPDATE models SET folder_id = :new_folder WHERE folder_id = :old_folder');
        $stmt->execute([':new_folder' => $moveModelsTo, ':old_folder' => $folderId]);
    } else {
        // Move models to no folder
        $stmt = $db->prepare('UPDATE models SET folder_id = NULL WHERE folder_id = :folder_id');
        $stmt->execute([':folder_id' => $folderId]);
    }

    // Move subfolders to parent
    $stmt = $db->prepare('SELECT parent_id FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);
    $parent = $stmt->fetch();
    $parentId = $parent ? $parent['parent_id'] : null;

    $stmt = $db->prepare('UPDATE folders SET parent_id = :parent_id WHERE parent_id = :folder_id');
    $stmt->execute([':parent_id' => $parentId, ':folder_id' => $folderId]);

    // Delete folder
    $stmt = $db->prepare('DELETE FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);

    echo json_encode(['success' => true]);
}

function listFolders() {
    global $user;

    $parentId = isset($_GET['parent_id']) ? ((int)$_GET['parent_id'] ?: null) : null;

    $db = getDB();

    if ($parentId === null && !isset($_GET['parent_id'])) {
        // Get all folders
        $stmt = $db->prepare('SELECT * FROM folders WHERE user_id = :user_id ORDER BY sort_order, name');
        $stmt->execute([':user_id' => $user['id']]);
    } else {
        // Get folders at specific level
        if ($parentId) {
            $stmt = $db->prepare('SELECT * FROM folders WHERE user_id = :user_id AND parent_id = :parent_id ORDER BY sort_order, name');
            $stmt->execute([':user_id' => $user['id'], ':parent_id' => $parentId]);
        } else {
            $stmt = $db->prepare('SELECT * FROM folders WHERE user_id = :user_id AND parent_id IS NULL ORDER BY sort_order, name');
            $stmt->execute([':user_id' => $user['id']]);
        }
    }

    $folders = [];
    while ($row = $stmt->fetch()) {
        // Get model count
        $countStmt = $db->prepare('SELECT COUNT(*) FROM models WHERE folder_id = :folder_id AND parent_id IS NULL');
        $countStmt->execute([':folder_id' => $row['id']]);
        $row['model_count'] = (int)$countStmt->fetchColumn();

        // Get subfolder count
        $subStmt = $db->prepare('SELECT COUNT(*) FROM folders WHERE parent_id = :parent_id');
        $subStmt->execute([':parent_id' => $row['id']]);
        $row['subfolder_count'] = (int)$subStmt->fetchColumn();

        $folders[] = $row;
    }

    echo json_encode(['success' => true, 'folders' => $folders]);
}

function getFolder() {
    global $user;

    $folderId = (int)($_GET['folder_id'] ?? 0);
    if (!$folderId) {
        echo json_encode(['success' => false, 'error' => 'Folder ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM folders WHERE id = :id AND user_id = :user_id');
    $stmt->execute([':id' => $folderId, ':user_id' => $user['id']]);
    $folder = $stmt->fetch();

    if (!$folder) {
        echo json_encode(['success' => false, 'error' => 'Folder not found']);
        return;
    }

    // Get breadcrumb path
    $breadcrumb = [];
    $currentId = $folder['parent_id'];
    while ($currentId) {
        $stmt = $db->prepare('SELECT id, name, parent_id FROM folders WHERE id = :id');
        $stmt->execute([':id' => $currentId]);
        $parent = $stmt->fetch();
        if ($parent) {
            array_unshift($breadcrumb, ['id' => $parent['id'], 'name' => $parent['name']]);
            $currentId = $parent['parent_id'];
        } else {
            break;
        }
    }

    $folder['breadcrumb'] = $breadcrumb;

    echo json_encode(['success' => true, 'folder' => $folder]);
}

function moveModelToFolder() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);
    $folderId = isset($_POST['folder_id']) ? ((int)$_POST['folder_id'] ?: null) : null;

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();

    // Verify folder ownership if specified
    if ($folderId) {
        $stmt = $db->prepare('SELECT user_id FROM folders WHERE id = :id');
        $stmt->execute([':id' => $folderId]);
        $folder = $stmt->fetch();

        if (!$folder || ($folder['user_id'] !== $user['id'] && !$user['is_admin'])) {
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            return;
        }
    }

    $stmt = $db->prepare('UPDATE models SET folder_id = :folder_id WHERE id = :id');
    $stmt->execute([':folder_id' => $folderId, ':id' => $modelId]);

    echo json_encode(['success' => true]);
}

function getFolderTree() {
    global $user;

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM folders WHERE user_id = :user_id ORDER BY sort_order, name');
    $stmt->execute([':user_id' => $user['id']]);

    $folders = [];
    while ($row = $stmt->fetch()) {
        $folders[$row['id']] = $row;
        $folders[$row['id']]['children'] = [];
    }

    // Build tree structure
    $tree = [];
    foreach ($folders as $id => &$folder) {
        if ($folder['parent_id'] && isset($folders[$folder['parent_id']])) {
            $folders[$folder['parent_id']]['children'][] = &$folder;
        } else {
            $tree[] = &$folder;
        }
    }

    echo json_encode(['success' => true, 'tree' => $tree]);
}
