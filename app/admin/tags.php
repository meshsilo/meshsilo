<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

requireFeature('tags');

if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to manage tags.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Manage Tags';
$activePage = '';
$adminPage = 'tags';

$db = getDB();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_tag'])) {
        $name = trim($_POST['tag_name'] ?? '');
        $color = trim($_POST['tag_color'] ?? '#6366f1');
        if (!empty($name)) {
            try {
                $stmt = $db->prepare('INSERT INTO tags (name, color) VALUES (:name, :color)');
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':color', $color, PDO::PARAM_STR);
                $stmt->execute();
                header('Location: ' . route('admin.tags', [], ['success' => '1']));
                exit;
            } catch (Exception $e) {
                $error = 'Tag already exists.';
            }
        } else {
            $error = 'Please enter a tag name.';
        }
    } elseif (isset($_POST['delete_tag'])) {
        $id = (int)$_POST['tag_id'];
        $db->prepare('DELETE FROM model_tags WHERE tag_id = :id')->bindValue(':id', $id, PDO::PARAM_INT)->execute();
        $stmt = $db->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        header('Location: ' . route('admin.tags', [], ['deleted' => '1']));
        exit;
    }
}

if (isset($_GET['success'])) {
    $message = 'Tag added successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Tag deleted.';
}

$result = $db->query('SELECT * FROM tags ORDER BY name');
$tags = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $tags[] = $row;
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Tags</h1>
                    <p>Manage model tags</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <details class="settings-section" open>
                        <summary><h2>Add Tag</h2></summary>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="input-with-button">
                                <input type="text" name="tag_name" class="form-input" placeholder="New tag name" required>
                                <input type="color" name="tag_color" value="#6366f1" style="height: 38px; border: 1px solid var(--color-border); border-radius: 4px;">
                                <button type="submit" name="add_tag" class="btn btn-primary">Add</button>
                            </div>
                        </form>
                    </details>

                    <details class="settings-section" open>
                        <summary><h2>Existing Tags</h2></summary>
                        <?php if (empty($tags)): ?>
                            <p class="text-muted">No tags yet.</p>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Color</th>
                                        <th>Name</th>
                                        <th>Models</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tags as $tag): ?>
                                    <tr>
                                        <td><span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:<?= htmlspecialchars($tag['color']) ?>;"></span></td>
                                        <td><?= htmlspecialchars($tag['name']) ?></td>
                                        <td>
                                            <?php
                                            $stmt = $db->prepare('SELECT COUNT(*) as count FROM model_tags WHERE tag_id = :id');
                                            $stmt->bindValue(':id', $tag['id'], PDO::PARAM_INT);
                                            $count = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC)['count'];
                                            echo $count;
                                            ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this tag? It will be removed from all models.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
                                                <button type="submit" name="delete_tag" class="btn btn-small btn-danger">Delete</button>
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
