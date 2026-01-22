<?php
/**
 * Logging System
 *
 * This file provides backward-compatible wrapper functions around the new Logger class.
 * All existing code using logError(), logWarning(), etc. will continue to work.
 */

require_once __DIR__ . '/Logger.php';

// Legacy constants (kept for backward compatibility)
define('LOG_PATH', __DIR__ . '/../logs/');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('LOG_MAX_FILES', 10);

define('LOG_LEVEL_ERROR', 1);
define('LOG_LEVEL_WARNING', 2);
define('LOG_LEVEL_INFO', 3);
define('LOG_LEVEL_DEBUG', 4);
define('LOG_LEVEL_AUDIT', 5);

define('LOG_CHANNEL_DEFAULT', 'app');
define('LOG_CHANNEL_AUTH', 'auth');
define('LOG_CHANNEL_UPLOAD', 'upload');
define('LOG_CHANNEL_ADMIN', 'admin');
define('LOG_CHANNEL_AUDIT', 'audit');

/**
 * Initialize logging - now handled by Logger class
 */
function initLogger() {
    // Logger auto-initializes, this is now a no-op
    Logger::getInstance();
}

/**
 * Get the configured minimum log level
 */
function getLogLevel() {
    static $level = null;
    if ($level === null) {
        if (function_exists('getSetting')) {
            $levelStr = getSetting('log_level', 'info');
        } else {
            $levelStr = 'info';
        }
        $levels = [
            'error' => LOG_LEVEL_ERROR,
            'warning' => LOG_LEVEL_WARNING,
            'info' => LOG_LEVEL_INFO,
            'debug' => LOG_LEVEL_DEBUG,
            'audit' => LOG_LEVEL_AUDIT
        ];
        $level = $levels[strtolower($levelStr)] ?? LOG_LEVEL_INFO;

        // Configure the new logger with the same level
        $loggerLevels = [
            LOG_LEVEL_ERROR => Logger::ERROR,
            LOG_LEVEL_WARNING => Logger::WARNING,
            LOG_LEVEL_INFO => Logger::INFO,
            LOG_LEVEL_DEBUG => Logger::DEBUG,
            LOG_LEVEL_AUDIT => Logger::DEBUG,
        ];
        Logger::getInstance()->configure(['min_level' => $loggerLevels[$level] ?? Logger::INFO]);
    }
    return $level;
}

/**
 * Get log file path for a channel
 */
function getLogFile($channel = LOG_CHANNEL_DEFAULT) {
    return LOG_PATH . $channel . '.log';
}

/**
 * Log a message (legacy wrapper)
 */
function logMessage($level, $message, $context = [], $channel = LOG_CHANNEL_DEFAULT) {
    $levelMap = [
        'ERROR' => Logger::ERROR,
        'WARNING' => Logger::WARNING,
        'INFO' => Logger::INFO,
        'DEBUG' => Logger::DEBUG,
        'NOTICE' => Logger::NOTICE,
        'AUDIT' => Logger::INFO,
    ];

    $loggerLevel = $levelMap[strtoupper($level)] ?? Logger::INFO;
    Logger::getInstance()->log($loggerLevel, $message, $context, $channel);
}

/**
 * Log an error
 */
function logError($message, $context = []) {
    Logger::getInstance()->error($message, $context);
}

/**
 * Log a warning
 */
function logWarning($message, $context = []) {
    Logger::getInstance()->warning($message, $context);
}

/**
 * Log info
 */
function logInfo($message, $context = []) {
    Logger::getInstance()->info($message, $context);
}

/**
 * Log debug info
 */
function logDebug($message, $context = []) {
    Logger::getInstance()->debug($message, $context);
}

/**
 * Log an audit event
 */
function logAudit($action, $context = []) {
    // Add user info if available
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if ($user) {
            $context['user_id'] = $user['id'];
            $context['username'] = $user['username'];
        }
    }
    Logger::getInstance()->log(Logger::INFO, $action, $context, LOG_CHANNEL_AUDIT);
}

/**
 * Log authentication events
 */
function logAuth($message, $context = []) {
    Logger::getInstance()->log(Logger::INFO, $message, $context, LOG_CHANNEL_AUTH);
}

/**
 * Log upload events
 */
function logUpload($message, $context = []) {
    Logger::getInstance()->log(Logger::INFO, $message, $context, LOG_CHANNEL_UPLOAD);
}

/**
 * Log admin actions
 */
function logAdmin($message, $context = []) {
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if ($user) {
            $context['admin_id'] = $user['id'];
            $context['admin_username'] = $user['username'];
        }
    }
    Logger::getInstance()->log(Logger::INFO, $message, $context, LOG_CHANNEL_ADMIN);
}

/**
 * Log an exception with full details
 */
function logException($e, $context = []) {
    $context['exception'] = $e;
    Logger::getInstance()->error($e->getMessage(), $context);
}

/**
 * Set up PHP error handler to use our logger
 */
function setupErrorHandler() {
    set_error_handler(function($severity, $message, $file, $line) {
        // Map PHP error levels to log levels
        $levelMap = [
            E_ERROR => Logger::ERROR,
            E_WARNING => Logger::WARNING,
            E_NOTICE => Logger::NOTICE,
            E_USER_ERROR => Logger::ERROR,
            E_USER_WARNING => Logger::WARNING,
            E_USER_NOTICE => Logger::NOTICE,
            E_STRICT => Logger::NOTICE,
            E_DEPRECATED => Logger::NOTICE,
            E_USER_DEPRECATED => Logger::NOTICE,
        ];

        $level = $levelMap[$severity] ?? Logger::WARNING;
        Logger::getInstance()->log($level, $message, [
            'file' => $file,
            'line' => $line,
            'severity' => $severity
        ]);

        return false; // Continue with normal error handling
    });

    set_exception_handler(function($e) {
        Logger::getInstance()->exception($e);
        throw $e; // Re-throw to show error page
    });
}

/**
 * Get available log channels
 */
function getLogChannels() {
    return [
        LOG_CHANNEL_DEFAULT => 'Application',
        LOG_CHANNEL_AUTH => 'Authentication',
        LOG_CHANNEL_UPLOAD => 'File Uploads',
        LOG_CHANNEL_ADMIN => 'Admin Actions',
        LOG_CHANNEL_AUDIT => 'Security Audit'
    ];
}

/**
 * Read log file contents (for admin viewing)
 */
function readLog($channel = LOG_CHANNEL_DEFAULT, $lines = 100, $filter = null) {
    $logFile = getLogFile($channel);

    if (!file_exists($logFile)) {
        return [];
    }

    $content = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Filter if specified
    if ($filter) {
        $content = array_filter($content, function($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
    }

    // Get last N lines
    $content = array_slice($content, -$lines);

    // Parse into structured format
    $entries = [];
    foreach ($content as $line) {
        // Try new format first: [timestamp] [LEVEL] [request_id] message
        if (preg_match('/^\[([\d-T:\.+]+)\]\s\[(\w+)\]\s\[([a-f0-9-]+)\]\s(.+)$/', $line, $matches)) {
            $entry = [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'request_id' => $matches[3],
                'message' => $matches[4]
            ];
        }
        // Fall back to old format: [timestamp] [LEVEL] message
        elseif (preg_match('/^\[([\d-]+\s[\d:]+)\]\s\[(\w+)\]\s(.+)$/', $line, $matches)) {
            $entry = [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'request_id' => null,
                'message' => $matches[3]
            ];
        }
        // Try JSON format
        elseif ($line[0] === '{') {
            $decoded = json_decode($line, true);
            if ($decoded) {
                $entry = [
                    'timestamp' => $decoded['timestamp'] ?? '',
                    'level' => strtoupper($decoded['level'] ?? 'INFO'),
                    'request_id' => $decoded['request_id'] ?? null,
                    'message' => $decoded['message'] ?? '',
                    'context' => $decoded['context'] ?? null,
                    'http' => $decoded['http'] ?? null,
                    'user' => $decoded['user'] ?? null,
                ];
                $entries[] = $entry;
                continue;
            } else {
                continue; // Skip unparseable lines
            }
        } else {
            continue; // Skip unparseable lines
        }

        // Try to extract JSON context from message
        if (preg_match('/^(.+?)\s(\{.+\})$/', $entry['message'], $msgMatches)) {
            $entry['message'] = $msgMatches[1];
            $entry['context'] = json_decode($msgMatches[2], true);
        }

        $entries[] = $entry;
    }

    return array_reverse($entries); // Newest first
}

/**
 * Get log file statistics
 */
function getLogStats($channel = LOG_CHANNEL_DEFAULT) {
    $logFile = getLogFile($channel);

    if (!file_exists($logFile)) {
        return [
            'exists' => false,
            'size' => 0,
            'lines' => 0,
            'modified' => null
        ];
    }

    return [
        'exists' => true,
        'size' => filesize($logFile),
        'lines' => count(file($logFile)),
        'modified' => filemtime($logFile)
    ];
}

/**
 * Clear a log file
 */
function clearLog($channel = LOG_CHANNEL_DEFAULT) {
    $logFile = getLogFile($channel);

    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
        logAudit('Log cleared', ['channel' => $channel]);
        return true;
    }

    return false;
}

/**
 * Get all log files including rotated ones
 */
function getAllLogFiles() {
    initLogger();

    $files = [];
    $channels = getLogChannels();

    foreach ($channels as $channel => $name) {
        $mainFile = getLogFile($channel);

        if (file_exists($mainFile)) {
            $files[] = [
                'channel' => $channel,
                'name' => $name,
                'file' => basename($mainFile),
                'size' => filesize($mainFile),
                'modified' => filemtime($mainFile),
                'rotated' => false
            ];
        }

        // Find rotated files
        $pattern = LOG_PATH . $channel . '_*.log';
        foreach (glob($pattern) as $rotatedFile) {
            $files[] = [
                'channel' => $channel,
                'name' => $name . ' (archived)',
                'file' => basename($rotatedFile),
                'size' => filesize($rotatedFile),
                'modified' => filemtime($rotatedFile),
                'rotated' => true
            ];
        }
    }

    // Sort by modification time, newest first
    usort($files, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    return $files;
}

/**
 * Get the current request ID for log correlation
 */
function getRequestId() {
    return Logger::getInstance()->getRequestId();
}

/**
 * Rotate log file if too large (legacy, now handled automatically)
 */
function rotateLogIfNeeded($logFile) {
    // Now handled by Logger class automatically
}

/**
 * Clean up old rotated log files (legacy, now handled automatically)
 */
function cleanupOldLogs($dir, $baseName) {
    // Now handled by Logger class automatically
}
