<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/features.php';

$pageTitle = 'Browse Models';
$activePage = 'browse';

$db = getDB();

// Get filter parameters
$search = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$tagId = (int)($_GET['tag'] ?? 0);
$sort = $_GET['sort'] ?? getSetting('default_sort', 'newest');
$view = $_GET['view'] ?? ($_COOKIE['silo_view'] ?? getSetting('default_view', 'grid'));
$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)getSetting('models_per_page', 20);
$offset = ($page - 1) * $perPage;

// Save view preference
if (isset($_GET['view'])) {
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('silo_view', $view, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

// Build query
$where = ['m.parent_id IS NULL'];
$params = [];

// Search filter (includes model name, description, creator, and part names)
if ($search !== '') {
    $where[] = '(m.name LIKE :search1 OR m.description LIKE :search2 OR m.creator LIKE :search3 OR EXISTS (SELECT 1 FROM models p WHERE p.parent_id = m.id AND p.name LIKE :search4))';
    $searchTerm = '%' . $search . '%';
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
}

// Category filter
if ($categoryId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id AND mc.category_id = :category_id)';
    $params[':category_id'] = $categoryId;
}

// Tag filter
if ($tagId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM model_tags mt WHERE mt.model_id = m.id AND mt.tag_id = :tag_id)';
    $params[':tag_id'] = $tagId;
}

// Archive filter
if (!$showArchived) {
    $where[] = '(m.is_archived = 0 OR m.is_archived IS NULL)';
}

$whereClause = implode(' AND ', $where);

if (class_exists('PluginManager')) {
    $whereClause = PluginManager::applyFilter('browse_query_where', $whereClause, $params);
}

// Sort options
$orderBy = match($sort) {
    'oldest' => 'm.created_at ASC',
    'name' => 'm.name ASC',
    'name_desc' => 'm.name DESC',
    'size' => 'm.file_size DESC',
    'size_asc' => 'm.file_size ASC',
    'parts' => 'm.part_count DESC',
    'downloads' => 'm.download_count DESC',
    default => 'm.created_at DESC' // newest
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

// Get models - optimized to only select columns we display
$sql = "SELECT m.id, m.name, m.description, m.creator, m.file_path, m.file_size, m.file_type,
               m.dedup_path, m.part_count, m.download_count, m.created_at, m.is_archived, m.thumbnail_path
        FROM models m
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$result = $stmt->execute();

$models = [];
$modelIds = [];
$multiPartModelIds = [];

// First pass: collect model data and identify multi-part models
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $row['tags'] = [];
    $row['preview_path'] = '/preview?id=' . $row['id'];
    $row['preview_type'] = $row['file_type'];
    $row['preview_file_size'] = $row['file_size'] ?? 0;

    if ($row['part_count'] > 0) {
        $multiPartModelIds[] = $row['id'];
    }

    $modelIds[] = $row['id'];
    $models[$row['id']] = $row;
}

// Batch load first parts for all multi-part models in a single query (fixes N+1)
if (!empty($multiPartModelIds)) {
    $placeholders = implode(',', array_fill(0, count($multiPartModelIds), '?'));
    $firstPartsSql = "SELECT m.parent_id, m.id, m.file_path, m.file_type, m.file_size, m.dedup_path
                      FROM models m
                      INNER JOIN (
                          SELECT parent_id, MIN(original_path) as min_path
                          FROM models
                          WHERE parent_id IN ($placeholders)
                          GROUP BY parent_id
                      ) first ON m.parent_id = first.parent_id AND m.original_path = first.min_path";
    $firstPartsStmt = $db->prepare($firstPartsSql);
    foreach ($multiPartModelIds as $index => $parentId) {
        $firstPartsStmt->bindValue($index + 1, $parentId, PDO::PARAM_INT);
    }
    $firstPartsResult = $firstPartsStmt->execute();

    while ($firstPart = $firstPartsResult->fetchArray(PDO::FETCH_ASSOC)) {
        $parentId = $firstPart['parent_id'];
        if (isset($models[$parentId])) {
            $models[$parentId]['preview_path'] = '/preview?id=' . $firstPart['id'];
            $models[$parentId]['preview_type'] = $firstPart['file_type'];
            $models[$parentId]['preview_file_size'] = $firstPart['file_size'] ?? 0;
        }
    }
}

// Convert back to indexed array for iteration
$models = array_values($models);

// Fetch all tags for all models in one query (fixes N+1 problem)
if (!empty($modelIds)) {
    $tagsByModel = getTagsForModels($modelIds);

    // Assign tags to models
    foreach ($models as &$model) {
        if (isset($tagsByModel[$model['id']])) {
            $model['tags'] = $tagsByModel[$model['id']];
        }
    }
    unset($model); // Break reference
}

// Get categories for filter dropdown
$categories = [];
$catResult = $db->query('SELECT c.*, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
while ($row = $catResult->fetchArray(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
}

// Get tags for filter dropdown
$tags = getAllTags();

// Get active filter names for display
$activeCategory = null;
if ($categoryId > 0) {
    $catStmt = $db->prepare('SELECT name FROM categories WHERE id = :id');
    $catStmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $catStmt->execute();
    $activeCategory = $catStmt->fetchColumn();
}

$activeTag = null;
if ($tagId > 0) {
    $tagStmt = $db->prepare('SELECT name, color FROM tags WHERE id = :id');
    $tagStmt->bindValue(':id', $tagId, PDO::PARAM_INT);
    $tagStmt->execute();
    $activeTag = $tagStmt->fetch();
}

// formatBytes is defined in includes/helpers.php

// Build URL helper for pagination/sorting
function buildUrl($params = []) {
    $current = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }
    return '?' . http_build_query($current);
}

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>
                    <?php if ($search): ?>
                        Search: "<?= htmlspecialchars($search) ?>"
                    <?php elseif ($activeCategory): ?>
                        Category: <?= htmlspecialchars($activeCategory) ?>
                    <?php elseif ($activeTag): ?>
                        Tag: <?= htmlspecialchars($activeTag['name']) ?>
                    <?php else: ?>
                        All Models
                    <?php endif; ?>
                </h1>
                <p><?= number_format($totalModels) ?> model<?= $totalModels !== 1 ? 's' : '' ?> found</p>
            </div>

            <?php if (isLoggedIn()): ?>
            <!-- Floating Batch Actions Bar -->
            <div id="batch-actions-bar" class="floating-batch-bar" style="display: none;">
                <div class="batch-actions-left">
                    <label class="batch-select-all">
                        <input type="checkbox" id="select-all-models" onchange="toggleSelectAllModels(this)">
                        <span id="selected-count">0</span> selected
                    </label>
                    <button type="button" class="btn btn-small btn-ghost" onclick="clearSelection()" title="Clear selection (Esc)">Clear</button>
                </div>
                <div class="batch-actions-right">
                    <button type="button" class="btn btn-small" onclick="batchDownload()" title="Download selected as ZIP">Download</button>
                    <select id="batch-tag-select" class="batch-select" onchange="batchApplyTag(this.value)">
                        <option value="">+ Tag</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">Create New...</option>
                    </select>
                    <select id="batch-category-select" class="batch-select" onchange="batchApplyCategory(this.value)">
                        <option value="">+ Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-small" onclick="batchSetCreator()" title="Set creator for selected">Set Creator</button>
                    <button type="button" class="btn btn-small" onclick="batchSetCollection()" title="Set collection for selected">Set Collection</button>
                    <button type="button" class="btn btn-small" onclick="batchExport()" title="Export selected with metadata">Export</button>
                    <button type="button" class="btn btn-small btn-warning" onclick="batchArchive()">Archive</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="browse-controls">
                <div class="browse-filters">
                    <?php if (isLoggedIn()): ?>
                    <label class="batch-mode-toggle" title="Enable batch selection">
                        <input type="checkbox" id="batch-mode-checkbox" onchange="toggleBatchMode(this.checked)">
                        Select
                    </label>
                    <?php endif; ?>

                    <select class="sort-select" onchange="location.href=this.value">
                        <option value="<?= buildUrl(['sort' => 'newest', 'page' => 1]) ?>" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="<?= buildUrl(['sort' => 'oldest', 'page' => 1]) ?>" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="<?= buildUrl(['sort' => 'name', 'page' => 1]) ?>" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="<?= buildUrl(['sort' => 'name_desc', 'page' => 1]) ?>" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="<?= buildUrl(['sort' => 'size', 'page' => 1]) ?>" <?= $sort === 'size' ? 'selected' : '' ?>>Largest First</option>
                        <option value="<?= buildUrl(['sort' => 'parts', 'page' => 1]) ?>" <?= $sort === 'parts' ? 'selected' : '' ?>>Most Parts</option>
                        <option value="<?= buildUrl(['sort' => 'downloads', 'page' => 1]) ?>" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most Downloads</option>
                        <?php if (class_exists('PluginManager')):
                            $pluginSortOptions = PluginManager::applyFilter('browse_sort_options', []);
                            foreach ($pluginSortOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= ($sort ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; endif; ?>
                    </select>

                    <?php if (isFeatureEnabled('categories') && !empty($categories)): ?>
                    <select class="sort-select" onchange="if(this.value) location.href=this.value">
                        <option value="">Filter by Category...</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= buildUrl(['category' => $cat['id'], 'page' => 1]) ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?> (<?= $cat['model_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('tags') && !empty($tags)): ?>
                    <select class="sort-select" onchange="if(this.value) location.href=this.value">
                        <option value="">Filter by Tag...</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= buildUrl(['tag' => $tag['id'], 'page' => 1]) ?>" <?= $tagId == $tag['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (class_exists('PluginManager')): ?>
                    <?= PluginManager::applyFilter('browse_filters', '') ?>
                    <?php endif; ?>

                    <?php if ($search || $categoryId || $tagId): ?>
                    <div class="active-filters">
                        <?php if ($search): ?>
                        <span class="active-filter">
                            Search: <?= htmlspecialchars($search) ?>
                            <a href="<?= buildUrl(['q' => null, 'page' => 1]) ?>" class="active-filter-remove">&times;</a>
                        </span>
                        <?php endif; ?>
                        <?php if (isFeatureEnabled('categories') && $activeCategory): ?>
                        <span class="active-filter">
                            <?= htmlspecialchars($activeCategory) ?>
                            <a href="<?= buildUrl(['category' => null, 'page' => 1]) ?>" class="active-filter-remove">&times;</a>
                        </span>
                        <?php endif; ?>
                        <?php if (isFeatureEnabled('tags') && $activeTag): ?>
                        <span class="active-filter" style="background-color: <?= htmlspecialchars($activeTag['color']) ?>">
                            <?= htmlspecialchars($activeTag['name']) ?>
                            <a href="<?= buildUrl(['tag' => null, 'page' => 1]) ?>" class="active-filter-remove">&times;</a>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="view-controls">
                    <div class="view-toggle">
                        <a href="<?= buildUrl(['view' => 'grid']) ?>" class="view-toggle-btn <?= $view === 'grid' ? 'active' : '' ?>" title="Grid view">&#9638;</a>
                        <a href="<?= buildUrl(['view' => 'list']) ?>" class="view-toggle-btn <?= $view === 'list' ? 'active' : '' ?>" title="List view">&#9776;</a>
                    </div>
                </div>
            </div>

            <?php if (empty($models)): ?>
                <p class="text-muted" style="text-align: center; padding: 3rem;">No models found. <?php if (!$search && !$categoryId && !$tagId): ?><a href="<?= route('upload') ?>">Upload your first model!</a><?php endif; ?></p>
            <?php elseif ($view === 'list'): ?>
                <div class="models-list">
                    <?php foreach ($models as $model): ?>
                    <article class="model-list-item <?= $model['is_archived'] ? 'archived' : '' ?>" data-model-id="<?= $model['id'] ?>" onclick="handleModelCardClick(event, <?= $model['id'] ?>)">
                        <?php if (isLoggedIn()): ?>
                        <label class="model-list-checkbox" onclick="event.stopPropagation()">
                            <input type="checkbox" class="model-checkbox" value="<?= $model['id'] ?>" onchange="updateBatchSelection()">
                        </label>
                        <?php endif; ?>
                        <div class="model-list-thumbnail"
                            <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path']) && ($model['preview_file_size'] ?? $model['file_size'] ?? 0) < 5242880): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if (!empty($model['thumbnail_path'])): ?>
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image">
                            <?php endif; ?>
                                                    </div>
                        <div class="model-list-info">
                            <h3 class="model-list-title"><?= htmlspecialchars($model['name']) ?></h3>
                            <?php if ($model['creator']): ?>
                            <p class="model-creator">by <?= htmlspecialchars($model['creator']) ?></p>
                            <?php endif; ?>
                            <div class="model-list-meta">
                                <span><?= formatBytes($model['file_size'] ?? 0) ?></span>
                                <?php if ($model['part_count'] > 0): ?>
                                <span><?= $model['part_count'] ?> parts</span>
                                <?php endif; ?>
                                <span><?= date('M j, Y', strtotime($model['created_at'])) ?></span>
                                <?php if (isFeatureEnabled('download_tracking') && $model['download_count'] > 0): ?>
                                <span class="download-count"><?= number_format($model['download_count']) ?> downloads</span>
                                <?php endif; ?>
                            </div>
                            <?php if (isFeatureEnabled('tags') && !empty($model['tags'])): ?>
                            <div class="model-tags" style="margin-top: 0.5rem; margin-bottom: 0;">
                                <?php foreach ($model['tags'] as $tag): ?>
                                <span class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>"><?= htmlspecialchars($tag['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="model-list-actions" onclick="event.stopPropagation()">
                            <?php if ($model['is_archived']): ?>
                            <span class="archived-badge">Archived</span>
                            <?php endif; ?>
                        </div>
                        <?php if (class_exists('PluginManager')): ?>
                        <?= PluginManager::applyFilter('model_card_extra', '', $model) ?>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="models-grid">
                    <?php foreach ($models as $model): ?>
                    <article class="model-card <?= $model['is_archived'] ? 'archived' : '' ?>" data-model-id="<?= $model['id'] ?>" onclick="handleModelCardClick(event, <?= $model['id'] ?>)">
                        <div class="model-thumbnail"
                            <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path']) && ($model['preview_file_size'] ?? $model['file_size'] ?? 0) < 5242880): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if (!empty($model['thumbnail_path'])): ?>
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image">
                            <?php endif; ?>
                            <?php if (isLoggedIn()): ?>
                            <label class="model-select-checkbox" onclick="event.stopPropagation()">
                                <input type="checkbox" class="model-checkbox" value="<?= $model['id'] ?>" onchange="updateBatchSelection()">
                            </label>
                            <?php endif; ?>
                            <?php if ($model['part_count'] > 0): ?>
                            <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                            <?php endif; ?>
                                                        <?php if ($model['is_archived']): ?>
                            <span class="archived-badge" style="position: absolute; bottom: 0.5rem; left: 0.5rem;">Archived</span>
                            <?php endif; ?>
                        </div>
                        <div class="model-info">
                            <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
                            <p class="model-creator"><?= $model['creator'] ? 'by ' . htmlspecialchars($model['creator']) : '' ?></p>
                            <?php if (isFeatureEnabled('download_tracking') && $model['download_count'] > 0): ?>
                            <p class="download-count" style="margin-top: 0.25rem;"><?= number_format($model['download_count']) ?> downloads</p>
                            <?php endif; ?>
                        </div>
                        <?php if (class_exists('PluginManager')): ?>
                        <?= PluginManager::applyFilter('model_card_extra', '', $model) ?>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="pagination-btn">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1): ?>
                <a href="<?= buildUrl(['page' => 1]) ?>" class="pagination-btn">1</a>
                <?php if ($startPage > 2): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="<?= buildUrl(['page' => $i]) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="<?= buildUrl(['page' => $totalPages]) ?>" class="pagination-btn"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="pagination-btn">Next &raquo;</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>

        <script>
        let batchModeEnabled = false;
        let lastClickedIndex = -1;

        function handleModelCardClick(event, modelId) {
            if (batchModeEnabled) {
                // In batch mode, toggle the checkbox
                const checkbox = event.currentTarget.querySelector('.model-checkbox');
                if (checkbox && event.target !== checkbox) {
                    const allCards = Array.from(document.querySelectorAll('[data-model-id]'));
                    const currentIndex = allCards.findIndex(card => card.dataset.modelId === String(modelId));

                    // Shift+click for range selection
                    if (event.shiftKey && lastClickedIndex >= 0 && currentIndex >= 0) {
                        const start = Math.min(lastClickedIndex, currentIndex);
                        const end = Math.max(lastClickedIndex, currentIndex);
                        const shouldCheck = !checkbox.checked;

                        for (let i = start; i <= end; i++) {
                            const cb = allCards[i].querySelector('.model-checkbox');
                            if (cb) cb.checked = shouldCheck;
                        }
                    } else {
                        checkbox.checked = !checkbox.checked;
                    }

                    lastClickedIndex = currentIndex;
                    updateBatchSelection();
                }
            } else {
                // Normal mode, navigate to model
                window.location = 'model.php?id=' + modelId;
            }
        }

        function toggleBatchMode(enabled) {
            batchModeEnabled = enabled;
            document.body.classList.toggle('batch-mode', enabled);

            // Show floating bar only when items are selected
            if (!enabled) {
                document.getElementById('batch-actions-bar').style.display = 'none';
            }

            // Show/hide checkboxes
            document.querySelectorAll('.model-select-checkbox, .model-list-checkbox').forEach(el => {
                el.style.display = enabled ? 'block' : 'none';
            });

            if (!enabled) {
                // Uncheck all when disabling
                document.querySelectorAll('.model-checkbox').forEach(cb => cb.checked = false);
                updateBatchSelection();
                lastClickedIndex = -1;
            }
        }

        function toggleSelectAllModels(checkbox) {
            document.querySelectorAll('.model-checkbox').forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateBatchSelection();
        }

        function clearSelection() {
            document.querySelectorAll('.model-checkbox').forEach(cb => cb.checked = false);
            updateBatchSelection();
            lastClickedIndex = -1;
        }

        function updateBatchSelection() {
            const checked = document.querySelectorAll('.model-checkbox:checked');
            const countEl = document.getElementById('selected-count');
            const bar = document.getElementById('batch-actions-bar');

            if (countEl) countEl.textContent = checked.length;

            // Show/hide floating bar based on selection count
            if (bar && batchModeEnabled) {
                bar.style.display = checked.length > 0 ? 'flex' : 'none';
            }

            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.model-checkbox');
            const selectAllCheckbox = document.getElementById('select-all-models');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checked.length === allCheckboxes.length && allCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checked.length > 0 && checked.length < allCheckboxes.length;
            }
        }

        function getSelectedModelIds() {
            return Array.from(document.querySelectorAll('.model-checkbox:checked')).map(cb => cb.value);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Only handle shortcuts when batch mode is enabled
            if (!batchModeEnabled) return;

            // Ctrl/Cmd + A: Select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                document.querySelectorAll('.model-checkbox').forEach(cb => cb.checked = true);
                updateBatchSelection();
            }

            // Escape: Clear selection
            if (e.key === 'Escape') {
                e.preventDefault();
                clearSelection();
            }
        });


        function batchDownload() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                return;
            }
            window.location = 'actions/batch-download.php?ids=' + ids.join(',');
        }

        async function batchExport() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'export_selective');
                formData.append('model_ids', JSON.stringify(ids));

                const response = await fetch('/actions/export-import', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {
                    window.location = '/actions/export-download?token=' + result.download_token;
                } else {
                    alert('Export failed: ' + result.error);
                }
            } catch (err) {
                alert('Export failed: ' + err.message);
            }
        }

        async function batchApplyTag(tagId) {
            const select = document.getElementById('batch-tag-select');
            if (!tagId) {
                select.value = '';
                return;
            }

            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                select.value = '';
                return;
            }

            let tagName = '';
            if (tagId === '__new__') {
                tagName = prompt('Enter new tag name:');
                if (!tagName) {
                    select.value = '';
                    return;
                }
                tagId = '';
            }

            try {
                const formData = new FormData();
                formData.append('action', 'add_tag');
                if (tagId) formData.append('tag_id', tagId);
                if (tagName) formData.append('tag_name', tagName);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('actions/batch-apply.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert(`Tagged ${result.updated} model(s)`);
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Batch tag error:', err);
                alert('Failed to apply tag');
            }

            select.value = '';
        }

        async function batchApplyCategory(categoryId) {
            const select = document.getElementById('batch-category-select');
            if (!categoryId) {
                select.value = '';
                return;
            }

            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                select.value = '';
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'add_category');
                formData.append('category_id', categoryId);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('actions/batch-apply.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert(`Added category to ${result.updated} model(s)`);
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Batch category error:', err);
                alert('Failed to apply category');
            }

            select.value = '';
        }

        async function batchArchive() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                return;
            }

            if (!confirm(`Archive ${ids.length} selected model(s)?`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'archive');
                formData.append('archive', '1');
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('actions/batch-apply.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert(`Archived ${result.updated} model(s)`);
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Batch archive error:', err);
                alert('Failed to archive');
            }
        }

        async function batchSetCreator() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                return;
            }

            const creator = prompt(`Set creator for ${ids.length} selected model(s):\n(Leave empty to clear)`);
            if (creator === null) return; // Cancelled

            try {
                const formData = new FormData();
                formData.append('action', 'set_creator');
                formData.append('creator', creator);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('actions/batch-apply.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert(`Updated creator on ${result.updated} model(s)`);
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Batch set creator error:', err);
                alert('Failed to set creator');
            }
        }

        async function batchSetCollection() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                alert('Please select models first');
                return;
            }

            const collection = prompt(`Set collection for ${ids.length} selected model(s):\n(Leave empty to clear)`);
            if (collection === null) return; // Cancelled

            try {
                const formData = new FormData();
                formData.append('action', 'set_collection');
                formData.append('collection', collection);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('actions/batch-apply.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert(`Updated collection on ${result.updated} model(s)`);
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Batch set collection error:', err);
                alert('Failed to set collection');
            }
        }

        // Initialize checkbox visibility
        document.querySelectorAll('.model-select-checkbox, .model-list-checkbox').forEach(el => {
            el.style.display = 'none';
        });
        </script>

<?php require_once 'includes/footer.php'; ?>
