<?php

namespace App\Auth;

/**
 * Pure (side-effect free) authorization and region-scoping decisions.
 *
 * This class is the single source of truth for role normalization, the
 * write/delete/export capability matrix and region access rules. It performs
 * no I/O (no DB, session, headers or `exit`) so that every branch can be
 * exercised by unit tests. The procedural `auth_middleware.php` delegates to
 * it and is responsible only for fetching the current user/session and turning
 * an {@see AccessDenied} into an HTTP response.
 */
final class AccessPolicy
{
    public const ROLE_ADMIN     = 'admin';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_VIEWER    = 'viewer';

    /** Roles allowed to create/update records. */
    public const WRITE_ROLES = [self::ROLE_ADMIN, self::ROLE_MODERATOR];

    /**
     * Canonicalize a raw role value. Unknown/empty roles fall back to viewer
     * (least privilege); the legacy "manager" role maps to "moderator".
     */
    public static function normalizeRole(?string $role): string
    {
        $role = strtolower((string) $role);
        if ($role === 'manager') {
            return self::ROLE_MODERATOR;
        }
        if (in_array($role, [self::ROLE_ADMIN, self::ROLE_MODERATOR, self::ROLE_VIEWER], true)) {
            return $role;
        }
        return self::ROLE_VIEWER;
    }

    public static function isAdmin(?string $role): bool
    {
        return self::normalizeRole($role) === self::ROLE_ADMIN;
    }

    public static function canWrite(?string $role): bool
    {
        return in_array(self::normalizeRole($role), self::WRITE_ROLES, true);
    }

    public static function canDelete(?string $role): bool
    {
        return self::isAdmin($role);
    }

    public static function canExport(?string $role): bool
    {
        return self::isAdmin($role);
    }

    /**
     * Whether a (already normalized or raw) role is one of $allowedRoles.
     */
    public static function hasAnyRole(?string $role, array $allowedRoles): bool
    {
        return in_array(self::normalizeRole($role), $allowedRoles, true);
    }

    /**
     * Whether $user may read/act on records belonging to $regionId.
     * Admins can access every region; everyone else only their own.
     */
    public static function canAccessRegion(array $user, $regionId): bool
    {
        if (self::isAdmin($user['role'] ?? '')) {
            return true;
        }
        return (int) $regionId === (int) ($user['region_id'] ?? 0);
    }

    /**
     * The effective region for the current context.
     *
     * Admins follow their selected "active" region (null = all regions);
     * non-admins are always pinned to their assigned region (0/absent → null).
     *
     * @param int|null $activeRegionId The admin's selected region from session.
     */
    public static function currentRegionId(array $user, ?int $activeRegionId): ?int
    {
        if (self::isAdmin($user['role'] ?? '')) {
            return $activeRegionId;
        }
        $regionId = (int) ($user['region_id'] ?? 0);
        return $regionId > 0 ? $regionId : null;
    }

    /**
     * Resolve the region a write should target, enforcing that non-admins
     * cannot write into another region.
     *
     * @param int|null $requestedRegionId region_id supplied by the request.
     * @param int|null $activeRegionId    admin's selected region (session).
     * @throws AccessDenied 400 if an admin has no resolvable region,
     *                       403 if a non-admin has no region or targets another.
     */
    public static function resolveRegionIdForWrite(array $user, ?int $requestedRegionId, ?int $activeRegionId): int
    {
        if (self::isAdmin($user['role'] ?? '')) {
            $regionId = $requestedRegionId ?? $activeRegionId ?? ($user['region_id'] ?? null);
            if (!$regionId || (int) $regionId <= 0) {
                throw new AccessDenied(400, 'Укажите регион (region_id) для операции');
            }
            return (int) $regionId;
        }

        $ownRegion = (int) ($user['region_id'] ?? 0);
        if ($ownRegion <= 0) {
            throw new AccessDenied(403, 'У пользователя не назначен регион');
        }
        if ($requestedRegionId !== null && (int) $requestedRegionId !== $ownRegion) {
            throw new AccessDenied(403, 'Нельзя создавать данные в другом регионе');
        }
        return $ownRegion;
    }

    /**
     * Resolve the region filter for reading lists. Admins see their active
     * region (or all regions → null); non-admins are restricted to their own.
     *
     * @throws AccessDenied 403 if a non-admin has no assigned region.
     */
    public static function resolveRegionIdForRead(array $user, ?int $activeRegionId): ?int
    {
        if (self::isAdmin($user['role'] ?? '')) {
            return self::currentRegionId($user, $activeRegionId);
        }
        $regionId = (int) ($user['region_id'] ?? 0);
        if ($regionId <= 0) {
            throw new AccessDenied(403, 'У пользователя не назначен регион');
        }
        return $regionId;
    }
}
