<?php
require_once 'includes/config.php';
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $author = trim($_POST['author'] ?? '');
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
    } else {
        $file = $_FILES['model_file'];
        $originalName = $file['name'];
        $tmpPath = $file['tmp_name'];
        $fileSize = $file['size'];

        // Get file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Validate extension
        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            $error = 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS);
        } elseif ($fileSize > MAX_FILE_SIZE) {
            $error = 'File too large. Maximum size: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB';
        } else {
            // Generate unique filename
            $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $filePath = UPLOAD_PATH . $filename;

            // Move uploaded file
            if (move_uploaded_file($tmpPath, $filePath)) {
                // Insert into database
                $stmt = $db->prepare('INSERT INTO models (name, filename, file_path, file_size, file_type, description, author, collection, source_url) VALUES (:name, :filename, :file_path, :file_size, :file_type, :description, :author, :collection, :source_url)');
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
                $stmt->bindValue(':file_path', 'assets/' . $filename, SQLITE3_TEXT);
                $stmt->bindValue(':file_size', $fileSize, SQLITE3_INTEGER);
                $stmt->bindValue(':file_type', $extension, SQLITE3_TEXT);
                $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                $stmt->bindValue(':author', $author, SQLITE3_TEXT);
                $stmt->bindValue(':collection', $collection, SQLITE3_TEXT);
                $stmt->bindValue(':source_url', $source_url, SQLITE3_TEXT);
                $stmt->execute();

                $modelId = $db->lastInsertRowID();

                // Link categories
                if (!empty($selectedCategories)) {
                    foreach ($selectedCategories as $categoryId) {
                        $stmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                        $stmt->bindValue(':model_id', $modelId, SQLITE3_INTEGER);
                        $stmt->bindValue(':category_id', (int)$categoryId, SQLITE3_INTEGER);
                        $stmt->execute();
                    }
                }

                // Add collection to collections table if it doesn't exist
                if (!empty($collection)) {
                    try {
                        $stmt = $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (:name)');
                        $stmt->bindValue(':name', $collection, SQLITE3_TEXT);
                        $stmt->execute();
                    } catch (Exception $e) {
                        // Ignore if already exists
                    }
                }

                // Redirect to model page or home
                header('Location: index.php?uploaded=1');
                exit;
            } else {
                $error = 'Failed to save uploaded file.';
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

            <form class="upload-form" action="upload.php" method="post" enctype="multipart/form-data">
                <div class="upload-dropzone" id="dropzone">
                    <div class="dropzone-content">
                        <span class="dropzone-icon">&#8679;</span>
                        <p class="dropzone-text">Drag and drop your file here</p>
                        <p class="dropzone-subtext">or</p>
                        <label class="btn btn-primary file-select-btn">
                            Browse Files
                            <input type="file" name="model_file" id="model_file" accept=".stl,.3mf" hidden>
                        </label>
                        <p class="dropzone-hint">Supported formats: .stl, .3mf (Max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB)</p>
                        <p class="file-name-display" id="file-name-display"></p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="model-name">Model Name <span class="required">*</span></label>
                    <input type="text" id="model-name" name="name" class="form-input" placeholder="Enter model name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="model-description">Description</label>
                    <textarea id="model-description" name="description" class="form-input form-textarea" placeholder="Describe your model..." rows="4"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="model-author">Author</label>
                    <input type="text" id="model-author" name="author" class="form-input" placeholder="Original creator of the model" value="<?= htmlspecialchars($_POST['author'] ?? '') ?>">
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

                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Upload Model</button>
                </div>
            </form>
        </div>

        <script>
        // Show selected file name
        document.getElementById('model_file').addEventListener('change', function(e) {
            const display = document.getElementById('file-name-display');
            if (this.files.length > 0) {
                display.textContent = 'Selected: ' + this.files[0].name;
                display.style.color = '#22c55e';
            } else {
                display.textContent = '';
            }
        });

        // Drag and drop support
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('model_file');

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
                const display = document.getElementById('file-name-display');
                display.textContent = 'Selected: ' + files[0].name;
                display.style.color = '#22c55e';
            }
        });
        </script>

<?php require_once 'includes/footer.php'; ?>
