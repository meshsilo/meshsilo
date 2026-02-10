<?php
/**
 * Rate Limiter
 *
 * Enterprise-grade rate limiting with configurable tiers and per-key limits
 */

class RateLimiter {
    private static $db;
    private static $tiers = null;

    /**
     * Initialize database connection
     */
    private static function init() {
        if (!self::$db) {
            self::$db = getDB();
        }
    }

    /**
     * Get rate limit tiers
     */
    public static function getTiers() {
        if (self::$tiers === null) {
            $tiersJson = getSetting('rate_limit_tiers', '');
            if ($tiersJson) {
                self::$tiers = json_decode($tiersJson, true);
            } else {
                // Default tiers
                self::$tiers = [
                    'anonymous' => [
                        'name' => 'Anonymous',
                        'requests_per_minute' => 30,
                        'requests_per_hour' => 500,
                        'requests_per_day' => 5000
                    ],
                    'authenticated' => [
                        'name' => 'Authenticated User',
                        'requests_per_minute' => 60,
                        'requests_per_hour' => 1000,
                        'requests_per_day' => 10000
                    ],
                    'premium' => [
                        'name' => 'Premium',
                        'requests_per_minute' => 120,
                        'requests_per_hour' => 3000,
                        'requests_per_day' => 50000
                    ],
                    'unlimited' => [
                        'name' => 'Unlimited',
                        'requests_per_minute' => 0,
                        'requests_per_hour' => 0,
                        'requests_per_day' => 0
                    ]
                ];
            }
        }
        return self::$tiers;
    }

    /**
     * Save rate limit tiers
     */
    public static function saveTiers($tiers) {
        setSetting('rate_limit_tiers', json_encode($tiers));
        self::$tiers = $tiers;
    }

    /**
     * Check rate limit and increment counter
     *
     * @param string $identifier User ID, API key, or IP address
     * @param string $tier Rate limit tier name
     * @param string $endpoint Optional endpoint-specific limiting
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => timestamp, 'tier' => string]
     */
    public static function check($identifier, $tier = 'anonymous', $endpoint = 'default') {
        self::init();

        $tiers = self::getTiers();
        $tierConfig = $tiers[$tier] ?? $tiers['anonymous'];

        // Unlimited tier bypasses checks
        if ($tier === 'unlimited' ||
            ($tierConfig['requests_per_minute'] == 0 &&
             $tierConfig['requests_per_hour'] == 0 &&
             $tierConfig['requests_per_day'] == 0)) {
            return [
                'allowed' => true,
                'remaining' => -1,
                'reset' => null,
                'tier' => $tier
            ];
        }

        $now = time();
        $minuteWindow = $now - 60;
        $hourWindow = $now - 3600;
        $dayWindow = $now - 86400;

        // Create rate_limits table if needed
        self::ensureTable();

        // Clean old entries
        $stmt = self::$db->prepare('DELETE FROM rate_limits WHERE `timestamp` < :cutoff');
        $stmt->bindValue(':cutoff', $dayWindow, PDO::PARAM_INT);
        $stmt->execute();

        // Count requests in windows
        $key = hash('sha256', $identifier . ':' . $endpoint);

        $minuteCount = self::getCount($key, $minuteWindow);
        $hourCount = self::getCount($key, $hourWindow);
        $dayCount = self::getCount($key, $dayWindow);

        // Check against limits
        $allowed = true;
        $remaining = PHP_INT_MAX;
        $reset = null;

        if ($tierConfig['requests_per_minute'] > 0) {
            $minRemaining = $tierConfig['requests_per_minute'] - $minuteCount;
            if ($minRemaining <= 0) {
                $allowed = false;
                $reset = $now + 60;
            }
            $remaining = min($remaining, max(0, $minRemaining));
        }

        if ($tierConfig['requests_per_hour'] > 0) {
            $hourRemaining = $tierConfig['requests_per_hour'] - $hourCount;
            if ($hourRemaining <= 0) {
                $allowed = false;
                if (!$reset) $reset = $now + 3600;
            }
            $remaining = min($remaining, max(0, $hourRemaining));
        }

        if ($tierConfig['requests_per_day'] > 0) {
            $dayRemaining = $tierConfig['requests_per_day'] - $dayCount;
            if ($dayRemaining <= 0) {
                $allowed = false;
                if (!$reset) $reset = $now + 86400;
            }
            $remaining = min($remaining, max(0, $dayRemaining));
        }

        // Record this request
        if ($allowed) {
            self::record($key);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset' => $reset,
            'tier' => $tier,
            'limits' => [
                'minute' => ['count' => $minuteCount + 1, 'limit' => $tierConfig['requests_per_minute']],
                'hour' => ['count' => $hourCount + 1, 'limit' => $tierConfig['requests_per_hour']],
                'day' => ['count' => $dayCount + 1, 'limit' => $tierConfig['requests_per_day']]
            ]
        ];
    }

    /**
     * Get request count since timestamp
     */
    private static function getCount($key, $since) {
        $stmt = self::$db->prepare('SELECT COUNT(*) as count FROM rate_limits WHERE key_hash = :key AND `timestamp` >= :since');
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':since', $since, PDO::PARAM_INT);
        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);
        return $row['count'] ?? 0;
    }

    /**
     * Record a request
     */
    private static function record($key) {
        $stmt = self::$db->prepare('INSERT INTO rate_limits (key_hash, `timestamp`) VALUES (:key, :ts)');
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':ts', time(), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Ensure rate_limits table exists
     */
    private static function ensureTable() {
        try {
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                self::$db->exec('
                    CREATE TABLE IF NOT EXISTS rate_limits (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        key_hash VARCHAR(64) NOT NULL,
                        `timestamp` INT NOT NULL,
                        INDEX idx_rate_limits_key (key_hash, `timestamp`)
                    )
                ');
                // Verify the table has the expected columns; if not, recreate
                try {
                    $stmt = self::$db->prepare('SELECT `timestamp` FROM rate_limits LIMIT 1');
                    $stmt->execute();
                } catch (Exception $e) {
                    // Table exists but with wrong schema - drop and recreate
                    self::$db->exec('DROP TABLE IF EXISTS rate_limits');
                    self::$db->exec('
                        CREATE TABLE rate_limits (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            key_hash VARCHAR(64) NOT NULL,
                            `timestamp` INT NOT NULL,
                            INDEX idx_rate_limits_key (key_hash, `timestamp`)
                        )
                    ');
                }
            } else {
                self::$db->exec('
                    CREATE TABLE IF NOT EXISTS rate_limits (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        key_hash TEXT NOT NULL,
                        timestamp INTEGER NOT NULL
                    )
                ');
                self::$db->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_key ON rate_limits (key_hash, timestamp)');
            }
        } catch (Exception $e) {
            // Log but don't fail - rate limiting is non-critical
            if (function_exists('logWarning')) {
                logWarning('Failed to ensure rate_limits table: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get tier for user/API key
     */
    public static function getTierForUser($userId = null, $apiKey = null) {
        self::init();

        // Check API key tier (rate_limit_tier column may not exist if migrations haven't run)
        if ($apiKey) {
            try {
                $stmt = self::$db->prepare('SELECT rate_limit_tier FROM api_keys WHERE key_hash = :key AND is_active = 1');
                $stmt->bindValue(':key', hash('sha256', $apiKey), PDO::PARAM_STR);
                $result = $stmt->execute();
                $row = $result->fetchArray(PDO::FETCH_ASSOC);
                if ($row && !empty($row['rate_limit_tier'])) {
                    return $row['rate_limit_tier'];
                }
            } catch (Exception $e) {
                // Column doesn't exist yet - fall through to default
            }
        }

        // Check user tier (rate_limit_tier column may not exist if migrations haven't run)
        if ($userId) {
            try {
                $stmt = self::$db->prepare('SELECT rate_limit_tier FROM users WHERE id = :id');
                $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
                $result = $stmt->execute();
                $row = $result->fetchArray(PDO::FETCH_ASSOC);
                if ($row && !empty($row['rate_limit_tier'])) {
                    return $row['rate_limit_tier'];
                }
            } catch (Exception $e) {
                // Column doesn't exist yet - fall through to default
            }
            return 'authenticated';
        }

        return 'anonymous';
    }

    /**
     * Set rate limit headers on response
     */
    public static function setHeaders($result) {
        header('X-RateLimit-Limit: ' . ($result['limits']['minute']['limit'] ?? 0));
        header('X-RateLimit-Remaining: ' . max(0, $result['remaining']));
        if ($result['reset']) {
            header('X-RateLimit-Reset: ' . $result['reset']);
        }
        header('X-RateLimit-Tier: ' . $result['tier']);

        if (!$result['allowed']) {
            header('Retry-After: ' . ($result['reset'] - time()));
        }
    }

    /**
     * Get rate limit statistics
     */
    public static function getStats() {
        self::init();
        self::ensureTable();

        $now = time();
        $stats = [];

        // Requests in last hour
        $stmt = self::$db->prepare('SELECT COUNT(*) as count FROM rate_limits WHERE `timestamp` >= :since');
        $stmt->bindValue(':since', $now - 3600, PDO::PARAM_INT);
        $result = $stmt->execute();
        $stats['requests_hour'] = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

        // Unique identifiers in last hour
        $stmt = self::$db->prepare('SELECT COUNT(DISTINCT key_hash) as count FROM rate_limits WHERE `timestamp` >= :since');
        $stmt->bindValue(':since', $now - 3600, PDO::PARAM_INT);
        $result = $stmt->execute();
        $stats['unique_keys_hour'] = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

        // Rate limited requests (approximate - those who hit limits)
        $stmt = self::$db->prepare('
            SELECT key_hash, COUNT(*) as count
            FROM rate_limits
            WHERE `timestamp` >= :since
            GROUP BY key_hash
            HAVING count > 60
        ');
        $stmt->bindValue(':since', $now - 3600, PDO::PARAM_INT);
        $result = $stmt->execute();
        $heavyUsers = 0;
        while ($result->fetchArray()) {
            $heavyUsers++;
        }
        $stats['heavy_users_hour'] = $heavyUsers;

        return $stats;
    }

    /**
     * Get top consumers
     */
    public static function getTopConsumers($limit = 10, $period = 3600) {
        self::init();
        self::ensureTable();

        $consumers = [];
        $stmt = self::$db->prepare('
            SELECT key_hash, COUNT(*) as request_count
            FROM rate_limits
            WHERE `timestamp` >= :since
            GROUP BY key_hash
            ORDER BY request_count DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':since', time() - $period, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $consumers[] = $row;
        }

        return $consumers;
    }

    /**
     * Reset rate limits for identifier
     */
    public static function reset($identifier, $endpoint = 'default') {
        self::init();
        self::ensureTable();

        $key = hash('sha256', $identifier . ':' . $endpoint);
        $stmt = self::$db->prepare('DELETE FROM rate_limits WHERE key_hash = :key');
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Cleanup old rate limit records
     */
    public static function cleanup($olderThan = 86400) {
        self::init();
        self::ensureTable();

        $stmt = self::$db->prepare('DELETE FROM rate_limits WHERE `timestamp` < :cutoff');
        $stmt->bindValue(':cutoff', time() - $olderThan, PDO::PARAM_INT);
        $stmt->execute();

        return self::$db->changes();
    }
}
