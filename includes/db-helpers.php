<?php
// Application helper functions (auth, tags, favorites, activity, storage, etc.)
function getUserByLogin($login)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :login1 OR email = :login2');
        $result = $stmt->execute([':login1' => $login, ':login2' => $login]);
        return $result->fetch();
    } catch (Exception $e) {
        logException($e, ['action' => 'get_user_by_login', 'login' => $login]);
        return null;
    }
}

// Verify password
function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

// Check if a column exists in a table
function getAllTags($useCache = true)
{
    $cacheKey = 'all_tags';

    // Try to get from cache first
    if ($useCache) {
        $cached = Cache::getInstance()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM tags ORDER BY name');
        $tags = [];
        while ($row = $result->fetch()) {
            $tags[] = $row;
        }

        // Cache for 5 minutes
        Cache::getInstance()->set($cacheKey, $tags, 300);
        return $tags;
    } catch (Exception $e) {
        return [];
    }
}

// Get all categories with model counts (cached for 5 minutes)
function getAllCategories($useCache = true)
{
    $cacheKey = 'all_categories';

    // Try to get from cache first
    if ($useCache) {
        $cached = Cache::getInstance()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    try {
        $db = getDB();
        $result = $db->query('SELECT c.*, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
        $categories = [];
        while ($row = $result->fetch()) {
            $categories[] = $row;
        }

        // Cache for 5 minutes
        Cache::getInstance()->set($cacheKey, $categories, 300);
        return $categories;
    } catch (Exception $e) {
        return [];
    }
}

// Invalidate tags cache (call after adding/removing tags)
function invalidateTagsCache()
{
    Cache::getInstance()->forget('all_tags');
}

// Invalidate categories cache (call after modifying categories)
function invalidateCategoriesCache()
{
    Cache::getInstance()->forget('all_categories');
}

// Get tags for a model
function getModelTags($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT t.* FROM tags t
            JOIN model_tags mt ON t.id = mt.tag_id
            WHERE mt.model_id = :model_id
            ORDER BY t.name
        ');
        $stmt->execute([':model_id' => $modelId]);
        $tags = [];
        while ($row = $stmt->fetch()) {
            $tags[] = $row;
        }
        return $tags;
    } catch (Exception $e) {
        return [];
    }
}

// Get tags for multiple models in one query (optimized for N+1 prevention)
function getTagsForModels(array $modelIds)
{
    if (empty($modelIds)) {
        return [];
    }

    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $query = "
            SELECT mt.model_id, t.id, t.name, t.color
            FROM model_tags mt
            JOIN tags t ON mt.tag_id = t.id
            WHERE mt.model_id IN ($placeholders)
            ORDER BY t.name
        ";
        $stmt = $db->prepare($query);

        $index = 1;
        foreach ($modelIds as $id) {
            $stmt->bindValue($index++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Group tags by model_id
        $tagsByModel = [];
        while ($row = $stmt->fetch()) {
            $tagsByModel[$row['model_id']][] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'color' => $row['color']
            ];
        }

        return $tagsByModel;
    } catch (Exception $e) {
        return [];
    }
}

// Get first part for multiple parent models in one query (optimized for N+1 prevention)
function getFirstPartsForModels(array $modelIds)
{
    if (empty($modelIds)) {
        return [];
    }

    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));

        // Get first part for each parent (using subquery to get minimum id per parent)
        $query = "
            SELECT m.*
            FROM models m
            INNER JOIN (
                SELECT parent_id, MIN(id) as first_id
                FROM models
                WHERE parent_id IN ($placeholders)
                GROUP BY parent_id
            ) first ON m.id = first.first_id
            ORDER BY m.original_path
        ";
        $stmt = $db->prepare($query);

        $index = 1;
        foreach ($modelIds as $id) {
            $stmt->bindValue($index++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Group by parent_id
        $partsByParent = [];
        while ($row = $stmt->fetch()) {
            $partsByParent[$row['parent_id']] = $row;
        }

        return $partsByParent;
    } catch (Exception $e) {
        return [];
    }
}

// Get categories for a single model
function getCategoriesForModel($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT c.id, c.name
            FROM categories c
            JOIN model_categories mc ON c.id = mc.category_id
            WHERE mc.model_id = :model_id
            ORDER BY c.name
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Get categories for multiple models in batch (reduces N+1 queries)
function getCategoriesForModels(array $modelIds)
{
    if (empty($modelIds)) {
        return [];
    }

    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $query = "
            SELECT mc.model_id, c.id, c.name
            FROM model_categories mc
            JOIN categories c ON mc.category_id = c.id
            WHERE mc.model_id IN ($placeholders)
            ORDER BY c.name
        ";
        $stmt = $db->prepare($query);

        $index = 1;
        foreach ($modelIds as $id) {
            $stmt->bindValue($index++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Group categories by model_id
        $categoriesByModel = [];
        while ($row = $stmt->fetch()) {
            $categoriesByModel[$row['model_id']][] = [
                'id' => $row['id'],
                'name' => $row['name']
            ];
        }

        return $categoriesByModel;
    } catch (Exception $e) {
        return [];
    }
}

// Get absolute file path for a model/part
function getModelFilePath($model)
{
    $basePath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../storage/assets/';
    $filePath = $model['dedup_path'] ?? $model['file_path'] ?? '';
    if (empty($filePath)) {
        return null;
    }
    // Handle both relative and absolute paths
    if (strpos($filePath, '/') === 0 || strpos($filePath, ':\\') !== false) {
        return $filePath;
    }
    return rtrim($basePath, '/') . '/' . ltrim($filePath, '/');
}

// Add tag to model
function addTagToModel($modelId, $tagId)
{
    try {
        $db = getDB();
        $type = $db->getType();
        $insertIgnore = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $stmt = $db->prepare("$insertIgnore INTO model_tags (model_id, tag_id) VALUES (:model_id, :tag_id)");
        $stmt->execute([':model_id' => $modelId, ':tag_id' => $tagId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Remove tag from model
function removeTagFromModel($modelId, $tagId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM model_tags WHERE model_id = :model_id AND tag_id = :tag_id');
        $stmt->execute([':model_id' => $modelId, ':tag_id' => $tagId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Create a new tag
function createTag($name, $color = '#6366f1')
{
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO tags (name, color) VALUES (:name, :color)');
        $stmt->execute([':name' => trim($name), ':color' => $color]);
        invalidateTagsCache();
        return $db->lastInsertId();
    } catch (Exception $e) {
        return false;
    }
}

// Delete a tag
function deleteTag($tagId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute([':id' => $tagId]);
        invalidateTagsCache();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get tag by name (case insensitive)
function getTagByName($name)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM tags WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute([':name' => trim($name)]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

// Get or create tag by name
function getOrCreateTag($name, $color = '#6366f1')
{
    $tag = getTagByName($name);
    if ($tag) {
        return $tag['id'];
    }
    return createTag($name, $color);
}

// =====================
// Favorites Functions
// =====================

// Check if model is favorited by user
function isModelFavorited($modelId, $userId = null)
{
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) {
        return false;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM favorites WHERE model_id = :model_id AND user_id = :user_id');
        $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Toggle favorite status
function toggleFavorite($modelId, $userId = null)
{
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) {
        return ['success' => false, 'error' => 'Not logged in'];
    }

    try {
        $db = getDB();

        if (isModelFavorited($modelId, $userId)) {
            $stmt = $db->prepare('DELETE FROM favorites WHERE model_id = :model_id AND user_id = :user_id');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
            return ['success' => true, 'favorited' => false];
        } else {
            $stmt = $db->prepare('INSERT INTO favorites (model_id, user_id) VALUES (:model_id, :user_id)');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
            return ['success' => true, 'favorited' => true];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Get user's favorites
function getUserFavorites($userId = null, $limit = 50)
{
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) {
        return [];
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT m.* FROM models m
            JOIN favorites f ON m.id = f.model_id
            WHERE f.user_id = :user_id AND m.parent_id IS NULL
            ORDER BY f.created_at DESC
            LIMIT :limit
        ');
        $stmt->execute([':user_id' => $userId, ':limit' => $limit]);
        $favorites = [];
        while ($row = $stmt->fetch()) {
            $favorites[] = $row;
        }
        return $favorites;
    } catch (Exception $e) {
        return [];
    }
}

// Get favorite count for a model
function getModelFavoriteCount($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $modelId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// =====================
// Activity Log Functions
// =====================

// Log an activity
function logActivity($action, $entityType, $entityId = null, $entityName = null, $details = null)
{
    if (getSetting('enable_activity_log', '1') !== '1') {
        return true;
    }

    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $db->prepare('
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, entity_name, details, ip_address)
            VALUES (:user_id, :action, :entity_type, :entity_id, :entity_name, :details, :ip_address)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':entity_name' => $entityName,
            ':details' => is_array($details) ? json_encode($details) : $details,
            ':ip_address' => $ipAddress
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get activity log entries
function getActivityLog($limit = 50, $offset = 0, $filters = [])
{
    try {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params[':entity_type'] = $filters['entity_type'];
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT al.*, u.username
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);

        $activities = [];
        while ($row = $stmt->fetch()) {
            $activities[] = $row;
        }
        return $activities;
    } catch (Exception $e) {
        return [];
    }
}

// Clean old activity log entries
function cleanActivityLog()
{
    $retentionDays = (int)getSetting('activity_log_retention_days', '90');
    if ($retentionDays <= 0) {
        return true;
    }

    try {
        $db = getDB();
        $type = $db->getType();

        if ($type === 'mysql') {
            $stmt = $db->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        } else {
            $stmt = $db->prepare("DELETE FROM activity_log WHERE created_at < datetime('now', '-' || :days || ' days')");
        }
        $stmt->execute([':days' => $retentionDays]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =====================
// Recently Viewed Functions
// =====================

// Record a model view
function recordModelView($modelId)
{
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $sessionId = session_id();

        // Delete existing entry for this model (user or session based)
        if ($userId) {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE model_id = :model_id AND user_id = :user_id');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
        } else {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE model_id = :model_id AND session_id = :session_id');
            $stmt->execute([':model_id' => $modelId, ':session_id' => $sessionId]);
        }

        // Insert new entry
        $stmt = $db->prepare('
            INSERT INTO recently_viewed (user_id, session_id, model_id)
            VALUES (:user_id, :session_id, :model_id)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionId,
            ':model_id' => $modelId
        ]);

        // Limit to 50 most recent per user/session using parameterized queries
        if ($userId) {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE user_id = :user_id AND id NOT IN (SELECT id FROM recently_viewed WHERE user_id = :user_id2 ORDER BY viewed_at DESC LIMIT 50)');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE session_id = :session_id AND id NOT IN (SELECT id FROM recently_viewed WHERE session_id = :session_id2 ORDER BY viewed_at DESC LIMIT 50)');
            $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
            $stmt->bindValue(':session_id2', $sessionId, PDO::PARAM_STR);
            $stmt->execute();
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get recently viewed models
function getRecentlyViewed($limit = 10)
{
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $sessionId = session_id();

        if ($userId) {
            $stmt = $db->prepare('
                SELECT m.* FROM models m
                JOIN recently_viewed rv ON m.id = rv.model_id
                WHERE rv.user_id = :user_id AND m.parent_id IS NULL
                ORDER BY rv.viewed_at DESC
                LIMIT :limit
            ');
            $stmt->execute([':user_id' => $userId, ':limit' => $limit]);
        } else {
            $stmt = $db->prepare('
                SELECT m.* FROM models m
                JOIN recently_viewed rv ON m.id = rv.model_id
                WHERE rv.session_id = :session_id AND m.parent_id IS NULL
                ORDER BY rv.viewed_at DESC
                LIMIT :limit
            ');
            $stmt->execute([':session_id' => $sessionId, ':limit' => $limit]);
        }

        $models = [];
        while ($row = $stmt->fetch()) {
            $models[] = $row;
        }
        return $models;
    } catch (Exception $e) {
        return [];
    }
}

// =====================
// Download Count Functions
// =====================

// Increment download count
function incrementDownloadCount($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET download_count = download_count + 1 WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get download count
function getDownloadCount($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT download_count FROM models WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['download_count'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// =====================
// License Constants
// =====================

function getLicenseOptions()
{
    return [
        '' => 'No License Specified',
        'cc0' => 'CC0 (Public Domain)',
        'cc-by' => 'CC BY (Attribution)',
        'cc-by-sa' => 'CC BY-SA (Attribution-ShareAlike)',
        'cc-by-nc' => 'CC BY-NC (Attribution-NonCommercial)',
        'cc-by-nc-sa' => 'CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)',
        'cc-by-nd' => 'CC BY-ND (Attribution-NoDerivatives)',
        'cc-by-nc-nd' => 'CC BY-NC-ND (Attribution-NonCommercial-NoDerivatives)',
        'mit' => 'MIT License',
        'gpl' => 'GPL (GNU General Public License)',
        'proprietary' => 'Proprietary / All Rights Reserved',
        'other' => 'Other'
    ];
}

function getLicenseName($key)
{
    $options = getLicenseOptions();
    return $options[$key] ?? $key;
}

// =====================
// Related Models Functions
// =====================

function getRelatedModels($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT rm.*, m.name, m.file_path, m.print_type, m.created_at
            FROM related_models rm
            JOIN models m ON rm.related_model_id = m.id
            WHERE rm.model_id = :model_id AND m.parent_id IS NULL
            ORDER BY rm.created_at DESC
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function addRelatedModel($modelId, $relatedModelId, $relationshipType = 'related')
{
    if ($modelId == $relatedModelId) {
        return false;
    }
    try {
        $db = getDB();
        // Add relation both ways
        $stmt = $db->prepare('INSERT OR IGNORE INTO related_models (model_id, related_model_id, relationship_type) VALUES (:model_id, :related_id, :type)');
        $stmt->execute([':model_id' => $modelId, ':related_id' => $relatedModelId, ':type' => $relationshipType]);
        $stmt->execute([':model_id' => $relatedModelId, ':related_id' => $modelId, ':type' => $relationshipType]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removeRelatedModel($modelId, $relatedModelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM related_models WHERE (model_id = :model_id1 AND related_model_id = :related_id1) OR (model_id = :related_id2 AND related_model_id = :model_id2)');
        $stmt->execute([':model_id1' => $modelId, ':related_id1' => $relatedModelId, ':related_id2' => $relatedModelId, ':model_id2' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =====================
// Version History Functions
// =====================

function getModelVersions($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT mv.*, u.username as created_by_name
            FROM model_versions mv
            LEFT JOIN users u ON mv.created_by = u.id
            WHERE mv.model_id = :model_id
            ORDER BY mv.version_number DESC
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function addModelVersion($modelId, $filePath, $fileSize, $fileHash, $changelog = '', $createdBy = null)
{
    try {
        $db = getDB();
        // Get current max version
        $stmt = $db->prepare('SELECT MAX(version_number) as max_ver FROM model_versions WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $modelId]);
        $row = $stmt->fetch();
        $nextVersion = ($row && $row['max_ver']) ? $row['max_ver'] + 1 : 1;

        $stmt = $db->prepare('
            INSERT INTO model_versions (model_id, version_number, file_path, file_size, file_hash, changelog, created_by)
            VALUES (:model_id, :version, :file_path, :file_size, :file_hash, :changelog, :created_by)
        ');
        $stmt->execute([
            ':model_id' => $modelId,
            ':version' => $nextVersion,
            ':file_path' => $filePath,
            ':file_size' => $fileSize,
            ':file_hash' => $fileHash,
            ':changelog' => $changelog,
            ':created_by' => $createdBy
        ]);

        // Update model's current version
        $stmt = $db->prepare('UPDATE models SET current_version = :version WHERE id = :id');
        $stmt->execute([':version' => $nextVersion, ':id' => $modelId]);

        return $nextVersion;
    } catch (Exception $e) {
        return false;
    }
}

function getModelVersion($modelId, $versionNumber)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM model_versions WHERE model_id = :model_id AND version_number = :version');
        $stmt->execute([':model_id' => $modelId, ':version' => $versionNumber]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// =====================
// Storage Usage Functions
// =====================

function getStorageUsageByCategory()
{
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT c.id, c.name,
                   COUNT(m.id) as model_count,
                   SUM(m.original_size) as total_size
            FROM categories c
            LEFT JOIN models m ON m.category_id = c.id AND m.parent_id IS NULL
            GROUP BY c.id, c.name
            ORDER BY total_size DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function getStorageUsageByUser()
{
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT u.id, u.username,
                   COUNT(m.id) as model_count,
                   SUM(m.original_size) as total_size
            FROM users u
            LEFT JOIN models m ON m.user_id = u.id AND m.parent_id IS NULL
            GROUP BY u.id, u.username
            ORDER BY total_size DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function getTotalStorageUsage()
{
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT COUNT(*) as model_count,
                   SUM(original_size) as total_size
            FROM models
            WHERE parent_id IS NULL
        ');
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['model_count' => 0, 'total_size' => 0];
    }
}

function getDedupStorageSavings()
{
    try {
        $db = getDB();

        // Total file_size of all dedup'd parts (what storage would be without dedup)
        $stmt = $db->query('SELECT SUM(file_size) as total FROM models WHERE dedup_path IS NOT NULL');
        $totalRow = $stmt->fetch();
        $totalSize = $totalRow ? (int)$totalRow['total'] : 0;

        if ($totalSize === 0) {
            return ['total_size' => 0, 'actual_size' => 0, 'saved_size' => 0, 'saved_percent' => 0];
        }

        // Actual disk usage: count each unique dedup_path only once
        $stmt = $db->query('
            SELECT SUM(size) as actual FROM (
                SELECT dedup_path, MAX(file_size) as size
                FROM models
                WHERE dedup_path IS NOT NULL
                GROUP BY dedup_path
            )
        ');
        $actualRow = $stmt->fetch();
        $actualSize = $actualRow ? (int)$actualRow['actual'] : 0;

        return [
            'total_size' => $totalSize,
            'actual_size' => $actualSize,
            'saved_size' => $totalSize - $actualSize,
            'saved_percent' => $totalSize > 0 ? round(($totalSize - $actualSize) / $totalSize * 100, 1) : 0
        ];
    } catch (Exception $e) {
        return ['total_size' => 0, 'actual_size' => 0, 'saved_size' => 0, 'saved_percent' => 0];
    }
}

// =====================
// Part Ordering Functions
// =====================

function updatePartOrder($partId, $sortOrder)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET sort_order = :sort_order WHERE id = :id');
        $stmt->execute([':sort_order' => $sortOrder, ':id' => $partId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function reorderParts($parentId, $partIds)
{
    try {
        $db = getDB();
        $db->beginTransaction();
        foreach ($partIds as $index => $partId) {
            $stmt = $db->prepare('UPDATE models SET sort_order = :sort_order WHERE id = :id AND parent_id = :parent_id');
            $stmt->execute([':sort_order' => $index, ':id' => $partId, ':parent_id' => $parentId]);
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollBack();
        }
        return false;
    }
}

// =====================
// Model Dimensions Functions
// =====================

function updateModelDimensions($modelId, $dimX, $dimY, $dimZ, $unit = 'mm')
{
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET dim_x = :x, dim_y = :y, dim_z = :z, dim_unit = :unit WHERE id = :id');
        $stmt->execute([':x' => $dimX, ':y' => $dimY, ':z' => $dimZ, ':unit' => $unit, ':id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getModelDimensions($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT dim_x, dim_y, dim_z, dim_unit FROM models WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['dim_x'] !== null) {
            return $row;
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// =====================
// Webhook Functions
// =====================

/**
 * Trigger webhook for an event (delegated to plugins via filter)
 */
function triggerWebhook($event, $payload)
{
    if (class_exists('PluginManager')) {
        PluginManager::applyFilter('trigger_webhook', null, $event, $payload);
    }
}

// =====================
// Batch Operations
// =====================

/**
 * Batch insert multiple rows into a table
 * @param string $table Table name
 * @param array $columns Column names
 * @param array $rows Array of row data arrays
 * @param int $chunkSize Number of rows per insert (default 100)
 * @return int Number of rows inserted
 */
function batchInsert(string $table, array $columns, array $rows, int $chunkSize = 100): int
{
    if (empty($rows) || empty($columns)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();
    $inserted = 0;

    // Build column list
    $columnList = implode(', ', array_map(function ($col) use ($type) {
        return $type === 'mysql' ? "`$col`" : "\"$col\"";
    }, $columns));

    // Process in chunks to avoid memory issues
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = "INSERT INTO $table ($columnList) VALUES $placeholders";
        $stmt = $db->prepare($sql);

        // Flatten values array
        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt->execute($values);
        $inserted += count($chunk);
    }

    return $inserted;
}

/**
 * Batch insert with IGNORE (skip duplicates)
 */
function batchInsertIgnore(string $table, array $columns, array $rows, int $chunkSize = 100): int
{
    if (empty($rows) || empty($columns)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();
    $inserted = 0;

    $columnList = implode(', ', array_map(function ($col) use ($type) {
        return $type === 'mysql' ? "`$col`" : "\"$col\"";
    }, $columns));

    $insertKeyword = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = "$insertKeyword INTO $table ($columnList) VALUES $placeholders";
        $stmt = $db->prepare($sql);

        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt->execute($values);
        $inserted += $stmt->rowCount();
    }

    return $inserted;
}

/**
 * Batch update using CASE statements (more efficient than individual updates)
 * @param string $table Table name
 * @param string $idColumn Primary key column
 * @param string $updateColumn Column to update
 * @param array $updates Array of [id => value] pairs
 * @return int Number of rows affected
 */
function batchUpdate(string $table, string $idColumn, string $updateColumn, array $updates): int
{
    if (empty($updates)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();

    $ids = array_keys($updates);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    // Build CASE statement
    $caseStmt = "CASE $idColumn ";
    $params = [];
    foreach ($updates as $id => $value) {
        $caseStmt .= "WHEN ? THEN ? ";
        $params[] = $id;
        $params[] = $value;
    }
    $caseStmt .= "END";

    // Add IDs for WHERE clause
    foreach ($ids as $id) {
        $params[] = $id;
    }

    $sql = "UPDATE $table SET $updateColumn = $caseStmt WHERE $idColumn IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}
