<?php
require_once __DIR__ . '/../includes/config.php';
$baseDir = '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

$pageTitle = 'Manage Collections';
$activePage = '';
$adminPage = 'collections';

$db = getDB();

// Create collections table if it doesn't exist
$db->exec('CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_collection'])) {
        $name = trim($_POST['collection_name'] ?? '');
        $description = trim($_POST['collection_description'] ?? '');
        if (!empty($name)) {
            try {
                $stmt = $db->prepare('INSERT INTO collections (name, description) VALUES (:name, :description)');
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':description', $description, SQLITE3_TEXT);
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
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
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
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $collections[] = $row;
}

require_once '../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once '../includes/admin-sidebar.php'; ?>

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
                    <section class="settings-section">
                        <h2>Add Collection</h2>
                        <form method="post">
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
                    </section>

                    <section class="settings-section">
                        <h2>Existing Collections</h2>
                        <?php if (empty($collections)): ?>
                            <p class="text-muted">No collections yet.</p>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Models</th>
                                        <th>Actions</th>
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
                                            $stmt->bindValue(':name', $collection['name'], SQLITE3_TEXT);
                                            $count = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['count'];
                                            echo $count;
                                            ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this collection?');">
                                                <input type="hidden" name="collection_id" value="<?= $collection['id'] ?>">
                                                <button type="submit" name="delete_collection" class="btn btn-small btn-danger">Delete</button>
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

<?php require_once '../includes/footer.php'; ?>
