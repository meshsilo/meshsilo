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
    <meta name="theme-color" content="#3b82f6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="description" content="<?= htmlspecialchars(getSetting('site_description', 'Digital Asset Manager for 3D print files')) ?>">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?> - <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="manifest" href="<?= basePath('manifest.json') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= basePath('images/icon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= basePath('images/favicon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= basePath('images/favicon-16.png') ?>">
    <link rel="apple-touch-icon" href="<?= basePath('images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= basePath('css/style.css') ?>?v=8">

    <!-- Three.js for 3D model rendering -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/fflate@0.8.0/umd/index.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/3MFLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/PLYLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/ColladaLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/FBXLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/TDSLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/AMFLoader.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js" crossorigin="anonymous"></script>
    <script src="<?= basePath('js/viewer.js') ?>?v=15" defer></script>
    <script src="<?= basePath('js/main.js') ?>?v=8" defer></script>
    <script>
        // Theme toggle functionality
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            document.cookie = 'meshsilo_theme=' + newTheme + ';path=/;max-age=31536000';
            updateThemeIcon(newTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.getElementById('theme-icon');
            if (icon) {
                icon.textContent = theme === 'light' ? '\u263E' : '\u2600';
            }
        }

        // Mobile menu toggle
        function toggleMobileMenu() {
            const nav = document.querySelector('.main-nav');
            const toggle = document.querySelector('.mobile-menu-toggle');
            const isOpen = nav.classList.toggle('mobile-open');
            toggle.setAttribute('aria-expanded', isOpen);
            document.body.classList.toggle('mobile-menu-open', isOpen);
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(e) {
            const nav = document.querySelector('.main-nav');
            const toggle = document.querySelector('.mobile-menu-toggle');
            if (nav && nav.classList.contains('mobile-open') &&
                !nav.contains(e.target) && !toggle.contains(e.target)) {
                nav.classList.remove('mobile-open');
                toggle.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('mobile-menu-open');
            }
        });

        // Queue indicator
        function toggleQueueDropdown() {
            var dropdown = document.getElementById('queue-dropdown');
            var indicator = document.getElementById('queue-indicator');
            if (dropdown && indicator) {
                indicator.classList.toggle('open');
            }
        }

        function refreshQueueStatus() {
            fetch('/actions/queue-status', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var badge = document.getElementById('queue-badge');
                var body = document.getElementById('queue-dropdown-body');
                if (!badge || !body) return;

                if (data.active > 0) {
                    badge.textContent = data.active;
                    badge.style.display = '';
                    var html = '';
                    data.jobs.forEach(function(job) {
                        var statusClass = job.status === 'processing' ? 'queue-job-processing' : 'queue-job-pending';
                        var statusIcon = job.status === 'processing' ? '&#9654;' : '&#9679;';
                        var ago = timeAgo(job.created_at);
                        html += '<div class="queue-job ' + statusClass + '">' +
                            '<span class="queue-job-status">' + statusIcon + '</span>' +
                            '<span class="queue-job-name">' + escapeHtml(job.name) + '</span>' +
                            '<span class="queue-job-time">' + ago + '</span>' +
                            '</div>';
                    });
                    body.innerHTML = html;
                } else {
                    badge.style.display = 'none';
                    body.innerHTML = '<div class="queue-empty">No active tasks</div>';
                }
            })
            .catch(function() {});
        }

        function timeAgo(dateStr) {
            var now = new Date();
            var then = new Date(dateStr.replace(' ', 'T') + 'Z');
            var diff = Math.floor((now - then) / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        function escapeHtml(str) {
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Register Service Worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('SW registered:', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('SW registration failed:', error);
                    });
            });
        }
    </script>
    <?php if (isLoggedIn()) : ?>
        <?= Csrf::metaTag() ?>
        <?= Csrf::ajaxSetupScript() ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                refreshQueueStatus();
                setInterval(refreshQueueStatus, 15000);
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
</head>
<body>
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
            <nav class="main-nav">
                <a href="<?= route('browse') ?>" <?= ($activePage ?? '') === 'browse' ? 'class="active"' : '' ?>>Browse</a>
                <?php if (isFeatureEnabled('categories')) : ?>
                <a href="<?= route('categories') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('collections')) : ?>
                <a href="<?= route('collections') ?>" <?= ($activePage ?? '') === 'collections' ? 'class="active"' : '' ?>>Collections</a>
                <?php endif; ?>
                <?php if (isFeatureEnabled('tags')) : ?>
                <a href="<?= route('tags') ?>" <?= ($activePage ?? '') === 'tags' ? 'class="active"' : '' ?>>Tags</a>
                <?php endif; ?>
                <?php if (canUpload()) : ?>
                <a href="<?= route('upload') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active"' : '' ?>>Upload</a>
                <?php endif; ?>
<?php if (class_exists('PluginManager')) :
    $pluginNavItems = PluginManager::applyFilter('nav_items', []);
    foreach ($pluginNavItems as $navItem) : ?>
    <a href="<?= htmlspecialchars($navItem['url'] ?? '#') ?>" <?= !empty($navItem['active']) ? 'class="active"' : '' ?>>
        <?php if (!empty($navItem['icon'])) :
            ?><i data-feather="<?= htmlspecialchars($navItem['icon']) ?>"></i> <?php
        endif; ?>
        <?= htmlspecialchars($navItem['label'] ?? '') ?>
    </a>
    <?php endforeach;
endif; ?>
            </nav>
            <div class="header-actions">
                <form action="<?= route('browse') ?>" method="get" style="display: contents;">
                    <input type="search" name="q" class="search-bar" placeholder="Search models..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                </form>
                <?php if (isFeatureEnabled('dark_theme') && $allowUserTheme) : ?>
                <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
                    <span id="theme-icon"><?= $currentTheme === 'light' ? '&#9790;' : '&#9728;' ?></span>
                </button>
                <?php endif; ?>
                <?php if (isLoggedIn()) : ?>
                    <?php $user = getCurrentUser(); ?>
                    <?php if (isFeatureEnabled('favorites')) : ?>
                    <a href="<?= route('favorites') ?>" class="btn btn-secondary" title="My Favorites">&#9829;</a>
                    <?php endif; ?>
                    <div class="queue-indicator" id="queue-indicator" title="Background Tasks">
                        <button type="button" class="btn btn-secondary queue-btn" onclick="toggleQueueDropdown()">
                            &#9881;<span class="queue-badge" id="queue-badge" style="display:none;">0</span>
                        </button>
                        <div class="queue-dropdown" id="queue-dropdown">
                            <div class="queue-dropdown-header">Background Tasks</div>
                            <div class="queue-dropdown-body" id="queue-dropdown-body">
                                <div class="queue-empty">No active tasks</div>
                            </div>
                        </div>
                    </div>
                    <?php if ($user['is_admin']) : ?>
                        <a href="<?= route('admin.settings') ?>" class="btn btn-secondary">Admin</a>
                    <?php endif; ?>
                    <div class="user-dropdown">
                        <button type="button" class="user-dropdown-toggle">
                            <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                            <span class="dropdown-arrow">&#9662;</span>
                        </button>
                        <div class="user-dropdown-menu">
                            <a href="<?= route('settings') ?>">&#9881; Settings</a>
                            <?php if (isFeatureEnabled('favorites')) : ?>
                            <a href="<?= route('favorites') ?>">&#9829; Favorites</a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?= route('logout') ?>">&#10140; Log Out</a>
                        </div>
                    </div>
                <?php else : ?>
                    <a href="<?= route('login') ?>" class="btn btn-primary">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
