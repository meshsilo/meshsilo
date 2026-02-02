<?php
/**
 * Share Link Actions
 * - Create/delete share links
 * - Track downloads
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

header('Content-Type: application/json');

// Check if share links feature is enabled
if (!isFeatureEnabled('share_links')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Share links feature is disabled']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        requireAuth();
        requireCsrf();
        createShareLink();
        break;
    case 'delete':
        requireAuth();
        requireCsrf();
        deleteShareLink();
        break;
    case 'list':
        requireAuth();
        listShareLinks();
        break;
    case 'validate':
        validateShareLink();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
}

function requireCsrf() {
    if (!Csrf::check()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

function createShareLink() {
    $user = getCurrentUser();

    $modelId = (int)($_POST['model_id'] ?? 0);
    $password = $_POST['password'] ?? null;
    $expiresIn = $_POST['expires_in'] ?? null;
    $maxDownloads = (int)($_POST['max_downloads'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    // Verify model ownership (user must own the model or be admin)
    $db = getDB();
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id AND parent_id IS NULL');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetch(PDO::FETCH_ASSOC);

    if (!$model) {
        echo json_encode(['success' => false, 'error' => 'Model not found']);
        return;
    }

    if ($model['user_id'] != $user['id'] && !$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'You do not own this model']);
        return;
    }

    // Generate unique token
    $token = bin2hex(random_bytes(32));

    // Calculate expiration
    $expiresAt = null;
    if ($expiresIn) {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiresIn"));
    }

    // Hash password if provided
    $passwordHash = null;
    if ($password) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }

    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO share_links (model_id, user_id, token, password_hash, expires_at, max_downloads)
        VALUES (:model_id, :user_id, :token, :password_hash, :expires_at, :max_downloads)
    ');
    $stmt->execute([
        ':model_id' => $modelId,
        ':user_id' => $user['id'],
        ':token' => $token,
        ':password_hash' => $passwordHash,
        ':expires_at' => $expiresAt,
        ':max_downloads' => $maxDownloads ?: null
    ]);

    $linkId = $db->lastInsertId();

    // Build share URL
    $siteUrl = getSetting('site_url', '');
    $shareUrl = rtrim($siteUrl, '/') . '/share.php?t=' . $token;

    logActivity('create_share_link', 'model', $modelId, null, ['link_id' => $linkId]);

    echo json_encode([
        'success' => true,
        'link_id' => $linkId,
        'token' => $token,
        'share_url' => $shareUrl,
        'expires_at' => $expiresAt,
        'has_password' => !empty($password)
    ]);
}

function deleteShareLink() {
    $user = getCurrentUser();

    $linkId = (int)($_POST['link_id'] ?? 0);
    if (!$linkId) {
        echo json_encode(['success' => false, 'error' => 'Link ID required']);
        return;
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT user_id, model_id FROM share_links WHERE id = :id');
    $stmt->execute([':id' => $linkId]);
    $link = $stmt->fetch();

    if (!$link || ($link['user_id'] !== $user['id'] && !$user['is_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM share_links WHERE id = :id');
    $stmt->execute([':id' => $linkId]);

    logActivity('delete_share_link', 'model', $link['model_id'], null, ['link_id' => $linkId]);

    echo json_encode(['success' => true]);
}

function listShareLinks() {
    $user = getCurrentUser();
    $modelId = (int)($_GET['model_id'] ?? 0);

    $db = getDB();

    if ($modelId) {
        $stmt = $db->prepare('
            SELECT sl.*, m.name as model_name
            FROM share_links sl
            JOIN models m ON sl.model_id = m.id
            WHERE sl.model_id = :model_id AND (sl.user_id = :user_id OR :is_admin = 1)
            ORDER BY sl.created_at DESC
        ');
        $stmt->execute([
            ':model_id' => $modelId,
            ':user_id' => $user['id'],
            ':is_admin' => $user['is_admin'] ? 1 : 0
        ]);
    } else {
        $stmt = $db->prepare('
            SELECT sl.*, m.name as model_name
            FROM share_links sl
            JOIN models m ON sl.model_id = m.id
            WHERE sl.user_id = :user_id OR :is_admin = 1
            ORDER BY sl.created_at DESC
            LIMIT 100
        ');
        $stmt->execute([
            ':user_id' => $user['id'],
            ':is_admin' => $user['is_admin'] ? 1 : 0
        ]);
    }

    $links = [];
    $siteUrl = getSetting('site_url', '');
    while ($row = $stmt->fetch()) {
        $row['share_url'] = rtrim($siteUrl, '/') . '/share.php?t=' . $row['token'];
        $row['has_password'] = !empty($row['password_hash']);
        $row['is_expired'] = $row['expires_at'] && strtotime($row['expires_at']) < time();
        $row['downloads_remaining'] = $row['max_downloads'] ? ($row['max_downloads'] - $row['download_count']) : null;
        unset($row['password_hash']);
        $links[] = $row;
    }

    echo json_encode(['success' => true, 'links' => $links]);
}

function validateShareLink() {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $password = $_POST['password'] ?? null;

    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Token required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT sl.*, m.name as model_name, m.filename, m.file_path
        FROM share_links sl
        JOIN models m ON sl.model_id = m.id
        WHERE sl.token = :token AND sl.is_active = 1
    ');
    $stmt->execute([':token' => $token]);
    $link = $stmt->fetch();

    if (!$link) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired link']);
        return;
    }

    // Check expiration
    if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
        echo json_encode(['success' => false, 'error' => 'Link has expired']);
        return;
    }

    // Check download limit
    if ($link['max_downloads'] && $link['download_count'] >= $link['max_downloads']) {
        echo json_encode(['success' => false, 'error' => 'Download limit reached']);
        return;
    }

    // Check password
    if ($link['password_hash']) {
        if (!$password) {
            echo json_encode(['success' => false, 'error' => 'Password required', 'requires_password' => true]);
            return;
        }
        if (!password_verify($password, $link['password_hash'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid password']);
            return;
        }
    }

    echo json_encode([
        'success' => true,
        'model_id' => $link['model_id'],
        'model_name' => $link['model_name'],
        'filename' => $link['filename'],
        'downloads_remaining' => $link['max_downloads'] ? ($link['max_downloads'] - $link['download_count']) : null
    ]);
}
