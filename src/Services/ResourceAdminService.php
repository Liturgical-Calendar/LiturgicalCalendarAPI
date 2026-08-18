<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves and applies a user's OpenFGA `admin` scopes.
 *
 * Single home for the resource-admin scoping logic shared by
 * AdminScopesHandler (GET /auth/admin-scopes), the widened
 * NotificationsHandler (GET /admin/notifications), and
 * AccessRequestAdminHandler (GET /admin/access-requests). Centralizing it
 * keeps the badge count and the review list in agreement.
 *
 * **Failure isolation.** Every OpenFGA lookup is scoped to a single
 * (relation, object type) pair, and a failure on one pair never discards the
 * scopes already resolved for the others. A type that is missing from the
 * deployed authorization model — or that is momentarily unreachable — degrades
 * only its own slice of the result. This matters: a single unknown type used
 * to collapse the whole scope map to empty, silently de-authorizing every
 * user of every dashboard card (issue #793).
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
        'rite_calendar_test',
    ];

    /**
     * Object types whose `viewer` relation the frontend admin dashboard consults
     * for card visibility (issue LiturgicalCalendarFrontend#399). In the FGA model
     * `viewer` is a union including `editor` and `admin`, so a single `viewer`
     * query means "viewer or above".
     */
    public const VIEWER_OBJECT_TYPES = [
        'general_roman_calendar',
        'national_calendar_test',
        'diocesan_calendar_test',
        'general_roman_calendar_test',
        'rite_calendar_test',
    ];

    private ?LoggerInterface $logger;

    /**
     * @param OpenFgaClient        $fgaClient OpenFGA client used for all relation lookups
     * @param LoggerInterface|null $logger    Optional PSR-3 logger. When omitted, the shared
     *                                        `auth` channel logger is created lazily on first
     *                                        failure, so the happy path never touches the
     *                                        filesystem.
     */
    public function __construct(private readonly OpenFgaClient $fgaClient, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Lazily resolve the PSR-3 logger used to report per-type lookup failures.
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ??= LoggerFactory::create('auth', null, 30, false, true, false);
    }

    /**
     * Report a fail-closed recovery, without ever letting the reporting itself fail.
     *
     * Both halves of `$this->logger()->error(...)` can throw in production:
     * `LoggerFactory::create()` raises `\RuntimeException` when the logs directory
     * cannot be created, and Monolog's stream handlers raise when the target stream
     * cannot be opened (unwritable directory, full disk). Since `\RuntimeException`
     * is precisely the type the surrounding catch blocks handle, an unguarded log
     * call would propagate out of the recovery path and turn a degraded
     * authorization lookup into an unhandled 500 — reinstating, by a different
     * route, the global outage that issue #793 exists to prevent.
     *
     * Diagnostics are best-effort; the fail-closed return is not.
     *
     * @param array<string, mixed> $context
     */
    private function logFailure(string $message, array $context): void
    {
        try {
            $this->logger()->error($message, $context);
        } catch (\Throwable) {
            // Deliberately swallowed: there is nowhere left to report a broken
            // logger to, and the caller's fail-closed result matters more.
        }
    }

    /**
     * List the object IDs of one type the user holds one relation on, failing
     * closed for that single (relation, type) pair.
     *
     * A `\RuntimeException` — which is what `OpenFgaApiException` extends, so it
     * covers transport errors and model mismatches such as `type_not_found` —
     * yields an empty list for this pair only, and is always logged with the
     * object type, the relation and the underlying message. Callers therefore
     * keep every other pair's results.
     *
     * @param string $fgaUser  Fully-qualified FGA user string in `user:{sub}` form
     * @param string $relation Relation to probe (e.g. `admin`, `editor`, `viewer`)
     * @param string $type     Object type to probe (e.g. `national_calendar`)
     * @return array<int, string> Object IDs without the type prefix; empty on failure
     */
    private function listObjectsIsolated(string $fgaUser, string $relation, string $type): array
    {
        try {
            return $this->fgaClient->listObjects($fgaUser, $relation, $type);
        } catch (\RuntimeException $e) {
            // Fail closed for THIS object type only: zeroing every other type
            // turns one misconfigured type into a global authorization outage.
            $this->logFailure(
                sprintf(
                    'OpenFGA listObjects failed for object type "%s", relation "%s": %s',
                    $type,
                    $relation,
                    $e->getMessage()
                ),
                [
                    'object_type' => $type,
                    'relation'    => $relation,
                    'user'        => $fgaUser,
                    'error'       => $e->getMessage(),
                ]
            );
            return [];
        }
    }

    /**
     * Union of the objects the user holds `admin` on across ADMIN_OBJECT_TYPES.
     *
     * Fails closed per object type: an OpenFGA error on one type contributes no
     * entries for that type and is logged, while every other type's objects are
     * still returned. Only an across-the-board failure yields an empty set.
     *
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @return list<array{object_type: string, object_id: string}>
     */
    public function resolveScopes(string $sub): array
    {
        $fgaUser = "user:{$sub}";
        $scopes  = [];

        foreach (self::ADMIN_OBJECT_TYPES as $type) {
            foreach ($this->listObjectsIsolated($fgaUser, 'admin', $type) as $objectId) {
                $scopes[] = ['object_type' => $type, 'object_id' => $objectId];
            }
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
     * Fails closed per object type AND per relation: an OpenFGA error while
     * probing `editor` on one type suppresses only that type's `editor` entries
     * — the same type's `admin` entries and all other types are unaffected.
     * Each failure is logged with the type and the relation that failed.
     *
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @return array{editor: list<array{object_type: string, object_id: string}>, admin: list<array{object_type: string, object_id: string}>}
     */
    public function resolveTestScopes(string $sub): array
    {
        $fgaUser = "user:{$sub}";
        $editor  = [];
        $admin   = [];

        foreach (self::TEST_OBJECT_TYPES as $type) {
            foreach ($this->listObjectsIsolated($fgaUser, 'editor', $type) as $objectId) {
                $editor[] = ['object_type' => $type, 'object_id' => $objectId];
            }
        }
        foreach (self::TEST_OBJECT_TYPES as $type) {
            foreach ($this->listObjectsIsolated($fgaUser, 'admin', $type) as $objectId) {
                $admin[] = ['object_type' => $type, 'object_id' => $objectId];
            }
        }

        return ['editor' => $editor, 'admin' => $admin];
    }

    /**
     * Object IDs the caller can view (viewer-or-above), keyed by object type,
     * across VIEWER_OBJECT_TYPES. Every key is always present.
     *
     * Fails closed per object type: an OpenFGA error on one type yields an empty
     * list for that key — never a missing key — and is logged, while every other
     * type keeps the IDs it resolved.
     *
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @return array<string, array<int, string>>
     */
    public function resolveViewerScopes(string $sub): array
    {
        $fgaUser = "user:{$sub}";
        $scopes  = array_fill_keys(self::VIEWER_OBJECT_TYPES, []);

        foreach (self::VIEWER_OBJECT_TYPES as $type) {
            $scopes[$type] = $this->listObjectsIsolated($fgaUser, 'viewer', $type);
        }

        return $scopes;
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
            } catch (\RuntimeException $e) {
                // Fail closed for THIS request only — a transient OpenFGA failure
                // excludes the request rather than surfacing a 500. Mirrors the
                // per-unit isolation of listObjectsIsolated().
                $this->logFailure(
                    sprintf('OpenFGA check failed while filtering access requests by admin access: %s', $e->getMessage()),
                    [
                        'user'  => $fgaUser,
                        'error' => $e->getMessage(),
                    ]
                );
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
