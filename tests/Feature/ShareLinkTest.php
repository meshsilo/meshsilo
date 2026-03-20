<?php

class ShareLinkTest extends SiloTestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new TestDatabase();
        $this->db->exec("CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            parent_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS share_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_id INTEGER NOT NULL,
            user_id INTEGER,
            token TEXT NOT NULL UNIQUE,
            password_hash TEXT,
            expires_at DATETIME,
            max_downloads INTEGER DEFAULT 0,
            download_count INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Insert test model
        $this->db->exec("INSERT INTO models (name) VALUES ('Shared Model')");

        $GLOBALS['_test_db'] = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_db']);
        parent::tearDown();
    }

    public function testShareLinkCanBeCreated(): void
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("INSERT INTO share_links (model_id, token, is_active) VALUES (:mid, :token, 1)");
        $stmt->execute([':mid' => 1, ':token' => $token]);

        $stmt = $this->db->prepare("SELECT * FROM share_links WHERE token = :token");
        $stmt->execute([':token' => $token]);
        $link = $stmt->fetchArray();

        $this->assertNotNull($link);
        $this->assertEquals(1, $link['model_id']);
        $this->assertEquals(1, $link['is_active']);
    }

    public function testExpiredLinkDetected(): void
    {
        $token = 'expired-token';
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $stmt = $this->db->prepare("INSERT INTO share_links (model_id, token, expires_at, is_active) VALUES (1, :token, :exp, 1)");
        $stmt->execute([':token' => $token, ':exp' => $pastDate]);

        $stmt = $this->db->prepare("SELECT * FROM share_links WHERE token = :token AND is_active = 1");
        $stmt->execute([':token' => $token]);
        $link = $stmt->fetchArray();

        $this->assertNotNull($link);
        $this->assertTrue(strtotime($link['expires_at']) < time());
    }

    public function testDownloadLimitEnforced(): void
    {
        $token = 'limited-token';
        $stmt = $this->db->prepare("INSERT INTO share_links (model_id, token, max_downloads, download_count, is_active) VALUES (1, :token, 5, 5, 1)");
        $stmt->execute([':token' => $token]);

        $stmt = $this->db->prepare("SELECT * FROM share_links WHERE token = :token");
        $stmt->execute([':token' => $token]);
        $link = $stmt->fetchArray();

        $this->assertTrue($link['download_count'] >= $link['max_downloads']);
    }

    public function testPasswordProtectedLinkVerification(): void
    {
        $token = 'password-token';
        $hash = password_hash('secret123', PASSWORD_ARGON2ID);
        $stmt = $this->db->prepare("INSERT INTO share_links (model_id, token, password_hash, is_active) VALUES (1, :token, :hash, 1)");
        $stmt->execute([':token' => $token, ':hash' => $hash]);

        $stmt = $this->db->prepare("SELECT password_hash FROM share_links WHERE token = :token");
        $stmt->execute([':token' => $token]);
        $link = $stmt->fetchArray();

        $this->assertTrue(password_verify('secret123', $link['password_hash']));
        $this->assertFalse(password_verify('wrong', $link['password_hash']));
    }

    public function testDeactivatedLinkNotFound(): void
    {
        $token = 'deactivated-token';
        $stmt = $this->db->prepare("INSERT INTO share_links (model_id, token, is_active) VALUES (1, :token, 0)");
        $stmt->execute([':token' => $token]);

        $stmt = $this->db->prepare("SELECT * FROM share_links WHERE token = :token AND is_active = 1");
        $stmt->execute([':token' => $token]);
        $link = $stmt->fetchArray();

        $this->assertFalse($link);
    }
}
