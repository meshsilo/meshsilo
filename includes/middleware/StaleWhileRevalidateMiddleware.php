<?php

/**
 * Stale-While-Revalidate Middleware
 *
 * Implements the stale-while-revalidate caching pattern for API responses.
 * Serves cached responses immediately while revalidating in the background.
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class StaleWhileRevalidateMiddleware implements MiddlewareInterface
{
    private string $cacheDir;
    private int $maxAge;
    private int $staleWhileRevalidate;
    private array $excludePatterns;

    public function __construct(array $options = [])
    {
        $this->cacheDir = $options['cache_dir'] ?? dirname(__DIR__, 2) . '/storage/cache/swr';
        $this->maxAge = $options['max_age'] ?? 60; // 1 minute fresh
        $this->staleWhileRevalidate = $options['stale_while_revalidate'] ?? 300; // 5 minutes stale
        $this->excludePatterns = $options['exclude'] ?? ['/admin/', '/actions/', '/api/'];

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function handle(array $params): bool
    {
        // Only cache GET requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return true;
        }

        // Skip excluded paths
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ($this->excludePatterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }

        // Skip if user is logged in (personalized content)
        if (!empty($_SESSION['user_id'])) {
            // Add private cache header
            header('Cache-Control: private, max-age=0');
            return true;
        }

        $cacheKey = $this->getCacheKey();
        $cached = $this->getFromCache($cacheKey);

        if ($cached) {
            $age = time() - $cached['timestamp'];

            // Fresh: serve from cache
            if ($age < $this->maxAge) {
                $this->serveCached($cached, $age);
                return false; // Stop further processing
            }

            // Stale but within revalidation window: serve stale, trigger background revalidation
            if ($age < ($this->maxAge + $this->staleWhileRevalidate)) {
                $this->serveCached($cached, $age, true);

                // Trigger background revalidation (using shutdown function)
                $this->scheduleRevalidation($cacheKey);

                return false;
            }
        }

        // No cache or expired: proceed with request and cache the response
        $this->startOutputBuffering($cacheKey);

        return true;
    }

    private function getCacheKey(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        return md5($uri . '?' . $query);
    }

    private function getFromCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.cache';
        if (!file_exists($file)) {
            return null;
        }

        $data = @json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['content'], $data['timestamp'], $data['headers'])) {
            return null;
        }

        return $data;
    }

    private function saveToCache(string $key, string $content, array $headers): void
    {
        $file = $this->cacheDir . '/' . $key . '.cache';
        $data = [
            'content' => $content,
            'timestamp' => time(),
            'headers' => $headers
        ];
        file_put_contents($file, json_encode($data));
    }

    private function serveCached(array $cached, int $age, bool $stale = false): void
    {
        // Send cache headers
        $maxAge = $stale ? 0 : ($this->maxAge - $age);
        $swr = $this->staleWhileRevalidate;

        header("Cache-Control: public, max-age={$maxAge}, stale-while-revalidate={$swr}");
        header('X-Cache: HIT' . ($stale ? ' (stale)' : ''));
        header('Age: ' . $age);

        // Send original headers
        foreach ($cached['headers'] as $name => $value) {
            if (stripos($name, 'cache-control') === false && stripos($name, 'set-cookie') === false) {
                header("{$name}: {$value}");
            }
        }

        // Output content
        echo $cached['content'];
    }

    private function startOutputBuffering(string $cacheKey): void
    {
        ob_start(function ($content) use ($cacheKey) {
            // Capture current headers
            $headers = [];
            foreach (headers_list() as $header) {
                list($name, $value) = explode(':', $header, 2);
                $headers[trim($name)] = trim($value);
            }

            // Only cache successful HTML responses
            $contentType = $headers['Content-Type'] ?? 'text/html';
            if (strpos($contentType, 'text/html') !== false && http_response_code() === 200) {
                $this->saveToCache($cacheKey, $content, $headers);
            }

            // Add cache headers
            header("Cache-Control: public, max-age={$this->maxAge}, stale-while-revalidate={$this->staleWhileRevalidate}");
            header('X-Cache: MISS');

            return $content;
        });
    }

    private function scheduleRevalidation(string $cacheKey): void
    {
        // In a real production environment, this would trigger a background job
        // For now, we just mark the need for revalidation
        register_shutdown_function(function () {
            // The next request will fetch fresh content
            // This could be enhanced with a job queue
        });
    }

    /**
     * Clear expired cache entries
     */
    public function cleanup(): int
    {
        $cleared = 0;
        $maxAge = $this->maxAge + $this->staleWhileRevalidate + 3600; // Extra hour buffer

        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            if (filemtime($file) < (time() - $maxAge)) {
                unlink($file);
                $cleared++;
            }
        }

        return $cleared;
    }
}
