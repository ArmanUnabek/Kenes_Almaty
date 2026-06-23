<?php

namespace Tests;

use App\Auth\AccessDenied;
use App\Auth\AccessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers the authorization / region-scoping decisions that gate every write,
 * delete, export and region-filtered read in the application. These were
 * previously untested (and the test bootstrap stubbed canAccessRegion to
 * always return true), so cross-region isolation was assumed rather than
 * verified.
 */
class AccessPolicyTest extends TestCase
{
    // ─── normalizeRole ─────────────────────────────────────────────────────

    public function testNormalizeRoleKeepsKnownRoles(): void
    {
        $this->assertSame('admin', AccessPolicy::normalizeRole('admin'));
        $this->assertSame('moderator', AccessPolicy::normalizeRole('moderator'));
        $this->assertSame('viewer', AccessPolicy::normalizeRole('viewer'));
    }

    public function testNormalizeRoleIsCaseInsensitive(): void
    {
        $this->assertSame('admin', AccessPolicy::normalizeRole('ADMIN'));
        $this->assertSame('moderator', AccessPolicy::normalizeRole('Moderator'));
    }

    public function testLegacyManagerMapsToModerator(): void
    {
        $this->assertSame('moderator', AccessPolicy::normalizeRole('manager'));
        $this->assertSame('moderator', AccessPolicy::normalizeRole('MANAGER'));
    }

    public function testUnknownOrEmptyRoleFallsBackToViewer(): void
    {
        $this->assertSame('viewer', AccessPolicy::normalizeRole('superuser'));
        $this->assertSame('viewer', AccessPolicy::normalizeRole(''));
        $this->assertSame('viewer', AccessPolicy::normalizeRole(null));
    }

    // ─── capability matrix ─────────────────────────────────────────────────

    public function testIsAdmin(): void
    {
        $this->assertTrue(AccessPolicy::isAdmin('admin'));
        $this->assertFalse(AccessPolicy::isAdmin('moderator'));
        $this->assertFalse(AccessPolicy::isAdmin('viewer'));
        $this->assertFalse(AccessPolicy::isAdmin('manager'));
    }

    public function testCanWriteAllowsAdminAndModeratorOnly(): void
    {
        $this->assertTrue(AccessPolicy::canWrite('admin'));
        $this->assertTrue(AccessPolicy::canWrite('moderator'));
        $this->assertTrue(AccessPolicy::canWrite('manager')); // legacy → moderator
        $this->assertFalse(AccessPolicy::canWrite('viewer'));
    }

    public function testDeleteAndExportRequireAdmin(): void
    {
        $this->assertTrue(AccessPolicy::canDelete('admin'));
        $this->assertTrue(AccessPolicy::canExport('admin'));
        foreach (['moderator', 'viewer', 'manager'] as $role) {
            $this->assertFalse(AccessPolicy::canDelete($role), "$role must not delete");
            $this->assertFalse(AccessPolicy::canExport($role), "$role must not export");
        }
    }

    public function testHasAnyRoleNormalizesBeforeComparing(): void
    {
        $this->assertTrue(AccessPolicy::hasAnyRole('manager', ['moderator']));
        $this->assertTrue(AccessPolicy::hasAnyRole('ADMIN', ['admin']));
        $this->assertFalse(AccessPolicy::hasAnyRole('viewer', ['admin', 'moderator']));
    }

    // ─── canAccessRegion ───────────────────────────────────────────────────

    public function testAdminCanAccessAnyRegion(): void
    {
        $admin = ['role' => 'admin', 'region_id' => 1];
        $this->assertTrue(AccessPolicy::canAccessRegion($admin, 1));
        $this->assertTrue(AccessPolicy::canAccessRegion($admin, 999));
    }

    public function testNonAdminCanAccessOnlyOwnRegion(): void
    {
        $user = ['role' => 'moderator', 'region_id' => 5];
        $this->assertTrue(AccessPolicy::canAccessRegion($user, 5));
        $this->assertTrue(AccessPolicy::canAccessRegion($user, '5')); // string coercion
        $this->assertFalse(AccessPolicy::canAccessRegion($user, 6));
    }

    public function testUserWithoutRegionCannotAccessRegions(): void
    {
        $user = ['role' => 'viewer'];
        $this->assertFalse(AccessPolicy::canAccessRegion($user, 1));
        // region_id defaults to 0, so only "region 0" would match — never a real region
        $this->assertTrue(AccessPolicy::canAccessRegion($user, 0));
    }

    // ─── currentRegionId ───────────────────────────────────────────────────

    public function testCurrentRegionIdForAdminFollowsActiveRegion(): void
    {
        $admin = ['role' => 'admin', 'region_id' => 1];
        $this->assertSame(7, AccessPolicy::currentRegionId($admin, 7));
        $this->assertNull(AccessPolicy::currentRegionId($admin, null)); // "all regions"
    }

    public function testCurrentRegionIdForNonAdminIsPinnedToOwnRegion(): void
    {
        $user = ['role' => 'moderator', 'region_id' => 3];
        // Active region is ignored for non-admins.
        $this->assertSame(3, AccessPolicy::currentRegionId($user, 99));
        $this->assertSame(3, AccessPolicy::currentRegionId($user, null));
    }

    public function testCurrentRegionIdForNonAdminWithoutRegionIsNull(): void
    {
        $this->assertNull(AccessPolicy::currentRegionId(['role' => 'viewer'], 5));
        $this->assertNull(AccessPolicy::currentRegionId(['role' => 'viewer', 'region_id' => 0], 5));
    }

    // ─── resolveRegionIdForWrite ───────────────────────────────────────────

    public function testAdminWriteUsesRequestedRegion(): void
    {
        $admin = ['role' => 'admin', 'region_id' => 1];
        $this->assertSame(8, AccessPolicy::resolveRegionIdForWrite($admin, 8, null));
    }

    public function testAdminWriteFallsBackToActiveThenOwnRegion(): void
    {
        $admin = ['role' => 'admin', 'region_id' => 2];
        $this->assertSame(4, AccessPolicy::resolveRegionIdForWrite($admin, null, 4));
        $this->assertSame(2, AccessPolicy::resolveRegionIdForWrite($admin, null, null));
    }

    public function testAdminWriteWithoutAnyRegionIsRejected(): void
    {
        $admin = ['role' => 'admin']; // no region anywhere
        $this->expectException(AccessDenied::class);
        $this->expectExceptionCode(400);
        AccessPolicy::resolveRegionIdForWrite($admin, null, null);
    }

    public function testNonAdminWriteIsPinnedToOwnRegion(): void
    {
        $user = ['role' => 'moderator', 'region_id' => 5];
        $this->assertSame(5, AccessPolicy::resolveRegionIdForWrite($user, null, null));
        $this->assertSame(5, AccessPolicy::resolveRegionIdForWrite($user, 5, null));
    }

    public function testNonAdminCannotWriteIntoAnotherRegion(): void
    {
        $user = ['role' => 'moderator', 'region_id' => 5];
        $this->expectException(AccessDenied::class);
        $this->expectExceptionCode(403);
        AccessPolicy::resolveRegionIdForWrite($user, 6, null);
    }

    public function testNonAdminWithoutRegionCannotWrite(): void
    {
        $user = ['role' => 'viewer'];
        $this->expectException(AccessDenied::class);
        $this->expectExceptionCode(403);
        AccessPolicy::resolveRegionIdForWrite($user, null, null);
    }

    // ─── resolveRegionIdForRead ────────────────────────────────────────────

    public function testAdminReadHonoursActiveRegionOrAllRegions(): void
    {
        $admin = ['role' => 'admin', 'region_id' => 1];
        $this->assertSame(9, AccessPolicy::resolveRegionIdForRead($admin, 9));
        $this->assertNull(AccessPolicy::resolveRegionIdForRead($admin, null));
    }

    public function testNonAdminReadIsRestrictedToOwnRegion(): void
    {
        $user = ['role' => 'viewer', 'region_id' => 3];
        $this->assertSame(3, AccessPolicy::resolveRegionIdForRead($user, 99));
    }

    public function testNonAdminReadWithoutRegionIsRejected(): void
    {
        $user = ['role' => 'viewer'];
        $this->expectException(AccessDenied::class);
        $this->expectExceptionCode(403);
        AccessPolicy::resolveRegionIdForRead($user, null);
    }
}
