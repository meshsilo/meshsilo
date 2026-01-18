<?php
// Error Logging Configuration
define('LOG_PATH', __DIR__ . '/../logs/');
define('LOG_FILE', LOG_PATH . 'error.log');
define('LOG_MAX_SIZE', 5 * 1024 * 1024); // 5MB max log size

/**
 * Initialize logging - create logs directory if needed
 */
function initLogger() {
    if (!file_exists(LOG_PATH)) {
        mkdir(LOG_PATH, 0755, true);
    }

    // Rotate log if too large
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
        $backupFile = LOG_PATH . 'error_' . date('Y-m-d_H-i-s') . '.log';
        rename(LOG_FILE, $backupFile);
    }
}

/**
 * Log an error message
 *
 * @param string $level Log level (ERROR, WARNING, INFO, DEBUG)
 * @param string $message The error message
 * @param array $context Additional context data
 */
function logMessage($level, $message, $context = []) {
    initLogger();

    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $logEntry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;

    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
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
