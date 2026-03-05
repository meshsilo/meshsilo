<?php
/**
 * Import from External Sources (Thingiverse, Printables)
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if (!hasPermission(PERM_UPLOAD)) {
    echo json_encode(['success' => false, 'error' => 'Upload permission required']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for all import actions
if (!Csrf::check()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

switch ($action) {
    case 'fetch_thingiverse':
        fetchThingiverse();
        break;
    case 'fetch_printables':
        fetchPrintables();
        break;
    case 'import':
        importModel();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Fetch model info from Thingiverse
 */
function fetchThingiverse() {
    $url = $_POST['url'] ?? '';

    // Extract thing ID from URL
    if (preg_match('/thingiverse\.com\/thing:(\d+)/', $url, $matches)) {
        $thingId = $matches[1];
    } elseif (is_numeric($url)) {
        $thingId = $url;
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid Thingiverse URL or ID']);
        return;
    }

    // Fetch from Thingiverse API
    $apiUrl = "https://api.thingiverse.com/things/$thingId";

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Silo/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Could not fetch from Thingiverse. API may require authentication.']);
        return;
    }

    $data = json_decode($response, true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid response from Thingiverse']);
        return;
    }

    // Get files list
    $filesUrl = "https://api.thingiverse.com/things/$thingId/files";
    $ch = curl_init($filesUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Silo/1.0'
        ]
    ]);
    $filesResponse = curl_exec($ch);
    curl_close($ch);

    $files = json_decode($filesResponse, true) ?: [];

    echo json_encode([
        'success' => true,
        'source' => 'thingiverse',
        'model' => [
            'id' => $thingId,
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'creator' => $data['creator']['name'] ?? '',
            'license' => $data['license'] ?? '',
            'url' => $data['public_url'] ?? $url,
            'thumbnail' => $data['thumbnail'] ?? null,
            'files' => array_map(function($f) {
                return [
                    'id' => $f['id'],
                    'name' => $f['name'],
                    'size' => $f['size'],
                    'download_url' => $f['download_url'] ?? $f['public_url']
                ];
            }, $files)
        ]
    ]);
}

/**
 * Fetch model info from Printables
 */
function fetchPrintables() {
    $url = $_POST['url'] ?? '';

    // Extract model ID from URL
    if (preg_match('/printables\.com\/model\/(\d+)/', $url, $matches)) {
        $modelId = $matches[1];
    } elseif (is_numeric($url)) {
        $modelId = $url;
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid Printables URL or ID']);
        return;
    }

    // Printables uses GraphQL API
    $graphqlUrl = 'https://www.printables.com/graphql/';
    $query = <<<GRAPHQL
    query PrintProfile(\$id: ID!) {
        print(id: \$id) {
            id
            name
            description
            datePublished
            user {
                publicUsername
            }
            license {
                name
            }
            images {
                filePath
            }
            stls {
                id
                name
                fileSize
                filePreviewPath
            }
        }
    }
    GRAPHQL;

    $ch = curl_init($graphqlUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'query' => $query,
            'variables' => ['id' => $modelId]
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Silo/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Could not fetch from Printables']);
        return;
    }

    $result = json_decode($response, true);
    $data = $result['data']['print'] ?? null;

    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Model not found on Printables']);
        return;
    }

    $files = array_map(function($f) use ($modelId) {
        return [
            'id' => $f['id'],
            'name' => $f['name'],
            'size' => $f['fileSize'],
            // Printables requires authentication for downloads
            'download_url' => null,
            'note' => 'Direct download not available - manual download required'
        ];
    }, $data['stls'] ?? []);

    echo json_encode([
        'success' => true,
        'source' => 'printables',
        'model' => [
            'id' => $modelId,
            'name' => $data['name'] ?? '',
            'description' => strip_tags($data['description'] ?? ''),
            'creator' => $data['user']['publicUsername'] ?? '',
            'license' => $data['license']['name'] ?? '',
            'url' => "https://www.printables.com/model/$modelId",
            'thumbnail' => !empty($data['images']) ? 'https://media.printables.com/' . $data['images'][0]['filePath'] : null,
            'files' => $files
        ]
    ]);
}

/**
 * Import a model (download files and create in Silo)
 */
function importModel() {
    global $user;

    $source = $_POST['source'] ?? '';
    $modelData = $_POST['model'] ?? '';

    if (is_string($modelData)) {
        $modelData = json_decode($modelData, true);
    }

    if (!$modelData) {
        echo json_encode(['success' => false, 'error' => 'Model data required']);
        return;
    }

    $name = $modelData['name'] ?? 'Imported Model';
    $description = $modelData['description'] ?? '';
    $creator = $modelData['creator'] ?? '';
    $license = $modelData['license'] ?? '';
    $sourceUrl = $modelData['url'] ?? '';
    $files = $modelData['files'] ?? [];

    if (empty($files)) {
        echo json_encode(['success' => false, 'error' => 'No files to import']);
        return;
    }

    // Create folder for imported model
    $folderId = 'import_' . bin2hex(random_bytes(8));
    $uploadDir = UPLOAD_PATH . $folderId;
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Could not create upload directory']);
        return;
    }

    $importedFiles = [];
    $errors = [];

    // Get allowed file extensions for validation
    $allowedExtensions = function_exists('getAllowedExtensions') ? getAllowedExtensions() : ['stl', '3mf', 'obj', 'ply', 'gcode'];

    foreach ($files as $file) {
        if (empty($file['download_url'])) {
            $errors[] = "Cannot download {$file['name']} - no download URL";
            continue;
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);

        // Validate file extension against allowed types
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExtensions, true)) {
            $errors[] = "File type not allowed: {$file['name']} (.{$fileExt})";
            continue;
        }

        // Validate URL to prevent SSRF
        $downloadUrl = $file['download_url'];
        $parsedUrl = parse_url($downloadUrl);
        if (!$parsedUrl || !isset($parsedUrl['scheme']) || !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)) {
            $errors[] = "Invalid download URL for {$file['name']} - only HTTP/HTTPS allowed";
            continue;
        }
        // Block internal/private network addresses
        $host = $parsedUrl['host'] ?? '';
        $hostIp = gethostbyname($host);
        if ($hostIp && filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $errors[] = "Download URL for {$file['name']} resolves to a private/reserved address";
            continue;
        }

        $filePath = $uploadDir . '/' . $filename;

        // Download file
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['User-Agent: Silo/1.0']
        ]);
        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($fileContent)) {
            $errors[] = "Failed to download {$file['name']}";
            continue;
        }

        if (file_put_contents($filePath, $fileContent) === false) {
            $errors[] = "Failed to save {$file['name']}";
            continue;
        }

        $importedFiles[] = [
            'filename' => $filename,
            'path' => $folderId . '/' . $filename,
            'size' => strlen($fileContent),
            'extension' => strtolower(pathinfo($filename, PATHINFO_EXTENSION))
        ];
    }

    if (empty($importedFiles)) {
        rmdir($uploadDir);
        echo json_encode(['success' => false, 'error' => 'No files imported', 'details' => $errors]);
        return;
    }

    // Insert main model
    $mainFile = $importedFiles[0];
    $db = getDB();

    $stmt = $db->prepare('
        INSERT INTO models (name, filename, file_path, file_size, file_type, description,
                           creator, source_url, license, file_hash, original_size,
                           created_at, updated_at)
        VALUES (:name, :filename, :file_path, :file_size, :file_type, :description,
                :creator, :source_url, :license, :file_hash, :original_size,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        ':name' => $name,
        ':filename' => $mainFile['filename'],
        ':file_path' => $mainFile['path'],
        ':file_size' => $mainFile['size'],
        ':file_type' => $mainFile['extension'],
        ':description' => $description,
        ':creator' => $creator,
        ':source_url' => $sourceUrl,
        ':license' => $license,
        ':file_hash' => hash_file('sha256', UPLOAD_PATH . $mainFile['path']),
        ':original_size' => $mainFile['size']
    ]);

    $modelId = $db->lastInsertId();

    // Add additional files as parts
    for ($i = 1; $i < count($importedFiles); $i++) {
        $part = $importedFiles[$i];
        $stmt = $db->prepare('
            INSERT INTO models (parent_id, name, filename, file_path, file_size, file_type,
                               file_hash, original_size, sort_order, created_at, updated_at)
            VALUES (:parent_id, :name, :filename, :file_path, :file_size, :file_type,
                    :file_hash, :original_size, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            ':parent_id' => $modelId,
            ':name' => pathinfo($part['filename'], PATHINFO_FILENAME),
            ':filename' => $part['filename'],
            ':file_path' => $part['path'],
            ':file_size' => $part['size'],
            ':file_type' => $part['extension'],
            ':file_hash' => hash_file('sha256', UPLOAD_PATH . $part['path']),
            ':original_size' => $part['size'],
            ':sort_order' => $i
        ]);
    }

    // Update part count
    $stmt = $db->prepare('UPDATE models SET part_count = :count WHERE id = :id');
    $stmt->execute([':count' => count($importedFiles), ':id' => $modelId]);

    logActivity('import', 'model', $modelId, $name, ['source' => $source, 'files' => count($importedFiles)]);

    echo json_encode([
        'success' => true,
        'model_id' => $modelId,
        'imported_files' => count($importedFiles),
        'errors' => $errors
    ]);
}
