<?php
// Storage, file path, and download helper functions
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
        logException($e, ['fn' => __FUNCTION__]);
        return [];
    }
}

/**
 * Attach viewer preview fields (preview_path, preview_type, preview_file_size)
 * to a list of models, in place.
 *
 * A multi-part model previews its first part; a single-file model previews
 * itself. Every multi-part model in the list is resolved with ONE batched query
 * via getFirstPartsForModels(), so this never degrades into a per-model query.
 *
 * Shared by the homepage, category, collection and favorites listings, which
 * each previously carried their own copy of this loop.
 *
 * @param array $models Rows with at least id, file_type, part_count. Modified in place.
 */
function attachPreviewData(array &$models)
{
    if (empty($models)) {
        return;
    }

    $multiPartIds = [];
    foreach ($models as $model) {
        if (!empty($model['part_count']) && isset($model['id'])) {
            $multiPartIds[] = $model['id'];
        }
    }

    $firstParts = $multiPartIds ? getFirstPartsForModels($multiPartIds) : [];

    foreach ($models as &$model) {
        $part = (!empty($model['part_count']) && isset($firstParts[$model['id']]))
            ? $firstParts[$model['id']]
            : null;

        if ($part) {
            $model['preview_path'] = '/preview?id=' . $part['id'];
            $model['preview_type'] = $part['file_type'];
            $model['preview_file_size'] = $part['file_size'] ?? 0;
        } elseif (empty($model['part_count'])) {
            // Single-file model previews itself. A multi-part model whose parts
            // could not be resolved is left without preview fields so the card
            // falls back to its thumbnail rather than pointing at a bad id.
            $model['preview_path'] = '/preview?id=' . $model['id'];
            $model['preview_type'] = $model['file_type'] ?? null;
            $model['preview_file_size'] = $model['file_size'] ?? 0;
        }
    }
    unset($model);
}

// Get absolute file path for a model/part
// Delegates to getAbsoluteFilePath (dedup.php) when available for consistent path resolution
function getModelFilePath($model)
{
    // Prefer the canonical path resolver if available (handles assets/ prefix correctly)
    if (function_exists('getAbsoluteFilePath')) {
        return getAbsoluteFilePath($model);
    }
    $basePath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../../storage/assets/';
    $filePath = $model['dedup_path'] ?? $model['file_path'] ?? '';
    if (empty($filePath)) {
        return null;
    }
    // Handle both relative and absolute paths
    if (strpos($filePath, '/') === 0 || strpos($filePath, ':\\') !== false) {
        return $filePath;
    }
    // Strip assets/ prefix if present — UPLOAD_PATH already points to storage/assets/
    if (strpos($filePath, 'assets/') === 0) {
        $filePath = substr($filePath, 7);
    }
    return rtrim($basePath, '/') . '/' . ltrim($filePath, '/');
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
        logException($e, ['fn' => __FUNCTION__]);
        return false;
    }
}

// =====================
// Storage Usage Functions
// =====================

function getStorageUsageByCategory()
{
    try {
        $db = getDB();
        // Pre-compute each parent model's total size (itself + parts) to avoid
        // double-counting when a model belongs to multiple categories.
        $stmt = $db->query('
            SELECT c.id, c.name,
                   COUNT(DISTINCT ms.model_id) as model_count,
                   COALESCE(SUM(ms.total_size), 0) as total_size
            FROM categories c
            LEFT JOIN model_categories mc ON mc.category_id = c.id
            LEFT JOIN (
                SELECT m.id as model_id,
                       COALESCE(SUM(COALESCE(all_models.file_size, 0)), 0) as total_size
                FROM models m
                LEFT JOIN models all_models ON all_models.id = m.id OR all_models.parent_id = m.id
                WHERE m.parent_id IS NULL
                GROUP BY m.id
            ) ms ON ms.model_id = mc.model_id
            GROUP BY c.id, c.name
            ORDER BY total_size DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        logException($e, ['fn' => __FUNCTION__]);
        return [];
    }
}

function getTotalStorageUsage()
{
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT
                (SELECT COUNT(*) FROM models WHERE parent_id IS NULL) as model_count,
                COALESCE((SELECT SUM(COALESCE(file_size, 0)) FROM models), 0) as total_size
        ');
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logException($e, ['fn' => __FUNCTION__]);
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
        logException($e, ['fn' => __FUNCTION__]);
        return ['total_size' => 0, 'actual_size' => 0, 'saved_size' => 0, 'saved_percent' => 0];
    }
}
