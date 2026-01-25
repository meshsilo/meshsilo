<?php
/**
 * Reset Password Page
 * Allows users to set a new password using a valid reset token
 */

require_once __DIR__ . '/../../includes/config.php';

// If already logged in, redirect to home
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$token = $_GET['token'] ?? '';
$error = '';
$message = '';
$validToken = false;
$resetComplete = false;
$tokenData = null;

$db = getDB();

// Validate token
if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    // Look up token and check expiration
    $stmt = $db->prepare('
        SELECT pr.*, u.id as user_id, u.username
        FROM password_resets pr
        JOIN users u ON u.email = pr.email
        WHERE pr.token = :token
          AND pr.used_at IS NULL
          AND pr.expires_at > :now
    ');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':now', date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $result = $stmt->execute();
    $tokenData = $result->fetchArray(SQLITE3_ASSOC);

    if (!$tokenData) {
        // Check if token exists but is expired or used
        $stmt = $db->prepare('SELECT * FROM password_resets WHERE token = :token');
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute();
        $expiredToken = $result->fetchArray(SQLITE3_ASSOC);

        if ($expiredToken) {
            if ($expiredToken['used_at']) {
                $error = 'This password reset link has already been used.';
            } else {
                $error = 'This password reset link has expired. Please request a new one.';
            }
        } else {
            $error = 'Invalid password reset link.';
        }

        logSecurityWarning('Invalid password reset token used', [
            'token_prefix' => substr($token, 0, 8) . '...',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } else {
        $validToken = true;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken && $tokenData) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword)) {
        $error = 'Please enter a new password.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Update password
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
        $stmt->bindValue(':password', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':id', $tokenData['user_id'], SQLITE3_INTEGER);
        $stmt->execute();

        // Mark token as used
        $stmt = $db->prepare('UPDATE password_resets SET used_at = :used_at WHERE id = :id');
        $stmt->bindValue(':used_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':id', $tokenData['id'], SQLITE3_INTEGER);
        $stmt->execute();

        // Delete all tokens for this email
        $stmt = $db->prepare('DELETE FROM password_resets WHERE email = :email');
        $stmt->bindValue(':email', $tokenData['email'], SQLITE3_TEXT);
        $stmt->execute();

        $resetComplete = true;
        $message = 'Your password has been reset successfully. You can now log in with your new password.';

        logAuthEvent('password_reset_completed', $tokenData['username'], true, [
            'user_id' => $tokenData['user_id'],
            'email' => $tokenData['email']
        ]);

        logActivity('password_reset', 'user', $tokenData['user_id'], $tokenData['username']);
    }
}

$pageTitle = 'Reset Password';
$activePage = '';
require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <span class="logo-icon">&#9653;</span>
                    <h1>Reset Password</h1>
                    <?php if ($validToken && !$resetComplete): ?>
                    <p>Enter your new password below</p>
                    <?php endif; ?>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($resetComplete): ?>
                <div class="text-center">
                    <p>You can now log in with your new password.</p>
                    <a href="/login" class="btn btn-primary" style="margin-top: 1rem;">Go to Login</a>
                </div>
                <?php elseif ($validToken): ?>
                <form class="auth-form" action="/reset-password?token=<?= htmlspecialchars(urlencode($token)) ?>" method="post">
                    <?php if ($tokenData): ?>
                    <div class="form-group">
                        <label>Resetting password for</label>
                        <input type="text" class="form-input" value="<?= htmlspecialchars($tokenData['email']) ?>" disabled>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" class="form-input"
                               placeholder="Enter new password" required minlength="8">
                        <p class="form-hint">Must be at least 8 characters</p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                               placeholder="Re-enter new password" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Reset Password</button>
                </form>
                <?php else: ?>
                <div class="text-center">
                    <p>Unable to reset password with this link.</p>
                    <a href="/forgot-password" class="btn btn-secondary" style="margin-top: 1rem;">Request New Reset Link</a>
                </div>
                <?php endif; ?>

                <div class="auth-footer">
                    <a href="/login" class="form-link">&larr; Back to Login</a>
                </div>
            </div>
        </div>

<style>
.auth-footer {
    margin-top: 1.5rem;
    text-align: center;
}
.text-center {
    text-align: center;
}
.form-hint {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    margin-top: 0.25rem;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
