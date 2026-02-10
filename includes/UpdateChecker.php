<?php
/**
 * Update Checker
 *
 * Checks for new versions of Silo by querying GitHub releases.
 * Caches results to avoid excessive API calls.
 */

class UpdateChecker {
    private const GITHUB_REPO = 'Azurith93/silo';
    private const CACHE_KEY = 'update_check';
    private const CACHE_TTL = 3600; // 1 hour

    private static ?array $cachedResult = null;

    /**
     * Check for updates
     *
     * @param bool $force Force check, ignoring cache
     * @return array Update info with keys: available, current, latest, url, published, changelog
     */
    public static function check(bool $force = false): array {
        // Return cached result if available
        if (self::$cachedResult !== null && !$force) {
            return self::$cachedResult;
        }

        // Try to get from cache
        if (!$force && class_exists('Cache')) {
            $cached = Cache::getInstance()->get(self::CACHE_KEY);
            if ($cached !== null) {
                self::$cachedResult = $cached;
                return $cached;
            }
        }

        $currentVersion = defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '0.0.0';

        $result = [
            'available' => false,
            'current' => $currentVersion,
            'latest' => $currentVersion,
            'url' => 'https://github.com/' . self::GITHUB_REPO . '/releases',
            'published' => null,
            'changelog' => null,
            'error' => null,
            'checked_at' => date('Y-m-d H:i:s')
        ];

        try {
            $releaseInfo = self::fetchLatestRelease();

            if ($releaseInfo) {
                $latestVersion = ltrim($releaseInfo['tag_name'] ?? '', 'v');
                $result['latest'] = $latestVersion;
                $result['url'] = $releaseInfo['html_url'] ?? $result['url'];
                $result['published'] = $releaseInfo['published_at'] ?? null;
                $result['changelog'] = $releaseInfo['body'] ?? null;
                $result['available'] = version_compare($latestVersion, $currentVersion, '>');
            }
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            if (function_exists('logWarning')) {
                logWarning('Update check failed', ['error' => $e->getMessage()]);
            }
        }

        // Cache the result
        if (class_exists('Cache')) {
            Cache::getInstance()->set(self::CACHE_KEY, $result, self::CACHE_TTL);
        }

        self::$cachedResult = $result;
        return $result;
    }

    /**
     * Fetch latest release from GitHub API
     */
    private static function fetchLatestRelease(): ?array {
        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: MeshSilo-UpdateChecker/' . (defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0'),
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 10
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            // Check if it's a 404 (no releases yet)
            /** @phpstan-ignore isset.variable */
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (strpos($header, '404') !== false) {
                        return null; // No releases yet
                    }
                }
            }
            throw new Exception('Failed to connect to GitHub API');
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid response from GitHub API');
        }

        return $data;
    }

    /**
     * Get all releases (for changelog display)
     *
     * @param int $limit Number of releases to fetch
     * @return array List of releases
     */
    public static function getReleases(int $limit = 10): array {
        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases?per_page=' . $limit;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: MeshSilo-UpdateChecker/' . (defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0'),
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 10
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $data;
    }

    /**
     * Clear the update check cache
     */
    public static function clearCache(): void {
        self::$cachedResult = null;
        if (class_exists('Cache')) {
            Cache::getInstance()->forget(self::CACHE_KEY);
        }
    }

    /**
     * Check if update check is enabled
     */
    public static function isEnabled(): bool {
        if (function_exists('getSetting')) {
            return getSetting('update_check_enabled', '1') === '1';
        }
        return true;
    }

    /**
     * Get time until next check
     */
    public static function getNextCheckTime(): ?int {
        if (class_exists('Cache')) {
            $cached = Cache::getInstance()->get(self::CACHE_KEY);
            if ($cached && isset($cached['checked_at'])) {
                $checkedAt = strtotime($cached['checked_at']);
                $nextCheck = $checkedAt + self::CACHE_TTL;
                return max(0, $nextCheck - time());
            }
        }
        return null;
    }
}
