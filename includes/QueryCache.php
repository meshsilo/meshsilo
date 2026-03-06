<?php

/**
 * Query Result Caching
 *
 * Caches expensive database query results to reduce database load.
 * Features:
 * - Automatic cache invalidation on write operations
 * - Tag-based cache groups for targeted invalidation
 * - TTL-based expiration
 * - Works with APCu, Redis, or file cache
 */

class QueryCache
{
    private static ?self $instance = null;
    private Cache $cache;
    private bool $enabled = true;
    private int $defaultTtl = 300; // 5 minutes

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->cache = Cache::getInstance();
        $this->enabled = getSetting('query_cache_enabled', '1') === '1';
    }

    /**
     * Execute a cached query
     */
    public function remember(string $key, int $ttl, callable $callback, array $tags = []): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $cacheKey = 'query:' . $key;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();

        if ($result !== null) {
            $this->cache->set($cacheKey, $result, $ttl);

            // Register tags for this cache entry
            foreach ($tags as $tag) {
                $this->registerTag($tag, $cacheKey);
            }
        }

        return $result;
    }

    /**
     * Cache a query result with automatic key generation
     */
    public function query(string $sql, array $params = [], ?int $ttl = null, array $tags = []): mixed
    {
        $key = md5($sql . serialize($params));
        $ttl = $ttl ?? $this->defaultTtl;

        return $this->remember($key, $ttl, function () use ($sql, $params) {
            $db = getDB();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, $tags);
    }

    /**
     * Cache a single row query
     */
    public function queryOne(string $sql, array $params = [], ?int $ttl = null, array $tags = []): ?array
    {
        $key = md5('one:' . $sql . serialize($params));
        $ttl = $ttl ?? $this->defaultTtl;

        return $this->remember($key, $ttl, function () use ($sql, $params) {
            $db = getDB();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }, $tags);
    }

    /**
     * Cache a scalar query result
     */
    public function queryScalar(string $sql, array $params = [], ?int $ttl = null, array $tags = []): mixed
    {
        $key = md5('scalar:' . $sql . serialize($params));
        $ttl = $ttl ?? $this->defaultTtl;

        return $this->remember($key, $ttl, function () use ($sql, $params) {
            $db = getDB();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        }, $tags);
    }

    /**
     * Invalidate cache entries by tag
     */
    public function invalidateTag(string $tag): int
    {
        $tagKey = 'query_tag:' . $tag;
        $keys = $this->cache->get($tagKey) ?? [];

        $count = 0;
        foreach ($keys as $key) {
            if ($this->cache->forget($key)) {
                $count++;
            }
        }

        $this->cache->forget($tagKey);
        return $count;
    }

    /**
     * Invalidate multiple tags
     */
    public function invalidateTags(array $tags): int
    {
        $count = 0;
        foreach ($tags as $tag) {
            $count += $this->invalidateTag($tag);
        }
        return $count;
    }

    /**
     * Invalidate a specific cache key
     */
    public function invalidate(string $key): bool
    {
        return $this->cache->forget('query:' . $key);
    }

    /**
     * Clear all query cache
     */
    public function flush(): bool
    {
        // This will clear all cache, not just queries
        // In production, you'd want to track query keys separately
        return $this->cache->flush();
    }

    /**
     * Register a cache key with a tag
     */
    private function registerTag(string $tag, string $key): void
    {
        $tagKey = 'query_tag:' . $tag;
        $keys = $this->cache->get($tagKey) ?? [];

        if (!in_array($key, $keys)) {
            $keys[] = $key;
            // Tags live for 24 hours
            $this->cache->set($tagKey, $keys, 86400);
        }
    }

    /**
     * Enable/disable query caching
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if caching is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set default TTL
     */
    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = $ttl;
        return $this;
    }
}

// ========================================
// Commonly Cached Queries
// ========================================

/**
 * Get cached model count
 */
function getCachedModelCount(?int $categoryId = null): int
{
    $cache = QueryCache::getInstance();

    $key = 'model_count' . ($categoryId ? '_cat' . $categoryId : '');
    $tags = ['models'];
    if ($categoryId) {
        $tags[] = 'category:' . $categoryId;
    }

    return (int)$cache->remember($key, 300, function () use ($categoryId) {
        $db = getDB();
        $sql = 'SELECT COUNT(*) FROM models WHERE parent_id IS NULL';
        $params = [];

        if ($categoryId) {
            $sql .= ' AND category_id = ?';
            $params[] = $categoryId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }, $tags);
}

/**
 * Get cached category list with counts
 */
function getCachedCategories(): array
{
    $cache = QueryCache::getInstance();

    return $cache->remember('categories_with_counts', 600, function () {
        $db = getDB();
        $stmt = $db->query('
            SELECT c.*, COUNT(m.id) as model_count
            FROM categories c
            LEFT JOIN models m ON m.category_id = c.id AND m.parent_id IS NULL
            GROUP BY c.id
            ORDER BY c.name
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, ['categories']);
}

/**
 * Get cached tag list with counts
 */
function getCachedTags(): array
{
    $cache = QueryCache::getInstance();

    return $cache->remember('tags_with_counts', 600, function () {
        $db = getDB();
        $stmt = $db->query('
            SELECT t.*, COUNT(mt.model_id) as model_count
            FROM tags t
            LEFT JOIN model_tags mt ON mt.tag_id = t.id
            GROUP BY t.id
            ORDER BY model_count DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, ['tags']);
}

/**
 * Get cached storage statistics
 */
function getCachedStorageStats(): array
{
    $cache = QueryCache::getInstance();

    return $cache->remember('storage_stats', 3600, function () {
        $db = getDB();

        $totalModels = $db->query('SELECT COUNT(*) FROM models WHERE parent_id IS NULL')->fetchColumn();
        $totalParts = $db->query('SELECT COUNT(*) FROM models WHERE parent_id IS NOT NULL')->fetchColumn();
        $totalSize = $db->query('SELECT SUM(file_size) FROM models')->fetchColumn();

        return [
            'total_models' => (int)$totalModels,
            'total_parts' => (int)$totalParts,
            'total_size' => (int)$totalSize,
        ];
    }, ['models', 'storage']);
}

/**
 * Invalidate model-related cache
 */
function invalidateModelCache(?int $modelId = null, ?int $categoryId = null): void
{
    $cache = QueryCache::getInstance();

    $cache->invalidateTag('models');

    if ($categoryId) {
        $cache->invalidateTag('category:' . $categoryId);
    }

    if ($modelId) {
        $cache->invalidate('model:' . $modelId);
    }

    // Also invalidate storage stats
    $cache->invalidateTag('storage');
}
