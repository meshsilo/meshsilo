<?php
/**
 * Tags API Routes
 *
 * GET    /api/tags          - List tags
 * GET    /api/tags/{id}     - Get single tag
 * POST   /api/tags          - Create tag
 * PUT    /api/tags/{id}     - Update tag
 * DELETE /api/tags/{id}     - Delete tag
 */

function handleTagsRoute($method, $id, $apiUser) {
    switch ($method) {
        case 'GET':
            if ($id === null) {
                listTags($apiUser);
            } else {
                getTag(validateId($id), $apiUser);
            }
            break;

        case 'POST':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            createTagApi($apiUser);
            break;

        case 'PUT':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            updateTag(validateId($id), $apiUser);
            break;

        case 'DELETE':
            requireApiPermission($apiUser, API_PERM_DELETE);
            deleteTagApi(validateId($id), $apiUser);
            break;

        default:
            apiError('Method not allowed', 405);
    }
}

/**
 * List all tags with model counts
 */
function listTags($apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $result = $db->query('
        SELECT t.*, COUNT(mt.model_id) as model_count
        FROM tags t
        LEFT JOIN model_tags mt ON t.id = mt.tag_id
        LEFT JOIN models m ON mt.model_id = m.id AND m.parent_id IS NULL
        GROUP BY t.id
        ORDER BY t.name
    ');

    $tags = [];
    while ($row = $result->fetch()) {
        $tags[] = formatTagForApi($row, true);
    }

    apiResponse(['data' => $tags]);
}

/**
 * Get a single tag
 */
function getTag($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $stmt = $db->prepare('
        SELECT t.*, COUNT(mt.model_id) as model_count
        FROM tags t
        LEFT JOIN model_tags mt ON t.id = mt.tag_id
        LEFT JOIN models m ON mt.model_id = m.id AND m.parent_id IS NULL
        WHERE t.id = :id
        GROUP BY t.id
    ');
    $stmt->execute([':id' => $id]);
    $tag = $stmt->fetch();

    if (!$tag) {
        apiError('Tag not found', 404);
    }

    apiResponse(['data' => formatTagForApi($tag, true)]);
}

/**
 * Create a new tag
 */
function createTagApi($apiUser) {
    $data = getJsonBody();
    validateRequired($data, ['name']);

    $name = trim($data['name']);
    $color = $data['color'] ?? '#6366f1';

    // Validate color format
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        apiError('Invalid color format. Use hex format like #6366f1', 400);
    }

    // Check for duplicate
    $existing = getTagByName($name);
    if ($existing) {
        apiError('A tag with this name already exists', 409);
    }

    $tagId = createTag($name, $color);

    if (!$tagId) {
        apiError('Failed to create tag', 500);
    }

    logActivity('create', 'tag', $tagId, $name, ['via' => 'api']);

    apiResponse([
        'data' => [
            'id' => (int)$tagId,
            'name' => $name,
            'color' => $color,
            'model_count' => 0
        ]
    ], 201);
}

/**
 * Update a tag
 */
function updateTag($id, $apiUser) {
    $data = getJsonBody();

    $db = getDB();

    // Verify tag exists
    $stmt = $db->prepare('SELECT * FROM tags WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $tag = $stmt->fetch();

    if (!$tag) {
        apiError('Tag not found', 404);
    }

    $updates = [];
    $params = [':id' => $id];

    if (isset($data['name'])) {
        $name = trim($data['name']);
        // Check for duplicate
        $stmt = $db->prepare('SELECT id FROM tags WHERE LOWER(name) = LOWER(:name) AND id != :id');
        $stmt->execute([':name' => $name, ':id' => $id]);
        if ($stmt->fetch()) {
            apiError('A tag with this name already exists', 409);
        }
        $updates[] = 'name = :name';
        $params[':name'] = $name;
    }

    if (isset($data['color'])) {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            apiError('Invalid color format. Use hex format like #6366f1', 400);
        }
        $updates[] = 'color = :color';
        $params[':color'] = $data['color'];
    }

    if (empty($updates)) {
        apiError('No valid fields to update', 400);
    }

    $updateClause = implode(', ', $updates);
    $stmt = $db->prepare("UPDATE tags SET $updateClause WHERE id = :id");
    $stmt->execute($params);

    logActivity('edit', 'tag', $id, $data['name'] ?? $tag['name'], ['via' => 'api']);

    // Return updated tag
    $stmt = $db->prepare('SELECT * FROM tags WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $tag = $stmt->fetch();

    apiResponse(['data' => formatTagForApi($tag)]);
}

/**
 * Delete a tag
 */
function deleteTagApi($id, $apiUser) {
    $db = getDB();

    // Verify tag exists
    $stmt = $db->prepare('SELECT * FROM tags WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $tag = $stmt->fetch();

    if (!$tag) {
        apiError('Tag not found', 404);
    }

    deleteTag($id);

    logActivity('delete', 'tag', $id, $tag['name'], ['via' => 'api']);

    apiResponse(['success' => true, 'message' => 'Tag deleted']);
}
