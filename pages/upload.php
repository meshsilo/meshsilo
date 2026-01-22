<?php
require_once 'includes/config.php';
require_once 'includes/converter.php';
require_once 'includes/dedup.php';
require_once 'includes/gcode.php';

// Check upload permission
requirePermission(PERM_UPLOAD);

$pageTitle = 'Upload Model';
$activePage = 'upload';

$db = getDB();

// Load categories from database
$result = $db->query('SELECT * FROM categories ORDER BY name');
$categories = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row;
}

// Load collections from database for datalist
$db->exec('CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$result = $db->query('SELECT name FROM collections ORDER BY name');
$collections = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
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
    $filePath = $folderPath . $filename;

    // Ensure folder exists
    if (!file_exists($folderPath)) {
        mkdir($folderPath, 0755, true);
    }

    // Copy file to assets folder
    if (copy($tmpPath, $filePath)) {
        try {
            // Insert into database
            $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, file_hash, description, creator, collection, source_url, parent_id, original_path) VALUES (:name, :filename, :file_path, :file_size, :file_type, :file_hash, :description, :creator, :collection, :source_url, :parent_id, :original_path)');
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
            $stmt->bindValue(':file_path', 'assets/' . $folderId . '/' . $filename, SQLITE3_TEXT);
            $stmt->bindValue(':file_size', $fileSize, SQLITE3_INTEGER);
            $stmt->bindValue(':file_type', $extension, SQLITE3_TEXT);
            $stmt->bindValue(':file_hash', $fileHash, SQLITE3_TEXT);
            $stmt->bindValue(':description', $parentId ? '' : $description, SQLITE3_TEXT); // Only set description on parent
            $stmt->bindValue(':creator', $parentId ? '' : $creator, SQLITE3_TEXT);
            $stmt->bindValue(':collection', $parentId ? '' : $collection, SQLITE3_TEXT);
            $stmt->bindValue(':source_url', $parentId ? '' : $source_url, SQLITE3_TEXT);
            $stmt->bindValue(':parent_id', $parentId, SQLITE3_INTEGER);
            $stmt->bindValue(':original_path', $originalPath, SQLITE3_TEXT);
            $stmt->execute();

            $modelId = $db->lastInsertRowID();

            // Link categories only for standalone models (not parts)
            if (!$parentId && !empty($selectedCategories)) {
                foreach ($selectedCategories as $categoryId) {
                    $stmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                    $stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
                    $stmt->bindValue(':category_id', (int)$categoryId, SQLITE3_INTEGER);
                    $stmt->execute();
                }
            }

            logInfo('Model uploaded successfully', ['model_id' => $modelId, 'name' => $name, 'file' => $filename, 'folder' => $folderId, 'parent_id' => $parentId]);

            // Auto-convert STL to 3MF if enabled
            if ($extension === 'stl' && getSetting('auto_convert_stl', '0') === '1') {
                $convertResult = convertPartTo3MF($modelId);
                if ($convertResult['success']) {
                    logInfo('Auto-converted STL to 3MF', [
                        'model_id' => $modelId,
                        'original_size' => $convertResult['original_size'],
                        'new_size' => $convertResult['new_size'],
                        'savings' => $convertResult['savings']
                    ]);
                }
            }

            // Parse GCODE metadata if uploaded file is GCODE
            if ($extension === 'gcode') {
                $gcodeMetadata = processGCodeFile($modelId, $filePath);
                if (!empty(array_filter($gcodeMetadata))) {
                    logInfo('Parsed GCODE metadata', ['model_id' => $modelId, 'metadata' => $gcodeMetadata]);
                }
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
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':filename', $folderId, SQLITE3_TEXT);
        $stmt->bindValue(':file_path', 'assets/' . $folderId, SQLITE3_TEXT);
        $stmt->bindValue(':file_size', $totalSize, SQLITE3_INTEGER);
        $stmt->bindValue(':file_type', 'parent', SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        $stmt->bindValue(':creator', $creator, SQLITE3_TEXT);
        $stmt->bindValue(':collection', $collection, SQLITE3_TEXT);
        $stmt->bindValue(':source_url', $source_url, SQLITE3_TEXT);
        $stmt->execute();

        $parentId = $db->lastInsertRowID();

        // Link categories to parent
        if (!empty($selectedCategories)) {
            foreach ($selectedCategories as $categoryId) {
                $stmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                $stmt->bindValue(':model_id', $parentId, SQLITE3_INTEGER);
                $stmt->bindValue(':category_id', (int)$categoryId, SQLITE3_INTEGER);
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
        $stmt->bindValue(':part_count', $partCount, SQLITE3_INTEGER);
        $stmt->bindValue(':file_size', $totalSize, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $parentId, SQLITE3_INTEGER);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        logException($e, ['action' => 'update_parent_model', 'parent_id' => $parentId]);
        return false;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

                    $zip->extractTo($extractDir);
                    $zip->close();

                    // First pass: collect all model files with their paths
                    $modelFiles = [];
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
                            // Save each file as a child of the parent, all in the same folder
                            foreach ($modelFiles as $modelFile) {
                                $partName = pathinfo($modelFile['filename'], PATHINFO_FILENAME);
                                if (saveModelFile($db, $modelFile['path'], $modelFile['filename'], $partName, '', '', '', '', [], $parentId, $modelFile['relative_path'], $folderId)) {
                                    $uploadedCount++;
                                }
                            }

                            // Update parent with final part count
                            updateParentModel($db, $parentId, $uploadedCount, $totalSize);
                            logInfo('ZIP extraction complete', ['file' => $originalName, 'parent_id' => $parentId, 'parts' => $uploadedCount, 'folder' => $folderId]);
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
                        $error = 'No valid model files (.stl, .3mf, .gcode) found in the ZIP archive.';
                        logWarning('No valid models in ZIP', ['file' => $originalName]);
                    }
                } else {
                    $error = 'Failed to open ZIP file.';
                    logError('Failed to open ZIP file', ['file' => $originalName]);
                }
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
                    } else {
                        $error = 'Failed to save uploaded file.';
                        logError('Failed to save single model file', ['file' => $originalName, 'name' => $name]);
                        // Clean up parent if part save failed
                        $db->exec('DELETE FROM models WHERE id = ' . $parentId);
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
                    $stmt->bindValue(':name', $collection, SQLITE3_TEXT);
                    $stmt->execute();
                } catch (Exception $e) {
                    // Ignore if already exists
                }
            }

            // Redirect on success
            if ($uploadedCount > 0) {
                header('Location: index.php?uploaded=' . $uploadedCount);
                exit;
            }
        }
    }
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

            <form class="upload-form" id="upload-form" action="upload.php" method="post" enctype="multipart/form-data">
                <div class="upload-dropzone" id="dropzone">
                    <div class="dropzone-content">
                        <span class="dropzone-icon">&#8679;</span>
                        <p class="dropzone-text">Drag and drop your file here</p>
                        <p class="dropzone-subtext">or</p>
                        <div class="upload-buttons">
                            <label class="btn btn-primary file-select-btn">
                                Browse Files
                                <input type="file" name="model_file" id="model_file" accept=".stl,.3mf,.gcode,.zip" hidden>
                            </label>
                            <label class="btn btn-secondary file-select-btn mobile-only">
                                Take Photo
                                <input type="file" name="photo_file" id="photo_file" accept="image/*" capture="environment" hidden>
                            </label>
                        </div>
                        <p class="dropzone-hint">Supported: .stl, .3mf, .gcode, .zip (Max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB)</p>
                        <p class="dropzone-hint">ZIP files will be unpacked and all model files imported</p>
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

                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
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
            display.style.color = '#22c55e';

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
                    display.style.color = '#22c55e';
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
