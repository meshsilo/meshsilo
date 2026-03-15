<?php
$pageTitle = 'Security Headers';
$adminPage = 'security-headers';

require_once __DIR__ . '/../../includes/permissions.php';

// Check permission
if (!canManageSecurity()) {
    $_SESSION['error'] = 'You do not have permission to manage security settings.';
    header('Location: ' . route('admin.health'));
    exit;
}

require_once __DIR__ . '/../../includes/SecurityHeaders.php';

$success = '';
$error = '';

// Handle form submission
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $config = [
            'hsts' => [
                'enabled' => isset($_POST['hsts_enabled']),
                'max_age' => (int)($_POST['hsts_max_age'] ?? 31536000),
                'include_subdomains' => isset($_POST['hsts_include_subdomains']),
                'preload' => isset($_POST['hsts_preload']),
            ],
            'csp' => [
                'enabled' => isset($_POST['csp_enabled']),
                'report_only' => isset($_POST['csp_report_only']),
                'directives' => [
                    'default-src' => array_filter(explode(' ', trim($_POST['csp_default_src'] ?? "'self'"))),
                    'script-src' => array_filter(explode(' ', trim($_POST['csp_script_src'] ?? ''))),
                    'style-src' => array_filter(explode(' ', trim($_POST['csp_style_src'] ?? ''))),
                    'img-src' => array_filter(explode(' ', trim($_POST['csp_img_src'] ?? ''))),
                    'font-src' => array_filter(explode(' ', trim($_POST['csp_font_src'] ?? ''))),
                    'connect-src' => array_filter(explode(' ', trim($_POST['csp_connect_src'] ?? ''))),
                    'frame-src' => array_filter(explode(' ', trim($_POST['csp_frame_src'] ?? ''))),
                    'object-src' => array_filter(explode(' ', trim($_POST['csp_object_src'] ?? ''))),
                    'base-uri' => array_filter(explode(' ', trim($_POST['csp_base_uri'] ?? ''))),
                    'form-action' => array_filter(explode(' ', trim($_POST['csp_form_action'] ?? ''))),
                    'frame-ancestors' => array_filter(explode(' ', trim($_POST['csp_frame_ancestors'] ?? ''))),
                ],
                'report_uri' => $_POST['csp_report_uri'] ?? '',
            ],
            'x_frame_options' => [
                'enabled' => isset($_POST['xfo_enabled']),
                'value' => $_POST['xfo_value'] ?? 'SAMEORIGIN',
            ],
            'x_content_type_options' => [
                'enabled' => isset($_POST['xcto_enabled']),
            ],
            'x_xss_protection' => [
                'enabled' => isset($_POST['xxss_enabled']),
                'mode' => $_POST['xxss_mode'] ?? 'block',
            ],
            'referrer_policy' => [
                'enabled' => isset($_POST['referrer_enabled']),
                'value' => $_POST['referrer_value'] ?? 'strict-origin-when-cross-origin',
            ],
            'permissions_policy' => [
                'enabled' => isset($_POST['permissions_enabled']),
                'directives' => [
                    'camera' => [],
                    'microphone' => [],
                    'geolocation' => [],
                    'payment' => [],
                    'usb' => [],
                ],
            ],
            'cross_origin_embedder_policy' => [
                'enabled' => isset($_POST['coep_enabled']),
                'value' => $_POST['coep_value'] ?? 'require-corp',
            ],
            'cross_origin_opener_policy' => [
                'enabled' => isset($_POST['coop_enabled']),
                'value' => $_POST['coop_value'] ?? 'same-origin',
            ],
            'cross_origin_resource_policy' => [
                'enabled' => isset($_POST['corp_enabled']),
                'value' => $_POST['corp_value'] ?? 'same-origin',
            ],
        ];

        SecurityHeaders::saveConfig($config);
        $success = 'Security headers configuration saved.';
    }
}

// Get current config and analysis
$config = SecurityHeaders::getConfig();
$analysis = SecurityHeaders::analyze();

include __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Security Headers</h1>
            <p>Configure HTTP security headers to protect against common attacks</p>
        </div>

        <?php if (!empty($success)): ?>
            <div role="status" class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Security Score Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Security Score</h2>
            </div>
            <div class="card-body">
                <div class="security-score-display">
                    <div class="score-circle grade-<?= strtolower($analysis['grade'][0]) ?>">
                        <span class="grade"><?= htmlspecialchars($analysis['grade']) ?></span>
                        <span class="score"><?= $analysis['score'] ?>/<?= $analysis['max_score'] ?></span>
                    </div>
                    <div class="score-details">
                        <?php if (empty($analysis['findings'])): ?>
                            <p class="text-success">All security headers are properly configured!</p>
                        <?php else: ?>
                            <h4>Recommendations</h4>
                            <ul class="findings-list">
                                <?php foreach ($analysis['findings'] as $finding): ?>
                                    <li class="finding finding-<?= $finding['severity'] ?>">
                                        <strong><?= htmlspecialchars($finding['header']) ?>:</strong>
                                        <?= htmlspecialchars($finding['message']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" class="security-headers-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <!-- HSTS -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2>Strict-Transport-Security (HSTS)</h2>
                    <p class="text-muted">Forces browsers to use HTTPS for all connections</p>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="hsts_enabled" <?= $config['hsts']['enabled'] ? 'checked' : '' ?>>
                            Enable HSTS
                        </label>
                        <p class="help-text">Warning: Only enable if your site is fully accessible via HTTPS</p>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hsts_max_age">Max Age (seconds)</label>
                            <select name="hsts_max_age" id="hsts_max_age" class="form-control">
                                <option value="86400" <?= $config['hsts']['max_age'] == 86400 ? 'selected' : '' ?>>1 day (testing)</option>
                                <option value="604800" <?= $config['hsts']['max_age'] == 604800 ? 'selected' : '' ?>>1 week</option>
                                <option value="2592000" <?= $config['hsts']['max_age'] == 2592000 ? 'selected' : '' ?>>30 days</option>
                                <option value="15768000" <?= $config['hsts']['max_age'] == 15768000 ? 'selected' : '' ?>>6 months</option>
                                <option value="31536000" <?= $config['hsts']['max_age'] == 31536000 ? 'selected' : '' ?>>1 year (recommended)</option>
                                <option value="63072000" <?= $config['hsts']['max_age'] == 63072000 ? 'selected' : '' ?>>2 years</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="hsts_include_subdomains" <?= $config['hsts']['include_subdomains'] ? 'checked' : '' ?>>
                            Include Subdomains
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="hsts_preload" <?= $config['hsts']['preload'] ? 'checked' : '' ?>>
                            Enable Preload
                        </label>
                        <p class="help-text">Submit to <a href="https://hstspreload.org" target="_blank" rel="noopener">hstspreload.org</a> after enabling</p>
                    </div>
                </div>
            </div>

            <!-- CSP -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2>Content Security Policy (CSP)</h2>
                    <p class="text-muted">Controls which resources the browser can load</p>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="csp_enabled" <?= $config['csp']['enabled'] ? 'checked' : '' ?>>
                            Enable CSP
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="csp_report_only" <?= $config['csp']['report_only'] ? 'checked' : '' ?>>
                            Report Only Mode (recommended for testing)
                        </label>
                        <p class="help-text">Violations are reported but not blocked</p>
                    </div>

                    <h4 class="mt-4">Directives</h4>
                    <p class="help-text">Space-separated values. Common: 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:</p>

                    <div class="form-group">
                        <label for="csp_default_src">default-src</label>
                        <input type="text" name="csp_default_src" id="csp_default_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['default-src'] ?? [])) ?>"
                               placeholder="'self'">
                    </div>
                    <div class="form-group">
                        <label for="csp_script_src">script-src</label>
                        <input type="text" name="csp_script_src" id="csp_script_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['script-src'] ?? [])) ?>"
                               placeholder="'self' 'unsafe-inline'">
                    </div>
                    <div class="form-group">
                        <label for="csp_style_src">style-src</label>
                        <input type="text" name="csp_style_src" id="csp_style_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['style-src'] ?? [])) ?>"
                               placeholder="'self' 'unsafe-inline'">
                    </div>
                    <div class="form-group">
                        <label for="csp_img_src">img-src</label>
                        <input type="text" name="csp_img_src" id="csp_img_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['img-src'] ?? [])) ?>"
                               placeholder="'self' data: blob: https:">
                    </div>
                    <div class="form-group">
                        <label for="csp_font_src">font-src</label>
                        <input type="text" name="csp_font_src" id="csp_font_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['font-src'] ?? [])) ?>"
                               placeholder="'self' https://fonts.gstatic.com">
                    </div>
                    <div class="form-group">
                        <label for="csp_connect_src">connect-src</label>
                        <input type="text" name="csp_connect_src" id="csp_connect_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['connect-src'] ?? [])) ?>"
                               placeholder="'self'">
                    </div>
                    <div class="form-group">
                        <label for="csp_frame_src">frame-src</label>
                        <input type="text" name="csp_frame_src" id="csp_frame_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['frame-src'] ?? [])) ?>"
                               placeholder="'self'">
                    </div>
                    <div class="form-group">
                        <label for="csp_object_src">object-src</label>
                        <input type="text" name="csp_object_src" id="csp_object_src" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['object-src'] ?? [])) ?>"
                               placeholder="'none'">
                    </div>
                    <div class="form-group">
                        <label for="csp_frame_ancestors">frame-ancestors</label>
                        <input type="text" name="csp_frame_ancestors" id="csp_frame_ancestors" class="form-control"
                               value="<?= htmlspecialchars(implode(' ', $config['csp']['directives']['frame-ancestors'] ?? [])) ?>"
                               placeholder="'self'">
                    </div>
                    <div class="form-group">
                        <label for="csp_report_uri">Report URI (optional)</label>
                        <input type="url" name="csp_report_uri" id="csp_report_uri" class="form-control"
                               value="<?= htmlspecialchars($config['csp']['report_uri'] ?? '') ?>"
                               placeholder="https://your-report-collector.com/csp">
                    </div>
                </div>
            </div>

            <!-- Other Headers -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2>Other Security Headers</h2>
                </div>
                <div class="card-body">
                    <!-- X-Frame-Options -->
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="xfo_enabled" <?= $config['x_frame_options']['enabled'] ? 'checked' : '' ?>>
                            X-Frame-Options (Clickjacking protection)
                        </label>
                        <select name="xfo_value" class="form-control mt-2" style="max-width: 200px;" aria-label="X-Frame-Options value">
                            <option value="DENY" <?= $config['x_frame_options']['value'] === 'DENY' ? 'selected' : '' ?>>DENY</option>
                            <option value="SAMEORIGIN" <?= $config['x_frame_options']['value'] === 'SAMEORIGIN' ? 'selected' : '' ?>>SAMEORIGIN</option>
                        </select>
                    </div>

                    <!-- X-Content-Type-Options -->
                    <div class="form-group mt-4">
                        <label class="checkbox-label">
                            <input type="checkbox" name="xcto_enabled" <?= $config['x_content_type_options']['enabled'] ? 'checked' : '' ?>>
                            X-Content-Type-Options: nosniff (Prevent MIME sniffing)
                        </label>
                    </div>

                    <!-- X-XSS-Protection -->
                    <div class="form-group mt-4">
                        <label class="checkbox-label">
                            <input type="checkbox" name="xxss_enabled" <?= $config['x_xss_protection']['enabled'] ? 'checked' : '' ?>>
                            X-XSS-Protection (Legacy XSS filter)
                        </label>
                        <select name="xxss_mode" class="form-control mt-2" style="max-width: 200px;" aria-label="X-XSS-Protection mode">
                            <option value="0" <?= $config['x_xss_protection']['mode'] === '0' ? 'selected' : '' ?>>Disabled (0)</option>
                            <option value="1" <?= $config['x_xss_protection']['mode'] === '1' ? 'selected' : '' ?>>Enabled (1)</option>
                            <option value="block" <?= $config['x_xss_protection']['mode'] === 'block' ? 'selected' : '' ?>>Block mode</option>
                        </select>
                    </div>

                    <!-- Referrer-Policy -->
                    <div class="form-group mt-4">
                        <label class="checkbox-label">
                            <input type="checkbox" name="referrer_enabled" <?= $config['referrer_policy']['enabled'] ? 'checked' : '' ?>>
                            Referrer-Policy
                        </label>
                        <select name="referrer_value" class="form-control mt-2" style="max-width: 300px;" aria-label="Referrer-Policy value">
                            <option value="no-referrer" <?= $config['referrer_policy']['value'] === 'no-referrer' ? 'selected' : '' ?>>no-referrer</option>
                            <option value="no-referrer-when-downgrade" <?= $config['referrer_policy']['value'] === 'no-referrer-when-downgrade' ? 'selected' : '' ?>>no-referrer-when-downgrade</option>
                            <option value="origin" <?= $config['referrer_policy']['value'] === 'origin' ? 'selected' : '' ?>>origin</option>
                            <option value="origin-when-cross-origin" <?= $config['referrer_policy']['value'] === 'origin-when-cross-origin' ? 'selected' : '' ?>>origin-when-cross-origin</option>
                            <option value="same-origin" <?= $config['referrer_policy']['value'] === 'same-origin' ? 'selected' : '' ?>>same-origin</option>
                            <option value="strict-origin" <?= $config['referrer_policy']['value'] === 'strict-origin' ? 'selected' : '' ?>>strict-origin</option>
                            <option value="strict-origin-when-cross-origin" <?= $config['referrer_policy']['value'] === 'strict-origin-when-cross-origin' ? 'selected' : '' ?>>strict-origin-when-cross-origin</option>
                            <option value="unsafe-url" <?= $config['referrer_policy']['value'] === 'unsafe-url' ? 'selected' : '' ?>>unsafe-url</option>
                        </select>
                    </div>

                    <!-- Permissions-Policy -->
                    <div class="form-group mt-4">
                        <label class="checkbox-label">
                            <input type="checkbox" name="permissions_enabled" <?= $config['permissions_policy']['enabled'] ? 'checked' : '' ?>>
                            Permissions-Policy (Restrict browser features)
                        </label>
                        <p class="help-text">Disables camera, microphone, geolocation, payment, and USB APIs by default</p>
                    </div>

                    <!-- Cross-Origin Policies -->
                    <h4 class="mt-4">Cross-Origin Isolation</h4>
                    <p class="help-text">These headers provide additional isolation between origins. May break some functionality.</p>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="coep_enabled" <?= $config['cross_origin_embedder_policy']['enabled'] ? 'checked' : '' ?>>
                            Cross-Origin-Embedder-Policy
                        </label>
                        <select name="coep_value" class="form-control mt-2" style="max-width: 200px;" aria-label="Cross-Origin-Embedder-Policy value">
                            <option value="unsafe-none" <?= $config['cross_origin_embedder_policy']['value'] === 'unsafe-none' ? 'selected' : '' ?>>unsafe-none</option>
                            <option value="require-corp" <?= $config['cross_origin_embedder_policy']['value'] === 'require-corp' ? 'selected' : '' ?>>require-corp</option>
                            <option value="credentialless" <?= $config['cross_origin_embedder_policy']['value'] === 'credentialless' ? 'selected' : '' ?>>credentialless</option>
                        </select>
                    </div>

                    <div class="form-group mt-3">
                        <label class="checkbox-label">
                            <input type="checkbox" name="coop_enabled" <?= $config['cross_origin_opener_policy']['enabled'] ? 'checked' : '' ?>>
                            Cross-Origin-Opener-Policy
                        </label>
                        <select name="coop_value" class="form-control mt-2" style="max-width: 200px;" aria-label="Cross-Origin-Opener-Policy value">
                            <option value="unsafe-none" <?= $config['cross_origin_opener_policy']['value'] === 'unsafe-none' ? 'selected' : '' ?>>unsafe-none</option>
                            <option value="same-origin-allow-popups" <?= $config['cross_origin_opener_policy']['value'] === 'same-origin-allow-popups' ? 'selected' : '' ?>>same-origin-allow-popups</option>
                            <option value="same-origin" <?= $config['cross_origin_opener_policy']['value'] === 'same-origin' ? 'selected' : '' ?>>same-origin</option>
                        </select>
                    </div>

                    <div class="form-group mt-3">
                        <label class="checkbox-label">
                            <input type="checkbox" name="corp_enabled" <?= $config['cross_origin_resource_policy']['enabled'] ? 'checked' : '' ?>>
                            Cross-Origin-Resource-Policy
                        </label>
                        <select name="corp_value" class="form-control mt-2" style="max-width: 200px;" aria-label="Cross-Origin-Resource-Policy value">
                            <option value="same-site" <?= $config['cross_origin_resource_policy']['value'] === 'same-site' ? 'selected' : '' ?>>same-site</option>
                            <option value="same-origin" <?= $config['cross_origin_resource_policy']['value'] === 'same-origin' ? 'selected' : '' ?>>same-origin</option>
                            <option value="cross-origin" <?= $config['cross_origin_resource_policy']['value'] === 'cross-origin' ? 'selected' : '' ?>>cross-origin</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Configuration</button>
            </div>
        </form>

        <!-- Server Config Export -->
        <div class="card mt-4">
            <div class="card-header">
                <h2>Server Configuration</h2>
                <p class="text-muted">For better performance, configure headers at the server level</p>
            </div>
            <div class="card-body">
                <div class="tabs">
                    <button type="button" class="tab-btn active" data-tab="apache">Apache (.htaccess)</button>
                    <button type="button" class="tab-btn" data-tab="nginx">Nginx</button>
                </div>
                <div class="tab-content" id="tab-apache">
                    <pre class="code-block"><?= htmlspecialchars(SecurityHeaders::generateApacheConfig()) ?></pre>
                    <button type="button" class="btn btn-sm" onclick="copyToClipboard(this, 'tab-apache')">Copy</button>
                </div>
                <div class="tab-content" id="tab-nginx" style="display: none;">
                    <pre class="code-block"><?= htmlspecialchars(SecurityHeaders::generateNginxConfig()) ?></pre>
                    <button type="button" class="btn btn-sm" onclick="copyToClipboard(this, 'tab-nginx')">Copy</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.security-score-display {
    display: flex;
    gap: 2rem;
    align-items: flex-start;
}

.score-circle {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.score-circle.grade-a { background: linear-gradient(135deg, #10b981, #059669); color: white; }
.score-circle.grade-b { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; }
.score-circle.grade-c { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
.score-circle.grade-d { background: linear-gradient(135deg, #f97316, #ea580c); color: white; }
.score-circle.grade-f { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }

.score-circle .grade {
    font-size: 2rem;
    font-weight: bold;
}

.score-circle .score {
    font-size: 0.9rem;
    opacity: 0.9;
}

.score-details {
    flex: 1;
}

.findings-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.finding {
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    border-radius: 4px;
    border-left: 4px solid;
}

.finding-high {
    background: rgba(239, 68, 68, 0.1);
    border-color: var(--color-danger);
}

.finding-medium {
    background: rgba(245, 158, 11, 0.1);
    border-color: var(--color-warning);
}

.finding-low {
    background: rgba(59, 130, 246, 0.1);
    border-color: var(--color-primary);
}

.tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.tab-btn {
    padding: 0.5rem 1rem;
    border: 1px solid var(--color-border);
    background: var(--color-bg);
    cursor: pointer;
    border-radius: 4px;
}

.tab-btn.active {
    background: var(--color-primary);
    color: white;
    border-color: var(--color-primary);
}

.code-block {
    background: var(--color-surface-hover);
    padding: 1rem;
    border-radius: var(--radius);
    overflow-x: auto;
    font-family: monospace;
    font-size: 0.85rem;
    white-space: pre-wrap;
    word-break: break-all;
}

.form-row {
    display: flex;
    gap: 1rem;
}

.form-row .form-group {
    flex: 1;
}
</style>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');

        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).style.display = 'block';
    });
});

function copyToClipboard(btn, tabId) {
    const code = document.querySelector('#' + tabId + ' .code-block').textContent;
    navigator.clipboard.writeText(code).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
