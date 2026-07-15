<?php

declare(strict_types=1);

/**
 * Plugin Repository Manager
 *
 * Manages plugin repository records (plugin_repositories table) and fetching of
 * their remote registries. Extracted from PluginManager as a cohesive
 * collaborator; PluginManager keeps the public facade
 * (getRepositories/addRepository/removeRepository/fetchRegistry) and delegates
 * here. These operations depend only on the database and network -- not on
 * PluginManager's in-memory plugin state -- so they extract cleanly.
 *
 * All remote URLs pass an SSRF guard (isPublicUrl) that fails closed.
 */
class PluginRepository
{
    /**
     * Per-request cache of isPublicUrl verdicts keyed by host. The check
     * does blocking DNS lookups (A + AAAA), which dominate refresh time
     * when several repositories share a host (e.g. raw.githubusercontent.com).
     *
     * @var array<string, bool>
     */
    private array $publicHostCache = [];

    /**
     * All configured plugin repositories (empty on failure).
     */
    public function getRepositories(): array
    {
        try {
            $db = getDB();
            $result = $db->query('SELECT id, name, url, is_official, registry_cache, last_fetched FROM plugin_repositories');
            return $result->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Add a plugin repository. Rejects invalid URLs and non-public hosts (SSRF).
     */
    public function addRepository(string $name, string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Block SSRF: refuse repositories that point at private/internal hosts
        if (!$this->isPublicUrl($url)) {
            logWarning('Blocked plugin repository with non-public host', ['url' => $url]);
            return false;
        }

        try {
            $db = getDB();
            $stmt = $db->prepare('INSERT INTO plugin_repositories (name, url, is_official) VALUES (:name, :url, 0)');
            $stmt->execute([':name' => $name, ':url' => $url]);
            return true;
        } catch (\Exception $e) {
            logError('Failed to add plugin repository', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Remove a non-official plugin repository by id.
     */
    public function removeRepository(int $id): bool
    {
        try {
            $db = getDB();
            $stmt = $db->prepare('DELETE FROM plugin_repositories WHERE id = :id AND is_official = 0');
            $stmt->execute([':id' => $id]);
            return true;
        } catch (\Exception $e) {
            logError('Failed to remove plugin repository', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Fetch and cache a repository's registry JSON. Returns null on any failure
     * (bad scheme, non-public host, network error, or malformed payload).
     */
    public function fetchRegistry(string $repoUrl): ?array
    {
        if (!$this->isFetchableUrl($repoUrl)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'User-Agent: ' . self::userAgent(),
            ],
        ]);

        return $this->storeRegistry($repoUrl, @file_get_contents($repoUrl, false, $context));
    }

    /**
     * Fetch and cache several registries CONCURRENTLY (curl_multi), so total
     * wall time is the slowest single fetch instead of the sum of all of
     * them. Falls back to sequential fetching when curl is unavailable.
     *
     * @param  list<string> $urls
     * @return array<string, array|null> url => registry (null on failure)
     */
    public function fetchRegistries(array $urls): array
    {
        $results = [];
        $toFetch = [];
        foreach ($urls as $url) {
            $results[$url] = null;
            if ($this->isFetchableUrl($url)) {
                $toFetch[] = $url;
            }
        }

        if ($toFetch === []) {
            return $results;
        }

        if (!function_exists('curl_multi_init') || count($toFetch) === 1) {
            foreach ($toFetch as $url) {
                $results[$url] = $this->fetchRegistry($url);
            }
            return $results;
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($toFetch as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                // SSRF: no redirects - a 3xx could pivot to a private host
                // after the pre-fetch DNS check passed.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => self::userAgent(),
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$url] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $url => $ch) {
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = curl_multi_getcontent($ch);
            $results[$url] = ($httpCode === 200 && is_string($body) && $body !== '')
                ? $this->storeRegistry($url, $body)
                : null;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $results;
    }

    /**
     * Scheme + SSRF validation shared by the fetchers.
     */
    private function isFetchableUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if (!$this->isPublicUrl($url)) {
            logWarning('Blocked plugin registry fetch to non-public host', ['url' => $url]);
            return false;
        }
        return true;
    }

    /**
     * Parse a registry response and cache it on the repository row.
     * Returns the decoded registry, or null when the response is a fetch
     * failure or malformed (missing the plugins key).
     */
    private function storeRegistry(string $repoUrl, string|false $response): ?array
    {
        if ($response === false) {
            return null;
        }

        $registry = json_decode($response, true);
        if (!is_array($registry) || !isset($registry['plugins'])) {
            return null;
        }

        try {
            $db = getDB();
            $type = $db->getType();

            if ($type === 'mysql') {
                $stmt = $db->prepare(
                    'UPDATE plugin_repositories SET registry_cache = :cache, last_fetched = NOW() WHERE url = :url'
                );
            } else {
                $stmt = $db->prepare(
                    'UPDATE plugin_repositories SET registry_cache = :cache, last_fetched = CURRENT_TIMESTAMP WHERE url = :url'
                );
            }
            $stmt->execute([':cache' => json_encode($registry), ':url' => $repoUrl]);
        } catch (\Exception $e) {
            logWarning('Failed to cache plugin registry', ['error' => $e->getMessage()]);
        }

        return $registry;
    }

    private static function userAgent(): string
    {
        return 'MeshSilo/' . (defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0');
    }

    /**
     * Validate that a URL's host resolves only to public IP addresses.
     * Prevents SSRF against loopback / private / link-local targets:
     * RFC1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), 127.0.0.0/8,
     * 169.254.0.0/16, ::1, fc00::/7, and other reserved ranges. Fails closed
     * when the host cannot be resolved.
     *
     * SHORTCUT: pre-fetch DNS check only (TOCTOU / DNS-rebinding still possible);
     * pin the resolved IP into the fetch if plugin registry URLs ever become
     * attacker-controlled rather than admin-entered.
     */
    private function isPublicUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

        // Strip IPv6 literal brackets, e.g. [::1]
        $host = trim($host, '[]');

        if (isset($this->publicHostCache[$host])) {
            return $this->publicHostCache[$host];
        }

        // Collect candidate IPs: a literal host, or DNS-resolved A/AAAA records
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $v4 = gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        // Could not resolve to any address -- fail closed
        if (empty($ips)) {
            return $this->publicHostCache[$host] = false;
        }

        // Reject if ANY resolved IP is private, reserved, loopback or link-local
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $this->publicHostCache[$host] = false;
            }
        }

        return $this->publicHostCache[$host] = true;
    }
}
