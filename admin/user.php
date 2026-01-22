<?php
require_once __DIR__ . '/../includes/config.php';
$baseDir = '../';

// Require admin permission
requirePermission(PERM_ADMIN, $baseDir . 'index.php');

$pageTitle = 'Edit User';
$activePage = '';
$adminPage = 'users';

$db = getDB();

// Get user ID from URL
$userId = (int)($_GET['id'] ?? 0);

if (!$userId) {
    header('Location: ' . route('admin.users'));
    exit;
}

// Get user data
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

if (!$user) {
    header('Location: ' . route('admin.users', [], ['error' => 'notfound']));
    exit;
}

// Get user's groups
$stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = :user_id');
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$groupResult = $stmt->execute();
$userGroupIds = [];
while ($g = $groupResult->fetchArray(SQLITE3_ASSOC)) {
    $userGroupIds[] = $g['group_id'];
}

// Get all groups
$result = $db->query('SELECT * FROM groups ORDER BY name');
$groups = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[$row['id']] = $row;
}

// Get Admin group ID
$adminGroupId = $db->querySingle("SELECT id FROM groups WHERE name = 'Admin'");

$currentUser = getCurrentUser();
$isCurrentUser = ($userId === $currentUser['id']);

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($username) || empty($email)) {
            $error = 'Username and email are required.';
        } else {
            try {
                $stmt = $db->prepare('UPDATE users SET username = :username, email = :email WHERE id = :id');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
                $stmt->execute();

                $user['username'] = $username;
                $user['email'] = $email;
                $message = 'Profile updated successfully.';
                logAdmin('User profile updated', ['user_id' => $userId, 'username' => $username]);
            } catch (Exception $e) {
                $error = 'Username or email already exists.';
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword)) {
            $error = 'Password cannot be empty.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
            $stmt->bindValue(':password', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            $message = 'Password changed successfully.';
            logAdmin('User password changed', ['user_id' => $userId, 'username' => $user['username']]);
            logAudit('Password changed by admin', ['target_user_id' => $userId, 'target_username' => $user['username']]);
        }
    } elseif (isset($_POST['update_groups'])) {
        $selectedGroups = $_POST['groups'] ?? [];

        // Prevent removing yourself from Admin group
        if ($isCurrentUser && $adminGroupId && !in_array($adminGroupId, $selectedGroups)) {
            $error = 'You cannot remove yourself from the Admin group.';
        } else {
            // Remove all existing group associations
            $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :id');
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            // Add new group associations
            foreach ($selectedGroups as $groupId) {
                $stmt = $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $stmt->bindValue(':group_id', (int)$groupId, SQLITE3_INTEGER);
                $stmt->execute();
            }

            // Update is_admin based on Admin group membership
            $isAdmin = $adminGroupId && in_array($adminGroupId, $selectedGroups) ? 1 : 0;
            $stmt = $db->prepare('UPDATE users SET is_admin = :is_admin WHERE id = :id');
            $stmt->bindValue(':is_admin', $isAdmin, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            $userGroupIds = array_map('intval', $selectedGroups);
            $user['is_admin'] = $isAdmin;
            $message = 'Groups updated successfully.';
            logAdmin('User groups updated', ['user_id' => $userId, 'username' => $user['username'], 'groups' => $selectedGroups]);
        }
    } elseif (isset($_POST['delete_user'])) {
        if ($isCurrentUser) {
            $error = 'You cannot delete your own account.';
        } else {
            // Delete user group associations first
            $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :id');
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();

            logAdmin('User deleted', ['user_id' => $userId, 'username' => $user['username']]);
            logAudit('User account deleted', ['deleted_user_id' => $userId, 'deleted_username' => $user['username']]);

            header('Location: ' . route('admin.users', [], ['deleted' => '1']));
            exit;
        }
    }
}

// Get user's permissions
$userPermissions = getUserPermissions($userId);

require_once '../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once '../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>
                        <a href="<?= route('admin.users') ?>" class="back-link">&larr;</a>
                        Edit User: <?= htmlspecialchars($user['username']) ?>
                        <?php if ($user['is_admin']): ?>
                        <span class="badge badge-admin">Admin</span>
                        <?php endif; ?>
                    </h1>
                    <p>Manage user settings, password, and group memberships</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="settings-form">
                    <!-- Profile Section -->
                    <section class="settings-section">
                        <h2>Profile Information</h2>
                        <form method="post">
                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" name="username" class="form-input"
                                           value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" class="form-input"
                                           value="<?= htmlspecialchars($user['email']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label>Account Created</label>
                                    <input type="text" class="form-input" value="<?= date('F j, Y g:i A', strtotime($user['created_at'])) ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label>OIDC Linked</label>
                                    <input type="text" class="form-input" value="<?= $user['oidc_id'] ? 'Yes (' . htmlspecialchars(substr($user['oidc_id'], 0, 20)) . '...)' : 'No' ?>" disabled>
                                </div>
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                        </form>
                    </section>

                    <!-- Password Section -->
                    <section class="settings-section">
                        <h2>Change Password</h2>
                        <form method="post">
                            <div class="form-row-grid">
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" class="form-input"
                                           minlength="8" placeholder="Minimum 8 characters">
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                                           placeholder="Re-enter password">
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                        </form>
                    </section>

                    <!-- Groups Section -->
                    <section class="settings-section">
                        <h2>Group Memberships</h2>
                        <form method="post">
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <?php foreach ($groups as $group): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>"
                                            <?= in_array($group['id'], $userGroupIds) ? 'checked' : '' ?>
                                            <?= ($isCurrentUser && $group['name'] === 'Admin') ? 'disabled' : '' ?>>
                                        <span>
                                            <?= htmlspecialchars($group['name']) ?>
                                            <?php if ($group['description']): ?>
                                            <small class="text-muted">- <?= htmlspecialchars($group['description']) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($isCurrentUser): ?>
                                <input type="hidden" name="groups[]" value="<?= $adminGroupId ?>">
                                <p class="form-hint">You cannot remove yourself from the Admin group.</p>
                                <?php endif; ?>
                            </div>
                            <button type="submit" name="update_groups" class="btn btn-primary">Update Groups</button>
                        </form>
                    </section>

                    <!-- Effective Permissions Section -->
                    <section class="settings-section">
                        <h2>Effective Permissions</h2>
                        <p class="text-muted">Permissions granted through group memberships:</p>
                        <div class="permission-list">
                            <?php
                            $allPerms = getAllPermissions();
                            foreach ($allPerms as $perm => $desc):
                                $hasPerm = in_array($perm, $userPermissions);
                            ?>
                            <div class="permission-item <?= $hasPerm ? 'has-permission' : 'no-permission' ?>">
                                <span class="permission-status"><?= $hasPerm ? '&#10003;' : '&#10005;' ?></span>
                                <span class="permission-name"><?= htmlspecialchars($desc) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Danger Zone -->
                    <?php if (!$isCurrentUser): ?>
                    <section class="settings-section danger-zone">
                        <h2>Danger Zone</h2>
                        <form method="post" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                            <p>Permanently delete this user account. This cannot be undone.</p>
                            <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                        </form>
                    </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>

<style>
.back-link {
    color: var(--text-secondary);
    text-decoration: none;
    margin-right: 0.5rem;
}
.back-link:hover {
    color: var(--text-primary);
}
.permission-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.5rem;
}
.permission-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    background: var(--bg-tertiary);
    border-radius: 4px;
}
.permission-item.has-permission {
    color: var(--success);
}
.permission-item.no-permission {
    color: var(--text-secondary);
    opacity: 0.6;
}
.permission-status {
    font-weight: bold;
}
.danger-zone {
    border: 1px solid var(--danger);
    background: rgba(239, 68, 68, 0.05);
}
.danger-zone h2 {
    color: var(--danger);
}
</style>

<?php require_once '../includes/footer.php'; ?>
