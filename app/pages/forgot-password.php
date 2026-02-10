<?php
/**
 * Forgot Password Page
 * Allows users to request a password reset email
 */

require_once __DIR__ . '/../../includes/config.php';

// If already logged in, redirect to home
if (isLoggedIn()) {
    header('Location: ' . route('home'));
    exit;
}

$message = '';
$error = '';
$emailSent = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db = getDB();

        // Check if user exists with this email
        $stmt = $db->prepare('SELECT id, username, email FROM users WHERE email = :email');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $result = $stmt->execute();
        $user = $result->fetchArray(PDO::FETCH_ASSOC);

        // Always show success message to prevent email enumeration
        $emailSent = true;
        $message = 'If an account exists with this email, you will receive a password reset link shortly.';

        if ($user) {
            $stmt = $db->prepare('SELECT password FROM users WHERE id = :id');
            $stmt->bindValue(':id', $user['id'], PDO::PARAM_INT);
            $result = $stmt->execute();
            $userData = $result->fetchArray(PDO::FETCH_ASSOC);

            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Delete any existing tokens for this email
            $stmt = $db->prepare('DELETE FROM password_resets WHERE email = :email');
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->execute();

            // Insert new token
            $stmt = $db->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)');
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':token', $token, PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);
            $stmt->execute();

            // Send email
            try {
                if (class_exists('Notification')) {
                    Notification::passwordReset($email, $token, $user['username']);
                } elseif (class_exists('Mail')) {
                    $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                                '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/reset-password?token=' . urlencode($token);
                    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Silo';

                    Mail::create()
                        ->to($email, $user['username'])
                        ->subject("Reset Your Password - $siteName")
                        ->body("
                            <h2>Password Reset Request</h2>
                            <p>Hello {$user['username']},</p>
                            <p>We received a request to reset your password. Click the button below to create a new password:</p>
                            <p style='margin: 20px 0;'>
                                <a href='$resetUrl' style='background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;'>
                                    Reset Password
                                </a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style='word-break: break-all;'>$resetUrl</p>
                            <p>This link will expire in 1 hour.</p>
                            <p>If you didn't request this, you can safely ignore this email.</p>
                            <p>Thanks,<br>$siteName</p>
                        ")
                        ->send();
                }

                logAuthEvent('password_reset_requested', $user['username'], true, [
                    'user_id' => $user['id'],
                    'email' => $email
                ]);
            } catch (Exception $e) {
                // Log error but don't reveal to user
                logError('Failed to send password reset email', [
                    'email' => $email,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            // Log attempt for non-existent email (potential enumeration attempt)
            logSecurityWarning('Password reset requested for non-existent email', [
                'email' => $email,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }
    }
}

$pageTitle = 'Forgot Password';
$activePage = '';
require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <?php $logoPath = getSetting('logo_path', ''); if ($logoPath): ?>
                    <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($logoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="auth-logo-img">
                    <?php endif; ?>
                    <h1>Forgot Password</h1>
                    <p>Enter your email to receive a password reset link</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if (!$emailSent): ?>
                <form class="auth-form" action="/forgot-password" method="post">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input"
                               placeholder="Enter your email address" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
                </form>
                <?php else: ?>
                <div class="text-center">
                    <p>Check your email for the password reset link.</p>
                    <p class="text-muted">Didn't receive the email? Check your spam folder or try again.</p>
                    <a href="/forgot-password" class="btn btn-secondary" style="margin-top: 1rem;">Try Again</a>
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
.text-muted {
    color: var(--color-text-muted);
    font-size: 0.875rem;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
