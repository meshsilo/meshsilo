<?php
/**
 * Saved Searches AJAX endpoint
 * POST: save/delete searches. GET: list user's saved searches.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/SavedSearches.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonSuccess(['searches' => SavedSearches::getUserSearches($user['id'], 20)]);
}

// POST actions
requireCsrfJson();

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'save':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            jsonError('Name required');
        }

        $searchData = SavedSearches::fromRequest($_POST);
        $id = SavedSearches::create(
            $user['id'],
            $name,
            $searchData['query'],
            $searchData['filters'],
            $searchData['sort_by'],
            $searchData['sort_order']
        );

        echo json_encode(['success' => (bool)$id, 'id' => $id]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonError('ID required');
        }
        $result = SavedSearches::delete($id, $user['id']);
        echo json_encode(['success' => $result]);
        break;

    default:
        jsonError('Invalid action');
        break;
}
