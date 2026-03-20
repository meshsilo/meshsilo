<?php
/**
 * Reset Password Page
 * Allows users to set a new password using a valid reset token
 */

require_once __DIR__ . '/../../includes/config.php';

// If already logged in, redirect to home
if (isLoggedIn()) {
    header('Location: ' . route('home'));
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
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
    $result = $stmt->execute();
    $tokenData = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        // Check if token exists but is expired or used
        $stmt = $db->prepare('SELECT * FROM password_resets WHERE token = :token');
        $stmt->bindValue(':token', $token, PDO::PARAM_STR);
        $result = $stmt->execute();
        $expiredToken = $result->fetchArray(PDO::FETCH_ASSOC);

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

    if (!Csrf::validate()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (empty($newPassword)) {
        $error = 'Please enter a new password.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Update password
        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $stmt = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
        $stmt->bindValue(':password', $hash, PDO::PARAM_STR);
        $stmt->bindValue(':id', $tokenData['user_id'], PDO::PARAM_INT);
        $stmt->execute();

        // Mark token as used
        $stmt = $db->prepare('UPDATE password_resets SET used_at = :used_at WHERE id = :id');
        $stmt->bindValue(':used_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $tokenData['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Delete all tokens for this email
        $stmt = $db->prepare('DELETE FROM password_resets WHERE email = :email');
        $stmt->bindValue(':email', $tokenData['email'], PDO::PARAM_STR);
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
                    <?php $logoPath = getSetting('logo_path', ''); if ($logoPath): ?>
                    <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="auth-logo-img">
                    <?php endif; ?>
                    <h1>Reset Password</h1>
                    <?php if ($validToken && !$resetComplete): ?>
                    <p>Enter your new password below</p>
                    <?php endif; ?>
                </div>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($message): ?>
                <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($resetComplete): ?>
                <div class="text-center">
                    <p>You can now log in with your new password.</p>
                    <a href="<?= route('login') ?>" class="btn btn-primary" style="margin-top: 1rem;">Go to Login</a>
                </div>
                <?php elseif ($validToken): ?>
                <form class="auth-form" action="<?= route('reset-password') ?>?token=<?= htmlspecialchars(urlencode($token)) ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if ($tokenData): ?>
                    <div class="form-group">
                        <label for="reset-email">Resetting password for</label>
                        <input type="text" id="reset-email" class="form-input" value="<?= htmlspecialchars($tokenData['email']) ?>" disabled>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-input"
                                   placeholder="Enter new password" required minlength="8" autocomplete="new-password" aria-describedby="pw-strength-text">
                            <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                        <div class="password-strength"><div class="password-strength-bar" id="pw-strength-bar"></div></div>
                        <div class="password-strength-text" id="pw-strength-text">Must be at least 8 characters</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                                   placeholder="Re-enter new password" required autocomplete="new-password">
                            <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Reset Password</button>
                </form>
                <?php else: ?>
                <div class="text-center">
                    <p>Unable to reset password with this link.</p>
                    <a href="<?= route('forgot-password') ?>" class="btn btn-secondary" style="margin-top: 1rem;">Request New Reset Link</a>
                </div>
                <?php endif; ?>

                <div class="auth-footer">
                    <a href="<?= route('login') ?>" class="form-link">&larr; Back to Login</a>
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

<script>
(function() {
    var pw = document.getElementById('password');
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
            { w: '0%', c: '', t: 'Must be at least 8 characters' },
            { w: '20%', c: '#ef4444', t: 'Weak' },
            { w: '40%', c: '#f97316', t: 'Fair' },
            { w: '60%', c: '#eab308', t: 'Good' },
            { w: '80%', c: '#22c55e', t: 'Strong' },
            { w: '100%', c: '#16a34a', t: 'Very strong' }
        ];
        var l = levels[score];
        bar.style.width = l.w;
        bar.style.backgroundColor = l.c;
        if (text) text.textContent = v.length === 0 ? levels[0].t : l.t;
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
