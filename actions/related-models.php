<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_POST['action'] ?? '';
$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$relatedModelId = isset($_POST['related_model_id']) ? (int)$_POST['related_model_id'] : 0;

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'No model specified']);
    exit;
}

$user = getCurrentUser();

switch ($action) {
    case 'add':
        if (!$relatedModelId) {
            echo json_encode(['success' => false, 'error' => 'No related model specified']);
            exit;
        }

        $relationshipType = $_POST['relationship_type'] ?? 'related';
        $result = addRelatedModel($modelId, $relatedModelId, $relationshipType);

        if ($result) {
            logActivity($user['id'], 'add_related', 'model', $modelId);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to add relation']);
        }
        break;

    case 'remove':
        if (!$relatedModelId) {
            echo json_encode(['success' => false, 'error' => 'No related model specified']);
            exit;
        }

        $result = removeRelatedModel($modelId, $relatedModelId);

        if ($result) {
            logActivity($user['id'], 'remove_related', 'model', $modelId);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to remove relation']);
        }
        break;

    case 'list':
        $related = getRelatedModels($modelId);
        echo json_encode(['success' => true, 'related' => $related]);
        break;

    case 'search':
        // Search for models to link
        $query = trim($_POST['query'] ?? '');
        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'results' => []]);
            exit;
        }

        $db = getDB();
        $stmt = $db->prepare('
            SELECT id, name, file_type, part_count
            FROM models
            WHERE parent_id IS NULL
            AND id != :model_id
            AND (name LIKE :query OR description LIKE :query)
            LIMIT 10
        ');
        $stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
        $stmt->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
        $result = $stmt->execute();

        $results = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $results[] = $row;
        }

        echo json_encode(['success' => true, 'results' => $results]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
