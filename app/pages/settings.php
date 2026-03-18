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


$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (isset($_POST['update_profile'])) {
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

        if (empty($currentPassword)) {
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
            <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="settings-form">
                <!-- Profile Section -->
                <section class="settings-section">
                    <h2>Profile Information</h2>
                    <form method="post">
                        <?= csrf_field() ?>
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
                                       value="<?= htmlspecialchars($user['email']) ?>" required autocomplete="email">
                            </div>
                        </div>
                        <div class="form-row-grid">
                            <div class="form-group">
                                <label for="account-created">Account Created</label>
                                <input type="text" id="account-created" class="form-input" value="<?= date('F j, Y', strtotime($user['created_at'])) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label for="account-type">Account Type</label>
                                <input type="text" id="account-type" class="form-input" value="Local Account" disabled>
                            </div>
                        </div>
                        <?php if (class_exists('PluginManager')): ?>
                        <?= PluginManager::applyFilter('user_profile_fields', '', $user) ?>
                        <?php endif; ?>
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Email</button>
                    </form>
                </section>

                <!-- Password Section -->
                <section class="settings-section">
                    <h2>Change Password</h2>

                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="current_password" name="current_password" class="form-input"
                                       placeholder="Enter your current password" required autocomplete="current-password">
                                <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                        </div>
                        <div class="form-row-grid">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password" class="form-input"
                                           minlength="8" placeholder="Minimum 8 characters" required autocomplete="new-password" aria-describedby="pw-strength-text">
                                    <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                </div>
                                <div class="password-strength"><div class="password-strength-bar" id="pw-strength-bar"></div></div>
                                <div class="password-strength-text" id="pw-strength-text"></div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                                           placeholder="Re-enter new password" required autocomplete="new-password">
                                    <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                    </form>
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
                            <label for="session-id">Session ID</label>
                            <input type="text" id="session-id" class="form-input" value="<?= htmlspecialchars(substr(session_id(), 0, 8)) ?>..." disabled>
                        </div>
                        <div class="form-group">
                            <label for="ip-address">IP Address</label>
                            <input type="text" id="ip-address" class="form-input" value="<?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?>" disabled>
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
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.settings-section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border);
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
    color: var(--color-text-muted);
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
    background: var(--color-surface-hover);
    border-radius: 4px;
}
.group-name {
    font-weight: 500;
}
.group-desc {
    color: var(--color-text-muted);
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
    background: var(--color-surface-hover);
    border-radius: 4px;
    font-size: 0.9rem;
}
.permission-item.has-permission {
    color: var(--color-success);
}
.permission-item.no-permission {
    color: var(--color-text-muted);
    opacity: 0.6;
}
.permission-status {
    font-weight: bold;
}
.text-muted {
    color: var(--color-text-muted);
}
</style>

<script>
(function() {
    var pw = document.getElementById('new_password');
    var bar = document.getElementById('pw-strength-bar');
    var text = document.getElementById('pw-strength-text');
    if (!pw || !bar) return;
    pw.addEventListener('input', function() {
        var v = pw.value, score = 0;
        if (v.length >= 8) score++;
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^a-zA-Z0-9]/.test(v)) score++;
        var levels = [
            { w: '0%', c: '', t: '' },
            { w: '20%', c: '#ef4444', t: 'Weak' },
            { w: '40%', c: '#f97316', t: 'Fair' },
            { w: '60%', c: '#eab308', t: 'Good' },
            { w: '80%', c: '#22c55e', t: 'Strong' },
            { w: '100%', c: '#16a34a', t: 'Very strong' }
        ];
        var l = levels[score];
        bar.style.width = l.w;
        bar.style.backgroundColor = l.c;
        if (text) text.textContent = v.length === 0 ? '' : l.t;
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
