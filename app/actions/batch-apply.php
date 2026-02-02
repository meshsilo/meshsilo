<?php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// CSRF validation
if (!Csrf::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? '';
$modelIds = isset($_POST['model_ids']) ? array_filter(array_map('intval', (array)$_POST['model_ids'])) : [];

if (empty($modelIds)) {
    echo json_encode(['success' => false, 'error' => 'No models specified']);
    exit;
}

$db = getDB();

// Filter model IDs to only include models the user owns (unless admin)
// This prevents users from batch-modifying other users' models
if (!$user['is_admin']) {
    $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
    $stmt = $db->prepare("SELECT id FROM models WHERE id IN ($placeholders) AND (user_id = ? OR user_id IS NULL)");
    $params = array_merge($modelIds, [$user['id']]);
    $stmt->execute($params);
    $ownedModelIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ownedModelIds[] = (int)$row['id'];
    }

    // Check if user is trying to modify models they don't own
    $unauthorizedCount = count(array_diff($modelIds, $ownedModelIds));
    if ($unauthorizedCount > 0 && empty($ownedModelIds)) {
        echo json_encode(['success' => false, 'error' => 'Permission denied - you can only modify your own models']);
        exit;
    }

    // Use only owned model IDs
    $modelIds = $ownedModelIds;

    if (empty($modelIds)) {
        echo json_encode(['success' => false, 'error' => 'No authorized models to modify']);
        exit;
    }
}

$successCount = 0;
$errorCount = 0;

switch ($action) {
    case 'add_tag':
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagId = isset($_POST['tag_id']) ? (int)$_POST['tag_id'] : 0;

        if (!$tagName && !$tagId) {
            echo json_encode(['success' => false, 'error' => 'No tag specified']);
            exit;
        }

        // Get or create tag
        if ($tagId) {
            $stmt = $db->prepare('SELECT id FROM tags WHERE id = :id');
            $stmt->bindValue(':id', $tagId, PDO::PARAM_INT);
            $result = $stmt->execute();
            if (!$result->fetchArray()) {
                $tagId = 0;
            }
        }

        if (!$tagId && $tagName) {
            // Check if tag exists by name
            $stmt = $db->prepare('SELECT id FROM tags WHERE LOWER(name) = LOWER(:name)');
            $stmt->bindValue(':name', $tagName, PDO::PARAM_STR);
            $result = $stmt->execute();
            $row = $result->fetchArray(PDO::FETCH_ASSOC);

            if ($row) {
                $tagId = $row['id'];
            } else {
                // Create new tag
                $colors = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6'];
                $color = $colors[array_rand($colors)];

                $stmt = $db->prepare('INSERT INTO tags (name, color) VALUES (:name, :color)');
                $stmt->bindValue(':name', $tagName, PDO::PARAM_STR);
                $stmt->bindValue(':color', $color, PDO::PARAM_STR);
                $stmt->execute();
                $tagId = $db->lastInsertRowID();
            }
        }

        if (!$tagId) {
            echo json_encode(['success' => false, 'error' => 'Failed to get/create tag']);
            exit;
        }

        foreach ($modelIds as $modelId) {
            if (addTagToModel($modelId, $tagId)) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_add_tag', 'models', 0, "Added tag to $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'remove_tag':
        $tagId = isset($_POST['tag_id']) ? (int)$_POST['tag_id'] : 0;

        if (!$tagId) {
            echo json_encode(['success' => false, 'error' => 'No tag specified']);
            exit;
        }

        foreach ($modelIds as $modelId) {
            if (removeTagFromModel($modelId, $tagId)) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_remove_tag', 'models', 0, "Removed tag from $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'add_category':
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

        if (!$categoryId) {
            echo json_encode(['success' => false, 'error' => 'No category specified']);
            exit;
        }

        foreach ($modelIds as $modelId) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_add_category', 'models', 0, "Added category to $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'remove_category':
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

        if (!$categoryId) {
            echo json_encode(['success' => false, 'error' => 'No category specified']);
            exit;
        }

        foreach ($modelIds as $modelId) {
            $stmt = $db->prepare('DELETE FROM model_categories WHERE model_id = :model_id AND category_id = :category_id');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_remove_category', 'models', 0, "Removed category from $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'archive':
        $archive = ($_POST['archive'] ?? '1') === '1' ? 1 : 0;

        foreach ($modelIds as $modelId) {
            $stmt = $db->prepare('UPDATE models SET is_archived = :archived WHERE id = :id');
            $stmt->bindValue(':archived', $archive, PDO::PARAM_INT);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        $action_name = $archive ? 'batch_archive' : 'batch_unarchive';
        logActivity($user['id'], $action_name, 'models', 0, ($archive ? 'Archived' : 'Unarchived') . " $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'delete':
        if (!$user['is_admin']) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            exit;
        }

        foreach ($modelIds as $modelId) {
            // Get model info for logging
            $stmt = $db->prepare('SELECT name FROM models WHERE id = :id');
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            $model = $result->fetchArray(PDO::FETCH_ASSOC);

            if ($model) {
                // Delete model (cascade will handle parts, tags, etc.)
                $stmt = $db->prepare('DELETE FROM models WHERE id = :id1 OR parent_id = :id2');
                $stmt->bindValue(':id1', $modelId, PDO::PARAM_INT);
                $stmt->bindValue(':id2', $modelId, PDO::PARAM_INT);
                if ($stmt->execute()) {
                    $successCount++;
                    logActivity($user['id'], 'delete', 'model', $modelId, $model['name']);
                } else {
                    $errorCount++;
                }
            }
        }

        echo json_encode(['success' => true, 'deleted' => $successCount, 'failed' => $errorCount]);
        break;

    case 'add_to_queue':
        foreach ($modelIds as $modelId) {
            if (addToPrintQueue($user['id'], $modelId)) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_add_to_queue', 'models', 0, "Added $successCount models to print queue");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'set_collection':
        $collection = trim($_POST['collection'] ?? '');

        foreach ($modelIds as $modelId) {
            $stmt = $db->prepare('UPDATE models SET collection = :collection WHERE id = :id');
            $stmt->bindValue(':collection', $collection ?: null, PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_set_collection', 'models', 0, "Set collection on $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'set_creator':
        $creator = trim($_POST['creator'] ?? '');

        foreach ($modelIds as $modelId) {
            $stmt = $db->prepare('UPDATE models SET creator = :creator WHERE id = :id');
            $stmt->bindValue(':creator', $creator ?: null, PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_set_creator', 'models', 0, "Set creator on $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    case 'set_license':
        $license = $_POST['license'] ?? '';

        foreach ($modelIds as $modelId) {
            $stmt = $db->prepare('UPDATE models SET license = :license WHERE id = :id');
            $stmt->bindValue(':license', $license, PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        logActivity($user['id'], 'batch_set_license', 'models', 0, "Set license on $successCount models");
        echo json_encode(['success' => true, 'updated' => $successCount, 'failed' => $errorCount]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
