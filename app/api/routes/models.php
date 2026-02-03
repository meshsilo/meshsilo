<?php
/**
 * Models API Routes
 *
 * GET    /api/models              - List models
 * GET    /api/models/{id}         - Get single model
 * POST   /api/models              - Create/upload model
 * PUT    /api/models/{id}         - Update model
 * DELETE /api/models/{id}         - Delete model
 * GET    /api/models/{id}/parts   - Get model parts
 * GET    /api/models/{id}/download - Download model file
 */

function handleModelsRoute($method, $id, $subResource, $apiUser) {
    switch ($method) {
        case 'GET':
            if ($id === null) {
                listModels($apiUser);
            } elseif ($subResource === 'parts') {
                getModelParts(validateId($id), $apiUser);
            } elseif ($subResource === 'download') {
                downloadModel(validateId($id), $apiUser);
            } else {
                getModel(validateId($id), $apiUser);
            }
            break;

        case 'POST':
            requireApiPermission($apiUser, API_PERM_WRITE);
            createModel($apiUser);
            break;

        case 'PUT':
            requireApiPermission($apiUser, API_PERM_WRITE);
            updateModel(validateId($id), $apiUser);
            break;

        case 'DELETE':
            requireApiPermission($apiUser, API_PERM_DELETE);
            deleteModel(validateId($id), $apiUser);
            break;

        default:
            apiError('Method not allowed', 405);
    }
}

/**
 * List models with filtering and pagination
 */
function listModels($apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $pagination = getPaginationParams();

    // Build query with filters
    $where = ['m.parent_id IS NULL'];
    $params = [];

    // Search filter
    if (!empty($_GET['q'])) {
        $where[] = '(m.name LIKE :search OR m.description LIKE :search OR m.creator LIKE :search)';
        $params[':search'] = '%' . $_GET['q'] . '%';
    }

    // Category filter
    if (!empty($_GET['category_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id AND mc.category_id = :category_id)';
        $params[':category_id'] = $_GET['category_id'];
    }

    // Tag filter
    if (!empty($_GET['tag_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM model_tags mt WHERE mt.model_id = m.id AND mt.tag_id = :tag_id)';
        $params[':tag_id'] = $_GET['tag_id'];
    }

    // Collection filter
    if (!empty($_GET['collection'])) {
        $where[] = 'm.collection = :collection';
        $params[':collection'] = $_GET['collection'];
    }

    // File type filter
    if (!empty($_GET['file_type'])) {
        $where[] = 'm.file_type = :file_type';
        $params[':file_type'] = $_GET['file_type'];
    }

    // Print type filter
    if (!empty($_GET['print_type'])) {
        $where[] = 'm.print_type = :print_type';
        $params[':print_type'] = $_GET['print_type'];
    }

    // Archived filter
    if (isset($_GET['is_archived'])) {
        $where[] = 'm.is_archived = :is_archived';
        $params[':is_archived'] = $_GET['is_archived'] === 'true' ? 1 : 0;
    } else {
        $where[] = '(m.is_archived = 0 OR m.is_archived IS NULL)';
    }

    // Printed filter
    if (isset($_GET['is_printed'])) {
        $where[] = 'm.is_printed = :is_printed';
        $params[':is_printed'] = $_GET['is_printed'] === 'true' ? 1 : 0;
    }

    // Build WHERE clause from conditions array
    // All conditions use parameter placeholders (:param) - no direct interpolation of user input
    $whereClause = implode(' AND ', $where);

    // Sort - SECURITY: $sort is safe because it's selected from a hardcoded whitelist.
    // User input ($_GET['sort']) is used only as a lookup key, never interpolated directly.
    $sortOptions = [
        'newest' => 'm.created_at DESC',
        'oldest' => 'm.created_at ASC',
        'name' => 'm.name ASC',
        'name_desc' => 'm.name DESC',
        'size' => 'm.file_size DESC',
        'downloads' => 'm.download_count DESC',
        'updated' => 'm.updated_at DESC'
    ];
    $sort = $sortOptions[$_GET['sort'] ?? 'newest'] ?? $sortOptions['newest'];

    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM models m WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Get models
    $params[':limit'] = $pagination['limit'];
    $params[':offset'] = $pagination['offset'];

    // SECURITY NOTE: $whereClause is built from $where array which only contains
    // conditions with parameter placeholders (e.g., 'm.name LIKE :search').
    // $sort is whitelist-validated above. Both are safe for interpolation.
    $stmt = $db->prepare("
        SELECT m.* FROM models m
        WHERE $whereClause
        ORDER BY $sort
        LIMIT :limit OFFSET :offset
    ");
    $stmt->execute($params);

    $models = [];
    while ($row = $stmt->fetch()) {
        $models[] = formatModelForApi($row);
    }

    apiResponse(paginatedResponse($models, $total, $pagination['page'], $pagination['limit']));
}

/**
 * Get a single model by ID
 */
function getModel($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $model = $stmt->fetch();

    if (!$model) {
        apiError('Model not found', 404);
    }

    apiResponse(['data' => formatModelForApi($model)]);
}

/**
 * Get parts for a model
 */
function getModelParts($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();

    // Verify model exists
    $stmt = $db->prepare('SELECT id FROM models WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        apiError('Model not found', 404);
    }

    // Get parts
    $stmt = $db->prepare('
        SELECT * FROM models
        WHERE parent_id = :parent_id
        ORDER BY sort_order, name
    ');
    $stmt->execute([':parent_id' => $id]);

    $parts = [];
    while ($row = $stmt->fetch()) {
        $parts[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'filename' => $row['filename'],
            'file_type' => $row['file_type'],
            'file_size' => (int)$row['file_size'],
            'sort_order' => (int)$row['sort_order'],
            'notes' => $row['notes'],
            'is_printed' => (bool)$row['is_printed'],
            'dimensions' => [
                'x' => $row['dim_x'] ? (float)$row['dim_x'] : null,
                'y' => $row['dim_y'] ? (float)$row['dim_y'] : null,
                'z' => $row['dim_z'] ? (float)$row['dim_z'] : null,
                'unit' => $row['dim_unit'] ?? 'mm'
            ]
        ];
    }

    apiResponse(['data' => $parts]);
}

/**
 * Download a model file
 */
function downloadModel($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $model = $stmt->fetch();

    if (!$model) {
        apiError('Model not found', 404);
    }

    $filePath = UPLOAD_PATH . $model['file_path'];
    if (!file_exists($filePath)) {
        apiError('File not found', 404);
    }

    // Increment download count
    incrementDownloadCount($id);

    // Log activity
    logActivity('download', 'model', $id, $model['name'], ['via' => 'api']);

    // Send file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $model['filename'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Content-Type: application/json', true, 200); // Reset content type header
    readfile($filePath);
    exit;
}

/**
 * Create a new model (upload)
 */
function createModel($apiUser) {
    // Check for file upload
    if (empty($_FILES['file'])) {
        apiError('No file uploaded. Use multipart/form-data with a "file" field.', 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];
        apiError($errors[$file['error']] ?? 'Unknown upload error', 400);
    }

    // Validate file extension
    $filename = $file['name'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!isExtensionAllowed($extension)) {
        apiError('File type not allowed. Allowed types: ' . implode(', ', getAllowedExtensions()), 400);
    }

    // Get metadata from request
    $name = $_POST['name'] ?? pathinfo($filename, PATHINFO_FILENAME);
    $description = $_POST['description'] ?? '';
    $creator = $_POST['creator'] ?? '';
    $collection = $_POST['collection'] ?? '';
    $sourceUrl = $_POST['source_url'] ?? '';
    $license = $_POST['license'] ?? '';
    $printType = $_POST['print_type'] ?? '';
    $categoryIds = isset($_POST['category_ids']) ? explode(',', $_POST['category_ids']) : [];
    $tagNames = isset($_POST['tags']) ? explode(',', $_POST['tags']) : [];

    // Create unique folder for this model
    $folderId = uniqid();
    $folderPath = UPLOAD_PATH . $folderId;
    if (!mkdir($folderPath, 0755, true)) {
        apiError('Failed to create storage folder', 500);
    }

    // Move uploaded file
    $destPath = $folderPath . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        rmdir($folderPath);
        apiError('Failed to save uploaded file', 500);
    }

    // Calculate file hash
    $fileHash = hash_file('sha256', $destPath);

    // Insert into database
    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO models (name, filename, file_path, file_size, file_type, description,
                           creator, collection, source_url, license, print_type, file_hash,
                           original_size, created_at, updated_at)
        VALUES (:name, :filename, :file_path, :file_size, :file_type, :description,
                :creator, :collection, :source_url, :license, :print_type, :file_hash,
                :original_size, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        ':name' => $name,
        ':filename' => $filename,
        ':file_path' => $folderId . '/' . $filename,
        ':file_size' => $file['size'],
        ':file_type' => $extension,
        ':description' => $description,
        ':creator' => $creator,
        ':collection' => $collection,
        ':source_url' => $sourceUrl,
        ':license' => $license,
        ':print_type' => $printType,
        ':file_hash' => $fileHash,
        ':original_size' => $file['size']
    ]);

    $modelId = $db->lastInsertId();

    // Add categories
    foreach ($categoryIds as $categoryId) {
        $categoryId = trim($categoryId);
        if (is_numeric($categoryId)) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
            $stmt->execute([':model_id' => $modelId, ':category_id' => $categoryId]);
        }
    }

    // Add tags
    foreach ($tagNames as $tagName) {
        $tagName = trim($tagName);
        if (!empty($tagName)) {
            $tagId = getOrCreateTag($tagName);
            addTagToModel($modelId, $tagId);
        }
    }

    // Log activity
    logActivity('upload', 'model', $modelId, $name, ['via' => 'api', 'size' => $file['size']]);

    // Trigger webhook
    triggerWebhook('model.created', [
        'model_id' => $modelId,
        'name' => $name,
        'file_type' => $extension,
        'file_size' => $file['size']
    ]);

    // Return the created model
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    apiResponse(['data' => formatModelForApi($model)], 201);
}

/**
 * Update a model
 */
function updateModel($id, $apiUser) {
    $db = getDB();

    // Verify model exists
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $model = $stmt->fetch();

    if (!$model) {
        apiError('Model not found', 404);
    }

    $data = getJsonBody();

    // Build update query
    $updates = [];
    $params = [':id' => $id];

    $allowedFields = [
        'name', 'description', 'creator', 'collection', 'source_url',
        'license', 'print_type', 'notes', 'is_archived', 'is_printed'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    if (empty($updates)) {
        apiError('No valid fields to update', 400);
    }

    $updates[] = 'updated_at = CURRENT_TIMESTAMP';
    $updateClause = implode(', ', $updates);

    $stmt = $db->prepare("UPDATE models SET $updateClause WHERE id = :id");
    $stmt->execute($params);

    // Update categories if provided
    if (isset($data['category_ids']) && is_array($data['category_ids'])) {
        // Remove existing
        $stmt = $db->prepare('DELETE FROM model_categories WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $id]);

        // Add new
        foreach ($data['category_ids'] as $categoryId) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
            $stmt->execute([':model_id' => $id, ':category_id' => $categoryId]);
        }
    }

    // Update tags if provided
    if (isset($data['tags']) && is_array($data['tags'])) {
        // Remove existing
        $stmt = $db->prepare('DELETE FROM model_tags WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $id]);

        // Add new
        foreach ($data['tags'] as $tagName) {
            $tagName = trim($tagName);
            if (!empty($tagName)) {
                $tagId = getOrCreateTag($tagName);
                addTagToModel($id, $tagId);
            }
        }
    }

    // Log activity
    logActivity('edit', 'model', $id, $model['name'], ['via' => 'api', 'changes' => array_keys($data)]);

    // Trigger webhook
    triggerWebhook('model.updated', [
        'model_id' => $id,
        'name' => $data['name'] ?? $model['name'],
        'changes' => array_keys($data)
    ]);

    // Return updated model
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $model = $stmt->fetch();

    apiResponse(['data' => formatModelForApi($model)]);
}

/**
 * Delete a model
 */
function deleteModel($id, $apiUser) {
    $db = getDB();

    // Verify model exists
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $model = $stmt->fetch();

    if (!$model) {
        apiError('Model not found', 404);
    }

    // Delete the file
    $filePath = UPLOAD_PATH . $model['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
        // Try to remove the folder if empty
        $folder = dirname($filePath);
        if (is_dir($folder) && count(scandir($folder)) === 2) {
            rmdir($folder);
        }
    }

    // Delete from database (cascades to model_categories, model_tags, etc.)
    $stmt = $db->prepare('DELETE FROM models WHERE id = :id1 OR parent_id = :id2');
    $stmt->execute([':id1' => $id, ':id2' => $id]);

    // Log activity
    logActivity('delete', 'model', $id, $model['name'], ['via' => 'api']);

    // Trigger webhook
    triggerWebhook('model.deleted', [
        'model_id' => $id,
        'name' => $model['name']
    ]);

    apiResponse(['success' => true, 'message' => 'Model deleted']);
}
