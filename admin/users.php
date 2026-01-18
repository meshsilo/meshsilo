<?php
require_once '../includes/config.php';
$baseDir = '../';
$pageTitle = 'Manage Users';
$activePage = '';
$adminPage = 'users';

$db = getDB();

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username, email, password, is_admin) VALUES (:username, :email, :password, :is_admin)');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':password', $hash, SQLITE3_TEXT);
                $stmt->bindValue(':is_admin', $is_admin, SQLITE3_INTEGER);
                $stmt->execute();
                header('Location: users.php?success=1');
                exit;
            } catch (Exception $e) {
                $error = 'Username or email already exists.';
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = (int)$_POST['user_id'];
        $currentUser = getCurrentUser();
        if ($id === $currentUser['id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: users.php?deleted=1');
            exit;
        }
    } elseif (isset($_POST['toggle_admin'])) {
        $id = (int)$_POST['user_id'];
        $currentUser = getCurrentUser();
        if ($id === $currentUser['id']) {
            $error = 'You cannot change your own admin status.';
        } else {
            $stmt = $db->prepare('UPDATE users SET is_admin = NOT is_admin WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: users.php?updated=1');
            exit;
        }
    }
}

if (isset($_GET['success'])) {
    $message = 'User added successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'User deleted.';
} elseif (isset($_GET['updated'])) {
    $message = 'User updated.';
}

// Get users
$result = $db->query('SELECT * FROM users ORDER BY username');
$users = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $row;
}

require_once '../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once '../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Users</h1>
                    <p>Manage user accounts</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <section class="settings-section">
                        <h2>Add User</h2>
                        <form method="post">
                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" class="form-input" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-inline">
                                    <input type="checkbox" name="is_admin">
                                    <span>Administrator</span>
                                </label>
                            </div>
                            <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                        </form>
                    </section>

                    <section class="settings-section">
                        <h2>Existing Users</h2>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <span class="badge <?= $user['is_admin'] ? 'badge-admin' : 'badge-user' ?>">
                                            <?= $user['is_admin'] ? 'Admin' : 'User' ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" name="toggle_admin" class="btn btn-small btn-secondary">
                                                <?= $user['is_admin'] ? 'Remove Admin' : 'Make Admin' ?>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" name="delete_user" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </div>

<?php require_once '../includes/footer.php'; ?>
