<?php

require_once __DIR__ . '/../bootstrap.php';
require_once APP_ROOT . '/includes/converter.php';

class ConverterTest extends SiloTestCase
{
    private string $fixturesDir;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = APP_ROOT . '/tests/fixtures';
        $this->tempDir = sys_get_temp_dir() . '/silo_converter_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp dir
        if (is_dir($this->tempDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testIsBinarySTL(): void
    {
        $converter = new STLConverter();
        $this->assertTrue($converter->isBinarySTL($this->fixturesDir . '/cube.stl'));
        $this->assertFalse($converter->isBinarySTL($this->fixturesDir . '/cube-ascii.stl'));
    }

    public function testParseBinarySTL(): void
    {
        $converter = new STLConverter();
        $result = $converter->parseBinarySTL($this->fixturesDir . '/cube.stl');

        $this->assertSame(12, $result['triangles']);
        $this->assertSame(8, $result['vertices']);
    }

    public function testParseASCIISTL(): void
    {
        $converter = new STLConverter();
        $result = $converter->parseASCIISTL($this->fixturesDir . '/cube-ascii.stl');

        $this->assertSame(12, $result['triangles']);
        $this->assertSame(8, $result['vertices']);
    }

    public function testConvertTo3MF(): void
    {
        $converter = new STLConverter();
        $outputPath = $this->tempDir . '/cube.3mf';
        $result = $converter->convertTo3MF($this->fixturesDir . '/cube.stl', $outputPath);

        $this->assertTrue($result['success']);
        $this->assertSame(12, $result['triangles']);
        $this->assertSame(8, $result['vertices']);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, $result['new_size']);

        // Verify ZIP structure
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($outputPath) === true);
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertNotFalse($zip->locateName('_rels/.rels'));
        $this->assertNotFalse($zip->locateName('3D/3dmodel.model'));

        // Verify 3MF XML content
        $modelXml = $zip->getFromName('3D/3dmodel.model');
        $zip->close();

        $this->assertNotFalse($modelXml);
        $this->assertStringContainsString('<vertices>', $modelXml);
        $this->assertStringContainsString('<triangles>', $modelXml);

        // Count vertices and triangles in XML
        $vertexCount = substr_count($modelXml, '<vertex ');
        $triangleXmlCount = substr_count($modelXml, '<triangle ');
        $this->assertSame(8, $vertexCount);
        $this->assertSame(12, $triangleXmlCount);
    }

    public function testEstimateBinarySTL(): void
    {
        $converter = new STLConverter();
        $result = $converter->estimateConversion($this->fixturesDir . '/cube.stl');

        $this->assertSame(12, $result['triangles']);
        $this->assertGreaterThan(0, $result['vertices']);
        $this->assertSame(684, $result['original_size']);
        $this->assertArrayHasKey('estimated_size', $result);
        $this->assertArrayHasKey('worth_converting', $result);
    }

    public function testEstimateASCIISTL(): void
    {
        $converter = new STLConverter();
        $result = $converter->estimateConversion($this->fixturesDir . '/cube-ascii.stl');

        $this->assertSame(12, $result['triangles']);
        $this->assertGreaterThan(0, $result['vertices']);
        $this->assertArrayHasKey('estimated_size', $result);
        $this->assertArrayHasKey('worth_converting', $result);
    }

    public function testTempCleanup(): void
    {
        // Check both possible temp directories
        $cacheDir = APP_ROOT . '/storage/cache';
        $sysTmp = sys_get_temp_dir();
        $dirs = array_unique([$cacheDir, $sysTmp]);

        $beforeVtx = [];
        $beforeTri = [];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $beforeVtx = array_merge($beforeVtx, glob($dir . '/silo_vtx_*'));
                $beforeTri = array_merge($beforeTri, glob($dir . '/silo_tri_*'));
            }
        }

        $converter = new STLConverter();
        $outputPath = $this->tempDir . '/cleanup_test.3mf';
        $converter->convertTo3MF($this->fixturesDir . '/cube.stl', $outputPath);

        $afterVtx = [];
        $afterTri = [];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $afterVtx = array_merge($afterVtx, glob($dir . '/silo_vtx_*'));
                $afterTri = array_merge($afterTri, glob($dir . '/silo_tri_*'));
            }
        }

        $this->assertCount(count($beforeVtx), $afterVtx, 'SQLite temp files not cleaned up');
        $this->assertCount(count($beforeTri), $afterTri, 'Triangle temp files not cleaned up');
    }

    public function testInvalidFile(): void
    {
        $converter = new STLConverter();

        $this->expectException(Exception::class);
        $converter->convertTo3MF('/nonexistent/path/fake.stl');
    }
}
