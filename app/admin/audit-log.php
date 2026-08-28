<?php
/**
 * Admin Audit Log Viewer
 *
 * View, filter, and export comprehensive audit logs
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/AuditLogger.php';
require_once __DIR__ . '/../../includes/features.php';

// Require feature to be enabled
requireFeature('activity_log');

// Require audit log permission
requireAdminPage('canViewAuditLog', 'You do not have permission to view the audit log.');

$pageTitle = 'Audit Log';
$adminPage = 'audit-log';
$activePage = 'admin';

// Handle export
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $filters = [
        'event_type' => $_GET['event_type'] ?? '',
        'severity' => $_GET['severity'] ?? '',
        'user_id' => $_GET['user_id'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'search' => $_GET['search'] ?? ''
    ];

    if ($format === 'csv') {
        $export = AuditLogger::exportCSV($filters);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        echo $export['content'];
        exit;
    } elseif ($format === 'json') {
        $export = AuditLogger::exportJSON($filters);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        echo $export['content'];
        exit;
    }
}

// Handle compliance report
if (isset($_GET['compliance_report'])) {
    $startDate = $_GET['report_start'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['report_end'] ?? date('Y-m-d');
    $format = $_GET['report_format'] ?? 'summary';

    $report = AuditLogger::generateComplianceReport($startDate . ' 00:00:00', $endDate . ' 23:59:59', $format);

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="compliance_report_' . date('Y-m-d') . '.json"');
    echo json_encode($report, JSON_PRETTY_PRINT);
    exit;
}

// Get filters
$filters = [
    'event_type' => $_GET['event_type'] ?? '',
    'severity' => $_GET['severity'] ?? '',
    'user_id' => $_GET['user_id'] ?? '',
    'resource_type' => $_GET['resource_type'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => $_GET['search'] ?? ''
];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get logs
$result = AuditLogger::query($filters, $perPage, $offset);
$logs = $result['data'];
$total = $result['total'];
$totalPages = ceil($total / $perPage);

// Get stats
$stats = AuditLogger::getStats();

// Get users for filter dropdown
$db = getDB();
$usersResult = $db->query('SELECT id, username FROM users ORDER BY username');
$users = [];
while ($row = $usersResult->fetchArray(PDO::FETCH_ASSOC)) {
    $users[] = $row;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>Audit Log</h1>
        <div class="header-actions">
            <div class="dropdown">
                <button type="button" class="btn btn-secondary dropdown-toggle">Export</button>
                <div class="dropdown-menu" role="menu">
                    <a href="?<?= http_build_query(array_merge($filters, ['export' => 'csv'])) ?>" class="dropdown-item" role="menuitem">Export CSV</a>
                    <a href="?<?= http_build_query(array_merge($filters, ['export' => 'json'])) ?>" class="dropdown-item" role="menuitem">Export JSON</a>
                </div>
            </div>
            <button type="button" class="btn btn-primary" data-action="show-compliance-modal">Compliance Report</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?= number_format($stats['total']) ?></span>
            <span class="stat-label">Total Events</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= number_format($stats['today']) ?></span>
            <span class="stat-label">Today</span>
        </div>
        <div class="stat-card">
            <span class="stat-value"><?= number_format($total) ?></span>
            <span class="stat-label">Filtered Results</span>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <form method="GET" class="filters-form" role="search">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="event_type">Event Type</label>
                    <select name="event_type" id="event_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="auth" <?= $filters['event_type'] === 'auth' ? 'selected' : '' ?>>Authentication</option>
                        <option value="data" <?= $filters['event_type'] === 'data' ? 'selected' : '' ?>>Data Changes</option>
                        <option value="admin" <?= $filters['event_type'] === 'admin' ? 'selected' : '' ?>>Admin Actions</option>
                        <option value="security" <?= $filters['event_type'] === 'security' ? 'selected' : '' ?>>Security</option>
                        <option value="api" <?= $filters['event_type'] === 'api' ? 'selected' : '' ?>>API</option>
                        <option value="system" <?= $filters['event_type'] === 'system' ? 'selected' : '' ?>>System</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="severity">Severity</label>
                    <select name="severity" id="severity" class="form-control">
                        <option value="">All Severities</option>
                        <option value="info" <?= $filters['severity'] === 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $filters['severity'] === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="error" <?= $filters['severity'] === 'error' ? 'selected' : '' ?>>Error</option>
                        <option value="critical" <?= $filters['severity'] === 'critical' ? 'selected' : '' ?>>Critical</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="audit_user_id">User</label>
                    <select name="user_id" id="audit_user_id" class="form-control">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filters['user_id'] == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date_from">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']) ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']) ?>">
                </div>

                <div class="filter-group">
                    <label for="audit_search">Search</label>
                    <input type="search" name="search" id="audit_search" class="form-control" placeholder="Search events..." value="<?= htmlspecialchars($filters['search']) ?>" enterkeyhint="search">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="<?= route('admin.audit-log') ?>" class="btn btn-secondary">Clear</a>
            </div>
        </form>
    </div>

    <!-- Log Table -->
    <div class="table-responsive">
        <table class="data-table audit-log-table" aria-label="Audit log entries">
            <thead>
                <tr>
                    <th scope="col">Timestamp</th>
                    <th scope="col">Type</th>
                    <th scope="col">Event</th>
                    <th scope="col">Severity</th>
                    <th scope="col">User</th>
                    <th scope="col">Resource</th>
                    <th scope="col">IP</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8" class="empty-row">No audit logs found</td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr class="severity-<?= htmlspecialchars($log['severity']) ?>">
                    <td class="timestamp">
                        <span title="<?= htmlspecialchars($log['created_at']) ?>">
                            <?= date('M j, H:i:s', strtotime($log['created_at'])) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-type-<?= htmlspecialchars($log['event_type']) ?>"><?= htmlspecialchars($log['event_type']) ?></span>
                    </td>
                    <td class="event-name"><?= htmlspecialchars($log['event_name']) ?></td>
                    <td>
                        <span class="badge badge-<?= $log['severity'] ?>"><?= htmlspecialchars($log['severity']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                    <td>
                        <?php if ($log['resource_type']): ?>
                        <span class="resource">
                            <?= htmlspecialchars($log['resource_type']) ?>
                            <?php if ($log['resource_id']): ?>#<?= $log['resource_id'] ?><?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="ip-address"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-secondary" data-action="show-details" data-log-id="<?= $log['id'] ?>">
                            Details
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="btn btn-secondary">&laquo; Prev</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="btn btn-secondary">Next &raquo;</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</div>

<!-- Details Modal -->
<div id="details-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="details-modal-title" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="details-modal-title">Event Details</h3>
            <button type="button" class="modal-close" aria-label="Close" data-action="close-details-modal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="details-content">
            Loading...
        </div>
    </div>
</div>

<!-- Compliance Report Modal -->
<div id="compliance-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="compliance-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="compliance-modal-title">Generate Compliance Report</h3>
            <button type="button" class="modal-close" aria-label="Close" data-action="close-compliance-modal"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="GET" class="modal-body">
            <input type="hidden" name="compliance_report" value="1">

            <div class="form-group">
                <label for="report_start">Start Date</label>
                <input type="date" name="report_start" id="report_start" class="form-control" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" required>
            </div>

            <div class="form-group">
                <label for="report_end">End Date</label>
                <input type="date" name="report_end" id="report_end" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label for="report_format">Report Format</label>
                <select name="report_format" id="report_format" class="form-control">
                    <option value="summary">Summary Only</option>
                    <option value="detailed">Detailed (includes critical events)</option>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-compliance-modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Generate Report</button>
            </div>
        </form>
    </div>
</div>

<!-- Log details data -->
<script<?= csp_nonce_attr() ?>>
window.AuditLogConfig = { logsData: <?= json_encode(array_combine(array_column($logs, 'id'), $logs)) ?> };
</script>

</div><!-- /.admin-layout -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
