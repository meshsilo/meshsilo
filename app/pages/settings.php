<?php
/**
 * User Settings Page
 * Allows users to view and update their profile, change password, etc.
 */

require_once __DIR__ . '/../../includes/config.php';

// Require authentication
if (!isLoggedIn()) {
    header('Location: ' . route('login'));
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Get full user data from database
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->bindValue(':id', $userId, PDO::PARAM_INT);
$result = $stmt->execute();
$user = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$user) {
    // User not found in database - session invalid
    session_destroy();
    header('Location: ' . route('login'));
    exit;
}

// Check if user is OIDC-only (no local password)
$isOIDCOnly = !empty($user['oidc_id']) && empty($user['password']);

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $error = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                // Check if email is already used by another user
                $stmt = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
                $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
                $result = $stmt->execute();
                $existing = $result->fetchArray(PDO::FETCH_ASSOC);

                if ($existing) {
                    $error = 'This email address is already in use.';
                } else {
                    $stmt = $db->prepare('UPDATE users SET email = :email WHERE id = :id');
                    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
                    $stmt->execute();

                    // Update session
                    $_SESSION['user']['email'] = $email;
                    $user['email'] = $email;
                    $message = 'Profile updated successfully.';

                    logActivity('profile_update', 'user', $userId, $user['username'], ['field' => 'email']);
                }
            } catch (Exception $e) {
                $error = 'Failed to update profile. Please try again.';
                logError('Profile update failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($isOIDCOnly) {
            $error = 'Password cannot be changed for SSO-only accounts.';
        } elseif (empty($currentPassword)) {
            $error = 'Current password is required.';
        } elseif (!verifyPassword($currentPassword, $user['password'])) {
            $error = 'Current password is incorrect.';
            logSecurityWarning('Failed password change attempt - wrong current password', [
                'user_id' => $userId,
                'username' => $user['username']
            ]);
        } elseif (empty($newPassword)) {
            $error = 'New password cannot be empty.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif ($currentPassword === $newPassword) {
            $error = 'New password must be different from current password.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
            $stmt->bindValue(':password', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $message = 'Password changed successfully.';

            logAuthEvent('password_change', $user['username'], true, ['user_id' => $userId]);
            logActivity('password_change', 'user', $userId, $user['username']);
        }
    } elseif (isset($_POST['set_password'])) {
        // For OIDC users who want to set a local password
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$isOIDCOnly) {
            $error = 'You already have a password set.';
        } elseif (empty($newPassword)) {
            $error = 'Password cannot be empty.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
            $stmt->bindValue(':password', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            // Update local state
            $isOIDCOnly = false;
            $user['password'] = $hash;

            $message = 'Password set successfully. You can now log in with your username and password.';

            logAuthEvent('password_set', $user['username'], true, ['user_id' => $userId, 'method' => 'oidc_to_local']);
            logActivity('password_set', 'user', $userId, $user['username']);
        }
    }
}

// Get user's groups
$stmt = $db->prepare('
    SELECT g.name, g.description
    FROM groups g
    JOIN user_groups ug ON g.id = ug.group_id
    WHERE ug.user_id = :user_id
    ORDER BY g.name
');
$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$groupResult = $stmt->execute();
$userGroups = [];
while ($g = $groupResult->fetchArray(PDO::FETCH_ASSOC)) {
    $userGroups[] = $g;
}

$pageTitle = 'Settings';
$activePage = 'settings';
require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="settings-page">
            <div class="page-header">
                <h1>Account Settings</h1>
                <p>Manage your profile and security settings</p>
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
                                <input type="text" id="username" class="form-input"
                                       value="<?= htmlspecialchars($user['username']) ?>" disabled>
                                <p class="form-hint">Username cannot be changed. Contact an administrator if needed.</p>
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
                                <input type="text" class="form-input" value="<?= date('F j, Y', strtotime($user['created_at'])) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Account Type</label>
                                <input type="text" class="form-input" value="<?= $user['oidc_id'] ? 'SSO (Single Sign-On)' : 'Local Account' ?>" disabled>
                            </div>
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Email</button>
                    </form>
                </section>

                <!-- Password Section -->
                <section class="settings-section">
                    <h2><?= $isOIDCOnly ? 'Set Password' : 'Change Password' ?></h2>

                    <?php if ($isOIDCOnly): ?>
                    <p class="text-muted">Your account uses Single Sign-On (SSO). You can optionally set a local password to also log in with your username and password.</p>
                    <form method="post">
                        <div class="form-row-grid">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-input"
                                       minlength="8" placeholder="Minimum 8 characters" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                                       placeholder="Re-enter password" required>
                            </div>
                        </div>
                        <button type="submit" name="set_password" class="btn btn-primary">Set Password</button>
                    </form>
                    <?php else: ?>
                    <form method="post">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-input"
                                   placeholder="Enter your current password" required>
                        </div>
                        <div class="form-row-grid">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-input"
                                       minlength="8" placeholder="Minimum 8 characters" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                                       placeholder="Re-enter new password" required>
                            </div>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                    </form>
                    <?php endif; ?>
                </section>

                <!-- Groups & Permissions Section -->
                <section class="settings-section">
                    <h2>Groups & Permissions</h2>
                    <p class="text-muted">Your account is a member of the following groups:</p>

                    <?php if (empty($userGroups)): ?>
                    <p class="text-muted"><em>No groups assigned. Contact an administrator for access.</em></p>
                    <?php else: ?>
                    <div class="group-list">
                        <?php foreach ($userGroups as $group): ?>
                        <div class="group-item">
                            <span class="group-name"><?= htmlspecialchars($group['name']) ?></span>
                            <?php if ($group['description']): ?>
                            <span class="group-desc">- <?= htmlspecialchars($group['description']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <h3>Your Permissions</h3>
                    <div class="permission-list">
                        <?php
                        $userPermissions = getUserPermissions($userId);
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

                <!-- Session Info -->
                <section class="settings-section">
                    <h2>Session Information</h2>
                    <div class="form-row-grid">
                        <div class="form-group">
                            <label>Session ID</label>
                            <input type="text" class="form-input" value="<?= htmlspecialchars(substr(session_id(), 0, 8)) ?>..." disabled>
                        </div>
                        <div class="form-group">
                            <label>IP Address</label>
                            <input type="text" class="form-input" value="<?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?>" disabled>
                        </div>
                    </div>
                </section>
            </div>
        </div>

<style>
.settings-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 2rem;
}
.settings-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.settings-section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}
.settings-section h3 {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    font-size: 1rem;
}
.form-row-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}
.form-hint {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}
.group-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.group-item {
    padding: 0.5rem 0.75rem;
    background: var(--bg-tertiary);
    border-radius: 4px;
}
.group-name {
    font-weight: 500;
}
.group-desc {
    color: var(--text-secondary);
    font-size: 0.9rem;
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
    font-size: 0.9rem;
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
.text-muted {
    color: var(--text-secondary);
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
