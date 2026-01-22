<?php
/**
 * PSR-3 Inspired Logger with Modern Features
 *
 * Improvements over the basic logger:
 * - Request ID for log correlation
 * - Buffered writes (optional)
 * - Sensitive data filtering
 * - Structured JSON output option
 * - Auto-captured context (URL, method, memory, timing)
 * - Log sampling for high-volume entries
 * - Stack trace formatting
 */

class Logger {
    // PSR-3 log levels
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    // Level priorities (lower = more severe)
    private static $levelPriority = [
        self::EMERGENCY => 0,
        self::ALERT     => 1,
        self::CRITICAL  => 2,
        self::ERROR     => 3,
        self::WARNING   => 4,
        self::NOTICE    => 5,
        self::INFO      => 6,
        self::DEBUG     => 7,
    ];

    // Singleton instance
    private static $instance = null;

    // Configuration
    private $logPath;
    private $minLevel = self::INFO;
    private $useBuffer = true;
    private $useJsonFormat = false;
    private $maxFileSize = 10 * 1024 * 1024; // 10MB
    private $maxFiles = 10;

    // Runtime state
    private $requestId;
    private $requestStart;
    private $buffer = [];
    private $sensitiveKeys = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'auth', 'credential', 'private_key', 'privatekey',
        'access_token', 'refresh_token', 'session_id', 'sessionid', 'csrf'
    ];

    // Sampling rates for high-volume log types (0.0 to 1.0)
    private $samplingRates = [];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->logPath = dirname(__DIR__) . '/logs/';
        $this->requestId = $this->generateRequestId();
        $this->requestStart = microtime(true);

        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        // Register shutdown handler to flush buffer
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * Generate unique request ID
     */
    private function generateRequestId(): string {
        // Use existing request ID header if present (for distributed tracing)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach (['X-Request-ID', 'X-Correlation-ID', 'X-Trace-ID'] as $header) {
            if (!empty($headers[$header])) {
                return substr($headers[$header], 0, 36);
            }
        }

        // Generate new ID: timestamp + random
        return sprintf('%08x-%04x-%04x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Get the current request ID
     */
    public function getRequestId(): string {
        return $this->requestId;
    }

    /**
     * Configure the logger
     */
    public function configure(array $options): self {
        if (isset($options['min_level'])) {
            $this->minLevel = $options['min_level'];
        }
        if (isset($options['use_buffer'])) {
            $this->useBuffer = (bool)$options['use_buffer'];
        }
        if (isset($options['json_format'])) {
            $this->useJsonFormat = (bool)$options['json_format'];
        }
        if (isset($options['max_file_size'])) {
            $this->maxFileSize = (int)$options['max_file_size'];
        }
        if (isset($options['sensitive_keys'])) {
            $this->sensitiveKeys = array_merge($this->sensitiveKeys, $options['sensitive_keys']);
        }
        if (isset($options['sampling'])) {
            $this->samplingRates = $options['sampling'];
        }
        return $this;
    }

    /**
     * Set sampling rate for a log category
     */
    public function setSampling(string $category, float $rate): self {
        $this->samplingRates[$category] = max(0.0, min(1.0, $rate));
        return $this;
    }

    /**
     * Check if this log should be sampled (written)
     */
    private function shouldSample(string $category): bool {
        if (!isset($this->samplingRates[$category])) {
            return true; // No sampling configured
        }
        return (mt_rand() / mt_getrandmax()) <= $this->samplingRates[$category];
    }

    /**
     * Log a message
     */
    public function log(string $level, string $message, array $context = [], string $channel = 'app'): void {
        // Check level
        if (!$this->shouldLog($level)) {
            return;
        }

        // Check sampling
        $category = $context['_category'] ?? $channel;
        unset($context['_category']);
        if (!$this->shouldSample($category)) {
            return;
        }

        // Build log entry
        $entry = $this->buildEntry($level, $message, $context, $channel);

        // Buffer or write immediately
        if ($this->useBuffer && $level !== self::EMERGENCY && $level !== self::CRITICAL) {
            $this->buffer[$channel][] = $entry;
        } else {
            $this->writeEntry($entry, $channel);
        }
    }

    /**
     * Check if level should be logged
     */
    private function shouldLog(string $level): bool {
        $levelPriority = self::$levelPriority[$level] ?? 6;
        $minPriority = self::$levelPriority[$this->minLevel] ?? 6;
        return $levelPriority <= $minPriority;
    }

    /**
     * Build log entry array
     */
    private function buildEntry(string $level, string $message, array $context, string $channel): array {
        $entry = [
            'timestamp' => date('Y-m-d\TH:i:s.uP'),
            'level' => $level,
            'channel' => $channel,
            'request_id' => $this->requestId,
            'message' => $message,
        ];

        // Add auto-captured context
        if (php_sapi_name() !== 'cli') {
            $entry['http'] = [
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'url' => $_SERVER['REQUEST_URI'] ?? null,
                'ip' => $this->getClientIp(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            ];
        }

        // Add user context if available
        if (function_exists('getCurrentUser') && function_exists('isLoggedIn') && isLoggedIn()) {
            $user = getCurrentUser();
            if ($user) {
                $entry['user'] = [
                    'id' => $user['id'] ?? null,
                    'username' => $user['username'] ?? null,
                ];
            }
        }

        // Add memory and timing for errors
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR])) {
            $entry['runtime'] = [
                'memory_bytes' => memory_get_usage(true),
                'memory_peak_bytes' => memory_get_peak_usage(true),
                'elapsed_ms' => round((microtime(true) - $this->requestStart) * 1000, 2),
            ];
        }

        // Filter and add custom context
        if (!empty($context)) {
            $entry['context'] = $this->filterSensitive($context);
        }

        return $entry;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): ?string {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /**
     * Filter sensitive data from context
     */
    private function filterSensitive(array $data, int $depth = 0): array {
        if ($depth > 5) {
            return ['_truncated' => 'max depth exceeded'];
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);

            // Check if key is sensitive
            $isSensitive = false;
            foreach ($this->sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $filtered[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $filtered[$key] = $this->filterSensitive($value, $depth + 1);
            } elseif (is_object($value)) {
                if ($value instanceof \Throwable) {
                    $filtered[$key] = $this->formatException($value);
                } elseif (method_exists($value, '__toString')) {
                    $filtered[$key] = (string)$value;
                } else {
                    $filtered[$key] = '[object ' . get_class($value) . ']';
                }
            } elseif (is_string($value) && strlen($value) > 1000) {
                $filtered[$key] = substr($value, 0, 1000) . '... [truncated]';
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Format exception for logging
     */
    private function formatException(\Throwable $e): array {
        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->formatTrace($e->getTrace()),
        ];
    }

    /**
     * Format stack trace (limit to key frames)
     */
    private function formatTrace(array $trace): array {
        $formatted = [];
        $count = 0;
        foreach ($trace as $frame) {
            if ($count >= 10) {
                $formatted[] = '... ' . (count($trace) - 10) . ' more frames';
                break;
            }

            $location = '';
            if (isset($frame['file'])) {
                $location = basename($frame['file']) . ':' . ($frame['line'] ?? '?');
            }

            $call = '';
            if (isset($frame['class'])) {
                $call = $frame['class'] . ($frame['type'] ?? '::') . $frame['function'];
            } elseif (isset($frame['function'])) {
                $call = $frame['function'];
            }

            $formatted[] = "$location $call()";
            $count++;
        }
        return $formatted;
    }

    /**
     * Write a single entry to file
     */
    private function writeEntry(array $entry, string $channel): void {
        $file = $this->logPath . $channel . '.log';
        $this->rotateIfNeeded($file);

        if ($this->useJsonFormat) {
            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            // Human-readable format
            $line = sprintf(
                "[%s] [%s] [%s] %s%s\n",
                $entry['timestamp'],
                strtoupper($entry['level']),
                $entry['request_id'],
                $entry['message'],
                !empty($entry['context']) ? ' ' . json_encode($entry['context'], JSON_UNESCAPED_SLASHES) : ''
            );
        }

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate log file if too large
     */
    private function rotateIfNeeded(string $file): void {
        if (!file_exists($file) || filesize($file) < $this->maxFileSize) {
            return;
        }

        $info = pathinfo($file);
        $rotated = $info['dirname'] . '/' . $info['filename'] . '_' . date('Y-m-d_His') . '.log';
        rename($file, $rotated);

        // Cleanup old files
        $pattern = $info['dirname'] . '/' . $info['filename'] . '_*.log';
        $files = glob($pattern);
        if (count($files) > $this->maxFiles) {
            usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
            foreach (array_slice($files, 0, count($files) - $this->maxFiles) as $old) {
                @unlink($old);
            }
        }
    }

    /**
     * Flush buffer to files
     */
    public function flush(): void {
        foreach ($this->buffer as $channel => $entries) {
            $file = $this->logPath . $channel . '.log';
            $this->rotateIfNeeded($file);

            $lines = '';
            foreach ($entries as $entry) {
                if ($this->useJsonFormat) {
                    $lines .= json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
                } else {
                    $lines .= sprintf(
                        "[%s] [%s] [%s] %s%s\n",
                        $entry['timestamp'],
                        strtoupper($entry['level']),
                        $entry['request_id'],
                        $entry['message'],
                        !empty($entry['context']) ? ' ' . json_encode($entry['context'], JSON_UNESCAPED_SLASHES) : ''
                    );
                }
            }

            if ($lines) {
                file_put_contents($file, $lines, FILE_APPEND | LOCK_EX);
            }
        }

        $this->buffer = [];
    }

    // Convenience methods
    public function emergency(string $message, array $context = []): void {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void {
        $this->log(self::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Log an exception
     */
    public function exception(\Throwable $e, array $context = []): void {
        $context['exception'] = $e;
        $this->error($e->getMessage(), $context);
    }
}

// Global helper functions for backward compatibility
function logger(): Logger {
    return Logger::getInstance();
}

function log_error(string $message, array $context = []): void {
    Logger::getInstance()->error($message, $context);
}

function log_warning(string $message, array $context = []): void {
    Logger::getInstance()->warning($message, $context);
}

function log_info(string $message, array $context = []): void {
    Logger::getInstance()->info($message, $context);
}

function log_debug(string $message, array $context = []): void {
    Logger::getInstance()->debug($message, $context);
}
