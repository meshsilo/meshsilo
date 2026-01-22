<?php
/**
 * Categories API Routes
 *
 * GET    /api/categories          - List categories
 * GET    /api/categories/{id}     - Get single category
 * POST   /api/categories          - Create category
 * PUT    /api/categories/{id}     - Update category
 * DELETE /api/categories/{id}     - Delete category
 */

function handleCategoriesRoute($method, $id, $apiUser) {
    switch ($method) {
        case 'GET':
            if ($id === null) {
                listCategories($apiUser);
            } else {
                getCategory(validateId($id), $apiUser);
            }
            break;

        case 'POST':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            createCategory($apiUser);
            break;

        case 'PUT':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            updateCategory(validateId($id), $apiUser);
            break;

        case 'DELETE':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            deleteCategoryApi(validateId($id), $apiUser);
            break;

        default:
            apiError('Method not allowed', 405);
    }
}

/**
 * List all categories with model counts
 */
function listCategories($apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $result = $db->query('
        SELECT c.*, COUNT(mc.model_id) as model_count
        FROM categories c
        LEFT JOIN model_categories mc ON c.id = mc.category_id
        LEFT JOIN models m ON mc.model_id = m.id AND m.parent_id IS NULL
        GROUP BY c.id
        ORDER BY c.name
    ');

    $categories = [];
    while ($row = $result->fetch()) {
        $categories[] = formatCategoryForApi($row, true);
    }

    apiResponse(['data' => $categories]);
}

/**
 * Get a single category with its models
 */
function getCategory($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $stmt = $db->prepare('
        SELECT c.*, COUNT(mc.model_id) as model_count
        FROM categories c
        LEFT JOIN model_categories mc ON c.id = mc.category_id
        LEFT JOIN models m ON mc.model_id = m.id AND m.parent_id IS NULL
        WHERE c.id = :id
        GROUP BY c.id
    ');
    $stmt->execute([':id' => $id]);
    $category = $stmt->fetch();

    if (!$category) {
        apiError('Category not found', 404);
    }

    apiResponse(['data' => formatCategoryForApi($category, true)]);
}

/**
 * Create a new category
 */
function createCategory($apiUser) {
    $data = getJsonBody();
    validateRequired($data, ['name']);

    $db = getDB();

    // Check for duplicate
    $stmt = $db->prepare('SELECT id FROM categories WHERE name = :name');
    $stmt->execute([':name' => $data['name']]);
    if ($stmt->fetch()) {
        apiError('A category with this name already exists', 409);
    }

    $stmt = $db->prepare('INSERT INTO categories (name) VALUES (:name)');
    $stmt->execute([':name' => $data['name']]);

    $categoryId = $db->lastInsertId();

    logActivity('create', 'category', $categoryId, $data['name'], ['via' => 'api']);

    apiResponse([
        'data' => [
            'id' => (int)$categoryId,
            'name' => $data['name'],
            'model_count' => 0
        ]
    ], 201);
}

/**
 * Update a category
 */
function updateCategory($id, $apiUser) {
    $data = getJsonBody();
    validateRequired($data, ['name']);

    $db = getDB();

    // Verify category exists
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $category = $stmt->fetch();

    if (!$category) {
        apiError('Category not found', 404);
    }

    // Check for duplicate name
    $stmt = $db->prepare('SELECT id FROM categories WHERE name = :name AND id != :id');
    $stmt->execute([':name' => $data['name'], ':id' => $id]);
    if ($stmt->fetch()) {
        apiError('A category with this name already exists', 409);
    }

    $stmt = $db->prepare('UPDATE categories SET name = :name WHERE id = :id');
    $stmt->execute([':name' => $data['name'], ':id' => $id]);

    logActivity('edit', 'category', $id, $data['name'], ['via' => 'api']);

    apiResponse([
        'data' => [
            'id' => (int)$id,
            'name' => $data['name']
        ]
    ]);
}

/**
 * Delete a category
 */
function deleteCategoryApi($id, $apiUser) {
    $db = getDB();

    // Verify category exists
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $category = $stmt->fetch();

    if (!$category) {
        apiError('Category not found', 404);
    }

    // Delete (cascade will remove model_categories entries)
    $stmt = $db->prepare('DELETE FROM categories WHERE id = :id');
    $stmt->execute([':id' => $id]);

    logActivity('delete', 'category', $id, $category['name'], ['via' => 'api']);

    apiResponse(['success' => true, 'message' => 'Category deleted']);
}
