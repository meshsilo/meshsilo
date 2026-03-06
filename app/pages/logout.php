<?php
/**
 * Logout handler with plugin-extensible redirect
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include full config
require_once 'includes/config.php';

// Get user info before destroying session
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['user']['username'] ?? 'unknown';

// Capture session data plugins may need for logout (e.g., OIDC tokens)
$preLogoutData = $_SESSION;

// Log the logout
if ($userId) {
    logAuthEvent('logout', $username, true, [
        'user_id' => $userId
    ]);

    if (function_exists('logActivity')) {
        logActivity('logout', 'user', $userId, $username);
    }
}

// Destroy the local session
session_destroy();

// Let plugins determine where to redirect (e.g., OIDC provider logout)
$redirectUrl = class_exists('PluginManager')
    ? PluginManager::applyFilter('logout_redirect', route('login'), $userId, $preLogoutData)
    : route('login');

header('Location: ' . $redirectUrl);
exit;
