<?php
// Category helper functions
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

// Invalidate categories cache (call after modifying categories)
function invalidateCategoriesCache()
{
    Cache::getInstance()->forget('all_categories');
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
