<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Resolves and applies a user's OpenFGA `admin` scopes.
 *
 * Single home for the resource-admin scoping logic shared by
 * AdminScopesHandler (GET /auth/admin-scopes), the widened
 * NotificationsHandler (GET /admin/notifications), and
 * AccessRequestAdminHandler (GET /admin/access-requests). Centralizing it
 * keeps the badge count and the review list in agreement.
 */
final class ResourceAdminService
{
    /**
     * Object types a user can hold the `admin` relation on. Mirrors the
     * admin-capable types in the OpenFGA authorization model.
     */
    public const ADMIN_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'general_roman_calendar',
    ];

    /**
     * Test object types a user can hold `editor`/`admin` on. Mirrors the
     * test-scoped object types in the OpenFGA authorization model.
     */
    public const TEST_OBJECT_TYPES = [
        'national_calendar_test',
        'diocesan_calendar_test',
        'general_roman_calendar_test',
    ];

    public function __construct(private readonly OpenFgaClient $fgaClient)
    {
    }

    /**
     * Union of the objects the user holds `admin` on across ADMIN_OBJECT_TYPES.
     *
     * Fails closed: any OpenFGA transport error yields an empty scope set.
     *
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @return list<array{object_type: string, object_id: string}>
     */
    public function resolveScopes(string $sub): array
    {
        $fgaUser = "user:{$sub}";
        $scopes  = [];

        try {
            foreach (self::ADMIN_OBJECT_TYPES as $type) {
                foreach ($this->fgaClient->listObjects($fgaUser, 'admin', $type) as $objectId) {
                    $scopes[] = ['object_type' => $type, 'object_id' => $objectId];
                }
            }
        } catch (\RuntimeException) {
            // Fail closed — caller is treated as not-a-resource-admin.
            return [];
        }

        return $scopes;
    }

    /**
     * The caller's `editor` and `admin` scopes across TEST_OBJECT_TYPES.
     *
     * `editor` is a superset of `admin` (the model grants test `editor` to
     * `admin`). Used to gate the admin-tests UI: edit requires `editor`,
     * delete requires `admin`.
     *
     * Fails closed: any OpenFGA transport error yields empty scope sets.
     *
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @return array{editor: list<array{object_type: string, object_id: string}>, admin: list<array{object_type: string, object_id: string}>}
     */
    public function resolveTestScopes(string $sub): array
    {
        $fgaUser = "user:{$sub}";
        $editor  = [];
        $admin   = [];

        try {
            foreach (self::TEST_OBJECT_TYPES as $type) {
                foreach ($this->fgaClient->listObjects($fgaUser, 'editor', $type) as $objectId) {
                    $editor[] = ['object_type' => $type, 'object_id' => $objectId];
                }
            }
            foreach (self::TEST_OBJECT_TYPES as $type) {
                foreach ($this->fgaClient->listObjects($fgaUser, 'admin', $type) as $objectId) {
                    $admin[] = ['object_type' => $type, 'object_id' => $objectId];
                }
            }
        } catch (\RuntimeException) {
            return ['editor' => [], 'admin' => []];
        }

        return ['editor' => $editor, 'admin' => $admin];
    }

    /**
     * Filter requests to only those the resource admin administers in full.
     *
     * A request qualifies only if the admin holds the `admin` relation on
     * EVERY resource in that request's permissions array. Requests with an
     * empty permissions array are excluded.
     *
     * @param array<int, array<string, mixed>> $requests
     * @param string $adminUserId Admin's Zitadel user ID (without "user:" prefix)
     * @return array<int, array<string, mixed>> Filtered, re-indexed requests
     */
    public function filterByAdminAccess(array $requests, string $adminUserId): array
    {
        $fgaUser = "user:{$adminUserId}";

        /** @var array<string, bool> $cache */
        $cache = [];

        return array_values(array_filter($requests, function (array $req) use ($fgaUser, &$cache): bool {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
            $permissions = is_array($req['permissions'] ?? null) ? $req['permissions'] : [];
            try {
                return $this->administersAllResources($permissions, $fgaUser, $cache);
            } catch (\RuntimeException) {
                // Fail closed — a transient OpenFGA failure excludes the request
                // rather than surfacing a 500. Mirrors resolveScopes().
                return false;
            }
        }));
    }

    /**
     * True iff the admin holds `admin` on every resource in $permissions.
     *
     * An empty $permissions array returns false (matches the prior
     * AccessRequestAdminHandler behavior of excluding empty-permission
     * requests). The $cache de-duplicates OpenFGA `check` calls per resource.
     *
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $permissions
     * @param string $fgaUser Fully-qualified FGA user string in `user:{sub}` form (already
     *                        prefixed). Contrast with {@see filterByAdminAccess()}, which accepts
     *                        a raw Zitadel sub and prepends the `user:` prefix internally.
     * @param array<string, bool> $cache Shared per-call check cache (by reference)
     * @return bool True iff the caller holds `admin` on every listed resource
     */
    public function administersAllResources(array $permissions, string $fgaUser, array &$cache): bool
    {
        if (empty($permissions)) {
            return false;
        }

        foreach ($permissions as $perm) {
            $objectType = $perm['object_type'] ?? '';
            $objectId   = $perm['object_id'] ?? '';
            $key        = "{$objectType}:{$objectId}";

            if (!isset($cache[$key])) {
                $cache[$key] = $this->fgaClient->check($fgaUser, 'admin', $key);
            }

            if (!$cache[$key]) {
                return false;
            }
        }

        return true;
    }
}
