<?php
/**
 * Homepage Configuration Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();

if (!$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if ($action === 'save' && !Csrf::check()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

switch ($action) {
    case 'save':
        saveHomepageConfig();
        break;
    case 'get':
        getHomepageConfig();
        break;
    case 'preview':
        getHomepagePreview();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function saveHomepageConfig() {
    $config = [
        'layout' => $_POST['layout'] ?? 'grid',
        'show_welcome' => isset($_POST['show_welcome']) ? true : false,
        'welcome_title' => trim($_POST['welcome_title'] ?? ''),
        'welcome_message' => trim($_POST['welcome_message'] ?? ''),
        'show_stats' => isset($_POST['show_stats']) ? true : false,
        'show_recent' => isset($_POST['show_recent']) ? true : false,
        'recent_count' => (int)($_POST['recent_count'] ?? 8),
        'show_featured' => isset($_POST['show_featured']) ? true : false,
        'featured_category_id' => (int)($_POST['featured_category_id'] ?? 0) ?: null,
        'featured_tag_id' => (int)($_POST['featured_tag_id'] ?? 0) ?: null,
        'featured_collection' => trim($_POST['featured_collection'] ?? ''),
        'show_categories' => isset($_POST['show_categories']) ? true : false,
        'show_quick_search' => isset($_POST['show_quick_search']) ? true : false,
        'custom_css' => trim($_POST['custom_css'] ?? ''),
        'sections_order' => $_POST['sections_order'] ?? ['welcome', 'stats', 'recent', 'featured', 'categories']
    ];

    if (is_string($config['sections_order'])) {
        $config['sections_order'] = json_decode($config['sections_order'], true) ?: ['welcome', 'stats', 'recent', 'featured', 'categories'];
    }

    setSetting('homepage_config', json_encode($config));

    echo json_encode(['success' => true]);
}

function getHomepageConfig() {
    $json = getSetting('homepage_config', '');
    $config = $json ? json_decode($json, true) : [];

    // Apply defaults
    $defaults = [
        'layout' => 'grid',
        'show_welcome' => true,
        'welcome_title' => '',
        'welcome_message' => '',
        'show_stats' => true,
        'show_recent' => true,
        'recent_count' => 8,
        'show_featured' => false,
        'featured_category_id' => null,
        'featured_tag_id' => null,
        'featured_collection' => '',
        'show_categories' => true,
        'show_quick_search' => true,
        'custom_css' => '',
        'sections_order' => ['welcome', 'stats', 'recent', 'featured', 'categories']
    ];

    $config = array_merge($defaults, $config);

    // Get categories and tags for dropdowns
    $db = getDB();

    $categories = [];
    $result = $db->query('SELECT id, name FROM categories ORDER BY name');
    while ($row = $result->fetch()) {
        $categories[] = $row;
    }

    $tags = [];
    $result = $db->query('SELECT id, name FROM tags ORDER BY name');
    while ($row = $result->fetch()) {
        $tags[] = $row;
    }

    $collections = [];
    $result = $db->query('SELECT DISTINCT collection FROM models WHERE collection IS NOT NULL AND collection != "" ORDER BY collection');
    while ($row = $result->fetch()) {
        $collections[] = $row['collection'];
    }

    echo json_encode([
        'success' => true,
        'config' => $config,
        'categories' => $categories,
        'tags' => $tags,
        'collections' => $collections
    ]);
}

function getHomepagePreview() {
    $json = getSetting('homepage_config', '');
    $config = $json ? json_decode($json, true) : [];

    $db = getDB();
    $data = [];

    // Stats
    if ($config['show_stats'] ?? true) {
        $stmt = $db->query('SELECT COUNT(*) FROM models WHERE parent_id IS NULL');
        $modelCount = (int)$stmt->fetchColumn();

        $stmt = $db->query('SELECT COUNT(*) FROM categories');
        $categoryCount = (int)$stmt->fetchColumn();

        $stmt = $db->query('SELECT SUM(download_count) FROM models');
        $downloadCount = (int)$stmt->fetchColumn();

        $data['stats'] = [
            'models' => $modelCount,
            'categories' => $categoryCount,
            'downloads' => $downloadCount
        ];
    }

    // Recent models
    if ($config['show_recent'] ?? true) {
        $limit = (int)($config['recent_count'] ?? 8);
        $stmt = $db->prepare('
            SELECT id, name, filename, file_type, thumbnail_path, created_at
            FROM models WHERE parent_id IS NULL
            ORDER BY created_at DESC LIMIT :limit
        ');
        $stmt->execute([':limit' => $limit]);

        $data['recent'] = [];
        while ($row = $stmt->fetch()) {
            $data['recent'][] = $row;
        }
    }

    // Featured models
    if ($config['show_featured'] ?? false) {
        $where = ['m.parent_id IS NULL'];
        $params = [];

        if (!empty($config['featured_category_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id AND mc.category_id = :cat_id)';
            $params[':cat_id'] = $config['featured_category_id'];
        }
        if (!empty($config['featured_tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM model_tags mt WHERE mt.model_id = m.id AND mt.tag_id = :tag_id)';
            $params[':tag_id'] = $config['featured_tag_id'];
        }
        if (!empty($config['featured_collection'])) {
            $where[] = 'm.collection = :collection';
            $params[':collection'] = $config['featured_collection'];
        }

        $whereClause = implode(' AND ', $where);
        $params[':limit'] = 8;

        $stmt = $db->prepare("
            SELECT m.id, m.name, m.filename, m.file_type, m.thumbnail_path
            FROM models m WHERE $whereClause
            ORDER BY m.download_count DESC, m.created_at DESC LIMIT :limit
        ");
        $stmt->execute($params);

        $data['featured'] = [];
        while ($row = $stmt->fetch()) {
            $data['featured'][] = $row;
        }
    }

    // Categories with counts
    if ($config['show_categories'] ?? true) {
        $result = $db->query('
            SELECT c.id, c.name, COUNT(mc.model_id) as model_count
            FROM categories c
            LEFT JOIN model_categories mc ON c.id = mc.category_id
            LEFT JOIN models m ON mc.model_id = m.id AND m.parent_id IS NULL
            GROUP BY c.id
            ORDER BY model_count DESC
            LIMIT 12
        ');

        $data['categories'] = [];
        while ($row = $result->fetch()) {
            $data['categories'][] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'config' => $config,
        'data' => $data
    ]);
}
