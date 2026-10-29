<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use LiturgicalCalendar\Api\Services\ViewerRetirementPlan;
use LiturgicalCalendar\Api\Services\ViewerRetirementSweep;
use LiturgicalCalendar\Api\Services\ZitadelService;
use LiturgicalCalendar\Tests\Support\MockOpenFgaClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * DB-free tests for the sweep that retires `viewer` tuples held by people (API #1049).
 *
 * OpenFGA is a small in-memory store behind {@see MockOpenFgaClientTrait}: every queued response is the same
 * router, which answers read / list-objects / write from `$this->store`. Repositories, Zitadel and the role cascade
 * are PHPUnit mocks over in-memory state, and every collaborator call is appended to `$this->events` so a test can
 * assert the order in which apply() does things.
 */
#[CoversClass(ViewerRetirementSweep::class)]
#[CoversClass(ViewerRetirementPlan::class)]
final class ViewerRetirementSweepTest extends TestCase
{
    use MockOpenFgaClientTrait;

    private const string REVIEWER = 'system:viewer-retirement';
    private const string NOTE     = 'Read access now comes with the role. Please request editor or admin access on the calendars you want to work on.';

    /** @var list<array{user: string, relation: string, object: string}> */
    private array $store = [];

    /** Page size the fake store uses regardless of what the client asks for (0 = honour the request). */
    private int $forcedPageSize = 0;

    private bool $failDeletes = false;

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    /** @var array<string, list<string>> */
    private array $roles = [];

    /** @var list<string> */
    private array $events = [];

    /** @var list<array<string, mixed>> */
    private array $readPayloads = [];

    /** @var list<array{user: ?string, action: string, type: string, id: ?string, details: ?array<string, mixed>}> */
    private array $audit = [];

    /** @var list<array<string, mixed>> */
    private array $outboxRows = [];

    // ------------------------------------------------------------------
    // plan()
    // ------------------------------------------------------------------

    public function testPlanFollowsContinuationTokens(): void
    {
        $this->forcedPageSize = 2;
        $this->store          = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u2', 'editor', 'national_calendar:FR'),
            self::tuple('user:u3', 'viewer', 'diocesan_calendar:romamo_it'),
            self::tuple('user:u4', 'viewer', 'wider_region:europe'),
        ];

        $plan = $this->sweep()->plan();

        self::assertSame([
            ['user' => 'user:u1', 'object' => 'national_calendar:IT'],
            ['user' => 'user:u3', 'object' => 'diocesan_calendar:romamo_it'],
            ['user' => 'user:u4', 'object' => 'wider_region:europe'],
        ], $plan->viewerTuples);

        // Two pages were read, the second with the first page's token, and neither sent a tuple_key:
        // OpenFGA Read rejects a relation-only key, so the sweep reads the whole store and filters.
        self::assertCount(2, $this->readPayloads);
        self::assertArrayNotHasKey('continuation_token', $this->readPayloads[0]);
        self::assertSame('2', $this->readPayloads[1]['continuation_token'] ?? null);
        foreach ($this->readPayloads as $payload) {
            self::assertArrayNotHasKey('tuple_key', $payload);
        }
    }

    public function testPlanIgnoresNonUserSubjects(): void
    {
        $this->store = [
            self::tuple('application:x', 'viewer', 'national_calendar:IT'),
            self::tuple('user:*', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u1', 'viewer', 'national_calendar:FR'),
        ];

        $plan = $this->sweep()->plan();

        self::assertSame([['user' => 'user:u1', 'object' => 'national_calendar:FR']], $plan->viewerTuples);
    }

    public function testPlanRewritesMixedPendingRequest(): void
    {
        $this->pending = [
            self::request('r1', 'u1', 'calendar_editor', [
                self::perm('national_calendar', 'IT', 'viewer'),
                self::perm('national_calendar', 'FR', 'editor'),
            ]),
            self::request('r2', 'u2', 'calendar_editor', [self::perm('national_calendar', 'DE', 'editor')]),
        ];

        $plan = $this->sweep()->plan();

        self::assertCount(1, $plan->pendingRequests);
        $entry = $plan->pendingRequests[0];
        self::assertSame('r1', $entry['id']);
        self::assertSame('u1', $entry['user']);
        self::assertSame('rewrite', $entry['action']);
        self::assertSame([self::perm('national_calendar', 'FR', 'editor')], $entry['keep']);
        self::assertSame([self::perm('national_calendar', 'IT', 'viewer')], $entry['removed']);
    }

    public function testPlanRejectsViewerOnlyPendingRequest(): void
    {
        $this->pending = [
            self::request('r1', 'u1', 'test_editor', [
                self::perm('national_calendar_test', 'IT', 'viewer'),
                self::perm('diocesan_calendar_test', 'romamo_it', 'viewer'),
            ]),
        ];

        $plan = $this->sweep()->plan();

        self::assertCount(1, $plan->pendingRequests);
        self::assertSame('reject', $plan->pendingRequests[0]['action']);
        self::assertSame([], $plan->pendingRequests[0]['keep']);
    }

    public function testPlanKeepsRoleBackedByEditor(): void
    {
        $this->store = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u1', 'editor', 'national_calendar:FR'),
        ];
        $this->roles = ['u1' => ['calendar_editor']];

        $plan = $this->sweep()->plan();

        self::assertCount(1, $plan->viewerTuples);
        self::assertSame([], $plan->roleRevocations);
    }

    public function testPlanRevokesViewerOnlyRole(): void
    {
        $this->store = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u2', 'viewer', 'national_calendar_test:IT'),
        ];
        $this->roles = [
            // calendar_editor is held only through the viewer tuple; the global admin role is not a scoped role.
            'u1' => ['calendar_editor', 'admin'],
            // developer has no tuple left once its viewer tuple goes.
            'u2' => ['developer'],
        ];

        $plan = $this->sweep()->plan();

        self::assertSame([
            ['user' => 'u1', 'role' => 'calendar_editor'],
            ['user' => 'u2', 'role' => 'developer'],
        ], $plan->roleRevocations);
    }

    public function testPlanKeepsDeveloperRoleBackedByAnotherTuple(): void
    {
        $this->store = [
            self::tuple('user:u1', 'viewer', 'national_calendar_test:IT'),
            self::tuple('user:u1', 'admin', 'diocesan_calendar:romamo_it'),
        ];
        $this->roles = ['u1' => ['developer']];

        self::assertSame([], $this->sweep()->plan()->roleRevocations);
    }

    public function testPlanLeavesRolesWhoseScopeHadNoViewerTuple(): void
    {
        // The viewer tuple is in test scope; calendar_editor (perhaps assigned directly, with no tuples yet) is not
        // held through viewer, so the sweep must not touch it.
        $this->store = [self::tuple('user:u1', 'viewer', 'national_calendar_test:IT')];
        $this->roles = ['u1' => ['calendar_editor', 'test_editor']];

        self::assertSame(
            [['user' => 'u1', 'role' => 'test_editor']],
            $this->sweep()->plan()->roleRevocations
        );
    }

    public function testPlanMakesNoWrites(): void
    {
        $this->store   = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->pending = [
            self::request('r1', 'u1', 'calendar_editor', [self::perm('national_calendar', 'IT', 'viewer')]),
        ];
        $this->roles   = ['u1' => ['calendar_editor']];

        $requests = $this->createMock(AccessRequestRepository::class);
        $requests->method('getPending')->willReturnCallback(fn (): array => $this->pending);
        $requests->expects($this->never())->method('replacePendingPermissions');
        $requests->expects($this->never())->method('reject');
        $requests->expects($this->never())->method('approve');
        $requests->expects($this->never())->method('revoke');
        $requests->expects($this->never())->method('cascadeRevokeByRole');

        $zitadel = $this->createMock(ZitadelService::class);
        $zitadel->method('getUserRoles')->willReturnCallback(fn (string $u): array => $this->roles[$u] ?? []);
        $zitadel->expects($this->never())->method('revokeUserRole');
        $zitadel->expects($this->never())->method('revokeGrant');

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects($this->never())->method('maybeCascadeRoleRevoke');
        $cascade->expects($this->never())->method('cascadeTupleRevokeForRole');

        $audit = $this->createMock(AuditLogRepository::class);
        $audit->expects($this->never())->method('log');

        $outbox = $this->createMock(OutboxBatchInsertInterface::class);
        $outbox->expects($this->never())->method('insertBatch');

        $plan = new ViewerRetirementSweep(
            $this->fakeFga(),
            $zitadel,
            $requests,
            $cascade,
            $audit,
            $outbox,
            new OutboxNotifier(null, 'litcal:test-stream'),
        )->plan();

        self::assertFalse($plan->isEmpty());
        self::assertNotContains('fga:write', array_map(static fn (string $e): string => explode(' ', $e)[0], $this->events));
        self::assertCount(1, $this->store);
    }

    // ------------------------------------------------------------------
    // apply()
    // ------------------------------------------------------------------

    public function testApplyDeletesRewritesRejectsAndCascades(): void
    {
        $this->store   = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u1', 'editor', 'national_calendar:FR'),
            self::tuple('user:u2', 'viewer', 'national_calendar_test:IT'),
        ];
        $this->pending = [
            self::request('r1', 'u1', 'calendar_editor', [
                self::perm('national_calendar', 'DE', 'viewer'),
                self::perm('national_calendar', 'DE', 'editor'),
            ]),
            self::request('r2', 'u2', 'test_editor', [self::perm('national_calendar_test', 'FR', 'viewer')]),
        ];
        $this->roles   = ['u1' => ['calendar_editor'], 'u2' => ['test_editor']];

        $sweep  = $this->sweep();
        $plan   = $sweep->plan();
        $counts = $sweep->apply($plan);

        $writes = array_values(array_filter(
            $this->events,
            static fn (string $e): bool => !str_starts_with($e, 'fga:read') && !str_starts_with($e, 'fga:list')
                && !str_starts_with($e, 'zitadel:getUserRoles') && !str_starts_with($e, 'repo:getPending')
        ));
        self::assertSame([
            'fga:write delete user:u1#viewer@national_calendar:IT',
            'audit viewer_retirement_delete_tuple national_calendar:IT',
            'fga:write delete user:u2#viewer@national_calendar_test:IT',
            'audit viewer_retirement_delete_tuple national_calendar_test:IT',
            'repo:replacePendingPermissions r1',
            'audit viewer_retirement_rewrite_request r1',
            'repo:reject r2 ' . self::REVIEWER . ' ' . self::NOTE,
            'audit viewer_retirement_reject_request r2',
            'cascade:maybeCascadeRoleRevoke u2 test_editor',
            'audit viewer_retirement_revoke_role u2',
        ], $writes);

        self::assertSame([self::perm('national_calendar', 'DE', 'editor')], $this->pending[0]['permissions']);
        self::assertSame('rejected', $this->pending[1]['status']);

        foreach ($this->audit as $entry) {
            self::assertSame(self::REVIEWER, $entry['user']);
        }

        self::assertSame(2, $counts['tuples_deleted']);
        self::assertSame(1, $counts['requests_rewritten']);
        self::assertSame(1, $counts['requests_rejected']);
        self::assertSame(1, $counts['roles_revoked']);
        self::assertSame(0, $counts['tuples_enqueued']);
        self::assertSame([self::tuple('user:u1', 'editor', 'national_calendar:FR')], $this->store);
    }

    public function testApplyEnqueuesOutboxOnDeleteFailure(): void
    {
        $this->store       = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->failDeletes = true;

        $sweep  = $this->sweep();
        $counts = $sweep->apply($sweep->plan());

        self::assertCount(1, $this->outboxRows);
        $row = $this->outboxRows[0];
        self::assertSame(OutboxOperation::DELETE_TUPLE, $row['operation']);
        self::assertSame('user:u1', $row['fga_user']);
        self::assertSame('viewer', $row['fga_relation']);
        self::assertSame('national_calendar:IT', $row['fga_object']);
        self::assertIsString($row['idempotency_key']);
        self::assertStringStartsWith('viewer_retirement:', $row['idempotency_key']);

        self::assertSame(0, $counts['tuples_deleted']);
        self::assertSame(1, $counts['tuples_enqueued']);
        self::assertCount(1, $this->audit);
        self::assertSame('enqueued', $this->audit[0]['details']['outcome'] ?? null);
    }

    public function testApplyCountsAnAlreadyDeletedTupleAsDone(): void
    {
        $this->store = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];

        $sweep = $this->sweep();
        $plan  = $sweep->plan();
        // Someone else removes the tuple between plan and apply: OpenFGA answers "unknown tuple".
        $this->store = [];
        $counts      = $sweep->apply($plan);

        self::assertSame(1, $counts['tuples_already_gone']);
        self::assertSame([], $this->outboxRows);
        self::assertSame([], $this->audit);
    }

    public function testApplyIsIdempotent(): void
    {
        $this->store   = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u2', 'viewer', 'national_calendar_test:IT'),
        ];
        $this->pending = [
            self::request('r1', 'u1', 'calendar_editor', [
                self::perm('national_calendar', 'IT', 'viewer'),
                self::perm('national_calendar', 'FR', 'editor'),
            ]),
            self::request('r2', 'u2', 'test_editor', [self::perm('national_calendar_test', 'FR', 'viewer')]),
        ];
        $this->roles   = ['u1' => ['calendar_editor'], 'u2' => ['developer']];

        $sweep = $this->sweep();
        $sweep->apply($sweep->plan());

        $second = $sweep->plan();
        self::assertTrue($second->isEmpty());
        self::assertSame([], $second->viewerTuples);
        self::assertSame([], $second->pendingRequests);
        self::assertSame([], $second->roleRevocations);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function sweep(): ViewerRetirementSweep
    {
        $requests = $this->createStub(AccessRequestRepository::class);
        $requests->method('getPending')->willReturnCallback(function (): array {
            $this->events[] = 'repo:getPending';
            return array_values(array_filter($this->pending, static fn (array $r): bool => $r['status'] === 'pending'));
        });
        $requests->method('replacePendingPermissions')->willReturnCallback(function (string $id, array $perms): bool {
            $this->events[] = "repo:replacePendingPermissions {$id}";
            foreach ($this->pending as $i => $row) {
                if ($row['id'] === $id && $row['status'] === 'pending') {
                    $this->pending[$i]['permissions'] = $perms;
                    return true;
                }
            }
            return false;
        });
        $requests->method('reject')->willReturnCallback(function (string $id, string $by, ?string $notes): bool {
            $this->events[] = "repo:reject {$id} {$by} {$notes}";
            foreach ($this->pending as $i => $row) {
                if ($row['id'] === $id && $row['status'] === 'pending') {
                    $this->pending[$i]['status'] = 'rejected';
                    return true;
                }
            }
            return false;
        });

        $zitadel = $this->createStub(ZitadelService::class);
        $zitadel->method('getUserRoles')->willReturnCallback(function (string $u): array {
            $this->events[] = "zitadel:getUserRoles {$u}";
            return $this->roles[$u] ?? [];
        });

        $cascade = $this->createStub(RoleCascadeService::class);
        $cascade->method('maybeCascadeRoleRevoke')->willReturnCallback(function (string $u, string $role): bool {
            $this->events[]  = "cascade:maybeCascadeRoleRevoke {$u} {$role}";
            $this->roles[$u] = array_values(array_diff($this->roles[$u] ?? [], [$role]));
            return true;
        });

        $audit = $this->createStub(AuditLogRepository::class);
        $audit->method('log')->willReturnCallback(
            function (?string $user, string $action, string $type, ?string $id = null, ?array $details = null): string {
                $this->events[] = "audit {$action} {$id}";
                /** @var array<string, mixed>|null $details */
                $this->audit[] = ['user' => $user, 'action' => $action, 'type' => $type, 'id' => $id, 'details' => $details];
                return 'audit-id';
            }
        );

        $outbox = $this->createStub(OutboxBatchInsertInterface::class);
        $outbox->method('insertBatch')->willReturnCallback(function (array $rows): array {
            $ids = [];
            foreach ($rows as $row) {
                /** @var array<string, mixed> $row */
                $this->outboxRows[] = $row;
                $ids[]              = count($this->outboxRows);
            }
            return $ids;
        });

        return new ViewerRetirementSweep(
            $this->fakeFga(),
            $zitadel,
            $requests,
            $cascade,
            $audit,
            $outbox,
            // OutboxNotifier is final; with no Redis it is a no-op, so the real one is used.
            new OutboxNotifier(null, 'litcal:test-stream'),
        );
    }

    /**
     * An OpenFgaClient backed by `$this->store`. `viewer` and `editor` are computed to include `admin`, as in the
     * model (authz/openfga-expectations.json).
     */
    private function fakeFga(): \LiturgicalCalendar\Api\Services\OpenFgaClient
    {
        $router = function (RequestInterface $request): GuzzleResponse {
            $path = $request->getUri()->getPath();
            /** @var array<string, mixed> $body */
            $body = json_decode((string) $request->getBody(), true) ?: [];

            if (str_ends_with($path, '/read')) {
                $this->events[]       = 'fga:read';
                $this->readPayloads[] = $body;
                $offset               = is_string($body['continuation_token'] ?? null) ? (int) $body['continuation_token'] : 0;
                $size                 = $this->forcedPageSize > 0
                    ? $this->forcedPageSize
                    : ( is_int($body['page_size'] ?? null) ? $body['page_size'] : 50 );
                $slice                = array_slice($this->store, $offset, $size);
                $next                 = $offset + $size < count($this->store) ? (string) ( $offset + $size ) : '';
                return self::json([
                    'tuples'             => array_map(static fn (array $t): array => ['key' => $t], $slice),
                    'continuation_token' => $next,
                ]);
            }

            if (str_ends_with($path, '/list-objects')) {
                $user           = is_string($body['user'] ?? null) ? $body['user'] : '';
                $relation       = is_string($body['relation'] ?? null) ? $body['relation'] : '';
                $type           = is_string($body['type'] ?? null) ? $body['type'] : '';
                $this->events[] = "fga:list {$user} {$relation} {$type}";
                $objects        = [];
                foreach ($this->store as $t) {
                    $matches = $t['relation'] === $relation || ( $relation !== 'admin' && $t['relation'] === 'admin' );
                    if ($t['user'] === $user && $matches && str_starts_with($t['object'], $type . ':')) {
                        $objects[] = $t['object'];
                    }
                }
                return self::json(['objects' => array_values(array_unique($objects))]);
            }

            if (str_ends_with($path, '/write')) {
                /** @var array{user: string, relation: string, object: string} $key */
                $key            = $body['deletes']['tuple_keys'][0] ?? []; // @phpstan-ignore offsetAccess.nonOffsetAccessible
                $this->events[] = "fga:write delete {$key['user']}#{$key['relation']}@{$key['object']}";
                if ($this->failDeletes) {
                    return self::json(['code' => 'unavailable', 'message' => 'Service Unavailable'], 503);
                }
                $index = array_search($key, $this->store, true);
                if ($index === false) {
                    return self::json([
                        'code'    => 'cannot_allow_unknown_tuple_to_be_deleted',
                        'message' => 'cannot delete a tuple which does not exist',
                    ], 400);
                }
                array_splice($this->store, $index, 1);
                return self::json([]);
            }

            self::fail("Unexpected OpenFGA call: {$path}");
        };

        return $this->mockOpenFgaClient(array_fill(0, 500, $router));
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status = 200): GuzzleResponse
    {
        return new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode($body) ?: '{}');
    }

    /**
     * @return array{user: string, relation: string, object: string}
     */
    private static function tuple(string $user, string $relation, string $object): array
    {
        return ['user' => $user, 'relation' => $relation, 'object' => $object];
    }

    /**
     * @return array{object_type: string, object_id: string, relation: string}
     */
    private static function perm(string $type, string $id, string $relation): array
    {
        return ['object_type' => $type, 'object_id' => $id, 'relation' => $relation];
    }

    /**
     * @param list<array{object_type: string, object_id: string, relation: string}> $permissions
     * @return array<string, mixed>
     */
    private static function request(string $id, string $user, string $role, array $permissions): array
    {
        return [
            'id'              => $id,
            'zitadel_user_id' => $user,
            'requested_role'  => $role,
            'permissions'     => $permissions,
            'status'          => 'pending',
        ];
    }
}
