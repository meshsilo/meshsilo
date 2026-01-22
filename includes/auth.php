<?php
session_start();

// Check URL enforcement (must happen before any output)
// First check config file, then database settings
$siteUrl = defined('SITE_URL') ? SITE_URL : '';
$forceSiteUrl = defined('FORCE_SITE_URL') ? FORCE_SITE_URL : false;

// Try to get from database if available (for runtime changes)
if (function_exists('getSetting')) {
    $dbSiteUrl = getSetting('site_url', '');
    $dbForceSiteUrl = getSetting('force_site_url', '0');
    if (!empty($dbSiteUrl)) {
        $siteUrl = $dbSiteUrl;
    }
    if ($dbForceSiteUrl === '1') {
        $forceSiteUrl = true;
    }
}

if ($forceSiteUrl && !empty($siteUrl)) {
    $configuredUrl = parse_url($siteUrl);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';

    // Check if the host matches
    if (!empty($configuredUrl['host']) && $configuredUrl['host'] !== $currentHost) {
        // Allow install.php to bypass
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== 'install.php') {
            http_response_code(403);
            die('Access denied. This site can only be accessed via the configured URL.');
        }
    }
}

// Pages that don't require authentication
$publicPages = ['login.php', 'oidc-callback.php', 'install.php'];

// Get current page filename
$currentPage = basename($_SERVER['PHP_SELF']);

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get current user
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Redirect to login if not authenticated (unless on public page or CLI)
if (php_sapi_name() !== 'cli' && !isLoggedIn() && !in_array($currentPage, $publicPages)) {
    logWarning('Unauthorized access attempt', [
        'page' => $currentPage,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    // Use route helper if available, otherwise fall back to /login
    $loginUrl = function_exists('route') ? route('login') : '/login';
    header('Location: ' . $loginUrl);
    exit;
}
