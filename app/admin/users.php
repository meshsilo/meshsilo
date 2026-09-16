<?php
require_once __DIR__ . '/../../includes/config.php';

// Require user management permission
requireAdminPage('canManageUsers', 'You do not have permission to manage users.');

$pageTitle = 'Manage Users';
$activePage = '';
$adminPage = 'users';

$db = getDB();

// Handle form submissions
$message = '';
$error = '';

// CSRF protection for all POST requests
if (($csrfError = Csrf::postError()) !== null) {
    $error = $csrfError;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedGroups = $_POST['groups'] ?? [];

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_ARGON2ID);
                $stmt = $db->prepare('INSERT INTO users (username, email, password, is_admin) VALUES (:username, :email, :password, 0)');
                $stmt->bindValue(':username', $username, PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                $stmt->bindValue(':password', $hash, PDO::PARAM_STR);
                $stmt->execute();

                $userId = $db->lastInsertRowID();

                // Assign groups
                foreach ($selectedGroups as $groupId) {
                    $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':group_id', (int)$groupId, PDO::PARAM_INT);
                    $stmt->execute();
                }

                // Update is_admin based on Admin group membership
                $adminGroupId = $db->querySingle("SELECT id FROM groups WHERE name = 'Admin'");
                if ($adminGroupId && in_array($adminGroupId, $selectedGroups)) {
                    $adminStmt = $db->prepare('UPDATE users SET is_admin = 1 WHERE id = :user_id');
                    $adminStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                    $adminStmt->execute();
                }

                if (class_exists('PluginManager')) {
                    PluginManager::doAction('user_registered', $userId, [
                        'username' => $username,
                        'email' => $email,
                        'method' => 'admin'
                    ]);
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
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
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
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            // Add new group associations
            foreach ($selectedGroups as $groupId) {
                $stmt = $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                $stmt->bindValue(':user_id', $id, PDO::PARAM_INT);
                $stmt->bindValue(':group_id', (int)$groupId, PDO::PARAM_INT);
                $stmt->execute();
            }

            // Update is_admin based on Admin group membership
            $isAdmin = $adminGroupId && in_array($adminGroupId, $selectedGroups) ? 1 : 0;
            $stmt = $db->prepare('UPDATE users SET is_admin = :is_admin WHERE id = :id');
            $stmt->bindValue(':is_admin', $isAdmin, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
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
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $groups[$row['id']] = $row;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$totalUsers = (int)$db->querySingle('SELECT COUNT(*) FROM users');
$totalPages = (int)ceil($totalUsers / $perPage);

// Get this page of users
$stmt = $db->prepare('SELECT * FROM users ORDER BY username LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$result = $stmt->execute();

$users = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $row['group_ids'] = [];
    $users[] = $row;
}

// Fetch every user's group memberships in one query rather than one per user
// (same batching shape as getFirstPartsForModels() in helpers/storage-helpers.php).
$userIds = array_column($users, 'id');
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare("SELECT user_id, group_id FROM user_groups WHERE user_id IN ($placeholders)");

    $index = 1;
    foreach ($userIds as $id) {
        $stmt->bindValue($index++, $id, PDO::PARAM_INT);
    }
    $stmt->execute();

    $groupIdsByUser = [];
    while ($row = $stmt->fetch()) {
        $groupIdsByUser[$row['user_id']][] = $row['group_id'];
    }

    foreach ($users as &$user) {
        $user['group_ids'] = $groupIdsByUser[$user['id']] ?? [];
    }
    unset($user);
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Users</h1>
                    <p>Manage user accounts and group memberships</p>
                </div>

                <?php if ($message): ?>
                <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <details class="settings-section">
                        <summary><h2>Add User</h2></summary>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" class="form-input" required autocomplete="username">
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" class="form-input" required autocomplete="email">
                                </div>
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" class="form-input" required autocomplete="new-password">
                                </div>
                            </div>
                            <fieldset class="form-group">
                                <legend>Groups</legend>
                                <div class="checkbox-group">
                                    <?php foreach ($groups as $group): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>" <?= $group['name'] === 'Users' ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($group['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                            <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                        </form>
                    </details>

                    <details class="settings-section" open>
                        <summary><h2>Existing Users</h2></summary>
                        <table class="data-table" aria-label="Users">
                            <thead>
                                <tr>
                                    <th scope="col">Username</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Groups</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Actions</th>
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
                                            <?= csrf_field() ?>
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
                                            <?php
                                            // The current user's own Admin checkbox is rendered disabled (above) and so
                                            // is never submitted. Re-add it via a hidden input ONLY when the user is
                                            // ALREADY in the Admin group. Emitting it unconditionally let a non-admin
                                            // with PERM_MANAGE_USERS grant themselves Admin by saving their own row,
                                            // since is_admin is derived from the submitted groups[] (privilege escalation).
                                            $adminGroupId = array_search('Admin', array_column($groups, 'name', 'id'));
                                            if ($user['id'] === getCurrentUser()['id'] && $adminGroupId !== false && in_array($adminGroupId, $user['group_ids'])):
                                            ?>
                                            <input type="hidden" name="groups[]" value="<?= (int)$adminGroupId ?>">
                                            <?php endif; ?>
                                            <button type="submit" name="update_groups" class="btn btn-small btn-secondary">Save</button>
                                        </form>
                                    </td>
                                    <td><?= !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'Unknown' ?></td>
                                    <td>
                                        <a href="<?= route('admin.user', ['id' => $user['id']]) ?>" class="btn btn-small btn-secondary">Edit</a>
                                        <?php if ($user['id'] !== getCurrentUser()['id']): ?>
                                        <form method="post" style="display:inline;" data-confirm="Delete this user? This cannot be undone.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" name="delete_user" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php
                        $paginationUrl = fn (int $p): string => '?' . http_build_query(array_merge($_GET, ['page' => $p]));
                        $paginationAttrs = 'style="margin-top: 1.5rem;"';
                        include __DIR__ . '/../../includes/partials/pagination.php';
                        ?>
                    </details>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
