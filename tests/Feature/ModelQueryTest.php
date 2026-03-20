<?php

class ModelQueryTest extends SiloTestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new TestDatabase();
        $this->db->exec("CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            filename TEXT,
            file_path TEXT,
            file_size INTEGER,
            file_type TEXT,
            description TEXT,
            creator TEXT,
            parent_id INTEGER,
            part_count INTEGER DEFAULT 0,
            print_type TEXT,
            original_size INTEGER,
            file_hash TEXT,
            dedup_path TEXT,
            sort_order INTEGER DEFAULT 0,
            original_path TEXT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_archived INTEGER DEFAULT 0,
            thumbnail_path TEXT,
            download_count INTEGER DEFAULT 0
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            color TEXT DEFAULT '#6366f1'
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS model_tags (
            model_id INTEGER,
            tag_id INTEGER,
            PRIMARY KEY (model_id, tag_id)
        )");

        // Insert test data
        $this->db->exec("INSERT INTO models (name, creator, file_type, file_size, part_count) VALUES ('Parent Model', 'TestUser', 'stl', 1024, 2)");
        $this->db->exec("INSERT INTO models (name, file_type, file_size, parent_id, sort_order, original_path) VALUES ('Part A', 'stl', 512, 1, 0, 'parts/a.stl')");
        $this->db->exec("INSERT INTO models (name, file_type, file_size, parent_id, sort_order, original_path) VALUES ('Part B', '3mf', 256, 1, 1, 'parts/b.3mf')");

        $this->db->exec("INSERT INTO tags (name, color) VALUES ('PLA', '#ff0000')");
        $this->db->exec("INSERT INTO model_tags (model_id, tag_id) VALUES (1, 1)");

        $GLOBALS['_test_db'] = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_db']);
        parent::tearDown();
    }

    public function testGetFirstPartsForModelsReturnsParts(): void
    {
        $parts = getFirstPartsForModels([1]);
        $this->assertArrayHasKey(1, $parts);
        $this->assertEquals('Part A', $parts[1]['name']);
    }

    public function testGetFirstPartsForModelsEmptyInput(): void
    {
        $parts = getFirstPartsForModels([]);
        $this->assertEmpty($parts);
    }

    public function testGetTagsForModelsReturnsTags(): void
    {
        $tags = getTagsForModels([1]);
        $this->assertArrayHasKey(1, $tags);
        $this->assertCount(1, $tags[1]);
        $this->assertEquals('PLA', $tags[1][0]['name']);
    }

    public function testGetTagsForModelsEmptyInput(): void
    {
        $tags = getTagsForModels([]);
        $this->assertEmpty($tags);
    }

    public function testGetModelTagsReturnsModelTags(): void
    {
        $tags = getModelTags(1);
        $this->assertCount(1, $tags);
        $this->assertEquals('PLA', $tags[0]['name']);
    }

    public function testGetModelTagsEmptyForUntaggedModel(): void
    {
        $tags = getModelTags(2); // Part A has no tags
        $this->assertEmpty($tags);
    }

    public function testGetModelFilePathWithRelativePath(): void
    {
        if (!defined('UPLOAD_PATH')) {
            define('UPLOAD_PATH', '/var/www/meshsilo/storage/assets/');
        }
        $model = ['file_path' => 'models/test.stl', 'dedup_path' => null];
        $path = getModelFilePath($model);
        $this->assertStringContainsString('models/test.stl', $path);
    }

    public function testGetModelFilePathWithDedupPath(): void
    {
        $model = ['file_path' => 'models/test.stl', 'dedup_path' => 'dedup/abc123.stl'];
        $path = getModelFilePath($model);
        $this->assertStringContainsString('dedup/abc123.stl', $path);
    }

    public function testGetModelFilePathNullForEmptyPath(): void
    {
        $model = ['file_path' => '', 'dedup_path' => null];
        $path = getModelFilePath($model);
        $this->assertNull($path);
    }
}
