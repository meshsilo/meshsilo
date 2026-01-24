<?php
// Determine current theme
$defaultTheme = getSetting('default_theme', 'dark');
$allowUserTheme = getSetting('allow_user_theme', '1') === '1';
$currentTheme = $defaultTheme;
if ($allowUserTheme && isset($_COOKIE['silo_theme'])) {
    $currentTheme = $_COOKIE['silo_theme'] === 'light' ? 'light' : 'dark';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? SITE_NAME ?> - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= basePath('css/style.css') ?>?v=2">

    <!-- Three.js for 3D model rendering -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fflate@0.8.0/umd/index.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/3MFLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/OBJLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/PLYLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/ColladaLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/FBXLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/TDSLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/AMFLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="<?= basePath('js/viewer.js') ?>?v=6" defer></script>
    <script src="<?= basePath('js/main.js') ?>?v=2" defer></script>
    <script>
        // Theme toggle functionality
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            document.cookie = 'silo_theme=' + newTheme + ';path=/;max-age=31536000';
            updateThemeIcon(newTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.getElementById('theme-icon');
            if (icon) {
                icon.textContent = theme === 'light' ? '\u263E' : '\u2600';
            }
        }
    </script>
</head>
<body>
    <header class="site-header">
        <div class="header-content">
            <a href="<?= route('home') ?>" class="logo">
                <span class="logo-icon">&#9653;</span>
                <span class="logo-text"><?= SITE_NAME ?></span>
            </a>
            <nav class="main-nav">
                <a href="<?= route('browse') ?>" <?= ($activePage ?? '') === 'browse' ? 'class="active"' : '' ?>>Browse</a>
                <?php if (getSetting('enable_categories', '1') === '1'): ?>
                <a href="<?= route('categories') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                <?php endif; ?>
                <?php if (getSetting('enable_collections', '1') === '1'): ?>
                <a href="<?= route('collections') ?>" <?= ($activePage ?? '') === 'collections' ? 'class="active"' : '' ?>>Collections</a>
                <?php endif; ?>
                <?php if (getSetting('enable_tags', '1') === '1'): ?>
                <a href="<?= route('tags') ?>" <?= ($activePage ?? '') === 'tags' ? 'class="active"' : '' ?>>Tags</a>
                <?php endif; ?>
                <?php if (canUpload()): ?>
                <a href="<?= route('upload') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active"' : '' ?>>Upload</a>
                <?php endif; ?>
            </nav>
            <div class="header-actions">
                <form action="<?= route('browse') ?>" method="get" style="display: contents;">
                    <input type="search" name="q" class="search-bar" placeholder="Search models..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                </form>
                <?php if ($allowUserTheme): ?>
                <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
                    <span id="theme-icon"><?= $currentTheme === 'light' ? '&#9790;' : '&#9728;' ?></span>
                </button>
                <?php endif; ?>
                <?php if (isLoggedIn()): ?>
                    <?php $user = getCurrentUser(); ?>
                    <a href="<?= route('print-queue') ?>" class="btn btn-secondary" title="Print Queue">&#128424;</a>
                    <a href="<?= route('favorites') ?>" class="btn btn-secondary" title="My Favorites">&#9829;</a>
                    <?php if ($user['is_admin']): ?>
                        <a href="<?= route('admin.settings') ?>" class="btn btn-secondary">Admin</a>
                    <?php endif; ?>
                    <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                    <a href="<?= route('logout') ?>" class="btn btn-primary">Log Out</a>
                <?php else: ?>
                    <a href="<?= route('login') ?>" class="btn btn-primary">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
