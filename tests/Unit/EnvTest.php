<?php

require_once dirname(__DIR__, 2) . '/includes/Env.php';

class EnvTest extends SiloTestCase {
    private string $testEnvFile;

    protected function setUp(): void {
        parent::setUp();
        $this->testEnvFile = sys_get_temp_dir() . '/.env_test_' . uniqid();
    }

    protected function tearDown(): void {
        if (file_exists($this->testEnvFile)) {
            unlink($this->testEnvFile);
        }
        parent::tearDown();
    }

    private function createEnvFile(string $content): void {
        file_put_contents($this->testEnvFile, $content);
    }

    public function testLoadsParsesBasicKeyValue(): void {
        $this->createEnvFile("APP_NAME=TestApp\nAPP_DEBUG=true");
        Env::load($this->testEnvFile);

        $this->assertEquals('TestApp', Env::get('APP_NAME'));
    }

    public function testHandlesQuotedValues(): void {
        $this->createEnvFile('MESSAGE="Hello World"');
        Env::load($this->testEnvFile);

        $this->assertEquals('Hello World', Env::get('MESSAGE'));
    }

    public function testHandlesSingleQuotedValues(): void {
        $this->createEnvFile("LITERAL='No \${VAR} interpolation'");
        Env::load($this->testEnvFile);

        $this->assertEquals('No ${VAR} interpolation', Env::get('LITERAL'));
    }

    public function testSkipsComments(): void {
        $this->createEnvFile("# This is a comment\nVALID=yes");
        Env::load($this->testEnvFile);

        $this->assertNull(Env::get('# This is a comment'));
        $this->assertEquals('yes', Env::get('VALID'));
    }

    public function testCastsTrueBoolean(): void {
        $this->createEnvFile("BOOL_TRUE=true\nBOOL_TRUE2=(true)");
        Env::load($this->testEnvFile);

        $this->assertTrue(Env::get('BOOL_TRUE'));
        $this->assertTrue(Env::get('BOOL_TRUE2'));
    }

    public function testCastsFalseBoolean(): void {
        $this->createEnvFile("BOOL_FALSE=false\nBOOL_FALSE2=(false)");
        Env::load($this->testEnvFile);

        $this->assertFalse(Env::get('BOOL_FALSE'));
        $this->assertFalse(Env::get('BOOL_FALSE2'));
    }

    public function testCastsNull(): void {
        $this->createEnvFile("NULL_VAL=null");
        Env::load($this->testEnvFile);

        $this->assertNull(Env::get('NULL_VAL'));
    }

    public function testCastsNumericValues(): void {
        $this->createEnvFile("INT_VAL=42\nFLOAT_VAL=3.14");
        Env::load($this->testEnvFile);

        $this->assertSame(42, Env::get('INT_VAL'));
        $this->assertSame(3.14, Env::get('FLOAT_VAL'));
    }

    public function testReturnsDefaultForMissingKey(): void {
        Env::load($this->testEnvFile);

        $this->assertEquals('default', Env::get('NONEXISTENT', 'default'));
        $this->assertNull(Env::get('NONEXISTENT'));
    }

    public function testHasReturnsTrueForExistingKey(): void {
        $this->createEnvFile("EXISTS=yes");
        Env::load($this->testEnvFile);

        $this->assertTrue(Env::has('EXISTS'));
    }

    public function testHasReturnsFalseForMissingKey(): void {
        $this->createEnvFile("");
        Env::load($this->testEnvFile);

        $this->assertFalse(Env::has('MISSING'));
    }

    public function testSetSetsRuntimeVariable(): void {
        Env::set('RUNTIME_VAR', 'runtime_value');

        $this->assertEquals('runtime_value', Env::get('RUNTIME_VAR'));
    }

    public function testStringHelperReturnsString(): void {
        $this->createEnvFile("NUM=123");
        Env::load($this->testEnvFile);

        $result = Env::string('NUM', '');
        $this->assertIsString($result);
    }

    public function testIntHelperReturnsInt(): void {
        $this->createEnvFile("NUM=42");
        Env::load($this->testEnvFile);

        $result = Env::int('NUM', 0);
        $this->assertSame(42, $result);
    }

    public function testFloatHelperReturnsFloat(): void {
        $this->createEnvFile("NUM=3.14");
        Env::load($this->testEnvFile);

        $result = Env::float('NUM', 0.0);
        $this->assertSame(3.14, $result);
    }

    public function testBoolHelperReturnsBool(): void {
        $this->createEnvFile("FLAG=true");
        Env::load($this->testEnvFile);

        $result = Env::bool('FLAG', false);
        $this->assertTrue($result);
    }

    public function testArrayHelperParsesCommaSeparated(): void {
        $this->createEnvFile("ITEMS=one,two,three");
        Env::load($this->testEnvFile);

        $result = Env::array('ITEMS', []);
        $this->assertEquals(['one', 'two', 'three'], $result);
    }

    public function testRequireThrowsForMissingKey(): void {
        $this->createEnvFile("");
        Env::load($this->testEnvFile);

        $this->expectException(RuntimeException::class);
        Env::require('REQUIRED_BUT_MISSING');
    }

    public function testHandlesInlineComments(): void {
        $this->createEnvFile("VALUE=actual # this is a comment");
        Env::load($this->testEnvFile);

        $this->assertEquals('actual', Env::get('VALUE'));
    }

    public function testHandlesEmptyLines(): void {
        $this->createEnvFile("FIRST=1\n\n\nSECOND=2");
        Env::load($this->testEnvFile);

        $this->assertEquals('1', Env::get('FIRST'));
        $this->assertEquals('2', Env::get('SECOND'));
    }

    public function testAllReturnsAllVariables(): void {
        $this->createEnvFile("A=1\nB=2");
        Env::load($this->testEnvFile);

        $all = Env::all();
        $this->assertArrayHasKey('A', $all);
        $this->assertArrayHasKey('B', $all);
    }

    public function testIsProductionDetectsProductionEnv(): void {
        $this->createEnvFile("APP_ENV=production");
        Env::load($this->testEnvFile);

        $this->assertTrue(Env::isProduction());
    }

    public function testIsDevelopmentDetectsDevelopmentEnv(): void {
        $this->createEnvFile("APP_ENV=development");
        Env::load($this->testEnvFile);

        $this->assertTrue(Env::isDevelopment());
    }

    public function testIsDebugDetectsDebugMode(): void {
        $this->createEnvFile("APP_DEBUG=true");
        Env::load($this->testEnvFile);

        $this->assertTrue(Env::isDebug());
    }
}
