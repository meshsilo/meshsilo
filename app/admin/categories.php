<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

// Require feature to be enabled
requireFeature('categories');

// Require category management permission
if (!isLoggedIn() || !canManageCategories()) {
    $_SESSION['error'] = 'You do not have permission to manage categories.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Manage Categories';
$activePage = '';
$adminPage = 'categories';

// Get categories from database
$db = getDB();
$result = $db->query('SELECT * FROM categories ORDER BY name');
$categories = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
}

// Handle form submissions
$message = '';
$error = '';

// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name'] ?? '');
        if (!empty($name)) {
            try {
                $stmt = $db->prepare('INSERT INTO categories (name) VALUES (:name)');
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->execute();
                invalidateCategoriesCache();
                $message = 'Category added successfully.';
                header('Location: ' . route('admin.categories', [], ['success' => '1']));
                exit;
            } catch (Exception $e) {
                $error = 'Category already exists.';
            }
        } else {
            $error = 'Please enter a category name.';
        }
    } elseif (isset($_POST['delete_category'])) {
        $id = (int)$_POST['category_id'];
        $stmt = $db->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        invalidateCategoriesCache();
        header('Location: ' . route('admin.categories', [], ['deleted' => '1']));
        exit;
    }
}

if (isset($_GET['success'])) {
    $message = 'Category added successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Category deleted.';
}

// Refresh categories list
$result = $db->query('SELECT * FROM categories ORDER BY name');
$categories = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Categories</h1>
                    <p>Manage model categories</p>
                </div>

                <?php if ($message): ?>
                <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <details class="settings-section" open>
                        <summary><h2>Add Category</h2></summary>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="input-with-button">
                                <input type="text" name="category_name" class="form-input" placeholder="New category name" required>
                                <button type="submit" name="add_category" class="btn btn-primary">Add</button>
                            </div>
                        </form>
                    </details>

                    <details class="settings-section" open>
                        <summary><h2>Existing Categories</h2></summary>
                        <?php if (empty($categories)): ?>
                            <p class="text-muted">No categories yet.</p>
                        <?php else: ?>
                            <table class="admin-table" aria-label="Categories">
                                <thead>
                                    <tr>
                                        <th scope="col">Name</th>
                                        <th scope="col">Models</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($category['name']) ?></td>
                                        <td>
                                            <?php
                                            $stmt = $db->prepare('SELECT COUNT(*) as count FROM model_categories WHERE category_id = :id');
                                            $stmt->bindValue(':id', $category['id'], PDO::PARAM_INT);
                                            $execResult = $stmt->execute();
                                            $count = $execResult ? ($execResult->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
                                            echo $count;
                                            ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:inline;" data-confirm="Delete this category?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                                <button type="submit" name="delete_category" class="btn btn-small btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </details>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
