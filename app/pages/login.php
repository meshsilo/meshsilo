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

    // Also rate limit per account+source. Keying on username alone (with denied
    // attempts counted) would let an attacker lock a victim out of their own
    // account indefinitely by flooding their username; scoping to username+IP
    // throttles single-source brute force without enabling that lockout DoS.
    $loginUsername = strtolower(trim($_POST['username'] ?? ''));
    if (!$error && $loginUsername !== '') {
        $userRateResult = RateLimiter::check($loginUsername . '|' . $ip, 'anonymous', 'login_user');
        if (!$userRateResult['allowed']) {
            $error = 'Too many login attempts. Please try again in a few minutes.';
            logAuthEvent('login', $_POST['username'] ?? '', false, ['reason' => 'rate_limited_account', 'ip' => $ip]);
        }
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

            // Check if 2FA is enabled for this user
            if (class_exists('TwoFactor') && TwoFactor::isEnabled($user['id'])) {
                // Store pending 2FA state - do NOT grant full session yet
                $_SESSION['2fa_pending_user_id'] = $user['id'];
                $_SESSION['2fa_pending_user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'is_admin' => $user['is_admin']
                ];
                // Preserve redirect target for after 2FA
                if (!empty($_SESSION['redirect_after_login'])) {
                    $_SESSION['2fa_redirect'] = $_SESSION['redirect_after_login'];
                    unset($_SESSION['redirect_after_login']);
                }
                RateLimiter::reset($ip, 'login');
                if ($loginUsername !== '') {
                    RateLimiter::reset($loginUsername . '|' . $ip, 'login_user');
                }
                header('Location: ' . route('2fa.verify'));
                exit;
            }

            // Login successful (no 2FA)
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
            if ($loginUsername !== '') {
                RateLimiter::reset($loginUsername . '|' . $ip, 'login_user');
            }

            // Redirect to the page they were trying to access, or home
            $redirect = $_SESSION['redirect_after_login'] ?? null;
            unset($_SESSION['redirect_after_login']);
            // Prevent open redirect - only allow relative paths starting with /
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
$minimalHeader = true;
$bodyClass = 'auth-page';
require_once 'includes/header.php';
?>

<div class="auth-split">

    <!-- Brand panel -->
    <div class="auth-brand-panel" aria-hidden="true">
        <div class="auth-brand-grid"></div>

        <svg class="auth-brand-art" viewBox="0 0 360 360" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- Top face -->
            <polygon points="180,60 300,125 180,190 60,125" fill="rgba(37,99,235,0.07)" stroke="rgba(37,99,235,0.55)" stroke-width="1.5" stroke-linejoin="round"/>
            <!-- Right face -->
            <polygon points="300,125 300,255 180,320 180,190" fill="rgba(37,99,235,0.04)" stroke="rgba(37,99,235,0.4)" stroke-width="1.5" stroke-linejoin="round"/>
            <!-- Left face -->
            <polygon points="60,125 180,190 180,320 60,255" fill="rgba(37,99,235,0.04)" stroke="rgba(37,99,235,0.4)" stroke-width="1.5" stroke-linejoin="round"/>

            <!-- Top face grid (midlines) -->
            <line x1="120" y1="92.5" x2="240" y2="157.5" stroke="rgba(37,99,235,0.3)" stroke-width="1"/>
            <line x1="240" y1="92.5" x2="120" y2="157.5" stroke="rgba(37,99,235,0.3)" stroke-width="1"/>
            <line x1="180" y1="60" x2="180" y2="190" stroke="rgba(37,99,235,0.2)" stroke-width="1" stroke-dasharray="4 4"/>

            <!-- Right face grid -->
            <line x1="300" y1="190" x2="180" y2="255" stroke="rgba(37,99,235,0.25)" stroke-width="1"/>
            <line x1="240" y1="157.5" x2="240" y2="287.5" stroke="rgba(37,99,235,0.25)" stroke-width="1"/>

            <!-- Left face grid -->
            <line x1="60" y1="190" x2="180" y2="255" stroke="rgba(37,99,235,0.25)" stroke-width="1"/>
            <line x1="120" y1="157.5" x2="120" y2="287.5" stroke="rgba(37,99,235,0.25)" stroke-width="1"/>

            <!-- Vertex dots -->
            <circle cx="180" cy="60" r="4" fill="rgba(37,99,235,1)"/>
            <circle cx="300" cy="125" r="3" fill="rgba(37,99,235,0.8)"/>
            <circle cx="60" cy="125" r="3" fill="rgba(37,99,235,0.8)"/>
            <circle cx="180" cy="190" r="4.5" fill="rgba(37,99,235,0.9)"/>
            <circle cx="300" cy="255" r="3" fill="rgba(37,99,235,0.55)"/>
            <circle cx="180" cy="320" r="3" fill="rgba(37,99,235,0.55)"/>
            <circle cx="60" cy="255" r="3" fill="rgba(37,99,235,0.55)"/>

            <!-- Grid midpoint dots -->
            <circle cx="180" cy="125" r="2" fill="rgba(37,99,235,0.55)"/>
            <circle cx="240" cy="92.5" r="2" fill="rgba(37,99,235,0.45)"/>
            <circle cx="120" cy="92.5" r="2" fill="rgba(37,99,235,0.45)"/>
            <circle cx="240" cy="157.5" r="2" fill="rgba(37,99,235,0.45)"/>
            <circle cx="120" cy="157.5" r="2" fill="rgba(37,99,235,0.45)"/>
            <circle cx="300" cy="190" r="2" fill="rgba(37,99,235,0.4)"/>
            <circle cx="240" cy="287.5" r="2" fill="rgba(37,99,235,0.35)"/>
            <circle cx="60" cy="190" r="2" fill="rgba(37,99,235,0.4)"/>
            <circle cx="120" cy="287.5" r="2" fill="rgba(37,99,235,0.35)"/>
            <circle cx="180" cy="255" r="2.5" fill="rgba(37,99,235,0.5)"/>
        </svg>

        <div class="auth-brand-content">
            <?php $logoPath = getSetting('logo_path', ''); if ($logoPath): ?>
            <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="auth-brand-logo-img">
            <?php else: ?>
            <div class="auth-brand-wordmark"><?= htmlspecialchars(SITE_NAME) ?></div>
            <?php endif; ?>
            <p class="auth-brand-tagline"><?= htmlspecialchars(getSetting('site_description', 'Your 3D Model Library')) ?></p>
            <ul class="auth-brand-features">
                <li>Organize STL, 3MF, OBJ, and more</li>
                <li>Browse, filter, and find any print file</li>
                <li>One library for your whole collection</li>
            </ul>
        </div>
    </div>

    <!-- Form panel -->
    <div class="auth-form-panel">
        <div class="auth-form-wrapper">

            <div class="auth-form-header">
                <div class="auth-form-logo">
                    <?php if ($logoPath): ?>
                    <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="auth-form-logo-img">
                    <?php else: ?>
                    <span class="auth-form-wordmark"><?= htmlspecialchars(SITE_NAME) ?></span>
                    <?php endif; ?>
                </div>
                <h1>Welcome back</h1>
                <p>Log in to your account to continue</p>
            </div>

            <?php if ($error): ?>
            <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form class="auth-form" action="<?= route('login') ?>" method="post" novalidate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="username">Username or email</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-input"
                        placeholder="you@example.com"
                        required
                        autocomplete="username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle" aria-label="Show password" title="Show password">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="auth-form-row">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="<?= route('forgot-password') ?>" class="form-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Log in</button>
            </form>

            <?php
            $loginButtons = class_exists('PluginManager') ? PluginManager::applyFilter('login_buttons', []) : [];
            if (!empty($loginButtons)): ?>
            <div class="auth-divider"><span>or continue with</span></div>
            <?php foreach ($loginButtons as $button): ?>
            <a href="<?= htmlspecialchars($button['url']) ?>" class="btn btn-secondary btn-full <?= htmlspecialchars($button['class'] ?? '') ?>">
                <?= htmlspecialchars($button['text']) ?>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>
