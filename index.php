<?php
/**
 * Silo Front Controller
 *
 * All requests are routed through this file via .htaccess rewrite rules.
 * If a route matches, it's dispatched. Otherwise, or for "/" route, the
 * homepage is displayed.
 */

// Increase memory limit for large operations
ini_set('memory_limit', '512M');

// Redirect to installer if not yet installed
if (!file_exists(__DIR__ . '/storage/db/config.local.php')
    && !file_exists(__DIR__ . '/db/config.local.php')
    && !file_exists(__DIR__ . '/config.local.php')
) {
    if (file_exists(__DIR__ . '/install.php')) {
        header('Location: /install.php');
        exit;
    }
}

// Check if this is a routed request (not the homepage)
$routePath = $_GET['route'] ?? '';
$routePath = '/' . trim($routePath, '/');

// Handle routing for non-homepage requests
if ($routePath !== '/' && !empty($_GET['route'])) {
    // Load router and config
    require_once __DIR__ . '/includes/Router.php';
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/helpers.php';

    // Enable access logging for all requests (optional - can be disabled in settings)
    if (getSetting('enable_access_log', '0') === '1') {
        require_once __DIR__ . '/includes/middleware/AccessLogMiddleware.php';
        $accessLog = new AccessLogMiddleware([
            'skip_paths' => ['/health', '/api/health'],
            'skip_extensions' => ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2']
        ]);
        $accessLog->handle([]);
    }

    // Check for maintenance mode first (allow admins to bypass)
    if (function_exists('getSetting') && getSetting('maintenance_mode', '0') === '1') {
        if (!function_exists('isAdmin') || !isAdmin()) {
            // Allow login route during maintenance
            if (!in_array($routePath, ['/login', '/logout', '/oidc-callback'])) {
                require_once __DIR__ . '/includes/middleware/MaintenanceMiddleware.php';
                $maintenance = new MaintenanceMiddleware();
                $maintenance->handle([]);
            }
        }
    }

    // Check for SEO redirects (old .php URLs)
    if (function_exists('getSetting') && getSetting('seo_redirects', '1') === '1') {
        require_once __DIR__ . '/includes/middleware/SeoRedirectMiddleware.php';
        $seo = new SeoRedirectMiddleware();
        $seo->handle([]);
    }

    // Load routes (use cache in production if available)
    $useCache = function_exists('getSetting') && getSetting('route_caching', '0') === '1';
    if ($useCache && Router::isCacheValid()) {
        Router::getInstance()->loadFromCache();
    } else {
        require_once __DIR__ . '/includes/routes.php';
        // Save cache if caching is enabled
        if ($useCache) {
            Router::getInstance()->saveToCache();
        }
    }

    $router = Router::getInstance();

    // Try to dispatch the route
    if ($router->dispatch()) {
        exit; // Route was handled
    }

    // Route not found - redirect to appropriate root
    if (str_starts_with($routePath, '/admin')) {
        header('Location: ' . route('admin.settings'));
    } else {
        header('Location: /');
    }
    exit;
}

// ============================================================================
// HOMEPAGE
// ============================================================================

require_once 'includes/config.php';
require_once 'includes/helpers.php';
require_once 'includes/dedup.php';

// Enable access logging for homepage (optional - can be disabled in settings)
if (getSetting('enable_access_log', '0') === '1') {
    require_once __DIR__ . '/includes/middleware/AccessLogMiddleware.php';
    $accessLog = new AccessLogMiddleware();
    $accessLog->handle([]);
}

$pageTitle = 'Home';
$activePage = 'browse';

$db = getDB();

// Get recent models (only parent/standalone models, not parts)
// Wrapped in try/catch to gracefully handle MySQL query differences
try {
    $result = $db->query('SELECT id, name, description, file_path, file_size, file_type, dedup_path, part_count, print_type, creator, created_at, is_archived, thumbnail_path FROM models WHERE parent_id IS NULL ORDER BY created_at DESC LIMIT 8');
    $models = [];
    $modelIds = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        if ($row['part_count'] > 0) {
            $modelIds[] = $row['id'];
        } else {
            // Use preview endpoint for single models
            $row['preview_path'] = '/preview?id=' . $row['id'];
            $row['preview_type'] = $row['file_type'];
            $row['print_types'] = $row['print_type'] ? [$row['print_type']] : [];
        }
        $models[] = $row;
    }
} catch (Throwable $e) {
    logException($e, ['action' => 'homepage_recent_models']);
    $models = [];
    $modelIds = [];
}

// Bulk load first parts for multi-part models (eliminates N+1 queries)
if (!empty($modelIds)) {
    try {
        $firstParts = getFirstPartsForModels($modelIds);

        // Get distinct print types for multi-part models in one query
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $printStmt = $db->prepare("SELECT parent_id, GROUP_CONCAT(DISTINCT print_type) as print_types FROM models WHERE parent_id IN ($placeholders) AND print_type IS NOT NULL GROUP BY parent_id");
        foreach ($modelIds as $index => $id) {
            $printStmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $printResult = $printStmt->execute();
        $printTypesByParent = [];
        while ($row = $printResult->fetchArray(PDO::FETCH_ASSOC)) {
            $printTypesByParent[$row['parent_id']] = explode(',', $row['print_types']);
        }

        // Assign to models
        foreach ($models as &$model) {
            if ($model['part_count'] > 0) {
                $firstPart = $firstParts[$model['id']] ?? null;
                if ($firstPart) {
                    // Use preview endpoint for multi-part models
                    $model['preview_path'] = '/preview?id=' . $firstPart['id'];
                    $model['preview_type'] = $firstPart['file_type'];
                }
                $model['print_types'] = $printTypesByParent[$model['id']] ?? [];
            }
        }
        unset($model);
    } catch (Throwable $e) {
        logException($e, ['action' => 'homepage_multipart_models']);
    }
}

// Get categories with model counts
try {
    $result = $db->query('SELECT c.*, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
} catch (Throwable $e) {
    logException($e, ['action' => 'homepage_categories']);
    $result = null;
}
$categories = [];
if ($result) {
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $categories[] = $row;
    }
}

$message = '';
$messageType = 'success';

if (isset($_GET['uploaded'])) {
    $count = (int)$_GET['uploaded'];
    if ($count === 1) {
        $message = 'Model uploaded successfully!';
    } else {
        $message = $count . ' models uploaded successfully!';
    }
}

// Check for session messages
if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    $messageType = 'success';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $message = $_SESSION['error'];
    $messageType = 'error';
    unset($_SESSION['error']);
}

// Get recently viewed models
$recentlyViewed = [];
if (isFeatureEnabled('recently_viewed')) {
    $recentlyViewed = getRecentlyViewed(6);
}

// Bulk load first parts for recently viewed multi-part models
$rvMultiPartIds = array_column(array_filter($recentlyViewed, fn($m) => $m['part_count'] > 0), 'id');
$rvParts = !empty($rvMultiPartIds) ? getFirstPartsForModels($rvMultiPartIds) : [];

// Enhance with preview data using preview endpoint
foreach ($recentlyViewed as &$rv) {
    if ($rv['part_count'] > 0) {
        $firstPart = $rvParts[$rv['id']] ?? null;
        if ($firstPart) {
            $rv['preview_path'] = '/preview?id=' . $firstPart['id'];
            $rv['preview_type'] = $firstPart['file_type'];
        }
    } else {
        $rv['preview_path'] = '/preview?id=' . $rv['id'];
        $rv['preview_type'] = $rv['file_type'];
    }
}
unset($rv);

// Get popular tags (cached for 5 minutes to reduce load)
$popularTags = [];
if (isFeatureEnabled('tags')) {
    $cacheKey = 'popular_tags_10';
    $cacheFile = __DIR__ . '/cache/popular_tags.json';
    $cacheValid = false;

    // Check if cache exists and is fresh (5 minutes)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) {
            $popularTags = $cached;
            $cacheValid = true;
        }
    }

    // Regenerate cache if needed
    if (!$cacheValid) {
        try {
            $tagResult = $db->query('SELECT t.id, t.name, t.color, COUNT(mt.model_id) as model_count FROM tags t JOIN model_tags mt ON t.id = mt.tag_id GROUP BY t.id ORDER BY model_count DESC LIMIT 10');
            while ($row = $tagResult->fetchArray(PDO::FETCH_ASSOC)) {
                $popularTags[] = $row;
            }
        } catch (Throwable $e) {
            logException($e, ['action' => 'homepage_popular_tags']);
        }

        // Save to cache
        if (!is_dir(__DIR__ . '/cache')) {
            @mkdir(__DIR__ . '/cache', 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($popularTags));
    }
}

require_once 'includes/header.php';
?>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>" style="max-width: 1400px; margin: 1rem auto;"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="hero-content">
                <h1>Your 3D Model Library</h1>
                <p>Store, organize, and share your 3D print files in one place.</p>
            </div>
        </section>

        <?php if (isFeatureEnabled('recently_viewed') && !empty($recentlyViewed)): ?>
        <section class="models-section recently-viewed-section">
            <div class="section-header">
                <h2>Recently Viewed</h2>
            </div>
            <div class="recently-viewed-grid">
                <?php foreach ($recentlyViewed as $rv): ?>
                <article class="model-card recently-viewed-card" onclick="window.location='<?= route('model.show', ['id' => $rv['id']]) ?>'">
                    <div class="model-thumbnail"
                        <?php if (empty($rv['thumbnail_path']) && !empty($rv['preview_path']) && ($rv['file_size'] ?? 0) < 5242880): ?>
                        data-model-url="<?= htmlspecialchars($rv['preview_path']) ?>"
                        data-file-type="<?= htmlspecialchars($rv['preview_type']) ?>"
                        <?php endif; ?>>
                        <?php if (!empty($rv['thumbnail_path'])): ?>
                        <img src="/assets/<?= htmlspecialchars($rv['thumbnail_path']) ?>" alt="<?= htmlspecialchars($rv['name']) ?>" class="model-thumbnail-image">
                        <?php endif; ?>
                    </div>
                    <div class="model-info">
                        <h3 class="model-title"><?= htmlspecialchars($rv['name']) ?></h3>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="models-section">
            <div class="section-header">
                <h2>Recent Models</h2>
                <a href="<?= route('browse') ?>" class="btn btn-secondary btn-small">View All</a>
            </div>
            <div class="models-grid">
                <?php if (empty($models)): ?>
                    <p class="text-muted">No models yet. <a href="<?= route('upload') ?>">Upload your first model!</a></p>
                <?php else: ?>
                    <?php foreach ($models as $model): ?>
                    <article class="model-card <?= $model['is_archived'] ? 'archived' : '' ?>" onclick="window.location='<?= route('model.show', ['id' => $model['id']]) ?>'">
                        <div class="model-thumbnail"
                            <?php if (empty($model['thumbnail_path']) && !empty($model['preview_path']) && ($model['file_size'] ?? 0) < 5242880): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if (!empty($model['thumbnail_path'])): ?>
                            <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image">
                            <?php endif; ?>
                            <?php if ($model['part_count'] > 0): ?>
                            <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                            <?php endif; ?>
                            <?php if (!empty($model['print_types'])): ?>
                            <div class="print-type-indicators">
                                <?php if (in_array('fdm', $model['print_types'])): ?>
                                <span class="print-type-badge print-type-fdm">FDM</span>
                                <?php endif; ?>
                                <?php if (in_array('sla', $model['print_types'])): ?>
                                <span class="print-type-badge print-type-sla">SLA</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="model-info">
                            <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
                            <p class="model-creator"><?= $model['creator'] ? 'by ' . htmlspecialchars($model['creator']) : '' ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($popularTags)): ?>
        <section class="models-section">
            <div class="section-header">
                <h2>Popular Tags</h2>
                <a href="<?= route('tags') ?>" class="btn btn-secondary btn-small">View All</a>
            </div>
            <div class="model-tags" style="padding: 0 1rem;">
                <?php foreach ($popularTags as $tag): ?>
                <a href="<?= route('browse', [], ['tag' => $tag['id']]) ?>" class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>; text-decoration: none; font-size: 0.875rem; padding: 0.5rem 1rem;">
                    <?= htmlspecialchars($tag['name']) ?>
                    <span style="opacity: 0.8; margin-left: 0.25rem;">(<?= $tag['model_count'] ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="categories-section">
            <div class="section-header">
                <h2>Categories</h2>
                <a href="<?= route('categories') ?>" class="btn btn-secondary btn-small">View All</a>
            </div>
            <div class="categories-grid">
                <?php foreach ($categories as $category): ?>
                <a href="<?= route('browse', [], ['category' => $category['id']]) ?>" class="category-card">
                    <span class="category-name"><?= htmlspecialchars($category['name']) ?></span>
                    <span class="category-count"><?= $category['model_count'] ?> models</span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

<?php require_once 'includes/footer.php'; ?>
