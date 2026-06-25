<?php

namespace Tests;

use App\Services\FileCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests App\Services\FileCache against a real temp directory (no mocking).
 * FileCache is pure filesystem I/O, so it is fully exercisable in isolation.
 */
class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/filecache_test_' . bin2hex(random_bytes(6));
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testConstructorCreatesDirectory(): void
    {
        $this->assertDirectoryExists($this->dir);
    }

    public function testSetGetRoundtrip(): void
    {
        $this->cache->set('alpha', ['a' => 1, 'b' => [2, 3]], 60);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->cache->get('alpha'));
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $this->assertNull($this->cache->get('does-not-exist'));
    }

    public function testExpiredEntryReturnsNullAndIsRemoved(): void
    {
        // Write a payload whose expires_at is in the past, matching FileCache's
        // on-disk format, to exercise the expiry branch deterministically.
        $path = $this->dir . '/' . md5('stale') . '.json';
        file_put_contents($path, json_encode([
            'key' => 'stale',
            'expires_at' => time() - 10,
            'value' => 'old',
        ]));

        $this->assertNull($this->cache->get('stale'));
        $this->assertFileDoesNotExist($path, 'expired entry should be unlinked on read');
    }

    public function testForgetRemovesEntry(): void
    {
        $this->cache->set('k', 'v', 60);
        $this->cache->forget('k');
        $this->assertNull($this->cache->get('k'));
    }

    public function testForgetPrefixRemovesOnlyMatchingKeys(): void
    {
        $this->cache->set('region:1:members', 'a', 60);
        $this->cache->set('region:2:members', 'b', 60);
        $this->cache->set('other:1', 'c', 60);

        $this->cache->forgetPrefix('region:1:');

        $this->assertNull($this->cache->get('region:1:members'));
        $this->assertSame('b', $this->cache->get('region:2:members'));
        $this->assertSame('c', $this->cache->get('other:1'));
    }

    public function testFlushAllRemovesEverything(): void
    {
        $this->cache->set('x', 1, 60);
        $this->cache->set('y', 2, 60);
        $this->cache->flushAll();
        $this->assertNull($this->cache->get('x'));
        $this->assertNull($this->cache->get('y'));
    }

    public function testTtlIsFlooredToAtLeastOneSecond(): void
    {
        // ttl <= 0 must still produce a readable entry (expires_at = time()+max(1,ttl)).
        $this->cache->set('z', 'now', 0);
        $this->assertSame('now', $this->cache->get('z'));
    }
}
