<?php

class TagManagementTest extends SiloTestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new TestDatabase();
        $this->db->exec("CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            color TEXT DEFAULT '#6366f1',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            parent_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS model_tags (
            model_id INTEGER,
            tag_id INTEGER,
            PRIMARY KEY (model_id, tag_id)
        )");

        // Insert test data
        $this->db->exec("INSERT INTO models (name) VALUES ('Test Model')");
        $this->db->exec("INSERT INTO tags (name, color) VALUES ('PLA', '#ff0000')");
        $this->db->exec("INSERT INTO tags (name, color) VALUES ('ABS', '#00ff00')");

        $GLOBALS['_test_db'] = $this->db;

        // Reset tags cache
        if (class_exists('Cache')) {
            try { Cache::getInstance()->forget('all_tags'); } catch (\Throwable $e) {}
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_db']);
        parent::tearDown();
    }

    public function testGetAllTagsReturnsAllTags(): void
    {
        $tags = getAllTags(false);
        $this->assertCount(2, $tags);
        $this->assertEquals('ABS', $tags[0]['name']); // Ordered by name
        $this->assertEquals('PLA', $tags[1]['name']);
    }

    public function testGetTagByNameFindsTag(): void
    {
        $tag = getTagByName('PLA');
        $this->assertNotNull($tag);
        $this->assertEquals('PLA', $tag['name']);
        $this->assertEquals('#ff0000', $tag['color']);
    }

    public function testGetTagByNameCaseInsensitive(): void
    {
        $tag = getTagByName('pla');
        $this->assertNotNull($tag);
        $this->assertEquals('PLA', $tag['name']);
    }

    public function testGetTagByNameReturnsNullForMissing(): void
    {
        $tag = getTagByName('NonExistent');
        $this->assertNull($tag);
    }

    public function testCreateTagReturnsId(): void
    {
        $id = createTag('PETG', '#0000ff');
        $this->assertIsNumeric($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testAddTagToModel(): void
    {
        $result = addTagToModel(1, 1);
        $this->assertTrue($result);

        $tags = getModelTags(1);
        $this->assertCount(1, $tags);
        $this->assertEquals('PLA', $tags[0]['name']);
    }

    public function testRemoveTagFromModel(): void
    {
        addTagToModel(1, 1);
        $result = removeTagFromModel(1, 1);
        $this->assertTrue($result);

        $tags = getModelTags(1);
        $this->assertCount(0, $tags);
    }

    public function testGetOrCreateTagCreatesNew(): void
    {
        $id = getOrCreateTag('TPU');
        $this->assertIsNumeric($id);

        $tag = getTagByName('TPU');
        $this->assertNotNull($tag);
    }

    public function testGetOrCreateTagReturnsExisting(): void
    {
        $existingTag = getTagByName('PLA');
        $id = getOrCreateTag('PLA');
        $this->assertEquals($existingTag['id'], $id);
    }
}
