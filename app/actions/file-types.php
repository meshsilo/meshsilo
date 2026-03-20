<?php
/**
 * Custom File Type Support Actions
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonError('Not logged in');
}

$user = getCurrentUser();

if (!$user['is_admin']) {
    jsonError('Admin access required');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    jsonError('Invalid CSRF token');
}

switch ($action) {
    case 'list':
        listFileTypes();
        break;
    case 'add':
        addFileType();
        break;
    case 'remove':
        removeFileType();
        break;
    case 'get_config':
        getFileTypeConfig();
        break;
    case 'save_config':
        saveFileTypeConfig();
        break;
    default:
        jsonError('Invalid action');
}

function listFileTypes() {
    $allowedExtensions = getAllowedExtensions();
    $config = getFileTypeConfig();

    $types = [];
    foreach ($allowedExtensions as $ext) {
        $types[] = [
            'extension' => $ext,
            'preview_handler' => $config[$ext]['preview_handler'] ?? 'none',
            'icon' => $config[$ext]['icon'] ?? 'file',
            'description' => $config[$ext]['description'] ?? ucfirst($ext) . ' file',
            'is_3d' => in_array($ext, ['stl', '3mf', 'obj', 'ply', 'gltf', 'glb', 'fbx', 'dae', 'blend', 'amf', '3ds', 'off', 'x3d'])
        ];
    }

    // Add built-in types info
    $builtIn = [
        'stl' => ['description' => 'STL (Stereolithography)', 'preview_handler' => 'three.js', 'icon' => '3d'],
        '3mf' => ['description' => '3D Manufacturing Format', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'gcode' => ['description' => 'G-Code Instructions', 'preview_handler' => 'gcode', 'icon' => 'code'],
        'obj' => ['description' => 'Wavefront OBJ', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'ply' => ['description' => 'Polygon File Format', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'amf' => ['description' => 'Additive Manufacturing Format', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'glb' => ['description' => 'Binary GL Transmission Format', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'gltf' => ['description' => 'GL Transmission Format', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'fbx' => ['description' => 'Autodesk FBX', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'dae' => ['description' => 'COLLADA Digital Asset Exchange', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'blend' => ['description' => 'Blender File', 'preview_handler' => 'none', 'icon' => '3d'],
        'step' => ['description' => 'STEP CAD File', 'preview_handler' => 'cad', 'icon' => 'cad'],
        'stp' => ['description' => 'STEP CAD File', 'preview_handler' => 'cad', 'icon' => 'cad'],
        'iges' => ['description' => 'IGES CAD File', 'preview_handler' => 'cad', 'icon' => 'cad'],
        'igs' => ['description' => 'IGES CAD File', 'preview_handler' => 'cad', 'icon' => 'cad'],
        '3ds' => ['description' => '3D Studio Max File', 'preview_handler' => 'three.js', 'icon' => '3d'],
        'dxf' => ['description' => 'AutoCAD Drawing Exchange Format', 'preview_handler' => 'none', 'icon' => 'cad'],
        'off' => ['description' => 'Object File Format', 'preview_handler' => 'none', 'icon' => '3d'],
        'x3d' => ['description' => 'Extensible 3D Graphics', 'preview_handler' => 'none', 'icon' => '3d'],
        'zip' => ['description' => 'ZIP Archive', 'preview_handler' => 'archive', 'icon' => 'archive']
    ];

    echo json_encode([
        'success' => true,
        'types' => $types,
        'built_in_info' => $builtIn,
        'preview_handlers' => ['none', 'three.js', 'gcode', 'cad', 'image', 'archive']
    ]);
}

function addFileType() {
    $extension = strtolower(trim($_POST['extension'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $previewHandler = $_POST['preview_handler'] ?? 'none';

    if (empty($extension)) {
        jsonError('Extension required');
        return;
    }

    // Remove leading dot if present
    $extension = ltrim($extension, '.');

    // Validate extension format
    if (!preg_match('/^[a-z0-9]+$/', $extension)) {
        jsonError('Invalid extension format');
        return;
    }

    // Get current allowed extensions
    $extensions = getAllowedExtensions();

    if (in_array($extension, $extensions)) {
        jsonError('Extension already allowed');
        return;
    }

    // Add new extension
    $extensions[] = $extension;
    setSetting('allowed_extensions', implode(',', $extensions));

    // Save config
    $config = json_decode(getSetting('file_type_config', '{}'), true) ?: [];
    $config[$extension] = [
        'description' => $description ?: ucfirst($extension) . ' file',
        'preview_handler' => $previewHandler,
        'icon' => 'file'
    ];
    setSetting('file_type_config', json_encode($config));

    jsonSuccess();
}

function removeFileType() {
    $extension = strtolower(trim($_POST['extension'] ?? ''));

    if (empty($extension)) {
        jsonError('Extension required');
        return;
    }

    // Prevent removing core built-in types (allow removing extended formats)
    $builtIn = ['stl', '3mf', 'zip'];
    if (in_array($extension, $builtIn)) {
        jsonError('Cannot remove core built-in file type');
        return;
    }

    // Remove from allowed extensions
    $extensions = array_filter(getAllowedExtensions(), fn($e) => $e !== $extension);
    setSetting('allowed_extensions', implode(',', $extensions));

    // Remove config
    $config = json_decode(getSetting('file_type_config', '{}'), true) ?: [];
    unset($config[$extension]);
    setSetting('file_type_config', json_encode($config));

    jsonSuccess();
}

function getFileTypeConfig() {
    $config = json_decode(getSetting('file_type_config', '{}'), true) ?: [];
    jsonSuccess(['config' => $config]);
}

function saveFileTypeConfig() {
    $extension = strtolower(trim($_POST['extension'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $previewHandler = $_POST['preview_handler'] ?? 'none';
    $icon = $_POST['icon'] ?? 'file';

    if (empty($extension)) {
        jsonError('Extension required');
        return;
    }

    $config = json_decode(getSetting('file_type_config', '{}'), true) ?: [];
    $config[$extension] = [
        'description' => $description,
        'preview_handler' => $previewHandler,
        'icon' => $icon
    ];
    setSetting('file_type_config', json_encode($config));

    jsonSuccess();
}
