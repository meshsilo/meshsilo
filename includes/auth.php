<?php
// Configure database sessions if enabled
$useDbSessions = getenv('DB_SESSIONS') === 'true' ||
    (defined('DB_SESSIONS') && DB_SESSIONS === true) ||
    (function_exists('getSetting') && getSetting('db_sessions', '0') === '1');

if ($useDbSessions && session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/DatabaseSessionHandler.php';
    $sessionLifetime = (int)(getenv('SESSION_LIFETIME') ?: 7200);
    $handler = new DatabaseSessionHandler($sessionLifetime);
    session_set_save_handler($handler, true);
}

if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie security (also check X-Forwarded-Proto behind reverse proxy)
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Session idle timeout check (default 30 minutes, configurable via settings)
if (php_sapi_name() !== 'cli' && isset($_SESSION['user_id'])) {
    $idleTimeout = 1800; // 30 minutes default
    if (function_exists('getSetting')) {
        $idleTimeout = (int)getSetting('session_idle_timeout', 1800);
    }

    if ($idleTimeout > 0) {
        $lastActivity = $_SESSION['last_activity'] ?? time();
        $idleTime = time() - $lastActivity;

        if ($idleTime > $idleTimeout) {
            // Session has been idle too long - destroy it
            if (function_exists('logAuthEvent') && isset($_SESSION['user']['username'])) {
                logAuthEvent('session_timeout', $_SESSION['user']['username'], true, [
                    'user_id' => $_SESSION['user_id'],
                    'idle_seconds' => $idleTime
                ]);
            }

            session_unset();
            session_destroy();

            // Start a new session for the error message
            session_start();
            $_SESSION['session_timeout_message'] = 'Your session has expired due to inactivity. Please log in again.';

            $loginUrl = function_exists('route') ? route('login') : '/login';
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
}

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

// Skip URL enforcement for CLI scripts
if ($forceSiteUrl && !empty($siteUrl) && php_sapi_name() !== 'cli') {
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

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get current user
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Enforce authentication for non-public pages/routes.
 *
 * This is wrapped in a function so it can be called from config.php AFTER
 * plugins have loaded, allowing plugins to register additional public routes
 * via the 'public_routes' filter.
 */
function enforceAuthentication(): void {
    // Pages that don't require authentication (old direct-access pattern)
    $publicPages = ['login.php', 'install.php', 'forgot-password.php', 'reset-password.php'];

    // Routes that don't require authentication (router pattern)
    $publicRoutes = ['/login', '/logout', '/install', '/forgot-password', '/reset-password'];
    if (class_exists('PluginManager')) {
        $publicRoutes = PluginManager::applyFilter('public_routes', $publicRoutes);
    }

    // Get current page filename (for direct access)
    $currentPage = basename($_SERVER['PHP_SELF']);

    // Get current route (for router access)
    $currentRoute = '/' . trim($_GET['route'] ?? '', '/');

    // Determine if current request is to a public page/route
    $isPublicPage = in_array($currentPage, $publicPages);
    $isPublicRoute = in_array($currentRoute, $publicRoutes);

    // Redirect to login if not authenticated (unless on public page/route)
    if (!isLoggedIn() && !$isPublicPage && !$isPublicRoute) {
        logWarning('Unauthorized access attempt', [
            'page' => $currentPage,
            'route' => $currentRoute,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        // Use route helper if available, otherwise fall back to /login
        $loginUrl = function_exists('route') ? route('login') : '/login';
        header('Location: ' . $loginUrl);
        exit;
    }
}
