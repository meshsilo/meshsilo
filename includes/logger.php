<?php
/**
 * Logger Class
 *
 * Modern logging implementation with support for multiple channels,
 * log rotation, structured logging, and Docker stdout/stderr output.
 *
 * Channels:
 * - app: General application logs (default)
 * - security: Authentication, authorization, and security events
 * - access: HTTP request/response logging
 * - database: SQL queries and database operations
 * - error: PHP errors and exceptions (also written to php-error.log)
 */

class Logger {
    // Log levels (lower = more severe)
    const ERROR = 1;
    const WARNING = 2;
    const NOTICE = 3;
    const INFO = 4;
    const DEBUG = 5;

    // Default channels
    const CHANNEL_APP = 'app';
    const CHANNEL_SECURITY = 'security';
    const CHANNEL_ACCESS = 'access';
    const CHANNEL_DATABASE = 'database';
    const CHANNEL_ERROR = 'php-error';

    private static $instance = null;
    private $logPath;
    private $minLevel = self::INFO;
    private $channelLevels = [];
    private $requestId;
    private $maxFileSize = 5242880; // 5MB
    private $maxFiles = 10;
    private $requestStartTime;
    private $queryLog = [];
    private $slowQueryThreshold = 1.0; // seconds

    private function __construct() {
        $this->logPath = __DIR__ . '/../storage/logs/';
        $this->requestId = $this->generateRequestId();
        $this->requestStartTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

        // Create logs directory if it doesn't exist
        if (!file_exists($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }

        // Set default channel levels
        $this->channelLevels = [
            self::CHANNEL_APP => self::INFO,
            self::CHANNEL_SECURITY => self::INFO,
            self::CHANNEL_ACCESS => self::INFO,
            self::CHANNEL_DATABASE => self::WARNING, // Only log slow queries by default
            self::CHANNEL_ERROR => self::ERROR,
        ];
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function configure($config) {
        if (isset($config['min_level'])) {
            $this->minLevel = $config['min_level'];
        }
        if (isset($config['log_path'])) {
            $this->logPath = $config['log_path'];
        }
        if (isset($config['max_file_size'])) {
            $this->maxFileSize = $config['max_file_size'];
        }
        if (isset($config['max_files'])) {
            $this->maxFiles = $config['max_files'];
        }
        if (isset($config['channel_levels']) && is_array($config['channel_levels'])) {
            $this->channelLevels = array_merge($this->channelLevels, $config['channel_levels']);
        }
        if (isset($config['slow_query_threshold'])) {
            $this->slowQueryThreshold = $config['slow_query_threshold'];
        }
        return $this;
    }

    public function getRequestId() {
        return $this->requestId;
    }

    public function getRequestStartTime() {
        return $this->requestStartTime;
    }

    private function generateRequestId() {
        return substr(md5(uniqid('', true) . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12);
    }

    /**
     * Get the minimum log level for a channel
     */
    private function getChannelLevel($channel) {
        return $this->channelLevels[$channel] ?? $this->minLevel;
    }

    /**
     * Set the log level for a specific channel
     */
    public function setChannelLevel($channel, $level) {
        $this->channelLevels[$channel] = $level;
        return $this;
    }

    public function log($level, $message, $context = [], $channel = 'app') {
        // Check channel-specific level first, then global level
        $channelLevel = $this->getChannelLevel($channel);
        if ($level > $channelLevel) {
            return;
        }

        $levelNames = [
            self::ERROR => 'ERROR',
            self::WARNING => 'WARNING',
            self::INFO => 'INFO',
            self::DEBUG => 'DEBUG',
            self::NOTICE => 'NOTICE',
        ];

        $levelName = $levelNames[$level] ?? 'INFO';
        $timestamp = date('Y-m-d H:i:s');

        // Add request context
        $context['request_id'] = $this->requestId;

        // Format log entry
        $logEntry = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $levelName,
            $this->requestId,
            $message
        );

        // Add context if present
        if (!empty($context)) {
            // Handle exception objects
            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                $e = $context['exception'];
                $context['exception'] = [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ];
            }

            // Remove request_id from output since it's in the header
            unset($context['request_id']);

            if (!empty($context)) {
                $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $logEntry .= PHP_EOL;

        // Write to log file
        $logFile = $this->logPath . $channel . '.log';

        // Rotate if needed
        $this->rotateIfNeeded($logFile);

        // Write log entry
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Also write to stdout/stderr when running in Docker
        if (getenv('MESHSILO_DOCKER') === 'true') {
            $streamName = ($level <= self::WARNING) ? 'php://stderr' : 'php://stdout';
            $stream = @fopen($streamName, 'w');
            if ($stream) {
                @fwrite($stream, "[{$channel}] {$logEntry}");
                @fclose($stream);
            }
        }
    }

    // =========================================================================
    // Standard logging methods (default to 'app' channel)
    // =========================================================================

    public function error($message, $context = []) {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log(self::WARNING, $message, $context);
    }

    public function info($message, $context = []) {
        $this->log(self::INFO, $message, $context);
    }

    public function debug($message, $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }

    public function notice($message, $context = []) {
        $this->log(self::NOTICE, $message, $context);
    }

    public function exception($exception, $context = []) {
        if ($exception instanceof Throwable) {
            $context['exception'] = $exception;
            $this->error($exception->getMessage(), $context);
        }
    }

    // =========================================================================
    // Security logging (channel: security)
    // =========================================================================

    public function securityInfo($message, $context = []) {
        $context = $this->addSecurityContext($context);
        $this->log(self::INFO, $message, $context, self::CHANNEL_SECURITY);
    }

    public function securityWarning($message, $context = []) {
        $context = $this->addSecurityContext($context);
        $this->log(self::WARNING, $message, $context, self::CHANNEL_SECURITY);
    }

    public function securityError($message, $context = []) {
        $context = $this->addSecurityContext($context);
        $this->log(self::ERROR, $message, $context, self::CHANNEL_SECURITY);
    }

    /**
     * Log authentication events (login, logout, failed attempts)
     */
    public function authEvent($event, $username, $success = true, $context = []) {
        $context['event'] = $event;
        $context['username'] = $username;
        $context['success'] = $success;
        $context = $this->addSecurityContext($context);

        $level = $success ? self::INFO : self::WARNING;
        $message = $success
            ? "Auth success: {$event} for user '{$username}'"
            : "Auth failure: {$event} for user '{$username}'";

        $this->log($level, $message, $context, self::CHANNEL_SECURITY);
    }

    /**
     * Log permission checks
     */
    public function permissionDenied($permission, $userId, $context = []) {
        $context['permission'] = $permission;
        $context['user_id'] = $userId;
        $context = $this->addSecurityContext($context);

        $this->log(self::WARNING, "Permission denied: {$permission} for user {$userId}", $context, self::CHANNEL_SECURITY);
    }

    private function addSecurityContext($context) {
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        if (isset($_SESSION['user_id'])) {
            $context['session_user_id'] = $_SESSION['user_id'];
        }

        return $context;
    }

    // =========================================================================
    // Access logging (channel: access)
    // =========================================================================

    /**
     * Log HTTP request (call at start of request)
     */
    public function logRequest($context = []) {
        $context['method'] = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $context['uri'] = $_SERVER['REQUEST_URI'] ?? '/';
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        if (isset($_SESSION['user_id'])) {
            $context['user_id'] = $_SESSION['user_id'];
        }

        $message = sprintf('%s %s', $context['method'], $context['uri']);
        $this->log(self::INFO, $message, $context, self::CHANNEL_ACCESS);
    }

    /**
     * Log HTTP response (call at end of request)
     */
    public function logResponse($statusCode = null, $context = []) {
        $statusCode = $statusCode ?? http_response_code();
        $duration = (microtime(true) - $this->requestStartTime) * 1000; // ms

        $context['status'] = $statusCode;
        $context['duration_ms'] = round($duration, 2);
        $context['method'] = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $context['uri'] = $_SERVER['REQUEST_URI'] ?? '/';
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (isset($_SESSION['user_id'])) {
            $context['user_id'] = $_SESSION['user_id'];
        }

        // Memory usage
        $context['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);

        $level = ($statusCode >= 500) ? self::ERROR : (($statusCode >= 400) ? self::WARNING : self::INFO);
        $message = sprintf('%s %s %d %.2fms', $context['method'], $context['uri'], $statusCode, $duration);

        $this->log($level, $message, $context, self::CHANNEL_ACCESS);
    }

    // =========================================================================
    // Database logging (channel: database)
    // =========================================================================

    /**
     * Log a database query
     */
    public function logQuery($sql, $params = [], $duration = null, $context = []) {
        $context['sql'] = $sql;
        if (!empty($params)) {
            $context['params'] = $params;
        }
        if ($duration !== null) {
            $context['duration_ms'] = round($duration * 1000, 2);
        }

        // Store for summary
        $this->queryLog[] = [
            'sql' => substr($sql, 0, 100),
            'duration' => $duration,
        ];

        // Only log slow queries by default
        if ($duration !== null && $duration >= $this->slowQueryThreshold) {
            $message = sprintf('Slow query (%.2fs): %s', $duration, substr($sql, 0, 100));
            $this->log(self::WARNING, $message, $context, self::CHANNEL_DATABASE);
        } elseif ($this->getChannelLevel(self::CHANNEL_DATABASE) >= self::DEBUG) {
            $message = sprintf('Query: %s', substr($sql, 0, 100));
            $this->log(self::DEBUG, $message, $context, self::CHANNEL_DATABASE);
        }
    }

    /**
     * Log a database error
     */
    public function logQueryError($sql, $error, $context = []) {
        $context['sql'] = $sql;
        $context['error'] = $error;

        $message = sprintf('Query error: %s', $error);
        $this->log(self::ERROR, $message, $context, self::CHANNEL_DATABASE);
    }

    /**
     * Get query log summary
     */
    public function getQuerySummary() {
        $totalQueries = count($this->queryLog);
        $totalTime = array_sum(array_column($this->queryLog, 'duration'));
        $slowQueries = array_filter($this->queryLog, fn($q) => ($q['duration'] ?? 0) >= $this->slowQueryThreshold);

        return [
            'total_queries' => $totalQueries,
            'total_time_ms' => round($totalTime * 1000, 2),
            'slow_queries' => count($slowQueries),
        ];
    }

    // =========================================================================
    // Log rotation
    // =========================================================================

    private function rotateIfNeeded($logFile) {
        if (!file_exists($logFile)) {
            return;
        }

        $size = @filesize($logFile);
        if ($size === false || $size < $this->maxFileSize) {
            return;
        }

        // Rotate the file
        $timestamp = date('Y-m-d_H-i-s');
        $rotatedFile = $logFile . '.' . $timestamp;
        @rename($logFile, $rotatedFile);

        // Clean up old rotated files
        $this->cleanupOldRotatedFiles($logFile);
    }

    private function cleanupOldRotatedFiles($baseFile) {
        $pattern = $baseFile . '.*';
        $files = glob($pattern);

        if (!$files || count($files) <= $this->maxFiles) {
            return;
        }

        // Sort by modification time
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Delete oldest files
        $toDelete = count($files) - $this->maxFiles;
        for ($i = 0; $i < $toDelete; $i++) {
            @unlink($files[$i]);
        }
    }
}

// =============================================================================
// Helper functions for backward compatibility
// =============================================================================

function logError($message, $context = []) {
    Logger::getInstance()->error($message, $context);
}

function logWarning($message, $context = []) {
    Logger::getInstance()->warning($message, $context);
}

function logInfo($message, $context = []) {
    Logger::getInstance()->info($message, $context);
}

function logDebug($message, $context = []) {
    Logger::getInstance()->debug($message, $context);
}

function logNotice($message, $context = []) {
    Logger::getInstance()->notice($message, $context);
}

function logException($exception, $context = []) {
    Logger::getInstance()->exception($exception, $context);
}

// =============================================================================
// Security logging helpers
// =============================================================================

function logSecurityInfo($message, $context = []) {
    Logger::getInstance()->securityInfo($message, $context);
}

function logSecurityWarning($message, $context = []) {
    Logger::getInstance()->securityWarning($message, $context);
}

function logSecurityError($message, $context = []) {
    Logger::getInstance()->securityError($message, $context);
}

function logAuthEvent($event, $username, $success = true, $context = []) {
    Logger::getInstance()->authEvent($event, $username, $success, $context);
}

function logPermissionDenied($permission, $userId, $context = []) {
    Logger::getInstance()->permissionDenied($permission, $userId, $context);
}

// =============================================================================
// Access logging helpers
// =============================================================================

function logRequest($context = []) {
    Logger::getInstance()->logRequest($context);
}

function logResponse($statusCode = null, $context = []) {
    Logger::getInstance()->logResponse($statusCode, $context);
}

// =============================================================================
// Database logging helpers
// =============================================================================

function logQuery($sql, $params = [], $duration = null, $context = []) {
    Logger::getInstance()->logQuery($sql, $params, $duration, $context);
}

function logQueryError($sql, $error, $context = []) {
    Logger::getInstance()->logQueryError($sql, $error, $context);
}
