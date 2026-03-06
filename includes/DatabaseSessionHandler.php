<?php

/**
 * Database Session Handler
 *
 * Stores sessions in the database for better scalability and security.
 * Benefits:
 * - Sessions persist across server restarts
 * - Works with load balancers (shared sessions)
 * - Easy session management and cleanup
 * - Better security (sessions stored securely in DB)
 */

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private ?PDO $db = null;
    private string $table = 'sessions';
    private int $lifetime;

    public function __construct(int $lifetime = 7200)
    {
        $this->lifetime = $lifetime;
    }

    /**
     * Open the session
     */
    public function open(string $path, string $name): bool
    {
        try {
            $this->db = getDB();
            $this->ensureTable();
            return true;
        } catch (Exception $e) {
            logError('Session open failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Close the session
     */
    public function close(): bool
    {
        $this->db = null;
        return true;
    }

    /**
     * Read session data
     */
    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT data FROM {$this->table} WHERE id = :id AND expires_at > :now"
            );
            $stmt->execute([
                ':id' => $id,
                ':now' => time()
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['data'] : '';
        } catch (Exception $e) {
            logError('Session read failed', ['id' => $id, 'error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Write session data
     */
    public function write(string $id, string $data): bool
    {
        try {
            $expiresAt = time() + $this->lifetime;
            $userId = $_SESSION['user_id'] ?? null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

            // Use REPLACE INTO for SQLite/MySQL compatibility
            $sql = "REPLACE INTO {$this->table} (id, data, user_id, ip_address, user_agent, expires_at, last_activity)
                    VALUES (:id, :data, :user_id, :ip_address, :user_agent, :expires_at, :last_activity)";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':data' => $data,
                ':user_id' => $userId,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':expires_at' => $expiresAt,
                ':last_activity' => time()
            ]);
        } catch (Exception $e) {
            logError('Session write failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Destroy a session
     */
    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            logError('Session destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Garbage collection
     */
    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE expires_at < :now");
            $stmt->execute([':now' => time()]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            logError('Session gc failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Ensure the sessions table exists
     */
    private function ensureTable(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                user_id INTEGER,
                ip_address TEXT,
                user_agent TEXT,
                expires_at INTEGER NOT NULL,
                last_activity INTEGER NOT NULL
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id VARCHAR(128) PRIMARY KEY,
                data TEXT NOT NULL,
                user_id INT,
                ip_address VARCHAR(45),
                user_agent VARCHAR(255),
                expires_at INT NOT NULL,
                last_activity INT NOT NULL,
                INDEX idx_expires (expires_at),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }

        $this->db->exec($sql);

        // Add index for expiration cleanup (SQLite)
        if ($driver === 'sqlite') {
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires ON {$this->table}(expires_at)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user ON {$this->table}(user_id)");
        }
    }

    /**
     * Get all active sessions for a user
     */
    public static function getUserSessions(int $userId): array
    {
        try {
            $db = getDB();
            $stmt = $db->prepare(
                "SELECT id, ip_address, user_agent, last_activity, expires_at
                 FROM sessions
                 WHERE user_id = :user_id AND expires_at > :now
                 ORDER BY last_activity DESC"
            );
            $stmt->execute([':user_id' => $userId, ':now' => time()]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Destroy all sessions for a user (useful for "log out everywhere")
     */
    public static function destroyUserSessions(int $userId, ?string $exceptSessionId = null): int
    {
        try {
            $db = getDB();
            if ($exceptSessionId) {
                $stmt = $db->prepare("DELETE FROM sessions WHERE user_id = :user_id AND id != :except_id");
                $stmt->execute([':user_id' => $userId, ':except_id' => $exceptSessionId]);
            } else {
                $stmt = $db->prepare("DELETE FROM sessions WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $userId]);
            }
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get session statistics
     */
    public static function getStats(): array
    {
        try {
            $db = getDB();
            $now = time();

            $stmt = $db->query("SELECT COUNT(*) FROM sessions WHERE expires_at > $now");
            $active = (int)$stmt->fetchColumn();

            $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at > $now AND user_id IS NOT NULL");
            $uniqueUsers = (int)$stmt->fetchColumn();

            return [
                'active_sessions' => $active,
                'unique_users' => $uniqueUsers
            ];
        } catch (Exception $e) {
            return ['active_sessions' => 0, 'unique_users' => 0];
        }
    }
}
