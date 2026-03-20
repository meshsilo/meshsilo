<?php

class LoginTest extends SiloTestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new TestDatabase();
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            is_admin INTEGER DEFAULT 0,
            permissions TEXT,
            two_factor_secret TEXT,
            two_factor_enabled INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Insert test user
        $hash = password_hash('testpass123', PASSWORD_ARGON2ID);
        $stmt = $this->db->prepare("INSERT INTO users (username, email, password, is_admin) VALUES (:u, :e, :p, :a)");
        $stmt->execute([':u' => 'testuser', ':e' => 'test@example.com', ':p' => $hash, ':a' => 0]);

        // Make getDB() return our test database
        $GLOBALS['_test_db'] = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_db']);
        parent::tearDown();
    }

    public function testVerifyPasswordWithCorrectPassword(): void
    {
        $hash = password_hash('correct', PASSWORD_ARGON2ID);
        $this->assertTrue(verifyPassword('correct', $hash));
    }

    public function testVerifyPasswordWithWrongPassword(): void
    {
        $hash = password_hash('correct', PASSWORD_ARGON2ID);
        $this->assertFalse(verifyPassword('wrong', $hash));
    }

    public function testVerifyPasswordWithBcryptHash(): void
    {
        // Existing bcrypt hashes should still work after Argon2id upgrade
        $hash = password_hash('legacy', PASSWORD_BCRYPT);
        $this->assertTrue(verifyPassword('legacy', $hash));
    }

    public function testIsLoggedInReturnsFalseWhenNoSession(): void
    {
        unset($_SESSION['user_id']);
        $this->assertFalse(isLoggedIn());
    }

    public function testIsLoggedInReturnsTrueWithSession(): void
    {
        $_SESSION['user_id'] = 1;
        $this->assertTrue(isLoggedIn());
    }

    public function testGetCurrentUserReturnsNullWhenNotLoggedIn(): void
    {
        unset($_SESSION['user']);
        $this->assertNull(getCurrentUser());
    }

    public function testGetCurrentUserReturnsUserData(): void
    {
        $_SESSION['user'] = ['id' => 1, 'username' => 'testuser'];
        $user = getCurrentUser();
        $this->assertEquals('testuser', $user['username']);
    }
}
