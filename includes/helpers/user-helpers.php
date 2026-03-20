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
