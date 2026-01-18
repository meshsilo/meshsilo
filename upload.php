<?php
require_once 'includes/config.php';
$pageTitle = 'Upload Model';
$activePage = 'upload';
require_once 'includes/header.php';

// Category data - will be loaded from database later
$categories = [
    ['slug' => 'functional', 'name' => 'Functional'],
    ['slug' => 'decorative', 'name' => 'Decorative'],
    ['slug' => 'tools', 'name' => 'Tools'],
    ['slug' => 'gaming', 'name' => 'Gaming'],
    ['slug' => 'art', 'name' => 'Art'],
    ['slug' => 'mechanical', 'name' => 'Mechanical'],
];
?>

        <div class="page-container">
            <div class="page-header">
                <h1>Upload Model</h1>
                <p>Share your 3D print files with the community</p>
            </div>

            <form class="upload-form" action="#" method="post" enctype="multipart/form-data">
                <div class="upload-dropzone">
                    <div class="dropzone-content">
                        <span class="dropzone-icon">&#8679;</span>
                        <p class="dropzone-text">Drag and drop your file here</p>
                        <p class="dropzone-subtext">or</p>
                        <label class="btn btn-primary file-select-btn">
                            Browse Files
                            <input type="file" name="model_file" accept=".stl,.3mf" hidden>
                        </label>
                        <p class="dropzone-hint">Supported formats: .stl, .3mf</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="model-name">Model Name</label>
                    <input type="text" id="model-name" name="name" class="form-input" placeholder="Enter model name" required>
                </div>

                <div class="form-group">
                    <label for="model-description">Description</label>
                    <textarea id="model-description" name="description" class="form-input form-textarea" placeholder="Describe your model..." rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label for="model-author">Author</label>
                    <input type="text" id="model-author" name="author" class="form-input" placeholder="Original creator of the model">
                </div>

                <div class="form-group">
                    <label for="model-collection">Collection</label>
                    <input type="text" id="model-collection" name="collection" class="form-input" placeholder="Collection name (e.g., Gridfinity, Voron)">
                </div>

                <div class="form-group">
                    <label for="model-source">Source Link</label>
                    <input type="url" id="model-source" name="source_url" class="form-input" placeholder="https://thingiverse.com/...">
                </div>

                <div class="form-group">
                    <label>Categories</label>
                    <div class="checkbox-group">
                        <?php foreach ($categories as $category): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($category['slug']) ?>">
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

<?php require_once 'includes/footer.php'; ?>
