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
    $where[] = '(m.name LIKE :search OR m.description LIKE :search OR m.creator LIKE :search)';
    $params[':search'] = '%' . $search . '%';
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
                    <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." class="search-bar" style="width: auto;">

                    <select name="category" class="sort-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort" class="sort-select">
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
                        <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                        <span id="selected-count">0</span> selected
                    </label>
                </div>
                <div class="batch-actions-right">
                    <select id="bulk-tag" class="sort-select" style="max-width: 150px;" onchange="bulkAddTag(this.value)">
                        <option value="">Add Tag...</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="bulk-category" class="sort-select" style="max-width: 150px;" onchange="bulkAddCategory(this.value)">
                        <option value="">Add Category...</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="bulk-license" class="sort-select" style="max-width: 150px;" onchange="bulkSetLicense(this.value)">
                        <option value="">Set License...</option>
                        <?php foreach (getLicenseOptions() as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn btn-small" onclick="bulkArchive(true)">Archive</button>
                    <button type="button" class="btn btn-small" onclick="bulkArchive(false)">Unarchive</button>
                    <button type="button" class="btn btn-small btn-danger" onclick="bulkDelete()">Delete</button>
                </div>
            </div>

            <!-- Models Table -->
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="header-select-all" onchange="toggleSelectAll(this)">
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
                                <input type="checkbox" class="model-checkbox" value="<?= $model['id'] ?>" onchange="updateSelection()">
                            </td>
                            <td>
                                <a href="<?= route('model.show', ['id' => $model['id']]) ?>" target="_blank" rel="noopener">
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
                                <button type="button" class="btn btn-small btn-danger" onclick="deleteModel(<?= $model['id'] ?>, '<?= htmlspecialchars(addslashes($model['name'])) ?>')">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination" style="margin-top: 1.5rem;">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination-btn">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="pagination-btn">1</a>
                <?php if ($startPage > 2): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="pagination-btn"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination-btn">Next &raquo;</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
            </div><!-- /.admin-content -->
        </div><!-- /.admin-layout -->

        <script>
        function toggleSelectAll(checkbox) {
            document.querySelectorAll('.model-checkbox').forEach(cb => {
                cb.checked = checkbox.checked;
            });
            document.getElementById('header-select-all').checked = checkbox.checked;
            document.getElementById('select-all').checked = checkbox.checked;
            updateSelection();
        }

        function updateSelection() {
            const checked = document.querySelectorAll('.model-checkbox:checked');
            const countEl = document.getElementById('selected-count');
            const bar = document.getElementById('bulk-actions-bar');

            countEl.textContent = checked.length;
            bar.style.display = checked.length > 0 ? 'flex' : 'none';

            // Sync header checkbox
            const allCheckboxes = document.querySelectorAll('.model-checkbox');
            const headerCheckbox = document.getElementById('header-select-all');
            const selectAllCheckbox = document.getElementById('select-all');

            if (checked.length === allCheckboxes.length && allCheckboxes.length > 0) {
                headerCheckbox.checked = true;
                headerCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else if (checked.length > 0) {
                headerCheckbox.indeterminate = true;
                selectAllCheckbox.indeterminate = true;
            } else {
                headerCheckbox.checked = false;
                headerCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            }
        }

        function getSelectedIds() {
            return Array.from(document.querySelectorAll('.model-checkbox:checked')).map(cb => cb.value);
        }

        async function bulkAddTag(tagId) {
            const select = document.getElementById('bulk-tag');
            if (!tagId) return;

            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('Select models first', 'error');
                select.value = '';
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'add_tag');
                formData.append('tag_id', tagId);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Tagged ${result.updated} model(s)`, 'success');
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed', 'error');
            }
            select.value = '';
        }

        async function bulkAddCategory(categoryId) {
            const select = document.getElementById('bulk-category');
            if (!categoryId) return;

            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('Select models first', 'error');
                select.value = '';
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'add_category');
                formData.append('category_id', categoryId);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Added category to ${result.updated} model(s)`, 'success');
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed', 'error');
            }
            select.value = '';
        }

        async function bulkSetLicense(license) {
            const select = document.getElementById('bulk-license');
            if (license === '') return;

            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('Select models first', 'error');
                select.value = '';
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'set_license');
                formData.append('license', license);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Set license on ${result.updated} model(s)`, 'success');
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed', 'error');
            }
            select.value = '';
        }

        async function bulkArchive(archive) {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('Select models first', 'error');
                return;
            }

            const action = archive ? 'archive' : 'unarchive';
            if (!await showConfirm(`${archive ? 'Archive' : 'Unarchive'} ${ids.length} model(s)?`)) return;

            try {
                const formData = new FormData();
                formData.append('action', 'archive');
                formData.append('archive', archive ? '1' : '0');
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`${archive ? 'Archived' : 'Unarchived'} ${result.updated} model(s)`, 'success');
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed', 'error');
            }
        }

        async function bulkDelete() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('Select models first', 'error');
                return;
            }

            if (!await showConfirm(`DELETE ${ids.length} model(s)? This cannot be undone!`)) return;
            if (!await showConfirm(`Are you sure? All files and data will be permanently deleted.`)) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Deleted ${result.deleted} model(s)`, 'success');
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed', 'error');
            }
        }

        async function deleteModel(id, name) {
            if (!await showConfirm(`Delete "${name}"? This cannot be undone!`)) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('model_ids[]', id);

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed', 'error');
            }
        }
        </script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
