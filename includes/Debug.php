<?php

require_once __DIR__ . '/DebugProfiler.php';
require_once __DIR__ . '/DebugPanels.php';

/**
 * Comprehensive Debug System
 *
 * Enable by creating a .debug file in the project root.
 *
 * Optional: Put JSON config in .debug file:
 * {
 *   "log_requests": true,
 *   "log_sessions": true,
 *   "log_queries": true,
 *   "log_errors": true,
 *   "log_routes": true,
 *   "log_auth": true,
 *   "log_uploads": true,
 *   "log_includes": true,
 *   "show_bar": true,
 *   "log_to_file": true,
 *   "verbose": false,
 *   "trace_depth": 5
 * }
 */

class Debug
{
    use DebugProfiler;
    use DebugPanels;
    private static bool $enabled = false;
    private static bool $initialized = false;
    private static array $config = [];
    private static array $logs = [];
    private static float $startTime;
    private static int $queryCount = 0;
    private static array $queries = [];
    private static array $timers = [];
    /** @phpstan-ignore property.onlyWritten */
    private static array $includes = [];
    /** @phpstan-ignore property.onlyWritten */
    private static array $routeInfo = [];
    /** @phpstan-ignore property.onlyWritten */
    private static array $authInfo = [];
    /** @phpstan-ignore property.onlyWritten */
    private static array $uploadInfo = [];
    private static array $configSnapshot = [];
    private static array $memorySnapshots = [];
    private static string $debugFile = '';

    // Log levels with colors for the debug bar
    const LEVELS = [
        'debug' => ['color' => '#888', 'icon' => '🔍'],
        'info' => ['color' => '#3b82f6', 'icon' => 'ℹ️'],
        'notice' => ['color' => '#06b6d4', 'icon' => '📝'],
        'warning' => ['color' => '#f59e0b', 'icon' => '⚠️'],
        'error' => ['color' => '#ef4444', 'icon' => '❌'],
        'fatal' => ['color' => '#dc2626', 'icon' => '💀'],
        'query' => ['color' => '#22c55e', 'icon' => '🗄️'],
        'session' => ['color' => '#8b5cf6', 'icon' => '🔐'],
        'request' => ['color' => '#06b6d4', 'icon' => '📥'],
        'response' => ['color' => '#14b8a6', 'icon' => '📤'],
        'route' => ['color' => '#ec4899', 'icon' => '🛤️'],
        'auth' => ['color' => '#f97316', 'icon' => '👤'],
        'upload' => ['color' => '#84cc16', 'icon' => '📁'],
        'timer' => ['color' => '#a855f7', 'icon' => '⏱️'],
        'memory' => ['color' => '#06b6d4', 'icon' => '💾'],
        'config' => ['color' => '#64748b', 'icon' => '⚙️'],
        'include' => ['color' => '#78716c', 'icon' => '📦'],
        'dump' => ['color' => '#eab308', 'icon' => '📋'],
    ];

    /**
     * Initialize debug system
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;
        self::$startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

        // Check for .debug file
        self::$debugFile = dirname(__DIR__) . '/.debug';
        if (!file_exists(self::$debugFile)) {
            return;
        }

        self::$enabled = true;

        // Load config from .debug file if it contains JSON
        $content = trim(file_get_contents(self::$debugFile));
        if (!empty($content) && $content[0] === '{') {
            $config = json_decode($content, true);
            if (is_array($config)) {
                self::$config = $config;
            }
        }

        // Set defaults
        self::$config = array_merge([
            'log_requests' => true,
            'log_sessions' => true,
            'log_queries' => true,
            'log_errors' => true,
            'log_routes' => true,
            'log_auth' => true,
            'log_uploads' => true,
            'log_includes' => false, // Can be noisy
            'log_config' => true,
            'show_bar' => true,
            'log_to_file' => true,
            'verbose' => false,
            'trace_depth' => 5,
            'max_log_entries' => 500,
            'truncate_strings' => 500,
        ], self::$config);

        // Set error reporting to maximum
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        // Register error handlers
        if (self::$config['log_errors']) {
            set_error_handler([self::class, 'handleError']);
            register_shutdown_function([self::class, 'handleShutdown']);
        }

        // Log request start
        if (self::$config['log_requests']) {
            self::logRequest();
        }

        // Log session info
        if (self::$config['log_sessions']) {
            self::logSession();
        }

        // Capture initial memory
        self::memorySnapshot('init');

        // Log config snapshot
        if (self::$config['log_config']) {
            self::captureConfig();
        }
    }

    /**
     * Check if debug mode is enabled
     */
    public static function isEnabled(): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        return self::$enabled;
    }

    /**
     * Get config value
     */
    public static function config(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    // =========================================================================
    // LOGGING METHODS
    // =========================================================================

    /**
     * Log a debug message
     */
    public static function log(string $message, array $context = [], string $level = 'debug'): void
    {
        if (!self::isEnabled()) {
            return;
        }

        // Limit log entries
        if (count(self::$logs) >= self::$config['max_log_entries']) {
            return;
        }

        $entry = [
            'time' => microtime(true) - self::$startTime,
            'level' => $level,
            'message' => $message,
            'context' => self::sanitizeContext($context),
            'memory' => memory_get_usage(true),
            'file' => null,
            'line' => null,
        ];

        // Get caller info
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[1] ?? $trace[0] ?? [];
        $entry['file'] = self::shortenPath($caller['file'] ?? '');
        $entry['line'] = $caller['line'] ?? 0;

        self::$logs[] = $entry;

        // Also write to debug log file
        if (self::$config['log_to_file']) {
            self::writeToFile($entry);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::log($message, $context, 'info');
    }

    public static function notice(string $message, array $context = []): void
    {
        self::log($message, $context, 'notice');
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log($message, $context, 'warning');
    }

    public static function error(string $message, array $context = []): void
    {
        self::log($message, $context, 'error');
    }

    // =========================================================================
    // DATABASE DEBUGGING
    // =========================================================================

    /**
     * Log a database query
     */
    public static function query(string $sql, array $params = [], ?float $duration = null): void
    {
        if (!self::isEnabled() || !self::$config['log_queries']) {
            return;
        }

        self::$queryCount++;

        $queryInfo = [
            'index' => self::$queryCount,
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'time' => microtime(true) - self::$startTime,
            'trace' => self::getShortTrace(3),
        ];

        self::$queries[] = $queryInfo;

        if (self::$config['verbose']) {
            self::log("Query #{$queryInfo['index']}: " . substr($sql, 0, 100), [
                'params' => $params,
                'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            ], 'query');
        }

        // Warn on slow queries (> 100ms)
        if ($duration && $duration > 0.1) {
            self::warn("Slow query ({$queryInfo['index']}): " . round($duration * 1000) . "ms", [
                'sql' => substr($sql, 0, 200),
            ]);
        }
    }

    /**
     * Start a query timer
     */
    public static function queryStart(): float
    {
        return microtime(true);
    }

    /**
     * End query timer and log
     */
    public static function queryEnd(float $start, string $sql, array $params = []): void
    {
        $duration = microtime(true) - $start;
        self::query($sql, $params, $duration);
    }

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
            'size' => self::formatBytes($file['size']),
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

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    /**
     * Handle PHP errors
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $errorTypes = [
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        $type = $errorTypes[$errno] ?? 'Unknown';
        $level = in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])
            ? 'error'
            : (in_array($errno, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING]) ? 'warning' : 'notice');

        self::log("PHP $type: $errstr", [
            'file' => self::shortenPath($errfile),
            'line' => $errline,
            'errno' => $errno,
            'trace' => self::getShortTrace(3),
        ], $level);

        return !in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]);
    }

    /**
     * Handle shutdown (for fatal errors)
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::log('Fatal Error: ' . $error['message'], [
                'file' => self::shortenPath($error['file']),
                'line' => $error['line'],
                'type' => $error['type'],
            ], 'fatal');
        }

        // Write summary
        if (self::isEnabled() && self::$config['log_to_file']) {
            self::writeSummary();
        }
    }

    /**
     * Log an exception
     */
    public static function exception(\Throwable $e, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::log(get_class($e) . ': ' . $e->getMessage(), array_merge([
            'file' => self::shortenPath($e->getFile()),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'trace' => self::formatTrace($e->getTrace()),
        ], $context), 'error');
    }

    // =========================================================================
    // DUMP & INSPECTION
    // =========================================================================

    /**
     * Dump a variable with context
     */
    public static function dump($var, string $label = 'dump'): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::log($label, [
            'type' => gettype($var),
            'value' => self::formatValue($var),
        ], 'dump');
    }

    /**
     * Dump and die (for debugging)
     */
    public static function dd($var, string $label = 'dump'): never
    {
        self::dump($var, $label);

        if (self::isEnabled()) {
            echo "<pre style='background:#1a1a2e;color:#e8e8e8;padding:20px;margin:20px;border-radius:8px;'>";
            echo "<strong style='color:#6366f1;'>Debug Dump: $label</strong>\n\n";
            var_dump($var);
            echo "\n\n<strong style='color:#6366f1;'>Debug Log:</strong>\n";
            foreach (array_slice(self::$logs, -20) as $log) {
                $color = self::LEVELS[$log['level']]['color'] ?? '#888';
                printf(
                    "<span style='color:#666;'>[+%.3fs]</span> <span style='color:%s;'>[%s]</span> %s\n",
                    $log['time'],
                    $color,
                    strtoupper($log['level']),
                    htmlspecialchars($log['message'])
                );
            }
            echo "</pre>";
        }

        exit(1);
    }

}

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

if (!function_exists('debug_log')) {
    function debug_log(string $message, array $context = []): void
    {
        Debug::log($message, $context);
    }
}

if (!function_exists('debug_info')) {
    function debug_info(string $message, array $context = []): void
    {
        Debug::info($message, $context);
    }
}

if (!function_exists('debug_warn')) {
    function debug_warn(string $message, array $context = []): void
    {
        Debug::warn($message, $context);
    }
}

if (!function_exists('debug_error')) {
    function debug_error(string $message, array $context = []): void
    {
        Debug::error($message, $context);
    }
}

if (!function_exists('debug_dump')) {
    function debug_dump($var, string $label = 'dump'): void
    {
        Debug::dump($var, $label);
    }
}

if (!function_exists('debug_dd')) {
    function debug_dd($var, string $label = 'dump'): never
    {
        Debug::dd($var, $label);
    }
}

if (!function_exists('debug_timer_start')) {
    function debug_timer_start(string $name): void
    {
        Debug::timerStart($name);
    }
}

if (!function_exists('debug_timer_end')) {
    function debug_timer_end(string $name): ?float
    {
        return Debug::timerEnd($name);
    }
}
