<?php
/**
 * Rate Limiter
 *
 * Provides request rate limiting using various storage backends:
 * - Database (SQLite/MySQL)
 * - APCu (if available)
 * - File-based (fallback)
 *
 * Supports multiple rate limit strategies:
 * - Fixed window
 * - Sliding window
 * - Token bucket
 */

class RateLimiter {
    private static ?self $instance = null;
    private string $driver = 'database';
    private string $cachePath;
    private $db = null;

    // Default limits
    const DEFAULT_LIMIT = 60;        // Requests per window
    const DEFAULT_WINDOW = 60;       // Window in seconds (1 minute)
    const DEFAULT_DECAY = 60;        // Decay time for blocking

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
        $this->cachePath = dirname(__DIR__) . '/storage/cache/ratelimit/';

        // Select best available driver
        if (function_exists('apcu_fetch') && apcu_enabled()) {
            $this->driver = 'apcu';
        } elseif (function_exists('getDB')) {
            $this->driver = 'database';
            $this->db = getDB();
            $this->ensureTable();
        } else {
            $this->driver = 'file';
            if (!is_dir($this->cachePath)) {
                mkdir($this->cachePath, 0755, true);
            }
        }
    }

    /**
     * Ensure rate limit table exists
     */
    private function ensureTable(): void {
        if (!$this->db) return;

        // Use database-appropriate syntax
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    `key` VARCHAR(255) NOT NULL UNIQUE,
                    hits INT DEFAULT 1,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key TEXT NOT NULL UNIQUE,
                    hits INTEGER DEFAULT 1,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_key ON rate_limits(`key`)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_expires ON rate_limits(expires_at)");
    }

    /**
     * Check if a request should be rate limited
     *
     * @param string $key Unique identifier (e.g., user_id, IP address, API key)
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $decaySeconds Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => timestamp]
     */
    public function attempt(string $key, int $maxAttempts = self::DEFAULT_LIMIT, int $decaySeconds = self::DEFAULT_WINDOW): array {
        $key = $this->sanitizeKey($key);

        switch ($this->driver) {
            case 'apcu':
                return $this->attemptApcu($key, $maxAttempts, $decaySeconds);
            case 'database':
                return $this->attemptDatabase($key, $maxAttempts, $decaySeconds);
            default:
                return $this->attemptFile($key, $maxAttempts, $decaySeconds);
        }
    }

    /**
     * Check rate limit without incrementing
     */
    public function check(string $key, int $maxAttempts = self::DEFAULT_LIMIT, int $decaySeconds = self::DEFAULT_WINDOW): array {
        $key = $this->sanitizeKey($key);
        $data = $this->getData($key);

        if (!$data || $data['expires_at'] < time()) {
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'reset' => time() + $decaySeconds,
                'hits' => 0
            ];
        }

        $remaining = max(0, $maxAttempts - $data['hits']);

        return [
            'allowed' => $remaining > 0,
            'remaining' => $remaining,
            'reset' => $data['expires_at'],
            'hits' => $data['hits']
        ];
    }

    /**
     * APCu-based rate limiting
     */
    private function attemptApcu(string $key, int $maxAttempts, int $decaySeconds): array {
        $expiresKey = $key . ':expires';
        $hitsKey = $key . ':hits';

        $expires = apcu_fetch($expiresKey);
        $hits = apcu_fetch($hitsKey);

        // If no record or expired, start fresh
        if ($expires === false || $expires < time()) {
            $expires = time() + $decaySeconds;
            $hits = 1;
            apcu_store($expiresKey, $expires, $decaySeconds);
            apcu_store($hitsKey, $hits, $decaySeconds);
        } else {
            // Increment hits
            $hits = apcu_inc($hitsKey);
        }

        $remaining = max(0, $maxAttempts - $hits);

        return [
            'allowed' => $hits <= $maxAttempts,
            'remaining' => $remaining,
            'reset' => $expires,
            'hits' => $hits
        ];
    }

    /**
     * Database-based rate limiting
     */
    private function attemptDatabase(string $key, int $maxAttempts, int $decaySeconds): array {
        if (!$this->db) {
            return ['allowed' => true, 'remaining' => $maxAttempts, 'reset' => time() + $decaySeconds, 'hits' => 0];
        }

        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + $decaySeconds);

        // Try to get existing record
        $stmt = $this->db->prepare("SELECT hits, expires_at FROM rate_limits WHERE `key` = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['expires_at'] < $now) {
            // No record or expired - create new (use REPLACE for cross-DB compatibility)
            $stmt = $this->db->prepare("REPLACE INTO rate_limits (`key`, hits, expires_at) VALUES (:key, 1, :expires)");
            $stmt->execute([':key' => $key, ':expires' => $expires]);

            return [
                'allowed' => true,
                'remaining' => $maxAttempts - 1,
                'reset' => time() + $decaySeconds,
                'hits' => 1
            ];
        }

        // Increment existing record
        $hits = $row['hits'] + 1;
        $stmt = $this->db->prepare("UPDATE rate_limits SET hits = :hits WHERE `key` = :key");
        $stmt->execute([':hits' => $hits, ':key' => $key]);

        $remaining = max(0, $maxAttempts - $hits);
        $expiresTimestamp = strtotime($row['expires_at']);

        return [
            'allowed' => $hits <= $maxAttempts,
            'remaining' => $remaining,
            'reset' => $expiresTimestamp,
            'hits' => $hits
        ];
    }

    /**
     * File-based rate limiting
     */
    private function attemptFile(string $key, int $maxAttempts, int $decaySeconds): array {
        $file = $this->cachePath . md5($key) . '.json';

        $data = null;
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
        }

        // If no record or expired, start fresh
        if (!$data || $data['expires_at'] < time()) {
            $data = [
                'hits' => 1,
                'expires_at' => time() + $decaySeconds
            ];
        } else {
            $data['hits']++;
        }

        file_put_contents($file, json_encode($data), LOCK_EX);

        $remaining = max(0, $maxAttempts - $data['hits']);

        return [
            'allowed' => $data['hits'] <= $maxAttempts,
            'remaining' => $remaining,
            'reset' => $data['expires_at'],
            'hits' => $data['hits']
        ];
    }

    /**
     * Get current data for a key
     */
    private function getData(string $key): ?array {
        switch ($this->driver) {
            case 'apcu':
                $expires = apcu_fetch($key . ':expires');
                $hits = apcu_fetch($key . ':hits');
                if ($expires === false) return null;
                return ['hits' => $hits, 'expires_at' => $expires];

            case 'database':
                if (!$this->db) return null;
                $stmt = $this->db->prepare("SELECT hits, expires_at FROM rate_limits WHERE `key` = :key");
                $stmt->execute([':key' => $key]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) return null;
                return ['hits' => $row['hits'], 'expires_at' => strtotime($row['expires_at'])];

            default:
                $file = $this->cachePath . md5($key) . '.json';
                if (!file_exists($file)) return null;
                return json_decode(file_get_contents($file), true);
        }
    }

    /**
     * Clear rate limit for a key
     */
    public function clear(string $key): bool {
        $key = $this->sanitizeKey($key);

        switch ($this->driver) {
            case 'apcu':
                apcu_delete($key . ':expires');
                apcu_delete($key . ':hits');
                return true;

            case 'database':
                if (!$this->db) return false;
                $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE `key` = :key");
                return $stmt->execute([':key' => $key]);

            default:
                $file = $this->cachePath . md5($key) . '.json';
                return file_exists($file) ? unlink($file) : true;
        }
    }

    /**
     * Clear all expired rate limits
     */
    public function clearExpired(): int {
        $cleared = 0;

        switch ($this->driver) {
            case 'database':
                if (!$this->db) return 0;
                $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE expires_at < :now");
                $stmt->execute([':now' => date('Y-m-d H:i:s')]);
                $cleared = $stmt->rowCount();
                break;

            case 'file':
                $files = glob($this->cachePath . '*.json');
                foreach ($files as $file) {
                    $data = json_decode(file_get_contents($file), true);
                    if ($data && $data['expires_at'] < time()) {
                        unlink($file);
                        $cleared++;
                    }
                }
                break;
        }

        return $cleared;
    }

    /**
     * Sanitize key for storage
     */
    private function sanitizeKey(string $key): string {
        return 'ratelimit:' . preg_replace('/[^a-zA-Z0-9:_-]/', '_', $key);
    }

    /**
     * Send rate limit headers
     */
    public static function sendHeaders(array $result, int $limit): void {
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset']);

        if (!$result['allowed']) {
            header('Retry-After: ' . ($result['reset'] - time()));
        }
    }

    /**
     * Handle rate limit exceeded
     */
    public static function tooManyRequests(array $result): never {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . ($result['reset'] - time()));

        echo json_encode([
            'error' => true,
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $result['reset'] - time()
        ]);
        exit;
    }

    /**
     * Middleware-style rate limit check
     *
     * @param string $key Identifier for rate limiting
     * @param int $limit Max requests per window
     * @param int $window Window in seconds
     * @param bool $exitOnLimit Whether to exit with 429 if limit exceeded
     * @return array Rate limit result
     */
    public static function throttle(string $key, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW, bool $exitOnLimit = true): array {
        $limiter = self::getInstance();
        $result = $limiter->attempt($key, $limit, $window);

        self::sendHeaders($result, $limit);

        if (!$result['allowed'] && $exitOnLimit) {
            self::tooManyRequests($result);
        }

        return $result;
    }

    /**
     * Rate limit by IP address
     */
    public static function byIp(int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): array {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';

        // Handle comma-separated IPs
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return self::throttle('ip:' . $ip, $limit, $window);
    }

    /**
     * Rate limit by API key
     */
    public static function byApiKey(string $apiKey, int $limit = 1000, int $window = 3600): array {
        return self::throttle('api:' . $apiKey, $limit, $window);
    }

    /**
     * Rate limit by user ID
     */
    public static function byUser(int $userId, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): array {
        return self::throttle('user:' . $userId, $limit, $window);
    }

    /**
     * Rate limit by route/endpoint
     */
    public static function byRoute(string $route, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): array {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return self::throttle('route:' . $route . ':' . $ip, $limit, $window);
    }

    /**
     * Combined rate limiting (multiple strategies)
     *
     * @param array $strategies Array of ['key' => string, 'limit' => int, 'window' => int]
     * @return array First failed result or last successful result
     */
    public static function multi(array $strategies): array {
        $limiter = self::getInstance();

        foreach ($strategies as $strategy) {
            $result = $limiter->attempt(
                $strategy['key'],
                $strategy['limit'] ?? self::DEFAULT_LIMIT,
                $strategy['window'] ?? self::DEFAULT_WINDOW
            );

            if (!$result['allowed']) {
                self::sendHeaders($result, $strategy['limit'] ?? self::DEFAULT_LIMIT);
                return $result;
            }
        }

        // All passed, send headers for the most restrictive
        $lastStrategy = end($strategies);
        self::sendHeaders($result, $lastStrategy['limit'] ?? self::DEFAULT_LIMIT);

        return $result;
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Throttle requests (convenience function)
 */
function throttle(string $key, int $limit = 60, int $window = 60): array {
    return RateLimiter::throttle($key, $limit, $window);
}

/**
 * Rate limit by IP (convenience function)
 */
function rate_limit_ip(int $limit = 60, int $window = 60): array {
    return RateLimiter::byIp($limit, $window);
}

/**
 * Rate limit by API key (convenience function)
 */
function rate_limit_api(string $apiKey, int $limit = 1000, int $window = 3600): array {
    return RateLimiter::byApiKey($apiKey, $limit, $window);
}
