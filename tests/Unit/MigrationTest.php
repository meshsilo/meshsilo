<?php

// Define database helper functions needed by migrations
if (!function_exists('tableExists')) {
    function tableExists($db, $table) {
        $type = $db->getType();
        if ($type === 'mysql') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
            $stmt->execute([':table' => $table]);
            return $stmt->fetchColumn() > 0;
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :table");
            $stmt->execute([':table' => $table]);
            return $stmt->fetchColumn() > 0;
        }
    }
}

if (!function_exists('columnExists')) {
    function columnExists($db, $table, $column) {
        $type = $db->getType();
        if ($type === 'mysql') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
            $stmt->execute([':table' => $table, ':column' => $column]);
            return $stmt->fetchColumn() > 0;
        } else {
            $result = $db->query("PRAGMA table_info($table)");
            while ($col = $result->fetch()) {
                if ($col['name'] === $column) {
                    return true;
                }
            }
            return false;
        }
    }
}

if (!function_exists('indexExists')) {
    function indexExists($db, $table, $indexName) {
        $type = $db->getType();
        if ($type === 'mysql') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index");
            $stmt->execute([':table' => $table, ':index' => $indexName]);
            return $stmt->fetchColumn() > 0;
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND tbl_name = :table AND name = :index");
            $stmt->execute([':table' => $table, ':index' => $indexName]);
            return $stmt->fetchColumn() > 0;
        }
    }
}

class MigrationTest extends SiloTestCase {
    private PDO $db;

    protected function setUp(): void {
        parent::setUp();
        // Create a fresh in-memory SQLite database for each test
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Helper: Check if a table exists in the SQLite database
     */
    private function tableExistsInDb(string $table): bool {
        $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:name");
        $stmt->execute([':name' => $table]);
        return $stmt->fetch() !== false;
    }

    public function testMigrationsFileExists(): void {
        $path = APP_ROOT . '/includes/migrations.php';
        $this->assertFileExists($path);
    }

    public function testGetMigrationListReturnsArray(): void {
        require_once APP_ROOT . '/includes/migrations.php';
        $migrations = getMigrationList();
        $this->assertIsArray($migrations);
        $this->assertNotEmpty($migrations);
    }

    public function testEachMigrationHasRequiredKeys(): void {
        require_once APP_ROOT . '/includes/migrations.php';
        $migrations = getMigrationList();

        foreach ($migrations as $index => $migration) {
            $this->assertArrayHasKey('name', $migration, "Migration $index missing 'name' key");
            $this->assertArrayHasKey('check', $migration, "Migration {$migration['name']} missing 'check' key");
            $this->assertArrayHasKey('apply', $migration, "Migration {$migration['name']} missing 'apply' key");
            $this->assertIsCallable($migration['check'], "Migration {$migration['name']} 'check' is not callable");
            $this->assertIsCallable($migration['apply'], "Migration {$migration['name']} 'apply' is not callable");
        }
    }

    public function testMigrationNamesAreUnique(): void {
        require_once APP_ROOT . '/includes/migrations.php';
        $migrations = getMigrationList();
        $names = array_column($migrations, 'name');
        $uniqueNames = array_unique($names);
        $this->assertCount(count($names), $uniqueNames, 'Duplicate migration names found: ' . implode(', ', array_diff_assoc($names, $uniqueNames)));
    }

    public function testCoreTablesExistAfterFirstFewMigrations(): void {
        // This test verifies that early migrations that create core tables
        // can run against an in-memory SQLite database.
        // We need the models table first since other tables reference it.
        // Create the base models table that migrations expect.
        $this->db->exec('CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            filename TEXT,
            file_path TEXT,
            file_size INTEGER DEFAULT 0,
            file_type TEXT,
            description TEXT,
            creator TEXT,
            collection TEXT,
            source_url TEXT,
            license TEXT,
            print_type TEXT,
            file_hash TEXT,
            original_size INTEGER,
            category_id INTEGER,
            user_id INTEGER,
            parent_id INTEGER,
            sort_order INTEGER DEFAULT 0,
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            is_printed INTEGER DEFAULT 0,
            download_count INTEGER DEFAULT 0,
            dim_x REAL,
            dim_y REAL,
            dim_z REAL,
            dim_unit TEXT DEFAULT "mm",
            thumbnail TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        // Create the users table that many migrations reference
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            email TEXT,
            password_hash TEXT,
            is_admin INTEGER DEFAULT 0,
            permissions TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        // Create settings table
        $this->db->exec('CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )');

        require_once APP_ROOT . '/includes/migrations.php';
        $migrations = getMigrationList();

        // Wrap the database in a simple object that has a getType() method,
        // which the migrations expect
        $dbWrapper = new class($this->db) {
            private PDO $pdo;
            public function __construct(PDO $pdo) { $this->pdo = $pdo; }
            public function getType(): string { return 'sqlite'; }
            public function exec(string $sql) { return $this->pdo->exec($sql); }
            public function prepare(string $sql) { return $this->pdo->prepare($sql); }
            public function query(string $sql) { return $this->pdo->query($sql); }
            public function lastInsertId() { return $this->pdo->lastInsertId(); }
        };

        // Run migrations that create core tables
        // Only run the first few that create fundamental tables (tags, model_tags, favorites, activity_log)
        $coreMigrationCount = min(4, count($migrations));
        $applied = 0;

        for ($i = 0; $i < $coreMigrationCount; $i++) {
            $migration = $migrations[$i];
            try {
                if (!$migration['check']($dbWrapper)) {
                    $migration['apply']($dbWrapper);
                    $applied++;
                }
            } catch (\Exception $e) {
                // Some migrations may fail due to missing dependencies;
                // that is expected. We just verify the first few core ones work.
                break;
            }
        }

        // At minimum the tags table should have been created
        $this->assertTrue(
            $this->tableExistsInDb('tags'),
            'Tags table should exist after running core migrations'
        );
    }
}
