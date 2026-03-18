<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

// Require feature to be enabled
requireFeature('activity_log');

// Require view logs permission
if (!isLoggedIn() || !canViewLogs()) {
    $_SESSION['error'] = 'You do not have permission to view activity logs.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Activity Log';
$activePage = 'admin';
$adminPage = 'activity';

$db = getDB();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filters
$filterAction = $_GET['action_filter'] ?? '';
$filterUser = (int)($_GET['user_filter'] ?? 0);
$filterEntity = $_GET['entity_filter'] ?? '';

$filters = [];
if ($filterAction) $filters['action'] = $filterAction;
if ($filterUser) $filters['user_id'] = $filterUser;
if ($filterEntity) $filters['entity_type'] = $filterEntity;

// Get activity log
$activities = getActivityLog($perPage, $offset, $filters);

// Get total count for pagination
$countWhere = ['1=1'];
$countParams = [];
if ($filterAction) {
    $countWhere[] = 'action = :action';
    $countParams[':action'] = $filterAction;
}
if ($filterUser) {
    $countWhere[] = 'user_id = :user_id';
    $countParams[':user_id'] = $filterUser;
}
if ($filterEntity) {
    $countWhere[] = 'entity_type = :entity_type';
    $countParams[':entity_type'] = $filterEntity;
}
$countSql = 'SELECT COUNT(*) FROM activity_log WHERE ' . implode(' AND ', $countWhere);
$countStmt = $db->prepare($countSql);
foreach ($countParams as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$totalActivities = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalActivities / $perPage);

// Get unique actions and entity types for filters
$actions = [];
$actionsResult = $db->query('SELECT DISTINCT action FROM activity_log ORDER BY action');
while ($row = $actionsResult->fetchArray(PDO::FETCH_ASSOC)) {
    $actions[] = $row['action'];
}

$entityTypes = [];
$entityResult = $db->query('SELECT DISTINCT entity_type FROM activity_log ORDER BY entity_type');
while ($row = $entityResult->fetchArray(PDO::FETCH_ASSOC)) {
    $entityTypes[] = $row['entity_type'];
}

// Get users for filter
$users = [];
$usersResult = $db->query('SELECT id, username FROM users ORDER BY username');
while ($row = $usersResult->fetchArray(PDO::FETCH_ASSOC)) {
    $users[] = $row;
}

// Helper for action icons
function getActionIcon($action) {
    return match($action) {
        'upload' => '&#8593;',
        'download' => '&#8595;',
        'delete' => '&#10005;',
        'edit', 'update', 'add_tag', 'remove_tag' => '&#9998;',
        'login' => '&#8594;',
        'logout' => '&#8592;',
        'favorite', 'unfavorite' => '&#9829;',
        'archive', 'unarchive' => '&#128451;',
        default => '&#8226;'
    };
}

// Helper for formatting time
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
            <div class="page-header">
                <h1>Activity Log</h1>
                <p><?= number_format($totalActivities) ?> activities recorded</p>
            </div>

            <div class="browse-controls">
                <div class="browse-filters">
                    <form method="get" role="search" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <select name="action_filter" class="sort-select" aria-label="Filter by action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $action): ?>
                            <option value="<?= htmlspecialchars($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>><?= ucfirst($action) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="user_filter" class="sort-select" aria-label="Filter by user">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $filterUser == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="entity_filter" class="sort-select" aria-label="Filter by type">
                            <option value="">All Types</option>
                            <?php foreach ($entityTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $filterEntity === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <?php if ($filterAction || $filterUser || $filterEntity): ?>
                        <a href="<?= route('admin.activity') ?>" class="btn btn-secondary btn-small">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php if (empty($activities)): ?>
                <p class="text-muted" style="text-align: center; padding: 3rem;">No activities recorded yet.</p>
            <?php else: ?>
                <div class="activity-list" style="background-color: var(--color-surface); border-radius: var(--radius-lg); padding: 1rem;">
                    <?php foreach ($activities as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-icon <?= htmlspecialchars($activity['action']) ?>">
                            <?= getActionIcon($activity['action']) ?>
                        </div>
                        <div class="activity-content">
                            <div class="activity-text">
                                <strong><?= htmlspecialchars($activity['username'] ?? 'Anonymous') ?></strong>
                                <?= htmlspecialchars($activity['action']) ?>
                                <?php if ($activity['entity_name']): ?>
                                <strong><?= htmlspecialchars($activity['entity_name']) ?></strong>
                                <?php endif; ?>
                                <?php if ($activity['entity_type']): ?>
                                (<?= htmlspecialchars($activity['entity_type']) ?>)
                                <?php endif; ?>
                                <?php if ($activity['details']): ?>
                                    <?php $details = json_decode($activity['details'], true); ?>
                                    <?php if ($details): ?>
                                    <span style="color: var(--color-text-muted);">
                                        <?php foreach ($details as $key => $value): ?>
                                        - <?= htmlspecialchars($key) ?>: <?= htmlspecialchars(is_string($value) ? $value : json_encode($value)) ?>
                                        <?php endforeach; ?>
                                    </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="activity-time">
                                <?= timeAgo($activity['created_at']) ?>
                                <?php if ($activity['ip_address']): ?>
                                <span style="margin-left: 0.5rem;"><?= htmlspecialchars($activity['ip_address']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($activity['entity_id'] && $activity['entity_type'] === 'model'): ?>
                        <a href="<?= route('model.show', ['id' => $activity['entity_id']]) ?>" class="btn btn-small btn-secondary" title="View">&#8594;</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination-btn" aria-label="Previous page">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="pagination-btn" aria-label="Page 1">1</a>
                <?php if ($startPage > 2): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>" aria-label="Page <?= $i ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="pagination-btn" aria-label="Page <?= $totalPages ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination-btn" aria-label="Next page">Next &raquo;</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
            </div><!-- /.admin-content -->
        </div><!-- /.admin-layout -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
