<?php
require_once 'includes/config.php';

$error = '';

// Check for OIDC error from callback
if (isset($_SESSION['oidc_error'])) {
    $error = $_SESSION['oidc_error'];
    unset($_SESSION['oidc_error']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $user = getUserByLogin($username);

        if ($user && verifyPassword($password, $user['password'])) {
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

            header('Location: /');
            exit;
        } else {
            $error = 'Invalid username or password.';
            logAuthEvent('login', $username, false, [
                'reason' => $user ? 'invalid_password' : 'user_not_found',
                'method' => 'password'
            ]);
        }
    }
}

// If already logged in, redirect to home
if (isLoggedIn()) {
    header('Location: /');
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

                <?php if (isOIDCEnabled()): ?>
                <div class="auth-divider">
                    <span>or</span>
                </div>
                <a href="<?= htmlspecialchars(getOIDCAuthUrl()) ?>" class="btn btn-secondary btn-full btn-oidc">
                    <?= htmlspecialchars(getSetting('oidc_button_text', 'Sign in with SSO')) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
