<?php
require_once 'includes/config.php';
$pageTitle = 'Categories';
$activePage = 'categories';
require_once 'includes/header.php';

// Category data - will be loaded from database later
$categories = [
    ['slug' => 'functional', 'name' => 'Functional', 'icon' => '&#9881;', 'count' => 24],
    ['slug' => 'decorative', 'name' => 'Decorative', 'icon' => '&#10022;', 'count' => 18],
    ['slug' => 'tools', 'name' => 'Tools', 'icon' => '&#9874;', 'count' => 12],
    ['slug' => 'gaming', 'name' => 'Gaming', 'icon' => '&#9918;', 'count' => 31],
    ['slug' => 'art', 'name' => 'Art', 'icon' => '&#9733;', 'count' => 15],
    ['slug' => 'mechanical', 'name' => 'Mechanical', 'icon' => '&#9211;', 'count' => 9],
];
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>Categories</h1>
                <p>Browse models by category</p>
            </div>

            <div class="categories-grid-large">
                <?php foreach ($categories as $category): ?>
                <a href="category.php?cat=<?= htmlspecialchars($category['slug']) ?>" class="category-card-large">
                    <div class="category-icon"><?= $category['icon'] ?></div>
                    <h2 class="category-name"><?= htmlspecialchars($category['name']) ?></h2>
                    <p class="category-count"><?= $category['count'] ?> models</p>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
