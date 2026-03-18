<?php
/**
 * Tag management actions
 */
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isFeatureEnabled('tags')) {
    jsonError('Tags feature is disabled', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

requireCsrfJson();

if (!canEdit()) {
    jsonError('Permission denied', 403);
}

$action = $_POST['action'] ?? '';
$modelId = (int)($_POST['model_id'] ?? 0);

if (!$modelId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid model ID']);
    exit;
}

// Verify model exists
$db = getDB();
$stmt = $db->prepare('SELECT id, name FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Model not found']);
    exit;
}

// Verify ownership - only owner or admin can tag
$user = getCurrentUser();
$stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$ownerResult = $stmt->execute();
$ownerInfo = $ownerResult->fetchArray(PDO::FETCH_ASSOC);
if ($ownerInfo && $ownerInfo['user_id'] && $ownerInfo['user_id'] !== $user['id'] && !$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied - not model owner']);
    exit;
}

switch ($action) {
    case 'add':
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagColor = $_POST['tag_color'] ?? '#6366f1';

        if (empty($tagName)) {
            echo json_encode(['success' => false, 'error' => 'Tag name required']);
            exit;
        }

        // Get or create the tag
        $tagId = getOrCreateTag($tagName, $tagColor);
        if (!$tagId) {
            echo json_encode(['success' => false, 'error' => 'Failed to create tag']);
            exit;
        }

        // Add tag to model
        if (addTagToModel($modelId, $tagId)) {
            // Get the tag info to return
            $stmt = $db->prepare('SELECT * FROM tags WHERE id = :id');
            $stmt->bindValue(':id', $tagId, PDO::PARAM_INT);
            $tagResult = $stmt->execute();
            $tag = $tagResult->fetchArray(PDO::FETCH_ASSOC);

            logActivity('add_tag', 'model', $modelId, $model['name'], ['tag' => $tagName]);
            echo json_encode(['success' => true, 'tag' => $tag]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to add tag']);
        }
        break;

    case 'remove':
        $tagId = (int)($_POST['tag_id'] ?? 0);

        if (!$tagId) {
            echo json_encode(['success' => false, 'error' => 'Tag ID required']);
            exit;
        }

        // Get tag name for logging
        $stmt = $db->prepare('SELECT name FROM tags WHERE id = :id');
        $stmt->bindValue(':id', $tagId, PDO::PARAM_INT);
        $stmt->execute();
        $tagName = $stmt->fetchColumn();

        if (removeTagFromModel($modelId, $tagId)) {
            logActivity('remove_tag', 'model', $modelId, $model['name'], ['tag' => $tagName]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to remove tag']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
