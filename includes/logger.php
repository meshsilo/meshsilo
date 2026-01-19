<?php
/**
 * Enhanced Logging System
 * Provides comprehensive logging with multiple channels, levels, and admin features
 */

// Log configuration
define('LOG_PATH', __DIR__ . '/../logs/');
define('LOG_MAX_SIZE', 5 * 1024 * 1024); // 5MB max log size
define('LOG_MAX_FILES', 10); // Keep last 10 rotated logs

// Log levels (lower number = more severe)
define('LOG_LEVEL_ERROR', 1);
define('LOG_LEVEL_WARNING', 2);
define('LOG_LEVEL_INFO', 3);
define('LOG_LEVEL_DEBUG', 4);
define('LOG_LEVEL_AUDIT', 5);

// Log channels
define('LOG_CHANNEL_DEFAULT', 'error');
define('LOG_CHANNEL_AUTH', 'auth');
define('LOG_CHANNEL_UPLOAD', 'upload');
define('LOG_CHANNEL_ADMIN', 'admin');
define('LOG_CHANNEL_AUDIT', 'audit');

/**
 * Initialize logging - create logs directory if needed
 */
function initLogger() {
    if (!file_exists(LOG_PATH)) {
        mkdir(LOG_PATH, 0755, true);
    }
}

/**
 * Get the configured minimum log level
 */
function getLogLevel() {
    static $level = null;
    if ($level === null) {
        // Try to get from settings, default to INFO
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
 * Rotate log file if too large
 */
function rotateLogIfNeeded($logFile) {
    if (file_exists($logFile) && filesize($logFile) > LOG_MAX_SIZE) {
        $pathInfo = pathinfo($logFile);
        $backupFile = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . date('Y-m-d_H-i-s') . '.log';
        rename($logFile, $backupFile);

        // Clean up old rotated logs
        cleanupOldLogs($pathInfo['dirname'], $pathInfo['filename']);
    }
}

/**
 * Clean up old rotated log files, keeping only LOG_MAX_FILES
 */
function cleanupOldLogs($dir, $baseName) {
    $pattern = $dir . '/' . $baseName . '_*.log';
    $files = glob($pattern);

    if (count($files) > LOG_MAX_FILES) {
        // Sort by modification time, oldest first
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Delete oldest files
        $toDelete = array_slice($files, 0, count($files) - LOG_MAX_FILES);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
}

/**
 * Log a message to a specific channel
 *
 * @param string $level Log level (ERROR, WARNING, INFO, DEBUG, AUDIT)
 * @param string $message The log message
 * @param array $context Additional context data
 * @param string $channel Log channel
 */
function logMessage($level, $message, $context = [], $channel = LOG_CHANNEL_DEFAULT) {
    initLogger();

    // Check if this level should be logged
    $levelNum = [
        'ERROR' => LOG_LEVEL_ERROR,
        'WARNING' => LOG_LEVEL_WARNING,
        'INFO' => LOG_LEVEL_INFO,
        'DEBUG' => LOG_LEVEL_DEBUG,
        'AUDIT' => LOG_LEVEL_AUDIT
    ][$level] ?? LOG_LEVEL_INFO;

    // Always log AUDIT messages, otherwise check level
    if ($level !== 'AUDIT' && $levelNum > getLogLevel()) {
        return;
    }

    $logFile = getLogFile($channel);
    rotateLogIfNeeded($logFile);

    $timestamp = date('Y-m-d H:i:s');

    // Add request info for non-CLI
    if (php_sapi_name() !== 'cli' && !isset($context['ip'])) {
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $logEntry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Log an error
 */
function logError($message, $context = []) {
    logMessage('ERROR', $message, $context);
}

/**
 * Log a warning
 */
function logWarning($message, $context = []) {
    logMessage('WARNING', $message, $context);
}

/**
 * Log info
 */
function logInfo($message, $context = []) {
    logMessage('INFO', $message, $context);
}

/**
 * Log debug info
 */
function logDebug($message, $context = []) {
    logMessage('DEBUG', $message, $context);
}

/**
 * Log an audit event (always logged, security/compliance related)
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
    logMessage('AUDIT', $action, $context, LOG_CHANNEL_AUDIT);
}

/**
 * Log authentication events
 */
function logAuth($message, $context = []) {
    logMessage('INFO', $message, $context, LOG_CHANNEL_AUTH);
}

/**
 * Log upload events
 */
function logUpload($message, $context = []) {
    logMessage('INFO', $message, $context, LOG_CHANNEL_UPLOAD);
}

/**
 * Log admin actions
 */
function logAdmin($message, $context = []) {
    // Add user info
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if ($user) {
            $context['admin_id'] = $user['id'];
            $context['admin_username'] = $user['username'];
        }
    }
    logMessage('INFO', $message, $context, LOG_CHANNEL_ADMIN);
}

/**
 * Log an exception with full details
 */
function logException($e, $context = []) {
    $context['exception'] = get_class($e);
    $context['file'] = $e->getFile();
    $context['line'] = $e->getLine();
    $context['trace'] = $e->getTraceAsString();
    logError($e->getMessage(), $context);
}

/**
 * Set up PHP error handler to use our logger
 */
function setupErrorHandler() {
    set_error_handler(function($severity, $message, $file, $line) {
        $levels = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_NOTICE => 'NOTICE',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
        ];
        $level = $levels[$severity] ?? 'UNKNOWN';
        logMessage($level, $message, ['file' => $file, 'line' => $line]);
        return false; // Continue with normal error handling
    });

    set_exception_handler(function($e) {
        logException($e);
        throw $e; // Re-throw to show error page
    });
}

/**
 * Get available log channels
 */
function getLogChannels() {
    return [
        LOG_CHANNEL_DEFAULT => 'General Errors',
        LOG_CHANNEL_AUTH => 'Authentication',
        LOG_CHANNEL_UPLOAD => 'File Uploads',
        LOG_CHANNEL_ADMIN => 'Admin Actions',
        LOG_CHANNEL_AUDIT => 'Security Audit'
    ];
}

/**
 * Read log file contents (for admin viewing)
 *
 * @param string $channel Log channel to read
 * @param int $lines Number of lines to return (from end)
 * @param string $filter Optional filter string
 * @return array Array of log entries
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
        if (preg_match('/^\[([\d-]+\s[\d:]+)\]\s\[(\w+)\]\s(.+)$/', $line, $matches)) {
            $entry = [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => $matches[3]
            ];

            // Try to extract JSON context
            if (preg_match('/^(.+?)\s(\{.+\})$/', $entry['message'], $msgMatches)) {
                $entry['message'] = $msgMatches[1];
                $entry['context'] = json_decode($msgMatches[2], true);
            }

            $entries[] = $entry;
        }
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
