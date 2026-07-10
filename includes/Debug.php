<?php

require_once __DIR__ . '/DebugProfiler.php';
require_once __DIR__ . '/DebugPanels.php';
require_once __DIR__ . '/DebugQueryLog.php';
require_once __DIR__ . '/DebugDiagnostics.php';

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
    use DebugQueryLog;
    use DebugDiagnostics;
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
