<?php
require_once __DIR__ . '/../includes/config.php';
$baseDir = '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

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
        $selectedGroups = $_POST['groups'] ?? [];

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username, email, password, is_admin) VALUES (:username, :email, :password, 0)');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':password', $hash, SQLITE3_TEXT);
                $stmt->execute();

                $userId = $db->lastInsertRowID();

                // Assign groups
                foreach ($selectedGroups as $groupId) {
                    $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->bindValue(':group_id', (int)$groupId, SQLITE3_INTEGER);
                    $stmt->execute();
                }

                // Update is_admin based on Admin group membership
                $adminGroupId = $db->querySingle("SELECT id FROM groups WHERE name = 'Admin'");
                if ($adminGroupId && in_array($adminGroupId, $selectedGroups)) {
                    $db->exec("UPDATE users SET is_admin = 1 WHERE id = $userId");
                }

                header('Location: ' . route('admin.users', [], ['success' => '1']));
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
            // Delete user group associations first
            $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: ' . route('admin.users', [], ['deleted' => '1']));
            exit;
        }
    } elseif (isset($_POST['update_groups'])) {
        $id = (int)$_POST['user_id'];
        $currentUser = getCurrentUser();
        $selectedGroups = $_POST['groups'] ?? [];

        // Get Admin group ID
        $adminGroupId = $db->querySingle("SELECT id FROM groups WHERE name = 'Admin'");

        // Prevent removing yourself from Admin group
        if ($id === $currentUser['id'] && $adminGroupId && !in_array($adminGroupId, $selectedGroups)) {
            $error = 'You cannot remove yourself from the Admin group.';
        } else {
            // Remove all existing group associations
            $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            // Add new group associations
            foreach ($selectedGroups as $groupId) {
                $stmt = $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                $stmt->bindValue(':user_id', $id, SQLITE3_INTEGER);
                $stmt->bindValue(':group_id', (int)$groupId, SQLITE3_INTEGER);
                $stmt->execute();
            }

            // Update is_admin based on Admin group membership
            $isAdmin = $adminGroupId && in_array($adminGroupId, $selectedGroups) ? 1 : 0;
            $stmt = $db->prepare('UPDATE users SET is_admin = :is_admin WHERE id = :id');
            $stmt->bindValue(':is_admin', $isAdmin, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            header('Location: ' . route('admin.users', [], ['updated' => '1']));
            exit;
        }
    }
}

if (isset($_GET['success'])) {
    $message = 'User added successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'User deleted.';
} elseif (isset($_GET['updated'])) {
    $message = 'User groups updated.';
}

// Get all groups
$result = $db->query('SELECT * FROM groups ORDER BY name');
$groups = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[$row['id']] = $row;
}

// Get users with their groups
$result = $db->query('SELECT * FROM users ORDER BY username');
$users = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // Get user's groups
    $stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $row['id'], SQLITE3_INTEGER);
    $groupResult = $stmt->execute();
    $row['group_ids'] = [];
    while ($g = $groupResult->fetchArray(SQLITE3_ASSOC)) {
        $row['group_ids'][] = $g['group_id'];
    }
    $users[] = $row;
}

require_once __DIR__ . '/../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Users</h1>
                    <p>Manage user accounts and group memberships</p>
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
                                <label>Groups</label>
                                <div class="checkbox-group">
                                    <?php foreach ($groups as $group): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>" <?= $group['name'] === 'Users' ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($group['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
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
                                    <th>Groups</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <a href="<?= route('admin.user', ['id' => $user['id']]) ?>" class="user-link"><?= htmlspecialchars($user['username']) ?></a>
                                        <?php if ($user['is_admin']): ?>
                                        <span class="badge badge-admin">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <div class="group-checkboxes">
                                                <?php foreach ($groups as $group): ?>
                                                <label class="checkbox-small" title="<?= htmlspecialchars($group['description'] ?? $group['name']) ?>">
                                                    <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>"
                                                        <?= in_array($group['id'], $user['group_ids']) ? 'checked' : '' ?>
                                                        <?= ($user['id'] === getCurrentUser()['id'] && $group['name'] === 'Admin') ? 'disabled' : '' ?>>
                                                    <span><?= htmlspecialchars($group['name']) ?></span>
                                                </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if ($user['id'] === getCurrentUser()['id']): ?>
                                            <input type="hidden" name="groups[]" value="<?= array_search('Admin', array_column($groups, 'name', 'id')) ?>">
                                            <?php endif; ?>
                                            <button type="submit" name="update_groups" class="btn btn-small btn-secondary">Save</button>
                                        </form>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <a href="<?= route('admin.user', ['id' => $user['id']]) ?>" class="btn btn-small btn-secondary">Edit</a>
                                        <?php if ($user['id'] !== getCurrentUser()['id']): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" name="delete_user" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
