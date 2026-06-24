<?php

namespace Tests;

use App\Services\FileStorage;
use PHPUnit\Framework\TestCase;

/**
 * Path-traversal confinement for FileStorage::pathWithinBase, used before
 * deleting a member's old photo (whose path is read from the DB).
 */
class FileStoragePathTest extends TestCase
{
    private const BASE = '/app/uploads';

    public function testAllowsPathInsideBase(): void
    {
        $this->assertSame(
            '/app/uploads/photos/member_1.jpg',
            FileStorage::pathWithinBase(self::BASE, '/app/uploads/photos/member_1.jpg')
        );
    }

    public function testAllowsBaseItself(): void
    {
        $this->assertSame('/app/uploads', FileStorage::pathWithinBase(self::BASE, '/app/uploads'));
    }

    public function testNormalizesInnerDotSegments(): void
    {
        $this->assertSame(
            '/app/uploads/photos/a.jpg',
            FileStorage::pathWithinBase(self::BASE, '/app/uploads/scans/../photos/./a.jpg')
        );
    }

    public function testRejectsTraversalOutsideBase(): void
    {
        $this->assertNull(FileStorage::pathWithinBase(self::BASE, '/app/uploads/../../etc/passwd'));
        $this->assertNull(FileStorage::pathWithinBase(self::BASE, '/app/uploads/photos/../../db.php'));
    }

    public function testRejectsSiblingOutsideBase(): void
    {
        // config.php lives in APP_ROOT, not under uploads/
        $this->assertNull(FileStorage::pathWithinBase(self::BASE, '/app/config.php'));
    }

    public function testRejectsPrefixButNotSubdirectory(): void
    {
        // "/app/uploadsX" must not be treated as inside "/app/uploads".
        $this->assertNull(FileStorage::pathWithinBase(self::BASE, '/app/uploadsX/secret.txt'));
    }

    public function testRejectsBackslashTraversal(): void
    {
        $this->assertNull(FileStorage::pathWithinBase(self::BASE, '/app/uploads/..\\..\\db.php'));
    }

    public function testRejectsEmptyPath(): void
    {
        $this->assertNull(FileStorage::pathWithinBase(self::BASE, ''));
    }

    public function testRealisticStoredPathJoin(): void
    {
        // Mirrors upload_photo.php: APP_ROOT . '/' . ltrim($dbPath, '/').
        $appRoot = '/var/www/journal';
        $dbPath = 'uploads/photos/member_5_123.jpg';
        $candidate = $appRoot . '/' . ltrim($dbPath, '/');
        $this->assertSame(
            '/var/www/journal/uploads/photos/member_5_123.jpg',
            FileStorage::pathWithinBase($appRoot . '/uploads', $candidate)
        );

        // A tampered DB value escaping uploads/ is rejected.
        $evil = $appRoot . '/' . ltrim('uploads/../config.php', '/');
        $this->assertNull(FileStorage::pathWithinBase($appRoot . '/uploads', $evil));
    }
}
