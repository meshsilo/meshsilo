<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/features.php';
require_once 'includes/SavedSearches.php';
require_once 'includes/BrowseQuery.php';

$pageTitle = 'Browse Models';
$activePage = 'browse';

// Get filter parameters
$search = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$tagIds = array_values(array_unique(array_filter(array_map('intval', (array)($_GET['tags'] ?? [])))));
if (empty($tagIds) && isset($_GET['tag']) && (int)$_GET['tag'] > 0) {
    $tagIds = [(int)$_GET['tag']];
}
$fileType = trim($_GET['file_type'] ?? '');
$printType = trim($_GET['print_type'] ?? '');
$collection = trim($_GET['collection'] ?? '');
$sort = $_GET['sort'] ?? getSetting('default_sort', 'newest');
$view = $_GET['view'] ?? ($_COOKIE['silo_view'] ?? getSetting('default_view', 'grid'));
$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)getSetting('models_per_page', 20);

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

// Execute query
$result = BrowseQuery::execute([
    'search' => $search,
    'categoryId' => $categoryId,
    'tagIds' => $tagIds,
    'fileType' => $fileType,
    'printType' => $printType,
    'collection' => $collection,
    'sort' => $sort,
    'page' => $page,
    'perPage' => $perPage,
    'showArchived' => $showArchived,
    'explicitSort' => isset($_GET['sort']),
]);

// Unpack results
$models = $result['models'];
$totalModels = $result['totalModels'];
$totalPages = $result['totalPages'];
$categories = $result['categories'];
$tags = $result['tags'];
$fileTypes = $result['fileTypes'];
$printTypes = $result['printTypes'];
$collections = $result['collections'];
$savedSearches = $result['savedSearches'];
$activeCategory = $result['activeCategory'];
$activeTags = $result['activeTags'];

// Per-page meta description based on active filters
if ($search !== '') {
    $metaDescription = 'Search results for "' . $search . '" — ' . number_format($totalModels) . ' 3D models on ' . SITE_NAME;
    $pageTitle = 'Search: ' . $search;
} elseif ($activeCategory) {
    $metaDescription = $activeCategory . ' category — ' . number_format($totalModels) . ' 3D models on ' . SITE_NAME;
} elseif (!empty($activeTags)) {
    $tagNames = implode(', ', array_column($activeTags, 'name'));
    $metaDescription = 'Models tagged ' . $tagNames . ' — ' . number_format($totalModels) . ' results on ' . SITE_NAME;
} elseif ($collection !== '') {
    $metaDescription = $collection . ' collection — ' . number_format($totalModels) . ' 3D models on ' . SITE_NAME;
} else {
    $metaDescription = 'Browse ' . number_format($totalModels) . ' 3D models — STL, 3MF, OBJ and more on ' . SITE_NAME;
}
$metaDescription = mb_substr($metaDescription, 0, 160);

$needsViewer = true;
$needsBrowsePageJs = true;
require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>
                    <?php if ($search): ?>
                        Search: "<?= htmlspecialchars($search) ?>"
                    <?php elseif ($activeCategory): ?>
                        Category: <?= htmlspecialchars($activeCategory) ?>
                    <?php elseif (!empty($activeTags)): ?>
                        Tag: <?= htmlspecialchars($activeTags[0]['name']) ?>
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
                        <input type="checkbox" id="select-all-models">
                        <span id="selected-count">0</span> selected
                    </label>
                    <button type="button" class="btn btn-small btn-ghost" data-action="clear-selection" title="Clear selection (Esc)">Clear</button>
                </div>
                <div class="batch-actions-right">
                    <button type="button" class="btn btn-small" data-action="batch-download" title="Download selected as ZIP">Download</button>
                    <select id="batch-tag-select" class="batch-select" aria-label="Add tag to selected">
                        <option value="">+ Tag</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">Create New...</option>
                    </select>
                    <select id="batch-category-select" class="batch-select" aria-label="Add category to selected">
                        <option value="">+ Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-small" data-action="batch-set-creator" title="Set creator for selected">Set Creator</button>
                    <button type="button" class="btn btn-small" data-action="batch-set-collection" title="Set collection for selected">Set Collection</button>
                    <button type="button" class="btn btn-small btn-warning" data-action="batch-archive">Archive</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="browse-controls">
                <!-- Row 1: batch toggle, sort, view toggle -->
                <div class="browse-filters">
                    <?php if (isLoggedIn()): ?>
                    <label class="batch-mode-toggle" title="Enable batch selection">
                        <input type="checkbox" id="batch-mode-checkbox">
                        Select
                    </label>
                    <?php endif; ?>

                    <select class="sort-select" data-navigate aria-label="Sort by">
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'newest', 'page' => 1]) ?>" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'oldest', 'page' => 1]) ?>" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'updated', 'page' => 1]) ?>" <?= $sort === 'updated' ? 'selected' : '' ?>>Recently Updated</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'name', 'page' => 1]) ?>" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'name_desc', 'page' => 1]) ?>" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'size', 'page' => 1]) ?>" <?= $sort === 'size' ? 'selected' : '' ?>>Largest First</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'parts', 'page' => 1]) ?>" <?= $sort === 'parts' ? 'selected' : '' ?>>Most Parts</option>
                        <option value="<?= BrowseQuery::buildUrl(['sort' => 'downloads', 'page' => 1]) ?>" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most Downloads</option>
                        <?php if (class_exists('PluginManager')):
                            $pluginSortOptions = PluginManager::applyFilter('browse_sort_options', []);
                            foreach ($pluginSortOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= ($sort ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; endif; ?>
                    </select>

                    <div class="view-toggle">
                        <a href="<?= BrowseQuery::buildUrl(['view' => 'grid']) ?>" class="view-toggle-btn <?= $view === 'grid' ? 'active' : '' ?>" title="Grid view">&#9638;</a>
                        <a href="<?= BrowseQuery::buildUrl(['view' => 'list']) ?>" class="view-toggle-btn <?= $view === 'list' ? 'active' : '' ?>" title="List view">&#9776;</a>
                    </div>
                </div>

                <!-- Row 2: inline filter bar with compact add-filter dropdowns and active chips -->
                <div class="filter-bar">
                    <?php if (isFeatureEnabled('categories') && !empty($categories)): ?>
                    <select class="filter-select" aria-label="Filter by category" data-navigate title="Filter by category">
                        <option value="">+ Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= BrowseQuery::buildUrl(['category' => $cat['id'], 'page' => 1]) ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?> (<?= $cat['model_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('tags') && !empty($tags)): ?>
                    <?php $unselectedTags = array_filter($tags, fn($t) => !in_array($t['id'], $tagIds)); ?>
                    <?php if (!empty($unselectedTags)): ?>
                    <select class="filter-select" aria-label="Filter by tag" data-navigate title="Filter by tag">
                        <option value="">+ Tag</option>
                        <?php foreach ($unselectedTags as $tag): ?>
                        <option value="<?= BrowseQuery::buildUrl(['tags' => array_merge($tagIds, [$tag['id']]), 'page' => 1]) ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($fileTypes)): ?>
                    <select class="filter-select" aria-label="Filter by file type" data-navigate title="Filter by file type">
                        <option value="">+ File Type</option>
                        <?php foreach ($fileTypes as $ft): ?>
                        <option value="<?= BrowseQuery::buildUrl(['file_type' => $ft, 'page' => 1]) ?>" <?= $fileType === $ft ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($ft)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($printTypes)): ?>
                    <select class="filter-select" aria-label="Filter by print type" data-navigate title="Filter by print type">
                        <option value="">+ Print Type</option>
                        <?php foreach ($printTypes as $pt): ?>
                        <option value="<?= BrowseQuery::buildUrl(['print_type' => $pt, 'page' => 1]) ?>" <?= $printType === $pt ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($pt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($collections)): ?>
                    <select class="filter-select" aria-label="Filter by collection" data-navigate title="Filter by collection">
                        <option value="">+ Collection</option>
                        <?php foreach ($collections as $coll): ?>
                        <option value="<?= BrowseQuery::buildUrl(['collection' => $coll, 'page' => 1]) ?>" <?= $collection === $coll ? 'selected' : '' ?>><?= htmlspecialchars($coll) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (class_exists('PluginManager')): ?>
                    <?= PluginManager::applyFilter('browse_filters', '') ?>
                    <?php endif; ?>

                    <?php if (isLoggedIn()): ?>
                    <?php if (!empty($savedSearches)): ?>
                    <select class="filter-select" aria-label="Load saved search" data-navigate title="Load saved search">
                        <option value="">Saved Searches</option>
                        <?php foreach ($savedSearches as $ss): ?>
                        <option value="<?= htmlspecialchars(SavedSearches::toUrl($ss)) ?>"><?= htmlspecialchars($ss['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php $hasFilters = $search !== '' || $categoryId > 0 || !empty($tagIds) || $fileType !== '' || $printType !== '' || $collection !== ''; ?>
                    <?php if ($hasFilters): ?>
                    <button type="button" class="btn btn-small btn-secondary" data-action="save-search" title="Save current search">Save Search</button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Active filter chips -->
                    <?php if ($search): ?>
                    <span class="filter-chip">
                        Search: <?= htmlspecialchars($search) ?>
                        <a href="<?= BrowseQuery::buildUrl(['q' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove search filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('categories') && $activeCategory): ?>
                    <span class="filter-chip">
                        <?= htmlspecialchars($activeCategory) ?>
                        <a href="<?= BrowseQuery::buildUrl(['category' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove category filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('tags')): ?>
                        <?php foreach ($activeTags as $activeTag): ?>
                        <span class="filter-chip" style="background-color: <?= htmlspecialchars($activeTag['color']) ?>">
                            <?= htmlspecialchars($activeTag['name']) ?>
                            <a href="<?= BrowseQuery::buildUrlWithoutTag($activeTag['id']) ?>" class="filter-chip-remove" aria-label="Remove tag filter">&times;</a>
                        </span>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($fileType !== ''): ?>
                    <span class="filter-chip">
                        Type: <?= htmlspecialchars(strtoupper($fileType)) ?>
                        <a href="<?= BrowseQuery::buildUrl(['file_type' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove file type filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if ($printType !== ''): ?>
                    <span class="filter-chip">
                        Print: <?= htmlspecialchars(strtoupper($printType)) ?>
                        <a href="<?= BrowseQuery::buildUrl(['print_type' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove print type filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if ($collection !== ''): ?>
                    <span class="filter-chip">
                        Collection: <?= htmlspecialchars($collection) ?>
                        <a href="<?= BrowseQuery::buildUrl(['collection' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove collection filter">&times;</a>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($models)): ?>
                <p class="text-muted empty-state-msg">No models found. <?php if (!$search && !$categoryId && empty($tagIds) && $fileType === ''): ?><a href="<?= route('upload') ?>">Upload your first model!</a><?php endif; ?></p>
            <?php elseif ($view === 'list'): ?>
                <div class="models-list">
                    <?php foreach ($models as $model): ?>
                    <article class="model-list-item <?= $model['is_archived'] ? 'archived' : '' ?>" data-model-id="<?= $model['id'] ?>" tabindex="0" role="link" aria-label="<?= htmlspecialchars($model['name']) ?>">
                        <?php if (isLoggedIn()): ?>
                        <label class="model-list-checkbox">
                            <input type="checkbox" class="model-checkbox" value="<?= $model['id'] ?>" aria-label="Select <?= htmlspecialchars($model['name']) ?>">
                        </label>
                        <?php endif; ?>
                        <div class="model-list-thumbnail"
                            <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path']) && ($model['preview_file_size'] ?? $model['file_size'] ?? 0) < 5242880): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if (!empty($model['thumbnail_path'])): ?>
                            <?php $thumbSrcset = function_exists('image_srcset') ? image_srcset('storage/assets/' . $model['thumbnail_path'], [280, 560]) : ''; ?>
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" loading="lazy" decoding="async"<?= $thumbSrcset ? ' srcset="' . htmlspecialchars($thumbSrcset) . '" sizes="(min-width: 280px) 280px, 100vw"' : '' ?>>
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
                                <time class="model-date" datetime="<?= htmlspecialchars(date('c', strtotime($model['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($model['created_at']) ?>"><?= date('M j, Y', strtotime($model['created_at'])) ?></time>
                                <?php if (isFeatureEnabled('download_tracking') && $model['download_count'] > 0): ?>
                                <span class="download-count"><?= number_format($model['download_count']) ?> downloads</span>
                                <?php endif; ?>
                            </div>
                            <?php if (isFeatureEnabled('tags') && !empty($model['tags'])): ?>
                            <div class="model-tags mt-2 mb-0">
                                <?php foreach ($model['tags'] as $tag): ?>
                                <span class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>"><?= htmlspecialchars($tag['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="model-list-actions">
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
                    <?php $cardOptions = [
                        'archivedClass'   => true,
                        'batchCheckbox'   => true,
                        'downloadCount'   => true,
                        'pluginExtra'     => true,
                        'fileSizeLimit'   => true,
                        'customOnClick'   => false,
                        'customOnKeydown' => false,
                    ]; include 'includes/partials/model-card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                <a href="<?= BrowseQuery::buildUrl(['page' => $page - 1]) ?>" class="pagination-btn" aria-label="Previous page">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1): ?>
                <a href="<?= BrowseQuery::buildUrl(['page' => 1]) ?>" class="pagination-btn" aria-label="Page 1">1</a>
                <?php if ($startPage > 2): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="<?= BrowseQuery::buildUrl(['page' => $i]) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>" aria-label="Page <?= $i ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="<?= BrowseQuery::buildUrl(['page' => $totalPages]) ?>" class="pagination-btn" aria-label="Page <?= $totalPages ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a href="<?= BrowseQuery::buildUrl(['page' => $page + 1]) ?>" class="pagination-btn" aria-label="Next page">Next &raquo;</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>

        <script>
        window.BrowsePageConfig = { csrfToken: '<?= Csrf::getToken() ?>' };
        </script>

<?php require_once 'includes/footer.php'; ?>
