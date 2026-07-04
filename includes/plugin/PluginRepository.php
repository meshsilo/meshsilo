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
        // Validate URL scheme to prevent SSRF
        $scheme = parse_url($repoUrl, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme ?? ''), ['http', 'https'], true)) {
            return null;
        }

        // Block SSRF: refuse to fetch from private/internal hosts
        if (!$this->isPublicUrl($repoUrl)) {
            logWarning('Blocked plugin registry fetch to non-public host', ['url' => $repoUrl]);
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'User-Agent: MeshSilo/' . (defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0'),
            ],
        ]);

        $response = @file_get_contents($repoUrl, false, $context);
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
            return false;
        }

        // Reject if ANY resolved IP is private, reserved, loopback or link-local
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
