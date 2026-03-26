<?php
require_once __DIR__ . '/../../includes/config.php';

// Must have a pending 2FA session
if (empty($_SESSION['2fa_pending_user_id'])) {
    header('Location: ' . route('login'));
    exit;
}

$userId = (int)$_SESSION['2fa_pending_user_id'];
$error = '';

// Handle verification form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check()) {
        $error = 'Invalid request. Please try again.';
    }

    // Rate limit 2FA attempts
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateResult = RateLimiter::check($ip, 'anonymous', '2fa_verify');
    if (!$rateResult['allowed']) {
        $error = 'Too many verification attempts. Please try again in a few minutes.';
    }

    if (!$error) {
        $code = trim($_POST['code'] ?? '');

        if (empty($code)) {
            $error = 'Please enter your verification code.';
        } else {
            // Try TOTP code first, then backup code
            if (TwoFactor::verifyForUser($userId, $code)) {
                // 2FA verified — grant full session
                $pendingUser = $_SESSION['2fa_pending_user'];
                $_SESSION['user_id'] = $pendingUser['id'];
                $_SESSION['user'] = $pendingUser;
                $_SESSION['2fa_verified'] = $pendingUser['id'];

                // Clean up pending state
                $redirect = $_SESSION['2fa_redirect'] ?? null;
                unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_user'], $_SESSION['2fa_redirect']);

                if (class_exists('PluginManager')) {
                    PluginManager::doAction('user:login', ['user_id' => $pendingUser['id'], 'username' => $pendingUser['username']]);
                }

                logAuthEvent('login', $pendingUser['username'], true, [
                    'user_id' => $pendingUser['id'],
                    'method' => 'password+2fa'
                ]);

                // Redirect to original target or home
                if ($redirect && (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//'))) {
                    $redirect = null;
                }
                header('Location: ' . ($redirect ?: route('home')));
                exit;
            } else {
                $error = 'Invalid verification code. Please try again.';
                logAuthEvent('2fa_verify', $_SESSION['2fa_pending_user']['username'] ?? '', false, [
                    'user_id' => $userId,
                    'reason' => 'invalid_code'
                ]);
            }
        }
    }
}

$pageTitle = 'Two-Factor Verification';
define('PUBLIC_PAGE', true);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= getSetting('theme', 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'MeshSilo') ?></title>
    <link rel="stylesheet" href="<?= basePath('css/base.css') ?>">
    <link rel="stylesheet" href="<?= basePath('css/pages.css') ?>">
</head>
<body>
    <div class="verify-container">
        <div class="verify-card">
            <h1>Two-Factor Verification</h1>
            <p>Enter the 6-digit code from your authenticator app, or use a backup code.</p>

            <?php if ($error): ?>
                <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="text" name="code" class="code-input" maxlength="8" pattern="[0-9a-zA-Z]+" inputmode="numeric" autofocus placeholder="000000" required>
                <button type="submit" class="verify-btn">Verify</button>
            </form>

            <p class="backup-hint">You can also enter a backup code if you've lost access to your authenticator app.</p>
            <a href="<?= route('login') ?>" class="cancel-link">Cancel and return to login</a>
        </div>
    </div>
</body>
</html>
