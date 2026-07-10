<?php

/**
 * Domain-specific diagnostics for the Debug system.
 *
 * Extracted from Debug.php as a cohesive collaborator grouping the
 * session/CSRF, authentication, routing, upload, configuration and
 * request/response event logging. Composed into the Debug facade via
 * `use`, so every existing static call (Debug::auth, Debug::route,
 * Debug::upload, Debug::diagnoseCsrf, Debug::diagnoseUpload,
 * Debug::configValue, Debug::response, ...) keeps working unchanged.
 * Shares the Debug class's private static state and helpers exactly as the
 * DebugProfiler / DebugPanels traits do.
 */
trait DebugDiagnostics
{
    // =========================================================================
    // SESSION & CSRF DEBUGGING
    // =========================================================================

    /**
     * Log session information
     */
    private static function logSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $savePath = session_save_path() ?: sys_get_temp_dir();

        $session = [
            'id' => session_id(),
            'name' => session_name(),
            'status' => self::getSessionStatusName(),
            'save_path' => $savePath,
            'save_path_writable' => is_writable($savePath),
            'save_path_exists' => is_dir($savePath),
            'cookie_params' => session_get_cookie_params(),
            'session_keys' => array_keys($_SESSION ?? []),
            'has_user' => isset($_SESSION['user_id']),
            'user_id' => $_SESSION['user_id'] ?? null,
            'has_csrf' => isset($_SESSION['csrf_token']),
        ];

        if (isset($_SESSION['csrf_token'])) {
            $session['csrf_preview'] = self::tokenPreview($_SESSION['csrf_token']);
        }

        self::log('Session initialized', $session, 'session');
    }

    /**
     * Diagnose CSRF issues
     */
    public static function diagnoseCsrf(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::log('=== CSRF DIAGNOSIS START ===', [], 'session');

        // Session status
        $savePath = session_save_path() ?: sys_get_temp_dir();
        self::log('Session Status', [
            'status' => self::getSessionStatusName(),
            'id' => session_id(),
            'id_length' => strlen(session_id()),
            'save_path' => $savePath,
            'save_path_writable' => is_writable($savePath),
            'save_handler' => ini_get('session.save_handler'),
        ], 'session');

        // Get tokens from various sources
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        $postToken = $_POST['csrf_token'] ?? null;
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $getToken = $_GET['csrf_token'] ?? null;

        self::log('Token Sources', [
            'session' => $sessionToken ? self::tokenPreview($sessionToken) . ' (len: ' . strlen($sessionToken) . ')' : 'NOT SET',
            'post' => $postToken ? self::tokenPreview($postToken) . ' (len: ' . strlen($postToken) . ')' : 'NOT SET',
            'header' => $headerToken ? self::tokenPreview($headerToken) . ' (len: ' . strlen($headerToken) . ')' : 'NOT SET',
            'get' => $getToken ? self::tokenPreview($getToken) . ' (len: ' . strlen($getToken) . ')' : 'NOT SET',
        ], 'session');

        // Check which submitted token to compare
        $submittedToken = $postToken ?? $headerToken ?? $getToken;

        if ($sessionToken && $submittedToken) {
            $exactMatch = $sessionToken === $submittedToken;
            $hashMatch = hash_equals($sessionToken, $submittedToken);

            self::log('Token Comparison', [
                'exact_match' => $exactMatch,
                'hash_equals_match' => $hashMatch,
                'session_length' => strlen($sessionToken),
                'submitted_length' => strlen($submittedToken),
                'difference' => $exactMatch ? 'none' : 'tokens differ',
            ], $hashMatch ? 'info' : 'error');

            if (!$hashMatch) {
                // Try to identify the issue
                if (strlen($sessionToken) !== strlen($submittedToken)) {
                    self::error('Token length mismatch - possible truncation or encoding issue');
                } else {
                    self::error('Tokens have same length but different content - session may have changed');
                }
            }
        } elseif (!$sessionToken) {
            self::error('No CSRF token in session - session may not be persisting');
        } elseif (!$submittedToken) {
            self::error('No CSRF token submitted - form may be missing csrf_field()');
        }

        // Cookie analysis
        $sessionCookieName = session_name();
        self::log('Cookie Analysis', [
            'session_cookie_name' => $sessionCookieName,
            'session_cookie_present' => isset($_COOKIE[$sessionCookieName]),
            'session_cookie_value_preview' => isset($_COOKIE[$sessionCookieName])
                ? self::tokenPreview($_COOKIE[$sessionCookieName])
                : 'NOT SET',
            'all_cookies' => array_keys($_COOKIE),
            'cookie_count' => count($_COOKIE),
        ], 'session');

        // Check cookie params vs current request
        $cookieParams = session_get_cookie_params();
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        self::log('Cookie/Request Compatibility', [
            'cookie_domain' => $cookieParams['domain'] ?: '(not set - uses current)',
            'current_host' => $currentHost,
            'cookie_secure' => $cookieParams['secure'] ? 'Yes (HTTPS only)' : 'No',
            'request_is_https' => $isHttps ? 'Yes' : 'No',
            'secure_mismatch' => $cookieParams['secure'] && !$isHttps,
            'cookie_path' => $cookieParams['path'],
            'cookie_samesite' => $cookieParams['samesite'] ?: 'not set',
        ], 'session');

        if ($cookieParams['secure'] && !$isHttps) {
            self::error('Cookie requires HTTPS but request is HTTP - cookie will not be sent');
        }

        self::log('=== CSRF DIAGNOSIS END ===', [], 'session');
    }

    /**
     * Log authentication events
     */
    public static function auth(string $event, array $context = []): void
    {
        if (!self::isEnabled() || !self::$config['log_auth']) {
            return;
        }

        self::$authInfo[] = [
            'event' => $event,
            'context' => $context,
            'time' => microtime(true) - self::$startTime,
        ];

        self::log("Auth: $event", $context, 'auth');
    }

    // =========================================================================
    // ROUTING DEBUGGING
    // =========================================================================

    /**
     * Log route matching
     */
    public static function route(string $event, array $context = []): void
    {
        if (!self::isEnabled() || !self::$config['log_routes']) {
            return;
        }

        self::$routeInfo[] = [
            'event' => $event,
            'context' => $context,
            'time' => microtime(true) - self::$startTime,
        ];

        self::log("Route: $event", $context, 'route');
    }

    /**
     * Log route match result
     */
    public static function routeMatched(string $pattern, string $name, string $file): void
    {
        self::route('matched', [
            'pattern' => $pattern,
            'name' => $name,
            'file' => self::shortenPath($file),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        ]);
    }

    /**
     * Log route not found
     */
    public static function routeNotFound(string $uri): void
    {
        self::route('not_found', [
            'uri' => $uri,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ]);
    }

    // =========================================================================
    // FILE UPLOAD DEBUGGING
    // =========================================================================

    /**
     * Log file upload attempt
     */
    public static function upload(string $event, array $context = []): void
    {
        if (!self::isEnabled() || !self::$config['log_uploads']) {
            return;
        }

        self::$uploadInfo[] = [
            'event' => $event,
            'context' => $context,
            'time' => microtime(true) - self::$startTime,
        ];

        self::log("Upload: $event", $context, 'upload');
    }

    /**
     * Diagnose file upload issues
     */
    public static function diagnoseUpload(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::log('=== UPLOAD DIAGNOSIS START ===', [], 'upload');

        // PHP upload settings
        self::log('PHP Upload Settings', [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'file_uploads' => ini_get('file_uploads'),
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
            'tmp_dir_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
        ], 'upload');

        // Request info
        self::log('Request Info', [
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
        ], 'upload');

        // $_FILES analysis
        if (empty($_FILES)) {
            self::warn('$_FILES is empty - no files uploaded or form encoding issue');
            self::log('Check that form has enctype="multipart/form-data"', [], 'upload');
        } else {
            foreach ($_FILES as $name => $file) {
                if (is_array($file['name'])) {
                    // Multiple files
                    for ($i = 0; $i < count($file['name']); $i++) {
                        self::logFileInfo($name . "[$i]", [
                            'name' => $file['name'][$i],
                            'type' => $file['type'][$i],
                            'size' => $file['size'][$i],
                            'tmp_name' => $file['tmp_name'][$i],
                            'error' => $file['error'][$i],
                        ]);
                    }
                } else {
                    self::logFileInfo($name, $file);
                }
            }
        }

        self::log('=== UPLOAD DIAGNOSIS END ===', [], 'upload');
    }

    private static function logFileInfo(string $fieldName, array $file): void
    {
        $errorMessages = [
            UPLOAD_ERR_OK => 'OK',
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            UPLOAD_ERR_EXTENSION => 'PHP extension stopped the upload',
        ];

        $error = $file['error'];
        $errorMsg = $errorMessages[$error] ?? "Unknown error ($error)";

        self::log("File field: $fieldName", [
            'name' => $file['name'],
            'type' => $file['type'],
            'size' => formatBytes($file['size']),
            'size_bytes' => $file['size'],
            'tmp_name' => $file['tmp_name'],
            'tmp_exists' => !empty($file['tmp_name']) && file_exists($file['tmp_name']),
            'error_code' => $error,
            'error_message' => $errorMsg,
        ], $error === UPLOAD_ERR_OK ? 'upload' : 'error');
    }

    // =========================================================================
    // CONFIGURATION DEBUGGING
    // =========================================================================

    /**
     * Capture configuration snapshot
     */
    private static function captureConfig(): void
    {
        self::$configSnapshot = [
            'defined_constants' => self::getAppConstants(),
            'php_settings' => [
                'display_errors' => ini_get('display_errors'),
                'error_reporting' => error_reporting(),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'session.save_handler' => ini_get('session.save_handler'),
                'session.save_path' => ini_get('session.save_path'),
                'session.cookie_secure' => ini_get('session.cookie_secure'),
                'session.cookie_httponly' => ini_get('session.cookie_httponly'),
                'session.cookie_samesite' => ini_get('session.cookie_samesite'),
            ],
            'server' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            ],
        ];

        self::log('Configuration captured', [
            'constants_count' => count(self::$configSnapshot['defined_constants']),
        ], 'config');
    }

    /**
     * Get application-specific constants
     */
    private static function getAppConstants(): array
    {
        $constants = get_defined_constants(true)['user'] ?? [];

        // Filter to app-related constants
        $appConstants = [];
        $prefixes = ['SITE_', 'DB_', 'UPLOAD_', 'MAX_', 'PERM_', 'MESHSILO_'];

        foreach ($constants as $name => $value) {
            foreach ($prefixes as $prefix) {
                if (strpos($name, $prefix) === 0) {
                    // Mask sensitive values
                    if (strpos($name, 'PASSWORD') !== false || strpos($name, 'SECRET') !== false || strpos($name, 'KEY') !== false) {
                        $appConstants[$name] = '***MASKED***';
                    } else {
                        $appConstants[$name] = $value;
                    }
                    break;
                }
            }
        }

        return $appConstants;
    }

    /**
     * Log a configuration value
     */
    public static function configValue(string $name, $value): void
    {
        if (!self::isEnabled() || !self::$config['log_config']) {
            return;
        }

        self::log("Config: $name", ['value' => $value], 'config');
    }

    // =========================================================================
    // REQUEST/RESPONSE DEBUGGING
    // =========================================================================

    /**
     * Log the current request
     */
    private static function logRequest(): void
    {
        $request = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? '',
            'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0,
            'get_params' => array_keys($_GET),
            'post_params' => array_keys($_POST),
            'has_files' => !empty($_FILES),
            'ajax' => !empty($_SERVER['HTTP_X_REQUESTED_WITH']),
        ];

        self::log('Request started', $request, 'request');
    }

    /**
     * Log response info
     */
    public static function response(int $statusCode, ?string $contentType = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::log('Response', [
            'status_code' => $statusCode,
            'content_type' => $contentType ?? (headers_list()[0] ?? 'unknown'),
            'duration_ms' => round((microtime(true) - self::$startTime) * 1000, 2),
        ], 'response');
    }
}
