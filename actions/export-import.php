<?php
/**
 * Export/Import Library Actions
 * Full backup/restore with metadata
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
    case 'export':
        exportLibrary();
        break;
    case 'export_selective':
        exportSelective();
        break;
    case 'import':
        importLibrary();
        break;
    case 'preview_import':
        previewImport();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Export entire library as ZIP
 */
function exportLibrary() {
    global $user;

    if (!$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Admin access required for full export']);
        return;
    }

    $exportDir = sys_get_temp_dir() . '/silo_export_' . uniqid();
    mkdir($exportDir, 0755, true);

    $db = getDB();

    // Export metadata
    $metadata = [
        'version' => '1.0',
        'exported_at' => date('c'),
        'exported_by' => $user['username'],
        'site_name' => getSetting('site_name', 'Silo')
    ];

    // Export models
    $models = [];
    $stmt = $db->query('SELECT * FROM models ORDER BY id');
    while ($row = $stmt->fetch()) {
        $models[] = $row;
    }
    $metadata['models'] = $models;

    // Export categories
    $categories = [];
    $stmt = $db->query('SELECT * FROM categories ORDER BY id');
    while ($row = $stmt->fetch()) {
        $categories[] = $row;
    }
    $metadata['categories'] = $categories;

    // Export model-category relationships
    $modelCategories = [];
    $stmt = $db->query('SELECT * FROM model_categories');
    while ($row = $stmt->fetch()) {
        $modelCategories[] = $row;
    }
    $metadata['model_categories'] = $modelCategories;

    // Export tags
    $tags = [];
    $stmt = $db->query('SELECT * FROM tags ORDER BY id');
    while ($row = $stmt->fetch()) {
        $tags[] = $row;
    }
    $metadata['tags'] = $tags;

    // Export model-tag relationships
    $modelTags = [];
    $stmt = $db->query('SELECT * FROM model_tags');
    while ($row = $stmt->fetch()) {
        $modelTags[] = $row;
    }
    $metadata['model_tags'] = $modelTags;

    // Export collections
    $collections = [];
    $stmt = $db->query('SELECT * FROM collections ORDER BY id');
    while ($row = $stmt->fetch()) {
        $collections[] = $row;
    }
    $metadata['collections'] = $collections;

    // Write metadata JSON
    file_put_contents($exportDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));

    // Create ZIP
    $zipPath = sys_get_temp_dir() . '/silo_export_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        echo json_encode(['success' => false, 'error' => 'Could not create ZIP file']);
        return;
    }

    $zip->addFile($exportDir . '/metadata.json', 'metadata.json');

    // Add model files
    foreach ($models as $model) {
        $filePath = UPLOAD_PATH . $model['file_path'];
        if (file_exists($filePath)) {
            $zip->addFile($filePath, 'files/' . $model['file_path']);
        }
    }

    $zip->close();

    // Clean up temp directory
    unlink($exportDir . '/metadata.json');
    rmdir($exportDir);

    // Return download info
    $downloadToken = bin2hex(random_bytes(16));
    $_SESSION['export_download_' . $downloadToken] = $zipPath;

    echo json_encode([
        'success' => true,
        'download_token' => $downloadToken,
        'filename' => basename($zipPath),
        'size' => filesize($zipPath)
    ]);
}

/**
 * Export selected models
 */
function exportSelective() {
    global $user;

    $modelIds = $_POST['model_ids'] ?? [];
    if (is_string($modelIds)) {
        $modelIds = json_decode($modelIds, true) ?: [];
    }

    if (empty($modelIds)) {
        echo json_encode(['success' => false, 'error' => 'No models selected']);
        return;
    }

    $exportDir = sys_get_temp_dir() . '/silo_export_' . uniqid();
    mkdir($exportDir, 0755, true);

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($modelIds), '?'));

    // Export selected models
    $models = [];
    $stmt = $db->prepare("SELECT * FROM models WHERE id IN ($placeholders) OR parent_id IN ($placeholders)");
    $params = array_merge($modelIds, $modelIds);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $models[] = $row;
    }

    // Get related categories
    $stmt = $db->prepare("
        SELECT DISTINCT c.* FROM categories c
        JOIN model_categories mc ON c.id = mc.category_id
        WHERE mc.model_id IN ($placeholders)
    ");
    $stmt->execute($modelIds);
    $categories = [];
    while ($row = $stmt->fetch()) {
        $categories[] = $row;
    }

    // Get related tags
    $stmt = $db->prepare("
        SELECT DISTINCT t.* FROM tags t
        JOIN model_tags mt ON t.id = mt.tag_id
        WHERE mt.model_id IN ($placeholders)
    ");
    $stmt->execute($modelIds);
    $tags = [];
    while ($row = $stmt->fetch()) {
        $tags[] = $row;
    }

    // Get relationships
    $stmt = $db->prepare("SELECT * FROM model_categories WHERE model_id IN ($placeholders)");
    $stmt->execute($modelIds);
    $modelCategories = [];
    while ($row = $stmt->fetch()) {
        $modelCategories[] = $row;
    }

    $stmt = $db->prepare("SELECT * FROM model_tags WHERE model_id IN ($placeholders)");
    $stmt->execute($modelIds);
    $modelTags = [];
    while ($row = $stmt->fetch()) {
        $modelTags[] = $row;
    }

    $metadata = [
        'version' => '1.0',
        'exported_at' => date('c'),
        'exported_by' => $user['username'],
        'selective' => true,
        'models' => $models,
        'categories' => $categories,
        'tags' => $tags,
        'model_categories' => $modelCategories,
        'model_tags' => $modelTags
    ];

    file_put_contents($exportDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));

    // Create ZIP
    $zipPath = sys_get_temp_dir() . '/silo_selective_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        echo json_encode(['success' => false, 'error' => 'Could not create ZIP file']);
        return;
    }

    $zip->addFile($exportDir . '/metadata.json', 'metadata.json');

    foreach ($models as $model) {
        $filePath = UPLOAD_PATH . $model['file_path'];
        if (file_exists($filePath)) {
            $zip->addFile($filePath, 'files/' . $model['file_path']);
        }
    }

    $zip->close();
    unlink($exportDir . '/metadata.json');
    rmdir($exportDir);

    $downloadToken = bin2hex(random_bytes(16));
    $_SESSION['export_download_' . $downloadToken] = $zipPath;

    echo json_encode([
        'success' => true,
        'download_token' => $downloadToken,
        'filename' => basename($zipPath),
        'size' => filesize($zipPath),
        'model_count' => count($models)
    ]);
}

/**
 * Import library from ZIP
 */
function importLibrary() {
    global $user;

    if (!$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'Admin access required for import']);
        return;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $skipExisting = isset($_POST['skip_existing']);
    $importCategories = isset($_POST['import_categories']);
    $importTags = isset($_POST['import_tags']);

    $zipPath = $_FILES['file']['tmp_name'];
    $extractDir = sys_get_temp_dir() . '/silo_import_' . uniqid();

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        echo json_encode(['success' => false, 'error' => 'Invalid ZIP file']);
        return;
    }

    // Safe extraction with path traversal protection (ZIP Slip prevention)
    mkdir($extractDir, 0755, true);
    $realExtractDir = realpath($extractDir);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if (substr($filename, -1) === '/') continue;

        $targetPath = $extractDir . '/' . $filename;
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

        $realTargetPath = realpath($targetDir) . '/' . basename($filename);
        if (strpos($realTargetPath, $realExtractDir) !== 0) {
            logWarning('ZIP path traversal blocked in import', ['filename' => $filename]);
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content !== false) file_put_contents($realTargetPath, $content);
    }
    $zip->close();

    // Read metadata
    $metadataPath = $extractDir . '/metadata.json';
    if (!file_exists($metadataPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid export file: missing metadata']);
        cleanupDir($extractDir);
        return;
    }

    $metadata = json_decode(file_get_contents($metadataPath), true);
    if (!$metadata) {
        echo json_encode(['success' => false, 'error' => 'Invalid metadata JSON']);
        cleanupDir($extractDir);
        return;
    }

    $db = getDB();
    $imported = 0;
    $skipped = 0;
    $errors = [];

    // Map old IDs to new IDs
    $categoryMap = [];
    $tagMap = [];
    $modelMap = [];

    // Import categories
    if ($importCategories && !empty($metadata['categories'])) {
        foreach ($metadata['categories'] as $cat) {
            $stmt = $db->prepare('SELECT id FROM categories WHERE name = :name');
            $stmt->execute([':name' => $cat['name']]);
            $existing = $stmt->fetch();

            if ($existing) {
                $categoryMap[$cat['id']] = $existing['id'];
            } else {
                $stmt = $db->prepare('INSERT INTO categories (name) VALUES (:name)');
                $stmt->execute([':name' => $cat['name']]);
                $categoryMap[$cat['id']] = $db->lastInsertId();
            }
        }
    }

    // Import tags
    if ($importTags && !empty($metadata['tags'])) {
        foreach ($metadata['tags'] as $tag) {
            $stmt = $db->prepare('SELECT id FROM tags WHERE name = :name');
            $stmt->execute([':name' => $tag['name']]);
            $existing = $stmt->fetch();

            if ($existing) {
                $tagMap[$tag['id']] = $existing['id'];
            } else {
                $stmt = $db->prepare('INSERT INTO tags (name, color) VALUES (:name, :color)');
                $stmt->execute([':name' => $tag['name'], ':color' => $tag['color'] ?? '#6366f1']);
                $tagMap[$tag['id']] = $db->lastInsertId();
            }
        }
    }

    // Import models (parent models first)
    $parentModels = array_filter($metadata['models'], fn($m) => empty($m['parent_id']));
    $childModels = array_filter($metadata['models'], fn($m) => !empty($m['parent_id']));

    foreach ($parentModels as $model) {
        $result = importModel($model, $extractDir, $skipExisting, $user['id']);
        if ($result['success']) {
            $modelMap[$model['id']] = $result['model_id'];
            $imported++;
        } elseif ($result['skipped']) {
            $skipped++;
        } else {
            $errors[] = $result['error'];
        }
    }

    // Import child models
    foreach ($childModels as $model) {
        if (isset($modelMap[$model['parent_id']])) {
            $model['parent_id'] = $modelMap[$model['parent_id']];
            $result = importModel($model, $extractDir, $skipExisting, $user['id']);
            if ($result['success']) {
                $modelMap[$model['id']] = $result['model_id'];
                $imported++;
            } elseif ($result['skipped']) {
                $skipped++;
            } else {
                $errors[] = $result['error'];
            }
        }
    }

    // Import relationships
    if ($importCategories && !empty($metadata['model_categories'])) {
        foreach ($metadata['model_categories'] as $mc) {
            if (isset($modelMap[$mc['model_id']]) && isset($categoryMap[$mc['category_id']])) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                $stmt->execute([
                    ':model_id' => $modelMap[$mc['model_id']],
                    ':category_id' => $categoryMap[$mc['category_id']]
                ]);
            }
        }
    }

    if ($importTags && !empty($metadata['model_tags'])) {
        foreach ($metadata['model_tags'] as $mt) {
            if (isset($modelMap[$mt['model_id']]) && isset($tagMap[$mt['tag_id']])) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO model_tags (model_id, tag_id) VALUES (:model_id, :tag_id)');
                $stmt->execute([
                    ':model_id' => $modelMap[$mt['model_id']],
                    ':tag_id' => $tagMap[$mt['tag_id']]
                ]);
            }
        }
    }

    cleanupDir($extractDir);

    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors
    ]);
}

function importModel($model, $extractDir, $skipExisting, $userId) {
    $db = getDB();

    // Check if exists by hash
    if ($skipExisting && !empty($model['file_hash'])) {
        $stmt = $db->prepare('SELECT id FROM models WHERE file_hash = :hash');
        $stmt->execute([':hash' => $model['file_hash']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'skipped' => true];
        }
    }

    // Check if file exists in export
    $sourcePath = $extractDir . '/files/' . $model['file_path'];
    if (!file_exists($sourcePath)) {
        return ['success' => false, 'skipped' => false, 'error' => "File not found: {$model['filename']}"];
    }

    // Create destination folder
    $folderId = uniqid();
    $destDir = UPLOAD_PATH . $folderId;
    if (!mkdir($destDir, 0755, true)) {
        return ['success' => false, 'skipped' => false, 'error' => "Could not create directory for {$model['filename']}"];
    }

    // Copy file
    $destPath = $destDir . '/' . $model['filename'];
    if (!copy($sourcePath, $destPath)) {
        rmdir($destDir);
        return ['success' => false, 'skipped' => false, 'error' => "Could not copy {$model['filename']}"];
    }

    // Insert into database
    $stmt = $db->prepare('
        INSERT INTO models (name, filename, file_path, file_size, file_type, description,
                           creator, collection, source_url, license, print_type, file_hash,
                           original_size, parent_id, uploaded_by, created_at, updated_at)
        VALUES (:name, :filename, :file_path, :file_size, :file_type, :description,
                :creator, :collection, :source_url, :license, :print_type, :file_hash,
                :original_size, :parent_id, :uploaded_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        ':name' => $model['name'],
        ':filename' => $model['filename'],
        ':file_path' => $folderId . '/' . $model['filename'],
        ':file_size' => $model['file_size'],
        ':file_type' => $model['file_type'],
        ':description' => $model['description'] ?? '',
        ':creator' => $model['creator'] ?? '',
        ':collection' => $model['collection'] ?? null,
        ':source_url' => $model['source_url'] ?? '',
        ':license' => $model['license'] ?? '',
        ':print_type' => $model['print_type'] ?? null,
        ':file_hash' => $model['file_hash'] ?? hash_file('sha256', $destPath),
        ':original_size' => $model['original_size'] ?? $model['file_size'],
        ':parent_id' => $model['parent_id'] ?? null,
        ':uploaded_by' => $userId
    ]);

    return ['success' => true, 'model_id' => $db->lastInsertId()];
}

function previewImport() {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $zipPath = $_FILES['file']['tmp_name'];
    $extractDir = sys_get_temp_dir() . '/silo_preview_' . uniqid();

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        echo json_encode(['success' => false, 'error' => 'Invalid ZIP file']);
        return;
    }

    // Safe extraction of metadata only (ZIP Slip prevention)
    mkdir($extractDir, 0755, true);
    $realExtractDir = realpath($extractDir);
    $metadataContent = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        // Only extract metadata.json, and validate the path
        if (basename($filename) === 'metadata.json' && $filename === 'metadata.json') {
            $metadataContent = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();

    if ($metadataContent === null) {
        echo json_encode(['success' => false, 'error' => 'Invalid export file']);
        cleanupDir($extractDir);
        return;
    }

    $metadataPath = $extractDir . '/metadata.json';
    file_put_contents($metadataPath, $metadataContent);
    if (!file_exists($metadataPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid export file']);
        cleanupDir($extractDir);
        return;
    }

    $metadata = json_decode(file_get_contents($metadataPath), true);
    cleanupDir($extractDir);

    echo json_encode([
        'success' => true,
        'preview' => [
            'version' => $metadata['version'] ?? 'unknown',
            'exported_at' => $metadata['exported_at'] ?? 'unknown',
            'exported_by' => $metadata['exported_by'] ?? 'unknown',
            'model_count' => count($metadata['models'] ?? []),
            'category_count' => count($metadata['categories'] ?? []),
            'tag_count' => count($metadata['tags'] ?? [])
        ]
    ]);
}

function cleanupDir($dir) {
    if (!is_dir($dir)) return;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($dir);
}
