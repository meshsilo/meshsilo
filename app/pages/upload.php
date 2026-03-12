<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/Queue.php';

// Check upload permission
requirePermission(PERM_UPLOAD);

$pageTitle = 'Upload Model';
$activePage = 'upload';

$db = getDB();

// Load categories from database
$result = $db->query('SELECT * FROM categories ORDER BY name');
$categories = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
}

// Load collections from database for datalist
$result = $db->query('SELECT name FROM collections ORDER BY name');
$collections = [];
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $collections[] = $row['name'];
}

$message = '';
$error = '';
$uploadedCount = 0;

// Helper function to create a model folder
function createModelFolder($folderName = null) {
    $folderId = $folderName ?? uniqid();
    $folderPath = UPLOAD_PATH . $folderId . '/';

    if (!file_exists($folderPath)) {
        mkdir($folderPath, 0755, true);
    }

    return $folderId;
}

// Helper function to save a single model file
function saveModelFile($db, $tmpPath, $originalName, $name, $description, $creator, $collection, $source_url, $selectedCategories, $parentId = null, $originalPath = null, $folderId = null) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // Only process valid model files
    if (!isModelExtension($extension)) {
        logWarning('Invalid model extension rejected', ['file' => $originalName, 'extension' => $extension]);
        return false;
    }

    $fileSize = filesize($tmpPath);

    // Calculate file hash for deduplication (content-based for 3MF files)
    $fileHash = calculateContentHash($tmpPath);

    // Create folder for standalone uploads (not parts of a ZIP)
    if (!$folderId) {
        $folderId = createModelFolder();
    }

    // Generate unique filename and store in folder
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $folderPath = UPLOAD_PATH . $folderId . '/';

    // Preserve subdirectory structure from ZIP uploads to prevent name conflicts
    $subDir = '';
    if ($originalPath && dirname($originalPath) !== '.') {
        $subDir = preg_replace('/[^a-zA-Z0-9._\/-]/', '_', dirname($originalPath));
        $subDir = trim($subDir, '/') . '/';
        $folderPath .= $subDir;
    }

    $filePath = $folderPath . $filename;

    // Ensure folder exists
    if (!file_exists($folderPath)) {
        mkdir($folderPath, 0755, true);
    }

    // Move file to assets folder (rename is instant on same filesystem, falls back to copy)
    $moved = @rename($tmpPath, $filePath);
    if (!$moved) {
        $moved = copy($tmpPath, $filePath);
        if ($moved) {
            @unlink($tmpPath);
        }
    }
    if ($moved) {
        try {
            // Insert into database
            $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, file_hash, description, creator, collection, source_url, parent_id, original_path) VALUES (:name, :filename, :file_path, :file_size, :file_type, :file_hash, :description, :creator, :collection, :source_url, :parent_id, :original_path)');
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
            $stmt->bindValue(':file_path', 'assets/' . $folderId . '/' . $subDir . $filename, PDO::PARAM_STR);
            $stmt->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
            $stmt->bindValue(':file_type', $extension, PDO::PARAM_STR);
            $stmt->bindValue(':file_hash', $fileHash, PDO::PARAM_STR);
            $stmt->bindValue(':description', $parentId ? '' : $description, PDO::PARAM_STR); // Only set description on parent
            $stmt->bindValue(':creator', $parentId ? '' : $creator, PDO::PARAM_STR);
            $stmt->bindValue(':collection', $parentId ? '' : $collection, PDO::PARAM_STR);
            $stmt->bindValue(':source_url', $parentId ? '' : $source_url, PDO::PARAM_STR);
            $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
            $stmt->bindValue(':original_path', $originalPath, PDO::PARAM_STR);
            $stmt->execute();

            $modelId = $db->lastInsertRowID();

            // Link categories only for standalone models (not parts)
            if (!$parentId && !empty($selectedCategories)) {
                foreach ($selectedCategories as $categoryId) {
                    $stmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
                    $stmt->bindValue(':category_id', (int)$categoryId, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }

            logInfo('Model uploaded successfully', ['model_id' => $modelId, 'name' => $name, 'file' => $filename, 'folder' => $folderId, 'parent_id' => $parentId]);

            // Queue auto-conversion and thumbnail generation for background processing
            if ($extension === 'stl' && getSetting('auto_convert_stl', '0') === '1') {
                Queue::push('ConvertStlTo3mf', ['model_id' => $modelId]);
            }
            if (in_array($extension, ['3mf', 'stl'])) {
                Queue::push('GenerateThumbnail', ['model_id' => $modelId]);
            }

            return $modelId;
        } catch (Exception $e) {
            logException($e, ['action' => 'save_model', 'name' => $name]);
            // Clean up the copied file since DB insert failed
            unlink($filePath);
            return false;
        }
    }

    logError('Failed to copy uploaded file', ['source' => $tmpPath, 'destination' => $filePath]);
    return false;
}

// Helper function to create a parent model for multi-part uploads
function createParentModel($db, $name, $description, $creator, $collection, $source_url, $selectedCategories, $totalSize, $folderId) {
    try {
        $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, description, creator, collection, source_url, part_count) VALUES (:name, :filename, :file_path, :file_size, :file_type, :description, :creator, :collection, :source_url, 0)');
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':filename', $folderId, PDO::PARAM_STR);
        $stmt->bindValue(':file_path', 'assets/' . $folderId, PDO::PARAM_STR);
        $stmt->bindValue(':file_size', $totalSize, PDO::PARAM_INT);
        $stmt->bindValue(':file_type', 'parent', PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        $stmt->bindValue(':creator', $creator, PDO::PARAM_STR);
        $stmt->bindValue(':collection', $collection, PDO::PARAM_STR);
        $stmt->bindValue(':source_url', $source_url, PDO::PARAM_STR);
        $stmt->execute();

        $parentId = $db->lastInsertRowID();

        // Link categories to parent
        if (!empty($selectedCategories)) {
            foreach ($selectedCategories as $categoryId) {
                $stmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                $stmt->bindValue(':model_id', $parentId, PDO::PARAM_INT);
                $stmt->bindValue(':category_id', (int)$categoryId, PDO::PARAM_INT);
                $stmt->execute();
            }
        }

        return $parentId;
    } catch (Exception $e) {
        logException($e, ['action' => 'create_parent_model', 'name' => $name]);
        return false;
    }
}

// Helper function to update parent model's part count and total size
function updateParentModel($db, $parentId, $partCount, $totalSize) {
    try {
        $stmt = $db->prepare('UPDATE models SET part_count = :part_count, file_size = :file_size WHERE id = :id');
        $stmt->bindValue(':part_count', $partCount, PDO::PARAM_INT);
        $stmt->bindValue(':file_size', $totalSize, PDO::PARAM_INT);
        $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        logException($e, ['action' => 'update_parent_model', 'parent_id' => $parentId]);
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if POST data was discarded due to size limits being exceeded
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
    $postMaxSize = ini_get('post_max_size');
    $uploadMaxSize = ini_get('upload_max_filesize');
    $postMaxBytes = convertToBytes($postMaxSize);
    $uploadMaxBytes = convertToBytes($uploadMaxSize);
    $effectiveLimit = min($postMaxBytes, $uploadMaxBytes);
    $effectiveLimitDisplay = $postMaxBytes < $uploadMaxBytes ? $postMaxSize : $uploadMaxSize;

    if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
        // POST data was likely discarded - file too large
        $error = sprintf(
            'Upload failed: The file exceeds the server\'s maximum upload size (%s). Please upload a smaller file or contact an administrator to increase the limit.',
            $effectiveLimitDisplay
        );
        logWarning('Upload exceeded size limit', [
            'content_length' => $contentLength,
            'post_max_size' => $postMaxSize,
            'upload_max_filesize' => $uploadMaxSize,
            'effective_limit' => $effectiveLimit
        ]);
    }
    // Validate CSRF token
    elseif (!Csrf::validate()) {
        $error = 'Security validation failed. Please try again.';
        // Log detailed CSRF diagnosis if debug mode is enabled
        if (class_exists('Debug') && Debug::isEnabled()) {
            Debug::diagnoseCsrf();
        }
    } else {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $creator = trim($_POST['author'] ?? '');
    $collection = trim($_POST['collection'] ?? '');
    $source_url = trim($_POST['source_url'] ?? '');
    $selectedCategories = $_POST['categories'] ?? [];

    // Validate required fields
    if (empty($name)) {
        $error = 'Please enter a model name.';
    } elseif (!isset($_FILES['model_file']) || $_FILES['model_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a file to upload.';
    } elseif ($_FILES['model_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed. Error code: ' . $_FILES['model_file']['error'];
        logError('File upload failed', ['error_code' => $_FILES['model_file']['error'], 'file' => $_FILES['model_file']['name'] ?? 'unknown']);
    } else {
        $file = $_FILES['model_file'];
        $originalName = $file['name'];
        $tmpPath = $file['tmp_name'];
        $fileSize = $file['size'];

        // Get file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Validate extension
        if (!isExtensionAllowed($extension)) {
            $error = 'Invalid file type. Allowed: ' . implode(', ', getAllowedExtensions());
            logWarning('Invalid file type rejected', ['file' => $originalName, 'extension' => $extension]);
        } elseif ($fileSize > MAX_FILE_SIZE) {
            $error = 'File too large. Maximum size: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB';
            logWarning('File too large rejected', ['file' => $originalName, 'size' => $fileSize, 'max' => MAX_FILE_SIZE]);
        } else {
            // Handle ZIP files
            if ($extension === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($tmpPath) === true) {
                    // Create temp directory for extraction
                    $extractDir = sys_get_temp_dir() . '/silo_' . uniqid();
                    mkdir($extractDir, 0755, true);
                    $realExtractDir = realpath($extractDir);

                    // Safe extraction with path traversal protection (ZIP Slip prevention)
                    $extractedCount = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);

                        // Skip directories
                        if (substr($filename, -1) === '/') {
                            continue;
                        }

                        // Build the target path and resolve it
                        $targetPath = $extractDir . '/' . $filename;
                        $targetDir = dirname($targetPath);

                        // Create directory if needed
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }

                        // Verify the resolved path is within our extraction directory
                        $realTargetPath = realpath($targetDir) . '/' . basename($filename);
                        if (strpos($realTargetPath, $realExtractDir) !== 0) {
                            // Path traversal attempt detected - skip this file
                            logWarning('ZIP path traversal attempt blocked', [
                                'filename' => $filename,
                                'target' => $realTargetPath,
                                'allowed_base' => $realExtractDir
                            ]);
                            continue;
                        }

                        // Extract the file using streams to avoid memory exhaustion on large files
                        $stream = $zip->getStream($filename);
                        if ($stream !== false) {
                            $outFile = fopen($realTargetPath, 'w');
                            if ($outFile !== false) {
                                stream_copy_to_stream($stream, $outFile);
                                fclose($outFile);
                                $extractedCount++;
                            }
                            fclose($stream);
                        }
                    }
                    $zip->close();

                    // First pass: collect all model files, images, and text files with their paths
                    $modelFiles = [];
                    $imageFiles = [];
                    $attachmentFiles = [];
                    $imageExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                    $textExtensions = ['txt', 'md'];
                    $pdfExtensions = ['pdf'];
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS)
                    );

                    foreach ($iterator as $fileInfo) {
                        if ($fileInfo->isFile()) {
                            $fileExt = strtolower($fileInfo->getExtension());
                            if (isModelExtension($fileExt)) {
                                // Get relative path from extract directory
                                $relativePath = str_replace($extractDir . '/', '', $fileInfo->getPathname());
                                $modelFiles[] = [
                                    'path' => $fileInfo->getPathname(),
                                    'filename' => $fileInfo->getFilename(),
                                    'relative_path' => $relativePath,
                                    'size' => $fileInfo->getSize()
                                ];
                            } elseif (in_array($fileExt, $imageExtensions)) {
                                $imageFiles[] = [
                                    'path' => $fileInfo->getPathname(),
                                    'filename' => $fileInfo->getFilename(),
                                    'extension' => $fileExt
                                ];
                                $attachmentFiles[] = [
                                    'path' => $fileInfo->getPathname(),
                                    'filename' => $fileInfo->getFilename(),
                                    'extension' => $fileExt,
                                    'type' => 'image'
                                ];
                            } elseif (in_array($fileExt, array_merge($textExtensions, $pdfExtensions))) {
                                $attachmentFiles[] = [
                                    'path' => $fileInfo->getPathname(),
                                    'filename' => $fileInfo->getFilename(),
                                    'extension' => $fileExt,
                                    'type' => in_array($fileExt, $textExtensions) ? 'text' : 'pdf'
                                ];
                            }
                        }
                    }

                    // Sort files by their relative path to maintain directory structure
                    usort($modelFiles, function($a, $b) {
                        return strcmp($a['relative_path'], $b['relative_path']);
                    });

                    if (!empty($modelFiles)) {
                        // Calculate total size
                        $totalSize = array_sum(array_column($modelFiles, 'size'));

                        // Create a folder for this model
                        $folderId = createModelFolder();

                        // Create parent model
                        $parentId = createParentModel($db, $name, $description, $creator, $collection, $source_url, $selectedCategories, $totalSize, $folderId);

                        if ($parentId) {
                            // Save all parts in a single transaction for speed
                            $db->exec('BEGIN TRANSACTION');
                            try {
                                foreach ($modelFiles as $modelFile) {
                                    $partName = pathinfo($modelFile['filename'], PATHINFO_FILENAME);
                                    if (saveModelFile($db, $modelFile['path'], $modelFile['filename'], $partName, '', '', '', '', [], $parentId, $modelFile['relative_path'], $folderId)) {
                                        $uploadedCount++;
                                    }
                                }

                                // Update parent with final part count
                                updateParentModel($db, $parentId, $uploadedCount, $totalSize);
                                $db->exec('COMMIT');
                            } catch (Exception $e) {
                                $db->exec('ROLLBACK');
                                throw $e;
                            }

                            // Save first image from ZIP as model thumbnail
                            if (!empty($imageFiles)) {
                                $img = $imageFiles[0];
                                $thumbDir = 'thumbnails';
                                $thumbFullDir = UPLOAD_PATH . $thumbDir;
                                if (!is_dir($thumbFullDir)) {
                                    mkdir($thumbFullDir, 0755, true);
                                }
                                $thumbFilename = $parentId . '_' . time() . '.' . $img['extension'];
                                $thumbDest = $thumbFullDir . '/' . $thumbFilename;
                                if (copy($img['path'], $thumbDest)) {
                                    $thumbRelative = $thumbDir . '/' . $thumbFilename;
                                    $stmt = $db->prepare('UPDATE models SET thumbnail_path = :path WHERE id = :id');
                                    $stmt->bindValue(':path', $thumbRelative, PDO::PARAM_STR);
                                    $stmt->bindValue(':id', $parentId, PDO::PARAM_INT);
                                    $stmt->execute();
                                    logInfo('Saved image from ZIP as thumbnail', ['parent_id' => $parentId, 'image' => $img['filename']]);
                                }
                            }

                            // Save images, text files, and PDFs from ZIP as attachments
                            if (!empty($attachmentFiles) && isFeatureEnabled('attachments')) {
                                $modelHash = substr(md5($name . $parentId), 0, 12);
                                $attachDir = UPLOAD_PATH . $modelHash . '/attachments';
                                if (!is_dir($attachDir)) {
                                    mkdir($attachDir, 0755, true);
                                }
                                foreach ($attachmentFiles as $attFile) {
                                    $attFilename = $attFile['type'] . '_' . $parentId . '_' . time() . '_' . mt_rand(100, 999) . '.' . $attFile['extension'];
                                    $attDest = $attachDir . '/' . $attFilename;
                                    if (copy($attFile['path'], $attDest)) {
                                        $attRelative = $modelHash . '/attachments/' . $attFilename;
                                        $mimeMap = [
                                            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                                            'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
                                            'txt' => 'text/plain', 'md' => 'text/markdown'
                                        ];
                                        $attMime = $mimeMap[$attFile['extension']] ?? 'application/octet-stream';
                                        $stmt = $db->prepare('INSERT INTO model_attachments (model_id, filename, file_path, file_type, mime_type, file_size, original_filename, display_order)
                                                              VALUES (:model_id, :filename, :file_path, :file_type, :mime_type, :file_size, :original_filename,
                                                                      (SELECT COALESCE(MAX(display_order), 0) + 1 FROM model_attachments WHERE model_id = :model_id2))');
                                        $stmt->bindValue(':model_id', $parentId, PDO::PARAM_INT);
                                        $stmt->bindValue(':model_id2', $parentId, PDO::PARAM_INT);
                                        $stmt->bindValue(':filename', $attFilename, PDO::PARAM_STR);
                                        $stmt->bindValue(':file_path', $attRelative, PDO::PARAM_STR);
                                        $stmt->bindValue(':file_type', $attFile['type'], PDO::PARAM_STR);
                                        $stmt->bindValue(':mime_type', $attMime, PDO::PARAM_STR);
                                        $stmt->bindValue(':file_size', filesize($attDest), PDO::PARAM_INT);
                                        $stmt->bindValue(':original_filename', $attFile['filename'], PDO::PARAM_STR);
                                        $stmt->execute();
                                    }
                                }
                                logInfo('Saved attachments from ZIP', ['parent_id' => $parentId, 'count' => count($attachmentFiles)]);
                            }

                            logInfo('ZIP extraction complete', ['file' => $originalName, 'parent_id' => $parentId, 'parts' => $uploadedCount, 'folder' => $folderId]);

                            if (class_exists('PluginManager')) {
                                PluginManager::applyFilter('after_upload', null, $parentId, [
                                    'name' => $name,
                                    'file_type' => $extension,
                                    'user_id' => isLoggedIn() ? (getCurrentUser()['id'] ?? null) : null
                                ]);
                            }
                        } else {
                            $error = 'Failed to create model entry.';
                            // Clean up empty folder if parent creation failed
                            if (is_dir(UPLOAD_PATH . $folderId)) {
                                rmdir(UPLOAD_PATH . $folderId);
                            }
                        }
                    }

                    // Clean up temp directory
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileInfo) {
                        if ($fileInfo->isDir()) {
                            rmdir($fileInfo->getRealPath());
                        } else {
                            unlink($fileInfo->getRealPath());
                        }
                    }
                    rmdir($extractDir);

                    if ($uploadedCount === 0 && empty($error)) {
                        $error = 'No valid 3D model or slicer files found in the ZIP archive.';
                        logWarning('No valid models in ZIP', ['file' => $originalName]);
                    }
                } else {
                    $error = 'Failed to open ZIP file.';
                    logError('Failed to open ZIP file', ['file' => $originalName]);
                }
            } elseif (isAttachmentExtension($extension)) {
                // Attachment files (images, text, PDFs) can't be uploaded standalone
                $error = 'Images, text files, and PDFs are added as attachments from the model page, or included in a ZIP with model files.';
                logInfo('Attachment file uploaded standalone', ['file' => $originalName, 'extension' => $extension]);
            } else {
                // Handle single model file - create parent with child part (like ZIP)
                $fileSize = filesize($tmpPath);

                // Create a folder for this model
                $folderId = createModelFolder();

                // Create parent model
                $parentId = createParentModel($db, $name, $description, $creator, $collection, $source_url, $selectedCategories, $fileSize, $folderId);

                if ($parentId) {
                    // Save the file as a child part
                    $partName = pathinfo($originalName, PATHINFO_FILENAME);
                    if (saveModelFile($db, $tmpPath, $originalName, $partName, '', '', '', '', [], $parentId, $originalName, $folderId)) {
                        $uploadedCount = 1;
                        // Update parent with part count
                        updateParentModel($db, $parentId, 1, $fileSize);
                        logInfo('Single file upload complete', ['file' => $originalName, 'parent_id' => $parentId, 'folder' => $folderId]);

                        if (class_exists('PluginManager')) {
                            PluginManager::applyFilter('after_upload', null, $parentId, [
                                'name' => $name,
                                'file_type' => $extension,
                                'user_id' => isLoggedIn() ? (getCurrentUser()['id'] ?? null) : null
                            ]);
                        }
                    } else {
                        $error = 'Failed to save uploaded file.';
                        logError('Failed to save single model file', ['file' => $originalName, 'name' => $name]);
                        // Clean up parent if part save failed using parameterized query
                        $cleanupStmt = $db->prepare('DELETE FROM models WHERE id = :id');
                        $cleanupStmt->bindValue(':id', $parentId, PDO::PARAM_INT);
                        $cleanupStmt->execute();
                        if (is_dir(UPLOAD_PATH . $folderId)) {
                            rmdir(UPLOAD_PATH . $folderId);
                        }
                    }
                } else {
                    $error = 'Failed to create model entry.';
                    // Clean up empty folder
                    if (is_dir(UPLOAD_PATH . $folderId)) {
                        rmdir(UPLOAD_PATH . $folderId);
                    }
                }
            }

            // Add collection to collections table if it doesn't exist
            if ($uploadedCount > 0 && !empty($collection)) {
                try {
                    $stmt = $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (:name)');
                    $stmt->bindValue(':name', $collection, PDO::PARAM_STR);
                    $stmt->execute();
                } catch (Exception $e) {
                    // Ignore if already exists
                }
            }

            // Redirect on success
            if ($uploadedCount > 0) {
                header('Location: ' . route('home', [], ['uploaded' => $uploadedCount]));
                exit;
            }
        }
    }
    } // End CSRF validation
}

require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="page-header">
                <h1>Upload Model</h1>
                <p>Share your 3D print files</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form class="upload-form" id="upload-form" action="<?= route('upload') ?>" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="upload-dropzone" id="dropzone">
                    <div class="dropzone-content">
                        <span class="dropzone-icon">&#8679;</span>
                        <p class="dropzone-text">Drag and drop your file here</p>
                        <p class="dropzone-subtext">or</p>
                        <div class="upload-buttons">
                            <label class="btn btn-primary file-select-btn">
                                Browse Files
                                <input type="file" name="model_file" id="model_file" accept=".stl,.3mf,.obj,.ply,.amf,.gcode,.glb,.gltf,.fbx,.dae,.blend,.step,.stp,.iges,.igs,.3ds,.dxf,.off,.x3d,.zip,.lys,.ctb,.pwmo,.sl1" hidden>
                            </label>
                            <label class="btn btn-secondary file-select-btn mobile-only">
                                Take Photo
                                <input type="file" name="photo_file" id="photo_file" accept="image/*" capture="environment" hidden>
                            </label>
                        </div>
                        <p class="dropzone-hint">Supported: 3D models, slicer files (.lys, .ctb, .sl1), and ZIP archives (Max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB)</p>
                        <p class="dropzone-hint">ZIP files will be unpacked — models imported as parts, images &amp; text files added as attachments</p>
                        <p class="file-name-display" id="file-name-display"></p>
                    </div>
                </div>

                <!-- Upload Progress Bar -->
                <div class="upload-progress" id="upload-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-bar-fill" id="progress-fill" style="width: 0%"></div>
                    </div>
                    <p class="progress-text" id="progress-text">Uploading... 0%</p>
                </div>

                <div class="form-group">
                    <label for="model-name">Model Name <span class="required">*</span></label>
                    <input type="text" id="model-name" name="name" class="form-input" placeholder="Enter model name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    <small class="form-help">For ZIP files with multiple models, the filename will be appended</small>
                </div>

                <div class="form-group">
                    <label for="model-description">Description</label>
                    <textarea id="model-description" name="description" class="form-input form-textarea" placeholder="Describe your model..." rows="4"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    <small class="form-hint">Supports Markdown: **bold**, *italic*, `code`, [links](url), lists, headings (##), and more.</small>
                </div>

                <!-- Advanced Options (collapsible on mobile) -->
                <details class="advanced-options" id="advanced-options">
                    <summary class="advanced-toggle">Advanced Options</summary>
                    <div class="advanced-content">
                        <div class="form-group">
                            <label for="model-creator">Creator</label>
                            <input type="text" id="model-creator" name="creator" class="form-input" placeholder="Original creator of the model" value="<?= htmlspecialchars($_POST['creator'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="model-collection">Collection</label>
                            <input type="text" id="model-collection" name="collection" class="form-input" placeholder="Collection name (e.g., Gridfinity, Voron)" list="collections-list" value="<?= htmlspecialchars($_POST['collection'] ?? '') ?>">
                            <datalist id="collections-list">
                                <?php foreach ($collections as $col): ?>
                                <option value="<?= htmlspecialchars($col) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group">
                            <label for="model-source">Source Link</label>
                            <input type="url" id="model-source" name="source_url" class="form-input" placeholder="https://thingiverse.com/..." value="<?= htmlspecialchars($_POST['source_url'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Categories</label>
                            <div class="checkbox-group">
                                <?php foreach ($categories as $category): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="categories[]" value="<?= $category['id'] ?>" <?= in_array($category['id'], $_POST['categories'] ?? []) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($category['name']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </details>

                <?php if (class_exists('PluginManager')): ?>
                <?= PluginManager::applyFilter('upload_form_fields', '') ?>
                <?php endif; ?>

                <div class="form-actions">
                    <a href="<?= route('home') ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" id="submit-btn">Upload Model</button>
                </div>
            </form>

        </div>

        <script>
        const fileInput = document.getElementById('model_file');
        const photoInput = document.getElementById('photo_file');
        const dropzone = document.getElementById('dropzone');
        const display = document.getElementById('file-name-display');
        const uploadForm = document.getElementById('upload-form');
        const submitBtn = document.getElementById('submit-btn');
        const progressContainer = document.getElementById('upload-progress');
        const progressFill = document.getElementById('progress-fill');
        const progressText = document.getElementById('progress-text');

        // File selection handlers
        function handleFileSelect(file) {
            display.textContent = 'Selected: ' + file.name;
            display.style.color = 'var(--color-success)';

            // Auto-fill name from filename if empty
            const nameInput = document.getElementById('model-name');
            if (!nameInput.value) {
                nameInput.value = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
            }
        }

        fileInput.addEventListener('change', function(e) {
            if (this.files.length > 0) {
                handleFileSelect(this.files[0]);
            } else {
                display.textContent = '';
            }
        });

        // Photo capture (mobile)
        if (photoInput) {
            photoInput.addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    display.textContent = 'Photo captured: ' + this.files[0].name;
                    display.style.color = 'var(--color-success)';
                }
            });
        }

        // Drag and drop support
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.remove('dragover'), false);
        });

        dropzone.addEventListener('drop', function(e) {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                handleFileSelect(files[0]);
            }
        });

        // Progressive upload with XHR
        uploadForm.addEventListener('submit', function(e) {
            // Check if file is selected
            if (!fileInput.files.length) {
                return; // Let default validation handle it
            }

            // For large files, use XHR for progress tracking
            const file = fileInput.files[0];
            const fileSizeMB = file.size / (1024 * 1024);

            // Use XHR for files larger than 1MB
            if (fileSizeMB > 1) {
                e.preventDefault();
                uploadWithProgress();
            }
        });

        function uploadWithProgress() {
            const formData = new FormData(uploadForm);

            // Show progress bar
            progressContainer.style.display = 'block';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressFill.style.width = percent + '%';
                    progressText.textContent = 'Uploading... ' + percent + '%';

                    if (percent === 100) {
                        progressText.textContent = 'Processing...';
                    }
                }
            });

            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    // Check if redirect (success)
                    if (xhr.responseURL && xhr.responseURL.includes('uploaded=')) {
                        window.location.href = xhr.responseURL;
                    } else {
                        // Re-submit form to show response
                        progressContainer.style.display = 'none';
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Upload Model';
                        uploadForm.submit();
                    }
                } else {
                    alert('Upload failed. Please try again.');
                    progressContainer.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Upload Model';
                }
            });

            xhr.addEventListener('error', function() {
                alert('Upload failed. Please check your connection and try again.');
                progressContainer.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload Model';
            });

            xhr.open('POST', uploadForm.action, true);
            xhr.send(formData);
        }

        // Auto-open advanced options on desktop, keep collapsed on mobile
        const advancedOptions = document.getElementById('advanced-options');
        if (window.innerWidth >= 768) {
            advancedOptions.setAttribute('open', '');
        }

        </script>

<?php require_once 'includes/footer.php'; ?>
