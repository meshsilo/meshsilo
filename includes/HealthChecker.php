<?php
/**
 * HealthChecker
 *
 * System health-check logic extracted from app/admin/health.php.
 * Each method returns the same array shape it produced as a free function
 * in the page; the health page renders the returned data unchanged.
 */
class HealthChecker
{
    /**
     * Get system metrics
     */
    public static function getSystemMetrics()
    {
        $db = getDB();
        if (!$db) {
            return ['error' => 'Database connection failed'];
        }

        $metrics = [];

        // Memory usage
        $memoryLimit = ini_get('memory_limit');
        $memoryUsed = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimitBytes = convertToBytes($memoryLimit);

        // Handle unlimited memory (-1)
        $memoryUnlimited = ($memoryLimitBytes <= 0);
        $metrics['memory'] = [
            'used' => $memoryUsed,
            'peak' => $memoryPeak,
            'limit' => $memoryLimitBytes,
            'unlimited' => $memoryUnlimited,
            'percent' => (!$memoryUnlimited && $memoryLimitBytes > 0) ? round(($memoryUsed / $memoryLimitBytes) * 100, 1) : 0
        ];

        // Disk usage
        $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../assets';
        $diskFree = @disk_free_space($uploadPath) ?: 0;
        $diskTotal = @disk_total_space($uploadPath) ?: 0;
        $diskUsed = $diskTotal - $diskFree;

        $metrics['disk'] = [
            'used' => $diskUsed,
            'free' => $diskFree,
            'total' => $diskTotal,
            'percent' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0
        ];

        // Database size
        $dbPath = defined('DB_PATH') ? DB_PATH : '';
        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
        $metrics['database'] = [
            'size' => $dbSize,
            'type' => defined('DB_TYPE') ? DB_TYPE : 'sqlite'
        ];

        // Request stats (from activity log if available)
        try {
            $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM activity_log WHERE created_at >= :since");
            $stmt->bindValue(':since', $oneHourAgo, PDO::PARAM_STR);
            $result = $stmt->execute();
            $metrics['requests_hour'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
        } catch (Exception $e) {
            $metrics['requests_hour'] = 0;
        }

        // Active sessions
        try {
            $now = time();
            $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM sessions WHERE expires_at > :now");
            $stmt->bindValue(':now', $now, PDO::PARAM_INT);
            $result = $stmt->execute();
            $metrics['active_sessions'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
        } catch (Exception $e) {
            $metrics['active_sessions'] = 0;
        }

        // Error count (last 24 hours)
        try {
            $oneDayAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM audit_log WHERE severity IN ('error', 'critical') AND created_at >= :since");
            $stmt->bindValue(':since', $oneDayAgo, PDO::PARAM_STR);
            $result = $stmt->execute();
            $metrics['errors_24h'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
        } catch (Exception $e) {
            $metrics['errors_24h'] = 0;
        }

        // Model count
        try {
            $result = $db->query("SELECT COUNT(*) as count FROM models WHERE parent_id IS NULL");
            $metrics['model_count'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
        } catch (Exception $e) {
            $metrics['model_count'] = 0;
        }

        // User count
        try {
            $result = $db->query("SELECT COUNT(*) as count FROM users");
            $metrics['user_count'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
        } catch (Exception $e) {
            $metrics['user_count'] = 0;
        }

        // PHP info
        $metrics['php'] = [
            'version' => PHP_VERSION,
            'memory_limit' => $memoryLimit,
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size')
        ];

        // Server uptime (if available)
        $metrics['uptime'] = self::getServerUptime();

        $metrics['timestamp'] = date('c');

        return $metrics;
    }

    /**
     * Check service health
     */
    public static function checkServices()
    {
        $services = [];

        // Database
        $services['database'] = self::checkDatabaseHealth();

        // File storage
        $services['storage'] = self::checkStorageHealth();

        // Cache
        $services['cache'] = self::checkCacheHealth();

        // Search (FTS)
        $services['search'] = self::checkSearchHealth();

        // External services
        $services['external'] = self::checkExternalServices();

        // Plugin hook: health_checks - custom service health checks (S3, Redis, external APIs)
        if (class_exists('PluginManager')) {
            $services = PluginManager::applyFilter('health_checks', $services);
        }

        return $services;
    }

    public static function checkDatabaseHealth()
    {
        $db = getDB();
        if (!$db) {
            return ['status' => 'critical', 'message' => 'Database connection failed', 'latency' => 0];
        }

        try {
            $start = microtime(true);
            $result = $db->query("SELECT 1");
            $latency = round((microtime(true) - $start) * 1000, 2);

            // Check for database locks or issues
            $integrity = true;
            if (defined('DB_TYPE') && DB_TYPE === 'sqlite') {
                $integrityResult = $db->query("PRAGMA integrity_check(1)");
                $row = $integrityResult->fetchArray(PDO::FETCH_ASSOC);
                $integrity = ($row && $row['integrity_check'] === 'ok');
            }

            return [
                'status' => $integrity ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
                'message' => $integrity ? 'Database operational' : 'Database integrity issues detected'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'down',
                'latency_ms' => 0,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    public static function checkStorageHealth()
    {
        $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../assets';

        if (!is_dir($uploadPath)) {
            return [
                'status' => 'down',
                'message' => 'Upload directory does not exist'
            ];
        }

        if (!is_writable($uploadPath)) {
            return [
                'status' => 'down',
                'message' => 'Upload directory is not writable'
            ];
        }

        $diskFree = @disk_free_space($uploadPath);
        $diskTotal = @disk_total_space($uploadPath);

        if ($diskFree === false || $diskTotal === false) {
            return [
                'status' => 'degraded',
                'message' => 'Cannot determine disk space'
            ];
        }

        $percentFree = $diskTotal > 0 ? ($diskFree / $diskTotal) * 100 : 0;

        if ($percentFree < 5) {
            return [
                'status' => 'critical',
                'message' => 'Disk space critically low (<5% free)'
            ];
        } elseif ($percentFree < 15) {
            return [
                'status' => 'warning',
                'message' => 'Disk space low (<15% free)'
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'Storage operational'
        ];
    }

    public static function checkCacheHealth()
    {
        $cachePath = defined('CACHE_PATH') ? CACHE_PATH : __DIR__ . '/../storage/cache';

        if (!is_dir($cachePath)) {
            return [
                'status' => 'warning',
                'message' => 'Cache directory does not exist'
            ];
        }

        if (!is_writable($cachePath)) {
            return [
                'status' => 'warning',
                'message' => 'Cache directory is not writable'
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'File cache operational'
        ];
    }

    public static function checkSearchHealth()
    {
        $db = getDB();
        if (!$db) {
            return ['status' => 'critical', 'message' => 'Database connection failed'];
        }

        try {
            // Check if FTS table exists (SQLite only)
            $hasFts = false;
            if ($db->getType() === 'sqlite') {
                $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='models_fts'");
                $hasFts = $result && $result->fetchArray(PDO::FETCH_ASSOC);
            } else {
                // MySQL uses FULLTEXT indexes, check if they exist
                $result = $db->query("SHOW INDEX FROM models WHERE Index_type = 'FULLTEXT'");
                $hasFts = $result && $result->fetch();
            }

            if (!$hasFts) {
                return [
                    'status' => 'warning',
                    'message' => 'Full-text search not configured'
                ];
            }

            return [
                'status' => 'healthy',
                'message' => 'Full-text search operational'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'degraded',
                'message' => 'Search check failed'
            ];
        }
    }

    public static function checkExternalServices()
    {
        $services = [];

        // Check S3 if configured
        $s3Enabled = getSetting('storage_type', 'local') === 's3' || getSetting('backup_s3_enabled', '0') === '1';
        if ($s3Enabled) {
            $services['s3'] = [
                'name' => 'S3 Storage',
                'status' => 'configured',
                'message' => 'S3 storage enabled'
            ];
        }

        // Check webhooks (only if table exists)
        try {
            $db = getDB();
            if ($db && tableExists($db, 'webhooks')) {
                $result = $db->query("SELECT COUNT(*) as count FROM webhooks WHERE is_active = 1");
                $webhookCount = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

                if ($webhookCount > 0) {
                    $services['webhooks'] = [
                        'name' => 'Webhooks',
                        'status' => 'healthy',
                        'message' => "$webhookCount active webhook(s)"
                    ];
                }
            }
        } catch (Exception $e) {
            // Safe to ignore
        }

        return $services;
    }

    /**
     * Get recent errors from logs
     */
    public static function getRecentErrors()
    {
        $db = getDB();
        $errors = [];

        if (!$db) {
            return $errors;
        }

        try {
            $result = $db->query("
                SELECT event_name, severity, resource_type, resource_id, created_at, metadata
                FROM audit_log
                WHERE severity IN ('error', 'critical', 'warning')
                ORDER BY created_at DESC
                LIMIT 20
            ");

            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $errors[] = $row;
            }
        } catch (Exception $e) {
            // Audit log might not exist
        }

        // Also check error log file
        $logFile = __DIR__ . '/../storage/logs/error.log';
        if (file_exists($logFile)) {
            $logLines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -10);
            foreach ($logLines as $line) {
                $errors[] = [
                    'event_name' => 'PHP Error',
                    'severity' => strpos($line, 'CRITICAL') !== false ? 'critical' :
                                 (strpos($line, 'ERROR') !== false ? 'error' : 'warning'),
                    'resource_type' => 'system',
                    'resource_id' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'metadata' => ['message' => substr($line, 0, 200)]
                ];
            }
        }

        return array_slice($errors, 0, 20);
    }

    public static function getServerUptime()
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime = @file_get_contents('/proc/uptime');
            if ($uptime) {
                $seconds = (int)floatval($uptime);
                $days = floor($seconds / 86400);
                $hours = floor(($seconds % 86400) / 3600);
                $minutes = floor(($seconds % 3600) / 60);
                return "{$days}d {$hours}h {$minutes}m";
            }
        }
        return 'N/A';
    }
}
