<?php
/**
 * Event System for Silo
 *
 * A simple publish/subscribe event system for application-wide events.
 * Supports listeners, webhooks, and logging.
 *
 * Usage:
 *   Events::on('model.uploaded', function($data) { ... });
 *   Events::emit('model.uploaded', ['model_id' => 123, 'user_id' => 1]);
 */

class Events {
    private static array $listeners = [];
    private static bool $initialized = false;
    private static bool $webhooksEnabled = true;
    private static bool $loggingEnabled = true;

    /**
     * Standard event names
     */
    public const MODEL_UPLOADED = 'model.uploaded';
    public const MODEL_UPDATED = 'model.updated';
    public const MODEL_DELETED = 'model.deleted';
    public const MODEL_DOWNLOADED = 'model.downloaded';
    public const MODEL_VIEWED = 'model.viewed';
    public const MODEL_FAVORITED = 'model.favorited';
    public const MODEL_UNFAVORITED = 'model.unfavorited';

    public const USER_REGISTERED = 'user.registered';
    public const USER_LOGIN = 'user.login';
    public const USER_LOGOUT = 'user.logout';
    public const USER_LOGIN_FAILED = 'user.login_failed';
    public const USER_PASSWORD_CHANGED = 'user.password_changed';
    public const USER_UPDATED = 'user.updated';

    public const CATEGORY_CREATED = 'category.created';
    public const CATEGORY_UPDATED = 'category.updated';
    public const CATEGORY_DELETED = 'category.deleted';

    public const COLLECTION_CREATED = 'collection.created';
    public const COLLECTION_UPDATED = 'collection.updated';
    public const COLLECTION_DELETED = 'collection.deleted';

    public const TAG_CREATED = 'tag.created';
    public const TAG_ADDED = 'tag.added';
    public const TAG_REMOVED = 'tag.removed';

    public const COMMENT_CREATED = 'comment.created';
    public const COMMENT_DELETED = 'comment.deleted';

    public const PRINT_STARTED = 'print.started';
    public const PRINT_COMPLETED = 'print.completed';

    public const BACKUP_CREATED = 'backup.created';
    public const BACKUP_RESTORED = 'backup.restored';

    public const SYSTEM_MAINTENANCE_STARTED = 'system.maintenance_started';
    public const SYSTEM_MAINTENANCE_ENDED = 'system.maintenance_ended';
    public const SYSTEM_ERROR = 'system.error';

    /**
     * Initialize the event system
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        // Load settings if available
        if (function_exists('getSetting')) {
            self::$webhooksEnabled = getSetting('webhooks_enabled', '1') === '1';
            self::$loggingEnabled = getSetting('event_logging', '1') === '1';
        }

        // Register default listeners
        self::registerDefaultListeners();

        self::$initialized = true;
    }

    /**
     * Register a listener for an event
     *
     * @param string $event Event name or pattern (e.g., 'model.*')
     * @param callable $callback Callback function
     * @param int $priority Priority (higher runs first, default 10)
     * @return void
     */
    public static function on(string $event, callable $callback, int $priority = 10): void {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = [];
        }

        self::$listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Sort by priority (descending)
        usort(self::$listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Register a one-time listener
     */
    public static function once(string $event, callable $callback, int $priority = 10): void {
        $wrapper = function($data) use ($event, $callback, &$wrapper) {
            self::off($event, $wrapper);
            return $callback($data);
        };

        self::on($event, $wrapper, $priority);
    }

    /**
     * Remove a listener
     */
    public static function off(string $event, ?callable $callback = null): void {
        if ($callback === null) {
            // Remove all listeners for this event
            unset(self::$listeners[$event]);
            return;
        }

        if (!isset(self::$listeners[$event])) {
            return;
        }

        self::$listeners[$event] = array_filter(
            self::$listeners[$event],
            fn($listener) => $listener['callback'] !== $callback
        );
    }

    /**
     * Emit an event
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param bool $async Run webhooks asynchronously (default true)
     * @return array Results from listeners
     */
    public static function emit(string $event, array $data = [], bool $async = true): array {
        self::init();

        $results = [];

        // Add metadata
        $data['_event'] = $event;
        $data['_timestamp'] = time();
        $data['_user_id'] = function_exists('getCurrentUser') ? (getCurrentUser()['id'] ?? null) : null;

        // Log event if enabled
        if (self::$loggingEnabled) {
            self::logEvent($event, $data);
        }

        // Call exact match listeners
        if (isset(self::$listeners[$event])) {
            foreach (self::$listeners[$event] as $listener) {
                try {
                    $result = $listener['callback']($data);
                    if ($result !== null) {
                        $results[] = $result;
                    }
                } catch (Exception $e) {
                    self::handleListenerError($event, $e);
                }
            }
        }

        // Call wildcard listeners (e.g., 'model.*' matches 'model.uploaded')
        foreach (self::$listeners as $pattern => $listeners) {
            if (strpos($pattern, '*') === false) {
                continue;
            }

            $regex = '/^' . str_replace(['*', '.'], ['.*', '\\.'], $pattern) . '$/';
            if (preg_match($regex, $event)) {
                foreach ($listeners as $listener) {
                    try {
                        $result = $listener['callback']($data);
                        if ($result !== null) {
                            $results[] = $result;
                        }
                    } catch (Exception $e) {
                        self::handleListenerError($event, $e);
                    }
                }
            }
        }

        // Trigger webhooks if enabled
        if (self::$webhooksEnabled) {
            self::triggerWebhooks($event, $data, $async);
        }

        return $results;
    }

    /**
     * Emit an event asynchronously (fire and forget)
     */
    public static function emitAsync(string $event, array $data = []): void {
        // For true async, we'd need a queue system
        // For now, just emit normally but suppress errors
        try {
            self::emit($event, $data, true);
        } catch (Exception $e) {
            // Log but don't throw
            if (function_exists('logError')) {
                logError('Async event error: ' . $e->getMessage(), [
                    'event' => $event,
                    'data' => $data
                ]);
            }
        }
    }

    /**
     * Log an event to the database
     */
    private static function logEvent(string $event, array $data): void {
        // Don't log system events to avoid recursion
        if (strpos($event, 'system.') === 0) {
            return;
        }

        try {
            if (!function_exists('getDB')) {
                return;
            }

            $db = getDB();

            // Check if event_log table exists
            $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='event_log'");
            if (!$tableCheck) {
                return; // Table doesn't exist yet
            }

            $stmt = $db->prepare('
                INSERT INTO event_log (event, data, user_id, ip_address, created_at)
                VALUES (:event, :data, :user_id, :ip, CURRENT_TIMESTAMP)
            ');

            $eventData = $data;
            unset($eventData['_event'], $eventData['_timestamp'], $eventData['_user_id']);

            $stmt->bindValue(':event', $event, SQLITE3_TEXT);
            $stmt->bindValue(':data', json_encode($eventData), SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $data['_user_id'], SQLITE3_INTEGER);
            $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null, SQLITE3_TEXT);
            $stmt->execute();
        } catch (Exception $e) {
            // Silently fail to avoid disrupting the application
        }
    }

    /**
     * Trigger webhooks for an event
     */
    private static function triggerWebhooks(string $event, array $data, bool $async): void {
        try {
            if (!function_exists('getDB')) {
                return;
            }

            $db = getDB();

            // Check if webhooks table exists
            $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='webhooks'");
            if (!$tableCheck) {
                return;
            }

            // Get webhooks that match this event
            $stmt = $db->prepare('
                SELECT * FROM webhooks
                WHERE is_active = 1
                AND (events LIKE :event_pattern OR events LIKE :wildcard_pattern OR events = "*")
            ');
            $stmt->bindValue(':event_pattern', '%"' . $event . '"%', SQLITE3_TEXT);

            // Also check for wildcard patterns (e.g., "model.*")
            $eventParts = explode('.', $event);
            $wildcardEvent = $eventParts[0] . '.*';
            $stmt->bindValue(':wildcard_pattern', '%"' . $wildcardEvent . '"%', SQLITE3_TEXT);

            $result = $stmt->execute();

            while ($webhook = $result->fetchArray(SQLITE3_ASSOC)) {
                $payload = [
                    'event' => $event,
                    'timestamp' => $data['_timestamp'],
                    'data' => array_diff_key($data, array_flip(['_event', '_timestamp', '_user_id']))
                ];

                if ($async) {
                    self::sendWebhookAsync($webhook, $payload);
                } else {
                    self::sendWebhook($webhook, $payload);
                }
            }
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Webhook trigger error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Send a webhook request
     */
    private static function sendWebhook(array $webhook, array $payload): bool {
        $url = $webhook['url'];
        $secret = $webhook['secret'] ?? '';

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Silo-Signature: sha256=' . $signature,
                'X-Silo-Event: ' . $payload['event'],
                'User-Agent: Silo-Webhook/1.0'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log webhook delivery
        self::logWebhookDelivery($webhook['id'], $payload['event'], $httpCode, $error);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Send webhook asynchronously (non-blocking)
     */
    private static function sendWebhookAsync(array $webhook, array $payload): void {
        // For true async, we'd use a job queue
        // For now, use a very short timeout
        $url = $webhook['url'];
        $secret = $webhook['secret'] ?? '';

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500, // Very short timeout
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Silo-Signature: sha256=' . $signature,
                'X-Silo-Event: ' . $payload['event'],
                'User-Agent: Silo-Webhook/1.0'
            ]
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Log webhook delivery attempt
     */
    private static function logWebhookDelivery(int $webhookId, string $event, int $httpCode, string $error): void {
        try {
            if (!function_exists('getDB')) {
                return;
            }

            $db = getDB();

            // Check if webhook_logs table exists
            $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='webhook_logs'");
            if (!$tableCheck) {
                return;
            }

            $stmt = $db->prepare('
                INSERT INTO webhook_logs (webhook_id, event, http_code, error, created_at)
                VALUES (:webhook_id, :event, :http_code, :error, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':webhook_id', $webhookId, SQLITE3_INTEGER);
            $stmt->bindValue(':event', $event, SQLITE3_TEXT);
            $stmt->bindValue(':http_code', $httpCode, SQLITE3_INTEGER);
            $stmt->bindValue(':error', $error ?: null, SQLITE3_TEXT);
            $stmt->execute();

            // Update webhook last_triggered
            $updateStmt = $db->prepare('UPDATE webhooks SET last_triggered = CURRENT_TIMESTAMP WHERE id = :id');
            $updateStmt->bindValue(':id', $webhookId, SQLITE3_INTEGER);
            $updateStmt->execute();
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Handle listener errors
     */
    private static function handleListenerError(string $event, Exception $e): void {
        if (function_exists('logError')) {
            logError('Event listener error', [
                'event' => $event,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Register default system listeners
     */
    private static function registerDefaultListeners(): void {
        // Log security events
        self::on(self::USER_LOGIN_FAILED, function($data) {
            if (function_exists('logWarning')) {
                logWarning('Failed login attempt', [
                    'username' => $data['username'] ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            }
        });

        // Activity logging for model events
        self::on('model.*', function($data) {
            if (function_exists('logActivity') && isset($data['model_id'])) {
                $event = $data['_event'];
                $action = str_replace('model.', '', $event);
                logActivity($action, 'model', $data['model_id'], $data['model_name'] ?? '');
            }
        }, 5); // Lower priority, runs after other listeners
    }

    /**
     * Get all registered listeners (for debugging)
     */
    public static function getListeners(): array {
        return self::$listeners;
    }

    /**
     * Clear all listeners (useful for testing)
     */
    public static function clear(): void {
        self::$listeners = [];
        self::$initialized = false;
    }
}

// Helper functions

/**
 * Emit an event (shorthand)
 */
function emit(string $event, array $data = []): array {
    return Events::emit($event, $data);
}

/**
 * Register an event listener (shorthand)
 */
function on(string $event, callable $callback, int $priority = 10): void {
    Events::on($event, $callback, $priority);
}
