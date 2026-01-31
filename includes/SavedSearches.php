<?php
/**
 * Saved Searches Manager
 * Save, load, and share search queries
 */

class SavedSearches {
    private static ?PDO $db = null;

    /**
     * Initialize database connection
     */
    private static function getDB(): PDO {
        if (self::$db === null) {
            self::$db = getDB();
            self::ensureTable();
        }
        return self::$db;
    }

    /**
     * Ensure the saved searches table exists
     */
    private static function ensureTable(): void {
        $db = self::$db;
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS saved_searches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    description TEXT,
                    query TEXT NOT NULL,
                    filters TEXT,
                    sort_by TEXT,
                    sort_order TEXT DEFAULT 'desc',
                    is_public INTEGER DEFAULT 0,
                    share_token TEXT UNIQUE,
                    use_count INTEGER DEFAULT 0,
                    last_used_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_saved_searches_user ON saved_searches(user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_saved_searches_public ON saved_searches(is_public)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_saved_searches_token ON saved_searches(share_token)");
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS saved_searches (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    query TEXT NOT NULL,
                    filters TEXT,
                    sort_by VARCHAR(50),
                    sort_order VARCHAR(10) DEFAULT 'desc',
                    is_public TINYINT DEFAULT 0,
                    share_token VARCHAR(64) UNIQUE,
                    use_count INT DEFAULT 0,
                    last_used_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_public (is_public),
                    INDEX idx_token (share_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    /**
     * Save a new search
     */
    public static function create(
        int $userId,
        string $name,
        string $query,
        array $filters = [],
        ?string $sortBy = null,
        string $sortOrder = 'desc',
        ?string $description = null,
        bool $isPublic = false
    ): ?int {
        $db = self::getDB();

        $shareToken = $isPublic ? bin2hex(random_bytes(16)) : null;

        $stmt = $db->prepare("
            INSERT INTO saved_searches
            (user_id, name, description, query, filters, sort_by, sort_order, is_public, share_token)
            VALUES (:user_id, :name, :description, :query, :filters, :sort_by, :sort_order, :is_public, :token)
        ");

        $result = $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':description' => $description,
            ':query' => $query,
            ':filters' => json_encode($filters),
            ':sort_by' => $sortBy,
            ':sort_order' => $sortOrder,
            ':is_public' => $isPublic ? 1 : 0,
            ':token' => $shareToken,
        ]);

        return $result ? (int)$db->lastInsertId() : null;
    }

    /**
     * Update an existing search
     */
    public static function update(
        int $id,
        int $userId,
        array $data
    ): bool {
        $db = self::getDB();

        // Verify ownership
        $stmt = $db->prepare("SELECT user_id FROM saved_searches WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $search = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$search || $search['user_id'] != $userId) {
            return false;
        }

        $updates = [];
        $params = [':id' => $id];

        $allowedFields = ['name', 'description', 'query', 'filters', 'sort_by', 'sort_order', 'is_public'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = :$field";
                $value = $data[$field];

                if ($field === 'filters' && is_array($value)) {
                    $value = json_encode($value);
                }
                if ($field === 'is_public') {
                    $value = $value ? 1 : 0;

                    // Generate share token if making public
                    if ($value) {
                        $updates[] = "share_token = :token";
                        $params[':token'] = bin2hex(random_bytes(16));
                    }
                }

                $params[":$field"] = $value;
            }
        }

        if (empty($updates)) {
            return true;
        }

        $updates[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE saved_searches SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a saved search
     */
    public static function delete(int $id, int $userId): bool {
        $db = self::getDB();

        $stmt = $db->prepare("DELETE FROM saved_searches WHERE id = :id AND user_id = :user_id");
        return $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }

    /**
     * Get a saved search by ID
     */
    public static function get(int $id): ?array {
        $db = self::getDB();

        $stmt = $db->prepare("SELECT * FROM saved_searches WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $search = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($search) {
            $search['filters'] = json_decode($search['filters'] ?: '[]', true);
        }

        return $search ?: null;
    }

    /**
     * Get a saved search by share token
     */
    public static function getByToken(string $token): ?array {
        $db = self::getDB();

        $stmt = $db->prepare("SELECT * FROM saved_searches WHERE share_token = :token AND is_public = 1");
        $stmt->execute([':token' => $token]);
        $search = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($search) {
            $search['filters'] = json_decode($search['filters'] ?: '[]', true);
        }

        return $search ?: null;
    }

    /**
     * Get all saved searches for a user
     */
    public static function getUserSearches(int $userId, int $limit = 50): array {
        $db = self::getDB();

        $stmt = $db->prepare("
            SELECT * FROM saved_searches
            WHERE user_id = :user_id
            ORDER BY use_count DESC, updated_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $searches = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['filters'] = json_decode($row['filters'] ?: '[]', true);
            $searches[] = $row;
        }

        return $searches;
    }

    /**
     * Get public saved searches
     */
    public static function getPublicSearches(int $limit = 20): array {
        $db = self::getDB();

        $stmt = $db->prepare("
            SELECT s.*, u.username as owner_name
            FROM saved_searches s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.is_public = 1
            ORDER BY s.use_count DESC, s.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $searches = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['filters'] = json_decode($row['filters'] ?: '[]', true);
            $searches[] = $row;
        }

        return $searches;
    }

    /**
     * Record usage of a saved search
     */
    public static function recordUsage(int $id): void {
        $db = self::getDB();

        $stmt = $db->prepare("
            UPDATE saved_searches
            SET use_count = use_count + 1, last_used_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Get share URL for a saved search
     */
    public static function getShareUrl(int $id, int $userId): ?string {
        $db = self::getDB();

        $stmt = $db->prepare("SELECT share_token FROM saved_searches WHERE id = :id AND user_id = :user_id AND is_public = 1");
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['share_token']) {
            $siteUrl = function_exists('getSetting') ? getSetting('site_url', '') : '';
            return rtrim($siteUrl, '/') . '/search/shared/' . $row['share_token'];
        }

        return null;
    }

    /**
     * Build query parameters from saved search
     */
    public static function toQueryParams(array $search): array {
        $params = [];

        if (!empty($search['query'])) {
            $params['q'] = $search['query'];
        }

        if (!empty($search['filters'])) {
            foreach ($search['filters'] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $params[$key] = $value;
                }
            }
        }

        if (!empty($search['sort_by'])) {
            $params['sort'] = $search['sort_by'];
        }

        if (!empty($search['sort_order'])) {
            $params['order'] = $search['sort_order'];
        }

        return $params;
    }

    /**
     * Build URL from saved search
     */
    public static function toUrl(array $search): string {
        $params = self::toQueryParams($search);

        if (function_exists('route')) {
            return route('search', [], $params);
        }

        return '/search?' . http_build_query($params);
    }

    /**
     * Create saved search from current URL parameters
     */
    public static function fromRequest(array $request): array {
        return [
            'query' => $request['q'] ?? '',
            'filters' => array_filter([
                'category' => $request['category'] ?? null,
                'tag' => $request['tag'] ?? null,
                'license' => $request['license'] ?? null,
                'file_type' => $request['file_type'] ?? null,
                'date_from' => $request['date_from'] ?? null,
                'date_to' => $request['date_to'] ?? null,
                'min_parts' => $request['min_parts'] ?? null,
                'max_parts' => $request['max_parts'] ?? null,
                'owner' => $request['owner'] ?? null,
            ], function($v) { return $v !== null && $v !== ''; }),
            'sort_by' => $request['sort'] ?? null,
            'sort_order' => $request['order'] ?? 'desc',
        ];
    }

    /**
     * Clone a public search for the current user
     */
    public static function clone(int $searchId, int $userId, ?string $newName = null): ?int {
        $original = self::get($searchId);

        if (!$original || (!$original['is_public'] && $original['user_id'] != $userId)) {
            return null;
        }

        $name = $newName ?? $original['name'] . ' (Copy)';

        return self::create(
            $userId,
            $name,
            $original['query'],
            $original['filters'],
            $original['sort_by'],
            $original['sort_order'],
            $original['description'],
            false // Cloned searches start as private
        );
    }

    /**
     * Get suggested searches based on user history
     */
    public static function getSuggestions(int $userId, int $limit = 5): array {
        $db = self::getDB();

        // Get user's most used searches
        $stmt = $db->prepare("
            SELECT * FROM saved_searches
            WHERE user_id = :user_id
            ORDER BY use_count DESC, last_used_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $suggestions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['filters'] = json_decode($row['filters'] ?: '[]', true);
            $suggestions[] = $row;
        }

        return $suggestions;
    }

    /**
     * Export saved searches as JSON
     */
    public static function export(int $userId): string {
        $searches = self::getUserSearches($userId);

        return json_encode([
            'version' => 1,
            'exported_at' => date('c'),
            'searches' => array_map(function($s) {
                return [
                    'name' => $s['name'],
                    'description' => $s['description'],
                    'query' => $s['query'],
                    'filters' => $s['filters'],
                    'sort_by' => $s['sort_by'],
                    'sort_order' => $s['sort_order'],
                ];
            }, $searches),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Import saved searches from JSON
     */
    public static function import(int $userId, string $json): array {
        $data = json_decode($json, true);

        if (!$data || !isset($data['searches'])) {
            return ['success' => false, 'error' => 'Invalid import data'];
        }

        $imported = 0;
        $failed = 0;

        foreach ($data['searches'] as $search) {
            $id = self::create(
                $userId,
                $search['name'] ?? 'Imported Search',
                $search['query'] ?? '',
                $search['filters'] ?? [],
                $search['sort_by'] ?? null,
                $search['sort_order'] ?? 'desc',
                $search['description'] ?? null,
                false
            );

            if ($id) {
                $imported++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => true,
            'imported' => $imported,
            'failed' => $failed,
        ];
    }
}
