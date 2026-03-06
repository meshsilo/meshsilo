<?php

/**
 * Model Dimensions Parser
 * Extracts bounding box dimensions from STL and 3MF files
 */

/**
 * Parse dimensions from a model file
 * @param string $filePath Path to the model file
 * @param string $fileType File type (stl or 3mf)
 * @return array|null Array with dim_x, dim_y, dim_z, dim_unit or null on failure
 */
function parseModelDimensions($filePath, $fileType)
{
    if (!file_exists($filePath)) {
        return null;
    }

    $fileType = strtolower($fileType);

    if ($fileType === 'stl') {
        return parseSTLDimensions($filePath);
    } elseif ($fileType === '3mf') {
        return parse3MFDimensions($filePath);
    }

    return null;
}

/**
 * Parse dimensions from a binary or ASCII STL file
 */
function parseSTLDimensions($filePath)
{
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return null;
    }

    // Read first 80 bytes (header)
    $header = fread($handle, 80);

    // Check if ASCII STL (starts with "solid")
    if (strpos($header, 'solid') === 0) {
        fclose($handle);
        return parseASCIISTL($filePath);
    }

    // Binary STL
    // Read number of triangles (4 bytes after header)
    $triangleCountData = fread($handle, 4);
    if (strlen($triangleCountData) < 4) {
        fclose($handle);
        return null;
    }

    $triangleCount = unpack('V', $triangleCountData)[1];

    if ($triangleCount === 0 || $triangleCount > 10000000) { // Sanity check
        fclose($handle);
        return null;
    }

    $minX = $minY = $minZ = PHP_FLOAT_MAX;
    $maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

    // Each triangle: 12 floats (normal + 3 vertices) + 2 bytes attribute
    // = 50 bytes per triangle
    for ($i = 0; $i < $triangleCount; $i++) {
        $triangleData = fread($handle, 50);
        if (strlen($triangleData) < 50) {
            break;
        }

        // Skip normal (3 floats = 12 bytes), read 3 vertices
        $vertices = unpack('f12', $triangleData);

        // Vertices start at index 4 (1-indexed in unpack)
        for ($v = 4; $v <= 12; $v += 3) {
            $x = $vertices[$v];
            $y = $vertices[$v + 1];
            $z = $vertices[$v + 2];

            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
            $minZ = min($minZ, $z);
            $maxZ = max($maxZ, $z);
        }
    }

    fclose($handle);

    if ($minX === PHP_FLOAT_MAX) {
        return null;
    }

    return [
        'dim_x' => round($maxX - $minX, 2),
        'dim_y' => round($maxY - $minY, 2),
        'dim_z' => round($maxZ - $minZ, 2),
        'dim_unit' => 'mm' // STL files are typically in mm
    ];
}

/**
 * Parse dimensions from an ASCII STL file
 */
function parseASCIISTL($filePath)
{
    $content = file_get_contents($filePath);
    if (!$content) {
        return null;
    }

    $minX = $minY = $minZ = PHP_FLOAT_MAX;
    $maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

    // Match all vertex lines
    preg_match_all('/vertex\s+([+-]?\d*\.?\d+(?:[eE][+-]?\d+)?)\s+([+-]?\d*\.?\d+(?:[eE][+-]?\d+)?)\s+([+-]?\d*\.?\d+(?:[eE][+-]?\d+)?)/i', $content, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        return null;
    }

    foreach ($matches as $match) {
        $x = (float)$match[1];
        $y = (float)$match[2];
        $z = (float)$match[3];

        $minX = min($minX, $x);
        $maxX = max($maxX, $x);
        $minY = min($minY, $y);
        $maxY = max($maxY, $y);
        $minZ = min($minZ, $z);
        $maxZ = max($maxZ, $z);
    }

    if ($minX === PHP_FLOAT_MAX) {
        return null;
    }

    return [
        'dim_x' => round($maxX - $minX, 2),
        'dim_y' => round($maxY - $minY, 2),
        'dim_z' => round($maxZ - $minZ, 2),
        'dim_unit' => 'mm'
    ];
}

/**
 * Parse dimensions from a 3MF file
 * 3MF files are ZIP archives containing XML model data
 */
function parse3MFDimensions($filePath)
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return null;
    }

    // Find the 3D model file (usually 3D/3dmodel.model)
    $modelContent = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('/\.model$/i', $name)) {
            $modelContent = $zip->getFromIndex($i);
            break;
        }
    }

    $zip->close();

    if (!$modelContent) {
        return null;
    }

    // Parse XML to extract vertices
    $minX = $minY = $minZ = PHP_FLOAT_MAX;
    $maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

    // Match all vertex definitions in the 3MF XML
    preg_match_all('/x="([+-]?\d*\.?\d+)"\s+y="([+-]?\d*\.?\d+)"\s+z="([+-]?\d*\.?\d+)"/i', $modelContent, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        return null;
    }

    foreach ($matches as $match) {
        $x = (float)$match[1];
        $y = (float)$match[2];
        $z = (float)$match[3];

        $minX = min($minX, $x);
        $maxX = max($maxX, $x);
        $minY = min($minY, $y);
        $maxY = max($maxY, $y);
        $minZ = min($minZ, $z);
        $maxZ = max($maxZ, $z);
    }

    if ($minX === PHP_FLOAT_MAX) {
        return null;
    }

    // 3MF uses millimeters by default
    return [
        'dim_x' => round($maxX - $minX, 2),
        'dim_y' => round($maxY - $minY, 2),
        'dim_z' => round($maxZ - $minZ, 2),
        'dim_unit' => 'mm'
    ];
}

/**
 * Format dimensions for display
 */
function formatDimensions($dimX, $dimY, $dimZ, $unit = 'mm')
{
    if ($dimX === null || $dimY === null || $dimZ === null) {
        return null;
    }
    return sprintf('%.1f × %.1f × %.1f %s', $dimX, $dimY, $dimZ, $unit);
}

/**
 * Parse and store dimensions for a model
 */
function calculateAndStoreDimensions($modelId)
{
    require_once __DIR__ . '/dedup.php';

    $db = getDB();
    $stmt = $db->prepare('SELECT file_path, file_type, dedup_path FROM models WHERE id = :id');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $model = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) {
        return false;
    }

    $filePath = getAbsoluteFilePath($model);
    if (!$filePath || !file_exists($filePath)) {
        return false;
    }

    $dimensions = parseModelDimensions($filePath, $model['file_type']);
    if (!$dimensions) {
        return false;
    }

    return updateModelDimensions($modelId, $dimensions['dim_x'], $dimensions['dim_y'], $dimensions['dim_z'], $dimensions['dim_unit']);
}
