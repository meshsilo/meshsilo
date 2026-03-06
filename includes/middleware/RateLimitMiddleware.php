<?php
/**
 * Rate Limiting Middleware
 *
 * Limits the number of requests per time window to prevent abuse.
 * Supports different limits for different route groups.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class RateLimitMiddleware implements MiddlewareInterface {
    private int $maxRequests;
    private int $windowSeconds;
    private string $prefix;

    // Default limits
    private const DEFAULT_MAX_REQUESTS = 60;
    private const DEFAULT_WINDOW_SECONDS = 60;

    // Storage directory for rate limit data
    private const STORAGE_DIR = __DIR__ . '/../../cache/ratelimit';

    // Flag to track if database storage failed (use file fallback)
    private static bool $databaseFailed = false;

    /**
     * Create rate limit middleware
     *
     * @param int $maxRequests Maximum requests allowed in the window
     * @param int $windowSeconds Time window in seconds
     * @param string $prefix Prefix for rate limit key (allows different limits per route group)
     */
    public function __construct(
        int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        string $prefix = 'default'
    ) {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->prefix = $prefix;
    }

    /**
     * Handle the middleware
     */
    public function handle(array $params): bool {
        // Check if rate limiting is enabled
        if (function_exists('getSetting') && getSetting('rate_limiting', '1') !== '1') {
            return true;
        }

        // Get identifier (IP or user ID)
        $identifier = $this->getIdentifier();

        // Get current request count
        $key = $this->prefix . ':' . $identifier;
        $data = $this->getRateLimitData($key);

        $now = time();
        $windowStart = $now - $this->windowSeconds;

        // Filter out old requests
        $requests = array_filter($data['requests'] ?? [], function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        // Check if over limit
        if (count($requests) >= $this->maxRequests) {
            $this->sendRateLimitResponse($requests, $now);
            return false;
        }

        // Add current request
        $requests[] = $now;

        // Save updated data
        $this->saveRateLimitData($key, ['requests' => $requests]);

        // Add rate limit headers
        $this->addRateLimitHeaders(count($requests));

        return true;
    }

    /**
     * Get unique identifier for the requester
     */
    private function getIdentifier(): string {
        // Prefer user ID if logged in
        if (function_exists('isLoggedIn') && isLoggedIn()) {
            $user = getCurrentUser();
            return 'user:' . ($user['id'] ?? 'unknown');
        }

        // Fall back to IP address - only trust proxy headers if TRUSTED_PROXIES is configured
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : (getenv('TRUSTED_PROXIES') ?: '');
        if ($trustedProxies && in_array($ip, array_map('trim', explode(',', $trustedProxies)))) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $ip;
            if (strpos($forwarded, ',') !== false) {
                $forwarded = trim(explode(',', $forwarded)[0]);
            }
            $ip = $forwarded;
        }

        return 'ip:' . $ip;
    }

    /**
     * Get rate limit data from storage
     */
    private function getRateLimitData(string $key): array {
        // Try database first if available
        if ($this->useDatabase()) {
            return $this->getDatabaseData($key);
        }

        // Fall back to file storage
        return $this->getFileData($key);
    }

    /**
     * Save rate limit data to storage
     */
    private function saveRateLimitData(string $key, array $data): void {
        if ($this->useDatabase()) {
            $this->saveDatabaseData($key, $data);
        } else {
            $this->saveFileData($key, $data);
        }
    }

    /**
     * Check if we should use database storage
     */
    private function useDatabase(): bool {
        // If database storage already failed this request, use file storage
        if (self::$databaseFailed) {
            return false;
        }

        return function_exists('getDB') && function_exists('getSetting')
            && getSetting('rate_limit_storage', 'file') === 'database';
    }

    /**
     * Get data from database
     */
    private function getDatabaseData(string $key): array {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT data, expires_at FROM rate_limits WHERE key_name = :key');
            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $result = $stmt->execute();
            $row = $result->fetchArray(PDO::FETCH_ASSOC);

            if ($row && $row['expires_at'] > time()) {
                return json_decode($row['data'], true) ?: [];
            }
        } catch (Exception $e) {
            // Schema mismatch or other database error - fall back to file storage
            self::$databaseFailed = true;
            return $this->getFileData($key);
        }

        return [];
    }

    /**
     * Save data to database
     */
    private function saveDatabaseData(string $key, array $data): void {
        try {
            $db = getDB();
            $expiresAt = time() + $this->windowSeconds + 60; // Add buffer
            $jsonData = json_encode($data);

            if ($db->getType() === 'mysql') {
                $stmt = $db->prepare('
                    INSERT INTO rate_limits (key_name, data, expires_at)
                    VALUES (:key, :data, :expires)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)
                ');
            } else {
                $stmt = $db->prepare('
                    INSERT OR REPLACE INTO rate_limits (key_name, data, expires_at)
                    VALUES (:key, :data, :expires)
                ');
            }
            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $stmt->bindValue(':data', $jsonData, PDO::PARAM_STR);
            $stmt->bindValue(':expires', $expiresAt, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            // Schema mismatch or other database error - fall back to file storage
            self::$databaseFailed = true;
            $this->saveFileData($key, $data);
        }
    }

    /**
     * Get data from file storage
     */
    private function getFileData(string $key): array {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true);

        return $data ?: [];
    }

    /**
     * Save data to file storage
     */
    private function saveFileData(string $key, array $data): void {
        $file = $this->getFilePath($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Get file path for a key
     */
    private function getFilePath(string $key): string {
        $hash = md5($key);
        $subdir = substr($hash, 0, 2);
        return self::STORAGE_DIR . '/' . $subdir . '/' . $hash . '.json';
    }

    /**
     * Send rate limit exceeded response
     */
    private function sendRateLimitResponse(array $requests, int $now): void {
        $oldestRequest = min($requests);
        $retryAfter = $oldestRequest + $this->windowSeconds - $now;

        http_response_code(429);
        header('Retry-After: ' . max(1, $retryAfter));
        header('X-RateLimit-Limit: ' . $this->maxRequests);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . ($oldestRequest + $this->windowSeconds));

        // Check if this is an API/AJAX request
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Rate limit exceeded',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => max(1, $retryAfter)
            ]);
        } else {
            // HTML response
            ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Limit Exceeded</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
            margin: 0;
            padding: 2rem;
        }
        .container {
            text-align: center;
            background: white;
            padding: 3rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
        }
        h1 { color: #e74c3c; margin-bottom: 1rem; }
        p { color: #666; line-height: 1.6; }
        .retry { margin-top: 1.5rem; padding: 1rem; background: #f8f9fa; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Too Many Requests</h1>
        <p>You've made too many requests in a short period of time. Please slow down and try again.</p>
        <div class="retry">
            <strong>Retry in:</strong> <?= max(1, $retryAfter) ?> seconds
        </div>
    </div>
</body>
</html>
            <?php
        }

        exit;
    }

    /**
     * Add rate limit headers to response
     */
    private function addRateLimitHeaders(int $currentCount): void {
        $remaining = max(0, $this->maxRequests - $currentCount);

        header('X-RateLimit-Limit: ' . $this->maxRequests);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . (time() + $this->windowSeconds));
    }

    /**
     * Clean up expired rate limit data (call periodically)
     */
    public static function cleanup(): int {
        $cleaned = 0;

        // Clean file storage
        $dir = self::STORAGE_DIR;
        if (is_dir($dir)) {
            $now = time();
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getMTime() < $now - 3600) {
                    unlink($file->getPathname());
                    $cleaned++;
                }
            }

            // Remove empty subdirectories
            foreach (glob($dir . '/*', GLOB_ONLYDIR) as $subdir) {
                if (count(glob($subdir . '/*')) === 0) {
                    rmdir($subdir);
                }
            }
        }

        // Clean database storage (only if schema is correct)
        if (function_exists('getDB') && !self::$databaseFailed) {
            try {
                $db = getDB();
                // Check if the table has the expected schema before cleaning
                $stmt = $db->prepare('SELECT 1 FROM rate_limits WHERE key_name IS NOT NULL AND expires_at < :time LIMIT 1');
                $stmt->bindValue(':time', time(), PDO::PARAM_INT);
                $stmt->execute();
                // If that worked, do the actual cleanup
                $db->exec('DELETE FROM rate_limits WHERE expires_at < ' . time());
            } catch (Exception $e) {
                // Schema mismatch or table doesn't exist - skip database cleanup
                self::$databaseFailed = true;
            }
        }

        return $cleaned;
    }
}

/**
 * Preset rate limits for common use cases
 */
class RateLimits {
    /**
     * Standard rate limit (60 requests/minute)
     */
    public static function standard(): RateLimitMiddleware {
        return new RateLimitMiddleware(60, 60, 'standard');
    }

    /**
     * API rate limit (100 requests/minute)
     */
    public static function api(): RateLimitMiddleware {
        return new RateLimitMiddleware(100, 60, 'api');
    }

    /**
     * Auth rate limit (5 attempts/minute for login/password reset)
     */
    public static function auth(): RateLimitMiddleware {
        return new RateLimitMiddleware(5, 60, 'auth');
    }

    /**
     * Upload rate limit (10 uploads/minute)
     */
    public static function upload(): RateLimitMiddleware {
        return new RateLimitMiddleware(10, 60, 'upload');
    }

    /**
     * Search rate limit (30 searches/minute)
     */
    public static function search(): RateLimitMiddleware {
        return new RateLimitMiddleware(30, 60, 'search');
    }

    /**
     * Download rate limit (100 downloads/hour)
     */
    public static function download(): RateLimitMiddleware {
        return new RateLimitMiddleware(100, 3600, 'download');
    }

    /**
     * Strict rate limit (10 requests/minute for sensitive operations)
     */
    public static function strict(): RateLimitMiddleware {
        return new RateLimitMiddleware(10, 60, 'strict');
    }
}
