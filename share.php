<?php
/**
 * Public Share Page
 * Allows access to shared models without login
 */

// Don't require authentication for this page
define('PUBLIC_PAGE', true);

require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/db.php';

$token = $_GET['t'] ?? '';
$error = '';
$model = null;
$link = null;
$requiresPassword = false;
$passwordError = false;

if (empty($token)) {
    $error = 'Invalid share link';
} else {
    $db = getDB();

    // Get share link
    $stmt = $db->prepare('
        SELECT sl.*, m.id as model_id, m.name, m.filename, m.file_path, m.file_type,
               m.file_size, m.description, m.creator, m.print_type,
               m.dim_x, m.dim_y, m.dim_z, m.dim_unit
        FROM share_links sl
        JOIN models m ON sl.model_id = m.id
        WHERE sl.token = :token AND sl.is_active = 1
    ');
    $stmt->execute([':token' => $token]);
    $result = $stmt->fetch();

    if (!$result) {
        $error = 'This share link is invalid or has been deactivated';
    } else {
        $link = $result;
        $model = $result;

        // Check expiration
        if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
            $error = 'This share link has expired';
            $model = null;
        }

        // Check download limit
        elseif ($link['max_downloads'] && $link['download_count'] >= $link['max_downloads']) {
            $error = 'This share link has reached its download limit';
            $model = null;
        }

        // Check password
        elseif ($link['password_hash']) {
            $requiresPassword = true;

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
                if (password_verify($_POST['password'], $link['password_hash'])) {
                    // Password correct - allow access
                    $_SESSION['share_auth_' . $token] = true;
                    $requiresPassword = false;
                } else {
                    $passwordError = true;
                }
            } elseif (isset($_SESSION['share_auth_' . $token])) {
                // Already authenticated
                $requiresPassword = false;
            }
        }
    }
}

// Handle download
if ($model && !$requiresPassword && isset($_GET['download'])) {
    $filePath = UPLOAD_PATH . $model['file_path'];

    if (file_exists($filePath)) {
        // Increment download count
        $stmt = $db->prepare('UPDATE share_links SET download_count = download_count + 1 WHERE token = :token');
        $stmt->execute([':token' => $token]);

        // Send file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $model['filename'] . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $error = 'File not found';
    }
}

$siteName = getSetting('site_name', 'Silo');
$pageTitle = $model ? htmlspecialchars($model['name']) . ' - Shared' : 'Shared Model';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_COOKIE['silo_theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .share-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .share-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 2rem;
        }
        .share-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .share-header h1 {
            margin: 0 0 0.5rem 0;
        }
        .share-preview {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .share-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .detail-item {
            background: var(--bg-tertiary);
            padding: 0.75rem;
            border-radius: 6px;
        }
        .detail-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        .detail-value {
            font-weight: 500;
        }
        .share-actions {
            text-align: center;
        }
        .password-form {
            max-width: 300px;
            margin: 0 auto;
        }
        .error-message {
            background: var(--danger);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }
        .downloads-remaining {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="share-container">
        <div class="share-card">
            <?php if ($error): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php elseif ($requiresPassword): ?>
                <div class="share-header">
                    <h1>Password Protected</h1>
                    <p>This shared model requires a password to access.</p>
                </div>
                <form method="post" class="password-form">
                    <?php if ($passwordError): ?>
                        <div class="alert alert-danger" style="margin-bottom: 1rem;">Incorrect password</div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Access Model</button>
                </form>
            <?php elseif ($model): ?>
                <div class="share-header">
                    <h1><?= htmlspecialchars($model['name']) ?></h1>
                    <?php if ($model['creator']): ?>
                        <p>by <?= htmlspecialchars($model['creator']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="share-preview" id="preview-container">
                    <canvas id="model-preview"></canvas>
                </div>

                <div class="share-details">
                    <div class="detail-item">
                        <div class="detail-label">File Type</div>
                        <div class="detail-value"><?= strtoupper($model['file_type']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">File Size</div>
                        <div class="detail-value"><?= formatFileSize($model['file_size']) ?></div>
                    </div>
                    <?php if ($model['print_type']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Print Type</div>
                        <div class="detail-value"><?= strtoupper($model['print_type']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($model['dim_x']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Dimensions</div>
                        <div class="detail-value">
                            <?= number_format($model['dim_x'], 1) ?> x
                            <?= number_format($model['dim_y'], 1) ?> x
                            <?= number_format($model['dim_z'], 1) ?>
                            <?= $model['dim_unit'] ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($model['description']): ?>
                <div class="detail-item" style="margin-bottom: 1.5rem;">
                    <div class="detail-label">Description</div>
                    <div class="detail-value"><?= nl2br(htmlspecialchars($model['description'])) ?></div>
                </div>
                <?php endif; ?>

                <div class="share-actions">
                    <a href="?t=<?= htmlspecialchars($token) ?>&download=1" class="btn btn-primary btn-lg">
                        Download <?= strtoupper($model['file_type']) ?>
                    </a>
                    <?php if ($link['max_downloads']): ?>
                    <div class="downloads-remaining">
                        <?= $link['max_downloads'] - $link['download_count'] ?> downloads remaining
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 1rem; color: var(--text-muted);">
            Shared via <a href="<?= htmlspecialchars(getSetting('site_url', '/')) ?>"><?= htmlspecialchars($siteName) ?></a>
        </div>
    </div>

    <?php if ($model && in_array($model['file_type'], ['stl', '3mf'])): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="js/viewer.js"></script>
    <script>
        // Initialize 3D preview if applicable
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('preview-container');
            const canvas = document.getElementById('model-preview');

            if (typeof initModelViewer === 'function') {
                // Would need to expose file path for public preview
                // For security, you might want to generate a temporary signed URL
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>

<?php
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
?>
