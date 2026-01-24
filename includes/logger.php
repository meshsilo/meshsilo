<?php
/**
 * Logger Class
 *
 * Modern logging implementation with support for multiple channels,
 * log rotation, and structured logging.
 */

class Logger {
    // Log levels
    const ERROR = 1;
    const WARNING = 2;
    const INFO = 3;
    const DEBUG = 4;
    const NOTICE = 5;

    private static $instance = null;
    private $logPath;
    private $minLevel = self::INFO;
    private $requestId;
    private $maxFileSize = 5242880; // 5MB
    private $maxFiles = 10;

    private function __construct() {
        $this->logPath = __DIR__ . '/../storage/logs/';
        $this->requestId = $this->generateRequestId();

        // Create logs directory if it doesn't exist
        if (!file_exists($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
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
        return $this;
    }

    public function getRequestId() {
        return $this->requestId;
    }

    private function generateRequestId() {
        return substr(md5(uniqid() . microtime()), 0, 12);
    }

    public function log($level, $message, $context = [], $channel = 'app') {
        // Skip if below minimum level
        if ($level > $this->minLevel) {
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
            $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
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
            $stream = ($level <= self::WARNING) ? STDERR : STDOUT;
            fwrite($stream, "[{$channel}] {$logEntry}");
        }
    }

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

    private function rotateIfNeeded($logFile) {
        if (!file_exists($logFile)) {
            return;
        }

        $size = filesize($logFile);
        if ($size < $this->maxFileSize) {
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

        if (count($files) <= $this->maxFiles) {
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

// Helper functions for backward compatibility
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
