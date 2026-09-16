<?php
// User authentication helper functions
function getUserByLogin($login)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, username, email, password, is_admin, permissions, two_factor_secret, two_factor_enabled, created_at FROM users WHERE username = :login1 OR email = :login2');
        $result = $stmt->execute([':login1' => $login, ':login2' => $login]);
        return $result->fetch();
    } catch (Exception $e) {
        logException($e, ['action' => 'get_user_by_login', 'login' => $login]);
        return null;
    }
}

// Verify password
function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

/**
 * Transparently upgrade a legacy password hash (e.g. bcrypt from before the
 * Argon2id switch) after a successful login - the only moment the plaintext
 * is available. A plain UPDATE of the hash does not trigger session
 * revocation (that is an explicit call in the password-change flows), so
 * other sessions stay logged in. Failure is non-fatal: the user still logs
 * in with the old hash and the upgrade retries next login.
 */
function upgradePasswordHashIfNeeded(int $userId, string $password, string $hash): void
{
    if (!password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
        return;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE users SET password = :hash WHERE id = :id');
        $stmt->execute([':hash' => password_hash($password, PASSWORD_ARGON2ID), ':id' => $userId]);
        logInfo('Password hash upgraded to Argon2id', ['user_id' => $userId]);
    } catch (Exception $e) {
        logException($e, ['action' => 'password_rehash_upgrade', 'user_id' => $userId]);
    }
}
