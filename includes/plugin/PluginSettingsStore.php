<?php

declare(strict_types=1);

/**
 * Plugin Settings Store
 *
 * Persists per-plugin settings as a JSON blob in the plugins.settings column.
 * Extracted from PluginManager as a cohesive collaborator; PluginManager keeps
 * the public facade (getSetting/setSetting/getSettings) and delegates here.
 *
 * All methods fail soft: a missing plugins table or malformed JSON yields the
 * caller-supplied default rather than an exception, matching the original
 * PluginManager behavior exactly.
 */
class PluginSettingsStore
{
    /**
     * Per-request memoization of decoded settings, keyed by plugin id.
     * Plugins commonly call getSetting() repeatedly in hot paths; without
     * this every call is a DB round-trip. Writes invalidate the entry.
     *
     * @var array<string, array>
     */
    private array $cache = [];

    /**
     * Get a single plugin setting value, or $default when unset/unavailable.
     */
    public function get(string $pluginId, string $key, mixed $default = null): mixed
    {
        return $this->getAll($pluginId)[$key] ?? $default;
    }

    /**
     * Set a single plugin setting value. Returns false on failure.
     */
    public function set(string $pluginId, string $key, mixed $value): bool
    {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT settings FROM plugins WHERE id = :id');
            $stmt->execute([':id' => $pluginId]);
            $row = $stmt->fetch();
            $settings = ($row && !empty($row['settings'])) ? json_decode($row['settings'], true) : [];
            if (!is_array($settings)) $settings = [];
            $settings[$key] = $value;

            $type = $db->getType();
            if ($type === 'mysql') {
                $stmt = $db->prepare('UPDATE plugins SET settings = :settings, updated_at = NOW() WHERE id = :id');
            } else {
                $stmt = $db->prepare('UPDATE plugins SET settings = :settings, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            }
            $stmt->execute([':settings' => json_encode($settings), ':id' => $pluginId]);
            unset($this->cache[$pluginId]);
            return true;
        } catch (\Exception $e) {
            logError("Failed to save plugin setting", ['plugin' => $pluginId, 'key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get all settings for a plugin as an associative array (empty on failure).
     */
    public function getAll(string $pluginId): array
    {
        if (array_key_exists($pluginId, $this->cache)) {
            return $this->cache[$pluginId];
        }

        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT settings FROM plugins WHERE id = :id');
            $stmt->execute([':id' => $pluginId]);
            $row = $stmt->fetch();
            if (!$row || empty($row['settings'])) {
                return $this->cache[$pluginId] = [];
            }
            $settings = json_decode($row['settings'], true);
            return $this->cache[$pluginId] = (is_array($settings) ? $settings : []);
        } catch (\Exception $e) {
            // Not cached: the plugins table may simply not exist yet
            // (mid-install); a later call in the same request may succeed.
            return [];
        }
    }
}
