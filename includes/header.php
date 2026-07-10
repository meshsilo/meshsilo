<?php
// Include features helper if not already loaded
if (!function_exists('isFeatureEnabled')) {
    require_once __DIR__ . '/features.php';
}

// Determine current theme
$defaultTheme = getSetting('default_theme', 'dark');
$allowUserTheme = getSetting('allow_user_theme', '1') === '1';
$currentTheme = $defaultTheme;
if ($allowUserTheme && isset($_COOKIE['meshsilo_theme'])) {
    $currentTheme = $_COOKIE['meshsilo_theme'] === 'light' ? 'light' : 'dark';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $currentTheme === 'dark' ? '#0f172a' : '#3b82f6' ?>">
    <meta name="color-scheme" content="light dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="description" content="<?= htmlspecialchars($metaDescription ?? getSetting('site_description', 'Digital Asset Manager for 3D print files')) ?>">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?> - <?= htmlspecialchars(SITE_NAME) ?></title>
<?php
// Build absolute base URL for OG tags
$_ogScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$_ogBase = $_ogScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_ogUrl = $_ogBase . ($_SERVER['REQUEST_URI'] ?? '/');
$_ogTitle = $pageTitle ?? SITE_NAME;
$_ogDescription = $metaDescription ?? getSetting('site_description', 'Digital Asset Manager for 3D print files');
$_ogType = $ogType ?? 'website';
$_ogImageAbsolute = isset($ogImage) ? $_ogBase . $ogImage : null;
?>
    <!-- Open Graph -->
    <meta property="og:site_name" content="<?= htmlspecialchars(SITE_NAME) ?>">
    <meta property="og:type" content="<?= htmlspecialchars($_ogType) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($_ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($_ogDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($_ogUrl) ?>">
<?php if ($_ogImageAbsolute): ?>
    <meta property="og:image" content="<?= htmlspecialchars($_ogImageAbsolute) ?>">
<?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card" content="<?= $_ogImageAbsolute ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($_ogTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_ogDescription) ?>">
<?php if ($_ogImageAbsolute): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($_ogImageAbsolute) ?>">
<?php endif; ?>
    <link rel="manifest" href="<?= basePath('manifest.json') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= basePath('images/icon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= basePath('images/favicon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= basePath('images/favicon-16.png') ?>">
    <link rel="apple-touch-icon" href="<?= basePath('images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= basePath('vendor/fontawesome/css/all.min.css') ?>?v=<?= filemtime(__DIR__ . '/../public/vendor/fontawesome/css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/base.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/base.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/layout.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/layout.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/components.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/components.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/pages.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/pages.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/upload.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/upload.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/viewer.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/viewer.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/model-detail.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/model-detail.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/lightbox.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/lightbox.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/browse.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/browse.css') ?>">
    <?php if (!empty($adminPage)): ?>
    <link rel="stylesheet" href="<?= basePath('css/admin.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/admin-stats.css') ?>?v=<?= filemtime(__DIR__ . '/../public/css/admin-stats.css') ?>">
    <?php endif; ?>

    <!-- CDN preconnect hint (dns-prefetch as fallback for older browsers) -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">

    <?php if (!empty($needsViewer)): ?>
    <?php include __DIR__ . '/partials/viewer-cdn.php'; ?>
    <?php endif; ?>
    <?php if (!empty($needsModelPageJs)): ?>
    <script src="<?= basePath('js/model-parts.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-parts.js') ?>" defer></script>
    <script src="<?= basePath('js/model-parts-selection.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-parts-selection.js') ?>" defer></script>
    <script src="<?= basePath('js/model-parts-folders.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-parts-folders.js') ?>" defer></script>
    <script src="<?= basePath('js/model-parts-upload.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-parts-upload.js') ?>" defer></script>
    <script src="<?= basePath('js/model-attachments.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-attachments.js') ?>" defer></script>
    <script src="<?= basePath('js/model-actions.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-actions.js') ?>" defer></script>
    <script src="<?= basePath('js/model-page.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/model-page.js') ?>" defer></script>
    <?php endif; ?>
    <?php if (!empty($needsBrowsePageJs)): ?>
    <script src="<?= basePath('js/browse-page.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/browse-page.js') ?>" defer></script>
    <?php endif; ?>
    <?php if (!empty($needsEditModelJs)): ?>
    <script src="<?= basePath('js/edit-model-page.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/edit-model-page.js') ?>" defer></script>
    <?php endif; ?>
    <?php if (!empty($needsTusJs)): ?>
    <script src="https://cdn.jsdelivr.net/npm/tus-js-client@4.3.1/dist/tus.min.js" integrity="sha384-UlHjK3F7TCQCEUpnoa1ohMbP2oaWB3Aypv4gMo511vaZ86uUZ0Zv7UzZ0J1zRUT1" crossorigin="anonymous" defer></script>
    <?php endif; ?>
    <?php if (!empty($adminPage)): ?>
    <script src="<?= basePath('js/admin-pages.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/admin-pages.js') ?>" defer></script>
    <?php endif; ?>
    <script src="<?= basePath('js/main.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/main.js') ?>" defer></script>
    <script>
    window.SiloConfig = {
        modelBase: '<?= htmlspecialchars(rtrim(route('model.show', ['id' => 0]), '0'), ENT_QUOTES) ?>',
        swVersion: '<?= defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '0' ?>'
    };
    </script>
    <script src="<?= basePath('js/ui-common.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/ui-common.js') ?>" defer></script>
    <?php if (isLoggedIn()) : ?>
        <?= Csrf::metaTag() ?>
        <?= Csrf::ajaxSetupScript() ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                refreshQueueStatus();
                setInterval(refreshQueueStatus, 15000);
            });
            // While the tab is hidden the interval fires but refreshQueueStatus() bails early;
            // refresh once immediately when the tab becomes visible again.
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) refreshQueueStatus();
            });
            document.addEventListener('click', function(e) {
                var indicator = document.getElementById('queue-indicator');
                if (indicator && indicator.classList.contains('open') && !indicator.contains(e.target)) {
                    indicator.classList.remove('open');
                }
            });
        </script>
    <?php endif; ?>
<?php if (class_exists('PluginManager')) : ?>
    <?= PluginManager::getInstance()->renderStyles() ?>
<?php endif; ?>
<?php if (class_exists('PluginManager')) : ?>
    <?= PluginManager::applyFilter('head_tags', '') ?>
<?php endif; ?>
    <script src="<?= basePath('js/header-search.js') ?>?v=<?= filemtime(__DIR__ . '/../public/js/header-search.js') ?>" defer></script>
</head>
<body<?= !empty($bodyClass) ? ' class="' . htmlspecialchars($bodyClass) . '"' : '' ?>>
<?php if (empty($minimalHeader)): ?>
    <a href="#main-content" class="skip-to-content">Skip to content</a>
    <header class="site-header">
        <div class="header-content">
            <a href="<?= route('home') ?>" class="logo">
                <?php $logoPath = getSetting('logo_path', ''); if ($logoPath) : ?>
                <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="logo-img">
                <?php endif; ?>
                <span class="logo-text"><?= htmlspecialchars(SITE_NAME) ?></span>
            </a>
            <button type="button" class="mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Toggle menu" aria-expanded="false">
                <span class="hamburger-icon"></span>
            </button>
            <nav class="main-nav" aria-label="Main navigation">
                <a href="<?= route('browse') ?>" <?= ($activePage ?? '') === 'browse' ? 'class="active" aria-current="page"' : '' ?>>Browse</a>
                <?php if (isFeatureEnabled('categories')) : ?>
                <a href="<?= route('categories') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active" aria-current="page"' : '' ?>>Categories</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('collections')) : ?>
                <a href="<?= route('collections') ?>" <?= ($activePage ?? '') === 'collections' ? 'class="active" aria-current="page"' : '' ?>>Collections</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('tags')) : ?>
                <a href="<?= route('tags') ?>" <?= ($activePage ?? '') === 'tags' ? 'class="active" aria-current="page"' : '' ?>>Tags</a>
                <?php endif; ?>
                <?php if (canUpload()) : ?>
                <a href="<?= route('upload') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active" aria-current="page"' : '' ?>>Upload</a>
                <?php endif; ?>
<?php if (class_exists('PluginManager')) :
    $pluginNavItems = PluginManager::applyFilter('nav_items', []);
    foreach ($pluginNavItems as $navItem) : ?>
    <a href="<?= htmlspecialchars($navItem['url'] ?? '#') ?>" <?= !empty($navItem['active']) ? 'class="active" aria-current="page"' : '' ?>>
        <?php if (!empty($navItem['icon'])) :
            ?><i data-feather="<?= htmlspecialchars($navItem['icon']) ?>"></i> <?php
        endif; ?>
        <?= htmlspecialchars($navItem['label'] ?? '') ?>
    </a>
    <?php endforeach;
endif; ?>
            </nav>
            <div class="header-actions">
                <div class="search-container" style="position: relative;">
                    <form id="search-form" action="<?= route('browse') ?>" method="get" role="search">
                        <input type="search" id="search-input" name="q" class="search-bar" placeholder="Search models..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off" aria-label="Search models" aria-expanded="false" aria-controls="search-dropdown" enterkeyhint="search">
                    </form>
                    <div id="search-dropdown" class="search-dropdown" hidden>
                        <div id="search-recent" class="search-dropdown-section" hidden>
                            <div class="search-dropdown-header">
                                <span>Recent</span>
                                <button type="button" id="search-clear-recent" class="search-dropdown-clear">Clear</button>
                            </div>
                            <ul id="search-recent-list" class="search-dropdown-list" role="listbox"></ul>
                        </div>
                        <div id="search-results" class="search-dropdown-section" hidden>
                            <div class="search-dropdown-header"><span>Models</span></div>
                            <ul id="search-results-list" class="search-dropdown-list" role="listbox"></ul>
                        </div>
                        <div class="search-dropdown-section" hidden>
                            <a id="search-browse-link" href="<?= route('browse') ?>" class="search-dropdown-item search-browse-all">
                                <span class="search-dropdown-icon"><i class="fa-solid fa-magnifying-glass"></i></span>Search for "<span class="search-browse-query"></span>"
                            </a>
                        </div>
                    </div>
                </div>
                <?php if (isFeatureEnabled('dark_theme') && $allowUserTheme) : ?>
                <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme" aria-label="Toggle light/dark theme">
                    <span id="theme-icon" aria-hidden="true"><?= $currentTheme === 'light' ? '<i class="fa-solid fa-moon"></i>' : '<i class="fa-solid fa-sun"></i>' ?></span>
                </button>
                <?php endif; ?>
                <?php if (isLoggedIn()) : ?>
                    <?php $user = getCurrentUser(); ?>
                    <?php if (isFeatureEnabled('favorites')) : ?>
                    <a href="<?= route('favorites') ?>" class="btn btn-secondary" title="My Favorites" aria-label="My favorites"><i class="fa-solid fa-heart" aria-hidden="true"></i></a>
                    <?php endif; ?>
                    <div class="queue-indicator" id="queue-indicator">
                        <button type="button" class="btn btn-secondary queue-btn" onclick="toggleQueueDropdown()" aria-label="Background tasks" aria-haspopup="true" aria-expanded="false" aria-controls="queue-dropdown">
                            <i class="fa-solid fa-list-check" aria-hidden="true"></i><span class="queue-badge" id="queue-badge" style="display:none;">0</span>
                        </button>
                        <div class="queue-dropdown" id="queue-dropdown">
                            <div class="queue-dropdown-header">Background Tasks</div>
                            <div class="queue-dropdown-body" id="queue-dropdown-body" aria-live="polite">
                                <div class="queue-empty">No active tasks</div>
                            </div>
                        </div>
                    </div>
                    <?php if ($user['is_admin']) : ?>
                        <a href="<?= route('admin.settings') ?>" class="btn btn-secondary">Admin</a>
                    <?php endif; ?>
                    <div class="user-dropdown">
                        <button type="button" class="user-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                            <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                            <span class="dropdown-arrow"><i class="fa-solid fa-chevron-down"></i></span>
                        </button>
                        <div class="user-dropdown-menu" role="menu">
                            <a href="<?= route('settings') ?>" role="menuitem"><i class="fa-solid fa-gear"></i> Settings</a>
                            <?php if (isFeatureEnabled('favorites')) : ?>
                            <a href="<?= route('favorites') ?>" role="menuitem"><i class="fa-solid fa-heart"></i> Favorites</a>
                            <?php endif; ?>
                            <div class="dropdown-divider" role="separator"></div>
                            <a href="<?= route('logout') ?>" role="menuitem"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
                        </div>
                    </div>
                <?php else : ?>
                    <a href="<?= route('login') ?>" class="btn btn-primary">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
<?php endif; ?>

    <main id="main-content"<?= !empty($minimalHeader) ? ' class="auth-main"' : '' ?>>
