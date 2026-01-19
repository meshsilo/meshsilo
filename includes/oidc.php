<?php
/**
 * OIDC (OpenID Connect) authentication helper
 */

/**
 * Check if OIDC is enabled and properly configured
 */
function isOIDCEnabled() {
    return getSetting('oidc_enabled', '0') === '1'
        && !empty(getSetting('oidc_provider_url'))
        && !empty(getSetting('oidc_client_id'))
        && !empty(getSetting('oidc_client_secret'));
}

/**
 * Get OIDC configuration from provider's discovery endpoint
 */
function getOIDCConfig() {
    $providerUrl = rtrim(getSetting('oidc_provider_url', ''), '/');
    $discoveryUrl = $providerUrl . '/.well-known/openid-configuration';

    $cacheKey = 'oidc_config_cache';
    $cached = getSetting($cacheKey);

    if ($cached) {
        $data = json_decode($cached, true);
        if ($data && isset($data['expires']) && $data['expires'] > time()) {
            return $data['config'];
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents($discoveryUrl, false, $context);

    if ($response === false) {
        logError('OIDC discovery failed', ['url' => $discoveryUrl]);
        return null;
    }

    $config = json_decode($response, true);

    if (!$config || !isset($config['authorization_endpoint'])) {
        logError('OIDC invalid config response', ['response' => $response]);
        return null;
    }

    // Cache for 1 hour
    setSetting($cacheKey, json_encode([
        'config' => $config,
        'expires' => time() + 3600
    ]));

    return $config;
}

/**
 * Generate the authorization URL for OIDC login
 */
function getOIDCAuthUrl() {
    $config = getOIDCConfig();

    if (!$config) {
        return null;
    }

    // Generate state and nonce for security
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));

    // Store in session for verification
    $_SESSION['oidc_state'] = $state;
    $_SESSION['oidc_nonce'] = $nonce;

    $clientId = getSetting('oidc_client_id');
    $redirectUri = getOIDCRedirectUri();

    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce
    ];

    return $config['authorization_endpoint'] . '?' . http_build_query($params);
}

/**
 * Get the OIDC redirect URI
 */
function getOIDCRedirectUri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . basePath('oidc-callback.php');
}

/**
 * Exchange authorization code for tokens
 */
function exchangeCodeForTokens($code) {
    $config = getOIDCConfig();

    if (!$config) {
        return null;
    }

    $clientId = getSetting('oidc_client_id');
    $clientSecret = getSetting('oidc_client_secret');
    $redirectUri = getOIDCRedirectUri();

    $postData = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'client_secret' => $clientSecret
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($postData),
            'timeout' => 10,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents($config['token_endpoint'], false, $context);

    if ($response === false) {
        logError('OIDC token exchange failed', ['endpoint' => $config['token_endpoint']]);
        return null;
    }

    $tokens = json_decode($response, true);

    if (!$tokens || isset($tokens['error'])) {
        logError('OIDC token exchange error', ['response' => $tokens]);
        return null;
    }

    return $tokens;
}

/**
 * Get user info from OIDC provider
 */
function getOIDCUserInfo($accessToken) {
    $config = getOIDCConfig();

    if (!$config || !isset($config['userinfo_endpoint'])) {
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'header' => 'Authorization: Bearer ' . $accessToken,
            'timeout' => 10,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents($config['userinfo_endpoint'], false, $context);

    if ($response === false) {
        logError('OIDC userinfo failed', ['endpoint' => $config['userinfo_endpoint']]);
        return null;
    }

    return json_decode($response, true);
}

/**
 * Decode and validate ID token (basic validation)
 */
function decodeIdToken($idToken) {
    $parts = explode('.', $idToken);

    if (count($parts) !== 3) {
        return null;
    }

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if (!$payload) {
        return null;
    }

    // Verify nonce
    if (isset($_SESSION['oidc_nonce']) && isset($payload['nonce'])) {
        if ($payload['nonce'] !== $_SESSION['oidc_nonce']) {
            logWarning('OIDC nonce mismatch');
            return null;
        }
    }

    // Verify expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        logWarning('OIDC token expired');
        return null;
    }

    return $payload;
}

/**
 * Find or create user from OIDC data
 */
function findOrCreateOIDCUser($userInfo, $idToken) {
    $db = getDB();

    // Get the subject identifier (unique user ID from provider)
    $sub = $userInfo['sub'] ?? null;

    if (!$sub) {
        logError('OIDC missing sub claim', ['userInfo' => $userInfo]);
        return null;
    }

    // Get email and name
    $email = $userInfo['email'] ?? null;
    $name = $userInfo['preferred_username'] ?? $userInfo['name'] ?? $userInfo['email'] ?? 'oidc_user';

    // First, check if we have a user with this OIDC ID
    $stmt = $db->prepare('SELECT * FROM users WHERE oidc_id = :oidc_id');
    $stmt->bindValue(':oidc_id', $sub, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if ($user) {
        // Update email if changed
        if ($email && $email !== $user['email']) {
            $stmt = $db->prepare('UPDATE users SET email = :email WHERE id = :id');
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
            $stmt->execute();
            $user['email'] = $email;
        }
        return $user;
    }

    // Check if user exists with same email
    if ($email) {
        $stmt = $db->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);

        if ($user) {
            // Link existing account to OIDC
            $stmt = $db->prepare('UPDATE users SET oidc_id = :oidc_id WHERE id = :id');
            $stmt->bindValue(':oidc_id', $sub, SQLITE3_TEXT);
            $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
            $stmt->execute();
            $user['oidc_id'] = $sub;

            logInfo('OIDC linked to existing user', ['user_id' => $user['id'], 'email' => $email]);
            return $user;
        }
    }

    // Create new user
    // Make username unique
    $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    if (empty($baseUsername)) {
        $baseUsername = 'user';
    }
    $username = $baseUsername;
    $counter = 1;

    while (true) {
        $stmt = $db->prepare('SELECT id FROM users WHERE username = :username');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();

        if (!$result->fetchArray()) {
            break;
        }

        $username = $baseUsername . $counter;
        $counter++;
    }

    // Generate a random password (user can't log in with it, OIDC only)
    $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $stmt = $db->prepare('INSERT INTO users (username, email, password, oidc_id, is_admin) VALUES (:username, :email, :password, :oidc_id, 0)');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email ?? $username . '@oidc.local', SQLITE3_TEXT);
    $stmt->bindValue(':password', $randomPassword, SQLITE3_TEXT);
    $stmt->bindValue(':oidc_id', $sub, SQLITE3_TEXT);
    $stmt->execute();

    $userId = $db->lastInsertRowID();

    // Add to default Users group
    $usersGroupId = $db->querySingle("SELECT id FROM groups WHERE name = 'Users'");
    if ($usersGroupId) {
        $stmt = $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':group_id', $usersGroupId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    logInfo('OIDC created new user', ['user_id' => $userId, 'username' => $username, 'email' => $email]);

    // Fetch and return the new user
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

/**
 * Test OIDC connection
 */
function testOIDCConnection() {
    $providerUrl = getSetting('oidc_provider_url');

    if (empty($providerUrl)) {
        return ['success' => false, 'message' => 'Provider URL not configured'];
    }

    $config = getOIDCConfig();

    if (!$config) {
        return ['success' => false, 'message' => 'Failed to fetch OIDC configuration from provider'];
    }

    $required = ['authorization_endpoint', 'token_endpoint', 'issuer'];
    $missing = [];

    foreach ($required as $key) {
        if (!isset($config[$key])) {
            $missing[] = $key;
        }
    }

    if (!empty($missing)) {
        return ['success' => false, 'message' => 'Provider config missing: ' . implode(', ', $missing)];
    }

    return [
        'success' => true,
        'message' => 'Connection successful',
        'issuer' => $config['issuer'],
        'endpoints' => [
            'authorization' => $config['authorization_endpoint'],
            'token' => $config['token_endpoint'],
            'userinfo' => $config['userinfo_endpoint'] ?? 'Not available'
        ]
    ];
}
