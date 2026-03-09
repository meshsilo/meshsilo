<?php

/**
 * STL to 3MF Converter
 * Converts STL files to 3MF format for better compression
 */

require_once __DIR__ . '/dedup.php';

class STLConverter
{
    private $vertices = [];
    private $triangles = [];

    /**
     * Check if an STL file is binary or ASCII
     */
    public function isBinarySTL($filePath)
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        // Read first 80 bytes (header) + 4 bytes (triangle count)
        $header = fread($handle, 80);
        $triangleCountData = fread($handle, 4);

        if (strlen($triangleCountData) < 4) {
            fclose($handle);
            return false;
        }

        $triangleCount = unpack('V', $triangleCountData)[1];

        // Binary STL: 80 byte header + 4 byte count + (50 bytes * triangles)
        $expectedSize = 84 + ($triangleCount * 50);
        $actualSize = filesize($filePath);

        fclose($handle);

        // If file size matches expected binary size (within tolerance), it's binary
        return abs($actualSize - $expectedSize) < 100;
    }

    /**
     * Maximum triangle count we'll attempt to convert.
     * ~2M triangles requires ~400MB for vertex map + arrays + XML generation.
     */
    private const MAX_TRIANGLES = 2_000_000;

    /**
     * Parse a binary STL file
     */
    public function parseBinarySTL($filePath)
    {
        $this->vertices = [];
        $this->triangles = [];

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new Exception('Cannot open STL file');
        }

        // Skip 80-byte header
        fseek($handle, 80);

        // Read triangle count
        $triangleCountData = fread($handle, 4);
        $triangleCount = unpack('V', $triangleCountData)[1];

        if ($triangleCount > self::MAX_TRIANGLES) {
            fclose($handle);
            throw new Exception(sprintf(
                'STL file too large for conversion (%s triangles, max %s)',
                number_format($triangleCount),
                number_format(self::MAX_TRIANGLES)
            ));
        }

        // Read all triangle data at once (50 bytes per triangle)
        $dataSize = $triangleCount * 50;
        $data = fread($handle, $dataSize);
        fclose($handle);

        if (strlen($data) < $dataSize) {
            throw new Exception('Incomplete STL file');
        }

        $vertexMap = [];
        $vertexIndex = 0;
        $offset = 0;

        for ($i = 0; $i < $triangleCount; $i++) {
            // Skip normal vector (12 bytes)
            $offset += 12;

            $triIndices = [];

            // Read 3 vertices (each 12 bytes: 3 floats)
            for ($v = 0; $v < 3; $v++) {
                $coords = unpack('f3', $data, $offset);
                $offset += 12;

                $vertex = [
                    round($coords[1], 6),
                    round($coords[2], 6),
                    round($coords[3], 6)
                ];

                $key = implode(',', $vertex);

                if (!isset($vertexMap[$key])) {
                    $vertexMap[$key] = $vertexIndex;
                    $this->vertices[] = $vertex;
                    $vertexIndex++;
                }

                $triIndices[] = $vertexMap[$key];
            }

            $this->triangles[] = $triIndices;

            // Skip attribute byte count (2 bytes)
            $offset += 2;
        }

        return [
            'vertices' => count($this->vertices),
            'triangles' => count($this->triangles)
        ];
    }

    /**
     * Parse an ASCII STL file
     * Uses line-by-line reading to avoid loading entire file into memory
     */
    public function parseASCIISTL($filePath)
    {
        $this->vertices = [];
        $this->triangles = [];

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('Cannot read STL file');
        }

        $vertexMap = [];
        $vertexIndex = 0;
        $triIndices = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if (preg_match('/^vertex\s+([\d.eE+-]+)\s+([\d.eE+-]+)\s+([\d.eE+-]+)/i', $line, $m)) {
                $vertex = [
                    round((float)$m[1], 6),
                    round((float)$m[2], 6),
                    round((float)$m[3], 6)
                ];

                $key = implode(',', $vertex);

                if (!isset($vertexMap[$key])) {
                    $vertexMap[$key] = $vertexIndex;
                    $this->vertices[] = $vertex;
                    $vertexIndex++;
                }

                $triIndices[] = $vertexMap[$key];
            } elseif (stripos($line, 'endloop') === 0) {
                if (count($triIndices) === 3) {
                    $this->triangles[] = $triIndices;
                    if (count($this->triangles) > self::MAX_TRIANGLES) {
                        fclose($handle);
                        throw new Exception(sprintf(
                            'STL file too large for conversion (exceeded %s triangles)',
                            number_format(self::MAX_TRIANGLES)
                        ));
                    }
                }
                $triIndices = [];
            }
        }

        fclose($handle);

        return [
            'vertices' => count($this->vertices),
            'triangles' => count($this->triangles)
        ];
    }

    /**
     * Parse an STL file (auto-detect format)
     */
    public function parseSTL($filePath)
    {
        if ($this->isBinarySTL($filePath)) {
            return $this->parseBinarySTL($filePath);
        } else {
            return $this->parseASCIISTL($filePath);
        }
    }

    /**
     * Generate 3MF XML content
     */
    private function generate3MFModel()
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">';
        $lines[] = '  <resources>';
        $lines[] = '    <object id="1" type="model">';
        $lines[] = '      <mesh>';

        // Vertices
        $lines[] = '        <vertices>';
        foreach ($this->vertices as $v) {
            $lines[] = sprintf('          <vertex x="%.6f" y="%.6f" z="%.6f" />', $v[0], $v[1], $v[2]);
        }
        $lines[] = '        </vertices>';

        // Triangles
        $lines[] = '        <triangles>';
        foreach ($this->triangles as $t) {
            $lines[] = sprintf('          <triangle v1="%d" v2="%d" v3="%d" />', $t[0], $t[1], $t[2]);
        }
        $lines[] = '        </triangles>';

        $lines[] = '      </mesh>';
        $lines[] = '    </object>';
        $lines[] = '  </resources>';
        $lines[] = '  <build>';
        $lines[] = '    <item objectid="1" />';
        $lines[] = '  </build>';
        $lines[] = '</model>';

        return implode("\n", $lines);
    }

    /**
     * Generate Content_Types XML
     */
    private function generateContentTypes()
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n" .
               '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml" />' . "\n" .
               '  <Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml" />' . "\n" .
               '</Types>';
    }

    /**
     * Generate relationships XML
     */
    private function generateRels()
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
               '  <Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" />' . "\n" .
               '</Relationships>';
    }

    /**
     * Convert STL to 3MF
     * @param string $stlPath Path to input STL file
     * @param string $outputPath Path for output 3MF file (optional)
     * @return array Result with success status and file info
     */
    public function convertTo3MF($stlPath, $outputPath = null)
    {
        if (!is_file($stlPath)) {
            throw new Exception('STL file not found: ' . $stlPath);
        }

        $originalSize = filesize($stlPath);

        // Parse the STL file
        $parseResult = $this->parseSTL($stlPath);

        if (empty($this->triangles)) {
            throw new Exception('No triangles found in STL file');
        }

        // Generate output path if not provided
        if ($outputPath === null) {
            $outputPath = preg_replace('/\.stl$/i', '.3mf', $stlPath);
        }

        // Create 3MF (ZIP) file
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Cannot create 3MF file');
        }

        // Add required files to the archive
        $zip->addFromString('[Content_Types].xml', $this->generateContentTypes());
        $zip->addFromString('_rels/.rels', $this->generateRels());
        $zip->addFromString('3D/3dmodel.model', $this->generate3MFModel());

        $zip->close();

        $newSize = filesize($outputPath);
        $savings = $originalSize - $newSize;
        $savingsPercent = ($originalSize > 0) ? round(($savings / $originalSize) * 100, 1) : 0;

        return [
            'success' => true,
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'savings' => $savings,
            'savings_percent' => $savingsPercent,
            'output_path' => $outputPath,
            'vertices' => $parseResult['vertices'],
            'triangles' => $parseResult['triangles']
        ];
    }

    /**
     * Estimate 3MF size without actually converting
     * For binary STL, reads only the header to get triangle count
     * @param string $stlPath Path to STL file
     * @return array Estimated sizes and savings
     */
    public function estimateConversion($stlPath)
    {
        if (!is_file($stlPath)) {
            throw new Exception('STL file not found');
        }

        $originalSize = filesize($stlPath);

        if ($this->isBinarySTL($stlPath)) {
            // Binary STL: triangle count is in bytes 80-84, no full parse needed
            $handle = fopen($stlPath, 'rb');
            fseek($handle, 80);
            $triangleCount = unpack('V', fread($handle, 4))[1];
            fclose($handle);

            // Binary STL has 3 vertices per triangle, but shared vertices reduce count
            // Estimate ~50% vertex sharing for typical models
            $estimatedVertices = (int)($triangleCount * 3 * 0.5);
        } else {
            // ASCII STL: must parse to count (but still cheaper than full conversion)
            $parseResult = $this->parseASCIISTL($stlPath);
            $triangleCount = $parseResult['triangles'];
            $estimatedVertices = $parseResult['vertices'];
        }

        // Estimate 3MF size based on XML structure
        // Each vertex line ~60 bytes, each triangle line ~50 bytes, ~500 bytes overhead
        $xmlSize = 500 + ($estimatedVertices * 60) + ($triangleCount * 50);

        // ZIP compression typically achieves 60-80% compression on XML
        $estimatedSize = (int)($xmlSize * 0.35);

        $savings = $originalSize - $estimatedSize;
        $savingsPercent = ($originalSize > 0) ? round(($savings / $originalSize) * 100, 1) : 0;

        return [
            'original_size' => $originalSize,
            'estimated_size' => $estimatedSize,
            'estimated_savings' => $savings,
            'estimated_savings_percent' => $savingsPercent,
            'vertices' => $estimatedVertices,
            'triangles' => $triangleCount,
            'worth_converting' => $savings > 0
        ];
    }
}

/**
 * Helper function to convert a model part
 */
function convertPartTo3MF($partId)
{
    $db = getDB();

    // Get part details
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $part = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$part) {
        return ['success' => false, 'error' => 'Part not found'];
    }

    if ($part['file_type'] !== 'stl') {
        return ['success' => false, 'error' => 'Only STL files can be converted'];
    }

    $stlPath = getAbsoluteFilePath($part);

    if (!$stlPath || !is_file($stlPath)) {
        return ['success' => false, 'error' => 'File not found on disk'];
    }

    try {
        $converter = new STLConverter();

        // Generate new filename - place output next to source file
        $newFilename = preg_replace('/\.stl$/i', '.3mf', $part['filename']);
        $newFilePath = dirname($stlPath) . '/' . $newFilename;

        // Convert the file
        $result = $converter->convertTo3MF($stlPath, $newFilePath);

        if ($result['success'] && $result['savings'] > 0) {
            // Compute new DB-relative file_path
            if (!empty($part['dedup_path'])) {
                // Source was dedup'd - new file sits next to dedup file
                $newDbFilePath = dirname($part['dedup_path']) . '/' . $newFilename;
            } else {
                $newDbFilePath = preg_replace('/\.stl$/i', '.3mf', $part['file_path']);
            }

            // Calculate new file hash (content-based for 3MF)
            $newFileHash = calculateContentHash($newFilePath);

            $stmt = $db->prepare('
                UPDATE models
                SET filename = :filename,
                    file_path = :file_path,
                    file_type = :file_type,
                    file_size = :file_size,
                    file_hash = :file_hash,
                    original_size = :original_size,
                    dedup_path = NULL
                WHERE id = :id
            ');
            $stmt->bindValue(':filename', $newFilename, PDO::PARAM_STR);
            $stmt->bindValue(':file_path', $newDbFilePath, PDO::PARAM_STR);
            $stmt->bindValue(':file_type', '3mf', PDO::PARAM_STR);
            $stmt->bindValue(':file_size', $result['new_size'], PDO::PARAM_INT);
            $stmt->bindValue(':file_hash', $newFileHash, PDO::PARAM_STR);
            $stmt->bindValue(':original_size', $result['original_size'], PDO::PARAM_INT);
            $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
            $stmt->execute();

            // Delete original STL file only if not shared via dedup
            if (!empty($part['dedup_path'])) {
                // Check if other models still reference this dedup file
                $refStmt = $db->prepare('SELECT COUNT(*) as cnt FROM models WHERE dedup_path = :dedup_path AND id != :id');
                $refStmt->bindValue(':dedup_path', $part['dedup_path'], PDO::PARAM_STR);
                $refStmt->bindValue(':id', $partId, PDO::PARAM_INT);
                $refResult = $refStmt->execute();
                $refCount = $refResult->fetchArray(PDO::FETCH_ASSOC)['cnt'];

                if ($refCount == 0) {
                    unlink($stlPath);
                }
            } else {
                unlink($stlPath);
            }

            logInfo('Part converted to 3MF', [
                'part_id' => $partId,
                'original_size' => $result['original_size'],
                'new_size' => $result['new_size'],
                'savings' => $result['savings']
            ]);

            return [
                'success' => true,
                'original_size' => $result['original_size'],
                'new_size' => $result['new_size'],
                'savings' => $result['savings'],
                'savings_percent' => $result['savings_percent']
            ];
        } else {
            // Remove the 3MF if it wasn't beneficial
            if (is_file($newFilePath)) {
                unlink($newFilePath);
            }
            return ['success' => false, 'error' => 'Conversion would not save space'];
        }
    } catch (Exception $e) {
        logException($e, ['action' => 'convert_to_3mf', 'part_id' => $partId]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Estimate conversion savings for a part
 */
function estimatePartConversion($partId)
{
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $part = $result->fetchArray(PDO::FETCH_ASSOC);

    if (!$part || $part['file_type'] !== 'stl') {
        return null;
    }

    $stlPath = getAbsoluteFilePath($part);

    if (!$stlPath || !is_file($stlPath)) {
        return null;
    }

    try {
        $converter = new STLConverter();
        return $converter->estimateConversion($stlPath);
    } catch (Exception $e) {
        return null;
    }
}
