<?php
/**
 * System Health Dashboard
 *
 * Real-time system monitoring with metrics, alerts, and performance indicators
 */
require_once __DIR__ . '/../../includes/config.php';

// Require view stats permission (health monitoring is part of system stats)
if (!isLoggedIn() || !canViewStats()) {
    $_SESSION['error'] = 'You do not have permission to view system health.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'System Health';
$activePage = 'admin';
$adminPage = 'health';

$db = getDB();

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'metrics':
            echo json_encode(getSystemMetrics());
            exit;
        case 'services':
            echo json_encode(checkServices());
            exit;
        case 'recent_errors':
            echo json_encode(getRecentErrors());
            exit;
    }
}

/**
 * Get system metrics
 */
function getSystemMetrics() {
    $db = getDB();
    if (!$db) {
        return ['error' => 'Database connection failed'];
    }

    $metrics = [];

    // Memory usage
    $memoryLimit = ini_get('memory_limit');
    $memoryUsed = memory_get_usage(true);
    $memoryPeak = memory_get_peak_usage(true);
    $memoryLimitBytes = convertToBytes($memoryLimit);

    // Handle unlimited memory (-1)
    $memoryUnlimited = ($memoryLimitBytes <= 0);
    $metrics['memory'] = [
        'used' => $memoryUsed,
        'peak' => $memoryPeak,
        'limit' => $memoryLimitBytes,
        'unlimited' => $memoryUnlimited,
        'percent' => (!$memoryUnlimited && $memoryLimitBytes > 0) ? round(($memoryUsed / $memoryLimitBytes) * 100, 1) : 0
    ];

    // Disk usage
    $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../../assets';
    $diskFree = @disk_free_space($uploadPath) ?: 0;
    $diskTotal = @disk_total_space($uploadPath) ?: 0;
    $diskUsed = $diskTotal - $diskFree;

    $metrics['disk'] = [
        'used' => $diskUsed,
        'free' => $diskFree,
        'total' => $diskTotal,
        'percent' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0
    ];

    // Database size
    $dbPath = defined('DB_PATH') ? DB_PATH : '';
    $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
    $metrics['database'] = [
        'size' => $dbSize,
        'type' => defined('DB_TYPE') ? DB_TYPE : 'sqlite'
    ];

    // Request stats (from activity log if available)
    try {
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM activity_log WHERE created_at >= :since");
        $stmt->bindValue(':since', $oneHourAgo, PDO::PARAM_STR);
        $result = $stmt->execute();
        $metrics['requests_hour'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
    } catch (Exception $e) {
        $metrics['requests_hour'] = 0;
    }

    // Active sessions
    try {
        $now = time();
        $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM sessions WHERE expires_at > :now");
        $stmt->bindValue(':now', $now, PDO::PARAM_INT);
        $result = $stmt->execute();
        $metrics['active_sessions'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
    } catch (Exception $e) {
        $metrics['active_sessions'] = 0;
    }

    // Error count (last 24 hours)
    try {
        $oneDayAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM audit_log WHERE severity IN ('error', 'critical') AND created_at >= :since");
        $stmt->bindValue(':since', $oneDayAgo, PDO::PARAM_STR);
        $result = $stmt->execute();
        $metrics['errors_24h'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
    } catch (Exception $e) {
        $metrics['errors_24h'] = 0;
    }

    // Model count
    try {
        $result = $db->query("SELECT COUNT(*) as count FROM models WHERE parent_id IS NULL");
        $metrics['model_count'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
    } catch (Exception $e) {
        $metrics['model_count'] = 0;
    }

    // User count
    try {
        $result = $db->query("SELECT COUNT(*) as count FROM users");
        $metrics['user_count'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
    } catch (Exception $e) {
        $metrics['user_count'] = 0;
    }

    // PHP info
    $metrics['php'] = [
        'version' => PHP_VERSION,
        'memory_limit' => $memoryLimit,
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size')
    ];

    // Server uptime (if available)
    $metrics['uptime'] = getServerUptime();

    $metrics['timestamp'] = date('c');

    return $metrics;
}

/**
 * Check service health
 */
function checkServices() {
    $services = [];

    // Database
    $services['database'] = checkDatabaseHealth();

    // File storage
    $services['storage'] = checkStorageHealth();

    // Cache
    $services['cache'] = checkCacheHealth();

    // Search (FTS)
    $services['search'] = checkSearchHealth();

    // External services
    $services['external'] = checkExternalServices();

    // Plugin hook: health_checks - custom service health checks (S3, Redis, external APIs)
    if (class_exists('PluginManager')) {
        $services = PluginManager::applyFilter('health_checks', $services);
    }

    return $services;
}

function checkDatabaseHealth() {
    $db = getDB();
    if (!$db) {
        return ['status' => 'critical', 'message' => 'Database connection failed', 'latency' => 0];
    }

    try {
        $start = microtime(true);
        $result = $db->query("SELECT 1");
        $latency = round((microtime(true) - $start) * 1000, 2);

        // Check for database locks or issues
        $integrity = true;
        if (defined('DB_TYPE') && DB_TYPE === 'sqlite') {
            $integrityResult = $db->query("PRAGMA integrity_check(1)");
            $row = $integrityResult->fetchArray(PDO::FETCH_ASSOC);
            $integrity = ($row && $row['integrity_check'] === 'ok');
        }

        return [
            'status' => $integrity ? 'healthy' : 'degraded',
            'latency_ms' => $latency,
            'message' => $integrity ? 'Database operational' : 'Database integrity issues detected'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'down',
            'latency_ms' => 0,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

function checkStorageHealth() {
    $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../../assets';

    if (!is_dir($uploadPath)) {
        return [
            'status' => 'down',
            'message' => 'Upload directory does not exist'
        ];
    }

    if (!is_writable($uploadPath)) {
        return [
            'status' => 'down',
            'message' => 'Upload directory is not writable'
        ];
    }

    $diskFree = @disk_free_space($uploadPath);
    $diskTotal = @disk_total_space($uploadPath);

    if ($diskFree === false || $diskTotal === false) {
        return [
            'status' => 'degraded',
            'message' => 'Cannot determine disk space'
        ];
    }

    $percentFree = $diskTotal > 0 ? ($diskFree / $diskTotal) * 100 : 0;

    if ($percentFree < 5) {
        return [
            'status' => 'critical',
            'message' => 'Disk space critically low (<5% free)'
        ];
    } elseif ($percentFree < 15) {
        return [
            'status' => 'warning',
            'message' => 'Disk space low (<15% free)'
        ];
    }

    return [
        'status' => 'healthy',
        'message' => 'Storage operational'
    ];
}

function checkCacheHealth() {
    $cachePath = defined('CACHE_PATH') ? CACHE_PATH : __DIR__ . '/../../storage/cache';

    if (!is_dir($cachePath)) {
        return [
            'status' => 'warning',
            'message' => 'Cache directory does not exist'
        ];
    }

    if (!is_writable($cachePath)) {
        return [
            'status' => 'warning',
            'message' => 'Cache directory is not writable'
        ];
    }

    return [
        'status' => 'healthy',
        'message' => 'File cache operational'
    ];
}

function checkSearchHealth() {
    $db = getDB();
    if (!$db) {
        return ['status' => 'critical', 'message' => 'Database connection failed'];
    }

    try {
        // Check if FTS table exists (SQLite only)
        $hasFts = false;
        if ($db->getType() === 'sqlite') {
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='models_fts'");
            $hasFts = $result && $result->fetchArray(PDO::FETCH_ASSOC);
        } else {
            // MySQL uses FULLTEXT indexes, check if they exist
            $result = $db->query("SHOW INDEX FROM models WHERE Index_type = 'FULLTEXT'");
            $hasFts = $result && $result->fetch();
        }

        if (!$hasFts) {
            return [
                'status' => 'warning',
                'message' => 'Full-text search not configured'
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'Full-text search operational'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'degraded',
            'message' => 'Search check failed'
        ];
    }
}

function checkExternalServices() {
    $services = [];

    // Check S3 if configured
    $s3Enabled = getSetting('storage_type', 'local') === 's3' || getSetting('backup_s3_enabled', '0') === '1';
    if ($s3Enabled) {
        $services['s3'] = [
            'name' => 'S3 Storage',
            'status' => 'configured',
            'message' => 'S3 storage enabled'
        ];
    }

    // Check webhooks (only if table exists)
    try {
        $db = getDB();
        if ($db && tableExists($db, 'webhooks')) {
            $result = $db->query("SELECT COUNT(*) as count FROM webhooks WHERE is_active = 1");
            $webhookCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

            if ($webhookCount > 0) {
                $services['webhooks'] = [
                    'name' => 'Webhooks',
                    'status' => 'healthy',
                    'message' => "$webhookCount active webhook(s)"
                ];
            }
        }
    } catch (Exception $e) {
        // Safe to ignore
    }

    return $services;
}

/**
 * Get recent errors from logs
 */
function getRecentErrors() {
    $db = getDB();
    $errors = [];

    if (!$db) {
        return $errors;
    }

    try {
        $result = $db->query("
            SELECT event_name, severity, resource_type, resource_id, created_at, metadata
            FROM audit_log
            WHERE severity IN ('error', 'critical', 'warning')
            ORDER BY created_at DESC
            LIMIT 20
        ");

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $errors[] = $row;
        }
    } catch (Exception $e) {
        // Audit log might not exist
    }

    // Also check error log file
    $logFile = __DIR__ . '/../../storage/logs/error.log';
    if (file_exists($logFile)) {
        $logLines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -10);
        foreach ($logLines as $line) {
            $errors[] = [
                'event_name' => 'PHP Error',
                'severity' => strpos($line, 'CRITICAL') !== false ? 'critical' :
                             (strpos($line, 'ERROR') !== false ? 'error' : 'warning'),
                'resource_type' => 'system',
                'resource_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'metadata' => ['message' => substr($line, 0, 200)]
            ];
        }
    }

    return array_slice($errors, 0, 20);
}

function getServerUptime() {
    if (PHP_OS_FAMILY === 'Linux') {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime) {
            $seconds = (int)floatval($uptime);
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$days}d {$hours}h {$minutes}m";
        }
    }
    return 'N/A';
}

// convertToBytes and formatBytes are defined in includes/helpers.php

// Get initial data
$metrics = getSystemMetrics();
$services = checkServices();
$recentErrors = getRecentErrors();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>System Health</h1>
        <div class="header-actions">
            <span class="last-updated">Last updated: <span id="last-update"><?= date('H:i:s') ?></span></span>
            <button type="button" class="btn btn-secondary" data-action="refresh-metrics">Refresh</button>
            <label class="auto-refresh">
                <input type="checkbox" id="auto-refresh" checked> Auto-refresh (30s)
            </label>
        </div>
    </div>

    <!-- Overall Status -->
    <div class="health-status-banner <?= getOverallStatus($services) ?>">
        <span class="status-icon"></span>
        <span class="status-text"><?= getStatusMessage($services) ?></span>
    </div>

    <!-- Key Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true">&#128202;</span>
                <span class="metric-title">Memory Usage</span>
            </div>
            <div class="metric-value" id="memory-percent"><?= $metrics['memory']['percent'] ?>%</div>
            <div class="metric-bar">
                <div class="metric-bar-fill <?= $metrics['memory']['percent'] > 80 ? 'warning' : '' ?>"
                     style="width: <?= min($metrics['memory']['percent'], 100) ?>%"></div>
            </div>
            <div class="metric-detail">
                <span id="memory-used"><?= formatBytes($metrics['memory']['used']) ?></span> /
                <span id="memory-limit"><?= $metrics['memory']['unlimited'] ? 'Unlimited' : formatBytes($metrics['memory']['limit']) ?></span>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true">&#128190;</span>
                <span class="metric-title">Disk Usage</span>
            </div>
            <div class="metric-value" id="disk-percent"><?= $metrics['disk']['percent'] ?>%</div>
            <div class="metric-bar">
                <div class="metric-bar-fill <?= $metrics['disk']['percent'] > 85 ? 'critical' : ($metrics['disk']['percent'] > 70 ? 'warning' : '') ?>"
                     style="width: <?= min($metrics['disk']['percent'], 100) ?>%"></div>
            </div>
            <div class="metric-detail">
                <span id="disk-free"><?= formatBytes($metrics['disk']['free']) ?></span> free of
                <span id="disk-total"><?= formatBytes($metrics['disk']['total']) ?></span>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true">&#128100;</span>
                <span class="metric-title">Active Sessions</span>
            </div>
            <div class="metric-value" id="active-sessions"><?= $metrics['active_sessions'] ?></div>
            <div class="metric-detail">Users currently logged in</div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true">&#9888;</span>
                <span class="metric-title">Errors (24h)</span>
            </div>
            <div class="metric-value <?= $metrics['errors_24h'] > 0 ? 'text-danger' : 'text-success' ?>"
                 id="errors-24h"><?= $metrics['errors_24h'] ?></div>
            <div class="metric-detail">Critical and error events</div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true">&#128196;</span>
                <span class="metric-title">Requests/Hour</span>
            </div>
            <div class="metric-value" id="requests-hour"><?= $metrics['requests_hour'] ?></div>
            <div class="metric-detail">Activity in the last hour</div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon">&#128230;</span>
                <span class="metric-title">Database</span>
            </div>
            <div class="metric-value" id="db-size"><?= formatBytes($metrics['database']['size']) ?></div>
            <div class="metric-detail"><?= ucfirst($metrics['database']['type']) ?> database</div>
        </div>
    </div>

    <!-- Service Status -->
    <div class="section">
        <h2>Service Status</h2>
        <div class="services-grid" id="services-container">
            <?php foreach ($services as $name => $service): ?>
            <?php if (is_array($service) && isset($service['status'])): ?>
            <div class="service-card status-<?= htmlspecialchars($service['status']) ?>">
                <div class="service-name"><?= ucfirst($name) ?></div>
                <div class="service-status">
                    <span class="status-badge badge-<?= htmlspecialchars($service['status']) ?>"><?= htmlspecialchars(ucfirst($service['status'])) ?></span>
                </div>
                <div class="service-message"><?= htmlspecialchars($service['message']) ?></div>
                <?php if (isset($service['latency_ms'])): ?>
                <div class="service-latency"><?= $service['latency_ms'] ?>ms</div>
                <?php endif; ?>
            </div>
            <?php elseif (is_array($service)): ?>
                <?php foreach ($service as $subName => $subService): ?>
                <div class="service-card status-<?= htmlspecialchars($subService['status'] ?? 'unknown') ?>">
                    <div class="service-name"><?= htmlspecialchars($subService['name'] ?? ucfirst($subName)) ?></div>
                    <div class="service-status">
                        <span class="status-badge badge-<?= htmlspecialchars($subService['status'] ?? 'unknown') ?>"><?= htmlspecialchars(ucfirst($subService['status'] ?? 'Unknown')) ?></span>
                    </div>
                    <div class="service-message"><?= htmlspecialchars($subService['message'] ?? '') ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- System Information -->
    <div class="section">
        <h2>System Information</h2>
        <div class="info-grid">
            <div class="info-card">
                <h3>PHP Configuration</h3>
                <table class="info-table" aria-label="PHP configuration">
                    <tr>
                        <td>PHP Version</td>
                        <td><?= $metrics['php']['version'] ?></td>
                    </tr>
                    <tr>
                        <td>Memory Limit</td>
                        <td><?= $metrics['php']['memory_limit'] ?></td>
                    </tr>
                    <tr>
                        <td>Max Execution Time</td>
                        <td><?= $metrics['php']['max_execution_time'] ?>s</td>
                    </tr>
                    <tr>
                        <td>Upload Max Filesize</td>
                        <td><?= $metrics['php']['upload_max_filesize'] ?></td>
                    </tr>
                    <tr>
                        <td>Post Max Size</td>
                        <td><?= $metrics['php']['post_max_size'] ?></td>
                    </tr>
                </table>
            </div>

            <div class="info-card">
                <h3>Application Stats</h3>
                <table class="info-table" aria-label="Application statistics">
                    <tr>
                        <td>Total Models</td>
                        <td id="model-count"><?= number_format($metrics['model_count']) ?></td>
                    </tr>
                    <tr>
                        <td>Total Users</td>
                        <td id="user-count"><?= number_format($metrics['user_count']) ?></td>
                    </tr>
                    <tr>
                        <td>Server Uptime</td>
                        <td><?= $metrics['uptime'] ?></td>
                    </tr>
                    <tr>
                        <td>App Version</td>
                        <td><?= defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Errors -->
    <?php if (!empty($recentErrors)): ?>
    <div class="section">
        <h2>Recent Issues</h2>
        <div class="errors-table-container">
            <table class="data-table" id="errors-table" aria-label="Recent issues">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Severity</th>
                        <th scope="col">Event</th>
                        <th scope="col">Resource</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentErrors as $error): ?>
                    <tr class="severity-<?= htmlspecialchars($error['severity']) ?>">
                        <td class="timestamp"><?= date('M j H:i', strtotime($error['created_at'])) ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($error['severity']) ?>"><?= htmlspecialchars($error['severity']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($error['event_name']) ?></td>
                        <td>
                            <?php if ($error['resource_type']): ?>
                            <?= htmlspecialchars($error['resource_type']) ?>
                            <?php if ($error['resource_id']): ?>#<?= $error['resource_id'] ?><?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="error-details">
                            <?php
                            $meta = is_string($error['metadata']) ? json_decode($error['metadata'], true) : $error['metadata'];
                            echo htmlspecialchars($meta['message'] ?? '-');
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="section-footer">
            <a href="<?= route('admin.audit-log') ?>?severity=error" class="btn btn-secondary">View All Errors</a>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<?php
function getOverallStatus($services) {
    $hasDown = false;
    $hasCritical = false;
    $hasWarning = false;

    foreach ($services as $service) {
        if (is_array($service)) {
            if (isset($service['status'])) {
                if ($service['status'] === 'down') $hasDown = true;
                if ($service['status'] === 'critical') $hasCritical = true;
                if ($service['status'] === 'warning' || $service['status'] === 'degraded') $hasWarning = true;
            } else {
                foreach ($service as $sub) {
                    if (isset($sub['status'])) {
                        if ($sub['status'] === 'down') $hasDown = true;
                        if ($sub['status'] === 'critical') $hasCritical = true;
                        if ($sub['status'] === 'warning' || $sub['status'] === 'degraded') $hasWarning = true;
                    }
                }
            }
        }
    }

    if ($hasDown) return 'status-down';
    if ($hasCritical) return 'status-critical';
    if ($hasWarning) return 'status-warning';
    return 'status-healthy';
}

function getStatusMessage($services) {
    $status = getOverallStatus($services);
    switch ($status) {
        case 'status-down': return 'System Degraded - Some services are down';
        case 'status-critical': return 'Critical Issues Detected';
        case 'status-warning': return 'System Operational with Warnings';
        default: return 'All Systems Operational';
    }
}
?>

<style>
.health-status-banner {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    font-weight: 500;
}

.health-status-banner.status-healthy {
    background: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success);
}

.health-status-banner.status-warning {
    background: color-mix(in srgb, var(--color-warning) 15%, transparent);
    color: var(--color-warning);
}

.health-status-banner.status-critical {
    background: color-mix(in srgb, var(--color-danger) 15%, transparent);
    color: var(--color-danger);
}

.health-status-banner.status-down {
    background: color-mix(in srgb, var(--color-danger) 20%, transparent);
    color: var(--color-danger);
}

.status-icon::before {
    font-size: 1.25rem;
}

.status-healthy .status-icon::before { content: "✔"; }
.status-warning .status-icon::before { content: "⚠"; }
.status-critical .status-icon::before, .status-down .status-icon::before { content: "✖"; }

.header-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.last-updated {
    color: var(--color-text-muted);
    font-size: 0.875rem;
}

.auto-refresh {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    cursor: pointer;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.metric-card {
    background: var(--color-surface);
    padding: 1.25rem;
    border-radius: var(--radius);
    border: 1px solid var(--color-border);
}

.metric-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.metric-icon {
    font-size: 1.25rem;
}

.metric-title {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.metric-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.metric-bar {
    height: 6px;
    background: var(--color-bg);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.metric-bar-fill {
    height: 100%;
    background: var(--color-primary);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.metric-bar-fill.warning {
    background: var(--color-warning);
}

.metric-bar-fill.critical {
    background: var(--color-danger);
}

.metric-detail {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.section {
    margin-bottom: 2rem;
}

.section h2 {
    margin-bottom: 1rem;
    font-size: 1.25rem;
}

.services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.service-card {
    background: var(--color-surface);
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid var(--color-border);
}

.service-card.status-healthy { border-left-color: var(--color-success); }
.service-card.status-warning, .service-card.status-degraded { border-left-color: var(--color-warning); }
.service-card.status-critical, .service-card.status-down { border-left-color: var(--color-danger); }
.service-card.status-configured { border-left-color: var(--color-primary); }

.service-name {
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.service-status {
    margin-bottom: 0.25rem;
}

.status-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
}

.badge-healthy { background: color-mix(in srgb, var(--color-success) 20%, transparent); color: var(--color-success); }
.badge-warning, .badge-degraded { background: color-mix(in srgb, var(--color-warning) 20%, transparent); color: var(--color-warning); }
.badge-critical, .badge-down { background: color-mix(in srgb, var(--color-danger) 20%, transparent); color: var(--color-danger); }
.badge-configured { background: color-mix(in srgb, var(--color-primary) 20%, transparent); color: var(--color-primary); }
.badge-error { background: color-mix(in srgb, var(--color-danger) 20%, transparent); color: var(--color-danger); }

.service-message {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.service-latency {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-top: 0.25rem;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
}

.info-card {
    background: var(--color-surface);
    padding: 1rem;
    border-radius: 8px;
}

.info-card h3 {
    margin-bottom: 1rem;
    font-size: 1rem;
}

.info-table {
    width: 100%;
}

.info-table td {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--color-border);
}

.info-table td:first-child {
    color: var(--color-text-muted);
}

.info-table td:last-child {
    text-align: right;
    font-family: monospace;
}

.info-table tr:last-child td {
    border-bottom: none;
}

.errors-table-container {
    overflow-x: auto;
}

.timestamp {
    white-space: nowrap;
    font-size: 0.85rem;
    font-family: monospace;
}

.error-details {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.85rem;
}

.severity-error { background: color-mix(in srgb, var(--color-danger) 5%, transparent); }
.severity-critical { background: color-mix(in srgb, var(--color-danger) 10%, transparent); }
.severity-warning { background: color-mix(in srgb, var(--color-warning) 5%, transparent); }

.section-footer {
    margin-top: 1rem;
    text-align: center;
}

.text-danger { color: var(--color-danger); }
.text-success { color: var(--color-success); }

@media (max-width: 768px) {
    .header-actions {
        flex-wrap: wrap;
    }

    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
let refreshInterval;

function refreshMetrics() {
    fetch('?action=metrics')
        .then(r => r.json())
        .then(data => {
            document.getElementById('memory-percent').textContent = data.memory.percent + '%';
            document.getElementById('memory-used').textContent = formatBytes(data.memory.used);
            document.getElementById('memory-limit').textContent = data.memory.unlimited ? 'Unlimited' : formatBytes(data.memory.limit);
            document.getElementById('disk-percent').textContent = data.disk.percent + '%';
            document.getElementById('disk-free').textContent = formatBytes(data.disk.free);
            document.getElementById('active-sessions').textContent = data.active_sessions;
            document.getElementById('errors-24h').textContent = data.errors_24h;
            document.getElementById('requests-hour').textContent = data.requests_hour;
            document.getElementById('db-size').textContent = formatBytes(data.database.size);
            document.getElementById('model-count').textContent = data.model_count.toLocaleString();
            document.getElementById('user-count').textContent = data.user_count.toLocaleString();
            document.getElementById('last-update').textContent = new Date().toLocaleTimeString();

            // Update progress bars
            document.querySelector('#memory-percent').closest('.metric-card').querySelector('.metric-bar-fill').style.width = Math.min(data.memory.percent, 100) + '%';
            document.querySelector('#disk-percent').closest('.metric-card').querySelector('.metric-bar-fill').style.width = Math.min(data.disk.percent, 100) + '%';
        })
        .catch(err => console.error('Failed to refresh metrics:', err));
}

function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024;
        i++;
    }
    return bytes.toFixed(1) + ' ' + units[i];
}

document.getElementById('auto-refresh').addEventListener('change', function() {
    if (this.checked) {
        refreshInterval = setInterval(refreshMetrics, 30000);
    } else {
        clearInterval(refreshInterval);
    }
});

// Start auto-refresh
refreshInterval = setInterval(refreshMetrics, 30000);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
