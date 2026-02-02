<?php
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

class Debug {
    private static bool $enabled = false;
    private static bool $initialized = false;
    private static array $config = [];
    private static array $logs = [];
    private static float $startTime;
    private static int $queryCount = 0;
    private static array $queries = [];
    private static array $timers = [];
    private static array $includes = [];
    private static array $routeInfo = [];
    private static array $authInfo = [];
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
    public static function init(): void {
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
    public static function isEnabled(): bool {
        if (!self::$initialized) {
            self::init();
        }
        return self::$enabled;
    }

    /**
     * Get config value
     */
    public static function config(string $key, $default = null) {
        return self::$config[$key] ?? $default;
    }

    // =========================================================================
    // LOGGING METHODS
    // =========================================================================

    /**
     * Log a debug message
     */
    public static function log(string $message, array $context = [], string $level = 'debug'): void {
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

    public static function info(string $message, array $context = []): void {
        self::log($message, $context, 'info');
    }

    public static function notice(string $message, array $context = []): void {
        self::log($message, $context, 'notice');
    }

    public static function warn(string $message, array $context = []): void {
        self::log($message, $context, 'warning');
    }

    public static function error(string $message, array $context = []): void {
        self::log($message, $context, 'error');
    }

    // =========================================================================
    // DATABASE DEBUGGING
    // =========================================================================

    /**
     * Log a database query
     */
    public static function query(string $sql, array $params = [], ?float $duration = null): void {
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
    public static function queryStart(): float {
        return microtime(true);
    }

    /**
     * End query timer and log
     */
    public static function queryEnd(float $start, string $sql, array $params = []): void {
        $duration = microtime(true) - $start;
        self::query($sql, $params, $duration);
    }

    // =========================================================================
    // SESSION & CSRF DEBUGGING
    // =========================================================================

    /**
     * Log session information
     */
    private static function logSession(): void {
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
    public static function diagnoseCsrf(): void {
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
            'cookie_samesite' => $cookieParams['samesite'] ?? 'not set',
        ], 'session');

        if ($cookieParams['secure'] && !$isHttps) {
            self::error('Cookie requires HTTPS but request is HTTP - cookie will not be sent');
        }

        self::log('=== CSRF DIAGNOSIS END ===', [], 'session');
    }

    /**
     * Log authentication events
     */
    public static function auth(string $event, array $context = []): void {
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
    public static function route(string $event, array $context = []): void {
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
    public static function routeMatched(string $pattern, string $name, string $file): void {
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
    public static function routeNotFound(string $uri): void {
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
    public static function upload(string $event, array $context = []): void {
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
    public static function diagnoseUpload(): void {
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

    private static function logFileInfo(string $fieldName, array $file): void {
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
    // PERFORMANCE DEBUGGING
    // =========================================================================

    /**
     * Start a timer
     */
    public static function timerStart(string $name): void {
        if (!self::isEnabled()) {
            return;
        }
        self::$timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }

    /**
     * End a timer and log
     */
    public static function timerEnd(string $name): ?float {
        if (!self::isEnabled() || !isset(self::$timers[$name])) {
            return null;
        }

        $start = self::$timers[$name]['start'];
        $memStart = self::$timers[$name]['memory_start'];
        $duration = microtime(true) - $start;
        $memUsed = memory_get_usage(true) - $memStart;

        unset(self::$timers[$name]);

        self::log("Timer '$name' completed", [
            'duration_ms' => round($duration * 1000, 2),
            'memory_used' => self::formatBytes($memUsed),
        ], 'timer');

        return $duration;
    }

    /**
     * Take a memory snapshot
     */
    public static function memorySnapshot(string $label): void {
        if (!self::isEnabled()) {
            return;
        }

        self::$memorySnapshots[$label] = [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'time' => microtime(true) - self::$startTime,
        ];

        if (self::$config['verbose']) {
            self::log("Memory snapshot: $label", [
                'current' => self::formatBytes(memory_get_usage(true)),
                'peak' => self::formatBytes(memory_get_peak_usage(true)),
            ], 'memory');
        }
    }

    // =========================================================================
    // CONFIGURATION DEBUGGING
    // =========================================================================

    /**
     * Capture configuration snapshot
     */
    private static function captureConfig(): void {
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
    private static function getAppConstants(): array {
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
    public static function configValue(string $name, $value): void {
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
    private static function logRequest(): void {
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
    public static function response(int $statusCode, ?string $contentType = null): void {
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
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
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
    public static function handleShutdown(): void {
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
    public static function exception(\Throwable $e, array $context = []): void {
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
    public static function dump($var, string $label = 'dump'): void {
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
    public static function dd($var, string $label = 'dump'): never {
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

    // =========================================================================
    // FILE OPERATIONS
    // =========================================================================

    /**
     * Write log entry to file
     */
    private static function writeToFile(array $entry): void {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/debug.log';

        // Rotate if too large (> 5MB)
        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            @rename($logFile, $logFile . '.' . date('Y-m-d-His'));
        }

        $contextStr = !empty($entry['context'])
            ? ' ' . json_encode($entry['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        $line = sprintf(
            "[%s] [+%.4fs] [%s] %s%s%s\n",
            date('Y-m-d H:i:s'),
            $entry['time'],
            strtoupper($entry['level']),
            $entry['message'],
            $contextStr,
            $entry['file'] ? " ({$entry['file']}:{$entry['line']})" : ''
        );

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Write request summary
     */
    private static function writeSummary(): void {
        $duration = microtime(true) - self::$startTime;
        $memory = memory_get_peak_usage(true);
        $errors = count(array_filter(self::$logs, fn($l) => in_array($l['level'], ['error', 'fatal'])));
        $warnings = count(array_filter(self::$logs, fn($l) => $l['level'] === 'warning'));

        $summary = sprintf(
            "[%s] === REQUEST COMPLETE === %s %s | %.0fms | %s | %d queries | %d errors | %d warnings\n%s\n",
            date('Y-m-d H:i:s'),
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? '',
            $duration * 1000,
            self::formatBytes($memory),
            self::$queryCount,
            $errors,
            $warnings,
            str_repeat('=', 100)
        );

        $logFile = dirname(__DIR__) . '/storage/logs/debug.log';
        @file_put_contents($logFile, $summary, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // DEBUG BAR RENDERING
    // =========================================================================

    /**
     * Render debug bar HTML
     */
    public static function renderBar(): string {
        if (!self::isEnabled() || !self::$config['show_bar']) {
            return '';
        }

        $metrics = self::getMetrics();
        $errors = count(array_filter(self::$logs, fn($l) => in_array($l['level'], ['error', 'fatal'])));
        $warnings = count(array_filter(self::$logs, fn($l) => $l['level'] === 'warning'));

        $durationMs = round($metrics['duration'] * 1000);
        $memory = self::formatBytes($metrics['memory_peak']);
        $queryTime = array_sum(array_column(self::$queries, 'duration'));
        $queryTimeMs = round($queryTime * 1000, 1);

        $errorColor = $errors > 0 ? '#ef4444' : ($warnings > 0 ? '#f59e0b' : '#22c55e');
        $issueCount = $errors + $warnings;

        // Build panels HTML
        $logsHtml = self::renderLogsPanel();
        $queriesHtml = self::renderQueriesPanel();
        $sessionHtml = self::renderSessionPanel();
        $requestHtml = self::renderRequestPanel();
        $configHtml = self::renderConfigPanel();
        $timelineHtml = self::renderTimelinePanel();

        return <<<HTML
<div id="debug-bar" style="
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #1a1a2e;
    color: #e8e8e8;
    font-family: 'Fira Code', 'Monaco', 'Consolas', monospace;
    font-size: 12px;
    z-index: 99999;
    border-top: 2px solid #6366f1;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
">
    <div style="display: flex; align-items: center; padding: 8px 15px; gap: 20px; flex-wrap: wrap;">
        <span style="color: #6366f1; font-weight: bold; display: flex; align-items: center; gap: 5px;">
            <span style="font-size: 16px;">🔧</span> DEBUG
        </span>
        <span title="Total request time" style="cursor: help;">⏱️ {$durationMs}ms</span>
        <span title="Peak memory usage" style="cursor: help;">💾 {$memory}</span>
        <span title="Database queries ({$queryTimeMs}ms)" style="cursor: help;">🗄️ {$metrics['query_count']} queries</span>
        <span title="{$errors} errors, {$warnings} warnings" style="color: {$errorColor}; cursor: help;">⚠️ {$issueCount} issues</span>
        <span title="Log entries" style="cursor: help;">📝 {$metrics['log_count']} logs</span>

        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button onclick="debugTogglePanel('logs')" class="debug-tab-btn" data-panel="logs">Logs</button>
            <button onclick="debugTogglePanel('queries')" class="debug-tab-btn" data-panel="queries">Queries</button>
            <button onclick="debugTogglePanel('session')" class="debug-tab-btn" data-panel="session">Session</button>
            <button onclick="debugTogglePanel('request')" class="debug-tab-btn" data-panel="request">Request</button>
            <button onclick="debugTogglePanel('config')" class="debug-tab-btn" data-panel="config">Config</button>
            <button onclick="debugTogglePanel('timeline')" class="debug-tab-btn" data-panel="timeline">Timeline</button>
            <button onclick="document.getElementById('debug-bar').style.display='none'"
                    style="background: transparent; border: none; color: #888; cursor: pointer; font-size: 16px; margin-left: 10px;">✕</button>
        </div>
    </div>

    <div id="debug-panel-logs" class="debug-panel" style="display: none;">{$logsHtml}</div>
    <div id="debug-panel-queries" class="debug-panel" style="display: none;">{$queriesHtml}</div>
    <div id="debug-panel-session" class="debug-panel" style="display: none;">{$sessionHtml}</div>
    <div id="debug-panel-request" class="debug-panel" style="display: none;">{$requestHtml}</div>
    <div id="debug-panel-config" class="debug-panel" style="display: none;">{$configHtml}</div>
    <div id="debug-panel-timeline" class="debug-panel" style="display: none;">{$timelineHtml}</div>
</div>

<style>
.debug-tab-btn {
    background: #2d2d44;
    border: 1px solid #3d3d5c;
    color: #a0a0a0;
    padding: 4px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-family: inherit;
    font-size: 11px;
    transition: all 0.2s;
}
.debug-tab-btn:hover, .debug-tab-btn.active {
    background: #6366f1;
    border-color: #6366f1;
    color: white;
}
.debug-panel {
    max-height: 350px;
    overflow: auto;
    border-top: 1px solid #2d2d44;
    padding: 15px;
    background: #0f0f1a;
}
.debug-section {
    margin-bottom: 15px;
}
.debug-section h4 {
    color: #6366f1;
    margin: 0 0 10px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.debug-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}
.debug-table th, .debug-table td {
    padding: 6px 10px;
    text-align: left;
    border-bottom: 1px solid #2d2d44;
}
.debug-table th {
    color: #888;
    font-weight: normal;
    background: #1a1a2e;
}
.debug-table tr:hover td {
    background: #1a1a2e;
}
.debug-log-entry {
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 3px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.debug-log-entry:hover {
    background: #1a1a2e;
}
.debug-log-time {
    color: #666;
    flex-shrink: 0;
    width: 60px;
}
.debug-log-level {
    flex-shrink: 0;
    width: 70px;
    font-weight: bold;
}
.debug-log-message {
    flex: 1;
    word-break: break-word;
}
.debug-log-context {
    color: #666;
    font-size: 10px;
    margin-top: 2px;
}
.debug-log-location {
    color: #555;
    font-size: 10px;
    flex-shrink: 0;
}
</style>

<script>
function debugTogglePanel(name) {
    document.querySelectorAll('.debug-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.debug-tab-btn').forEach(b => b.classList.remove('active'));

    const panel = document.getElementById('debug-panel-' + name);
    const btn = document.querySelector('[data-panel="' + name + '"]');

    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        btn.classList.add('active');
    }
}
</script>
HTML;
    }

    private static function renderLogsPanel(): string {
        $html = '<div class="debug-section">';

        if (empty(self::$logs)) {
            $html .= '<p style="color: #666;">No log entries</p>';
        } else {
            foreach (self::$logs as $log) {
                $levelInfo = self::LEVELS[$log['level']] ?? ['color' => '#888', 'icon' => '•'];
                $context = !empty($log['context'])
                    ? '<div class="debug-log-context">' . htmlspecialchars(json_encode($log['context'], JSON_UNESCAPED_SLASHES)) . '</div>'
                    : '';
                $location = $log['file'] ? "{$log['file']}:{$log['line']}" : '';

                $html .= sprintf(
                    '<div class="debug-log-entry">
                        <span class="debug-log-time">+%.3fs</span>
                        <span class="debug-log-level" style="color: %s;">%s %s</span>
                        <div class="debug-log-message">%s%s</div>
                        <span class="debug-log-location">%s</span>
                    </div>',
                    $log['time'],
                    $levelInfo['color'],
                    $levelInfo['icon'],
                    strtoupper($log['level']),
                    htmlspecialchars($log['message']),
                    $context,
                    $location
                );
            }
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderQueriesPanel(): string {
        $html = '<div class="debug-section">';
        $html .= '<h4>🗄️ Database Queries (' . self::$queryCount . ')</h4>';

        if (empty(self::$queries)) {
            $html .= '<p style="color: #666;">No queries executed</p>';
        } else {
            $html .= '<table class="debug-table"><thead><tr><th>#</th><th>Time</th><th>Duration</th><th>Query</th></tr></thead><tbody>';

            foreach (self::$queries as $q) {
                $duration = $q['duration'] ? round($q['duration'] * 1000, 2) . 'ms' : '-';
                $durationColor = ($q['duration'] ?? 0) > 0.1 ? '#ef4444' : '#22c55e';
                $sql = htmlspecialchars(substr($q['sql'], 0, 300));
                if (strlen($q['sql']) > 300) $sql .= '...';

                $html .= sprintf(
                    '<tr>
                        <td>%d</td>
                        <td>+%.3fs</td>
                        <td style="color: %s;">%s</td>
                        <td><code style="color: #22c55e;">%s</code></td>
                    </tr>',
                    $q['index'],
                    $q['time'],
                    $durationColor,
                    $duration,
                    $sql
                );
            }

            $html .= '</tbody></table>';

            $totalTime = array_sum(array_column(self::$queries, 'duration'));
            $html .= '<p style="margin-top: 10px; color: #888;">Total query time: ' . round($totalTime * 1000, 2) . 'ms</p>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderSessionPanel(): string {
        $html = '<div class="debug-section">';
        $html .= '<h4>🔐 Session & Authentication</h4>';

        $savePath = session_save_path() ?: sys_get_temp_dir();
        $cookieParams = session_get_cookie_params();

        $html .= '<table class="debug-table"><tbody>';
        $html .= '<tr><td>Session ID</td><td><code>' . session_id() . '</code></td></tr>';
        $html .= '<tr><td>Session Status</td><td>' . self::getSessionStatusName() . '</td></tr>';
        $html .= '<tr><td>Save Path</td><td>' . htmlspecialchars($savePath) . ' ' . (is_writable($savePath) ? '✅' : '❌ not writable') . '</td></tr>';
        $html .= '<tr><td>User ID</td><td>' . ($_SESSION['user_id'] ?? '<em>not logged in</em>') . '</td></tr>';
        $html .= '<tr><td>CSRF Token</td><td>' . (isset($_SESSION['csrf_token']) ? '<code>' . self::tokenPreview($_SESSION['csrf_token']) . '</code> (' . strlen($_SESSION['csrf_token']) . ' chars)' : '❌ NOT SET') . '</td></tr>';
        $html .= '<tr><td>Cookie Domain</td><td>' . ($cookieParams['domain'] ?: '<em>current host</em>') . '</td></tr>';
        $html .= '<tr><td>Cookie Path</td><td>' . $cookieParams['path'] . '</td></tr>';
        $html .= '<tr><td>Cookie Secure</td><td>' . ($cookieParams['secure'] ? 'Yes (HTTPS only)' : 'No') . '</td></tr>';
        $html .= '<tr><td>Cookie SameSite</td><td>' . ($cookieParams['samesite'] ?? 'not set') . '</td></tr>';
        $html .= '<tr><td>Session Keys</td><td>' . implode(', ', array_keys($_SESSION ?? [])) . '</td></tr>';
        $html .= '</tbody></table>';

        $html .= '</div>';
        return $html;
    }

    private static function renderRequestPanel(): string {
        $html = '<div class="debug-section">';
        $html .= '<h4>📥 Request Details</h4>';

        $html .= '<table class="debug-table"><tbody>';
        $html .= '<tr><td>Method</td><td><strong>' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . '</strong></td></tr>';
        $html .= '<tr><td>URI</td><td>' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '</td></tr>';
        $html .= '<tr><td>Host</td><td>' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') . '</td></tr>';
        $html .= '<tr><td>HTTPS</td><td>' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'Yes' : 'No') . '</td></tr>';
        $html .= '<tr><td>Content-Type</td><td>' . htmlspecialchars($_SERVER['CONTENT_TYPE'] ?? 'not set') . '</td></tr>';
        $html .= '<tr><td>Content-Length</td><td>' . ($_SERVER['CONTENT_LENGTH'] ?? 'not set') . '</td></tr>';
        $html .= '<tr><td>User Agent</td><td style="max-width: 400px; word-break: break-all;">' . htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 150)) . '</td></tr>';
        $html .= '<tr><td>Remote IP</td><td>' . ($_SERVER['REMOTE_ADDR'] ?? '') . '</td></tr>';
        $html .= '<tr><td>Referer</td><td>' . htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'none') . '</td></tr>';
        $html .= '</tbody></table>';

        if (!empty($_GET)) {
            $html .= '<h4 style="margin-top: 15px;">GET Parameters</h4>';
            $html .= '<table class="debug-table"><tbody>';
            foreach ($_GET as $k => $v) {
                $html .= '<tr><td>' . htmlspecialchars($k) . '</td><td><code>' . htmlspecialchars(is_array($v) ? json_encode($v) : substr($v, 0, 100)) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!empty($_POST)) {
            $html .= '<h4 style="margin-top: 15px;">POST Parameters</h4>';
            $html .= '<table class="debug-table"><tbody>';
            foreach ($_POST as $k => $v) {
                $display = (stripos($k, 'password') !== false || stripos($k, 'token') !== false || stripos($k, 'csrf') !== false)
                    ? '***masked***'
                    : (is_array($v) ? json_encode($v) : substr($v, 0, 100));
                $html .= '<tr><td>' . htmlspecialchars($k) . '</td><td><code>' . htmlspecialchars($display) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!empty($_FILES)) {
            $html .= '<h4 style="margin-top: 15px;">Uploaded Files</h4>';
            $html .= '<table class="debug-table"><thead><tr><th>Field</th><th>Name</th><th>Size</th><th>Type</th><th>Error</th></tr></thead><tbody>';
            foreach ($_FILES as $field => $file) {
                if (is_array($file['name'])) {
                    for ($i = 0; $i < count($file['name']); $i++) {
                        $html .= sprintf(
                            '<tr><td>%s[%d]</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                            htmlspecialchars($field), $i,
                            htmlspecialchars($file['name'][$i]),
                            self::formatBytes($file['size'][$i]),
                            htmlspecialchars($file['type'][$i]),
                            $file['error'][$i] === 0 ? '✅' : '❌ ' . $file['error'][$i]
                        );
                    }
                } else {
                    $html .= sprintf(
                        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                        htmlspecialchars($field),
                        htmlspecialchars($file['name']),
                        self::formatBytes($file['size']),
                        htmlspecialchars($file['type']),
                        $file['error'] === 0 ? '✅' : '❌ ' . $file['error']
                    );
                }
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderConfigPanel(): string {
        $html = '<div class="debug-section">';
        $html .= '<h4>⚙️ Configuration</h4>';

        if (!empty(self::$configSnapshot['defined_constants'])) {
            $html .= '<h5 style="color: #888; margin: 10px 0 5px;">Application Constants</h5>';
            $html .= '<table class="debug-table"><tbody>';
            foreach (self::$configSnapshot['defined_constants'] as $name => $value) {
                $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td><code>' . htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<h5 style="color: #888; margin: 15px 0 5px;">PHP Settings</h5>';
        $html .= '<table class="debug-table"><tbody>';
        foreach (self::$configSnapshot['php_settings'] ?? [] as $name => $value) {
            $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td><code>' . htmlspecialchars((string)$value) . '</code></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h5 style="color: #888; margin: 15px 0 5px;">Server Info</h5>';
        $html .= '<table class="debug-table"><tbody>';
        foreach (self::$configSnapshot['server'] ?? [] as $name => $value) {
            $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td><code>' . htmlspecialchars((string)$value) . '</code></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '</div>';
        return $html;
    }

    private static function renderTimelinePanel(): string {
        $html = '<div class="debug-section">';
        $html .= '<h4>📊 Timeline</h4>';

        $totalDuration = microtime(true) - self::$startTime;
        $scale = 100 / $totalDuration; // percentage per second

        $html .= '<div style="position: relative; height: 30px; background: #1a1a2e; border-radius: 4px; margin: 10px 0; overflow: hidden;">';

        // Query time bar
        $queryTime = array_sum(array_column(self::$queries, 'duration'));
        $queryWidth = $queryTime * $scale;
        $html .= '<div style="position: absolute; left: 0; top: 0; height: 100%; width: ' . $queryWidth . '%; background: #22c55e; opacity: 0.7;" title="Queries: ' . round($queryTime * 1000) . 'ms"></div>';

        $html .= '</div>';

        $html .= '<div style="display: flex; gap: 20px; font-size: 11px; color: #888;">';
        $html .= '<span><span style="display: inline-block; width: 12px; height: 12px; background: #22c55e; border-radius: 2px; margin-right: 5px;"></span>Queries (' . round($queryTime * 1000) . 'ms)</span>';
        $html .= '<span>Total: ' . round($totalDuration * 1000) . 'ms</span>';
        $html .= '</div>';

        // Memory timeline
        if (!empty(self::$memorySnapshots)) {
            $html .= '<h5 style="color: #888; margin: 15px 0 5px;">Memory Snapshots</h5>';
            $html .= '<table class="debug-table"><thead><tr><th>Label</th><th>Time</th><th>Current</th><th>Peak</th></tr></thead><tbody>';
            foreach (self::$memorySnapshots as $label => $snap) {
                $html .= sprintf(
                    '<tr><td>%s</td><td>+%.3fs</td><td>%s</td><td>%s</td></tr>',
                    htmlspecialchars($label),
                    $snap['time'],
                    self::formatBytes($snap['current']),
                    self::formatBytes($snap['peak'])
                );
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        return $html;
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    public static function getMetrics(): array {
        return [
            'duration' => microtime(true) - self::$startTime,
            'memory_current' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'query_count' => self::$queryCount,
            'log_count' => count(self::$logs),
        ];
    }

    public static function getLogs(): array {
        return self::$logs;
    }

    public static function getQueries(): array {
        return self::$queries;
    }

    private static function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private static function formatValue($var, int $depth = 0): mixed {
        $maxLen = self::$config['truncate_strings'] ?? 500;

        if ($depth > 3) {
            return '[max depth]';
        }
        if (is_object($var)) {
            return get_class($var) . ' {...}';
        }
        if (is_array($var)) {
            if (count($var) > 50) {
                return '[array: ' . count($var) . ' items]';
            }
            $result = [];
            foreach ($var as $key => $value) {
                $result[$key] = self::formatValue($value, $depth + 1);
            }
            return $result;
        }
        if (is_string($var) && strlen($var) > $maxLen) {
            return substr($var, 0, $maxLen) . '... [' . strlen($var) . ' chars]';
        }
        return $var;
    }

    private static function sanitizeContext(array $context): array {
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'auth', 'credential'];

        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            if (is_string($key)) {
                foreach ($sensitiveKeys as $sensitive) {
                    if (stripos($key, $sensitive) !== false && $key !== 'csrf_token') {
                        $value = '***';
                        return;
                    }
                }
            }
            if (is_string($value) && strlen($value) > 500) {
                $value = substr($value, 0, 500) . '...';
            }
        });

        return $context;
    }

    private static function shortenPath(string $path): string {
        $root = dirname(__DIR__);
        if (strpos($path, $root) === 0) {
            return substr($path, strlen($root) + 1);
        }
        return basename(dirname($path)) . '/' . basename($path);
    }

    private static function tokenPreview(string $token): string {
        return substr($token, 0, 8) . '...' . substr($token, -4);
    }

    private static function getSessionStatusName(): string {
        return match(session_status()) {
            PHP_SESSION_DISABLED => 'Disabled',
            PHP_SESSION_NONE => 'None (not started)',
            PHP_SESSION_ACTIVE => 'Active',
            default => 'Unknown'
        };
    }

    private static function getShortTrace(int $skip = 2): array {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::$config['trace_depth'] + $skip);
        $trace = array_slice($trace, $skip);

        return array_map(function ($frame) {
            return [
                'file' => self::shortenPath($frame['file'] ?? ''),
                'line' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }, $trace);
    }

    private static function formatTrace(array $trace): array {
        $depth = self::$config['trace_depth'] ?? 5;
        $trace = array_slice($trace, 0, $depth);

        return array_map(function ($frame) {
            return self::shortenPath($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0) . ' ' .
                   ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }, $trace);
    }
}

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

if (!function_exists('debug_log')) {
    function debug_log(string $message, array $context = []): void {
        Debug::log($message, $context);
    }
}

if (!function_exists('debug_info')) {
    function debug_info(string $message, array $context = []): void {
        Debug::info($message, $context);
    }
}

if (!function_exists('debug_warn')) {
    function debug_warn(string $message, array $context = []): void {
        Debug::warn($message, $context);
    }
}

if (!function_exists('debug_error')) {
    function debug_error(string $message, array $context = []): void {
        Debug::error($message, $context);
    }
}

if (!function_exists('debug_dump')) {
    function debug_dump($var, string $label = 'dump'): void {
        Debug::dump($var, $label);
    }
}

if (!function_exists('debug_dd')) {
    function debug_dd($var, string $label = 'dump'): never {
        Debug::dd($var, $label);
    }
}

if (!function_exists('debug_timer_start')) {
    function debug_timer_start(string $name): void {
        Debug::timerStart($name);
    }
}

if (!function_exists('debug_timer_end')) {
    function debug_timer_end(string $name): ?float {
        return Debug::timerEnd($name);
    }
}
