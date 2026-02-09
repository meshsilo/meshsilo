<?php

class UploadValidationTest extends SiloTestCase {
    protected function setUp(): void {
        parent::setUp();

        // Define upload-related constants if not already defined
        if (!defined('ALLOWED_EXTENSIONS')) {
            define('ALLOWED_EXTENSIONS', [
                'stl', '3mf', 'obj', 'ply', 'amf', 'gcode',
                'glb', 'gltf', 'fbx', 'dae', 'blend',
                'step', 'stp', 'iges', 'igs', '3ds',
                'dxf', 'off', 'x3d', 'zip'
            ]);
        }
        if (!defined('MODEL_EXTENSIONS')) {
            define('MODEL_EXTENSIONS', [
                'stl', '3mf', 'obj', 'ply', 'amf', 'gcode',
                'glb', 'gltf', 'fbx', 'dae', 'blend',
                'step', 'stp', 'iges', 'igs', '3ds',
                'dxf', 'off', 'x3d'
            ]);
        }
        if (!defined('MAX_FILE_SIZE')) {
            define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
        }
        if (!defined('MAX_UPLOAD_SIZE')) {
            define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
        }
    }

    public function testStlExtensionIsAllowed(): void {
        $this->assertContains('stl', ALLOWED_EXTENSIONS);
    }

    public function testThreeMfExtensionIsAllowed(): void {
        $this->assertContains('3mf', ALLOWED_EXTENSIONS);
    }

    public function testObjExtensionIsAllowed(): void {
        $this->assertContains('obj', ALLOWED_EXTENSIONS);
    }

    public function testPlyExtensionIsAllowed(): void {
        $this->assertContains('ply', ALLOWED_EXTENSIONS);
    }

    public function testGltfExtensionIsAllowed(): void {
        $this->assertContains('gltf', ALLOWED_EXTENSIONS);
        $this->assertContains('glb', ALLOWED_EXTENSIONS);
    }

    public function testStepExtensionIsAllowed(): void {
        $this->assertContains('step', ALLOWED_EXTENSIONS);
        $this->assertContains('stp', ALLOWED_EXTENSIONS);
    }

    public function testGcodeExtensionIsAllowed(): void {
        $this->assertContains('gcode', ALLOWED_EXTENSIONS);
    }

    public function testZipExtensionIsAllowed(): void {
        $this->assertContains('zip', ALLOWED_EXTENSIONS);
    }

    public function testExeExtensionIsNotAllowed(): void {
        $this->assertNotContains('exe', ALLOWED_EXTENSIONS);
    }

    public function testPhpExtensionIsNotAllowed(): void {
        $this->assertNotContains('php', ALLOWED_EXTENSIONS);
    }

    public function testJsExtensionIsNotAllowed(): void {
        $this->assertNotContains('js', ALLOWED_EXTENSIONS);
    }

    public function testShExtensionIsNotAllowed(): void {
        $this->assertNotContains('sh', ALLOWED_EXTENSIONS);
    }

    public function testBatExtensionIsNotAllowed(): void {
        $this->assertNotContains('bat', ALLOWED_EXTENSIONS);
    }

    public function testHtmlExtensionIsNotAllowed(): void {
        $this->assertNotContains('html', ALLOWED_EXTENSIONS);
    }

    public function testSvgExtensionIsNotAllowed(): void {
        $this->assertNotContains('svg', ALLOWED_EXTENSIONS);
    }

    public function testMaxFileSizeIsDefined(): void {
        $this->assertGreaterThan(0, MAX_FILE_SIZE);
    }

    public function testMaxFileSizeIsReasonable(): void {
        // Should be at least 1MB
        $this->assertGreaterThanOrEqual(1024 * 1024, MAX_FILE_SIZE);
        // Should not exceed 1GB
        $this->assertLessThanOrEqual(1024 * 1024 * 1024, MAX_FILE_SIZE);
    }

    public function testMaxUploadSizeIsDefined(): void {
        $this->assertGreaterThan(0, MAX_UPLOAD_SIZE);
    }

    public function testModelExtensionsAreSubsetOfAllowed(): void {
        foreach (MODEL_EXTENSIONS as $ext) {
            $this->assertContains(
                $ext,
                ALLOWED_EXTENSIONS,
                "Model extension '$ext' should also be in ALLOWED_EXTENSIONS"
            );
        }
    }

    public function testAllowedExtensionsContainsOnlyLowercase(): void {
        foreach (ALLOWED_EXTENSIONS as $ext) {
            $this->assertEquals(
                strtolower($ext),
                $ext,
                "Extension '$ext' should be lowercase"
            );
        }
    }

    public function testDangerousExtensionsBlocked(): void {
        $dangerous = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar',
            'exe', 'bat', 'cmd', 'com', 'vbs', 'vbe', 'js', 'jse',
            'wsf', 'wsh', 'ps1', 'ps2', 'psc1', 'psc2', 'msh',
            'sh', 'bash', 'csh', 'ksh',
            'html', 'htm', 'xhtml', 'svg', 'xml',
            'py', 'rb', 'pl', 'cgi',
        ];

        foreach ($dangerous as $ext) {
            $this->assertNotContains(
                $ext,
                ALLOWED_EXTENSIONS,
                "Dangerous extension '$ext' should not be in ALLOWED_EXTENSIONS"
            );
        }
    }
}
