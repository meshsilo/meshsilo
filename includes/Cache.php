<?php
/**
 * Caching System
 *
 * Provides a simple caching layer with multiple driver support:
 * - Redis (if available, best for distributed/high-traffic)
 * - APCu (if available, fastest for single-server)
 * - File-based caching (default, works everywhere)
 * - Memory (request-scoped, for expensive repeated calls)
 */

class Cache {
    private static ?self $instance = null;
    private string $driver = 'file';
    private string $path;
    private string $prefix = 'silo_';
    private int $defaultTtl = 3600; // 1 hour
    private array $memory = [];
    private ?\Redis $redis = null;

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
        $this->path = dirname(__DIR__) . '/storage/cache/';

        // Auto-detect best available driver (priority: redis > apcu > file)
        if ($this->connectRedis()) {
            $this->driver = 'redis';
        } elseif (extension_loaded('apcu') && apcu_enabled()) {
            $this->driver = 'apcu';
        } else {
            $this->driver = 'file';
            if (!is_dir($this->path)) {
                mkdir($this->path, 0755, true);
            }
        }
    }

    /**
     * Try to connect to Redis
     */
    private function connectRedis(): bool {
        if (!extension_loaded('redis')) {
            return false;
        }

        // Check for Redis configuration in settings or environment
        $redisHost = getenv('REDIS_HOST') ?: (defined('REDIS_HOST') ? REDIS_HOST : '');
        if (empty($redisHost)) {
            return false;
        }

        try {
            $this->redis = new \Redis();
            $redisPort = (int)(getenv('REDIS_PORT') ?: (defined('REDIS_PORT') ? REDIS_PORT : 6379));
            $this->redis->connect($redisHost, $redisPort, 2.0); // 2 second timeout

            $redisPassword = getenv('REDIS_PASSWORD') ?: (defined('REDIS_PASSWORD') ? REDIS_PASSWORD : '');
            if (!empty($redisPassword)) {
                $this->redis->auth($redisPassword);
            }

            $redisDb = (int)(getenv('REDIS_DB') ?: (defined('REDIS_DB') ? REDIS_DB : 0));
            if ($redisDb > 0) {
                $this->redis->select($redisDb);
            }

            return $this->redis->ping() === true || $this->redis->ping() === '+PONG';
        } catch (\Exception $e) {
            $this->redis = null;
            return false;
        }
    }

    /**
     * Configure the cache
     */
    public function configure(array $options): self {
        if (isset($options['driver'])) {
            $this->driver = $options['driver'];
        }
        if (isset($options['path'])) {
            $this->path = rtrim($options['path'], '/') . '/';
        }
        if (isset($options['prefix'])) {
            $this->prefix = $options['prefix'];
        }
        if (isset($options['ttl'])) {
            $this->defaultTtl = (int)$options['ttl'];
        }
        return $this;
    }

    /**
     * Get a cached value
     */
    public function get(string $key, $default = null) {
        $prefixedKey = $this->prefix . $key;

        // Check memory cache first
        if (isset($this->memory[$prefixedKey])) {
            $item = $this->memory[$prefixedKey];
            if ($item['expires'] === 0 || $item['expires'] > time()) {
                return $item['value'];
            }
            unset($this->memory[$prefixedKey]);
        }

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    $value = $this->redis->get($prefixedKey);
                    if ($value !== false) {
                        return unserialize($value);
                    }
                }
                return $default;

            case 'apcu':
                $success = false;
                $value = apcu_fetch($prefixedKey, $success);
                if (!$success) return $default;
                $unserialized = @unserialize($value);
                return $unserialized !== false ? $unserialized : $default;

            case 'file':
                return $this->getFromFile($prefixedKey, $default);

            case 'memory':
                return $default;

            default:
                return $default;
        }
    }

    /**
     * Store a value in cache
     */
    public function set(string $key, $value, ?int $ttl = null): bool {
        $prefixedKey = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;
        $expires = $ttl > 0 ? time() + $ttl : 0;

        // Always store in memory for request duration
        $this->memory[$prefixedKey] = [
            'value' => $value,
            'expires' => $expires
        ];

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    if ($ttl > 0) {
                        return $this->redis->setex($prefixedKey, $ttl, serialize($value));
                    }
                    return $this->redis->set($prefixedKey, serialize($value));
                }
                return false;

            case 'apcu':
                return apcu_store($prefixedKey, serialize($value), $ttl);

            case 'file':
                return $this->setToFile($prefixedKey, $value, $expires);

            case 'memory':
                return true;

            default:
                return false;
        }
    }

    /**
     * Store a value forever
     */
    public function forever(string $key, $value): bool {
        return $this->set($key, $value, 0);
    }

    /**
     * Check if key exists
     */
    public function has(string $key): bool {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Delete a cached value
     */
    public function forget(string $key): bool {
        $prefixedKey = $this->prefix . $key;

        unset($this->memory[$prefixedKey]);

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    return $this->redis->del($prefixedKey) !== false;
                }
                return true;

            case 'apcu':
                return apcu_delete($prefixedKey);

            case 'file':
                $file = $this->getCacheFile($prefixedKey);
                if (file_exists($file)) {
                    return unlink($file);
                }
                return true;

            default:
                return true;
        }
    }

    /**
     * Delete multiple keys by pattern
     */
    public function forgetPattern(string $pattern): int {
        $count = 0;
        $fullPattern = $this->prefix . $pattern;

        switch ($this->driver) {
            case 'apcu':
                $iterator = new APCUIterator('/^' . preg_quote($fullPattern, '/') . '/');
                foreach ($iterator as $item) {
                    apcu_delete($item['key']);
                    $count++;
                }
                break;

            case 'file':
                $files = glob($this->path . md5($fullPattern) . '*');
                foreach ($files as $file) {
                    if (unlink($file)) {
                        $count++;
                    }
                }
                break;
        }

        // Clear matching memory cache
        foreach (array_keys($this->memory) as $key) {
            if (fnmatch($fullPattern, $key)) {
                unset($this->memory[$key]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Clear all cache
     */
    public function flush(): bool {
        $this->memory = [];

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    // Only flush keys with our prefix
                    $keys = $this->redis->keys($this->prefix . '*');
                    if (!empty($keys)) {
                        $this->redis->del($keys);
                    }
                }
                return true;

            case 'apcu':
                return apcu_clear_cache();

            case 'file':
                $files = glob($this->path . '*.cache');
                foreach ($files as $file) {
                    @unlink($file);
                }
                return true;

            default:
                return true;
        }
    }

    /**
     * Get or set - return cached value or store result of callback
     */
    public function remember(string $key, $ttl, callable $callback) {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, is_int($ttl) ? $ttl : $this->defaultTtl);

        return $value;
    }

    /**
     * Get or set forever
     */
    public function rememberForever(string $key, callable $callback) {
        return $this->remember($key, 0, $callback);
    }

    /**
     * Pull - get and forget
     */
    public function pull(string $key, $default = null) {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /**
     * Increment a numeric value
     */
    public function increment(string $key, int $amount = 1): int {
        $prefixedKey = $this->prefix . $key;

        $value = (int)$this->get($key, 0) + $amount;
        $this->set($key, $value);
        return $value;
    }

    /**
     * Decrement a numeric value
     */
    public function decrement(string $key, int $amount = 1): int {
        return $this->increment($key, -$amount);
    }

    /**
     * Get multiple values
     */
    public function many(array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Set multiple values
     */
    public function setMany(array $values, ?int $ttl = null): bool {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * Get cache statistics
     */
    public function stats(): array {
        $stats = [
            'driver' => $this->driver,
            'memory_items' => count($this->memory),
        ];

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    $info = $this->redis->info();
                    $stats['hits'] = $info['keyspace_hits'] ?? 0;
                    $stats['misses'] = $info['keyspace_misses'] ?? 0;
                    $stats['entries'] = $this->redis->dbSize();
                    $stats['memory_size'] = $info['used_memory'] ?? 0;
                }
                break;

            case 'apcu':
                $info = apcu_cache_info();
                $stats['hits'] = $info['num_hits'] ?? 0;
                $stats['misses'] = $info['num_misses'] ?? 0;
                $stats['entries'] = $info['num_entries'] ?? 0;
                $stats['memory_size'] = $info['mem_size'] ?? 0;
                break;

            case 'file':
                $files = glob($this->path . '*.cache');
                $stats['files'] = count($files);
                $stats['size'] = array_sum(array_map('filesize', $files));
                break;
        }

        return $stats;
    }

    // ========================================
    // File Driver Methods
    // ========================================

    /**
     * Get cache file path
     */
    private function getCacheFile(string $key): string {
        return $this->path . md5($key) . '.cache';
    }

    /**
     * Get value from file cache
     */
    private function getFromFile(string $key, $default) {
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return $default;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $default;
        }

        $data = @unserialize($content);
        if ($data === false) {
            @unlink($file);
            return $default;
        }

        // Check expiration
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Store value to file cache
     */
    private function setToFile(string $key, $value, int $expires): bool {
        $file = $this->getCacheFile($key);

        $data = serialize([
            'expires' => $expires,
            'value' => $value
        ]);

        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /**
     * Clean expired file cache entries
     */
    public function gc(): int {
        if ($this->driver !== 'file') {
            return 0;
        }

        $count = 0;
        $files = glob($this->path . '*.cache');

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $data = @unserialize($content);
            if ($data === false || ($data['expires'] !== 0 && $data['expires'] < time())) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Get cache instance
 */
function cache(?string $key = null, $default = null) {
    $cache = Cache::getInstance();

    if ($key === null) {
        return $cache;
    }

    return $cache->get($key, $default);
}

/**
 * Cache a value
 */
function cache_set(string $key, $value, ?int $ttl = null): bool {
    return Cache::getInstance()->set($key, $value, $ttl);
}

/**
 * Remember a value
 */
function cache_remember(string $key, int $ttl, callable $callback) {
    return Cache::getInstance()->remember($key, $ttl, $callback);
}

/**
 * Forget a cached value
 */
function cache_forget(string $key): bool {
    return Cache::getInstance()->forget($key);
}
