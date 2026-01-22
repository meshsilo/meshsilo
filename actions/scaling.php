<?php
/**
 * Scaling Preview Actions
 * Show dimensions at different scales and check printer fit
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'preview':
        getScalingPreview();
        break;
    case 'check_fit':
        checkFitAtScale();
        break;
    case 'common_scales':
        getCommonScales();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Get dimensions at various scales
 */
function getScalingPreview() {
    $modelId = (int)($_GET['model_id'] ?? 0);
    $scales = $_GET['scales'] ?? [25, 50, 75, 100, 125, 150, 200];

    if (is_string($scales)) {
        $scales = json_decode($scales, true) ?: [25, 50, 75, 100, 125, 150, 200];
    }

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT dim_x, dim_y, dim_z, dim_unit FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model || !$model['dim_x']) {
        echo json_encode(['success' => false, 'error' => 'Model dimensions not available']);
        return;
    }

    $baseX = (float)$model['dim_x'];
    $baseY = (float)$model['dim_y'];
    $baseZ = (float)$model['dim_z'];
    $unit = $model['dim_unit'] ?? 'mm';

    // Convert to mm if in inches
    if ($unit === 'in') {
        $baseX *= 25.4;
        $baseY *= 25.4;
        $baseZ *= 25.4;
    }

    $results = [];
    foreach ($scales as $scale) {
        $scale = (float)$scale;
        $factor = $scale / 100;

        $results[] = [
            'scale' => $scale,
            'dimensions' => [
                'x' => round($baseX * $factor, 2),
                'y' => round($baseY * $factor, 2),
                'z' => round($baseZ * $factor, 2)
            ],
            'volume_cm3' => round(($baseX * $factor * $baseY * $factor * $baseZ * $factor) / 1000, 2)
        ];
    }

    // Get user's printers for fit check
    global $user;
    $stmt = $db->prepare('SELECT id, name, bed_x, bed_y, bed_z FROM printers WHERE user_id = :user_id OR user_id IS NULL ORDER BY is_default DESC');
    $stmt->execute([':user_id' => $user['id']]);
    $printers = [];
    while ($row = $stmt->fetch()) {
        $printers[] = $row;
    }

    // Check fit for each scale against each printer
    foreach ($results as &$result) {
        $result['printer_fit'] = [];
        foreach ($printers as $printer) {
            if (!$printer['bed_x']) continue;

            $fits = $result['dimensions']['x'] <= $printer['bed_x'] &&
                    $result['dimensions']['y'] <= $printer['bed_y'] &&
                    $result['dimensions']['z'] <= $printer['bed_z'];

            $result['printer_fit'][] = [
                'printer_id' => $printer['id'],
                'printer_name' => $printer['name'],
                'fits' => $fits
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'base_dimensions' => [
            'x' => $baseX,
            'y' => $baseY,
            'z' => $baseZ,
            'unit' => 'mm'
        ],
        'scales' => $results,
        'printers' => $printers
    ]);
}

/**
 * Check if model fits at a specific scale
 */
function checkFitAtScale() {
    $modelId = (int)($_GET['model_id'] ?? 0);
    $scale = (float)($_GET['scale'] ?? 100);
    $printerId = (int)($_GET['printer_id'] ?? 0);

    if (!$modelId) {
        echo json_encode(['success' => false, 'error' => 'Model ID required']);
        return;
    }

    $db = getDB();

    // Get model dimensions
    $stmt = $db->prepare('SELECT dim_x, dim_y, dim_z, dim_unit FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model || !$model['dim_x']) {
        echo json_encode(['success' => false, 'error' => 'Model dimensions not available']);
        return;
    }

    // Get printer
    if ($printerId) {
        $stmt = $db->prepare('SELECT * FROM printers WHERE id = :id');
        $stmt->execute([':id' => $printerId]);
    } else {
        global $user;
        $stmt = $db->prepare('SELECT * FROM printers WHERE user_id = :user_id AND is_default = 1');
        $stmt->execute([':user_id' => $user['id']]);
    }
    $printer = $stmt->fetch();

    if (!$printer || !$printer['bed_x']) {
        echo json_encode(['success' => false, 'error' => 'Printer not found or bed dimensions not set']);
        return;
    }

    // Calculate scaled dimensions
    $factor = $scale / 100;
    $baseX = (float)$model['dim_x'];
    $baseY = (float)$model['dim_y'];
    $baseZ = (float)$model['dim_z'];

    // Convert to mm if needed
    if ($model['dim_unit'] === 'in') {
        $baseX *= 25.4;
        $baseY *= 25.4;
        $baseZ *= 25.4;
    }

    $scaledX = $baseX * $factor;
    $scaledY = $baseY * $factor;
    $scaledZ = $baseZ * $factor;

    $fitsX = $scaledX <= $printer['bed_x'];
    $fitsY = $scaledY <= $printer['bed_y'];
    $fitsZ = $scaledZ <= $printer['bed_z'];
    $fitsAll = $fitsX && $fitsY && $fitsZ;

    // Calculate maximum scale that fits
    $maxScaleX = ($printer['bed_x'] / $baseX) * 100;
    $maxScaleY = ($printer['bed_y'] / $baseY) * 100;
    $maxScaleZ = ($printer['bed_z'] / $baseZ) * 100;
    $maxScale = min($maxScaleX, $maxScaleY, $maxScaleZ);

    echo json_encode([
        'success' => true,
        'scale' => $scale,
        'fits' => $fitsAll,
        'scaled_dimensions' => [
            'x' => round($scaledX, 2),
            'y' => round($scaledY, 2),
            'z' => round($scaledZ, 2)
        ],
        'printer' => [
            'name' => $printer['name'],
            'bed_x' => (float)$printer['bed_x'],
            'bed_y' => (float)$printer['bed_y'],
            'bed_z' => (float)$printer['bed_z']
        ],
        'fit_details' => [
            'x' => $fitsX,
            'y' => $fitsY,
            'z' => $fitsZ
        ],
        'margins' => [
            'x' => round($printer['bed_x'] - $scaledX, 2),
            'y' => round($printer['bed_y'] - $scaledY, 2),
            'z' => round($printer['bed_z'] - $scaledZ, 2)
        ],
        'max_scale' => round($maxScale, 1)
    ]);
}

/**
 * Get common scale presets
 */
function getCommonScales() {
    echo json_encode([
        'success' => true,
        'scales' => [
            ['value' => 25, 'label' => '25% - Miniature'],
            ['value' => 50, 'label' => '50% - Half Size'],
            ['value' => 75, 'label' => '75% - Reduced'],
            ['value' => 100, 'label' => '100% - Original'],
            ['value' => 125, 'label' => '125% - Slightly Larger'],
            ['value' => 150, 'label' => '150% - 1.5x'],
            ['value' => 200, 'label' => '200% - Double Size'],
            ['value' => 300, 'label' => '300% - Triple Size']
        ]
    ]);
}
