<?php
require_once 'includes/config.php';

// Check if categories are enabled
if (getSetting('enable_categories', '1') !== '1') {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Categories';
$activePage = 'categories';

$db = getDB();

// Load categories from database with model counts
$result = $db->query('
    SELECT c.id, c.name, COUNT(mc.model_id) as count
    FROM categories c
    LEFT JOIN model_categories mc ON c.id = mc.category_id
    LEFT JOIN models m ON mc.model_id = m.id AND m.parent_id IS NULL
    GROUP BY c.id
    ORDER BY c.name
');

$categories = [];
$icons = [
    'Functional' => '&#9881;',
    'Decorative' => '&#10022;',
    'Tools' => '&#9874;',
    'Gaming' => '&#9918;',
    'Art' => '&#9733;',
    'Mechanical' => '&#9211;',
];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $row['icon'] = $icons[$row['name']] ?? '&#128193;';
    $categories[] = $row;
}

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>Categories</h1>
                <p>Browse models by category</p>
            </div>

            <div class="categories-grid-large">
                <?php foreach ($categories as $category): ?>
                <a href="category.php?id=<?= $category['id'] ?>" class="category-card-large">
                    <div class="category-icon"><?= $category['icon'] ?></div>
                    <h2 class="category-name"><?= htmlspecialchars($category['name']) ?></h2>
                    <p class="category-count"><?= $category['count'] ?> models</p>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
