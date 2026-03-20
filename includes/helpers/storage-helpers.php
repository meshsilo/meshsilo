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
