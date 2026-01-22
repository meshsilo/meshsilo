<?php
/**
 * Smart Collections Actions
 * Dynamic collections based on rules
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        createSmartCollection();
        break;
    case 'update':
        updateSmartCollection();
        break;
    case 'delete':
        deleteSmartCollection();
        break;
    case 'list':
        listSmartCollections();
        break;
    case 'get':
        getSmartCollection();
        break;
    case 'models':
        getSmartCollectionModels();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function createSmartCollection() {
    global $user;

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rules = $_POST['rules'] ?? '';
    $isPublic = isset($_POST['is_public']) ? 1 : 0;

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name required']);
        return;
    }

    if (empty($rules)) {
        echo json_encode(['success' => false, 'error' => 'At least one rule required']);
        return;
    }

    // Validate rules JSON
    $rulesArray = is_string($rules) ? json_decode($rules, true) : $rules;
    if (!is_array($rulesArray)) {
        echo json_encode(['success' => false, 'error' => 'Invalid rules format']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO smart_collections (user_id, name, description, rules, is_public)
        VALUES (:user_id, :name, :description, :rules, :is_public)
    ');
    $stmt->execute([
        ':user_id' => $user['id'],
        ':name' => $name,
        ':description' => $description,
        ':rules' => json_encode($rulesArray),
        ':is_public' => $isPublic
    ]);

    $collectionId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'collection_id' => $collectionId
    ]);
}

function updateSmartCollection() {
    global $user;

    $collectionId = (int)($_POST['collection_id'] ?? 0);
    if (!$collectionId) {
        echo json_encode(['success' => false, 'error' => 'Collection ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM smart_collections WHERE id = :id');
    $stmt->execute([':id' => $collectionId]);
    $collection = $stmt->fetch();

    if (!$collection || ($collection['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $updates = [];
    $params = [':id' => $collectionId];

    if (isset($_POST['name'])) {
        $updates[] = 'name = :name';
        $params[':name'] = trim($_POST['name']);
    }
    if (isset($_POST['description'])) {
        $updates[] = 'description = :description';
        $params[':description'] = trim($_POST['description']);
    }
    if (isset($_POST['rules'])) {
        $rules = $_POST['rules'];
        $rulesArray = is_string($rules) ? json_decode($rules, true) : $rules;
        $updates[] = 'rules = :rules';
        $params[':rules'] = json_encode($rulesArray);
    }
    if (isset($_POST['is_public'])) {
        $updates[] = 'is_public = :is_public';
        $params[':is_public'] = $_POST['is_public'] ? 1 : 0;
    }

    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        return;
    }

    $sql = 'UPDATE smart_collections SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
}

function deleteSmartCollection() {
    global $user;

    $collectionId = (int)($_POST['collection_id'] ?? 0);
    if (!$collectionId) {
        echo json_encode(['success' => false, 'error' => 'Collection ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id FROM smart_collections WHERE id = :id');
    $stmt->execute([':id' => $collectionId]);
    $collection = $stmt->fetch();

    if (!$collection || ($collection['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM smart_collections WHERE id = :id');
    $stmt->execute([':id' => $collectionId]);

    echo json_encode(['success' => true]);
}

function listSmartCollections() {
    global $user;

    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM smart_collections
        WHERE user_id = :user_id OR is_public = 1
        ORDER BY name ASC
    ');
    $stmt->execute([':user_id' => $user['id']]);

    $collections = [];
    while ($row = $stmt->fetch()) {
        $row['rules'] = json_decode($row['rules'], true);
        $row['is_owner'] = $row['user_id'] === $user['id'];
        $collections[] = $row;
    }

    echo json_encode(['success' => true, 'collections' => $collections]);
}

function getSmartCollection() {
    global $user;

    $collectionId = (int)($_GET['collection_id'] ?? 0);
    if (!$collectionId) {
        echo json_encode(['success' => false, 'error' => 'Collection ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM smart_collections
        WHERE id = :id AND (user_id = :user_id OR is_public = 1)
    ');
    $stmt->execute([':id' => $collectionId, ':user_id' => $user['id']]);
    $collection = $stmt->fetch();

    if (!$collection) {
        echo json_encode(['success' => false, 'error' => 'Collection not found']);
        return;
    }

    $collection['rules'] = json_decode($collection['rules'], true);
    $collection['is_owner'] = $collection['user_id'] === $user['id'];

    echo json_encode(['success' => true, 'collection' => $collection]);
}

function getSmartCollectionModels() {
    global $user;

    $collectionId = (int)($_GET['collection_id'] ?? 0);
    $limit = min(100, (int)($_GET['limit'] ?? 50));
    $offset = (int)($_GET['offset'] ?? 0);

    if (!$collectionId) {
        echo json_encode(['success' => false, 'error' => 'Collection ID required']);
        return;
    }

    $db = getDB();

    // Get collection rules
    $stmt = $db->prepare('
        SELECT rules FROM smart_collections
        WHERE id = :id AND (user_id = :user_id OR is_public = 1)
    ');
    $stmt->execute([':id' => $collectionId, ':user_id' => $user['id']]);
    $collection = $stmt->fetch();

    if (!$collection) {
        echo json_encode(['success' => false, 'error' => 'Collection not found']);
        return;
    }

    $rules = json_decode($collection['rules'], true);
    $models = executeSmartCollectionRules($rules, $limit, $offset);

    echo json_encode(['success' => true, 'models' => $models]);
}

/**
 * Execute smart collection rules and return matching models
 */
function executeSmartCollectionRules($rules, $limit = 50, $offset = 0) {
    $db = getDB();

    $where = ['m.parent_id IS NULL'];
    $params = [];
    $joins = [];

    foreach ($rules as $rule) {
        $field = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? '=';
        $value = $rule['value'] ?? '';

        switch ($field) {
            case 'is_printed':
                $where[] = 'm.is_printed = :is_printed';
                $params[':is_printed'] = $value ? 1 : 0;
                break;

            case 'is_archived':
                $where[] = '(m.is_archived = :is_archived OR m.is_archived IS NULL)';
                $params[':is_archived'] = $value ? 1 : 0;
                break;

            case 'file_type':
                $where[] = 'm.file_type = :file_type';
                $params[':file_type'] = $value;
                break;

            case 'print_type':
                $where[] = 'm.print_type = :print_type';
                $params[':print_type'] = $value;
                break;

            case 'created_days':
                $days = (int)$value;
                $type = $db->getType();
                if ($type === 'mysql') {
                    $where[] = 'm.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)';
                } else {
                    $where[] = "m.created_at >= datetime('now', '-' || :days || ' days')";
                }
                $params[':days'] = $days;
                break;

            case 'has_tag':
                $joins[] = 'JOIN model_tags mt ON m.id = mt.model_id';
                $joins[] = 'JOIN tags t ON mt.tag_id = t.id';
                $where[] = 't.name = :tag_name';
                $params[':tag_name'] = $value;
                break;

            case 'category':
                $joins[] = 'JOIN model_categories mc ON m.id = mc.model_id';
                $joins[] = 'JOIN categories c ON mc.category_id = c.id';
                $where[] = 'c.name = :category_name';
                $params[':category_name'] = $value;
                break;

            case 'min_size':
                $where[] = 'm.file_size >= :min_size';
                $params[':min_size'] = (int)$value;
                break;

            case 'max_size':
                $where[] = 'm.file_size <= :max_size';
                $params[':max_size'] = (int)$value;
                break;

            case 'has_dimensions':
                $where[] = 'm.dim_x IS NOT NULL';
                break;

            case 'favorited':
                global $user;
                $joins[] = 'JOIN favorites f ON m.id = f.model_id';
                $where[] = 'f.user_id = :fav_user_id';
                $params[':fav_user_id'] = $user['id'];
                break;
        }
    }

    $joinClause = implode(' ', array_unique($joins));
    $whereClause = implode(' AND ', $where);

    $sql = "SELECT DISTINCT m.* FROM models m $joinClause WHERE $whereClause ORDER BY m.created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $models = [];
    while ($row = $stmt->fetch()) {
        $models[] = $row;
    }

    return $models;
}
