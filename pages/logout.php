<?php
/**
 * Logout handler with OIDC single logout support
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include full config for settings and OIDC functions
require_once __DIR__ . '/includes/config.php';

// Get user info before destroying session
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['user']['username'] ?? 'unknown';
$idToken = $_SESSION['oidc_id_token'] ?? null;
$wasOIDCUser = !empty($_SESSION['user']['oidc_id']) || !empty($idToken);

// Log the logout
if ($userId) {
    logInfo('User logged out', [
        'user_id' => $userId,
        'username' => $username,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'method' => $wasOIDCUser ? 'oidc' : 'local'
    ]);

    // Log activity if enabled
    if (function_exists('logActivity')) {
        logActivity('logout', 'user', $userId, $username, ['method' => $wasOIDCUser ? 'oidc' : 'local']);
    }
}

// Destroy the local session
session_destroy();

// Check if we should do OIDC single logout
$oidcSingleLogout = getSetting('oidc_single_logout', '1') === '1';

if ($wasOIDCUser && $oidcSingleLogout && isOIDCEnabled()) {
    // Get OIDC logout URL
    $logoutUrl = getOIDCLogoutUrl($idToken);

    if ($logoutUrl) {
        // Redirect to OIDC provider's logout endpoint
        header('Location: ' . $logoutUrl);
        exit;
    }
}

// Default: redirect to login page
header('Location: login.php');
exit;
