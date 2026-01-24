<?php
/**
 * Health Check Endpoint
 *
 * Returns system health status for monitoring tools, load balancers,
 * and container orchestration systems.
 *
 * Responses:
 * - 200 OK: System is healthy
 * - 503 Service Unavailable: System has issues
 *
 * Usage:
 * - Simple check: GET /health
 * - Detailed check: GET /health?detailed=1 (requires API key or admin)
 */

// Quick response for simple health checks
if (!isset($_GET['detailed'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
    exit;
}

// Detailed check requires authentication
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Check if authorized for detailed view
$authorized = false;

// Check API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if (!empty($apiKey)) {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM api_keys WHERE api_key = :key AND is_active = 1');
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $authorized = $result->fetchArray() !== false;
}

// Check admin session
if (!$authorized && function_exists('isAdmin') && isAdmin()) {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required for detailed health check']);
    exit;
}

// Perform detailed health checks
$health = [
    'status' => 'ok',
    'timestamp' => time(),
    'checks' => []
];

$allHealthy = true;

// Database check
$dbStart = microtime(true);
try {
    $db = getDB();
    $result = $db->query('SELECT 1');
    $dbHealthy = $result !== false;
    $dbTime = round((microtime(true) - $dbStart) * 1000, 2);

    $health['checks']['database'] = [
        'status' => $dbHealthy ? 'ok' : 'error',
        'response_time_ms' => $dbTime,
        'type' => defined('DB_TYPE') ? DB_TYPE : 'unknown'
    ];

    if (!$dbHealthy) $allHealthy = false;
} catch (Exception $e) {
    $health['checks']['database'] = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
    $allHealthy = false;
}

// Storage check
$uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/storage/assets/';
$storageWritable = is_writable($uploadPath);
$storageFree = @disk_free_space($uploadPath);
$storageTotal = @disk_total_space($uploadPath);

$health['checks']['storage'] = [
    'status' => $storageWritable ? 'ok' : 'error',
    'writable' => $storageWritable,
    'path' => $uploadPath
];

if ($storageFree !== false && $storageTotal !== false) {
    $health['checks']['storage']['free_bytes'] = $storageFree;
    $health['checks']['storage']['total_bytes'] = $storageTotal;
    $health['checks']['storage']['free_percent'] = round(($storageFree / $storageTotal) * 100, 1);

    // Warn if less than 10% free
    if ($storageFree / $storageTotal < 0.1) {
        $health['checks']['storage']['status'] = 'warning';
        $health['checks']['storage']['message'] = 'Low disk space';
    }
}

if (!$storageWritable) $allHealthy = false;

// Cache directory check
$cacheDir = __DIR__ . '/storage/cache';
$cacheWritable = is_dir($cacheDir) && is_writable($cacheDir);
$health['checks']['cache'] = [
    'status' => $cacheWritable ? 'ok' : 'warning',
    'writable' => $cacheWritable
];

// Logs directory check
$logsDir = __DIR__ . '/storage/logs';
$logsWritable = is_dir($logsDir) && is_writable($logsDir);
$health['checks']['logs'] = [
    'status' => $logsWritable ? 'ok' : 'warning',
    'writable' => $logsWritable
];

// PHP info
$health['checks']['php'] = [
    'status' => 'ok',
    'version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size')
];

// Required extensions
$requiredExtensions = ['pdo', 'json', 'mbstring', 'fileinfo'];
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (!empty($missingExtensions)) {
    $health['checks']['php']['status'] = 'warning';
    $health['checks']['php']['missing_extensions'] = $missingExtensions;
}

// Optional extensions
$optionalExtensions = ['curl', 'gd', 'zip', 'xml'];
$health['checks']['php']['extensions'] = [
    'curl' => extension_loaded('curl'),
    'gd' => extension_loaded('gd'),
    'zip' => extension_loaded('zip'),
    'xml' => extension_loaded('xml')
];

// Maintenance mode check
$maintenanceMode = false;
if (file_exists(__DIR__ . '/.maintenance')) {
    $maintenanceMode = true;
} elseif (function_exists('getSetting')) {
    $maintenanceMode = getSetting('maintenance_mode', '0') === '1';
}

$health['checks']['maintenance'] = [
    'status' => $maintenanceMode ? 'maintenance' : 'ok',
    'enabled' => $maintenanceMode
];

// Application info
$health['app'] = [
    'name' => defined('SITE_NAME') ? SITE_NAME : 'MeshSilo',
    'version' => defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0',
    'environment' => defined('APP_ENV') ? APP_ENV : 'production'
];

// Model/data counts (quick queries)
try {
    $modelCount = $db->querySingle('SELECT COUNT(*) FROM models WHERE parent_id IS NULL');
    $userCount = $db->querySingle('SELECT COUNT(*) FROM users');

    $health['data'] = [
        'models' => (int)$modelCount,
        'users' => (int)$userCount
    ];
} catch (Exception $e) {
    // Ignore data count errors
}

// Overall status
if (!$allHealthy) {
    $health['status'] = 'error';
    http_response_code(503);
} elseif ($maintenanceMode) {
    $health['status'] = 'maintenance';
    http_response_code(503);
}

// Add response time
$health['response_time_ms'] = round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2);

echo json_encode($health, JSON_PRETTY_PRINT);
