<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

// Require admin permission (bulk model management is admin-only)
if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to access bulk model management.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Model Management';
$activePage = 'admin';
$adminPage = 'models';
$db = getDB();
$user = getCurrentUser();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filters
$search = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$sort = $_GET['sort'] ?? 'newest';

// Build query
$where = ['m.parent_id IS NULL'];
$params = [];

if ($search) {
    $where[] = '(m.name LIKE :search1 OR m.description LIKE :search2 OR m.creator LIKE :search3)';
    $params[':search1'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
}

if ($categoryId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id AND mc.category_id = :category_id)';
    $params[':category_id'] = $categoryId;
}

if ($showArchived) {
    $where[] = 'm.is_archived = 1';
} else {
    $where[] = '(m.is_archived = 0 OR m.is_archived IS NULL)';
}

$whereClause = implode(' AND ', $where);

$orderBy = match($sort) {
    'oldest' => 'm.created_at ASC',
    'name' => 'm.name ASC',
    'size' => 'm.file_size DESC',
    'downloads' => 'm.download_count DESC',
    default => 'm.created_at DESC'
};

// Get total count
$countSql = "SELECT COUNT(*) FROM models m WHERE $whereClause";
$countStmt = $db->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalModels = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalModels / $perPage);

// Get models
$sql = "SELECT m.*, u.username as uploader FROM models m LEFT JOIN users u ON m.user_id = u.id WHERE $whereClause ORDER BY $orderBy LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$result = $stmt->execute();

$models = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $models[] = $row;
}

// Get categories for filter
$categories = [];
$catResult = $db->query('SELECT * FROM categories ORDER BY name');
while ($row = $catResult->fetchArray(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
}

// Get all tags for batch operations
$tags = getAllTags();

// formatBytes is defined in includes/helpers.php

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
            <div class="page-header">
                <h1>Model Management</h1>
                <p><?= number_format($totalModels) ?> model<?= $totalModels !== 1 ? 's' : '' ?></p>
            </div>

            <!-- Filters -->
            <div class="admin-filters" style="display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; align-items: center;">
                <form method="get" role="search" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                    <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." class="search-bar" style="width: auto;" aria-label="Search models" enterkeyhint="search">

                    <select name="category" class="sort-select" aria-label="Filter by category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort" class="sort-select" aria-label="Sort order">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                        <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>Size</option>
                        <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Downloads</option>
                    </select>

                    <label style="display: flex; align-items: center; gap: 0.25rem;">
                        <input type="checkbox" name="archived" value="1" <?= $showArchived ? 'checked' : '' ?>>
                        Archived Only
                    </label>

                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
            </div>

            <!-- Bulk Actions Bar -->
            <div id="bulk-actions-bar" class="batch-actions-bar" style="display: none;">
                <div class="batch-actions-left">
                    <label class="batch-select-all">
                        <input type="checkbox" id="select-all">
                        <span id="selected-count">0</span> selected
                    </label>
                </div>
                <div class="batch-actions-right">
                    <select id="bulk-tag" class="sort-select" style="max-width: 150px;" aria-label="Add tag to selected">
                        <option value="">Add Tag...</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="bulk-category" class="sort-select" style="max-width: 150px;" aria-label="Add category to selected">
                        <option value="">Add Category...</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="bulk-license" class="sort-select" style="max-width: 150px;" aria-label="Set license for selected">
                        <option value="">Set License...</option>
                        <?php foreach (getLicenseOptions() as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn btn-small" data-action="bulk-archive" data-archive="1">Archive</button>
                    <button type="button" class="btn btn-small" data-action="bulk-archive" data-archive="0">Unarchive</button>
                    <button type="button" class="btn btn-small btn-danger" data-action="bulk-delete">Delete</button>
                </div>
            </div>

            <!-- Models Table -->
            <div class="admin-table-container">
                <table class="admin-table" aria-label="Models">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 40px;">
                                <input type="checkbox" id="header-select-all" aria-label="Select all models">
                            </th>
                            <th scope="col">Model</th>
                            <th scope="col">Uploader</th>
                            <th scope="col">Size</th>
                            <th scope="col">Parts</th>
                            <th scope="col">Downloads</th>
                            <th scope="col">Created</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($models as $model): ?>
                        <tr data-model-id="<?= $model['id'] ?>">
                            <td>
                                <input type="checkbox" class="model-checkbox" value="<?= $model['id'] ?>" aria-label="Select <?= htmlspecialchars($model['name']) ?>">
                            </td>
                            <td>
                                <a href="<?= route('model.show', ['id' => $model['id']]) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($model['name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($model['uploader'] ?? 'Unknown') ?></td>
                            <td><?= formatBytes($model['file_size'] ?? 0) ?></td>
                            <td><?= $model['part_count'] ?: '-' ?></td>
                            <td><?= number_format($model['download_count'] ?? 0) ?></td>
                            <td><?= date('M j, Y', strtotime($model['created_at'])) ?></td>
                            <td>
                                <?php if ($model['is_archived']): ?>
                                <span class="archived-badge">Archived</span>
                                <?php else: ?>
                                <span style="color: var(--color-success);">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= route('model.edit', ['id' => $model['id']]) ?>" class="btn btn-small">Edit</a>
                                <button type="button" class="btn btn-small btn-danger" data-action="delete-model" data-model-id="<?= $model['id'] ?>" data-model-name="<?= htmlspecialchars($model['name']) ?>">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination" style="margin-top: 1.5rem;">
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
