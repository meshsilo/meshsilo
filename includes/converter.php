<?php
/**
 * STL to 3MF Converter
 * Converts STL files to 3MF format for better compression
 */

require_once __DIR__ . '/dedup.php';

class STLConverter {
    private $vertices = [];
    private $triangles = [];

    /**
     * Check if an STL file is binary or ASCII
     */
    public function isBinarySTL($filePath) {
        $handle = fopen($filePath, 'rb');
        if (!$handle) return false;

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
     * Parse a binary STL file
     */
    public function parseBinarySTL($filePath) {
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

        $vertexMap = [];
        $vertexIndex = 0;

        for ($i = 0; $i < $triangleCount; $i++) {
            // Skip normal vector (12 bytes)
            fseek($handle, 12, SEEK_CUR);

            $triIndices = [];

            // Read 3 vertices (each 12 bytes: 3 floats)
            for ($v = 0; $v < 3; $v++) {
                $vertexData = fread($handle, 12);
                $coords = unpack('f3', $vertexData);
                $vertex = [
                    round($coords[1], 6),
                    round($coords[2], 6),
                    round($coords[3], 6)
                ];

                // Create unique key for vertex deduplication
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
            fseek($handle, 2, SEEK_CUR);
        }

        fclose($handle);

        return [
            'vertices' => count($this->vertices),
            'triangles' => count($this->triangles)
        ];
    }

    /**
     * Parse an ASCII STL file
     */
    public function parseASCIISTL($filePath) {
        $this->vertices = [];
        $this->triangles = [];

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception('Cannot read STL file');
        }

        $vertexMap = [];
        $vertexIndex = 0;

        // Match all facets
        preg_match_all('/facet\s+normal\s+[\d.eE+-]+\s+[\d.eE+-]+\s+[\d.eE+-]+\s+outer\s+loop\s+(vertex\s+([\d.eE+-]+)\s+([\d.eE+-]+)\s+([\d.eE+-]+)\s*){3}\s*endloop\s+endfacet/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            // Extract vertices from this facet
            preg_match_all('/vertex\s+([\d.eE+-]+)\s+([\d.eE+-]+)\s+([\d.eE+-]+)/i', $match[0], $vertexMatches, PREG_SET_ORDER);

            $triIndices = [];

            foreach ($vertexMatches as $vm) {
                $vertex = [
                    round((float)$vm[1], 6),
                    round((float)$vm[2], 6),
                    round((float)$vm[3], 6)
                ];

                $key = implode(',', $vertex);

                if (!isset($vertexMap[$key])) {
                    $vertexMap[$key] = $vertexIndex;
                    $this->vertices[] = $vertex;
                    $vertexIndex++;
                }

                $triIndices[] = $vertexMap[$key];
            }

            if (count($triIndices) === 3) {
                $this->triangles[] = $triIndices;
            }
        }

        return [
            'vertices' => count($this->vertices),
            'triangles' => count($this->triangles)
        ];
    }

    /**
     * Parse an STL file (auto-detect format)
     */
    public function parseSTL($filePath) {
        if ($this->isBinarySTL($filePath)) {
            return $this->parseBinarySTL($filePath);
        } else {
            return $this->parseASCIISTL($filePath);
        }
    }

    /**
     * Generate 3MF XML content
     */
    private function generate3MFModel() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">' . "\n";
        $xml .= '  <resources>' . "\n";
        $xml .= '    <object id="1" type="model">' . "\n";
        $xml .= '      <mesh>' . "\n";

        // Vertices
        $xml .= '        <vertices>' . "\n";
        foreach ($this->vertices as $v) {
            $xml .= sprintf('          <vertex x="%.6f" y="%.6f" z="%.6f" />' . "\n", $v[0], $v[1], $v[2]);
        }
        $xml .= '        </vertices>' . "\n";

        // Triangles
        $xml .= '        <triangles>' . "\n";
        foreach ($this->triangles as $t) {
            $xml .= sprintf('          <triangle v1="%d" v2="%d" v3="%d" />' . "\n", $t[0], $t[1], $t[2]);
        }
        $xml .= '        </triangles>' . "\n";

        $xml .= '      </mesh>' . "\n";
        $xml .= '    </object>' . "\n";
        $xml .= '  </resources>' . "\n";
        $xml .= '  <build>' . "\n";
        $xml .= '    <item objectid="1" />' . "\n";
        $xml .= '  </build>' . "\n";
        $xml .= '</model>';

        return $xml;
    }

    /**
     * Generate Content_Types XML
     */
    private function generateContentTypes() {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n" .
               '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml" />' . "\n" .
               '  <Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml" />' . "\n" .
               '</Types>';
    }

    /**
     * Generate relationships XML
     */
    private function generateRels() {
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
    public function convertTo3MF($stlPath, $outputPath = null) {
        if (!file_exists($stlPath)) {
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
     * @param string $stlPath Path to STL file
     * @return array Estimated sizes and savings
     */
    public function estimateConversion($stlPath) {
        if (!file_exists($stlPath)) {
            throw new Exception('STL file not found');
        }

        $originalSize = filesize($stlPath);

        // Parse the STL to get vertex/triangle counts
        $parseResult = $this->parseSTL($stlPath);

        // Estimate 3MF size based on XML structure
        // Each vertex line is approximately 60 bytes
        // Each triangle line is approximately 50 bytes
        // Plus overhead for XML structure (~500 bytes)
        $xmlSize = 500 + (count($this->vertices) * 60) + (count($this->triangles) * 50);

        // ZIP compression typically achieves 60-80% compression on XML
        $estimatedSize = (int)($xmlSize * 0.35);

        $savings = $originalSize - $estimatedSize;
        $savingsPercent = ($originalSize > 0) ? round(($savings / $originalSize) * 100, 1) : 0;

        return [
            'original_size' => $originalSize,
            'estimated_size' => $estimatedSize,
            'estimated_savings' => $savings,
            'estimated_savings_percent' => $savingsPercent,
            'vertices' => $parseResult['vertices'],
            'triangles' => $parseResult['triangles'],
            'worth_converting' => $savings > 0
        ];
    }
}

/**
 * Helper function to convert a model part
 */
function convertPartTo3MF($partId) {
    $db = getDB();

    // Get part details
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $part = $result->fetchArray(SQLITE3_ASSOC);

    if (!$part) {
        return ['success' => false, 'error' => 'Part not found'];
    }

    if ($part['file_type'] !== 'stl') {
        return ['success' => false, 'error' => 'Only STL files can be converted'];
    }

    $stlPath = __DIR__ . '/../' . $part['file_path'];

    if (!file_exists($stlPath)) {
        return ['success' => false, 'error' => 'File not found on disk'];
    }

    try {
        $converter = new STLConverter();

        // Generate new filename
        $newFilename = preg_replace('/\.stl$/i', '.3mf', $part['filename']);
        $newFilePath = dirname($stlPath) . '/' . $newFilename;

        // Convert the file
        $result = $converter->convertTo3MF($stlPath, $newFilePath);

        if ($result['success'] && $result['savings'] > 0) {
            // Update database - use case-insensitive replacement for file_path
            $newDbFilePath = preg_replace('/\.stl$/i', '.3mf', $part['file_path']);

            // Calculate new file hash (content-based for 3MF)
            $newFileHash = calculateContentHash($newFilePath);

            $stmt = $db->prepare('
                UPDATE models
                SET filename = :filename,
                    file_path = :file_path,
                    file_type = :file_type,
                    file_size = :file_size,
                    file_hash = :file_hash,
                    original_size = :original_size
                WHERE id = :id
            ');
            $stmt->bindValue(':filename', $newFilename, SQLITE3_TEXT);
            $stmt->bindValue(':file_path', $newDbFilePath, SQLITE3_TEXT);
            $stmt->bindValue(':file_type', '3mf', SQLITE3_TEXT);
            $stmt->bindValue(':file_size', $result['new_size'], SQLITE3_INTEGER);
            $stmt->bindValue(':file_hash', $newFileHash, SQLITE3_TEXT);
            $stmt->bindValue(':original_size', $result['original_size'], SQLITE3_INTEGER);
            $stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
            $stmt->execute();

            // Delete original STL file
            unlink($stlPath);

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
            if (file_exists($newFilePath)) {
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
function estimatePartConversion($partId) {
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $part = $result->fetchArray(SQLITE3_ASSOC);

    if (!$part || $part['file_type'] !== 'stl') {
        return null;
    }

    $stlPath = __DIR__ . '/../' . $part['file_path'];

    if (!file_exists($stlPath)) {
        return null;
    }

    try {
        $converter = new STLConverter();
        return $converter->estimateConversion($stlPath);
    } catch (Exception $e) {
        return null;
    }
}
