<?php
/**
 * Queue Status Action
 * Returns current queue status as JSON for the header queue indicator.
 */

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $activeJobs = Queue::active(20);
    $activeCount = count($activeJobs);

    $jobs = [];
    foreach ($activeJobs as $job) {
        // Make job class names human-readable
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

    echo json_encode([
        'active' => $activeCount,
        'jobs' => $jobs,
    ]);
} catch (Exception $e) {
    echo json_encode(['active' => 0, 'jobs' => []]);
}
