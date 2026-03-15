<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/features.php';
require_once 'includes/SavedSearches.php';

$pageTitle = 'Browse Models';
$activePage = 'browse';

$db = getDB();

// Detect FTS availability for ranked full-text search
$dbType = $db->getType();
$ftsAvailable = false;
if ($dbType === 'sqlite') {
    $ftsAvailable = tableExists($db, 'models_fts');
} elseif ($dbType === 'mysql') {
    try {
        $r = $db->query("SHOW INDEX FROM models WHERE Key_name = 'idx_models_fulltext'");
        $ftsAvailable = ($r !== false && $r->fetch() !== false);
    } catch (Exception $e) {
        $ftsAvailable = false;
    }
}

// Get filter parameters
$search = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
// Multi-tag: accept tags[] array; also accept legacy ?tag=N single-tag param
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
$ftsActive = false;
$ftsJoin = '';        // Extra JOIN for main query only (provides rank column)
$ftsJoinParams = [];  // Params bound only to main query (not count query)
$ftsOrderBy = null;   // Relevance ordering — overrides $orderBy when FTS active

// Search filter: FTS5/FULLTEXT with LIKE fallback, searches parents and parts
if ($search !== '') {
    if ($ftsAvailable && $dbType === 'sqlite') {
        $ftsActive = true;
        // Build prefix query for partial word matching: "drag" → "drag*"
        // Strip FTS5 special chars (AND, OR, NOT, NEAR, ^, ") to avoid syntax errors
        $ftsWords = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        $ftsWords = array_values(array_filter(array_map(fn($w) => preg_replace('/[^\w\x80-\xff]/u', '', $w), $ftsWords)));
        $ftsQuery = implode(' ', array_map(fn($w) => $w . '*', $ftsWords));
        if (empty($ftsWords)) {
            // All words were FTS special chars — fall through to LIKE
            $ftsActive = false;
        } else {
            $params[':fts_query'] = $ftsQuery;
            $params[':fts_query_parts'] = $ftsQuery;
            $ftsSearchTerm = '%' . $search . '%';
            $params[':fts_tag_search'] = $ftsSearchTerm;
            $params[':fts_cat_search'] = $ftsSearchTerm;
            // Match parent models directly, via parts, or via tag/category names
            $where[] = "(m.id IN (SELECT rowid FROM models_fts WHERE models_fts MATCH :fts_query)
                OR m.id IN (SELECT p.parent_id FROM models p WHERE p.parent_id IS NOT NULL AND p.id IN (SELECT rowid FROM models_fts WHERE models_fts MATCH :fts_query_parts))
                OR EXISTS (SELECT 1 FROM model_tags mt2 JOIN tags t2 ON mt2.tag_id = t2.id WHERE mt2.model_id = m.id AND t2.name LIKE :fts_tag_search)
                OR EXISTS (SELECT 1 FROM model_categories mc2 JOIN categories c2 ON mc2.category_id = c2.id WHERE mc2.model_id = m.id AND c2.name LIKE :fts_cat_search))";
            // Main query only: LEFT JOIN to get rank column for parent-level relevance
            $ftsJoin = "LEFT JOIN (SELECT rowid, rank FROM models_fts WHERE models_fts MATCH :fts_query2) fts_r ON fts_r.rowid = m.id";
            $ftsJoinParams = [':fts_query2' => $ftsQuery];
            // Use relevance ordering when no explicit sort selected (FTS5 rank is negative: lower = better)
            if (!isset($_GET['sort'])) {
                $ftsOrderBy = 'COALESCE(fts_r.rank, 1) ASC, m.created_at DESC';
            }
        }
    } elseif ($ftsAvailable && $dbType === 'mysql') {
        $ftsActive = true;
        $params[':fts_query'] = $search;
        $mysqlFtsExpr = 'MATCH(m.name, m.description, m.creator) AGAINST(:fts_query IN NATURAL LANGUAGE MODE)';
        $partSubquery = 'EXISTS (SELECT 1 FROM models p WHERE p.parent_id = m.id AND MATCH(p.name, p.description, p.creator) AGAINST(:fts_query_part IN NATURAL LANGUAGE MODE))';
        $params[':fts_query_part'] = $search;
        $mysqlSearchTerm = '%' . $search . '%';
        $tagSubquery = 'EXISTS (SELECT 1 FROM model_tags mt2 JOIN tags t2 ON mt2.tag_id = t2.id WHERE mt2.model_id = m.id AND t2.name LIKE :mysql_tag_search)';
        $catSubquery = 'EXISTS (SELECT 1 FROM model_categories mc2 JOIN categories c2 ON mc2.category_id = c2.id WHERE mc2.model_id = m.id AND c2.name LIKE :mysql_cat_search)';
        $params[':mysql_tag_search'] = $mysqlSearchTerm;
        $params[':mysql_cat_search'] = $mysqlSearchTerm;
        $where[] = "($mysqlFtsExpr OR $partSubquery OR $tagSubquery OR $catSubquery)";
        if (!isset($_GET['sort'])) {
            $params[':fts_sort'] = $search;
            $ftsOrderBy = 'MATCH(m.name, m.description, m.creator) AGAINST(:fts_sort IN NATURAL LANGUAGE MODE) DESC';
        }
    }

    if (!$ftsActive) {
        // LIKE fallback (FTS unavailable) — searches name, description, creator, notes, and part names
        $searchTerm = '%' . $search . '%';
        $partSubquery = 'EXISTS (SELECT 1 FROM models p WHERE p.parent_id = m.id AND (p.name LIKE :part_search1 OR p.notes LIKE :part_search2))';
        $tagSubquery = 'EXISTS (SELECT 1 FROM model_tags mt2 JOIN tags t2 ON mt2.tag_id = t2.id WHERE mt2.model_id = m.id AND t2.name LIKE :tag_search)';
        $catSubquery = 'EXISTS (SELECT 1 FROM model_categories mc2 JOIN categories c2 ON mc2.category_id = c2.id WHERE mc2.model_id = m.id AND c2.name LIKE :cat_search)';
        $where[] = "(m.name LIKE :search1 OR m.description LIKE :search2 OR m.creator LIKE :search3 OR m.notes LIKE :search4 OR $partSubquery OR $tagSubquery OR $catSubquery)";
        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
        $params[':search3'] = $searchTerm;
        $params[':search4'] = $searchTerm;
        $params[':part_search1'] = $searchTerm;
        $params[':part_search2'] = $searchTerm;
        $params[':tag_search'] = $searchTerm;
        $params[':cat_search'] = $searchTerm;
    }
}

// Category filter
if ($categoryId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id AND mc.category_id = :category_id)';
    $params[':category_id'] = $categoryId;
}

// Tag filter (OR within selected tags — model must have ANY of the selected tags)
if (!empty($tagIds)) {
    $tagPlaceholders = implode(',', array_map(fn($i) => ':tag_id_' . $i, array_keys($tagIds)));
    $where[] = "EXISTS (SELECT 1 FROM model_tags mt WHERE mt.model_id = m.id AND mt.tag_id IN ($tagPlaceholders))";
    foreach ($tagIds as $i => $tid) {
        $params[':tag_id_' . $i] = $tid;
    }
}

// File type filter
if ($fileType !== '') {
    $where[] = 'm.file_type = :file_type';
    $params[':file_type'] = $fileType;
}

// Print type filter (matches parent models directly or those with parts of the given print type)
if ($printType !== '' && in_array($printType, ['fdm', 'sla'])) {
    $where[] = '(m.print_type = :print_type OR m.id IN (SELECT parent_id FROM models WHERE parent_id IS NOT NULL AND print_type = :print_type2))';
    $params[':print_type'] = $printType;
    $params[':print_type2'] = $printType;
}

// Collection filter
if ($collection !== '') {
    $where[] = 'm.collection = :collection';
    $params[':collection'] = $collection;
}

// Archive filter
if (!$showArchived) {
    $where[] = '(m.is_archived = 0 OR m.is_archived IS NULL)';
}

$whereClause = implode(' AND ', $where);

if (class_exists('PluginManager')) {
    $whereClause = PluginManager::applyFilter('browse_query_where', $whereClause, $params);
}

// Sort options (FTS relevance overrides default when search active and no explicit sort set)
$orderBy = $ftsOrderBy ?? match($sort) {
    'oldest' => 'm.created_at ASC',
    'updated' => 'm.updated_at DESC',
    'name' => 'm.name ASC',
    'name_desc' => 'm.name DESC',
    'size' => 'm.file_size DESC',
    'size_asc' => 'm.file_size ASC',
    'parts' => 'm.part_count DESC',
    'downloads' => 'm.download_count DESC',
    default => 'm.created_at DESC' // newest
};

// Count query: no FTS join needed (rank not required for counting)
$countSql = "SELECT COUNT(*) FROM models m WHERE $whereClause";
$countStmt = $db->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalModels = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalModels / $perPage);

// Main query: includes FTS join for rank column when FTS is active
$sql = "SELECT m.id, m.name, m.description, m.creator, m.file_path, m.file_size, m.file_type,
               m.dedup_path, m.part_count, m.download_count, m.created_at, m.is_archived, m.thumbnail_path
        FROM models m
        $ftsJoin
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
foreach ($ftsJoinParams as $key => $value) {
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

// Get categories for filter dropdown (cached for 5 minutes)
$categories = Cache::getInstance()->remember('browse_categories', 300, function() use ($db) {
    $cats = [];
    $catResult = $db->query('SELECT c.id, c.name, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
    while ($row = $catResult->fetchArray(PDO::FETCH_ASSOC)) {
        $cats[] = $row;
    }
    return $cats;
});

// Get tags for filter dropdown
$tags = getAllTags();

// Get distinct file types for filter dropdown
$fileTypes = [];
$ftResult = $db->query("SELECT DISTINCT file_type FROM models WHERE parent_id IS NULL AND file_type IS NOT NULL AND file_type != '' ORDER BY file_type");
if ($ftResult) {
    while ($ftRow = $ftResult->fetchArray()) {
        $fileTypes[] = $ftRow['file_type'];
    }
}

// Get distinct print types for filter dropdown
$printTypes = [];
$ptResult = $db->query("SELECT DISTINCT print_type FROM models WHERE print_type IS NOT NULL AND print_type != '' ORDER BY print_type");
if ($ptResult) {
    while ($ptRow = $ptResult->fetchArray()) {
        $printTypes[] = $ptRow['print_type'];
    }
}

// Get distinct collections for filter dropdown
$collections = [];
$collResult = $db->query("SELECT DISTINCT collection FROM models WHERE parent_id IS NULL AND collection IS NOT NULL AND collection != '' ORDER BY collection");
if ($collResult) {
    while ($collRow = $collResult->fetchArray()) {
        $collections[] = $collRow['collection'];
    }
}

// Get user's saved searches
$savedSearches = [];
if (isLoggedIn()) {
    $user = getCurrentUser();
    $savedSearches = SavedSearches::getUserSearches($user['id'], 20);
}

// Get active filter names for display
$activeCategory = null;
if ($categoryId > 0) {
    $catStmt = $db->prepare('SELECT name FROM categories WHERE id = :id');
    $catStmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
    $catStmt->execute();
    $activeCategory = $catStmt->fetchColumn();
}

$activeTags = [];
if (!empty($tagIds)) {
    $tagPlaceholders2 = implode(',', array_fill(0, count($tagIds), '?'));
    $activeTagStmt = $db->prepare("SELECT id, name, color FROM tags WHERE id IN ($tagPlaceholders2) ORDER BY name");
    foreach ($tagIds as $i => $tid) {
        $activeTagStmt->bindValue($i + 1, $tid, PDO::PARAM_INT);
    }
    $activeTagStmt->execute();
    while ($tagRow = $activeTagStmt->fetch()) {
        $activeTags[] = $tagRow;
    }
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

// Remove a single tag ID from the tags[] array parameter while keeping all other filters.
// buildUrl(['tags' => null]) would remove ALL tags; this helper removes just one.
function buildUrlWithoutTag($tagId) {
    $current = $_GET;
    $current['tags'] = array_values(array_filter(
        array_map('intval', (array)($current['tags'] ?? [])),
        fn($id) => $id !== (int)$tagId
    ));
    // Also remove legacy ?tag= param if present
    unset($current['tag']);
    $current['page'] = 1;
    if (empty($current['tags'])) {
        unset($current['tags']);
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
                        <input type="checkbox" id="select-all-models" onchange="toggleSelectAllModels(this)">
                        <span id="selected-count">0</span> selected
                    </label>
                    <button type="button" class="btn btn-small btn-ghost" onclick="clearSelection()" title="Clear selection (Esc)">Clear</button>
                </div>
                <div class="batch-actions-right">
                    <button type="button" class="btn btn-small" onclick="batchDownload()" title="Download selected as ZIP">Download</button>
                    <select id="batch-tag-select" class="batch-select" onchange="batchApplyTag(this.value)" aria-label="Add tag to selected">
                        <option value="">+ Tag</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">Create New...</option>
                    </select>
                    <select id="batch-category-select" class="batch-select" onchange="batchApplyCategory(this.value)" aria-label="Add category to selected">
                        <option value="">+ Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-small" onclick="batchSetCreator()" title="Set creator for selected">Set Creator</button>
                    <button type="button" class="btn btn-small" onclick="batchSetCollection()" title="Set collection for selected">Set Collection</button>
                    <button type="button" class="btn btn-small btn-warning" onclick="batchArchive()">Archive</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="browse-controls">
                <!-- Row 1: batch toggle, sort, view toggle -->
                <div class="browse-filters">
                    <?php if (isLoggedIn()): ?>
                    <label class="batch-mode-toggle" title="Enable batch selection">
                        <input type="checkbox" id="batch-mode-checkbox" onchange="toggleBatchMode(this.checked)">
                        Select
                    </label>
                    <?php endif; ?>

                    <select class="sort-select" aria-label="Sort by" onchange="location.href=this.value">
                        <option value="<?= buildUrl(['sort' => 'newest', 'page' => 1]) ?>" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="<?= buildUrl(['sort' => 'oldest', 'page' => 1]) ?>" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="<?= buildUrl(['sort' => 'updated', 'page' => 1]) ?>" <?= $sort === 'updated' ? 'selected' : '' ?>>Recently Updated</option>
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

                    <div class="view-toggle">
                        <a href="<?= buildUrl(['view' => 'grid']) ?>" class="view-toggle-btn <?= $view === 'grid' ? 'active' : '' ?>" title="Grid view">&#9638;</a>
                        <a href="<?= buildUrl(['view' => 'list']) ?>" class="view-toggle-btn <?= $view === 'list' ? 'active' : '' ?>" title="List view">&#9776;</a>
                    </div>
                </div>

                <!-- Row 2: inline filter bar with compact add-filter dropdowns and active chips -->
                <div class="filter-bar">
                    <?php if (isFeatureEnabled('categories') && !empty($categories)): ?>
                    <select class="filter-select" aria-label="Filter by category" onchange="if(this.value) location.href=this.value" title="Filter by category">
                        <option value="">+ Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= buildUrl(['category' => $cat['id'], 'page' => 1]) ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?> (<?= $cat['model_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('tags') && !empty($tags)): ?>
                    <?php $unselectedTags = array_filter($tags, fn($t) => !in_array($t['id'], $tagIds)); ?>
                    <?php if (!empty($unselectedTags)): ?>
                    <select class="filter-select" aria-label="Filter by tag" onchange="if(this.value) location.href=this.value" title="Filter by tag">
                        <option value="">+ Tag</option>
                        <?php foreach ($unselectedTags as $tag): ?>
                        <option value="<?= buildUrl(['tags' => array_merge($tagIds, [$tag['id']]), 'page' => 1]) ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($fileTypes)): ?>
                    <select class="filter-select" aria-label="Filter by file type" onchange="if(this.value) location.href=this.value" title="Filter by file type">
                        <option value="">+ File Type</option>
                        <?php foreach ($fileTypes as $ft): ?>
                        <option value="<?= buildUrl(['file_type' => $ft, 'page' => 1]) ?>" <?= $fileType === $ft ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($ft)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($printTypes)): ?>
                    <select class="filter-select" aria-label="Filter by print type" onchange="if(this.value) location.href=this.value" title="Filter by print type">
                        <option value="">+ Print Type</option>
                        <?php foreach ($printTypes as $pt): ?>
                        <option value="<?= buildUrl(['print_type' => $pt, 'page' => 1]) ?>" <?= $printType === $pt ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($pt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($collections)): ?>
                    <select class="filter-select" aria-label="Filter by collection" onchange="if(this.value) location.href=this.value" title="Filter by collection">
                        <option value="">+ Collection</option>
                        <?php foreach ($collections as $coll): ?>
                        <option value="<?= buildUrl(['collection' => $coll, 'page' => 1]) ?>" <?= $collection === $coll ? 'selected' : '' ?>><?= htmlspecialchars($coll) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (class_exists('PluginManager')): ?>
                    <?= PluginManager::applyFilter('browse_filters', '') ?>
                    <?php endif; ?>

                    <?php if (isLoggedIn()): ?>
                    <?php if (!empty($savedSearches)): ?>
                    <select class="filter-select" aria-label="Load saved search" onchange="if(this.value) location.href=this.value" title="Load saved search">
                        <option value="">Saved Searches</option>
                        <?php foreach ($savedSearches as $ss): ?>
                        <option value="<?= htmlspecialchars(SavedSearches::toUrl($ss)) ?>"><?= htmlspecialchars($ss['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php $hasFilters = $search !== '' || $categoryId > 0 || !empty($tagIds) || $fileType !== '' || $printType !== '' || $collection !== ''; ?>
                    <?php if ($hasFilters): ?>
                    <button type="button" class="btn btn-small btn-secondary" onclick="saveCurrentSearch()" title="Save current search">Save Search</button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Active filter chips -->
                    <?php if ($search): ?>
                    <span class="filter-chip">
                        Search: <?= htmlspecialchars($search) ?>
                        <a href="<?= buildUrl(['q' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove search filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('categories') && $activeCategory): ?>
                    <span class="filter-chip">
                        <?= htmlspecialchars($activeCategory) ?>
                        <a href="<?= buildUrl(['category' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove category filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if (isFeatureEnabled('tags')): ?>
                        <?php foreach ($activeTags as $activeTag): ?>
                        <span class="filter-chip" style="background-color: <?= htmlspecialchars($activeTag['color']) ?>">
                            <?= htmlspecialchars($activeTag['name']) ?>
                            <a href="<?= buildUrlWithoutTag($activeTag['id']) ?>" class="filter-chip-remove" aria-label="Remove tag filter">&times;</a>
                        </span>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($fileType !== ''): ?>
                    <span class="filter-chip">
                        Type: <?= htmlspecialchars(strtoupper($fileType)) ?>
                        <a href="<?= buildUrl(['file_type' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove file type filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if ($printType !== ''): ?>
                    <span class="filter-chip">
                        Print: <?= htmlspecialchars(strtoupper($printType)) ?>
                        <a href="<?= buildUrl(['print_type' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove print type filter">&times;</a>
                    </span>
                    <?php endif; ?>

                    <?php if ($collection !== ''): ?>
                    <span class="filter-chip">
                        Collection: <?= htmlspecialchars($collection) ?>
                        <a href="<?= buildUrl(['collection' => null, 'page' => 1]) ?>" class="filter-chip-remove" aria-label="Remove collection filter">&times;</a>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($models)): ?>
                <p class="text-muted" style="text-align: center; padding: 3rem;">No models found. <?php if (!$search && !$categoryId && empty($tagIds) && $fileType === ''): ?><a href="<?= route('upload') ?>">Upload your first model!</a><?php endif; ?></p>
            <?php elseif ($view === 'list'): ?>
                <div class="models-list">
                    <?php foreach ($models as $model): ?>
                    <article class="model-list-item <?= $model['is_archived'] ? 'archived' : '' ?>" data-model-id="<?= $model['id'] ?>" onclick="handleModelCardClick(event, <?= $model['id'] ?>)" tabindex="0" role="link" aria-label="<?= htmlspecialchars($model['name']) ?>" onkeydown="if(event.key==='Enter')handleModelCardClick(event,<?= $model['id'] ?>)">
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
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" loading="lazy">
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
                    <article class="model-card <?= $model['is_archived'] ? 'archived' : '' ?>" data-model-id="<?= $model['id'] ?>" onclick="handleModelCardClick(event, <?= $model['id'] ?>)" tabindex="0" role="link" aria-label="<?= htmlspecialchars($model['name']) ?>" onkeydown="if(event.key==='Enter')handleModelCardClick(event,<?= $model['id'] ?>)">
                        <div class="model-thumbnail"
                            <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path']) && ($model['preview_file_size'] ?? $model['file_size'] ?? 0) < 5242880): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if (!empty($model['thumbnail_path'])): ?>
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" loading="lazy">
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
                            <?php if (!empty($model['created_at'])): ?>
                            <time class="model-date" datetime="<?= htmlspecialchars(date('c', strtotime($model['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($model['created_at']) ?>"><?= date('M j, Y', strtotime($model['created_at'])) ?></time>
                            <?php endif; ?>
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
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="pagination-btn" aria-label="Previous page">&laquo; Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1): ?>
                <a href="<?= buildUrl(['page' => 1]) ?>" class="pagination-btn" aria-label="Page 1">1</a>
                <?php if ($startPage > 2): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="<?= buildUrl(['page' => $i]) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>" aria-label="Page <?= $i ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="<?= buildUrl(['page' => $totalPages]) ?>" class="pagination-btn" aria-label="Page <?= $totalPages ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="pagination-btn" aria-label="Next page">Next &raquo;</a>
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
                window.location = '/model/' + modelId;
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
                showToast('Please select models first', 'error');
                return;
            }
            window.location = '/actions/batch-download?ids=' + ids.join(',');
        }

        async function batchApplyTag(tagId) {
            const select = document.getElementById('batch-tag-select');
            if (!tagId) {
                select.value = '';
                return;
            }

            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                showToast('Please select models first', 'error');
                select.value = '';
                return;
            }

            let tagName = '';
            if (tagId === '__new__') {
                tagName = await showPrompt('Enter new tag name:');
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

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Tagged ${result.updated} model(s)`, 'success');
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error('Batch tag error:', err);
                showToast('Failed to apply tag', 'error');
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
                showToast('Please select models first', 'error');
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
                console.error('Batch category error:', err);
                showToast('Failed to apply category', 'error');
            }

            select.value = '';
        }

        async function batchArchive() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                showToast('Please select models first', 'error');
                return;
            }

            if (!await showConfirm(`Archive ${ids.length} selected model(s)?`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'archive');
                formData.append('archive', '1');
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Archived ${result.updated} model(s)`, 'success');
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error('Batch archive error:', err);
                showToast('Failed to archive', 'error');
            }
        }

        async function batchSetCreator() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                showToast('Please select models first', 'error');
                return;
            }

            const creator = await showPrompt(`Set creator for ${ids.length} selected model(s) (leave empty to clear):`);
            if (creator === null) return; // Cancelled

            try {
                const formData = new FormData();
                formData.append('action', 'set_creator');
                formData.append('creator', creator);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Updated creator on ${result.updated} model(s)`, 'success');
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error('Batch set creator error:', err);
                showToast('Failed to set creator', 'error');
            }
        }

        async function batchSetCollection() {
            const ids = getSelectedModelIds();
            if (ids.length === 0) {
                showToast('Please select models first', 'error');
                return;
            }

            const collection = await showPrompt(`Set collection for ${ids.length} selected model(s) (leave empty to clear):`);
            if (collection === null) return; // Cancelled

            try {
                const formData = new FormData();
                formData.append('action', 'set_collection');
                formData.append('collection', collection);
                ids.forEach(id => formData.append('model_ids[]', id));

                const response = await fetch('/actions/batch-apply', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    showToast(`Updated collection on ${result.updated} model(s)`, 'success');
                    location.reload();
                } else {
                    showToast(result.error || 'Unknown error', 'error');
                }
            } catch (err) {
                console.error('Batch set collection error:', err);
                showToast('Failed to set collection', 'error');
            }
        }

        async function saveCurrentSearch() {
            var name = await showPrompt('Name for this saved search:');
            if (!name || !name.trim()) return;

            var formData = new FormData();
            formData.append('action', 'save');
            formData.append('name', name.trim());
            formData.append('csrf_token', '<?= Csrf::getToken() ?>');
            // Pass current filters
            var params = new URLSearchParams(window.location.search);
            params.forEach(function(value, key) {
                if (key !== 'page') {
                    formData.append(key, value);
                }
            });

            try {
                var resp = await fetch('/saved-searches', { method: 'POST', body: formData });
                var data = await resp.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.error || 'Failed to save search', 'error');
                }
            } catch (e) {
                showToast('Failed to save search', 'error');
            }
        }

        // Initialize checkbox visibility
        document.querySelectorAll('.model-select-checkbox, .model-list-checkbox').forEach(el => {
            el.style.display = 'none';
        });
        </script>

<?php require_once 'includes/footer.php'; ?>
