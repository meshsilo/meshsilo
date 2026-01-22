<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Support JSON body
$input = json_decode(file_get_contents('php://input'), true);
if ($input) {
    $_POST = array_merge($_POST, $input);
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

    case 'set_remix_source':
        // Set the original source for a remix
        $remixOf = isset($_POST['remix_of']) ? (int)$_POST['remix_of'] : null;
        $externalUrl = trim($_POST['external_url'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $db = getDB();

        // Check model exists and user has permission
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
        $model = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$model) {
            echo json_encode(['success' => false, 'error' => 'Model not found']);
            exit;
        }

        if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        // Prevent circular references
        if ($remixOf) {
            if ($remixOf == $modelId) {
                echo json_encode(['success' => false, 'error' => 'A model cannot be a remix of itself']);
                exit;
            }

            // Check the remix target exists
            $stmt = $db->prepare('SELECT id FROM models WHERE id = :id AND parent_id IS NULL');
            $stmt->bindValue(':id', $remixOf, SQLITE3_INTEGER);
            if (!$stmt->execute()->fetchArray()) {
                echo json_encode(['success' => false, 'error' => 'Original model not found']);
                exit;
            }
        }

        // Update the model
        if ($remixOf) {
            $stmt = $db->prepare('
                UPDATE models
                SET remix_of = :remix_of, external_source_url = NULL, external_source_id = NULL
                WHERE id = :id
            ');
            $stmt->bindValue(':remix_of', $remixOf, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();

            // Also add to related_models for easy querying
            $stmt = $db->prepare('
                INSERT OR REPLACE INTO related_models (model_id, related_model_id, relationship_type, is_remix, remix_notes, created_by)
                VALUES (:model_id, :related_id, :type, 1, :notes, :user_id)
            ');
            $stmt->bindValue(':model_id', $remixOf, SQLITE3_INTEGER);
            $stmt->bindValue(':related_id', $modelId, SQLITE3_INTEGER);
            $stmt->bindValue(':type', 'remix', SQLITE3_TEXT);
            $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $user['id'], SQLITE3_INTEGER);
            $stmt->execute();

            logActivity($user['id'], 'set_remix_source', 'model', $modelId, 'Set as remix of model #' . $remixOf);
        } elseif ($externalUrl) {
            // Extract external source ID if possible
            $externalId = null;
            if (preg_match('/thingiverse\.com\/thing:(\d+)/i', $externalUrl, $matches)) {
                $externalId = 'thingiverse:' . $matches[1];
            } elseif (preg_match('/printables\.com\/model\/(\d+)/i', $externalUrl, $matches)) {
                $externalId = 'printables:' . $matches[1];
            } elseif (preg_match('/cults3d\.com\/.*?\/([^\/]+)$/i', $externalUrl, $matches)) {
                $externalId = 'cults3d:' . $matches[1];
            } elseif (preg_match('/myminifactory\.com\/object\/.*?-(\d+)$/i', $externalUrl, $matches)) {
                $externalId = 'myminifactory:' . $matches[1];
            }

            $stmt = $db->prepare('
                UPDATE models
                SET remix_of = NULL, external_source_url = :url, external_source_id = :ext_id
                WHERE id = :id
            ');
            $stmt->bindValue(':url', $externalUrl, SQLITE3_TEXT);
            $stmt->bindValue(':ext_id', $externalId, SQLITE3_TEXT);
            $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();

            logActivity($user['id'], 'set_remix_source', 'model', $modelId, 'Set external source: ' . $externalUrl);
        } else {
            echo json_encode(['success' => false, 'error' => 'Either remix_of or external_url is required']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Remix source updated']);
        break;

    case 'clear_remix_source':
        // Clear the remix source
        $db = getDB();

        // Check model exists and user has permission
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
        $model = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$model) {
            echo json_encode(['success' => false, 'error' => 'Model not found']);
            exit;
        }

        if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        // Clear remix info
        $stmt = $db->prepare('
            UPDATE models
            SET remix_of = NULL, external_source_url = NULL, external_source_id = NULL
            WHERE id = :id
        ');
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
        $stmt->execute();

        // Remove from related_models
        if ($model['remix_of']) {
            $stmt = $db->prepare('
                DELETE FROM related_models
                WHERE model_id = :parent_id AND related_model_id = :model_id AND is_remix = 1
            ');
            $stmt->bindValue(':parent_id', $model['remix_of'], SQLITE3_INTEGER);
            $stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        logActivity($user['id'], 'clear_remix_source', 'model', $modelId);
        echo json_encode(['success' => true, 'message' => 'Remix source cleared']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
