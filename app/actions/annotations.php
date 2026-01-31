<?php
/**
 * Model Annotations API
 *
 * CRUD operations for 3D model annotations
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

// Ensure annotations table exists
ensureAnnotationsTable();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$modelId = (int)($_POST['model_id'] ?? $_GET['model_id'] ?? 0);

if (!$modelId) {
    echo json_encode(['success' => false, 'error' => 'Model ID required']);
    exit;
}

$db = getDB();

switch ($action) {
    case 'list':
        listAnnotations($db, $modelId);
        break;

    case 'create':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }
        createAnnotation($db, $modelId);
        break;

    case 'update':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }
        updateAnnotation($db);
        break;

    case 'delete':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }
        deleteAnnotation($db);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function ensureAnnotationsTable() {
    $db = getDB();

    try {
        if ($db->getType() === 'mysql') {
            $db->exec('CREATE TABLE IF NOT EXISTS annotations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                user_id INT NOT NULL,
                position_x DOUBLE NOT NULL,
                position_y DOUBLE NOT NULL,
                position_z DOUBLE NOT NULL,
                normal_x DOUBLE DEFAULT 0,
                normal_y DOUBLE DEFAULT 0,
                normal_z DOUBLE DEFAULT 1,
                content TEXT NOT NULL,
                color VARCHAR(7) DEFAULT "#ff0000",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE IF NOT EXISTS annotations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                position_x REAL NOT NULL,
                position_y REAL NOT NULL,
                position_z REAL NOT NULL,
                normal_x REAL DEFAULT 0,
                normal_y REAL DEFAULT 0,
                normal_z REAL DEFAULT 1,
                content TEXT NOT NULL,
                color TEXT DEFAULT "#ff0000",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
    } catch (Exception $e) {
        // Table probably already exists
    }
}

function listAnnotations($db, $modelId) {
    $stmt = $db->prepare('
        SELECT a.*, u.username
        FROM annotations a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.model_id = :model_id
        ORDER BY a.created_at DESC
    ');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();

    $annotations = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $annotations[] = [
            'id' => (int)$row['id'],
            'model_id' => (int)$row['model_id'],
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'] ?? 'Unknown',
            'position' => [
                'x' => (float)$row['position_x'],
                'y' => (float)$row['position_y'],
                'z' => (float)$row['position_z']
            ],
            'normal' => [
                'x' => (float)$row['normal_x'],
                'y' => (float)$row['normal_y'],
                'z' => (float)$row['normal_z']
            ],
            'content' => $row['content'],
            'color' => $row['color'],
            'created_at' => $row['created_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'annotations' => $annotations
    ]);
}

function createAnnotation($db, $modelId) {
    $user = getCurrentUser();

    $positionX = (float)($_POST['position_x'] ?? 0);
    $positionY = (float)($_POST['position_y'] ?? 0);
    $positionZ = (float)($_POST['position_z'] ?? 0);
    $normalX = (float)($_POST['normal_x'] ?? 0);
    $normalY = (float)($_POST['normal_y'] ?? 0);
    $normalZ = (float)($_POST['normal_z'] ?? 1);
    $content = trim($_POST['content'] ?? '');
    $color = $_POST['color'] ?? '#ff0000';

    // Validate color format
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#ff0000';
    }

    if (empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Annotation content required']);
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO annotations (model_id, user_id, position_x, position_y, position_z, normal_x, normal_y, normal_z, content, color)
        VALUES (:model_id, :user_id, :pos_x, :pos_y, :pos_z, :norm_x, :norm_y, :norm_z, :content, :color)
    ');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':pos_x', $positionX, PDO::PARAM_STR);
    $stmt->bindValue(':pos_y', $positionY, PDO::PARAM_STR);
    $stmt->bindValue(':pos_z', $positionZ, PDO::PARAM_STR);
    $stmt->bindValue(':norm_x', $normalX, PDO::PARAM_STR);
    $stmt->bindValue(':norm_y', $normalY, PDO::PARAM_STR);
    $stmt->bindValue(':norm_z', $normalZ, PDO::PARAM_STR);
    $stmt->bindValue(':content', $content, PDO::PARAM_STR);
    $stmt->bindValue(':color', $color, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $annotationId = $db->lastInsertRowID();

        echo json_encode([
            'success' => true,
            'annotation' => [
                'id' => $annotationId,
                'model_id' => $modelId,
                'user_id' => $user['id'],
                'username' => $user['username'],
                'position' => ['x' => $positionX, 'y' => $positionY, 'z' => $positionZ],
                'normal' => ['x' => $normalX, 'y' => $normalY, 'z' => $normalZ],
                'content' => $content,
                'color' => $color
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create annotation']);
    }
}

function updateAnnotation($db) {
    $user = getCurrentUser();
    $annotationId = (int)($_POST['id'] ?? 0);

    if (!$annotationId) {
        echo json_encode(['success' => false, 'error' => 'Annotation ID required']);
        return;
    }

    // Check ownership
    $stmt = $db->prepare('SELECT user_id FROM annotations WHERE id = :id');
    $stmt->bindValue(':id', $annotationId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $annotation = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$annotation) {
        echo json_encode(['success' => false, 'error' => 'Annotation not found']);
        return;
    }

    if ($annotation['user_id'] != $user['id'] && !$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $content = trim($_POST['content'] ?? '');
    $color = $_POST['color'] ?? '#ff0000';

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#ff0000';
    }

    if (empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Annotation content required']);
        return;
    }

    $stmt = $db->prepare('UPDATE annotations SET content = :content, color = :color WHERE id = :id');
    $stmt->bindValue(':content', $content, PDO::PARAM_STR);
    $stmt->bindValue(':color', $color, PDO::PARAM_STR);
    $stmt->bindValue(':id', $annotationId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update annotation']);
    }
}

function deleteAnnotation($db) {
    $user = getCurrentUser();
    $annotationId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

    if (!$annotationId) {
        echo json_encode(['success' => false, 'error' => 'Annotation ID required']);
        return;
    }

    // Check ownership
    $stmt = $db->prepare('SELECT user_id FROM annotations WHERE id = :id');
    $stmt->bindValue(':id', $annotationId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $annotation = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$annotation) {
        echo json_encode(['success' => false, 'error' => 'Annotation not found']);
        return;
    }

    if ($annotation['user_id'] != $user['id'] && !$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM annotations WHERE id = :id');
    $stmt->bindValue(':id', $annotationId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete annotation']);
    }
}
