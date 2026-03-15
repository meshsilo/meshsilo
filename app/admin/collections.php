<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

// Require feature to be enabled
requireFeature('collections');

// Require collection management permission
if (!isLoggedIn() || !canManageCollections()) {
    $_SESSION['error'] = 'You do not have permission to manage collections.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Manage Collections';
$activePage = '';
$adminPage = 'collections';

$db = getDB();

// Handle form submissions
$message = '';
$error = '';

// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_collection'])) {
        $name = trim($_POST['collection_name'] ?? '');
        $description = trim($_POST['collection_description'] ?? '');
        if (!empty($name)) {
            try {
                $stmt = $db->prepare('INSERT INTO collections (name, description) VALUES (:name, :description)');
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':description', $description, PDO::PARAM_STR);
                $stmt->execute();
                header('Location: ' . route('admin.collections', [], ['success' => '1']));
                exit;
            } catch (Exception $e) {
                $error = 'Collection already exists.';
            }
        } else {
            $error = 'Please enter a collection name.';
        }
    } elseif (isset($_POST['delete_collection'])) {
        $id = (int)$_POST['collection_id'];
        $stmt = $db->prepare('DELETE FROM collections WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        header('Location: ' . route('admin.collections', [], ['deleted' => '1']));
        exit;
    }
}

if (isset($_GET['success'])) {
    $message = 'Collection added successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Collection deleted.';
}

// Get collections
$result = $db->query('SELECT * FROM collections ORDER BY name');
$collections = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $collections[] = $row;
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Collections</h1>
                    <p>Manage model collections (e.g., Gridfinity, Voron)</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <details class="settings-section" open>
                        <summary><h2>Add Collection</h2></summary>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label for="collection_name">Collection Name</label>
                                <input type="text" id="collection_name" name="collection_name" class="form-input" placeholder="e.g., Gridfinity, Voron" required>
                            </div>
                            <div class="form-group">
                                <label for="collection_description">Description</label>
                                <textarea id="collection_description" name="collection_description" class="form-input form-textarea" placeholder="Optional description..." rows="2"></textarea>
                            </div>
                            <button type="submit" name="add_collection" class="btn btn-primary">Add Collection</button>
                        </form>
                    </details>

                    <details class="settings-section" open>
                        <summary><h2>Existing Collections</h2></summary>
                        <?php if (empty($collections)): ?>
                            <p class="text-muted">No collections yet.</p>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Name</th>
                                        <th scope="col">Description</th>
                                        <th scope="col">Models</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($collections as $collection): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($collection['name']) ?></td>
                                        <td><?= htmlspecialchars($collection['description'] ?? '-') ?></td>
                                        <td>
                                            <?php
                                            $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE collection = :name');
                                            $stmt->bindValue(':name', $collection['name'], PDO::PARAM_STR);
                                            $execResult = $stmt->execute();
                                            $count = $execResult ? ($execResult->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
                                            echo $count;
                                            ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this collection?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="collection_id" value="<?= $collection['id'] ?>">
                                                <button type="submit" name="delete_collection" class="btn btn-small btn-danger">Delete</button>
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
