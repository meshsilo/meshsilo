<?php
/**
 * Queue Status Action
 * Returns current queue status as JSON for the header queue indicator.
 * Includes conversion progress and model/part IDs being converted.
 */

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDB();
    $activeJobs = Queue::active(20);
    $activeCount = count($activeJobs);

    $jobs = [];
    foreach ($activeJobs as $job) {
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $job['job_class']);

        $jobs[] = [
            'id' => (int)$job['id'],
            'name' => $name,
            'status' => $job['status'],
            'queue' => $job['queue'],
            'attempts' => (int)$job['attempts'],
            'created_at' => $job['created_at'],
        ];
    }

    // Conversion progress and IDs
    $conversions = null;
    $convertingPartIds = [];
    $convertingModelIds = [];

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

        $total = (int)($row['total'] ?? 0);
        $remaining = (int)($row['remaining'] ?? 0);
        $completed = (int)($row['completed'] ?? 0);

        if ($total > 0) {
            $conversions = [
                'total' => $total,
                'completed' => $completed,
                'failed' => (int)($row['failed'] ?? 0),
                'remaining' => $remaining,
            ];

            // Get part IDs being converted (pending/processing only)
            if ($remaining > 0) {
                $stmt = $db->prepare("
                    SELECT payload FROM jobs
                    WHERE job_class = 'ConvertStlTo3mf'
                    AND status IN ('pending', 'processing')
                ");
                $result = $stmt->execute();
                while ($jobRow = $result->fetchArray(PDO::FETCH_ASSOC)) {
                    $payload = json_decode($jobRow['payload'], true);
                    if (!empty($payload['model_id'])) {
                        $convertingPartIds[] = (int)$payload['model_id'];
                    }
                }

                // Find parent model IDs for these parts
                if (!empty($convertingPartIds)) {
                    $placeholders = implode(',', array_fill(0, count($convertingPartIds), '?'));
                    $stmt = $db->prepare("
                        SELECT DISTINCT parent_id FROM models
                        WHERE id IN ($placeholders) AND parent_id IS NOT NULL AND parent_id > 0
                    ");
                    foreach ($convertingPartIds as $i => $pid) {
                        $stmt->bindValue($i + 1, $pid, PDO::PARAM_INT);
                    }
                    $result = $stmt->execute();
                    while ($parentRow = $result->fetchArray(PDO::FETCH_ASSOC)) {
                        $convertingModelIds[] = (int)$parentRow['parent_id'];
                    }
                }
            }

            // Auto-cleanup: if no remaining conversions, delete all completed conversion jobs
            if ($remaining === 0 && $completed > 0) {
                $db->exec("DELETE FROM jobs WHERE job_class = 'ConvertStlTo3mf' AND status IN ('completed', 'failed')");
                $conversions = null;
            }
        }
    } catch (Exception $e) {
        // Ignore — conversions field will be null
    }

    // Optional: model-specific upload status
    $uploadStatus = null;
    $checkModelId = isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0;
    if ($checkModelId > 0) {
        try {
            $stmt = $db->prepare('SELECT upload_status FROM models WHERE id = :id');
            $stmt->bindValue(':id', $checkModelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            $row = $result->fetchArray(PDO::FETCH_ASSOC);
            $uploadStatus = $row['upload_status'] ?? null;
        } catch (Exception $e) {
            // Column may not exist on old installs
        }
    }

    echo json_encode([
        'active' => $activeCount,
        'jobs' => $jobs,
        'conversions' => $conversions,
        'converting_part_ids' => $convertingPartIds,
        'converting_model_ids' => $convertingModelIds,
        'upload_status' => $uploadStatus,
    ]);
} catch (Exception $e) {
    echo json_encode(['active' => 0, 'jobs' => [], 'conversions' => null, 'converting_part_ids' => [], 'converting_model_ids' => [], 'upload_status' => null]);
}
