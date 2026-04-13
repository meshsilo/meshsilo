<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/Queue.php';
require_once 'includes/UploadProcessor.php';

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

// All uploads now go through the tus chunked upload endpoint (/actions/tus).
// The old single-POST form handler has been removed. The HTML form below
// collects metadata, but submission is intercepted by tus-js-client.
$error = '';

$needsTusJs = true;
require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="page-header">
                <h1>Upload Model</h1>
                <p>Share your 3D print files</p>
            </div>

            <?php if ($error): ?>
            <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form class="upload-form" id="upload-form" action="<?= route('upload') ?>" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="upload-dropzone" id="dropzone">
                    <div class="dropzone-content">
                        <span class="dropzone-icon" aria-hidden="true">&#8679;</span>
                        <p class="dropzone-text">Drag and drop your file here</p>
                        <p class="dropzone-subtext">or</p>
                        <div class="upload-buttons">
                            <label class="btn btn-primary file-select-btn">
                                Browse Files
                                <input type="file" name="model_file" id="model_file" accept=".stl,.3mf,.obj,.ply,.amf,.gcode,.glb,.gltf,.fbx,.dae,.blend,.step,.stp,.iges,.igs,.3ds,.dxf,.off,.x3d,.zip,.lys,.ctb,.pwmo,.sl1" hidden aria-label="Browse model files">
                            </label>
                            <label class="btn btn-secondary file-select-btn mobile-only">
                                Take Photo
                                <input type="file" name="photo_file" id="photo_file" accept="image/*" capture="environment" hidden aria-label="Take photo">
                            </label>
                        </div>
                        <p class="dropzone-hint">Supported: 3D models, slicer files (.lys, .ctb, .sl1), and ZIP archives (Max <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB)</p>
                        <p class="dropzone-hint">ZIP files will be unpacked — models imported as parts, images &amp; text files added as attachments</p>
                        <p class="file-name-display" id="file-name-display"></p>
                    </div>
                </div>

                <!-- Upload Progress Bar -->
                <div class="upload-progress" id="upload-progress" style="display: none;">
                    <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress">
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
                    <details class="markdown-preview-toggle" style="margin-top:0.5rem">
                        <summary style="cursor:pointer;color:var(--color-text-muted);font-size:0.85rem">Preview</summary>
                        <div id="md-preview" class="markdown-content" style="padding:1rem;border:1px solid var(--color-border);border-radius:var(--radius);margin-top:0.5rem;min-height:3rem;background:var(--color-surface)"></div>
                    </details>
                </div>

                <!-- Advanced Options (collapsible on mobile) -->
                <details class="advanced-options" id="advanced-options">
                    <summary class="advanced-toggle">Advanced Options</summary>
                    <div class="advanced-content">
                        <div class="form-group">
                            <label for="model-creator">Creator</label>
                            <input type="text" id="model-creator" name="creator" class="form-input" placeholder="Original creator of the model" autocomplete="name" value="<?= htmlspecialchars($_POST['creator'] ?? '') ?>">
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

                        <fieldset class="form-group">
                            <legend>Categories</legend>
                            <div class="checkbox-group">
                                <?php foreach ($categories as $category): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="categories[]" value="<?= $category['id'] ?>" <?= in_array($category['id'], $_POST['categories'] ?? []) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($category['name']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
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

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        function getFileIcon(ext) {
            var icons = {
                'stl': '\uD83D\uDDA8', '3mf': '\uD83D\uDCE6', 'obj': '\uD83D\uDCCB',
                'gcode': '\u2699', 'zip': '\uD83D\uDCC1', 'step': '\uD83D\uDD27',
                'stp': '\uD83D\uDD27', 'iges': '\uD83D\uDD27', 'igs': '\uD83D\uDD27',
                'blend': '\uD83C\uDFA8', 'fbx': '\uD83C\uDFAC', 'glb': '\uD83C\uDF10',
                'gltf': '\uD83C\uDF10'
            };
            return icons[ext] || '\uD83D\uDCC4';
        }

        // File selection handlers
        function handleFileSelect(file) {
            var ext = file.name.split('.').pop().toLowerCase();
            display.innerHTML = '';
            var preview = document.createElement('div');
            preview.className = 'file-preview-info';
            preview.innerHTML =
                '<span class="file-preview-icon" aria-hidden="true">' + getFileIcon(ext) + '</span>' +
                '<div class="file-preview-details">' +
                    '<div class="file-preview-name"></div>' +
                    '<div class="file-preview-meta">' + ext.toUpperCase() + ' \u2022 ' + formatFileSize(file.size) + '</div>' +
                '</div>';
            preview.querySelector('.file-preview-name').textContent = file.name;
            display.appendChild(preview);

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

        // Max file size from PHP (for client-side validation)
        const MAX_FILE_SIZE = <?= defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : (100 * 1024 * 1024) ?>;

        // Tus chunked resumable upload
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!fileInput.files.length) {
                showToast('Please select a file', 'error');
                return;
            }

            const file = fileInput.files[0];
            const name = document.getElementById('model-name').value.trim();

            if (!name) {
                showToast('Please enter a model name', 'error');
                return;
            }

            // Client-side size check
            if (file.size > MAX_FILE_SIZE) {
                showToast('File exceeds maximum upload size (' + formatFileSize(MAX_FILE_SIZE) + ')', 'error');
                return;
            }

            // Collect form metadata
            const categories = [];
            document.querySelectorAll('input[name="categories[]"]:checked').forEach(function(cb) {
                categories.push(cb.value);
            });

            // Show progress
            progressContainer.style.display = 'block';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            var lastModelId = null;

            var upload = new tus.Upload(file, {
                endpoint: '/actions/tus',
                chunkSize: 5 * 1024 * 1024, // 5MB chunks
                retryDelays: [0, 1000, 3000, 5000],
                metadata: {
                    filename: file.name,
                    filetype: file.type || file.name.split('.').pop(),
                    name: name,
                    description: document.getElementById('model-description').value || '',
                    creator: document.getElementById('model-creator') ? document.getElementById('model-creator').value : '',
                    collection: document.getElementById('model-collection') ? document.getElementById('model-collection').value : '',
                    source_url: document.getElementById('model-source') ? document.getElementById('model-source').value : '',
                    categories: JSON.stringify(categories)
                },
                onProgress: function(bytesUploaded, bytesTotal) {
                    var percent = Math.round((bytesUploaded / bytesTotal) * 100);
                    progressFill.style.width = percent + '%';
                    progressFill.parentElement.setAttribute('aria-valuenow', percent);
                    progressText.textContent = 'Uploading... ' + percent + '% (' + formatFileSize(bytesUploaded) + ' / ' + formatFileSize(bytesTotal) + ')';
                },
                onAfterResponse: function(req, res) {
                    // Capture the model_id from the final PATCH response body
                    var body = res.getBody();
                    if (body) {
                        try {
                            var data = JSON.parse(body);
                            if (data.model_id) {
                                lastModelId = data.model_id;
                            }
                        } catch (e) { /* not JSON — normal for non-final chunks */ }
                    }
                },
                onSuccess: function() {
                    progressText.textContent = 'Processing...';
                    if (lastModelId) {
                        window.location.href = '/model/' + lastModelId;
                    } else {
                        window.location.href = '/?uploaded=1';
                    }
                },
                onError: function(error) {
                    showToast('Upload failed: ' + error.message, 'error');
                    progressContainer.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Upload Model';
                }
            });

            // Check for previous uploads to resume
            upload.findPreviousUploads().then(function(previousUploads) {
                if (previousUploads.length > 0) {
                    upload.resumeFromPreviousUpload(previousUploads[0]);
                }
                upload.start();
            });
        });

        // Markdown preview
        (function() {
            var ta = document.getElementById('model-description');
            var preview = document.getElementById('md-preview');
            var timer = null;
            function updatePreview() {
                var text = ta.value;
                if (!text.trim()) { preview.innerHTML = '<em style="color:var(--color-text-muted)">Nothing to preview</em>'; return; }
                var html = text
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/^### (.+)$/gm, '<h4>$1</h4>')
                    .replace(/^## (.+)$/gm, '<h3>$1</h3>')
                    .replace(/^# (.+)$/gm, '<h2>$1</h2>')
                    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.+?)\*/g, '<em>$1</em>')
                    .replace(/`(.+?)`/g, '<code>$1</code>')
                    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>')
                    .replace(/^- (.+)$/gm, '<li>$1</li>')
                    .replace(/\n/g, '<br>');
                preview.innerHTML = html;
            }
            ta.addEventListener('input', function() { clearTimeout(timer); timer = setTimeout(updatePreview, 200); });
            document.querySelector('.markdown-preview-toggle').addEventListener('toggle', updatePreview);
        })();

        // Auto-open advanced options on desktop, keep collapsed on mobile
        const advancedOptions = document.getElementById('advanced-options');
        if (window.innerWidth >= 768) {
            advancedOptions.setAttribute('open', '');
        }

        </script>

<?php require_once 'includes/footer.php'; ?>
