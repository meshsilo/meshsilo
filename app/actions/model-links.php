<?php
/**
 * Model External Links CRUD
 *
 * Manages external links attached to models (documentation, videos, forums, repos, etc.)
 */
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isFeatureEnabled('external_links')) {
    jsonError('External links feature is disabled', 403);
}

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

$user = getCurrentUser();

// Parse JSON body or form data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

if (in_array($action, ['add', 'remove']) && !Csrf::check()) {
    jsonError('Security validation failed', 403);
}

$db = getDB();

switch ($action) {
    case 'add':
        addLink($db, $user, $input);
        break;
    case 'delete':
        deleteLink($db, $user, $input);
        break;
    case 'reorder':
        reorderLinks($db, $user, $input);
        break;
    default:
        jsonError('Invalid action');
}

function canManageLinks($db, $user, $modelId) {
    $stmt = $db->prepare('SELECT user_id FROM models WHERE id = :id AND parent_id IS NULL');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) return false;

    return (!empty($model['user_id']) && $model['user_id'] == $user['id'])
        || !empty($user['is_admin'])
        || canEdit();
}

function addLink($db, $user, $input) {
    $modelId = (int)($input['model_id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $url = trim($input['url'] ?? '');
    $linkType = trim($input['link_type'] ?? 'other');

    if (!$modelId || !$title || !$url) {
        jsonError('Model ID, title, and URL are required');
        return;
    }

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL) && strpos($url, '/') !== 0) {
        jsonError('Invalid URL');
        return;
    }

    // Validate link type
    $validTypes = ['documentation', 'video', 'forum', 'repository', 'source', 'store', 'other'];
    if (!in_array($linkType, $validTypes)) {
        $linkType = 'other';
    }

    if (!canManageLinks($db, $user, $modelId)) {
        http_response_code(403);
        jsonError('Permission denied');
        return;
    }

    // Get next sort order
    $stmt = $db->prepare('SELECT MAX(sort_order) as max_sort FROM model_links WHERE model_id = :model_id');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $row = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);
    $sortOrder = ($row && $row['max_sort'] !== null) ? $row['max_sort'] + 1 : 0;

    $stmt = $db->prepare('INSERT INTO model_links (model_id, title, url, link_type, sort_order) VALUES (:model_id, :title, :url, :link_type, :sort_order)');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':url', $url, PDO::PARAM_STR);
    $stmt->bindValue(':link_type', $linkType, PDO::PARAM_STR);
    $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
    $stmt->execute();

    $linkId = $db->lastInsertRowID();

    logActivity('add_link', 'model', $modelId, $title);

    echo json_encode([
        'success' => true,
        'link' => [
            'id' => $linkId,
            'title' => $title,
            'url' => $url,
            'link_type' => $linkType,
            'sort_order' => $sortOrder
        ]
    ]);
}

function deleteLink($db, $user, $input) {
    $linkId = (int)($input['link_id'] ?? 0);

    if (!$linkId) {
        jsonError('Link ID required');
        return;
    }

    // Get link and model info
    $stmt = $db->prepare('SELECT ml.*, m.user_id as model_owner FROM model_links ml JOIN models m ON ml.model_id = m.id WHERE ml.id = :id');
    $stmt->bindValue(':id', $linkId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $link = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$link) {
        jsonError('Link not found');
        return;
    }

    if (!canManageLinks($db, $user, $link['model_id'])) {
        http_response_code(403);
        jsonError('Permission denied');
        return;
    }

    $stmt = $db->prepare('DELETE FROM model_links WHERE id = :id');
    $stmt->bindValue(':id', $linkId, PDO::PARAM_INT);
    $stmt->execute();

    logActivity('delete_link', 'model', $link['model_id'], $link['title']);

    jsonSuccess();
}

function reorderLinks($db, $user, $input) {
    $modelId = (int)($input['model_id'] ?? 0);
    $order = $input['order'] ?? [];

    if (!$modelId || empty($order)) {
        jsonError('Model ID and order required');
        return;
    }

    if (!canManageLinks($db, $user, $modelId)) {
        http_response_code(403);
        jsonError('Permission denied');
        return;
    }

    foreach ($order as $i => $linkId) {
        $stmt = $db->prepare('UPDATE model_links SET sort_order = :sort WHERE id = :id AND model_id = :model_id');
        $stmt->bindValue(':sort', $i, PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$linkId, PDO::PARAM_INT);
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $stmt->execute();
    }

    jsonSuccess();
}
