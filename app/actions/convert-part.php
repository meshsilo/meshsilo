<?php
/**
 * AJAX endpoint for converting STL parts to 3MF
 * Conversions are queued as background jobs and processed by the queue worker.
 */

// Suppress HTML error output for JSON endpoint
ini_set('display_errors', '0');
ini_set('html_errors', '0');

ob_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/converter.php';

header('Content-Type: application/json');

function isPartAlreadyQueued(int $partId): bool
{
    $db = getDB();
    // Match exact payload to avoid partial ID matches (e.g. 12 matching 123)
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt FROM jobs
        WHERE job_class = 'ConvertStlTo3mf'
        AND status IN ('pending', 'processing')
        AND payload = :payload
    ");
    $stmt->bindValue(':payload', json_encode(['model_id' => $partId]), PDO::PARAM_STR);
    $result = $stmt->execute();
    $row = $result->fetchArray(PDO::FETCH_ASSOC);
    return ($row['cnt'] ?? 0) > 0;
}

// Require edit permission
if (!canEdit()) {
    ob_end_clean();
    jsonError('Permission denied', 403);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if (in_array($action, ['convert', 'batch'])) {
    if (!Csrf::check()) {
        ob_end_clean();
        jsonError('Invalid request token', 403);
    }
}

if ($action === 'estimate') {
    $partId = (int)($_POST['part_id'] ?? $_GET['part_id'] ?? 0);
    if (!$partId) {
        ob_end_clean();
        jsonError('Part ID required', 400);
    }

    $result = estimatePartConversion($partId);
    $strayOutput = ob_get_clean();
    if ($result) {
        echo json_encode([
            'success' => true,
            'original_size' => $result['original_size'],
            'estimated_size' => $result['estimated_size'],
            'estimated_savings' => $result['estimated_savings'],
            'estimated_savings_percent' => $result['estimated_savings_percent'],
            'worth_converting' => $result['worth_converting'],
            'vertices' => $result['vertices'],
            'triangles' => $result['triangles']
        ]);
    } else {
        if ($strayOutput) {
            logWarning('Conversion estimate produced unexpected output', ['output' => substr($strayOutput, 0, 500), 'part_id' => $partId]);
        }
        jsonError('Cannot estimate conversion for this file');
    }
} elseif ($action === 'convert') {
    // Queue a single conversion job
    $partId = (int)($_POST['part_id'] ?? 0);
    if (!$partId) {
        ob_end_clean();
        jsonError('Part ID required', 400);
    }

    ob_end_clean();

    // Verify it's an STL file before queuing
    $db = getDB();
    $stmt = $db->prepare('SELECT file_type FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $part = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$part || $part['file_type'] !== 'stl') {
        jsonError('Only STL files can be converted');
    }

    // Check for duplicate — skip if already queued
    if (isPartAlreadyQueued($partId)) {
        jsonSuccess(['queued' => 0, 'message' => 'Already queued']);
    }

    Queue::push('ConvertStlTo3mf', ['model_id' => $partId]);
    jsonSuccess(['queued' => 1]);

} elseif ($action === 'batch') {
    // Queue multiple conversion jobs
    $partIds = $_POST['part_ids'] ?? [];
    if (empty($partIds) || !is_array($partIds)) {
        ob_end_clean();
        jsonError('No part IDs provided', 400);
    }

    ob_end_clean();
    $db = getDB();
    $queued = 0;

    foreach ($partIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;

        // Verify it's an STL part before queuing
        $stmt = $db->prepare('SELECT file_type FROM models WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $result = $stmt->execute();
        $part = $result->fetchArray(PDO::FETCH_ASSOC);

        if ($part && $part['file_type'] === 'stl' && !isPartAlreadyQueued($id)) {
            Queue::push('ConvertStlTo3mf', ['model_id' => $id]);
            $queued++;
        }
    }

    jsonSuccess(['queued' => $queued]);

} elseif ($action === 'conversion-progress') {
    // Return conversion job progress
    ob_end_clean();
    $db = getDB();

    try {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as remaining
            FROM jobs
            WHERE job_class = 'ConvertStlTo3mf'
        ");
        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);

        echo json_encode([
            'total' => (int)($row['total'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'remaining' => (int)($row['remaining'] ?? 0),
        ]);
    } catch (Exception $e) {
        echo json_encode(['total' => 0, 'completed' => 0, 'failed' => 0, 'remaining' => 0]);
    }
} else {
    ob_end_clean();
    http_response_code(400);
    jsonError('Invalid action. Use "estimate", "convert", "batch", or "conversion-progress"');
}
