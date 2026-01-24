<?php
/**
 * Custom CSS/Branding Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();

if (!$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        getBranding();
        break;
    case 'save':
        saveBranding();
        break;
    case 'upload_logo':
        uploadLogo();
        break;
    case 'upload_favicon':
        uploadFavicon();
        break;
    case 'delete_logo':
        deleteLogo();
        break;
    case 'delete_favicon':
        deleteFavicon();
        break;
    case 'preview_css':
        previewCSS();
        break;
    case 'reset':
        resetBranding();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function getBranding() {
    $branding = [
        'site_name' => getSetting('site_name', 'MeshSilo'),
        'site_tagline' => getSetting('site_tagline', '3D Model Library'),
        'logo_path' => getSetting('logo_path', ''),
        'favicon_path' => getSetting('favicon_path', ''),
        'primary_color' => getSetting('brand_primary_color', '#6366f1'),
        'secondary_color' => getSetting('brand_secondary_color', '#8b5cf6'),
        'accent_color' => getSetting('brand_accent_color', '#06b6d4'),
        'background_color' => getSetting('brand_background_color', '#f9fafb'),
        'text_color' => getSetting('brand_text_color', '#111827'),
        'custom_css' => getSetting('custom_css', ''),
        'custom_head_html' => getSetting('custom_head_html', ''),
        'custom_footer_html' => getSetting('custom_footer_html', ''),
        'font_family' => getSetting('brand_font_family', 'Inter, system-ui, sans-serif'),
        'border_radius' => getSetting('brand_border_radius', '0.5rem'),
        'dark_mode_enabled' => getSetting('dark_mode_enabled', '0'),
        'dark_background_color' => getSetting('brand_dark_background', '#1f2937'),
        'dark_text_color' => getSetting('brand_dark_text', '#f9fafb')
    ];

    echo json_encode(['success' => true, 'branding' => $branding]);
}

function saveBranding() {
    $fields = [
        'site_name' => 'site_name',
        'site_tagline' => 'site_tagline',
        'primary_color' => 'brand_primary_color',
        'secondary_color' => 'brand_secondary_color',
        'accent_color' => 'brand_accent_color',
        'background_color' => 'brand_background_color',
        'text_color' => 'brand_text_color',
        'custom_css' => 'custom_css',
        'custom_head_html' => 'custom_head_html',
        'custom_footer_html' => 'custom_footer_html',
        'font_family' => 'brand_font_family',
        'border_radius' => 'brand_border_radius',
        'dark_mode_enabled' => 'dark_mode_enabled',
        'dark_background_color' => 'brand_dark_background',
        'dark_text_color' => 'brand_dark_text'
    ];

    foreach ($fields as $postKey => $settingKey) {
        if (isset($_POST[$postKey])) {
            $value = $_POST[$postKey];

            // Validate colors
            if (strpos($postKey, 'color') !== false) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    continue; // Skip invalid colors
                }
            }

            // Sanitize CSS to prevent XSS
            if ($postKey === 'custom_css') {
                $value = sanitizeCSS($value);
            }

            setSetting($settingKey, $value);
        }
    }

    // Generate compiled CSS file
    generateCompiledCSS();

    echo json_encode(['success' => true]);
}

function uploadLogo() {
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $file = $_FILES['logo'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowedTypes = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    if (!in_array($extension, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: png, jpg, svg, webp']);
        return;
    }

    // Max 2MB
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large. Max 2MB']);
        return;
    }

    $uploadDir = UPLOAD_PATH . 'branding/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Delete old logo
    $oldLogo = getSetting('logo_path', '');
    if ($oldLogo && file_exists(UPLOAD_PATH . $oldLogo)) {
        unlink(UPLOAD_PATH . $oldLogo);
    }

    $filename = 'logo_' . time() . '.' . $extension;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
        return;
    }

    $relativePath = 'branding/' . $filename;
    setSetting('logo_path', $relativePath);

    echo json_encode([
        'success' => true,
        'path' => $relativePath,
        'url' => '/assets/' . $relativePath
    ]);
}

function uploadFavicon() {
    if (empty($_FILES['favicon']) || $_FILES['favicon']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $file = $_FILES['favicon'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowedTypes = ['ico', 'png', 'svg'];
    if (!in_array($extension, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ico, png, svg']);
        return;
    }

    // Max 500KB
    if ($file['size'] > 500 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large. Max 500KB']);
        return;
    }

    $uploadDir = UPLOAD_PATH . 'branding/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Delete old favicon
    $oldFavicon = getSetting('favicon_path', '');
    if ($oldFavicon && file_exists(UPLOAD_PATH . $oldFavicon)) {
        unlink(UPLOAD_PATH . $oldFavicon);
    }

    $filename = 'favicon_' . time() . '.' . $extension;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
        return;
    }

    $relativePath = 'branding/' . $filename;
    setSetting('favicon_path', $relativePath);

    echo json_encode([
        'success' => true,
        'path' => $relativePath,
        'url' => '/assets/' . $relativePath
    ]);
}

function deleteLogo() {
    $logoPath = getSetting('logo_path', '');
    if ($logoPath && file_exists(UPLOAD_PATH . $logoPath)) {
        unlink(UPLOAD_PATH . $logoPath);
    }
    setSetting('logo_path', '');
    echo json_encode(['success' => true]);
}

function deleteFavicon() {
    $faviconPath = getSetting('favicon_path', '');
    if ($faviconPath && file_exists(UPLOAD_PATH . $faviconPath)) {
        unlink(UPLOAD_PATH . $faviconPath);
    }
    setSetting('favicon_path', '');
    echo json_encode(['success' => true]);
}

function previewCSS() {
    $css = $_POST['css'] ?? '';
    $css = sanitizeCSS($css);

    // Try to parse CSS to check for errors
    $errors = [];
    if (!empty($css)) {
        // Basic validation - check for unbalanced braces
        $openBraces = substr_count($css, '{');
        $closeBraces = substr_count($css, '}');
        if ($openBraces !== $closeBraces) {
            $errors[] = 'Unbalanced braces in CSS';
        }
    }

    echo json_encode([
        'success' => empty($errors),
        'sanitized_css' => $css,
        'errors' => $errors
    ]);
}

function resetBranding() {
    $defaults = [
        'brand_primary_color' => '#6366f1',
        'brand_secondary_color' => '#8b5cf6',
        'brand_accent_color' => '#06b6d4',
        'brand_background_color' => '#f9fafb',
        'brand_text_color' => '#111827',
        'custom_css' => '',
        'custom_head_html' => '',
        'custom_footer_html' => '',
        'brand_font_family' => 'Inter, system-ui, sans-serif',
        'brand_border_radius' => '0.5rem',
        'brand_dark_background' => '#1f2937',
        'brand_dark_text' => '#f9fafb'
    ];

    foreach ($defaults as $key => $value) {
        setSetting($key, $value);
    }

    // Regenerate compiled CSS
    generateCompiledCSS();

    echo json_encode(['success' => true]);
}

/**
 * Sanitize CSS to prevent XSS
 */
function sanitizeCSS($css) {
    // Remove any HTML tags
    $css = strip_tags($css);

    // Remove javascript: URLs
    $css = preg_replace('/javascript\s*:/i', '', $css);

    // Remove expression() (IE)
    $css = preg_replace('/expression\s*\(/i', '', $css);

    // Remove behavior: (IE)
    $css = preg_replace('/behavior\s*:/i', '', $css);

    // Remove -moz-binding (Firefox)
    $css = preg_replace('/-moz-binding\s*:/i', '', $css);

    // Remove @import (to prevent external resource loading)
    $css = preg_replace('/@import/i', '', $css);

    return trim($css);
}

/**
 * Generate compiled CSS file with custom branding
 */
function generateCompiledCSS() {
    $css = ":root {\n";
    $css .= "  --color-primary: " . getSetting('brand_primary_color', '#6366f1') . ";\n";
    $css .= "  --color-secondary: " . getSetting('brand_secondary_color', '#8b5cf6') . ";\n";
    $css .= "  --color-accent: " . getSetting('brand_accent_color', '#06b6d4') . ";\n";
    $css .= "  --color-background: " . getSetting('brand_background_color', '#f9fafb') . ";\n";
    $css .= "  --color-text: " . getSetting('brand_text_color', '#111827') . ";\n";
    $css .= "  --font-family: " . getSetting('brand_font_family', 'Inter, system-ui, sans-serif') . ";\n";
    $css .= "  --border-radius: " . getSetting('brand_border_radius', '0.5rem') . ";\n";
    $css .= "}\n\n";

    // Dark mode
    if (getSetting('dark_mode_enabled', '0') === '1') {
        $css .= "@media (prefers-color-scheme: dark) {\n";
        $css .= "  :root {\n";
        $css .= "    --color-background: " . getSetting('brand_dark_background', '#1f2937') . ";\n";
        $css .= "    --color-text: " . getSetting('brand_dark_text', '#f9fafb') . ";\n";
        $css .= "  }\n";
        $css .= "}\n\n";
    }

    // Custom CSS
    $customCSS = getSetting('custom_css', '');
    if ($customCSS) {
        $css .= "/* Custom CSS */\n" . $customCSS . "\n";
    }

    // Write to file
    $cssDir = UPLOAD_PATH . 'branding/';
    if (!is_dir($cssDir)) {
        mkdir($cssDir, 0755, true);
    }

    file_put_contents($cssDir . 'custom.css', $css);
}
