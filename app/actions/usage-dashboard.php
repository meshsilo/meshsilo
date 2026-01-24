<?php
/**
 * Usage Dashboard Actions
 * Show users their storage usage, activity, etc.
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'summary':
        getUsageSummary();
        break;
    case 'storage':
        getStorageBreakdown();
        break;
    case 'activity':
        getActivityStats();
        break;
    case 'trends':
        getUploadTrends();
        break;
    case 'all':
        getAllDashboardData();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function getUsageSummary() {
    global $user;
    $db = getDB();

    // User's model count
    $stmt = $db->prepare('SELECT COUNT(*) FROM models WHERE uploaded_by = :user_id AND parent_id IS NULL');
    $stmt->execute([':user_id' => $user['id']]);
    $modelCount = (int)$stmt->fetchColumn();

    // User's storage
    $stmt = $db->prepare('SELECT SUM(original_size) FROM models WHERE uploaded_by = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $storageUsed = (int)$stmt->fetchColumn();

    // User limits
    $storageLimit = (int)$user['storage_limit_mb'] * 1024 * 1024; // Convert MB to bytes
    $modelLimit = (int)$user['model_limit'];

    // Downloads of user's models
    $stmt = $db->prepare('SELECT SUM(download_count) FROM models WHERE uploaded_by = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $totalDownloads = (int)$stmt->fetchColumn();

    // User's favorites
    $stmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $favoriteCount = (int)$stmt->fetchColumn();

    // Print count
    $stmt = $db->prepare('SELECT COUNT(*) FROM print_history WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $user['id']]);
    $printCount = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'summary' => [
            'models' => [
                'count' => $modelCount,
                'limit' => $modelLimit ?: null,
                'percent' => $modelLimit ? round(($modelCount / $modelLimit) * 100, 1) : null
            ],
            'storage' => [
                'used_bytes' => $storageUsed,
                'used_formatted' => formatBytes($storageUsed),
                'limit_bytes' => $storageLimit ?: null,
                'limit_formatted' => $storageLimit ? formatBytes($storageLimit) : null,
                'percent' => $storageLimit ? round(($storageUsed / $storageLimit) * 100, 1) : null
            ],
            'downloads' => $totalDownloads,
            'favorites' => $favoriteCount,
            'prints' => $printCount
        ]
    ]);
}

function getStorageBreakdown() {
    global $user;
    $db = getDB();

    // By file type
    $stmt = $db->prepare('
        SELECT file_type, COUNT(*) as count, SUM(original_size) as total_size
        FROM models WHERE uploaded_by = :user_id AND parent_id IS NULL
        GROUP BY file_type ORDER BY total_size DESC
    ');
    $stmt->execute([':user_id' => $user['id']]);

    $byType = [];
    while ($row = $stmt->fetch()) {
        $byType[] = [
            'type' => $row['file_type'],
            'count' => (int)$row['count'],
            'size' => (int)$row['total_size'],
            'size_formatted' => formatBytes($row['total_size'])
        ];
    }

    // By category
    $stmt = $db->prepare('
        SELECT c.name, COUNT(DISTINCT m.id) as count, SUM(m.original_size) as total_size
        FROM models m
        JOIN model_categories mc ON m.id = mc.model_id
        JOIN categories c ON mc.category_id = c.id
        WHERE m.uploaded_by = :user_id AND m.parent_id IS NULL
        GROUP BY c.id ORDER BY total_size DESC
    ');
    $stmt->execute([':user_id' => $user['id']]);

    $byCategory = [];
    while ($row = $stmt->fetch()) {
        $byCategory[] = [
            'category' => $row['name'],
            'count' => (int)$row['count'],
            'size' => (int)$row['total_size'],
            'size_formatted' => formatBytes($row['total_size'])
        ];
    }

    // Largest files
    $stmt = $db->prepare('
        SELECT id, name, file_type, original_size
        FROM models WHERE uploaded_by = :user_id AND parent_id IS NULL
        ORDER BY original_size DESC LIMIT 10
    ');
    $stmt->execute([':user_id' => $user['id']]);

    $largestFiles = [];
    while ($row = $stmt->fetch()) {
        $largestFiles[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'type' => $row['file_type'],
            'size' => (int)$row['original_size'],
            'size_formatted' => formatBytes($row['original_size'])
        ];
    }

    echo json_encode([
        'success' => true,
        'breakdown' => [
            'by_type' => $byType,
            'by_category' => $byCategory,
            'largest_files' => $largestFiles
        ]
    ]);
}

function getActivityStats() {
    global $user;
    $db = getDB();
    $type = $db->getType();

    // Recent activity
    if ($type === 'mysql') {
        $stmt = $db->prepare('
            SELECT action, entity_type, entity_id, entity_name, created_at
            FROM activity_log WHERE user_id = :user_id
            ORDER BY created_at DESC LIMIT 20
        ');
    } else {
        $stmt = $db->prepare('
            SELECT action, entity_type, entity_id, entity_name, created_at
            FROM activity_log WHERE user_id = :user_id
            ORDER BY created_at DESC LIMIT 20
        ');
    }
    $stmt->execute([':user_id' => $user['id']]);

    $recentActivity = [];
    while ($row = $stmt->fetch()) {
        $recentActivity[] = $row;
    }

    // Activity by type (last 30 days)
    if ($type === 'mysql') {
        $stmt = $db->prepare('
            SELECT action, COUNT(*) as count
            FROM activity_log
            WHERE user_id = :user_id AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY action ORDER BY count DESC
        ');
    } else {
        $stmt = $db->prepare("
            SELECT action, COUNT(*) as count
            FROM activity_log
            WHERE user_id = :user_id AND created_at > datetime('now', '-30 days')
            GROUP BY action ORDER BY count DESC
        ");
    }
    $stmt->execute([':user_id' => $user['id']]);

    $activityByType = [];
    while ($row = $stmt->fetch()) {
        $activityByType[$row['action']] = (int)$row['count'];
    }

    echo json_encode([
        'success' => true,
        'activity' => [
            'recent' => $recentActivity,
            'by_type' => $activityByType
        ]
    ]);
}

function getUploadTrends() {
    global $user;
    $db = getDB();
    $type = $db->getType();

    // Uploads per day (last 30 days)
    if ($type === 'mysql') {
        $stmt = $db->prepare('
            SELECT DATE(created_at) as date, COUNT(*) as count, SUM(original_size) as total_size
            FROM models
            WHERE uploaded_by = :user_id AND parent_id IS NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ');
    } else {
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count, SUM(original_size) as total_size
            FROM models
            WHERE uploaded_by = :user_id AND parent_id IS NULL
            AND created_at > datetime('now', '-30 days')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
    }
    $stmt->execute([':user_id' => $user['id']]);

    $uploadsByDay = [];
    while ($row = $stmt->fetch()) {
        $uploadsByDay[] = [
            'date' => $row['date'],
            'count' => (int)$row['count'],
            'size' => (int)$row['total_size']
        ];
    }

    // Uploads per month (last 12 months)
    if ($type === 'mysql') {
        $stmt = $db->prepare('
            SELECT DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count, SUM(original_size) as total_size
            FROM models
            WHERE uploaded_by = :user_id AND parent_id IS NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, "%Y-%m")
            ORDER BY month ASC
        ');
    } else {
        $stmt = $db->prepare("
            SELECT strftime('%Y-%m', created_at) as month, COUNT(*) as count, SUM(original_size) as total_size
            FROM models
            WHERE uploaded_by = :user_id AND parent_id IS NULL
            AND created_at > datetime('now', '-12 months')
            GROUP BY strftime('%Y-%m', created_at)
            ORDER BY month ASC
        ");
    }
    $stmt->execute([':user_id' => $user['id']]);

    $uploadsByMonth = [];
    while ($row = $stmt->fetch()) {
        $uploadsByMonth[] = [
            'month' => $row['month'],
            'count' => (int)$row['count'],
            'size' => (int)$row['total_size']
        ];
    }

    echo json_encode([
        'success' => true,
        'trends' => [
            'daily' => $uploadsByDay,
            'monthly' => $uploadsByMonth
        ]
    ]);
}

function getAllDashboardData() {
    ob_start();

    // Capture summary
    getUsageSummary();
    $summary = json_decode(ob_get_clean(), true);

    ob_start();
    getStorageBreakdown();
    $storage = json_decode(ob_get_clean(), true);

    ob_start();
    getActivityStats();
    $activity = json_decode(ob_get_clean(), true);

    ob_start();
    getUploadTrends();
    $trends = json_decode(ob_get_clean(), true);

    echo json_encode([
        'success' => true,
        'summary' => $summary['summary'] ?? null,
        'storage' => $storage['breakdown'] ?? null,
        'activity' => $activity['activity'] ?? null,
        'trends' => $trends['trends'] ?? null
    ]);
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
