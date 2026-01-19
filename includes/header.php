<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? SITE_NAME ?> - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= basePath('css/style.css') ?>">

    <!-- Three.js for 3D model rendering -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fflate@0.8.0/umd/index.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/STLLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/3MFLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="<?= basePath('js/viewer.js') ?>" defer></script>
    <script src="<?= basePath('js/main.js') ?>" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="header-content">
            <a href="<?= basePath('index.php') ?>" class="logo">
                <span class="logo-icon">&#9653;</span>
                <span class="logo-text"><?= SITE_NAME ?></span>
            </a>
            <nav class="main-nav">
                <a href="<?= basePath('index.php') ?>" <?= ($activePage ?? '') === 'browse' ? 'class="active"' : '' ?>>Browse</a>
                <?php if (getSetting('enable_categories', '1') === '1'): ?>
                <a href="<?= basePath('categories.php') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                <?php endif; ?>
                <?php if (getSetting('enable_collections', '1') === '1'): ?>
                <a href="<?= basePath('collections.php') ?>" <?= ($activePage ?? '') === 'collections' ? 'class="active"' : '' ?>>Collections</a>
                <?php endif; ?>
                <?php if (canUpload()): ?>
                <a href="<?= basePath('upload.php') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active"' : '' ?>>Upload</a>
                <?php endif; ?>
            </nav>
            <div class="header-actions">
                <input type="search" class="search-bar" placeholder="Search models...">
                <?php if (isLoggedIn()): ?>
                    <?php $user = getCurrentUser(); ?>
                    <?php if ($user['is_admin']): ?>
                        <a href="<?= basePath('admin/settings.php') ?>" class="btn btn-secondary">Admin</a>
                    <?php endif; ?>
                    <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                    <a href="<?= basePath('logout.php') ?>" class="btn btn-primary">Log Out</a>
                <?php else: ?>
                    <a href="<?= basePath('login.php') ?>" class="btn btn-primary">Log In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
