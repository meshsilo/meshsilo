<?php
/**
 * Full-Text Search System
 *
 * Provides full-text search capabilities using SQLite FTS5 when available,
 * with fallback to LIKE-based search. Supports indexing models with their
 * names, descriptions, tags, and categories.
 */

class Search {
    private $db;
    private bool $ftsAvailable = false;
    private static ?self $instance = null;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->db = function_exists('getDB') ? getDB() : null;
        $this->checkFtsAvailability();
    }

    /**
     * Check if FTS5 is available
     */
    private function checkFtsAvailability(): void {
        if (!$this->db) return;

        try {
            // Check if FTS5 is compiled in
            $result = $this->db->query("SELECT sqlite_compileoption_used('ENABLE_FTS5')");
            $row = $result->fetchArray();
            $this->ftsAvailable = ($row[0] ?? 0) == 1;
        } catch (Exception $e) {
            $this->ftsAvailable = false;
        }
    }

    /**
     * Initialize search tables
     */
    public function initialize(): bool {
        if (!$this->db) return false;

        if ($this->ftsAvailable) {
            return $this->initializeFts();
        } else {
            return $this->initializeFallback();
        }
    }

    /**
     * Initialize FTS5 virtual table
     */
    private function initializeFts(): bool {
        // Create FTS5 virtual table
        $sql = "
            CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                model_id UNINDEXED,
                name,
                description,
                tags,
                category,
                file_names,
                content='',
                tokenize='porter unicode61'
            )
        ";

        try {
            $this->db->exec($sql);
            return true;
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Failed to create FTS5 table', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Initialize fallback search table
     */
    private function initializeFallback(): bool {
        $sql = "
            CREATE TABLE IF NOT EXISTS search_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL UNIQUE,
                name TEXT,
                description TEXT,
                tags TEXT,
                category TEXT,
                file_names TEXT,
                search_text TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ";

        try {
            $this->db->exec($sql);

            // Create index on search_text for faster LIKE queries
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_search_text ON search_index(search_text)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_search_model ON search_index(model_id)");

            return true;
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Failed to create search table', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Index a model
     */
    public function indexModel(int $modelId): bool {
        if (!$this->db) return false;

        // Get model data
        $stmt = $this->db->prepare("
            SELECT m.id, m.name, m.description, m.notes,
                   c.name as category_name,
                   GROUP_CONCAT(DISTINCT t.name) as tags,
                   GROUP_CONCAT(DISTINCT p.filename) as file_names
            FROM models m
            LEFT JOIN categories c ON m.category_id = c.id
            LEFT JOIN model_tags mt ON m.id = mt.model_id
            LEFT JOIN tags t ON mt.tag_id = t.id
            LEFT JOIN parts p ON m.id = p.model_id
            WHERE m.id = :id
            GROUP BY m.id
        ");
        $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $model = $result->fetchArray(SQLITE3_ASSOC);

        if (!$model) return false;

        // Prepare search data
        $data = [
            'model_id' => $modelId,
            'name' => $model['name'] ?? '',
            'description' => ($model['description'] ?? '') . ' ' . ($model['notes'] ?? ''),
            'tags' => $model['tags'] ?? '',
            'category' => $model['category_name'] ?? '',
            'file_names' => $model['file_names'] ?? ''
        ];

        if ($this->ftsAvailable) {
            return $this->indexModelFts($data);
        } else {
            return $this->indexModelFallback($data);
        }
    }

    /**
     * Index model using FTS5
     */
    private function indexModelFts(array $data): bool {
        try {
            // Delete existing entry
            $stmt = $this->db->prepare("DELETE FROM search_index WHERE model_id = :id");
            $stmt->bindValue(':id', $data['model_id'], SQLITE3_INTEGER);
            $stmt->execute();

            // Insert new entry
            $stmt = $this->db->prepare("
                INSERT INTO search_index (model_id, name, description, tags, category, file_names)
                VALUES (:model_id, :name, :description, :tags, :category, :file_names)
            ");

            $stmt->bindValue(':model_id', $data['model_id'], SQLITE3_INTEGER);
            $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':tags', $data['tags'], SQLITE3_TEXT);
            $stmt->bindValue(':category', $data['category'], SQLITE3_TEXT);
            $stmt->bindValue(':file_names', $data['file_names'], SQLITE3_TEXT);

            return $stmt->execute() !== false;
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('FTS index failed', ['model_id' => $data['model_id'], 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Index model using fallback table
     */
    private function indexModelFallback(array $data): bool {
        // Create combined search text
        $searchText = strtolower(implode(' ', [
            $data['name'],
            $data['description'],
            $data['tags'],
            $data['category'],
            $data['file_names']
        ]));

        try {
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO search_index
                (model_id, name, description, tags, category, file_names, search_text, updated_at)
                VALUES (:model_id, :name, :description, :tags, :category, :file_names, :search_text, :updated_at)
            ");

            $stmt->bindValue(':model_id', $data['model_id'], SQLITE3_INTEGER);
            $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':tags', $data['tags'], SQLITE3_TEXT);
            $stmt->bindValue(':category', $data['category'], SQLITE3_TEXT);
            $stmt->bindValue(':file_names', $data['file_names'], SQLITE3_TEXT);
            $stmt->bindValue(':search_text', $searchText, SQLITE3_TEXT);
            $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

            return $stmt->execute() !== false;
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Fallback index failed', ['model_id' => $data['model_id'], 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Remove a model from the index
     */
    public function removeModel(int $modelId): bool {
        if (!$this->db) return false;

        try {
            $stmt = $this->db->prepare("DELETE FROM search_index WHERE model_id = :id");
            $stmt->bindValue(':id', $modelId, SQLITE3_INTEGER);
            return $stmt->execute() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Search for models
     */
    public function search(string $query, array $options = []): array {
        if (!$this->db || trim($query) === '') {
            return ['results' => [], 'total' => 0];
        }

        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;
        $categoryId = $options['category_id'] ?? null;

        if ($this->ftsAvailable) {
            return $this->searchFts($query, $limit, $offset, $categoryId);
        } else {
            return $this->searchFallback($query, $limit, $offset, $categoryId);
        }
    }

    /**
     * Search using FTS5
     */
    private function searchFts(string $query, int $limit, int $offset, ?int $categoryId): array {
        // Escape and prepare FTS query
        $ftsQuery = $this->prepareFtsQuery($query);

        $sql = "
            SELECT s.model_id, m.name, m.description, m.thumbnail, m.created_at,
                   c.name as category_name, c.id as category_id,
                   bm25(search_index) as rank
            FROM search_index s
            JOIN models m ON s.model_id = m.id
            LEFT JOIN categories c ON m.category_id = c.id
            WHERE search_index MATCH :query
            AND m.is_archived = 0
        ";

        if ($categoryId !== null) {
            $sql .= " AND m.category_id = :category_id";
        }

        $sql .= " ORDER BY rank LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':query', $ftsQuery, SQLITE3_TEXT);
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
            if ($categoryId !== null) {
                $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
            }

            $result = $stmt->execute();
            $results = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }

            // Get total count
            $countSql = "
                SELECT COUNT(*) as total
                FROM search_index s
                JOIN models m ON s.model_id = m.id
                WHERE search_index MATCH :query
                AND m.is_archived = 0
            ";
            if ($categoryId !== null) {
                $countSql .= " AND m.category_id = :category_id";
            }

            $stmt = $this->db->prepare($countSql);
            $stmt->bindValue(':query', $ftsQuery, SQLITE3_TEXT);
            if ($categoryId !== null) {
                $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
            }
            $countResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            return [
                'results' => $results,
                'total' => (int)($countResult['total'] ?? 0),
                'query' => $query,
                'fts' => true
            ];
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('FTS search failed', ['query' => $query, 'error' => $e->getMessage()]);
            }
            // Fall back to LIKE search
            return $this->searchFallback($query, $limit, $offset, $categoryId);
        }
    }

    /**
     * Search using LIKE fallback
     */
    private function searchFallback(string $query, int $limit, int $offset, ?int $categoryId): array {
        // Split query into terms
        $terms = preg_split('/\s+/', strtolower(trim($query)));
        $terms = array_filter($terms, fn($t) => strlen($t) >= 2);

        if (empty($terms)) {
            return ['results' => [], 'total' => 0];
        }

        // Build LIKE conditions
        $conditions = [];
        $params = [];
        foreach ($terms as $i => $term) {
            $key = ":term$i";
            $conditions[] = "s.search_text LIKE $key";
            $params[$key] = '%' . $term . '%';
        }

        $sql = "
            SELECT s.model_id, m.name, m.description, m.thumbnail, m.created_at,
                   c.name as category_name, c.id as category_id
            FROM search_index s
            JOIN models m ON s.model_id = m.id
            LEFT JOIN categories c ON m.category_id = c.id
            WHERE (" . implode(' AND ', $conditions) . ")
            AND m.is_archived = 0
        ";

        if ($categoryId !== null) {
            $sql .= " AND m.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }

        $sql .= " ORDER BY m.name ASC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
                $stmt->bindValue($key, $value, $type);
            }

            $result = $stmt->execute();
            $results = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }

            // Get total count
            $countSql = "
                SELECT COUNT(*) as total
                FROM search_index s
                JOIN models m ON s.model_id = m.id
                WHERE (" . implode(' AND ', $conditions) . ")
                AND m.is_archived = 0
            ";
            if ($categoryId !== null) {
                $countSql .= " AND m.category_id = :category_id";
            }

            $stmt = $this->db->prepare($countSql);
            foreach ($params as $key => $value) {
                if ($key === ':limit' || $key === ':offset') continue;
                $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
                $stmt->bindValue($key, $value, $type);
            }
            $countResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            return [
                'results' => $results,
                'total' => (int)($countResult['total'] ?? 0),
                'query' => $query,
                'fts' => false
            ];
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Fallback search failed', ['query' => $query, 'error' => $e->getMessage()]);
            }
            return ['results' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Prepare FTS5 query string
     */
    private function prepareFtsQuery(string $query): string {
        // Remove special FTS characters
        $query = preg_replace('/[":*^~(){}[\]\\\\]/', ' ', $query);

        // Split into terms
        $terms = preg_split('/\s+/', trim($query));
        $terms = array_filter($terms, fn($t) => strlen($t) >= 2);

        // Build query with prefix matching
        $ftsTerms = array_map(fn($t) => '"' . $t . '"*', $terms);

        return implode(' AND ', $ftsTerms);
    }

    /**
     * Rebuild entire search index
     */
    public function rebuildIndex(): array {
        if (!$this->db) {
            return ['success' => false, 'error' => 'No database connection'];
        }

        // Clear existing index
        try {
            $this->db->exec("DELETE FROM search_index");
        } catch (Exception $e) {
            // Table might not exist
            $this->initialize();
        }

        // Get all models
        $result = $this->db->query("SELECT id FROM models WHERE is_archived = 0");
        $indexed = 0;
        $failed = 0;

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($this->indexModel($row['id'])) {
                $indexed++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => true,
            'indexed' => $indexed,
            'failed' => $failed,
            'fts_available' => $this->ftsAvailable
        ];
    }

    /**
     * Get search suggestions (autocomplete)
     */
    public function suggest(string $query, int $limit = 10): array {
        if (!$this->db || strlen($query) < 2) {
            return [];
        }

        $query = strtolower(trim($query));

        // Search model names
        $stmt = $this->db->prepare("
            SELECT DISTINCT name
            FROM models
            WHERE LOWER(name) LIKE :query
            AND is_archived = 0
            ORDER BY download_count DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':query', $query . '%', SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $suggestions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $suggestions[] = $row['name'];
        }

        // Also search tags
        $stmt = $this->db->prepare("
            SELECT DISTINCT name
            FROM tags
            WHERE LOWER(name) LIKE :query
            LIMIT :limit
        ");
        $stmt->bindValue(':query', $query . '%', SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit - count($suggestions), SQLITE3_INTEGER);

        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $suggestions[] = $row['name'];
        }

        return array_slice(array_unique($suggestions), 0, $limit);
    }

    /**
     * Check if FTS is available
     */
    public function isFtsAvailable(): bool {
        return $this->ftsAvailable;
    }

    /**
     * Get index statistics
     */
    public function stats(): array {
        if (!$this->db) {
            return ['error' => 'No database'];
        }

        $total = $this->db->querySingle("SELECT COUNT(*) FROM search_index");
        $models = $this->db->querySingle("SELECT COUNT(*) FROM models WHERE is_archived = 0");

        return [
            'indexed' => (int)$total,
            'total_models' => (int)$models,
            'coverage' => $models > 0 ? round(($total / $models) * 100, 1) : 0,
            'fts_available' => $this->ftsAvailable
        ];
    }
}

/**
 * Helper function
 */
function search(string $query, array $options = []): array {
    return Search::getInstance()->search($query, $options);
}
