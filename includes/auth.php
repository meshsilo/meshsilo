<?php

// Always use database sessions for persistence across restarts
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/DatabaseSessionHandler.php';
    $sessionLifetime = (int)(getenv('SESSION_LIFETIME') ?: 7200);
    $handler = new DatabaseSessionHandler($sessionLifetime);
    session_set_save_handler($handler, true);
}

if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie security (also check X-Forwarded-Proto behind trusted reverse proxy)
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    // Only trust X-Forwarded-Proto from configured trusted proxies
    if (!$secure && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($trustedProxies) && (in_array($remoteAddr, $trustedProxies, true) || in_array('*', $trustedProxies, true))) {
            $secure = true;
        }
    }
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
// SITE_URL and FORCE_SITE_URL constants are already loaded from the database
// in config.php before auth.php is included, so use them directly.
$siteUrl = defined('SITE_URL') ? SITE_URL : '';
$forceSiteUrl = defined('FORCE_SITE_URL') ? FORCE_SITE_URL : false;

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
function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

// Get current user
//
// Returns the user array from the session. If only $_SESSION['user_id'] is
// set (e.g., after a partial login flow, session migration, or any other
// path that populated user_id but not the full user record), rehydrate the
// session by fetching the user row from the DB. This prevents a class of
// crashes where isLoggedIn() returns true but every caller of
// getCurrentUser() then dereferences null.
function getCurrentUser()
{
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }

    if (!isset($_SESSION['user_id']) || !function_exists('getDB')) {
        return null;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, email, is_admin FROM users WHERE id = :id');
        $stmt->bindValue(':id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $_SESSION['user'] = $row;
        return $row;
    } catch (\Throwable $e) {
        if (function_exists('logException')) {
            logException($e, ['action' => 'session_rehydrate', 'user_id' => $_SESSION['user_id'] ?? null]);
        }
        return null;
    }
}

/**
 * Enforce authentication for non-public pages/routes.
 *
 * This is wrapped in a function so it can be called from config.php AFTER
 * plugins have loaded, allowing plugins to register additional public routes
 * via the 'public_routes' filter.
 */
function enforceAuthentication(): void
{
    // Skip for API requests (API uses its own key-based auth in api/index.php)
    if (defined('API_REQUEST') && API_REQUEST === true) {
        return;
    }

    // Routes that don't require authentication
    $publicRoutes = ['/login', '/logout', '/install', '/forgot-password', '/reset-password', '/2fa-verify'];
    if (class_exists('PluginManager')) {
        $publicRoutes = PluginManager::applyFilter('public_routes', $publicRoutes);
    }

    // Get current route
    $currentRoute = '/' . trim($_GET['route'] ?? '', '/');
    $isPublicRoute = in_array($currentRoute, $publicRoutes);

    // Skip for API routes - they handle their own key-based auth in api/index.php
    // Note: API_REQUEST constant isn't defined yet at this point because the API
    // route handler (app/api/index.php) loads after enforceAuthentication() runs.
    // So we also check the route path directly.
    $isApiRoute = str_starts_with($currentRoute, '/api/') || $currentRoute === '/api';

    // Redirect to login if not authenticated (unless on public route or API)
    if (!isLoggedIn() && !$isPublicRoute && !$isApiRoute) {
        logWarning('Unauthorized access attempt', [
            'route' => $currentRoute,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        $loginUrl = function_exists('route') ? route('login') : '/login';
        header('Location: ' . $loginUrl);
        exit;
    }
}
