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

    // Search filter (uses FTS when available for ranked results)
    if (!empty($_GET['q'])) {
        $dbType = $db->getType();
        $ftsAvailable = ($dbType === 'sqlite' && tableExists($db, 'models_fts'));

        if ($ftsAvailable) {
            $ftsWords = preg_split('/\s+/', trim($_GET['q']), -1, PREG_SPLIT_NO_EMPTY);
            $ftsWords = array_values(array_filter(array_map(fn($w) => preg_replace('/[^\w\x80-\xff]/u', '', $w), $ftsWords)));
            if (!empty($ftsWords)) {
                $ftsQuery = implode(' ', array_map(fn($w) => $w . '*', $ftsWords));
                $where[] = 'm.id IN (SELECT rowid FROM models_fts WHERE models_fts MATCH :fts_query)';
                $params[':fts_query'] = $ftsQuery;
            } else {
                $searchTerm = '%' . $_GET['q'] . '%';
                $where[] = '(m.name LIKE :search1 OR m.description LIKE :search2 OR m.creator LIKE :search3)';
                $params[':search1'] = $searchTerm;
                $params[':search2'] = $searchTerm;
                $params[':search3'] = $searchTerm;
            }
        } else {
            $searchTerm = '%' . $_GET['q'] . '%';
            $where[] = '(m.name LIKE :search1 OR m.description LIKE :search2 OR m.creator LIKE :search3)';
            $params[':search1'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
        }
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
                // Null-check, not truthiness: a legitimate 0.0 dimension must
                // survive rather than being coerced to null.
                'x' => (isset($row['dim_x']) && $row['dim_x'] !== '') ? (float)$row['dim_x'] : null,
                'y' => (isset($row['dim_y']) && $row['dim_y'] !== '') ? (float)$row['dim_y'] : null,
                'z' => (isset($row['dim_z']) && $row['dim_z'] !== '') ? (float)$row['dim_z'] : null,
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

    // Models are shared: a read-scoped key may download any model, consistent
    // with listModels/getModel (which already return all models) and the web
    // download path. Downloads are not owner-gated.

    // Resolve through the dedup layer: once the dedup job relocates a file,
    // file_path alone no longer exists on disk (the web download already
    // resolves via getAbsoluteFilePath; the API must match).
    require_once __DIR__ . '/../../../includes/dedup.php';
    $filePath = getAbsoluteFilePath($model);
    if (!$filePath || !file_exists($filePath)) {
        apiError('File not found', 404);
    }

    // Increment download count
    incrementDownloadCount($id);

    // Log activity
    logActivity('download', 'model', $id, $model['name'], ['via' => 'api']);

    // Sanitize filename for Content-Disposition header
    $safeFilename = basename($model['filename']);
    $safeFilename = str_replace(["\r", "\n", "\t", '"', '\\'], '', $safeFilename);

    // Send file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($filePath));
    if (getenv('MESHSILO_DOCKER') === 'true' && defined('UPLOAD_PATH')) {
        $relativePath = str_replace(realpath(UPLOAD_PATH), '', realpath($filePath));
        header('X-Accel-Redirect: /internal-assets' . $relativePath);
    } else {
        readfile($filePath);
    }
    exit;
}

/**
 * Only allow http/https or relative source URLs to be stored - blocks
 * javascript:, data:, and other dangerous schemes from being persisted and
 * later rendered as a link. Mirrors tusSanitizeSourceUrl() in actions/tus.php
 * so every write path (browser and REST) applies the same allow-list.
 * Anything with a non-http(s) scheme becomes ''.
 */
if (!function_exists('apiSanitizeSourceUrl')) {
    function apiSanitizeSourceUrl($url): string {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        // Reject control-character obfuscation (e.g. "java\tscript:"): browsers
        // strip these before resolving the scheme, so they'd sneak past the
        // check below as a "relative" URL.
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '';
        }
        // Has an explicit scheme? Only http/https are permitted.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url)) {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            return in_array($scheme, ['http', 'https'], true) ? $url : '';
        }
        // No scheme -> relative URL, allowed as-is.
        return $url;
    }
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
    // Reject non-http(s) schemes (javascript:, data:, ...) to close a stored-XSS
    // gap; matches the browser upload paths.
    $sourceUrl = apiSanitizeSourceUrl($_POST['source_url'] ?? '');
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

    // Sanitize filename: strip directory components, then remove unsafe characters
    $filename = basename($filename); // Strip directory components
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename); // Remove unsafe characters

    // Move uploaded file
    $destPath = $folderPath . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        rmdir($folderPath);
        apiError('Failed to save uploaded file', 500);
    }

    // Calculate file hash
    $fileHash = hash_file('sha256', $destPath);

    // Insert into database. The file has already been moved into place, so wrap
    // the insert plus the category/tag writes in a transaction and, on any DB
    // failure, roll back AND remove the moved file and its folder - otherwise a
    // failed insert would leak an orphaned upload on disk.
    $db = getDB();
    $pdo = $db->getPDO();
    $inTransaction = false;
    try {
        $pdo->beginTransaction();
        $inTransaction = true;

        $stmt = $db->prepare('
            INSERT INTO models (name, filename, file_path, file_size, file_type, description,
                               creator, collection, source_url, license, print_type, file_hash,
                               original_size, user_id, created_at, updated_at)
            VALUES (:name, :filename, :file_path, :file_size, :file_type, :description,
                    :creator, :collection, :source_url, :license, :print_type, :file_hash,
                    :original_size, :user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            ':name' => $name,
            ':filename' => $filename,
            // Canonical convention: file_path carries the 'assets/' prefix
            // (matches UploadProcessor and getAbsoluteFilePath resolution)
            ':file_path' => 'assets/' . $folderId . '/' . $filename,
            ':file_size' => $file['size'],
            ':file_type' => $extension,
            ':description' => $description,
            ':creator' => $creator,
            ':collection' => $collection,
            ':source_url' => $sourceUrl,
            ':license' => $license,
            ':print_type' => $printType,
            ':file_hash' => $fileHash,
            ':original_size' => $file['size'],
            ':user_id' => $apiUser['user_id']
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

        $pdo->commit();
        $inTransaction = false;
    } catch (Throwable $e) {
        if ($inTransaction) {
            $pdo->rollBack();
        }
        // Clean up the moved file and folder so a DB failure leaves nothing behind.
        if (is_file($destPath)) {
            unlink($destPath);
        }
        if (is_dir($folderPath)) {
            @rmdir($folderPath);
        }
        if (function_exists('logException')) {
            logException($e, ['action' => 'api_create_model']);
        }
        apiError('Failed to create model', 500);
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

    // Verify ownership - must own the model or be admin
    if (!$apiUser['is_admin'] && !empty($model['user_id']) && $model['user_id'] != $apiUser['user_id']) {
        apiError('Not authorized to modify this model', 403);
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
            $value = $data[$field];
            // Reject non-http(s) schemes on source_url (stored-XSS guard), same
            // as the browser write paths and createModel.
            if ($field === 'source_url') {
                $value = apiSanitizeSourceUrl($value);
            }
            $updates[] = "$field = :$field";
            $params[":$field"] = $value;
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

    // Verify ownership - must own the model or be admin
    if (!$apiUser['is_admin'] && !empty($model['user_id']) && $model['user_id'] != $apiUser['user_id']) {
        apiError('Not authorized to delete this model', 403);
    }

    // Resolve model files through the same shared cleanup helpers the web delete
    // page uses (includes/helpers/model-delete.php) so files, thumbnails, version
    // files, attachments, and deduplicated files are all removed correctly.
    // (The old UPLOAD_PATH . file_path build doubled the assets/ prefix and never
    // deleted anything, orphaning files on disk.)
    require_once __DIR__ . '/../../../includes/dedup.php';

    $filesToDelete = [];
    $dedupFilesToCheck = [];
    $thumbnailsToDelete = [];

    // The model's own thumbnail
    if (!empty($model['thumbnail_path'])) {
        $thumbnailsToDelete[] = $model['thumbnail_path'];
    }

    // Collect child part files, thumbnails, and version files before deletion
    $childStmt = $db->prepare('SELECT id, file_path, dedup_path, thumbnail_path FROM models WHERE parent_id = :parent_id');
    $childStmt->bindValue(':parent_id', $id, PDO::PARAM_INT);
    $childStmt->execute();
    $childIds = [];
    while ($row = $childStmt->fetch(PDO::FETCH_ASSOC)) {
        $childIds[] = (int)$row['id'];
        if (!empty($row['thumbnail_path'])) {
            $thumbnailsToDelete[] = $row['thumbnail_path'];
        }
        if (!empty($row['dedup_path'])) {
            $dedupFilesToCheck[$row['dedup_path']] = true;
        } elseif (!empty($row['file_path'])) {
            $filesToDelete[] = getAbsoluteFilePath($row);
        }
    }

    // Collect the model's own file
    if (!empty($model['dedup_path'])) {
        $dedupFilesToCheck[$model['dedup_path']] = true;
    } elseif (!empty($model['file_path'])) {
        $filesToDelete[] = getAbsoluteFilePath($model);
    }

    // Delete physical version files for the model and each part while the
    // model_versions rows still exist (cascade removes the rows on delete).
    deleteModelVersionFiles($db, $id);
    foreach ($childIds as $childId) {
        deleteModelVersionFiles($db, $childId);
    }

    // Delete attachment files (images/PDFs) and their rows
    try {
        $attStmt = $db->prepare('SELECT file_path FROM model_attachments WHERE model_id = :model_id');
        $attStmt->bindValue(':model_id', $id, PDO::PARAM_INT);
        $attStmt->execute();
        while ($att = $attStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($att['file_path'])) {
                safeUnlinkAssetFile($att['file_path']);
            }
        }
        $delAtt = $db->prepare('DELETE FROM model_attachments WHERE model_id = :model_id');
        $delAtt->bindValue(':model_id', $id, PDO::PARAM_INT);
        $delAtt->execute();
    } catch (Throwable $e) {
        // model_attachments table may not exist yet - continue with deletion
    }

    // Delete from database (cascades to model_categories, model_tags, model_versions, etc.)
    $stmt = $db->prepare('DELETE FROM models WHERE id = :id1 OR parent_id = :id2');
    $stmt->execute([':id1' => $id, ':id2' => $id]);

    // Remove the collected model/part files and empty folders
    cleanupModelFiles($filesToDelete, $dedupFilesToCheck);

    // Remove thumbnail files (never deduplicated)
    foreach ($thumbnailsToDelete as $thumbPath) {
        safeUnlinkAssetFile($thumbPath);
    }

    // Log activity
    logActivity('delete', 'model', $id, $model['name'], ['via' => 'api']);

    // Trigger webhook
    triggerWebhook('model.deleted', [
        'model_id' => $id,
        'name' => $model['name']
    ]);

    apiResponse(['success' => true, 'message' => 'Model deleted']);
}
