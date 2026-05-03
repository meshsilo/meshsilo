<?php
// Tag management helper functions
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
        $result = $db->query('SELECT id, name, color FROM tags ORDER BY name');
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

// Invalidate tags cache (call after adding/removing tags)
function invalidateTagsCache()
{
    Cache::getInstance()->forget('all_tags');
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
        $stmt = $db->prepare('SELECT id, name, color FROM tags WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute([':name' => trim($name)]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
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
