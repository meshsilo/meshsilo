<?php
require_once 'includes/config.php';

$error = '';

// Check for session timeout message
if (isset($_SESSION['session_timeout_message'])) {
    $error = $_SESSION['session_timeout_message'];
    unset($_SESSION['session_timeout_message']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!Csrf::check()) {
        $error = 'Invalid request. Please try again.';
    }

    // Rate limit login attempts using RateLimiter
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateResult = RateLimiter::check($ip, 'anonymous', 'login');
    if (!$rateResult['allowed']) {
        $error = 'Too many login attempts. Please try again in a few minutes.';
        logAuthEvent('login', $_POST['username'] ?? '', false, ['reason' => 'rate_limited', 'ip' => $ip]);
    }

    if (!$error) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $user = getUserByLogin($username);

        if ($user && verifyPassword($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'is_admin' => $user['is_admin']
            ];

            if (class_exists('PluginManager')) {
                PluginManager::doAction('user:login', ['user_id' => $user['id'], 'username' => $user['username']]);
            }

            logAuthEvent('login', $user['username'], true, [
                'user_id' => $user['id'],
                'method' => 'password'
            ]);

            // Clear login attempts on success
            RateLimiter::reset($ip, 'login');

            // Redirect to the page they were trying to access, or home
            $redirect = $_SESSION['redirect_after_login'] ?? null;
            unset($_SESSION['redirect_after_login']);
            // Prevent open redirect — only allow relative paths starting with /
            if ($redirect && (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//'))) {
                $redirect = null;
            }
            header('Location: ' . ($redirect ?: route('home')));
            exit;
        } else {
            $error = 'Invalid username or password.';
            logAuthEvent('login', $username, false, [
                'reason' => $user ? 'invalid_password' : 'user_not_found',
                'method' => 'password'
            ]);
            // Failed attempt already recorded by RateLimiter::check()
        }
    }
    } // end if (!$error) rate limit check
}

// If already logged in, redirect to home
if (isLoggedIn()) {
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Log In';
$activePage = '';
require_once 'includes/header.php';
?>

        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <?php $logoPath = getSetting('logo_path', ''); if ($logoPath): ?>
                    <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="auth-logo-img">
                    <?php endif; ?>
                    <h1>Welcome back</h1>
                    <p>Log in to your <?= htmlspecialchars(SITE_NAME) ?> account</p>
                </div>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form class="auth-form" action="<?= route('login') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <input type="text" id="username" name="username" class="form-input" placeholder="Enter your username or email" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-input" placeholder="Enter your password" required autocomplete="current-password">
                            <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>

                    <div class="form-group form-row">
                        <label class="checkbox-inline">
                            <input type="checkbox" name="remember">
                            <span>Remember me</span>
                        </label>
                        <a href="<?= route('forgot-password') ?>" class="form-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Log In</button>
                </form>

                <?php
                $loginButtons = class_exists('PluginManager') ? PluginManager::applyFilter('login_buttons', []) : [];
                if (!empty($loginButtons)): ?>
                <div class="auth-divider">
                    <span>or</span>
                </div>
                <?php foreach ($loginButtons as $button): ?>
                <a href="<?= htmlspecialchars($button['url']) ?>" class="btn btn-secondary btn-full <?= htmlspecialchars($button['class'] ?? '') ?>">
                    <?= htmlspecialchars($button['text']) ?>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
