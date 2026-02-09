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
    // Rate limit login attempts (5 attempts per 15 minutes per IP)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = __DIR__ . '/../../storage/cache/login_attempts';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $attemptFile = $cacheDir . '/' . md5($ip) . '.json';
    $attempts = [];
    if (file_exists($attemptFile)) {
        $attempts = json_decode(file_get_contents($attemptFile), true) ?: [];
        // Remove attempts older than 15 minutes
        $cutoff = time() - 900;
        $attempts = array_filter($attempts, fn($t) => $t > $cutoff);
    }
    if (count($attempts) >= 5) {
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

            logAuthEvent('login', $user['username'], true, [
                'user_id' => $user['id'],
                'method' => 'password'
            ]);

            // Clear login attempts on success
            if (isset($attemptFile) && file_exists($attemptFile)) {
                @unlink($attemptFile);
            }

            header('Location: ' . route('home'));
            exit;
        } else {
            $error = 'Invalid username or password.';
            logAuthEvent('login', $username, false, [
                'reason' => $user ? 'invalid_password' : 'user_not_found',
                'method' => 'password'
            ]);
            // Record failed attempt for rate limiting
            $attempts[] = time();
            file_put_contents($attemptFile, json_encode(array_values($attempts)));
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
                    <span class="logo-icon">&#9653;</span>
                    <h1>Welcome back</h1>
                    <p>Log in to your <?= SITE_NAME ?> account</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form class="auth-form" action="/login" method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <input type="text" id="username" name="username" class="form-input" placeholder="Enter your username or email" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-input" placeholder="Enter your password" required>
                    </div>

                    <div class="form-group form-row">
                        <label class="checkbox-inline">
                            <input type="checkbox" name="remember">
                            <span>Remember me</span>
                        </label>
                        <a href="/forgot-password" class="form-link">Forgot password?</a>
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
