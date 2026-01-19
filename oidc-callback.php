<?php
/**
 * OIDC callback handler
 * Handles the authorization code flow callback from the OIDC provider
 */

// Start session before including config
session_start();

require_once 'includes/logger.php';
require_once 'includes/db.php';
require_once 'includes/oidc.php';

$error = null;

// Check for error from provider
if (isset($_GET['error'])) {
    $error = 'Authentication failed: ' . ($_GET['error_description'] ?? $_GET['error']);
    logWarning('OIDC error from provider', [
        'error' => $_GET['error'],
        'description' => $_GET['error_description'] ?? ''
    ]);
}

// Verify state parameter
if (!$error && (!isset($_GET['state']) || !isset($_SESSION['oidc_state']) || $_GET['state'] !== $_SESSION['oidc_state'])) {
    $error = 'Invalid state parameter. Please try again.';
    logWarning('OIDC state mismatch', [
        'received' => $_GET['state'] ?? 'none',
        'expected' => $_SESSION['oidc_state'] ?? 'none'
    ]);
}

// Check for authorization code
if (!$error && !isset($_GET['code'])) {
    $error = 'No authorization code received.';
}

// Exchange code for tokens
if (!$error) {
    $tokens = exchangeCodeForTokens($_GET['code']);

    if (!$tokens || !isset($tokens['access_token'])) {
        $error = 'Failed to exchange authorization code for tokens.';
    }
}

// Get user info
if (!$error) {
    $userInfo = null;

    // Try to get info from ID token first
    if (isset($tokens['id_token'])) {
        $idTokenData = decodeIdToken($tokens['id_token']);
        if ($idTokenData) {
            $userInfo = $idTokenData;
        }
    }

    // Supplement with userinfo endpoint
    $userInfoEndpoint = getOIDCUserInfo($tokens['access_token']);
    if ($userInfoEndpoint) {
        $userInfo = array_merge($userInfo ?? [], $userInfoEndpoint);
    }

    if (!$userInfo || !isset($userInfo['sub'])) {
        $error = 'Failed to get user information from provider.';
    }
}

// Find or create user
if (!$error) {
    $user = findOrCreateOIDCUser($userInfo, $tokens['id_token'] ?? null);

    if (!$user) {
        $error = 'Failed to create or find user account.';
    }
}

// Clean up OIDC session data
unset($_SESSION['oidc_state']);
unset($_SESSION['oidc_nonce']);

// If we have an error, redirect to login with error
if ($error) {
    $_SESSION['oidc_error'] = $error;
    header('Location: login.php');
    exit;
}

// Log in the user
$_SESSION['user_id'] = $user['id'];
$_SESSION['user'] = [
    'id' => $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'is_admin' => $user['is_admin']
];

logInfo('OIDC login successful', [
    'user_id' => $user['id'],
    'username' => $user['username'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);

// Redirect to home
header('Location: index.php');
exit;
