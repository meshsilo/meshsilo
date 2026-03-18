<?php

trait DebugPanels
{
    // =========================================================================
    // DEBUG BAR RENDERING
    // =========================================================================

    /**
     * Render debug bar HTML
     */
    public static function renderBar(): string
    {
        if (!self::isEnabled() || !self::$config['show_bar']) {
            return '';
        }

        $metrics = self::getMetrics();
        $errors = count(array_filter(self::$logs, fn($l) => in_array($l['level'], ['error', 'fatal'])));
        $warnings = count(array_filter(self::$logs, fn($l) => $l['level'] === 'warning'));

        $durationMs = round($metrics['duration'] * 1000);
        $memory = self::formatBytes($metrics['memory_peak']);
        $queryTime = array_sum(array_column(self::$queries, 'duration'));
        $queryTimeMs = round($queryTime * 1000, 1);

        $errorColor = $errors > 0 ? '#ef4444' : ($warnings > 0 ? '#f59e0b' : '#22c55e');
        $issueCount = $errors + $warnings;

        // Build panels HTML
        $logsHtml = self::renderLogsPanel();
        $queriesHtml = self::renderQueriesPanel();
        $sessionHtml = self::renderSessionPanel();
        $requestHtml = self::renderRequestPanel();
        $configHtml = self::renderConfigPanel();
        $timelineHtml = self::renderTimelinePanel();

        return <<<HTML
<div id="debug-bar" style="
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #1a1a2e;
    color: #e8e8e8;
    font-family: 'Fira Code', 'Monaco', 'Consolas', monospace;
    font-size: 12px;
    z-index: 99999;
    border-top: 2px solid #6366f1;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
">
    <div style="display: flex; align-items: center; padding: 8px 15px; gap: 20px; flex-wrap: wrap;">
        <span style="color: #6366f1; font-weight: bold; display: flex; align-items: center; gap: 5px;">
            <span style="font-size: 16px;">🔧</span> DEBUG
        </span>
        <span title="Total request time" style="cursor: help;">⏱️ {$durationMs}ms</span>
        <span title="Peak memory usage" style="cursor: help;">💾 {$memory}</span>
        <span title="Database queries ({$queryTimeMs}ms)" style="cursor: help;">🗄️ {$metrics['query_count']} queries</span>
        <span title="{$errors} errors, {$warnings} warnings" style="color: {$errorColor}; cursor: help;">⚠️ {$issueCount} issues</span>
        <span title="Log entries" style="cursor: help;">📝 {$metrics['log_count']} logs</span>

        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button type="button" onclick="debugTogglePanel('logs')" class="debug-tab-btn" data-panel="logs">Logs</button>
            <button type="button" onclick="debugTogglePanel('queries')" class="debug-tab-btn" data-panel="queries">Queries</button>
            <button type="button" onclick="debugTogglePanel('session')" class="debug-tab-btn" data-panel="session">Session</button>
            <button type="button" onclick="debugTogglePanel('request')" class="debug-tab-btn" data-panel="request">Request</button>
            <button type="button" onclick="debugTogglePanel('config')" class="debug-tab-btn" data-panel="config">Config</button>
            <button type="button" onclick="debugTogglePanel('timeline')" class="debug-tab-btn" data-panel="timeline">Timeline</button>
            <button type="button" onclick="document.getElementById('debug-bar').style.display='none'"
                    style="background: transparent; border: none; color: #888; cursor: pointer; font-size: 16px; margin-left: 10px;">✕</button>
        </div>
    </div>

    <div id="debug-panel-logs" class="debug-panel" style="display: none;">{$logsHtml}</div>
    <div id="debug-panel-queries" class="debug-panel" style="display: none;">{$queriesHtml}</div>
    <div id="debug-panel-session" class="debug-panel" style="display: none;">{$sessionHtml}</div>
    <div id="debug-panel-request" class="debug-panel" style="display: none;">{$requestHtml}</div>
    <div id="debug-panel-config" class="debug-panel" style="display: none;">{$configHtml}</div>
    <div id="debug-panel-timeline" class="debug-panel" style="display: none;">{$timelineHtml}</div>
</div>

<style>
.debug-tab-btn {
    background: #2d2d44;
    border: 1px solid #3d3d5c;
    color: #a0a0a0;
    padding: 4px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-family: inherit;
    font-size: 11px;
    transition: all 0.2s;
}
.debug-tab-btn:hover, .debug-tab-btn.active {
    background: #6366f1;
    border-color: #6366f1;
    color: white;
}
.debug-panel {
    max-height: 350px;
    overflow: auto;
    border-top: 1px solid #2d2d44;
    padding: 15px;
    background: #0f0f1a;
}
.debug-section {
    margin-bottom: 15px;
}
.debug-section h4 {
    color: #6366f1;
    margin: 0 0 10px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.debug-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}
.debug-table th, .debug-table td {
    padding: 6px 10px;
    text-align: left;
    border-bottom: 1px solid #2d2d44;
}
.debug-table th {
    color: #888;
    font-weight: normal;
    background: #1a1a2e;
}
.debug-table tr:hover td {
    background: #1a1a2e;
}
.debug-log-entry {
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 3px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.debug-log-entry:hover {
    background: #1a1a2e;
}
.debug-log-time {
    color: #666;
    flex-shrink: 0;
    width: 60px;
}
.debug-log-level {
    flex-shrink: 0;
    width: 70px;
    font-weight: bold;
}
.debug-log-message {
    flex: 1;
    word-break: break-word;
}
.debug-log-context {
    color: #666;
    font-size: 10px;
    margin-top: 2px;
}
.debug-log-location {
    color: #555;
    font-size: 10px;
    flex-shrink: 0;
}
</style>

<script>
function debugTogglePanel(name) {
    document.querySelectorAll('.debug-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.debug-tab-btn').forEach(b => b.classList.remove('active'));

    const panel = document.getElementById('debug-panel-' + name);
    const btn = document.querySelector('[data-panel="' + name + '"]');

    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        btn.classList.add('active');
    }
}
</script>
HTML;
    }

    private static function renderLogsPanel(): string
    {
        $html = '<div class="debug-section">';

        if (empty(self::$logs)) {
            $html .= '<p style="color: #666;">No log entries</p>';
        } else {
            foreach (self::$logs as $log) {
                $levelInfo = self::LEVELS[$log['level']] ?? ['color' => '#888', 'icon' => '•'];
                $context = !empty($log['context'])
                    ? '<div class="debug-log-context">' . htmlspecialchars(json_encode($log['context'], JSON_UNESCAPED_SLASHES)) . '</div>'
                    : '';
                $location = $log['file'] ? "{$log['file']}:{$log['line']}" : '';

                $html .= sprintf(
                    '<div class="debug-log-entry">
                        <span class="debug-log-time">+%.3fs</span>
                        <span class="debug-log-level" style="color: %s;">%s %s</span>
                        <div class="debug-log-message">%s%s</div>
                        <span class="debug-log-location">%s</span>
                    </div>',
                    $log['time'],
                    $levelInfo['color'],
                    $levelInfo['icon'],
                    strtoupper($log['level']),
                    htmlspecialchars($log['message']),
                    $context,
                    $location
                );
            }
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderQueriesPanel(): string
    {
        $html = '<div class="debug-section">';
        $html .= '<h4>🗄️ Database Queries (' . self::$queryCount . ')</h4>';

        if (empty(self::$queries)) {
            $html .= '<p style="color: #666;">No queries executed</p>';
        } else {
            $html .= '<table class="debug-table"><thead><tr><th scope="col">#</th><th scope="col">Time</th><th scope="col">Duration</th><th scope="col">Query</th></tr></thead><tbody>';

            foreach (self::$queries as $q) {
                $duration = $q['duration'] ? round($q['duration'] * 1000, 2) . 'ms' : '-';
                $durationColor = ($q['duration'] ?? 0) > 0.1 ? '#ef4444' : '#22c55e';
                $sql = htmlspecialchars(substr($q['sql'], 0, 300));
                if (strlen($q['sql']) > 300) {
                    $sql .= '...';
                }

                $html .= sprintf(
                    '<tr>
                        <td>%d</td>
                        <td>+%.3fs</td>
                        <td style="color: %s;">%s</td>
                        <td><code style="color: #22c55e;">%s</code></td>
                    </tr>',
                    $q['index'],
                    $q['time'],
                    $durationColor,
                    $duration,
                    $sql
                );
            }

            $html .= '</tbody></table>';

            $totalTime = array_sum(array_column(self::$queries, 'duration'));
            $html .= '<p style="margin-top: 10px; color: #888;">Total query time: ' . round($totalTime * 1000, 2) . 'ms</p>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderSessionPanel(): string
    {
        $html = '<div class="debug-section">';
        $html .= '<h4>🔐 Session & Authentication</h4>';

        $savePath = session_save_path() ?: sys_get_temp_dir();
        $cookieParams = session_get_cookie_params();

        $html .= '<table class="debug-table"><tbody>';
        $html .= '<tr><td>Session ID</td><td><code>' . session_id() . '</code></td></tr>';
        $html .= '<tr><td>Session Status</td><td>' . self::getSessionStatusName() . '</td></tr>';
        $html .= '<tr><td>Save Path</td><td>' . htmlspecialchars($savePath) . ' ' . (is_writable($savePath) ? '✅' : '❌ not writable') . '</td></tr>';
        $html .= '<tr><td>User ID</td><td>' . ($_SESSION['user_id'] ?? '<em>not logged in</em>') . '</td></tr>';
        $html .= '<tr><td>CSRF Token</td><td>' . (isset($_SESSION['csrf_token']) ? '<code>' . self::tokenPreview($_SESSION['csrf_token']) . '</code> (' . strlen($_SESSION['csrf_token']) . ' chars)' : '❌ NOT SET') . '</td></tr>';
        $html .= '<tr><td>Cookie Domain</td><td>' . ($cookieParams['domain'] ?: '<em>current host</em>') . '</td></tr>';
        $html .= '<tr><td>Cookie Path</td><td>' . $cookieParams['path'] . '</td></tr>';
        $html .= '<tr><td>Cookie Secure</td><td>' . ($cookieParams['secure'] ? 'Yes (HTTPS only)' : 'No') . '</td></tr>';
        $html .= '<tr><td>Cookie SameSite</td><td>' . ($cookieParams['samesite'] ?: 'not set') . '</td></tr>';
        $html .= '<tr><td>Session Keys</td><td>' . implode(', ', array_keys($_SESSION ?? [])) . '</td></tr>';
        $html .= '</tbody></table>';

        $html .= '</div>';
        return $html;
    }

    private static function renderRequestPanel(): string
    {
        $html = '<div class="debug-section">';
        $html .= '<h4>📥 Request Details</h4>';

        $html .= '<table class="debug-table"><tbody>';
        $html .= '<tr><td>Method</td><td><strong>' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . '</strong></td></tr>';
        $html .= '<tr><td>URI</td><td>' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '</td></tr>';
        $html .= '<tr><td>Host</td><td>' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') . '</td></tr>';
        $html .= '<tr><td>HTTPS</td><td>' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'Yes' : 'No') . '</td></tr>';
        $html .= '<tr><td>Content-Type</td><td>' . htmlspecialchars($_SERVER['CONTENT_TYPE'] ?? 'not set') . '</td></tr>';
        $html .= '<tr><td>Content-Length</td><td>' . ($_SERVER['CONTENT_LENGTH'] ?? 'not set') . '</td></tr>';
        $html .= '<tr><td>User Agent</td><td style="max-width: 400px; word-break: break-all;">' . htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 150)) . '</td></tr>';
        $html .= '<tr><td>Remote IP</td><td>' . ($_SERVER['REMOTE_ADDR'] ?? '') . '</td></tr>';
        $html .= '<tr><td>Referer</td><td>' . htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'none') . '</td></tr>';
        $html .= '</tbody></table>';

        if (!empty($_GET)) {
            $html .= '<h4 style="margin-top: 15px;">GET Parameters</h4>';
            $html .= '<table class="debug-table"><tbody>';
            foreach ($_GET as $k => $v) {
                $html .= '<tr><td>' . htmlspecialchars($k) . '</td><td><code>' . htmlspecialchars(is_array($v) ? json_encode($v) : substr($v, 0, 100)) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!empty($_POST)) {
            $html .= '<h4 style="margin-top: 15px;">POST Parameters</h4>';
            $html .= '<table class="debug-table"><tbody>';
            foreach ($_POST as $k => $v) {
                $display = (stripos($k, 'password') !== false || stripos($k, 'token') !== false || stripos($k, 'csrf') !== false)
                    ? '***masked***'
                    : (is_array($v) ? json_encode($v) : substr($v, 0, 100));
                $html .= '<tr><td>' . htmlspecialchars($k) . '</td><td><code>' . htmlspecialchars($display) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!empty($_FILES)) {
            $html .= '<h4 style="margin-top: 15px;">Uploaded Files</h4>';
            $html .= '<table class="debug-table"><thead><tr><th scope="col">Field</th><th scope="col">Name</th><th scope="col">Size</th><th scope="col">Type</th><th scope="col">Error</th></tr></thead><tbody>';
            foreach ($_FILES as $field => $file) {
                if (is_array($file['name'])) {
                    for ($i = 0; $i < count($file['name']); $i++) {
                        $html .= sprintf(
                            '<tr><td>%s[%d]</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                            htmlspecialchars($field),
                            $i,
                            htmlspecialchars($file['name'][$i]),
                            self::formatBytes($file['size'][$i]),
                            htmlspecialchars($file['type'][$i]),
                            $file['error'][$i] === 0 ? '✅' : '❌ ' . $file['error'][$i]
                        );
                    }
                } else {
                    $html .= sprintf(
                        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                        htmlspecialchars($field),
                        htmlspecialchars($file['name']),
                        self::formatBytes($file['size']),
                        htmlspecialchars($file['type']),
                        $file['error'] === 0 ? '✅' : '❌ ' . $file['error']
                    );
                }
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderConfigPanel(): string
    {
        $html = '<div class="debug-section">';
        $html .= '<h4>⚙️ Configuration</h4>';

        if (!empty(self::$configSnapshot['defined_constants'])) {
            $html .= '<h5 style="color: #888; margin: 10px 0 5px;">Application Constants</h5>';
            $html .= '<table class="debug-table"><tbody>';
            foreach (self::$configSnapshot['defined_constants'] as $name => $value) {
                $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td><code>' . htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) . '</code></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<h5 style="color: #888; margin: 15px 0 5px;">PHP Settings</h5>';
        $html .= '<table class="debug-table"><tbody>';
        foreach (self::$configSnapshot['php_settings'] ?? [] as $name => $value) {
            $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td><code>' . htmlspecialchars((string)$value) . '</code></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h5 style="color: #888; margin: 15px 0 5px;">Server Info</h5>';
        $html .= '<table class="debug-table"><tbody>';
        foreach (self::$configSnapshot['server'] ?? [] as $name => $value) {
            $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td><code>' . htmlspecialchars((string)$value) . '</code></td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '</div>';
        return $html;
    }

    private static function renderTimelinePanel(): string
    {
        $html = '<div class="debug-section">';
        $html .= '<h4>📊 Timeline</h4>';

        $totalDuration = microtime(true) - self::$startTime;
        $scale = 100 / $totalDuration; // percentage per second

        $html .= '<div style="position: relative; height: 30px; background: #1a1a2e; border-radius: 4px; margin: 10px 0; overflow: hidden;">';

        // Query time bar
        $queryTime = array_sum(array_column(self::$queries, 'duration'));
        $queryWidth = $queryTime * $scale;
        $html .= '<div style="position: absolute; left: 0; top: 0; height: 100%; width: ' . $queryWidth . '%; background: #22c55e; opacity: 0.7;" title="Queries: ' . round($queryTime * 1000) . 'ms"></div>';

        $html .= '</div>';

        $html .= '<div style="display: flex; gap: 20px; font-size: 11px; color: #888;">';
        $html .= '<span><span style="display: inline-block; width: 12px; height: 12px; background: #22c55e; border-radius: 2px; margin-right: 5px;"></span>Queries (' . round($queryTime * 1000) . 'ms)</span>';
        $html .= '<span>Total: ' . round($totalDuration * 1000) . 'ms</span>';
        $html .= '</div>';

        // Memory timeline
        if (!empty(self::$memorySnapshots)) {
            $html .= '<h5 style="color: #888; margin: 15px 0 5px;">Memory Snapshots</h5>';
            $html .= '<table class="debug-table"><thead><tr><th scope="col">Label</th><th scope="col">Time</th><th scope="col">Current</th><th scope="col">Peak</th></tr></thead><tbody>';
            foreach (self::$memorySnapshots as $label => $snap) {
                $html .= sprintf(
                    '<tr><td>%s</td><td>+%.3fs</td><td>%s</td><td>%s</td></tr>',
                    htmlspecialchars($label),
                    $snap['time'],
                    self::formatBytes($snap['current']),
                    self::formatBytes($snap['peak'])
                );
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';
        return $html;
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    public static function getMetrics(): array
    {
        return [
            'duration' => microtime(true) - self::$startTime,
            'memory_current' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'query_count' => self::$queryCount,
            'log_count' => count(self::$logs),
        ];
    }

    public static function getLogs(): array
    {
        return self::$logs;
    }

    public static function getQueries(): array
    {
        return self::$queries;
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private static function formatValue($var, int $depth = 0): mixed
    {
        $maxLen = self::$config['truncate_strings'] ?? 500;

        if ($depth > 3) {
            return '[max depth]';
        }
        if (is_object($var)) {
            return get_class($var) . ' {...}';
        }
        if (is_array($var)) {
            if (count($var) > 50) {
                return '[array: ' . count($var) . ' items]';
            }
            $result = [];
            foreach ($var as $key => $value) {
                $result[$key] = self::formatValue($value, $depth + 1);
            }
            return $result;
        }
        if (is_string($var) && strlen($var) > $maxLen) {
            return substr($var, 0, $maxLen) . '... [' . strlen($var) . ' chars]';
        }
        return $var;
    }

    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'auth', 'credential'];

        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            if (is_string($key)) {
                foreach ($sensitiveKeys as $sensitive) {
                    if (stripos($key, $sensitive) !== false && $key !== 'csrf_token') {
                        $value = '***';
                        return;
                    }
                }
            }
            if (is_string($value) && strlen($value) > 500) {
                $value = substr($value, 0, 500) . '...';
            }
        });

        return $context;
    }

    private static function shortenPath(string $path): string
    {
        $root = dirname(__DIR__);
        if (strpos($path, $root) === 0) {
            return substr($path, strlen($root) + 1);
        }
        return basename(dirname($path)) . '/' . basename($path);
    }

    private static function tokenPreview(string $token): string
    {
        return substr($token, 0, 8) . '...' . substr($token, -4);
    }

    private static function getSessionStatusName(): string
    {
        return match (session_status()) {
            PHP_SESSION_DISABLED => 'Disabled',
            PHP_SESSION_NONE => 'None (not started)',
            PHP_SESSION_ACTIVE => 'Active',
        };
    }

    private static function getShortTrace(int $skip = 2): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::$config['trace_depth'] + $skip);
        $trace = array_slice($trace, $skip);

        return array_map(function ($frame) {
            return [
                'file' => self::shortenPath($frame['file'] ?? ''),
                'line' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'],
            ];
        }, $trace);
    }

    private static function formatTrace(array $trace): array
    {
        $depth = self::$config['trace_depth'] ?? 5;
        $trace = array_slice($trace, 0, $depth);

        return array_map(function ($frame) {
            return self::shortenPath($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0) . ' ' .
                   ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }, $trace);
    }
}
