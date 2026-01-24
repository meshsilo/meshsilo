<?php
/**
 * Stats API Routes
 *
 * GET /api/stats - Get system statistics
 */

function handleStatsRoute($method, $apiUser) {
    if ($method !== 'GET') {
        apiError('Method not allowed', 405);
    }

    requireApiPermission($apiUser, API_PERM_READ);

    $db = getDB();

    // Total models
    $stmt = $db->query('SELECT COUNT(*) FROM models WHERE parent_id IS NULL');
    $totalModels = (int)$stmt->fetchColumn();

    // Total parts
    $stmt = $db->query('SELECT COUNT(*) FROM models WHERE parent_id IS NOT NULL');
    $totalParts = (int)$stmt->fetchColumn();

    // Total storage
    $stmt = $db->query('SELECT SUM(original_size) FROM models');
    $totalStorage = (int)$stmt->fetchColumn();

    // Total downloads
    $stmt = $db->query('SELECT SUM(download_count) FROM models');
    $totalDownloads = (int)$stmt->fetchColumn();

    // Models by file type
    $result = $db->query('
        SELECT file_type, COUNT(*) as count
        FROM models
        WHERE parent_id IS NULL
        GROUP BY file_type
        ORDER BY count DESC
    ');
    $byFileType = [];
    while ($row = $result->fetch()) {
        $byFileType[$row['file_type'] ?? 'unknown'] = (int)$row['count'];
    }

    // Models by print type
    $result = $db->query('
        SELECT print_type, COUNT(*) as count
        FROM models
        WHERE parent_id IS NULL AND print_type IS NOT NULL
        GROUP BY print_type
        ORDER BY count DESC
    ');
    $byPrintType = [];
    while ($row = $result->fetch()) {
        $byPrintType[$row['print_type']] = (int)$row['count'];
    }

    // Models by category
    $result = $db->query('
        SELECT c.name, COUNT(mc.model_id) as count
        FROM categories c
        LEFT JOIN model_categories mc ON c.id = mc.category_id
        LEFT JOIN models m ON mc.model_id = m.id AND m.parent_id IS NULL
        GROUP BY c.id
        ORDER BY count DESC
    ');
    $byCategory = [];
    while ($row = $result->fetch()) {
        $byCategory[$row['name']] = (int)$row['count'];
    }

    // Recent uploads (last 30 days)
    $type = $db->getType();
    if ($type === 'mysql') {
        $stmt = $db->query('
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM models
            WHERE parent_id IS NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ');
    } else {
        $stmt = $db->query("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM models
            WHERE parent_id IS NULL
            AND created_at > datetime('now', '-30 days')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
    }
    $recentUploads = [];
    while ($row = $stmt->fetch()) {
        $recentUploads[] = [
            'date' => $row['date'],
            'count' => (int)$row['count']
        ];
    }

    // Printed vs unprinted
    $stmt = $db->query('SELECT COUNT(*) FROM models WHERE parent_id IS NULL AND is_printed = 1');
    $printedCount = (int)$stmt->fetchColumn();

    apiResponse([
        'data' => [
            'totals' => [
                'models' => $totalModels,
                'parts' => $totalParts,
                'storage_bytes' => $totalStorage,
                'storage_formatted' => formatBytes($totalStorage),
                'downloads' => $totalDownloads,
                'printed' => $printedCount,
                'unprinted' => $totalModels - $printedCount
            ],
            'by_file_type' => $byFileType,
            'by_print_type' => $byPrintType,
            'by_category' => $byCategory,
            'recent_uploads' => $recentUploads
        ]
    ]);
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
