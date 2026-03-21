<?php
/**
 * Check for duplicate files before upload
 * Accepts a file via POST and returns any matching existing models
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not authenticated', 401);
}

if (!isFeatureEnabled('duplicate_detection')) {
    jsonSuccess(['duplicates' => []]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Check for file upload
if (empty($_FILES['file'])) {
    jsonError('No file provided');
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonError('Upload error');
}

$tempPath = $file['tmp_name'];
$originalName = $file['name'];

// Check for duplicates by hash
$duplicateCheck = checkUploadForDuplicates($tempPath);

// Also check for similar names
$similarModels = findSimilarByName(pathinfo($originalName, PATHINFO_FILENAME));

// Remove any from similarModels that are already in duplicates
if ($duplicateCheck['is_duplicate']) {
    $duplicateIds = array_column($duplicateCheck['existing'], 'id');
    $similarModels = array_filter($similarModels, function($m) use ($duplicateIds) {
        return !in_array($m['id'], $duplicateIds);
    });
    $similarModels = array_values($similarModels);
}

$result = [
    'success' => true,
    'is_duplicate' => $duplicateCheck['is_duplicate'],
    'hash' => $duplicateCheck['hash'],
    'exact_matches' => array_map(function($m) {
        return [
            'id' => $m['parent_model_id'] ?? $m['id'],
            'name' => $m['parent_name'] ?? $m['name'],
            'creator' => $m['creator'] ?? '',
            'created_at' => $m['created_at']
        ];
    }, $duplicateCheck['existing']),
    'similar_names' => array_map(function($m) {
        return [
            'id' => $m['id'],
            'name' => $m['name'],
            'creator' => $m['creator'] ?? '',
            'created_at' => $m['created_at']
        ];
    }, $similarModels)
];

// Clean up temp file
@unlink($tempPath);

echo json_encode($result);
