<?php

require_once dirname(__DIR__, 2) . '/includes/Cache.php';

class CacheTest extends SiloTestCase {
    private Cache $cache;
    private string $testCachePath;

    protected function setUp(): void {
        parent::setUp();
        $this->testCachePath = sys_get_temp_dir() . '/silo_test_cache_' . uniqid();
        mkdir($this->testCachePath, 0755, true);

        // Force file driver for testing
        $this->cache = new Cache([
            'driver' => 'file',
            'path' => $this->testCachePath
        ]);
    }

    protected function tearDown(): void {
        // Clean up test cache files
        $files = glob($this->testCachePath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testCachePath)) {
            rmdir($this->testCachePath);
        }
        parent::tearDown();
    }

    public function testSetAndGet(): void {
        $this->cache->set('key1', 'value1');
        $this->assertEquals('value1', $this->cache->get('key1'));
    }

    public function testGetReturnsDefaultForMissingKey(): void {
        $this->assertEquals('default', $this->cache->get('nonexistent', 'default'));
        $this->assertNull($this->cache->get('nonexistent'));
    }

    public function testHasReturnsTrueForExistingKey(): void {
        $this->cache->set('exists', 'value');
        $this->assertTrue($this->cache->has('exists'));
    }

    public function testHasReturnsFalseForMissingKey(): void {
        $this->assertFalse($this->cache->has('missing'));
    }

    public function testDeleteRemovesKey(): void {
        $this->cache->set('to_delete', 'value');
        $this->assertTrue($this->cache->has('to_delete'));

        $this->cache->delete('to_delete');
        $this->assertFalse($this->cache->has('to_delete'));
    }

    public function testClearRemovesAllKeys(): void {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $this->cache->clear();

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function testTtlExpiration(): void {
        $this->cache->set('expiring', 'value', 1); // 1 second TTL

        $this->assertTrue($this->cache->has('expiring'));

        sleep(2);

        $this->assertFalse($this->cache->has('expiring'));
        $this->assertNull($this->cache->get('expiring'));
    }

    public function testRememberStoresCallback(): void {
        $called = 0;
        $callback = function() use (&$called) {
            $called++;
            return 'computed_value';
        };

        // First call - callback should be executed
        $result1 = $this->cache->remember('computed', 60, $callback);
        $this->assertEquals('computed_value', $result1);
        $this->assertEquals(1, $called);

        // Second call - callback should NOT be executed (cached)
        $result2 = $this->cache->remember('computed', 60, $callback);
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $called); // Still 1
    }

    public function testForgetRemovesKey(): void {
        $this->cache->set('forget_me', 'value');
        $this->cache->forget('forget_me');

        $this->assertFalse($this->cache->has('forget_me'));
    }

    public function testPutIsSameAsSet(): void {
        $this->cache->put('put_key', 'put_value', 60);
        $this->assertEquals('put_value', $this->cache->get('put_key'));
    }

    public function testStoresArrays(): void {
        $array = ['name' => 'John', 'age' => 30];
        $this->cache->set('user', $array);

        $result = $this->cache->get('user');
        $this->assertEquals($array, $result);
    }

    public function testStoresObjects(): void {
        $obj = new stdClass();
        $obj->name = 'Test';
        $obj->value = 123;

        $this->cache->set('object', $obj);

        $result = $this->cache->get('object');
        $this->assertEquals($obj->name, $result->name);
        $this->assertEquals($obj->value, $result->value);
    }

    public function testIncrementValue(): void {
        $this->cache->set('counter', 5);

        $newValue = $this->cache->increment('counter');
        $this->assertEquals(6, $newValue);

        $newValue = $this->cache->increment('counter', 5);
        $this->assertEquals(11, $newValue);
    }

    public function testDecrementValue(): void {
        $this->cache->set('counter', 10);

        $newValue = $this->cache->decrement('counter');
        $this->assertEquals(9, $newValue);

        $newValue = $this->cache->decrement('counter', 5);
        $this->assertEquals(4, $newValue);
    }

    public function testGetMultiple(): void {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->set('c', 3);

        $result = $this->cache->getMultiple(['a', 'b', 'd'], 'default');

        $this->assertEquals(1, $result['a']);
        $this->assertEquals(2, $result['b']);
        $this->assertEquals('default', $result['d']);
    }

    public function testSetMultiple(): void {
        $this->cache->setMultiple([
            'multi1' => 'value1',
            'multi2' => 'value2'
        ]);

        $this->assertEquals('value1', $this->cache->get('multi1'));
        $this->assertEquals('value2', $this->cache->get('multi2'));
    }

    public function testDeleteMultiple(): void {
        $this->cache->set('del1', 'value1');
        $this->cache->set('del2', 'value2');

        $this->cache->deleteMultiple(['del1', 'del2']);

        $this->assertFalse($this->cache->has('del1'));
        $this->assertFalse($this->cache->has('del2'));
    }

    public function testForeverStoresWithoutExpiration(): void {
        $this->cache->forever('permanent', 'value');
        $this->assertEquals('value', $this->cache->get('permanent'));

        // Should still exist after a delay (not truly testing forever, but checking it works)
        sleep(1);
        $this->assertEquals('value', $this->cache->get('permanent'));
    }

    public function testAddOnlyWhenKeyDoesNotExist(): void {
        $result1 = $this->cache->add('unique', 'first');
        $this->assertTrue($result1);
        $this->assertEquals('first', $this->cache->get('unique'));

        // Second add should fail
        $result2 = $this->cache->add('unique', 'second');
        $this->assertFalse($result2);
        $this->assertEquals('first', $this->cache->get('unique')); // Still first
    }
}
