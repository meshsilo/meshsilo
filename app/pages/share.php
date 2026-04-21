<?php
/**
 * Public Share Page
 * Allows access to shared models without login
 */

// Don't require authentication for this page
define('PUBLIC_PAGE', true);

require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/dedup.php';
require_once __DIR__ . '/../../includes/Csrf.php';

$token = $_GET['token'] ?? $_GET['t'] ?? '';
$error = '';
$model = null;
$link = null;
$requiresPassword = false;
$passwordError = false;
$rateLimited = false;

if (empty($token)) {
    $error = 'Invalid share link';
} else {
    $db = getDB();

    // Get share link
    $stmt = $db->prepare('
        SELECT sl.*, m.id as model_id, m.name, m.filename, m.file_path, m.file_type,
               m.file_size, m.description, m.creator, m.thumbnail_path, m.part_count,
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
                // Rate limit password attempts to prevent brute force
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $rateResult = RateLimiter::check($ip, 'anonymous', 'share_password');
                if (!$rateResult['allowed']) {
                    $passwordError = true;
                    $rateLimited = true;
                } elseif (!Csrf::check()) {
                    $passwordError = true;
                } elseif (password_verify($_POST['password'], $link['password_hash'])) {
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
$isMultiPart = $model && ($model['part_count'] ?? 0) > 0 && ($model['file_type'] === 'parent' || empty($model['file_path']));
if ($model && !$requiresPassword && isset($_GET['download'])) {
    if ($isMultiPart) {
        $error = 'This is a multi-part model. Individual file downloads are not available via share links.';
    } elseif (($filePath = getAbsoluteFilePath($model)) && file_exists($filePath)) {
        // Increment download count
        $stmt = $db->prepare('UPDATE share_links SET download_count = download_count + 1 WHERE token = :token');
        $stmt->execute([':token' => $token]);

        // Sanitize filename for Content-Disposition header
        $safeFilename = basename($model['filename']);
        $safeFilename = str_replace(["\r", "\n", "\t", '"', '\\'], '', $safeFilename);

        // Send file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . filesize($filePath));
        if (getenv('MESHSILO_DOCKER') === 'true' && defined('UPLOAD_PATH')) {
            $relativePath = str_replace(realpath(UPLOAD_PATH), '', realpath($filePath));
            header('X-Accel-Redirect: /internal-assets' . $relativePath);
        } else {
            readfile($filePath);
        }
        exit;
    } else {
        $error = 'File not found';
    }
}

// Determine display file type for multi-part models
$displayFileType = $model ? $model['file_type'] : '';
if ($model && $isMultiPart) {
    // Get distinct file types from parts
    $partTypesStmt = $db->prepare('SELECT DISTINCT file_type FROM models WHERE parent_id = :pid AND file_type IS NOT NULL AND file_type != ""');
    $partTypesStmt->execute([':pid' => $model['model_id']]);
    $partTypes = $partTypesStmt->fetchAll(PDO::FETCH_COLUMN);
    $displayFileType = !empty($partTypes) ? implode(', ', array_map('strtoupper', $partTypes)) : 'Multi-part';
} else {
    $displayFileType = strtoupper($displayFileType);
}

$siteName = getSetting('site_name', 'MeshSilo');
$pageTitle = $model ? htmlspecialchars($model['name']) . ' - Shared' : 'Shared Model';
$hasThumbnail = $model && !empty($model['thumbnail_path']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_COOKIE['meshsilo_theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= htmlspecialchars($siteName) ?></title>
<?php
// Build absolute base URL for OG tags
$_shareScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$_shareBase = $_shareScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_shareUrl = $_shareBase . ($_SERVER['REQUEST_URI'] ?? '/');
$_shareTitle = $model ? htmlspecialchars($model['name']) : 'Shared Model';
if ($model && !empty($model['description'])) {
    if (!class_exists('Markdown')) {
        require_once __DIR__ . '/../../includes/Markdown.php';
    }
    $_shareDesc = mb_substr(trim(strip_tags(Markdown::render($model['description']))), 0, 160);
}
if (empty($_shareDesc)) {
    $_shareDesc = 'Shared 3D model on ' . $siteName;
}
$_shareImage = $hasThumbnail ? $_shareBase . '/assets/' . $model['thumbnail_path'] : null;
?>
    <meta name="description" content="<?= htmlspecialchars($_shareDesc) ?>">
    <!-- Open Graph -->
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= htmlspecialchars($_shareTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($_shareDesc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($_shareUrl) ?>">
<?php if ($_shareImage): ?>
    <meta property="og:image" content="<?= htmlspecialchars($_shareImage) ?>">
<?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card" content="<?= $_shareImage ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($_shareTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_shareDesc) ?>">
<?php if ($_shareImage): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($_shareImage) ?>">
<?php endif; ?>
    <link rel="stylesheet" href="/css/base.css?v=10">
    <link rel="stylesheet" href="/css/layout.css?v=10">
    <link rel="stylesheet" href="/css/components.css?v=10">
    <link rel="stylesheet" href="/css/pages.css?v=10">
    <style>
        .share-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .share-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 2rem;
        }
        .share-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .share-header h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.5rem;
        }
        .share-header .share-creator {
            color: var(--color-text-muted);
            font-size: 0.9rem;
        }
        .share-preview {
            background: var(--color-surface-hover);
            border-radius: 8px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .share-preview img {
            width: 100%;
            max-height: 400px;
            object-fit: contain;
        }
        .share-preview .share-preview-placeholder {
            padding: 3rem;
            color: var(--color-text-muted);
            text-align: center;
        }
        .share-preview .share-preview-placeholder svg {
            display: block;
            margin: 0 auto 0.75rem;
            opacity: 0.4;
        }
        .share-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        .detail-item {
            background: var(--color-surface-hover);
            padding: 0.75rem 1rem;
            border-radius: 8px;
        }
        .detail-label {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        .detail-value {
            font-weight: 600;
            font-size: 0.95rem;
        }
        .share-description {
            background: var(--color-surface-hover);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.6;
            color: var(--color-text);
        }
        .share-description .detail-label {
            margin-bottom: 0.5rem;
        }
        .share-actions {
            text-align: center;
            padding-top: 0.5rem;
        }
        .share-actions .btn {
            padding: 0.75rem 2.5rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
        }
        .share-actions .btn svg {
            vertical-align: -2px;
            margin-right: 0.5rem;
        }
        .share-multipart-note {
            color: var(--color-text-muted);
            font-size: 0.9rem;
        }
        .password-form {
            max-width: 320px;
            margin: 0 auto;
        }
        .error-page {
            text-align: center;
            padding: 2rem 0;
        }
        .error-page svg {
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .error-page h2 {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
        }
        .error-page p {
            color: var(--color-text-muted);
            margin: 0;
        }
        .downloads-remaining {
            font-size: 0.85rem;
            color: var(--color-text-muted);
            margin-top: 0.75rem;
        }
        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-wrapper input {
            flex: 1;
            padding-right: 2.5rem;
        }
        .password-toggle {
            position: absolute;
            right: 0.5rem;
            background: none;
            border: none;
            color: var(--color-text-muted);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.25rem;
        }
        .share-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 0.75rem;
            font-size: 0.8rem;
            color: var(--color-text-muted);
        }
        .share-footer a {
            color: var(--color-primary);
            text-decoration: none;
        }
        .share-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .share-card { padding: 1.25rem; }
            .share-details { grid-template-columns: 1fr 1fr; }
            .share-actions .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="share-container">
        <div class="share-card">
            <?php if ($error): ?>
                <div class="error-page">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <h2>Share Link Unavailable</h2>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php elseif ($requiresPassword): ?>
                <div class="share-header">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.75rem; opacity: 0.6;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <h1>Password Protected</h1>
                    <p class="share-creator">Enter the password to view this shared model.</p>
                </div>
                <form method="post" class="password-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <?php if ($rateLimited): ?>
                        <div role="alert" class="alert alert-error" style="margin-bottom: 1rem;">Too many attempts. Please try again later.</div>
                    <?php elseif ($passwordError): ?>
                        <div role="alert" class="alert alert-error" style="margin-bottom: 1rem;">Incorrect password</div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required autofocus autocomplete="current-password">
                            <button type="button" class="password-toggle" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">Access Model</button>
                </form>
            <?php elseif ($model): ?>
                <div class="share-header">
                    <h1><?= htmlspecialchars($model['name']) ?></h1>
                    <?php if ($model['creator']): ?>
                        <p class="share-creator">by <?= htmlspecialchars($model['creator']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="share-preview">
                    <?php if ($hasThumbnail): ?>
                        <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="share-preview-placeholder">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                            </svg>
                            <span>No preview available</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="share-details">
                    <div class="detail-item">
                        <div class="detail-label">File Type</div>
                        <div class="detail-value"><?= htmlspecialchars($displayFileType) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">File Size</div>
                        <div class="detail-value"><?= formatFileSize($model['file_size']) ?></div>
                    </div>
                    <?php if ($isMultiPart): ?>
                    <div class="detail-item">
                        <div class="detail-label">Parts</div>
                        <div class="detail-value"><?= (int)$model['part_count'] ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($model['dim_x']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Dimensions</div>
                        <div class="detail-value">
                            <?= number_format($model['dim_x'], 1) ?> x
                            <?= number_format($model['dim_y'], 1) ?> x
                            <?= number_format($model['dim_z'], 1) ?>
                            <?= htmlspecialchars($model['dim_unit'] ?? 'mm') ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($model['description']): ?>
                <div class="share-description">
                    <div class="detail-label">Description</div>
                    <?= nl2br(htmlspecialchars($model['description'])) ?>
                </div>
                <?php endif; ?>

                <div class="share-actions">
                    <?php if (!$isMultiPart): ?>
                    <a href="?download=1" class="btn btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download <?= htmlspecialchars($displayFileType) ?>
                    </a>
                    <?php else: ?>
                    <p class="share-multipart-note">This is a multi-part model with <?= (int)$model['part_count'] ?> parts. Downloads are not available via share links.</p>
                    <?php endif; ?>
                    <?php if ($link['max_downloads']): ?>
                    <div class="downloads-remaining">
                        <?= $link['max_downloads'] - $link['download_count'] ?> downloads remaining
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="share-footer">
            Shared via <a href="<?= htmlspecialchars(getSetting('site_url', '/')) ?>"><?= htmlspecialchars($siteName) ?></a>
        </div>
    </div>

    <script>
    (function() {
        var eyeOpen = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var eyeClosed = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.password-toggle');
            if (!btn) return;
            var input = btn.parentElement.querySelector('input');
            if (!input) return;
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.innerHTML = isPassword ? eyeClosed : eyeOpen;
            btn.title = isPassword ? 'Hide password' : 'Show password';
        });
    })();
    </script>

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
