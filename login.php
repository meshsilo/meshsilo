<?php
require_once 'includes/config.php';

$error = '';

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

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// If already logged in, redirect to home
if (isLoggedIn()) {
    header('Location: index.php');
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

                <form class="auth-form" action="login.php" method="post">
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
                        <a href="#" class="form-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Log In</button>
                </form>
            </div>
        </div>

<?php require_once 'includes/footer.php'; ?>
