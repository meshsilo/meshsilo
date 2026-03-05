<?php
/**
 * Upload Approval Queue Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();

// Check if approval is required
$requireApproval = getSetting('require_approval', '0') === '1';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['approve', 'reject', 'bulk_approve']) && !Csrf::check()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

switch ($action) {
    case 'pending':
        listPendingApprovals();
        break;
    case 'approve':
        approveModel();
        break;
    case 'reject':
        rejectModel();
        break;
    case 'bulk_approve':
        bulkApprove();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function listPendingApprovals() {
    global $user;

    if (!$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.username as uploader_name
        FROM models m
        LEFT JOIN users u ON m.uploaded_by = u.id
        WHERE m.approval_status = 'pending' AND m.parent_id IS NULL
        ORDER BY m.created_at ASC
    ");
    $stmt->execute();

    $pending = [];
    while ($row = $stmt->fetch()) {
        $pending[] = $row;
    }

    echo json_encode(['success' => true, 'pending' => $pending, 'count' => count($pending)]);
}

function approveModel() {
    global $user;

    if (!$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        return;
    }

    $modelId = (int)($_POST['model_id'] ?? 0);
    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("
            UPDATE models SET approval_status = 'approved', approved_by = :admin_id, approved_at = NOW()
            WHERE id = :id
        ");
    } else {
        $stmt = $db->prepare("
            UPDATE models SET approval_status = 'approved', approved_by = :admin_id, approved_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
    }
    $stmt->execute([':admin_id' => $user['id'], ':id' => $modelId]);

    logActivity('approve', 'model', $modelId);

    // Trigger webhook
    triggerWebhook('model.approved', ['model_id' => $modelId, 'approved_by' => $user['id']]);

    echo json_encode(['success' => true]);
}

function rejectModel() {
    global $user;

    if (!$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        return;
    }

    $modelId = (int)($_POST['model_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("
            UPDATE models SET approval_status = 'rejected', approved_by = :admin_id, approved_at = NOW()
            WHERE id = :id
        ");
    } else {
        $stmt = $db->prepare("
            UPDATE models SET approval_status = 'rejected', approved_by = :admin_id, approved_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
    }
    $stmt->execute([':admin_id' => $user['id'], ':id' => $modelId]);

    logActivity('reject', 'model', $modelId, null, ['reason' => $reason]);

    echo json_encode(['success' => true]);
}

function bulkApprove() {
    global $user;

    if (!$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        return;
    }

    $modelIds = $_POST['model_ids'] ?? [];
    if (is_string($modelIds)) {
        $modelIds = json_decode($modelIds, true) ?: [];
    }

    if (empty($modelIds)) {
        echo json_encode(['success' => false, 'error' => 'No models specified']);
        return;
    }

    $db = getDB();
    $type = $db->getType();

    $approved = 0;
    foreach ($modelIds as $modelId) {
        $modelId = (int)$modelId;
        if ($modelId <= 0) continue;

        if ($type === 'mysql') {
            $stmt = $db->prepare("
                UPDATE models SET approval_status = 'approved', approved_by = :admin_id, approved_at = NOW()
                WHERE id = :id AND approval_status = 'pending'
            ");
        } else {
            $stmt = $db->prepare("
                UPDATE models SET approval_status = 'approved', approved_by = :admin_id, approved_at = CURRENT_TIMESTAMP
                WHERE id = :id AND approval_status = 'pending'
            ");
        }
        $stmt->execute([':admin_id' => $user['id'], ':id' => $modelId]);

        if ($db->changes() > 0) {
            $approved++;
        }
    }

    logActivity('bulk_approve', 'model', null, null, ['count' => $approved]);

    echo json_encode(['success' => true, 'approved_count' => $approved]);
}
