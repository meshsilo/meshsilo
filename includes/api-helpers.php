<?php

/**
 * API Helper Functions
 */

/**
 * Send a JSON API response
 */
function apiResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send an API error response
 */
function apiError($message, $statusCode = 400, $details = null)
{
    $response = [
        'error' => true,
        'message' => $message
    ];
    if ($details !== null) {
        $response['details'] = $details;
    }
    apiResponse($response, $statusCode);
}

/**
 * Get JSON request body
 */
function getJsonBody()
{
    $json = file_get_contents('php://input');
    if (empty($json)) {
        return [];
    }
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        apiError('Invalid JSON in request body', 400);
    }
    return $data;
}

/**
 * Get pagination parameters
 */
function getPaginationParams($defaults = [])
{
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(1, intval($_GET['limit'] ?? ($defaults['limit'] ?? 20))));
    $offset = ($page - 1) * $limit;

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Create paginated response
 */
function paginatedResponse($items, $total, $page, $limit)
{
    $totalPages = ceil($total / $limit);

    return [
        'data' => $items,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages
        ]
    ];
}

/**
 * Format a model for API response
 */
function formatModelForApi($model)
{
    if (!$model) {
        return null;
    }

    // Get categories
    $categories = getModelCategories($model['id']);

    // Get tags
    $tags = getModelTags($model['id']);

    return [
        'id' => (int)$model['id'],
        'name' => $model['name'],
        'filename' => $model['filename'],
        'file_type' => $model['file_type'],
        'file_size' => (int)$model['file_size'],
        'description' => $model['description'],
        'creator' => $model['creator'],
        'collection' => $model['collection'],
        'source_url' => $model['source_url'],
        'license' => $model['license'],
        'print_type' => $model['print_type'],
        'dimensions' => [
            'x' => $model['dim_x'] ? (float)$model['dim_x'] : null,
            'y' => $model['dim_y'] ? (float)$model['dim_y'] : null,
            'z' => $model['dim_z'] ? (float)$model['dim_z'] : null,
            'unit' => $model['dim_unit'] ?? 'mm'
        ],
        'download_count' => (int)($model['download_count'] ?? 0),
        'part_count' => (int)($model['part_count'] ?? 0),
        'is_archived' => (bool)($model['is_archived'] ?? false),
        'is_printed' => (bool)($model['is_printed'] ?? false),
        'printed_at' => $model['printed_at'],
        'current_version' => (int)($model['current_version'] ?? 1),
        'categories' => array_map(function ($c) {
            return ['id' => (int)$c['id'], 'name' => $c['name']];
        }, $categories),
        'tags' => array_map(function ($t) {
            return ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color']];
        }, $tags),
        'created_at' => $model['created_at'],
        'updated_at' => $model['updated_at']
    ];
}

/**
 * Get model categories
 */
function getModelCategories($modelId)
{
    $db = getDB();
    $stmt = $db->prepare('
        SELECT c.* FROM categories c
        JOIN model_categories mc ON c.id = mc.category_id
        WHERE mc.model_id = :model_id
        ORDER BY c.name
    ');
    $stmt->execute([':model_id' => $modelId]);

    $categories = [];
    while ($row = $stmt->fetch()) {
        $categories[] = $row;
    }
    return $categories;
}

/**
 * Format a category for API response
 */
function formatCategoryForApi($category, $includeCount = false)
{
    $result = [
        'id' => (int)$category['id'],
        'name' => $category['name']
    ];

    if ($includeCount && isset($category['model_count'])) {
        $result['model_count'] = (int)$category['model_count'];
    }

    return $result;
}

/**
 * Format a tag for API response
 */
function formatTagForApi($tag, $includeCount = false)
{
    $result = [
        'id' => (int)$tag['id'],
        'name' => $tag['name'],
        'color' => $tag['color'],
        'created_at' => $tag['created_at']
    ];

    if ($includeCount && isset($tag['model_count'])) {
        $result['model_count'] = (int)$tag['model_count'];
    }

    return $result;
}

/**
 * Format a collection for API response
 */
function formatCollectionForApi($collection, $includeCount = false)
{
    $result = [
        'id' => (int)$collection['id'],
        'name' => $collection['name'],
        'description' => $collection['description'],
        'created_at' => $collection['created_at']
    ];

    if ($includeCount && isset($collection['model_count'])) {
        $result['model_count'] = (int)$collection['model_count'];
    }

    return $result;
}

/**
 * Validate required fields in request
 */
function validateRequired($data, $fields)
{
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        apiError('Missing required fields: ' . implode(', ', $missing), 400);
    }
}

/**
 * Sanitize and validate ID parameter
 */
function validateId($id, $name = 'id')
{
    if (!is_numeric($id) || $id < 1) {
        apiError("Invalid $name", 400);
    }
    return (int)$id;
}
