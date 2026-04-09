<?php

declare(strict_types=1);

/**
 * Plugin Manager
 *
 * Core plugin engine for MeshSilo. Handles plugin discovery, lifecycle
 * management, asset injection, routing, and repository integration.
 */
class PluginManager
{
    private static ?self $instance = null;
    private array $plugins = [];
    private array $activePlugins = [];
    private array $routes = [];
    private array $adminMenuItems = [];
    private array $adminPages = [];
    private array $styles = [];
    private array $scripts = [];
    private array $filters = [];
    private array $actions = [];
    private array $bootErrors = [];
    private string $pluginsDir;
    private string $currentPluginId = '';

    private function __construct()
    {
        $this->pluginsDir = dirname(__DIR__) . '/plugins';
        if (!is_dir($this->pluginsDir)) {
            @mkdir($this->pluginsDir, 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ========================================================================
    // Core Methods
    // ========================================================================

    public function discoverPlugins(): void
    {
        $this->plugins = [];
        $this->activePlugins = [];

        if (!is_dir($this->pluginsDir)) {
            return;
        }

        $dirs = scandir($this->pluginsDir);
        if ($dirs === false) {
            return;
        }

        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestPath = $this->pluginsDir . '/' . $entry . '/plugin.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $json = file_get_contents($manifestPath);
            if ($json === false) {
                continue;
            }

            $manifest = json_decode($json, true);
            if (!is_array($manifest) || empty($manifest['id']) || empty($manifest['name']) || empty($manifest['version'])) {
                continue;
            }

            $manifest['_dir'] = $entry;
            $this->plugins[$manifest['id']] = $manifest;
        }

        try {
            $db = getDB();
            $result = $db->query('SELECT id, is_active, installed_at, updated_at FROM plugins');
            while ($row = $result->fetchArray()) {
                $pluginId = $row['id'];
                if (!isset($this->plugins[$pluginId])) {
                    continue;
                }
                $this->plugins[$pluginId]['_db'] = $row;
                if ((int)$row['is_active'] === 1) {
                    $this->activePlugins[$pluginId] = true;
                }
            }
        } catch (\Exception $e) {
            // plugins table may not exist yet
        }
    }

    public function loadActivePlugins(): void
    {
        foreach ($this->activePlugins as $id => $active) {
            $this->bootPlugin($id);
        }
    }

    public function bootPlugin(string $id): void
    {
        $bootFile = $this->pluginsDir . '/' . ($this->plugins[$id]['_dir'] ?? $id) . '/boot.php';
        if (!is_file($bootFile)) {
            return;
        }

        $this->currentPluginId = $id;

        try {
            (function (string $_bootFile, self $_plugin, string $_pluginDir, array $_pluginMeta): void {
                $plugin = $_plugin;
                $pluginDir = $_pluginDir;
                $pluginMeta = $_pluginMeta;
                require $_bootFile;
            })(
                $bootFile,
                $this,
                $this->pluginsDir . '/' . ($this->plugins[$id]['_dir'] ?? $id),
                $this->plugins[$id]
            );
        } catch (\Throwable $e) {
            $this->bootErrors[$id] = $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
            logError("Plugin boot failed: $id", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        $this->currentPluginId = '';
    }

    /**
     * Get all boot errors (plugin_id => error message).
     */
    public function getBootErrors(): array
    {
        return $this->bootErrors;
    }

    /**
     * Get boot error for a specific plugin, or null if none.
     */
    public function getBootError(string $id): ?string
    {
        return $this->bootErrors[$id] ?? null;
    }

    /**
     * Get changelog content for a plugin (from CHANGELOG.md in plugin directory).
     */
    public function getChangelog(string $id): ?string
    {
        $dir = $this->pluginsDir . '/' . ($this->plugins[$id]['_dir'] ?? $id);
        foreach (['CHANGELOG.md', 'changelog.md', 'CHANGES.md'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                return file_get_contents($path);
            }
        }
        return null;
    }

    /**
     * Get features registered by a plugin (routes, filters, actions, admin pages, styles, scripts).
     */
    public function getPluginFeatures(string $id): array
    {
        $features = [];
        foreach ($this->routes as $r) {
            if (($r['plugin'] ?? '') === $id) {
                $features[] = 'Route: ' . $r['method'] . ' ' . $r['pattern'];
            }
        }
        foreach ($this->filters as $hook => $callbacks) {
            foreach ($callbacks as $cb) {
                if (($cb['plugin'] ?? '') === $id) {
                    $features[] = 'Filter: ' . $hook;
                }
            }
        }
        foreach ($this->actions as $event => $callbacks) {
            foreach ($callbacks as $cb) {
                if (($cb['plugin'] ?? '') === $id) {
                    $features[] = 'Action: ' . $event;
                }
            }
        }
        foreach ($this->adminMenuItems as $item) {
            if (($item['plugin'] ?? '') === $id) {
                $features[] = 'Admin menu: ' . $item['label'];
            }
        }
        foreach ($this->styles as $s) {
            if (($s['plugin'] ?? '') === $id) {
                $features[] = 'Stylesheet: ' . $s['path'];
            }
        }
        foreach ($this->scripts as $s) {
            if (($s['plugin'] ?? '') === $id) {
                $features[] = 'Script: ' . $s['path'];
            }
        }
        return $features;
    }

    // ========================================================================
    // Lifecycle Methods
    // ========================================================================

    public function enablePlugin(string $id): bool
    {
        if (!isset($this->plugins[$id])) {
            return false;
        }

        $manifest = $this->plugins[$id];

        if (defined('MESHSILO_VERSION') && !empty($manifest['min_silo_version'])) {
            if (version_compare(MESHSILO_VERSION, $manifest['min_silo_version'], '<')) {
                return false;
            }
        }

        if (!empty($manifest['requires_plugins']) && is_array($manifest['requires_plugins'])) {
            foreach ($manifest['requires_plugins'] as $dep) {
                if (!$this->isPluginActive($dep)) {
                    return false;
                }
            }
        }

        try {
            $db = getDB();
            $type = $db->getType();

            if ($type === 'mysql') {
                $stmt = $db->prepare(
                    'INSERT INTO plugins (id, is_active, installed_at, updated_at) '
                    . 'VALUES (:id, 1, NOW(), NOW()) '
                    . 'ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()'
                );
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO plugins (id, is_active, installed_at, updated_at) '
                    . 'VALUES (:id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                    . 'ON CONFLICT(id) DO UPDATE SET is_active = 1, updated_at = CURRENT_TIMESTAMP'
                );
            }
            $stmt->execute([':id' => $id]);

            $this->activePlugins[$id] = true;

            $migrationsFile = $this->pluginsDir . '/' . ($manifest['_dir'] ?? $id) . '/migrations.php';
            if (is_file($migrationsFile)) {
                $this->runPluginMigrations($id);
            }

            Router::clearCache();
            @unlink(dirname(__DIR__) . '/storage/cache/classmap.php');
            logInfo("Plugin enabled: $id");

            return true;
        } catch (\Exception $e) {
            logError("Failed to enable plugin: $id", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function disablePlugin(string $id): bool
    {
        try {
            // Check if other active plugins depend on this one
            foreach ($this->activePlugins as $otherId => $active) {
                if ($otherId === $id) {
                    continue;
                }
                $otherManifest = $this->plugins[$otherId] ?? [];
                $requires = $otherManifest['requires_plugins'] ?? [];
                if (is_array($requires) && in_array($id, $requires, true)) {
                    return false;
                }
            }

            $db = getDB();
            $type = $db->getType();

            if ($type === 'mysql') {
                $stmt = $db->prepare('UPDATE plugins SET is_active = 0, updated_at = NOW() WHERE id = :id');
            } else {
                $stmt = $db->prepare('UPDATE plugins SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            }
            $stmt->execute([':id' => $id]);

            unset($this->activePlugins[$id]);

            Router::clearCache();
            @unlink(dirname(__DIR__) . '/storage/cache/classmap.php');
            logInfo("Plugin disabled: $id");

            return true;
        } catch (\Exception $e) {
            logError("Failed to disable plugin: $id", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function installPlugin(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'ZipArchive extension not available'];
        }

        // Limit plugin ZIP to 50MB
        if (filesize($zipPath) > 50 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Plugin ZIP exceeds 50MB size limit'];
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            return ['success' => false, 'error' => 'Failed to open zip file'];
        }

        $manifestIndex = null;
        $prefix = '';

        // Look for plugin.json at root level
        $manifestIndex = $zip->locateName('plugin.json');
        if ($manifestIndex === false) {
            // Check inside a single subdirectory
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^([^/]+)/plugin\.json$#', $name, $m)) {
                    $manifestIndex = $i;
                    $prefix = $m[1] . '/';
                    break;
                }
            }
        }

        if ($manifestIndex === false) {
            $zip->close();
            return ['success' => false, 'error' => 'No plugin.json found in archive'];
        }

        $manifestJson = $zip->getFromIndex($manifestIndex);
        if ($manifestJson === false) {
            $zip->close();
            return ['success' => false, 'error' => 'Failed to read plugin.json'];
        }

        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest) || empty($manifest['id']) || empty($manifest['name']) || empty($manifest['version'])) {
            $zip->close();
            return ['success' => false, 'error' => 'Invalid plugin.json: missing required fields (id, name, version)'];
        }

        $pluginId = $this->sanitizePluginId($manifest['id']);
        if ($pluginId === '' || $pluginId !== $manifest['id']) {
            $zip->close();
            return ['success' => false, 'error' => 'Invalid plugin ID: only lowercase alphanumeric and hyphens allowed'];
        }

        $isUpgrade = isset($this->plugins[$pluginId]);
        $targetDir = $this->pluginsDir . '/' . $pluginId;
        $backupDir = null;

        $tempDir = sys_get_temp_dir() . '/meshsilo_plugin_' . bin2hex(random_bytes(8));
        @mkdir($tempDir, 0755, true);

        // Validate zip entries for path traversal before extraction
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }
            if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                $zip->close();
                self::recursiveDelete($tempDir);
                return ['success' => false, 'error' => 'Archive contains unsafe paths'];
            }
        }

        $zip->extractTo($tempDir);
        $zip->close();

        $sourceDir = $prefix !== '' ? $tempDir . '/' . rtrim($prefix, '/') : $tempDir;

        // If upgrading, backup existing plugin directory
        if ($isUpgrade && is_dir($targetDir)) {
            $backupDir = $this->pluginsDir . '/.backup-' . $pluginId . '-' . time();
            if (!rename($targetDir, $backupDir)) {
                self::recursiveDelete($tempDir);
                return ['success' => false, 'error' => 'Failed to backup existing plugin for upgrade'];
            }
        }

        // Move new files into place
        if (!rename($sourceDir, $targetDir)) {
            // Restore backup on failure
            if ($backupDir && is_dir($backupDir)) {
                rename($backupDir, $targetDir);
            }
            self::recursiveDelete($tempDir);
            return ['success' => false, 'error' => 'Failed to move plugin to plugins directory'];
        }

        // Clean up temp dir and backup
        if ($prefix !== '' && is_dir($tempDir)) {
            self::recursiveDelete($tempDir);
        }
        if ($backupDir && is_dir($backupDir)) {
            self::recursiveDelete($backupDir);
        }

        // Update database (insert or update)
        try {
            $db = getDB();
            $type = $db->getType();

            if ($type === 'mysql') {
                $stmt = $db->prepare(
                    'INSERT INTO plugins (id, name, version, description, author, is_active, installed_at, updated_at) '
                    . 'VALUES (:id, :name, :version, :desc, :author, ' . ($isUpgrade ? '(SELECT is_active FROM (SELECT is_active FROM plugins WHERE id = :id2) t)' : '0') . ', NOW(), NOW()) '
                    . 'ON DUPLICATE KEY UPDATE name = :name2, version = :version2, description = :desc2, author = :author2, updated_at = NOW()'
                );
                $stmt->execute([
                    ':id' => $pluginId, ':name' => $manifest['name'], ':version' => $manifest['version'],
                    ':desc' => $manifest['description'] ?? '', ':author' => $manifest['author'] ?? '',
                    ':id2' => $pluginId, ':name2' => $manifest['name'], ':version2' => $manifest['version'],
                    ':desc2' => $manifest['description'] ?? '', ':author2' => $manifest['author'] ?? '',
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO plugins (id, name, version, description, author, is_active, installed_at, updated_at) '
                    . 'VALUES (:id, :name, :version, :desc, :author, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                    . 'ON CONFLICT(id) DO UPDATE SET name = :name2, version = :version2, description = :desc2, author = :author2, updated_at = CURRENT_TIMESTAMP'
                );
                $stmt->execute([
                    ':id' => $pluginId, ':name' => $manifest['name'], ':version' => $manifest['version'],
                    ':desc' => $manifest['description'] ?? '', ':author' => $manifest['author'] ?? '',
                    ':name2' => $manifest['name'], ':version2' => $manifest['version'],
                    ':desc2' => $manifest['description'] ?? '', ':author2' => $manifest['author'] ?? '',
                ]);
            }
        } catch (\Exception $e) {
            logError("Failed to upsert plugin DB row: $pluginId", ['error' => $e->getMessage()]);
        }

        // Run migrations if present
        if ($isUpgrade) {
            $migrationsFile = $targetDir . '/migrations.php';
            if (is_file($migrationsFile)) {
                $this->runPluginMigrations($pluginId);
            }
        }

        $this->plugins[$pluginId] = $manifest;
        $this->plugins[$pluginId]['_dir'] = $pluginId;

        logInfo($isUpgrade ? "Plugin upgraded: $pluginId" : "Plugin installed: $pluginId");

        return ['success' => true, 'plugin' => $manifest, 'upgraded' => $isUpgrade];
    }

    public function uninstallPlugin(string $id): bool
    {
        if ($this->isPluginActive($id)) {
            if (!$this->disablePlugin($id)) {
                return false;
            }
        }

        try {
            $db = getDB();
            $stmt = $db->prepare('DELETE FROM plugins WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } catch (\Exception $e) {
            logError("Failed to delete plugin DB row: $id", ['error' => $e->getMessage()]);
        }

        $pluginDir = $this->pluginsDir . '/' . ($this->plugins[$id]['_dir'] ?? $id);
        if (is_dir($pluginDir)) {
            self::recursiveDelete($pluginDir);
        }

        unset($this->plugins[$id]);
        unset($this->activePlugins[$id]);

        Router::clearCache();
        @unlink(dirname(__DIR__) . '/storage/cache/classmap.php');
        logInfo("Plugin uninstalled: $id");

        return true;
    }

    // ========================================================================
    // Plugin API Methods (called by plugins during boot)
    // ========================================================================

    public function addRoute(string $method, string $pattern, $handler, ?string $name = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'name' => $name,
            'plugin' => $this->currentPluginId,
        ];
    }

    public function addAdminMenuItem(string $category, string $label, string $slug, ?string $routeName = null): void
    {
        $this->adminMenuItems[] = [
            'category' => $category,
            'label' => $label,
            'slug' => $slug,
            'route' => $routeName,
            'plugin' => $this->currentPluginId,
        ];
    }

    public function addAdminPage(string $slug, string $file): void
    {
        $this->adminPages[$slug] = $file;
    }

    public function addStylesheet(string $pluginId, string $relativePath): void
    {
        $this->styles[] = ['plugin' => $pluginId, 'path' => $relativePath];
    }

    public function addScript(string $pluginId, string $relativePath): void
    {
        $this->scripts[] = ['plugin' => $pluginId, 'path' => $relativePath];
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][] = ['callback' => $callback, 'priority' => $priority, 'plugin' => $this->currentPluginId];
        usort($this->filters[$hook], fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Register an action hook (event listener).
     * Unlike filters, actions don't return/modify a value — they just execute.
     */
    public function addAction(string $event, callable $callback, int $priority = 10): void
    {
        $this->actions[$event][] = ['callback' => $callback, 'priority' => $priority, 'plugin' => $this->currentPluginId];
        usort($this->actions[$event], fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Fire an action event. All registered listeners are called.
     */
    public static function doAction(string $event, mixed ...$args): void
    {
        $instance = self::getInstance();
        if (empty($instance->actions[$event])) {
            return;
        }

        foreach ($instance->actions[$event] as $action) {
            try {
                ($action['callback'])(...$args);
            } catch (\Throwable $e) {
                logError("Plugin action error on '{$event}'", [
                    'plugin' => $action['plugin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get a plugin setting value.
     */
    public function getSetting(string $pluginId, string $key, mixed $default = null): mixed
    {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT settings FROM plugins WHERE id = :id');
            $stmt->execute([':id' => $pluginId]);
            $row = $stmt->fetch();
            if (!$row || empty($row['settings'])) {
                return $default;
            }
            $settings = json_decode($row['settings'], true);
            return $settings[$key] ?? $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Set a plugin setting value.
     */
    public function setSetting(string $pluginId, string $key, mixed $value): bool
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
            return true;
        } catch (\Exception $e) {
            logError("Failed to save plugin setting", ['plugin' => $pluginId, 'key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get all settings for a plugin.
     */
    public function getSettings(string $pluginId): array
    {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT settings FROM plugins WHERE id = :id');
            $stmt->execute([':id' => $pluginId]);
            $row = $stmt->fetch();
            if (!$row || empty($row['settings'])) {
                return [];
            }
            $settings = json_decode($row['settings'], true);
            return is_array($settings) ? $settings : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get plugins that have settings declarations in their manifest.
     */
    public function getPluginsWithSettings(): array
    {
        $result = [];
        foreach ($this->activePlugins as $id => $active) {
            $manifest = $this->plugins[$id] ?? [];
            if (!empty($manifest['settings']) && is_array($manifest['settings'])) {
                $result[$id] = $manifest;
            }
        }
        return $result;
    }

    /**
     * Render settings form HTML for a plugin.
     * Settings are declared in plugin.json: "settings": [{"key":"x","label":"X","type":"text","default":"","description":"..."}]
     * Supported types: text, password, textarea, checkbox, select, number
     */
    public function renderPluginSettingsForm(string $pluginId): string
    {
        $manifest = $this->plugins[$pluginId] ?? [];
        $fields = $manifest['settings'] ?? [];
        if (empty($fields) || !is_array($fields)) {
            return '';
        }

        $savedSettings = $this->getSettings($pluginId);
        $html = '';

        foreach ($fields as $field) {
            $key = $field['key'] ?? '';
            $label = htmlspecialchars($field['label'] ?? $key);
            $type = $field['type'] ?? 'text';
            $default = $field['default'] ?? '';
            $description = htmlspecialchars($field['description'] ?? '');
            $value = $savedSettings[$key] ?? $default;
            $inputName = 'plugin_settings[' . htmlspecialchars($key) . ']';

            $html .= '<div class="form-group">';
            $html .= '<label for="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '">' . $label . '</label>';

            switch ($type) {
                case 'textarea':
                    $html .= '<textarea id="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '" name="' . $inputName . '" class="form-input" rows="4">' . htmlspecialchars($value) . '</textarea>';
                    break;

                case 'checkbox':
                    $checked = ($value === '1' || $value === true) ? ' checked' : '';
                    $html .= '<input type="hidden" name="' . $inputName . '" value="0">';
                    $html .= '<label class="toggle-label"><input type="checkbox" id="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '" name="' . $inputName . '" value="1"' . $checked . '><span class="toggle-switch"></span><span>' . $label . '</span></label>';
                    break;

                case 'select':
                    $html .= '<select id="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '" name="' . $inputName . '" class="form-input">';
                    foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                        $selected = ((string)$value === (string)$optVal) ? ' selected' : '';
                        $html .= '<option value="' . htmlspecialchars($optVal) . '"' . $selected . '>' . htmlspecialchars($optLabel) . '</option>';
                    }
                    $html .= '</select>';
                    break;

                case 'number':
                    $min = isset($field['min']) ? ' min="' . (int)$field['min'] . '"' : '';
                    $max = isset($field['max']) ? ' max="' . (int)$field['max'] . '"' : '';
                    $html .= '<input type="number" id="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '" name="' . $inputName . '" class="form-input" value="' . htmlspecialchars($value) . '"' . $min . $max . '>';
                    break;

                case 'password':
                    $html .= '<input type="password" id="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '" name="' . $inputName . '" class="form-input" value="' . htmlspecialchars($value) . '" autocomplete="off">';
                    break;

                default: // text
                    $html .= '<input type="text" id="plugin-' . htmlspecialchars($pluginId) . '-' . htmlspecialchars($key) . '" name="' . $inputName . '" class="form-input" value="' . htmlspecialchars($value) . '">';
                    break;
            }

            if ($description) {
                $html .= '<p class="form-help">' . $description . '</p>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Save plugin settings from form POST data.
     */
    public function savePluginSettings(string $pluginId, array $postData): bool
    {
        $manifest = $this->plugins[$pluginId] ?? [];
        $fields = $manifest['settings'] ?? [];
        if (empty($fields)) {
            return false;
        }

        // Only save declared fields (prevent arbitrary key injection)
        $allowedKeys = array_column($fields, 'key');
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $postData)) {
                $this->setSetting($pluginId, $key, $postData[$key]);
            }
        }

        return true;
    }

    // ========================================================================
    // Integration Methods (called by existing MeshSilo code)
    // ========================================================================

    public function registerRoutes(Router $router): void
    {
        foreach ($this->routes as $route) {
            $method = strtolower($route['method']);
            if (method_exists($router, $method)) {
                $router->$method($route['pattern'], $route['handler'], $route['name']);
            } elseif ($route['method'] === 'ANY') {
                $router->any($route['pattern'], $route['handler'], $route['name']);
            }
        }

        foreach ($this->adminPages as $slug => $file) {
            $safeSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
            $resolvedFile = $file;
            $manager = $this;

            $handler = function () use ($safeSlug, $resolvedFile, $manager): void {
                $adminPage = $safeSlug;
                $pluginManager = $manager;
                require $resolvedFile;
            };

            $router->group(['prefix' => '/admin', 'middleware' => ['admin']], function (Router $router) use ($safeSlug, $handler): void {
                $router->get('/plugin/' . $safeSlug, $handler, 'admin.plugin.' . $safeSlug);
                $router->post('/plugin/' . $safeSlug, $handler, 'admin.plugin.' . $safeSlug . '.post');
            });
        }
    }

    public function renderStyles(): string
    {
        $html = '';
        foreach ($this->styles as $style) {
            $pluginId = htmlspecialchars($style['plugin'], ENT_QUOTES, 'UTF-8');
            $path = htmlspecialchars($style['path'], ENT_QUOTES, 'UTF-8');
            $version = $this->getAssetVersion($style['plugin'], $style['path']);
            $html .= '<link rel="stylesheet" href="/plugin-assets/' . $pluginId . '/' . $path . '?v=' . $version . '">' . "\n";
        }
        return $html;
    }

    public function renderScripts(): string
    {
        $html = '';
        foreach ($this->scripts as $script) {
            $pluginId = htmlspecialchars($script['plugin'], ENT_QUOTES, 'UTF-8');
            $path = htmlspecialchars($script['path'], ENT_QUOTES, 'UTF-8');
            $version = $this->getAssetVersion($script['plugin'], $script['path']);
            $html .= '<script src="/plugin-assets/' . $pluginId . '/' . $path . '?v=' . $version . '"></script>' . "\n";
        }
        return $html;
    }

    private function getAssetVersion(string $pluginId, string $relativePath): string
    {
        $file = $this->pluginsDir . '/' . ($this->plugins[$pluginId]['_dir'] ?? $pluginId) . '/assets/' . $relativePath;
        if (file_exists($file)) {
            return (string)filemtime($file);
        }
        // Fall back to plugin directory mtime
        $dir = $this->pluginsDir . '/' . ($this->plugins[$pluginId]['_dir'] ?? $pluginId);
        return is_dir($dir) ? (string)filemtime($dir) : '1';
    }

    public function getAdminMenuItems(): array
    {
        $grouped = [];
        foreach ($this->adminMenuItems as $item) {
            $grouped[$item['category']][] = $item;
        }
        return $grouped;
    }

    public function getAdminPages(): array
    {
        return $this->adminPages;
    }

    public static function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $instance = self::getInstance();
        if (empty($instance->filters[$hook])) {
            return $value;
        }

        foreach ($instance->filters[$hook] as $filter) {
            try {
                $value = ($filter['callback'])($value, ...$args);
            } catch (\Throwable $e) {
                logError("Plugin filter error on '{$hook}'", [
                    'plugin' => $filter['plugin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                // Continue with unmodified value — don't let one broken plugin crash the page
            }
        }

        return $value;
    }

    // ========================================================================
    // Repository Methods
    // ========================================================================

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

    public function addRepository(string $name, string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
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

    public function fetchRegistry(string $repoUrl): ?array
    {
        // Validate URL scheme to prevent SSRF
        $scheme = parse_url($repoUrl, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme ?? ''), ['http', 'https'], true)) {
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

    public function getAvailablePlugins(): array
    {
        $repos = $this->getRepositories();
        $available = [];

        foreach ($repos as $repo) {
            if (empty($repo['registry_cache'])) {
                continue;
            }

            $registry = json_decode($repo['registry_cache'], true);
            if (!is_array($registry) || !isset($registry['plugins'])) {
                continue;
            }

            // Registry-level source info (inherited by all plugins)
            $registrySource = $registry['source'] ?? null;

            foreach ($registry['plugins'] as $plugin) {
                if (!isset($plugin['id'])) {
                    continue;
                }
                $plugin['_installed'] = isset($this->plugins[$plugin['id']]);
                $plugin['_repo'] = $repo['name'] ?? $repo['url'];

                // Version comparison
                if ($plugin['_installed']) {
                    $installedVersion = $this->plugins[$plugin['id']]['version'] ?? '0.0.0';
                    $availableVersion = $plugin['version'] ?? '0.0.0';
                    $plugin['_installed_version'] = $installedVersion;
                    $plugin['_update_available'] = version_compare($availableVersion, $installedVersion, '>');
                } else {
                    $plugin['_installed_version'] = null;
                    $plugin['_update_available'] = false;
                }

                // Attach source info: plugin-level overrides registry-level
                if (!isset($plugin['_source']) && $registrySource && !empty($plugin['path'])) {
                    $plugin['_source'] = array_merge($registrySource, ['path' => $plugin['path']]);
                }

                $available[$plugin['id']] = $plugin;
            }
        }

        return $available;
    }

    public function installFromRepo(string $pluginId, array $source): array
    {
        $type = $source['type'] ?? 'github';
        $repo = $source['repo'] ?? '';
        $branch = $source['branch'] ?? 'main';
        $path = $source['path'] ?? '';

        if ($type !== 'github' || empty($repo) || empty($path)) {
            return ['success' => false, 'error' => 'Invalid source configuration'];
        }

        // Validate repo format (owner/name)
        if (!preg_match('/^[a-zA-Z0-9\-_.]+\/[a-zA-Z0-9\-_.]+$/', $repo)) {
            return ['success' => false, 'error' => 'Invalid repository format'];
        }

        $pluginsDir = dirname(__DIR__) . '/plugins';
        if (!is_dir($pluginsDir)) {
            mkdir($pluginsDir, 0755, true);
        }

        $destDir = $pluginsDir . '/' . $pluginId;

        // Download to temp directory first (atomic install)
        $tempDir = sys_get_temp_dir() . '/meshsilo-plugin-' . $pluginId . '-' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $this->downloadGitHubDirectory($repo, $branch, $path, $tempDir);
        } catch (\Exception $e) {
            $this->recursiveDelete($tempDir);
            return ['success' => false, 'error' => 'Failed to download plugin: ' . $e->getMessage()];
        }

        // Validate the downloaded plugin has a manifest
        $manifestFile = $tempDir . '/plugin.json';
        if (!file_exists($manifestFile)) {
            $this->recursiveDelete($tempDir);
            return ['success' => false, 'error' => 'Downloaded plugin is missing plugin.json'];
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($manifest) || empty($manifest['id'])) {
            $this->recursiveDelete($tempDir);
            return ['success' => false, 'error' => 'Invalid plugin.json in downloaded plugin'];
        }

        // All files downloaded and validated — swap into place
        if (is_dir($destDir)) {
            $this->recursiveDelete($destDir);
        }
        rename($tempDir, $destDir);

        // Register in database
        try {
            $db = getDB();
            $type = $db->getType();

            if ($type === 'mysql') {
                $stmt = $db->prepare(
                    'INSERT INTO plugins (id, name, version, description, author, is_active, settings, installed_at, updated_at) '
                    . 'VALUES (:id, :name, :version, :desc, :author, 0, \'{}\', NOW(), NOW()) '
                    . 'ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), '
                    . 'description = VALUES(description), author = VALUES(author), updated_at = NOW()'
                );
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO plugins (id, name, version, description, author, is_active, settings, installed_at, updated_at) '
                    . 'VALUES (:id, :name, :version, :desc, :author, 0, \'{}\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                    . 'ON CONFLICT(id) DO UPDATE SET name = :name, version = :version, '
                    . 'description = :desc, author = :author, updated_at = CURRENT_TIMESTAMP'
                );
            }

            $stmt->execute([
                ':id' => $manifest['id'],
                ':name' => $manifest['name'] ?? $manifest['id'],
                ':version' => $manifest['version'] ?? '0.0.0',
                ':desc' => $manifest['description'] ?? '',
                ':author' => $manifest['author'] ?? '',
            ]);
        } catch (\Exception $e) {
            logWarning('Failed to register plugin in database', ['error' => $e->getMessage()]);
        }

        // Re-discover plugins
        $this->discoverPlugins();

        return [
            'success' => true,
            'plugin' => $manifest,
        ];
    }

    /**
     * Recursively download a directory from a GitHub repository using the Contents API.
     */
    private function downloadGitHubDirectory(string $repo, string $branch, string $path, string $destDir): void
    {
        $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . ltrim($path, '/') . '?ref=' . urlencode($branch);

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header' => "User-Agent: MeshSilo/" . (defined('MESHSILO_VERSION') ? MESHSILO_VERSION : '1.0.0') . "\r\n"
                           . "Accept: application/vnd.github.v3+json\r\n",
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            throw new \RuntimeException('Failed to fetch directory listing from GitHub (network error or rate limit)');
        }

        $items = json_decode($response, true);
        if (!is_array($items)) {
            throw new \RuntimeException('Invalid response from GitHub API');
        }

        // GitHub API errors return {"message": "..."} — detect and throw
        if (isset($items['message'])) {
            throw new \RuntimeException('GitHub API error: ' . $items['message']);
        }

        // Ensure we have a sequential array of items, not an associative error object
        if (empty($items) || !isset($items[0])) {
            throw new \RuntimeException('Empty directory listing from GitHub for: ' . $path);
        }

        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            $itemType = $item['type'] ?? '';

            // Sanitize file name
            if ($name === '' || str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
                continue;
            }

            if ($itemType === 'file') {
                $downloadUrl = $item['download_url'] ?? null;
                if ($downloadUrl === null) {
                    continue;
                }

                // Validate URL scheme
                $scheme = parse_url($downloadUrl, PHP_URL_SCHEME);
                if (!in_array(strtolower($scheme ?? ''), ['http', 'https'], true)) {
                    continue;
                }

                $fileContent = @file_get_contents($downloadUrl, false, $context);
                if ($fileContent === false) {
                    throw new \RuntimeException('Failed to download file: ' . $name);
                }

                // Ensure parent directory exists
                $fileDir = dirname($destDir . '/' . $name);
                if (!is_dir($fileDir)) {
                    mkdir($fileDir, 0755, true);
                }
                file_put_contents($destDir . '/' . $name, $fileContent);
            } elseif ($itemType === 'dir') {
                $subDir = $destDir . '/' . $name;
                if (!is_dir($subDir)) {
                    mkdir($subDir, 0755, true);
                }
                $this->downloadGitHubDirectory($repo, $branch, $path . '/' . $name, $subDir);
            }
        }
    }

    public function checkUpdates(): array
    {
        $available = $this->getAvailablePlugins();
        $updates = [];

        foreach ($this->plugins as $id => $installed) {
            if (!isset($available[$id])) {
                continue;
            }

            $remoteVersion = $available[$id]['version'] ?? '0.0.0';
            $localVersion = $installed['version'] ?? '0.0.0';

            if (version_compare($remoteVersion, $localVersion, '>')) {
                $updates[$id] = [
                    'id' => $id,
                    'name' => $installed['name'],
                    'current_version' => $localVersion,
                    'available_version' => $remoteVersion,
                    '_source' => $available[$id]['_source'] ?? null,
                ];
            }
        }

        return $updates;
    }

    // ========================================================================
    // Info Methods
    // ========================================================================

    public function getPlugin(string $id): ?array
    {
        if (!isset($this->plugins[$id])) {
            return null;
        }

        $plugin = $this->plugins[$id];
        $plugin['is_active'] = isset($this->activePlugins[$id]);
        return $plugin;
    }

    public function getAllPlugins(): array
    {
        $result = [];
        foreach ($this->plugins as $id => $plugin) {
            $plugin['is_active'] = isset($this->activePlugins[$id]);
            $result[$id] = $plugin;
        }
        return $result;
    }

    public function getActivePlugins(): array
    {
        $result = [];
        foreach ($this->activePlugins as $id => $active) {
            if (isset($this->plugins[$id])) {
                $plugin = $this->plugins[$id];
                $plugin['is_active'] = true;
                $result[$id] = $plugin;
            }
        }
        return $result;
    }

    public function isPluginActive(string $id): bool
    {
        return isset($this->activePlugins[$id]);
    }

    public function runPluginMigrations(string $id): array
    {
        $migrationsFile = $this->pluginsDir . '/' . ($this->plugins[$id]['_dir'] ?? $id) . '/migrations.php';
        $stats = ['run' => 0, 'skipped' => 0];

        if (!is_file($migrationsFile)) {
            return $stats;
        }

        try {
            $migrations = require $migrationsFile;
            if (!is_array($migrations)) {
                return $stats;
            }

            $db = getDB();
            foreach ($migrations as $migration) {
                if (!isset($migration['check'], $migration['apply'])) {
                    continue;
                }

                $needed = !($migration['check'])($db);
                if ($needed) {
                    ($migration['apply'])($db);
                    $stats['run']++;
                } else {
                    $stats['skipped']++;
                }
            }
        } catch (\Exception $e) {
            logError("Plugin migration failed: $id", ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private static function recursiveDelete(string $dir): bool
    {
        $pluginsDir = dirname(__DIR__) . '/plugins';
        $realDir = realpath($dir);
        $realPluginsDir = realpath($pluginsDir);

        if ($realDir === false) {
            return false;
        }

        // Safety check: path must be inside the plugins directory or a temp directory
        $inPlugins = $realPluginsDir !== false && str_starts_with($realDir, $realPluginsDir . DIRECTORY_SEPARATOR);
        $inTemp = str_starts_with($realDir, realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR);

        if (!$inPlugins && !$inTemp) {
            return false;
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    private function sanitizePluginId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9\-]/', '', $id);
        return substr($id, 0, 100);
    }

    public function getPluginsDir(): string
    {
        return $this->pluginsDir;
    }
}
