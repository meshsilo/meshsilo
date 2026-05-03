<?php

/**
 * STL to 3MF Converter
 * Converts STL files to 3MF format for better compression.
 *
 * Uses disk-backed storage (SQLite temp DB for vertex deduplication,
 * binary temp file for triangle indices) to support arbitrarily large
 * STL files within bounded RAM.
 */

require_once __DIR__ . '/dedup.php';

class STLConverter
{
    private ?PDO $tempDb = null;
    private $triangleFile = null;
    private ?string $triangleTempPath = null;
    private ?string $tempDbPath = null;
    private int $vertexCount = 0;
    private int $triangleCount = 0;

    /** Cached prepared statements for vertex operations */
    private ?\PDOStatement $insertVertexStmt = null;
    private ?\PDOStatement $selectVertexStmt = null;

    /**
     * Check if an STL file is binary or ASCII
     */
    public function isBinarySTL($filePath)
    {
        $actualSize = filesize($filePath);

        // Too small to be a valid binary STL (84-byte header minimum)
        if ($actualSize < 84) {
            return false;
        }

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
        fclose($handle);

        // A triangle count of 0 is invalid for binary
        if ($triangleCount === 0) {
            return false;
        }

        // Binary STL: 80 byte header + 4 byte count + (50 bytes * triangles)
        $expectedSize = 84 + ($triangleCount * 50);

        // If file size matches expected binary size (within tolerance), it's binary
        return abs($actualSize - $expectedSize) < 100;
    }

    /**
     * Initialize temp storage: SQLite DB for vertices, binary file for triangles.
     */
    private function initTempStorage(): void
    {
        $cacheDir = __DIR__ . '/../storage/cache';
        $tempDir = is_writable($cacheDir) ? $cacheDir : sys_get_temp_dir();

        // SQLite temp DB on disk (not :memory:) so it doesn't eat RAM
        $baseTmp = tempnam($tempDir, 'silo_vtx_');
        @unlink($baseTmp); // Remove the placeholder file created by tempnam
        $this->tempDbPath = $baseTmp . '.db';
        $this->tempDb = new PDO('sqlite:' . $this->tempDbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->tempDb->exec('PRAGMA journal_mode=WAL');
        $this->tempDb->exec('PRAGMA synchronous=OFF');
        $this->tempDb->exec('PRAGMA temp_store=FILE');
        $this->tempDb->exec('PRAGMA cache_size=-50000'); // ~50MB page cache
        $this->tempDb->exec('
            CREATE TABLE vertices (
                id INTEGER PRIMARY KEY,
                key BLOB UNIQUE,
                x REAL,
                y REAL,
                z REAL
            )
        ');

        // Prepare cached statements — id is assigned manually (no AUTOINCREMENT)
        $this->insertVertexStmt = $this->tempDb->prepare(
            'INSERT OR IGNORE INTO vertices (id, key, x, y, z) VALUES (?, ?, ?, ?, ?)'
        );
        $this->selectVertexStmt = $this->tempDb->prepare(
            'SELECT id FROM vertices WHERE key = ?'
        );

        // Binary temp file for triangle indices (12 bytes per triangle: 3 x uint32)
        $this->triangleTempPath = tempnam($tempDir, 'silo_tri_');
        $this->triangleFile = fopen($this->triangleTempPath, 'w+b');
        if (!$this->triangleFile) {
            throw new Exception('Cannot create temp file for triangle data');
        }

        $this->vertexCount = 0;
        $this->triangleCount = 0;

        // Ensure cleanup on fatal errors
        $dbPath = $this->tempDbPath;
        $triPath = $this->triangleTempPath;
        register_shutdown_function(function () use ($dbPath, $triPath) {
            if ($dbPath && is_file($dbPath)) @unlink($dbPath);
            if ($dbPath && is_file($dbPath . '-wal')) @unlink($dbPath . '-wal');
            if ($dbPath && is_file($dbPath . '-shm')) @unlink($dbPath . '-shm');
            if ($triPath && is_file($triPath)) @unlink($triPath);
        });
    }

    /**
     * Clean up temp storage (SQLite DB + triangle file).
     */
    private function cleanupTempStorage(): void
    {
        $this->insertVertexStmt = null;
        $this->selectVertexStmt = null;

        if ($this->tempDb !== null) {
            $this->tempDb = null; // Close PDO connection
        }
        if ($this->tempDbPath !== null) {
            if (is_file($this->tempDbPath)) @unlink($this->tempDbPath);
            if (is_file($this->tempDbPath . '-wal')) @unlink($this->tempDbPath . '-wal');
            if (is_file($this->tempDbPath . '-shm')) @unlink($this->tempDbPath . '-shm');
            $this->tempDbPath = null;
        }

        if ($this->triangleFile !== null) {
            fclose($this->triangleFile);
            $this->triangleFile = null;
        }
        if ($this->triangleTempPath !== null) {
            if (is_file($this->triangleTempPath)) @unlink($this->triangleTempPath);
            $this->triangleTempPath = null;
        }
    }

    /**
     * Look up or insert a vertex, returning its 0-based index.
     */
    private function getOrInsertVertex(float $x, float $y, float $z): int
    {
        $key = pack('d3', $x, $y, $z);

        // Try to insert with the next available ID (ignored if key already exists)
        $this->insertVertexStmt->execute([$this->vertexCount, $key, $x, $y, $z]);
        if ($this->insertVertexStmt->rowCount() > 0) {
            // New vertex inserted
            return $this->vertexCount++;
        }

        // Duplicate key — look up the existing ID
        $this->selectVertexStmt->execute([$key]);
        $row = $this->selectVertexStmt->fetch(PDO::FETCH_NUM);
        $this->selectVertexStmt->closeCursor();

        return (int)$row[0];
    }

    /**
     * Write a triangle's three vertex indices to the binary temp file.
     */
    private function writeTriangle(int $v1, int $v2, int $v3): void
    {
        fwrite($this->triangleFile, pack('VVV', $v1, $v2, $v3));
        $this->triangleCount++;
    }

    /**
     * Parse a binary STL file
     */
    public function parseBinarySTL($filePath)
    {
        $this->initTempStorage();

        try {
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                throw new Exception('Cannot open STL file');
            }

            // Skip 80-byte header
            fseek($handle, 80);

            // Read triangle count
            $triangleCountData = fread($handle, 4);
            $triangleCount = unpack('V', $triangleCountData)[1];

            // Read in chunks of 1000 triangles (~50KB) instead of entire file at once
            $chunkSize = 1000;
            $remaining = $triangleCount;
            $batchCount = 0;

            $this->tempDb->beginTransaction();

            while ($remaining > 0) {
                $batch = min($chunkSize, $remaining);
                $data = fread($handle, $batch * 50);

                if (strlen($data) < $batch * 50) {
                    fclose($handle);
                    throw new Exception('Incomplete STL file');
                }

                $offset = 0;
                for ($i = 0; $i < $batch; $i++) {
                    // Unpack all 12 floats at once (normal[3] + v1[3] + v2[3] + v3[3])
                    $floats = unpack('f12', $data, $offset);
                    $offset += 48;

                    $triIndices = [];

                    // Process 3 vertices (skip normal at indices 1-3)
                    for ($v = 0; $v < 3; $v++) {
                        $base = 4 + ($v * 3);
                        $x = round($floats[$base], 6);
                        $y = round($floats[$base + 1], 6);
                        $z = round($floats[$base + 2], 6);

                        $triIndices[] = $this->getOrInsertVertex($x, $y, $z);
                    }

                    $this->writeTriangle($triIndices[0], $triIndices[1], $triIndices[2]);

                    // Skip attribute byte count (2 bytes)
                    $offset += 2;
                    $batchCount++;

                    // Commit transaction every 50K triangles
                    if ($batchCount >= 50000) {
                        $this->tempDb->commit();
                        $this->tempDb->beginTransaction();
                        $batchCount = 0;
                    }
                }

                $remaining -= $batch;
                unset($data);
            }

            $this->tempDb->commit();
            fclose($handle);

            return [
                'vertices' => $this->vertexCount,
                'triangles' => $this->triangleCount
            ];
        } catch (Exception $e) {
            if (isset($handle) && is_resource($handle)) fclose($handle);
            $this->cleanupTempStorage();
            throw $e;
        }
    }

    /**
     * Parse an ASCII STL file
     * Uses line-by-line reading to avoid loading entire file into memory
     */
    public function parseASCIISTL($filePath)
    {
        $this->initTempStorage();

        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new Exception('Cannot read STL file');
            }

            $triIndices = [];
            $batchCount = 0;

            $this->tempDb->beginTransaction();

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if (preg_match('/^vertex\s+([\d.eE+-]+)\s+([\d.eE+-]+)\s+([\d.eE+-]+)/i', $line, $m)) {
                    $x = round((float)$m[1], 6);
                    $y = round((float)$m[2], 6);
                    $z = round((float)$m[3], 6);

                    $triIndices[] = $this->getOrInsertVertex($x, $y, $z);
                } elseif (stripos($line, 'endloop') === 0) {
                    if (count($triIndices) === 3) {
                        $this->writeTriangle($triIndices[0], $triIndices[1], $triIndices[2]);
                        $batchCount++;

                        // Commit transaction every 50K triangles
                        if ($batchCount >= 50000) {
                            $this->tempDb->commit();
                            $this->tempDb->beginTransaction();
                            $batchCount = 0;
                        }
                    }
                    $triIndices = [];
                }
            }

            $this->tempDb->commit();
            fclose($handle);

            return [
                'vertices' => $this->vertexCount,
                'triangles' => $this->triangleCount
            ];
        } catch (Exception $e) {
            if (isset($handle) && is_resource($handle)) fclose($handle);
            $this->cleanupTempStorage();
            throw $e;
        }
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
     * Stream 3MF XML content directly to a file.
     * Reads vertices from SQLite and triangles from the binary temp file.
     */
    private function write3MFModelToFile($filePath)
    {
        $handle = fopen($filePath, 'w');
        if (!$handle) {
            throw new Exception('Cannot create temporary model file');
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($handle, '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">' . "\n");
        fwrite($handle, "  <resources>\n");
        fwrite($handle, "    <object id=\"1\" type=\"model\">\n");
        fwrite($handle, "      <mesh>\n");

        // Stream vertices from SQLite in batches of 1000
        fwrite($handle, "        <vertices>\n");
        $stmt = $this->tempDb->query('SELECT x, y, z FROM vertices ORDER BY id');
        $buffer = '';
        $bufCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $buffer .= sprintf('          <vertex x="%.6f" y="%.6f" z="%.6f" />' . "\n", $row[0], $row[1], $row[2]);
            if (++$bufCount >= 1000) {
                fwrite($handle, $buffer);
                $buffer = '';
                $bufCount = 0;
            }
        }
        if ($buffer !== '') {
            fwrite($handle, $buffer);
        }
        fwrite($handle, "        </vertices>\n");

        // Stream triangles from binary temp file in batches of 1000
        fwrite($handle, "        <triangles>\n");
        fseek($this->triangleFile, 0);
        $buffer = '';
        $bufCount = 0;
        while (($data = fread($this->triangleFile, 12)) !== false && strlen($data) === 12) {
            $indices = unpack('V3', $data);
            $buffer .= sprintf('          <triangle v1="%d" v2="%d" v3="%d" />' . "\n", $indices[1], $indices[2], $indices[3]);
            if (++$bufCount >= 1000) {
                fwrite($handle, $buffer);
                $buffer = '';
                $bufCount = 0;
            }
        }
        if ($buffer !== '') {
            fwrite($handle, $buffer);
        }
        fwrite($handle, "        </triangles>\n");

        fwrite($handle, "      </mesh>\n");
        fwrite($handle, "    </object>\n");
        fwrite($handle, "  </resources>\n");
        fwrite($handle, "  <build>\n");
        fwrite($handle, "    <item objectid=\"1\" />\n");
        fwrite($handle, "  </build>\n");
        fwrite($handle, '</model>');

        fclose($handle);
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
     * @param string|null $outputPath Path for output 3MF file (optional)
     * @return array Result with success status and file info
     */
    public function convertTo3MF($stlPath, $outputPath = null)
    {
        if (!is_file($stlPath)) {
            throw new Exception('STL file not found: ' . $stlPath);
        }

        $originalSize = filesize($stlPath);

        // Parse the STL file (populates temp storage)
        $parseResult = $this->parseSTL($stlPath);

        if ($this->triangleCount === 0) {
            $this->cleanupTempStorage();
            throw new Exception('No triangles found in STL file');
        }

        // Generate output path if not provided
        if ($outputPath === null) {
            $outputPath = preg_replace('/\.stl$/i', '.3mf', $stlPath);
        }

        // Write 3MF model XML to a temp file on the same filesystem as output
        $cacheDir = __DIR__ . '/../storage/cache';
        $tempModelFile = tempnam(is_writable($cacheDir) ? $cacheDir : sys_get_temp_dir(), 'silo3mf_');

        try {
            $this->write3MFModelToFile($tempModelFile);

            // Clean up temp storage before ZIP creation to free disk space
            $this->cleanupTempStorage();

            // Create 3MF (ZIP) file
            $zip = new ZipArchive();
            if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('Cannot create 3MF file');
            }

            $zip->addFromString('[Content_Types].xml', $this->generateContentTypes());
            $zip->addFromString('_rels/.rels', $this->generateRels());
            $zip->addFile($tempModelFile, '3D/3dmodel.model');

            $zip->close();
        } finally {
            if (is_file($tempModelFile)) {
                unlink($tempModelFile);
            }
            $this->cleanupTempStorage();
        }

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
     * Estimate 3MF size without actually converting.
     * For binary STL, reads only the header to get triangle count.
     * For ASCII STL, stream-counts without loading geometry.
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
            // ASCII STL: stream-count without loading geometry into memory
            $handle = fopen($stlPath, 'r');
            if (!$handle) {
                throw new Exception('Cannot read STL file');
            }

            $triangleCount = 0;
            $vertexRefCount = 0;

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if (stripos($trimmed, 'endloop') === 0) {
                    $triangleCount++;
                } elseif (preg_match('/^vertex\s+/i', $trimmed)) {
                    $vertexRefCount++;
                }
            }
            fclose($handle);

            // Estimate ~50% vertex sharing (same heuristic as binary path)
            $estimatedVertices = (int)($vertexRefCount * 0.5);
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
 * Get system memory total and used bytes.
 *
 * In Docker, cgroup `memory.current` / `memory.usage_in_bytes` counts
 * reclaimable page cache toward "used" — the kernel reclaims that cache
 * instantly under memory pressure, so it's not actually consumed in any
 * meaningful sense. That inflation trips the 90% check in
 * `convertPartTo3MF` even on a nearly-idle container that's just been
 * reading STL files.
 *
 * We subtract reclaimable memory when we can get it (cgroup `memory.stat`
 * on v2, `memory.stat` on v1, MemAvailable on bare metal) so "used" means
 * genuinely-in-use memory, not "memory that happens to have a kernel
 * cache page in it."
 */
function getSystemMemory(): ?array
{
    // cgroup v2 (modern Docker)
    if (is_readable('/sys/fs/cgroup/memory.max') && is_readable('/sys/fs/cgroup/memory.current')) {
        $max = trim(file_get_contents('/sys/fs/cgroup/memory.max'));
        $current = (int)trim(file_get_contents('/sys/fs/cgroup/memory.current'));
        if ($max !== 'max' && (int)$max > 0) {
            // Subtract reclaimable page cache (memory.stat:file) so the
            // "used" number reflects actually-consumed memory.
            $reclaimable = 0;
            if (is_readable('/sys/fs/cgroup/memory.stat')) {
                $stat = file_get_contents('/sys/fs/cgroup/memory.stat');
                // `file` = page cache backed by files on disk (reclaimable).
                // We intentionally don't subtract `shmem` or `anon` — those
                // are genuinely consumed and matter for conversion headroom.
                if (preg_match('/^file\s+(\d+)/m', $stat, $m)) {
                    $reclaimable = (int)$m[1];
                }
            }
            $used = max(0, $current - $reclaimable);
            return ['total' => (int)$max, 'used' => $used];
        }
    }

    // cgroup v1
    if (is_readable('/sys/fs/cgroup/memory/memory.limit_in_bytes') && is_readable('/sys/fs/cgroup/memory/memory.usage_in_bytes')) {
        $limit = (int)trim(file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes'));
        $usage = (int)trim(file_get_contents('/sys/fs/cgroup/memory/memory.usage_in_bytes'));
        if ($limit > 0 && $limit < (PHP_INT_MAX / 2)) {
            // Same fix for cgroup v1: memory.stat exposes `cache` (reclaimable).
            $reclaimable = 0;
            if (is_readable('/sys/fs/cgroup/memory/memory.stat')) {
                $stat = file_get_contents('/sys/fs/cgroup/memory/memory.stat');
                if (preg_match('/^cache\s+(\d+)/m', $stat, $m)) {
                    $reclaimable = (int)$m[1];
                }
            }
            $used = max(0, $usage - $reclaimable);
            return ['total' => $limit, 'used' => $used];
        }
    }

    // /proc/meminfo (bare metal or host-level)
    // MemAvailable already accounts for reclaimable cache — this path was
    // always correct.
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch) &&
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch)) {
            $total = (int)$totalMatch[1] * 1024;
            $available = (int)$availMatch[1] * 1024;
            return ['total' => $total, 'used' => $total - $available];
        }
    }

    return null;
}

function convertPartTo3MF($partId)
{
    // Force garbage collection before memory check so previous conversion
    // memory is properly released (PHP doesn't free immediately otherwise)
    gc_collect_cycles();

    // Check system memory — throw so queue worker retries with backoff
    // Use 90% threshold: in Docker, cgroup memory includes reclaimable page cache
    // which inflates the "used" number. The kernel reclaims cache under pressure.
    $mem = getSystemMemory();
    if ($mem !== null) {
        $usedPercent = ($mem['used'] / $mem['total']) * 100;
        if ($usedPercent >= 90) {
            throw new Exception(sprintf(
                'System memory too high for conversion (%.0f%% used)',
                $usedPercent
            ));
        }
    }

    $db = getDB();

    // Get part details
    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
    $stmt->bindValue(':id', $partId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $part = $result->fetch(PDO::FETCH_ASSOC);

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

    // Check available disk space — conversion needs temp files
    // Estimate: triangle file (12 bytes/tri) + SQLite DB (~180 bytes/vertex)
    // Use binary header to get triangle count for the estimate
    $diskEstimateTriangles = 0;
    if (filesize($stlPath) >= 84) {
        $fh = fopen($stlPath, 'rb');
        if ($fh) {
            fseek($fh, 80);
            $data = fread($fh, 4);
            if (strlen($data) === 4) {
                $diskEstimateTriangles = unpack('V', $data)[1];
            }
            fclose($fh);
        }
    }
    if ($diskEstimateTriangles > 0) {
        $cacheDir = __DIR__ . '/../storage/cache';
        $checkDir = is_writable($cacheDir) ? $cacheDir : sys_get_temp_dir();
        $neededBytes = ($diskEstimateTriangles * 12) + ($diskEstimateTriangles * 3 * 60);
        $freeBytes = disk_free_space($checkDir);
        if ($freeBytes !== false && $freeBytes < $neededBytes) {
            return ['success' => false, 'error' => sprintf(
                'Insufficient disk space for conversion temp files (need %s, have %s)',
                formatBytes($neededBytes),
                formatBytes($freeBytes)
            )];
        }
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
                $refCount = $refResult ? ($refResult->fetchArray(PDO::FETCH_ASSOC)['cnt'] ?? 0) : 0;

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
    $part = $result->fetch(PDO::FETCH_ASSOC);

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
