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
            echo json_encode(['success' => false, 'error' => 'Name required']);
            exit;
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
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        $result = SavedSearches::delete($id, $user['id']);
        echo json_encode(['success' => $result]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
