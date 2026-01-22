<?php
/**
 * Model Rating Actions
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'rate':
        rateModel();
        break;
    case 'get':
        getRating();
        break;
    case 'delete':
        deleteRating();
        break;
    case 'model_ratings':
        getModelRatings();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function rateModel() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);
    $printability = (int)($_POST['printability'] ?? 0);
    $quality = (int)($_POST['quality'] ?? 0);
    $difficulty = (int)($_POST['difficulty'] ?? 0);
    $review = trim($_POST['review'] ?? '');

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    // Validate ratings (1-5 or 0 for not rated)
    foreach (['printability' => $printability, 'quality' => $quality, 'difficulty' => $difficulty] as $name => $value) {
        if ($value < 0 || $value > 5) {
            echo json_encode(['success' => false, 'error' => "Invalid $name rating"]);
            return;
        }
    }

    $db = getDB();
    $type = $db->getType();

    // Upsert rating
    if ($type === 'mysql') {
        $stmt = $db->prepare('
            INSERT INTO model_ratings (model_id, user_id, printability, quality, difficulty, review)
            VALUES (:model_id, :user_id, :printability, :quality, :difficulty, :review)
            ON DUPLICATE KEY UPDATE
                printability = :printability2, quality = :quality2, difficulty = :difficulty2,
                review = :review2, updated_at = NOW()
        ');
        $stmt->execute([
            ':model_id' => $modelId,
            ':user_id' => $user['id'],
            ':printability' => $printability ?: null,
            ':quality' => $quality ?: null,
            ':difficulty' => $difficulty ?: null,
            ':review' => $review ?: null,
            ':printability2' => $printability ?: null,
            ':quality2' => $quality ?: null,
            ':difficulty2' => $difficulty ?: null,
            ':review2' => $review ?: null
        ]);
    } else {
        $stmt = $db->prepare('
            INSERT OR REPLACE INTO model_ratings (model_id, user_id, printability, quality, difficulty, review, updated_at)
            VALUES (:model_id, :user_id, :printability, :quality, :difficulty, :review, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            ':model_id' => $modelId,
            ':user_id' => $user['id'],
            ':printability' => $printability ?: null,
            ':quality' => $quality ?: null,
            ':difficulty' => $difficulty ?: null,
            ':review' => $review ?: null
        ]);
    }

    logActivity('rate', 'model', $modelId);

    // Get updated averages
    $averages = getModelAverageRatings($modelId);

    echo json_encode([
        'success' => true,
        'averages' => $averages
    ]);
}

function getRating() {
    global $user;

    $modelId = (int)($_GET['model_id'] ?? 0);
    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM model_ratings WHERE model_id = :model_id AND user_id = :user_id');
    $stmt->execute([':model_id' => $modelId, ':user_id' => $user['id']]);
    $rating = $stmt->fetch();

    $averages = getModelAverageRatings($modelId);

    echo json_encode([
        'success' => true,
        'rating' => $rating ?: null,
        'averages' => $averages
    ]);
}

function deleteRating() {
    global $user;

    $modelId = (int)($_POST['model_id'] ?? 0);
    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM model_ratings WHERE model_id = :model_id AND user_id = :user_id');
    $stmt->execute([':model_id' => $modelId, ':user_id' => $user['id']]);

    echo json_encode(['success' => true]);
}

function getModelRatings() {
    $modelId = (int)($_GET['model_id'] ?? 0);
    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT mr.*, u.username
        FROM model_ratings mr
        JOIN users u ON mr.user_id = u.id
        WHERE mr.model_id = :model_id
        ORDER BY mr.created_at DESC
    ');
    $stmt->execute([':model_id' => $modelId]);

    $ratings = [];
    while ($row = $stmt->fetch()) {
        $ratings[] = $row;
    }

    $averages = getModelAverageRatings($modelId);

    echo json_encode([
        'success' => true,
        'ratings' => $ratings,
        'averages' => $averages
    ]);
}

function getModelAverageRatings($modelId) {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT
            AVG(printability) as avg_printability,
            AVG(quality) as avg_quality,
            AVG(difficulty) as avg_difficulty,
            COUNT(*) as total_ratings
        FROM model_ratings
        WHERE model_id = :model_id
    ');
    $stmt->execute([':model_id' => $modelId]);
    $row = $stmt->fetch();

    return [
        'printability' => $row['avg_printability'] ? round((float)$row['avg_printability'], 1) : null,
        'quality' => $row['avg_quality'] ? round((float)$row['avg_quality'], 1) : null,
        'difficulty' => $row['avg_difficulty'] ? round((float)$row['avg_difficulty'], 1) : null,
        'total_ratings' => (int)$row['total_ratings']
    ];
}
