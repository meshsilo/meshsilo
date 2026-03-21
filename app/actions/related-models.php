<?php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

// Support JSON body
$input = json_decode(file_get_contents('php://input'), true);
if ($input) {
    $_POST = array_merge($_POST, $input);
}

$action = $_POST['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['add', 'remove']) && !Csrf::check()) {
    jsonError('Invalid CSRF token');
}

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$relatedModelId = isset($_POST['related_model_id']) ? (int)$_POST['related_model_id'] : 0;

if (!$modelId) {
    jsonError('No model specified');
}

$user = getCurrentUser();

switch ($action) {
    case 'add':
        if (!$relatedModelId) {
            jsonError('No related model specified');
        }

        $relationshipType = $_POST['relationship_type'] ?? 'related';
        $result = addRelatedModel($modelId, $relatedModelId, $relationshipType);

        if ($result) {
            logActivity('add_related', 'model', $modelId);
            jsonSuccess();
        } else {
            jsonError('Failed to add relation');
        }
        break;

    case 'remove':
        if (!$relatedModelId) {
            jsonError('No related model specified');
        }

        $result = removeRelatedModel($modelId, $relatedModelId);

        if ($result) {
            logActivity('remove_related', 'model', $modelId);
            jsonSuccess();
        } else {
            jsonError('Failed to remove relation');
        }
        break;

    case 'list':
        $related = getRelatedModels($modelId);
        jsonSuccess(['related' => $related]);
        break;

    case 'search':
        // Search for models to link
        $query = trim($_POST['query'] ?? '');
        if (strlen($query) < 2) {
            jsonSuccess(['results' => []]);
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
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
        $result = $stmt->execute();

        $results = [];
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $results[] = $row;
        }

        jsonSuccess(['results' => $results]);
        break;

    case 'set_remix_source':
        // Set the original source for a remix
        $remixOf = isset($_POST['remix_of']) ? (int)$_POST['remix_of'] : null;
        $externalUrl = trim($_POST['external_url'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $db = getDB();

        // Check model exists and user has permission
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $model = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

        if (!$model) {
            jsonError('Model not found');
        }

        if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
            jsonError('Permission denied', 403);
        }

        // Prevent circular references
        if ($remixOf) {
            if ($remixOf == $modelId) {
                jsonError('A model cannot be a remix of itself');
            }

            // Check the remix target exists
            $stmt = $db->prepare('SELECT id FROM models WHERE id = :id AND parent_id IS NULL');
            $stmt->bindValue(':id', $remixOf, PDO::PARAM_INT);
            if (!$stmt->execute()->fetchArray()) {
                jsonError('Original model not found');
            }
        }

        // Update the model
        if ($remixOf) {
            $stmt = $db->prepare('
                UPDATE models
                SET remix_of = :remix_of, external_source_url = NULL, external_source_id = NULL
                WHERE id = :id
            ');
            $stmt->bindValue(':remix_of', $remixOf, PDO::PARAM_INT);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            // Also add to related_models for easy querying
            $stmt = $db->prepare('
                INSERT OR REPLACE INTO related_models (model_id, related_model_id, relationship_type, is_remix, remix_notes, created_by)
                VALUES (:model_id, :related_id, :type, 1, :notes, :user_id)
            ');
            $stmt->bindValue(':model_id', $remixOf, PDO::PARAM_INT);
            $stmt->bindValue(':related_id', $modelId, PDO::PARAM_INT);
            $stmt->bindValue(':type', 'remix', PDO::PARAM_STR);
            $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
            $stmt->execute();

            logActivity('set_remix_source', 'model', $modelId, 'Set as remix of model #' . $remixOf);
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
            $stmt->bindValue(':url', $externalUrl, PDO::PARAM_STR);
            $stmt->bindValue(':ext_id', $externalId, PDO::PARAM_STR);
            $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
            $stmt->execute();

            logActivity('set_remix_source', 'model', $modelId, 'Set external source: ' . $externalUrl);
        } else {
            jsonError('Either remix_of or external_url is required');
        }

        jsonSuccess(['message' => 'Remix source updated']);
        break;

    case 'clear_remix_source':
        // Clear the remix source
        $db = getDB();

        // Check model exists and user has permission
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $model = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

        if (!$model) {
            jsonError('Model not found');
        }

        if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
            jsonError('Permission denied', 403);
        }

        // Clear remix info
        $stmt = $db->prepare('
            UPDATE models
            SET remix_of = NULL, external_source_url = NULL, external_source_id = NULL
            WHERE id = :id
        ');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $stmt->execute();

        // Remove from related_models
        if ($model['remix_of']) {
            $stmt = $db->prepare('
                DELETE FROM related_models
                WHERE model_id = :parent_id AND related_model_id = :model_id AND is_remix = 1
            ');
            $stmt->bindValue(':parent_id', $model['remix_of'], PDO::PARAM_INT);
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $stmt->execute();
        }

        logActivity('clear_remix_source', 'model', $modelId);
        jsonSuccess(['message' => 'Remix source cleared']);
        break;

    default:
        jsonError('Unknown action');
}
