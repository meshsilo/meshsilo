<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? SITE_NAME ?> - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= basePath('css/style.css') ?>">
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
                <a href="<?= basePath('categories.php') ?>" <?= ($activePage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                <a href="<?= basePath('upload.php') ?>" <?= ($activePage ?? '') === 'upload' ? 'class="active"' : '' ?>>Upload</a>
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
