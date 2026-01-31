<?php
require_once __DIR__ . '/../../includes/config.php';
// Set baseDir based on how we're accessed (router vs direct)
// Router loads from root context, direct access needs ../
$baseDir = isset($_SERVER['ROUTE_NAME']) ? '' : '../';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name'] ?? '');
        if (!empty($name)) {
            try {
                $stmt = $db->prepare('INSERT INTO categories (name) VALUES (:name)');
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->execute();
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
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <section class="settings-section">
                        <h2>Add Category</h2>
                        <form method="post">
                            <div class="input-with-button">
                                <input type="text" name="category_name" class="form-input" placeholder="New category name" required>
                                <button type="submit" name="add_category" class="btn btn-primary">Add</button>
                            </div>
                        </form>
                    </section>

                    <section class="settings-section">
                        <h2>Existing Categories</h2>
                        <?php if (empty($categories)): ?>
                            <p class="text-muted">No categories yet.</p>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Models</th>
                                        <th>Actions</th>
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
                                            $count = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC)['count'];
                                            echo $count;
                                            ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category?');">
                                                <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                                <button type="submit" name="delete_category" class="btn btn-small btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
