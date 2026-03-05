<?php
/**
 * Collections API Routes
 *
 * GET    /api/collections          - List collections
 * GET    /api/collections/{id}     - Get single collection
 * POST   /api/collections          - Create collection
 * PUT    /api/collections/{id}     - Update collection
 * DELETE /api/collections/{id}     - Delete collection
 */

function handleCollectionsRoute($method, $id, $apiUser) {
    switch ($method) {
        case 'GET':
            if ($id === null) {
                listCollections($apiUser);
            } else {
                getCollection(validateId($id), $apiUser);
            }
            break;

        case 'POST':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            createCollectionApi($apiUser);
            break;

        case 'PUT':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            updateCollection(validateId($id), $apiUser);
            break;

        case 'DELETE':
            requireApiPermission($apiUser, API_PERM_DELETE);
            deleteCollectionApi(validateId($id), $apiUser);
            break;

        default:
            apiError('Method not allowed', 405);
    }
}

/**
 * List all collections with model counts
 */
function listCollections($apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $result = $db->query('
        SELECT c.*,
               (SELECT COUNT(*) FROM models m WHERE m.collection = c.name AND m.parent_id IS NULL) as model_count
        FROM collections c
        ORDER BY c.name
    ');

    $collections = [];
    while ($row = $result->fetch()) {
        $collections[] = formatCollectionForApi($row, true);
    }

    apiResponse(['data' => $collections]);
}

/**
 * Get a single collection
 */
function getCollection($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM collections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $collection = $stmt->fetch();

    if (!$collection) {
        apiError('Collection not found', 404);
    }

    // Get model count
    $stmt = $db->prepare('SELECT COUNT(*) FROM models WHERE collection = :name AND parent_id IS NULL');
    $stmt->execute([':name' => $collection['name']]);
    $collection['model_count'] = (int)$stmt->fetchColumn();

    apiResponse(['data' => formatCollectionForApi($collection, true)]);
}

/**
 * Create a new collection
 */
function createCollectionApi($apiUser) {
    $data = getJsonBody();
    validateRequired($data, ['name']);

    $db = getDB();

    // Check for duplicate
    $stmt = $db->prepare('SELECT id FROM collections WHERE name = :name');
    $stmt->execute([':name' => $data['name']]);
    if ($stmt->fetch()) {
        apiError('A collection with this name already exists', 409);
    }

    $stmt = $db->prepare('INSERT INTO collections (name, description) VALUES (:name, :description)');
    $stmt->execute([
        ':name' => $data['name'],
        ':description' => $data['description'] ?? ''
    ]);

    $collectionId = $db->lastInsertId();

    logActivity('create', 'collection', $collectionId, $data['name'], ['via' => 'api']);

    apiResponse([
        'data' => [
            'id' => (int)$collectionId,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'model_count' => 0
        ]
    ], 201);
}

/**
 * Update a collection
 */
function updateCollection($id, $apiUser) {
    $data = getJsonBody();

    $db = getDB();

    // Verify collection exists
    $stmt = $db->prepare('SELECT * FROM collections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $collection = $stmt->fetch();

    if (!$collection) {
        apiError('Collection not found', 404);
    }

    $updates = [];
    $params = [':id' => $id];
    $oldName = $collection['name'];

    if (isset($data['name'])) {
        // Check for duplicate
        $stmt = $db->prepare('SELECT id FROM collections WHERE name = :name AND id != :id');
        $stmt->execute([':name' => $data['name'], ':id' => $id]);
        if ($stmt->fetch()) {
            apiError('A collection with this name already exists', 409);
        }
        $updates[] = 'name = :name';
        $params[':name'] = $data['name'];
    }

    if (isset($data['description'])) {
        $updates[] = 'description = :description';
        $params[':description'] = $data['description'];
    }

    if (empty($updates)) {
        apiError('No valid fields to update', 400);
    }

    $updateClause = implode(', ', $updates);
    $stmt = $db->prepare("UPDATE collections SET $updateClause WHERE id = :id");
    $stmt->execute($params);

    // Update models with this collection name if name changed
    if (isset($data['name']) && $data['name'] !== $oldName) {
        $stmt = $db->prepare('UPDATE models SET collection = :new_name WHERE collection = :old_name');
        $stmt->execute([':new_name' => $data['name'], ':old_name' => $oldName]);
    }

    logActivity('edit', 'collection', $id, $data['name'] ?? $collection['name'], ['via' => 'api']);

    // Return updated collection
    $stmt = $db->prepare('SELECT * FROM collections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $collection = $stmt->fetch();

    // Get model count
    $stmt = $db->prepare('SELECT COUNT(*) FROM models WHERE collection = :name AND parent_id IS NULL');
    $stmt->execute([':name' => $collection['name']]);
    $collection['model_count'] = (int)$stmt->fetchColumn();

    apiResponse(['data' => formatCollectionForApi($collection, true)]);
}

/**
 * Delete a collection
 */
function deleteCollectionApi($id, $apiUser) {
    $db = getDB();

    // Verify collection exists
    $stmt = $db->prepare('SELECT * FROM collections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $collection = $stmt->fetch();

    if (!$collection) {
        apiError('Collection not found', 404);
    }

    // Clear collection name from models
    $stmt = $db->prepare('UPDATE models SET collection = NULL WHERE collection = :name');
    $stmt->execute([':name' => $collection['name']]);

    // Delete collection
    $stmt = $db->prepare('DELETE FROM collections WHERE id = :id');
    $stmt->execute([':id' => $id]);

    logActivity('delete', 'collection', $id, $collection['name'], ['via' => 'api']);

    apiResponse(['success' => true, 'message' => 'Collection deleted']);
}
