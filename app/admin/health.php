<?php
/**
 * System Health Dashboard
 *
 * Real-time system monitoring with metrics, alerts, and performance indicators
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/HealthChecker.php';

// Require view stats permission (health monitoring is part of system stats)
requireAdminPage('canViewStats', 'You do not have permission to view system health.');

$pageTitle = 'System Health';
$activePage = 'admin';
$adminPage = 'health';

$db = getDB();

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'metrics':
            echo json_encode(HealthChecker::getSystemMetrics());
            exit;
        case 'services':
            echo json_encode(HealthChecker::checkServices());
            exit;
        case 'recent_errors':
            echo json_encode(HealthChecker::getRecentErrors());
            exit;
    }
}

// convertToBytes and formatBytes are defined in includes/helpers.php
// Health-check logic lives in includes/HealthChecker.php

// Get initial data
$metrics = HealthChecker::getSystemMetrics();
$services = HealthChecker::checkServices();
$recentErrors = HealthChecker::getRecentErrors();

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
                <span class="metric-icon" aria-hidden="true"><i class="fa-solid fa-memory"></i></span>
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
                <span class="metric-icon" aria-hidden="true"><i class="fa-solid fa-hard-drive"></i></span>
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
                <span class="metric-icon" aria-hidden="true"><i class="fa-solid fa-users"></i></span>
                <span class="metric-title">Active Sessions</span>
            </div>
            <div class="metric-value" id="active-sessions"><?= $metrics['active_sessions'] ?></div>
            <div class="metric-detail">Users currently logged in</div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <span class="metric-title">Errors (24h)</span>
            </div>
            <div class="metric-value <?= $metrics['errors_24h'] > 0 ? 'text-danger' : 'text-success' ?>"
                 id="errors-24h"><?= $metrics['errors_24h'] ?></div>
            <div class="metric-detail">Critical and error events</div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
                <span class="metric-title">Requests/Hour</span>
            </div>
            <div class="metric-value" id="requests-hour"><?= $metrics['requests_hour'] ?></div>
            <div class="metric-detail">Activity in the last hour</div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <span class="metric-icon"><i class="fa-solid fa-database"></i></span>
                <span class="metric-title">Database</span>
            </div>
            <div class="metric-value" id="db-size"><?= formatBytes($metrics['database']['size']) ?></div>
            <div class="metric-detail"><?= ucfirst($metrics['database']['type']) ?> database</div>
        </div>
    </div>

    <!-- Service Status -->
    <div class="section">
        <h2>Service Status</h2>
        <div class="metrics-grid" id="services-container">
            <?php
            $serviceIcons = [
                'database' => '<i class="fa-solid fa-database"></i>',
                'storage' => '<i class="fa-solid fa-hard-drive"></i>',
                'cache' => '<i class="fa-solid fa-bolt"></i>',
                'search' => '<i class="fa-solid fa-magnifying-glass"></i>',
            ];
            $statusIcons = [
                'healthy' => '<i class="fa-solid fa-check"></i>',
                'warning' => '<i class="fa-solid fa-triangle-exclamation"></i>',
                'degraded' => '<i class="fa-solid fa-triangle-exclamation"></i>',
                'critical' => '<i class="fa-solid fa-xmark"></i>',
                'down' => '<i class="fa-solid fa-xmark"></i>',
                'configured' => '<i class="fa-solid fa-check"></i>',
            ];
            ?>
            <?php foreach ($services as $name => $service): ?>
            <?php if (is_array($service) && isset($service['status'])): ?>
            <div class="metric-card">
                <div class="metric-header">
                    <span class="metric-icon" aria-hidden="true"><?= $serviceIcons[$name] ?? '<i class="fa-solid fa-gear"></i>' ?></span>
                    <span class="metric-title"><?= ucfirst(htmlspecialchars($name)) ?></span>
                </div>
                <div class="metric-value service-status-value status-color-<?= htmlspecialchars($service['status']) ?>">
                    <?= $statusIcons[$service['status']] ?? '' ?> <?= ucfirst(htmlspecialchars($service['status'])) ?>
                </div>
                <div class="metric-detail"><?= htmlspecialchars($service['message']) ?></div>
                <?php if (isset($service['latency_ms'])): ?>
                <div class="metric-detail" style="margin-top: 0.25rem;"><?= $service['latency_ms'] ?>ms response time</div>
                <?php endif; ?>
            </div>
            <?php elseif (is_array($service)): ?>
                <?php foreach ($service as $subName => $subService): ?>
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-icon" aria-hidden="true"><i class="fa-solid fa-gear"></i></span>
                        <span class="metric-title"><?= htmlspecialchars($subService['name'] ?? ucfirst($subName)) ?></span>
                    </div>
                    <div class="metric-value service-status-value status-color-<?= htmlspecialchars($subService['status'] ?? 'unknown') ?>">
                        <?= $statusIcons[$subService['status'] ?? ''] ?? '' ?> <?= ucfirst(htmlspecialchars($subService['status'] ?? 'Unknown')) ?>
                    </div>
                    <div class="metric-detail"><?= htmlspecialchars($subService['message'] ?? '') ?></div>
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
                <div class="info-card-header">
                    <span class="info-card-icon" aria-hidden="true"><i class="fa-solid fa-gear"></i></span>
                    <h3>PHP Configuration</h3>
                </div>
                <div class="info-items">
                    <div class="info-item"><span class="info-label">PHP Version</span><span class="info-value"><?= $metrics['php']['version'] ?></span></div>
                    <div class="info-item"><span class="info-label">Memory Limit</span><span class="info-value"><?= $metrics['php']['memory_limit'] ?></span></div>
                    <div class="info-item"><span class="info-label">Max Execution</span><span class="info-value"><?= $metrics['php']['max_execution_time'] ?>s</span></div>
                    <div class="info-item"><span class="info-label">Upload Limit</span><span class="info-value"><?= $metrics['php']['upload_max_filesize'] ?></span></div>
                    <div class="info-item"><span class="info-label">Post Max Size</span><span class="info-value"><?= $metrics['php']['post_max_size'] ?></span></div>
                </div>
            </div>

            <div class="info-card">
                <div class="info-card-header">
                    <span class="info-card-icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
                    <h3>Application</h3>
                </div>
                <div class="info-items">
                    <div class="info-item"><span class="info-label">Models</span><span class="info-value" id="model-count"><?= number_format($metrics['model_count']) ?></span></div>
                    <div class="info-item"><span class="info-label">Users</span><span class="info-value" id="user-count"><?= number_format($metrics['user_count']) ?></span></div>
                    <div class="info-item"><span class="info-label">Uptime</span><span class="info-value"><?= $metrics['uptime'] ?></span></div>
                    <div class="info-item"><span class="info-label">Version</span><span class="info-value"><?= defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '0.5.0' ?></span></div>
                </div>
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

<script<?= csp_nonce_attr() ?>>
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
