<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Repositories\OutboxPendingWritesInterface;
use LiturgicalCalendar\Api\Services\Exception\ViewerRetirementAbortedException;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use LiturgicalCalendar\Api\Services\ViewerRetirementCli;
use LiturgicalCalendar\Api\Services\ViewerRetirementPlan;
use LiturgicalCalendar\Api\Services\ViewerRetirementSweep;
use LiturgicalCalendar\Api\Services\ZitadelService;
use LiturgicalCalendar\Tests\Support\MockOpenFgaClientTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

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
#[CoversClass(ViewerRetirementCli::class)]
#[CoversClass(ViewerRetirementAbortedException::class)]
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

    /** The cascade answers true but Zitadel kept the role (its revoke swallowed a failure). */
    private bool $zitadelKeepsRoles = false;

    /** The strict Zitadel role read throws once this many reads have succeeded (-1 = never). */
    private int $roleReadsBeforeFailure = -1;

    /** replacePendingPermissions() throws. */
    private bool $rewriteThrows = false;

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
        $zitadel->method('getUserRolesOrFail')->willReturnCallback(fn (string $u): array => $this->roles[$u] ?? []);
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
        $counts = $sweep->apply($plan)['counts'];

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
        $counts = $sweep->apply($sweep->plan())['counts'];

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
        $counts      = $sweep->apply($plan)['counts'];

        self::assertSame(1, $counts['tuples_already_gone']);
        self::assertSame([], $this->outboxRows);
        self::assertSame([], $this->audit);
    }

    public function testApplyReportsRoleStillHeldAfterCascadeAsFailed(): void
    {
        $this->store             = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->roles             = ['u1' => ['calendar_editor']];
        $this->zitadelKeepsRoles = true;

        $sweep  = $this->sweep();
        $result = $sweep->apply($sweep->plan());

        self::assertSame(1, $result['counts']['roles_failed']);
        self::assertSame(0, $result['counts']['roles_revoked']);
        self::assertSame(
            [
                [
                    'user'    => 'u1',
                    'role'    => 'calendar_editor',
                    'outcome' => 'failed',
                    'reason'  => 'Zitadel still lists the role after the cascade',
                ]
            ],
            $result['unrealised_revocations']
        );
        self::assertSame(2, ViewerRetirementSweep::exitCode($result));

        $roleAudit = array_values(array_filter(
            $this->audit,
            static fn (array $a): bool => $a['action'] === 'viewer_retirement_revoke_role'
        ));
        self::assertCount(1, $roleAudit);
        self::assertSame('failed', $roleAudit[0]['details']['outcome'] ?? null);
        self::assertSame('Zitadel still lists the role after the cascade', $roleAudit[0]['details']['reason'] ?? null);
    }

    public function testApplyDefersRoleWhoseViewerDeleteWasEnqueued(): void
    {
        $this->store       = [self::tuple('user:u1', 'viewer', 'national_calendar_test:IT')];
        $this->roles       = ['u1' => ['developer']];
        $this->failDeletes = true;

        $sweep = $this->sweep();
        $plan  = $sweep->plan();
        self::assertSame([['user' => 'u1', 'role' => 'developer']], $plan->roleRevocations);

        $result = $sweep->apply($plan);

        self::assertNotContains('cascade:maybeCascadeRoleRevoke u1 developer', $this->events);
        self::assertSame(1, $result['counts']['roles_deferred']);
        self::assertSame(
            [
                [
                    'user'    => 'u1',
                    'role'    => 'developer',
                    'outcome' => 'deferred',
                    'reason'  => 'a viewer delete in scope was enqueued or failed',
                ]
            ],
            $result['unrealised_revocations']
        );
        self::assertSame(2, ViewerRetirementSweep::exitCode($result));

        // A deferral is audited with its outcome and reason, as a failure is.
        $roleAudit = $this->roleAudit();
        self::assertCount(1, $roleAudit);
        self::assertSame('deferred', $roleAudit[0]['details']['outcome'] ?? null);
        self::assertSame('a viewer delete in scope was enqueued or failed', $roleAudit[0]['details']['reason'] ?? null);
    }

    public function testApplyCascadesAnEditorRoleEvenWhenItsViewerDeleteWasEnqueued(): void
    {
        // A viewer tuple never justifies calendar_editor, so a viewer delete still queued does not hold the role up:
        // the cascade runs, and deletes leftover viewers itself.
        $this->store       = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->roles       = ['u1' => ['calendar_editor']];
        $this->failDeletes = true;

        $sweep  = $this->sweep();
        $result = $sweep->apply($sweep->plan());

        self::assertContains('cascade:maybeCascadeRoleRevoke u1 calendar_editor', $this->events);
        self::assertSame(0, $result['counts']['roles_deferred']);
        self::assertSame(1, $result['counts']['roles_revoked']);
        self::assertSame([], $result['unrealised_revocations']);
    }

    public function testApplyAuditsAKeptRoleWithItsReason(): void
    {
        $this->store = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->roles = ['u1' => ['calendar_editor']];

        $cascade = $this->createStub(RoleCascadeService::class);
        $cascade->method('maybeCascadeRoleRevoke')->willReturn(false);

        $sweep  = $this->sweep(cascade: $cascade);
        $result = $sweep->apply($sweep->plan());

        self::assertSame(1, $result['counts']['roles_kept']);
        self::assertSame('kept', $result['unrealised_revocations'][0]['outcome'] ?? null);
        $roleAudit = $this->roleAudit();
        self::assertCount(1, $roleAudit);
        self::assertSame('kept', $roleAudit[0]['details']['outcome'] ?? null);
        self::assertIsString($roleAudit[0]['details']['reason'] ?? null);
    }

    public function testApplyReportsAFailedRoleReReadAsFailed(): void
    {
        $this->store = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->roles = ['u1' => ['calendar_editor']];

        $sweep = $this->sweep();
        $plan  = $sweep->plan();
        // The cascade runs, then the read-back fails: that must not pass for "the role is gone".
        $this->roleReadsBeforeFailure = 0;
        $result                       = $sweep->apply($plan);

        self::assertSame(0, $result['counts']['roles_revoked']);
        self::assertSame(1, $result['counts']['roles_failed']);
        self::assertCount(1, $result['unrealised_revocations']);
        $entry = $result['unrealised_revocations'][0];
        self::assertSame('failed', $entry['outcome']);
        self::assertStringContainsString('could not re-read the Zitadel roles', $entry['reason']);
        self::assertStringContainsString('HTTP 401', $entry['reason']);
        self::assertSame(2, ViewerRetirementSweep::exitCode($result));
        self::assertSame('failed', $this->roleAudit()[0]['details']['outcome'] ?? null);
    }

    public function testApplyGoesOnToTheRolePhaseWhenARequestChangeThrows(): void
    {
        $this->store         = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->roles         = ['u1' => ['calendar_editor']];
        $this->pending       = [
            self::request('r1', 'u1', 'calendar_editor', [
                self::perm('national_calendar', 'IT', 'viewer'),
                self::perm('national_calendar', 'FR', 'editor'),
            ]),
            self::request('r2', 'u2', 'calendar_editor', [self::perm('national_calendar', 'DE', 'viewer')]),
        ];
        $this->rewriteThrows = true;

        $sweep  = $this->sweep();
        $result = $sweep->apply($sweep->plan());

        self::assertSame(1, $result['counts']['requests_failed']);
        self::assertSame(1, $result['counts']['requests_rejected'], 'the next request is still handled');
        self::assertContains('cascade:maybeCascadeRoleRevoke u1 calendar_editor', $this->events);
        self::assertSame(1, $result['counts']['roles_revoked']);
        self::assertSame(2, ViewerRetirementSweep::exitCode($result));
    }

    public function testApplyThatStopsEarlyReportsEveryRevocationNotCarriedOut(): void
    {
        $this->store = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u2', 'viewer', 'national_calendar:FR'),
        ];
        $this->roles = ['u1' => ['calendar_editor'], 'u2' => ['calendar_editor']];

        $cascade = $this->createStub(RoleCascadeService::class);
        $cascade->method('maybeCascadeRoleRevoke')->willReturn(false); // kept → unrealised() → the logger throws

        $sweep = $this->sweep(cascade: $cascade, logger: self::throwingLogger('planned role revocation not carried out'));
        $plan  = $sweep->plan();

        try {
            $sweep->apply($plan);
            self::fail('apply() must surface the failure');
        } catch (ViewerRetirementAbortedException $e) {
            self::assertSame(2, $e->partial['counts']['tuples_deleted']);
            self::assertSame(
                [['u1', 'failed'], ['u2', 'failed']],
                array_map(static fn (array $u): array => [$u['user'], $u['outcome']], $e->partial['unrealised_revocations'])
            );
            self::assertStringContainsString('the sweep stopped early', $e->partial['unrealised_revocations'][0]['reason']);
        }

        // The script path prints what was done and what was not, then exits 1.
        [$code, $out, $err] = $this->runCli(
            $this->sweep(cascade: $cascade, logger: self::throwingLogger('planned role revocation not carried out')),
            true,
            [self::tuple('user:u1', 'viewer', 'national_calendar:IT')],
        );
        self::assertSame(1, $code);
        self::assertStringContainsString('sweep stopped early', $err);
        self::assertStringContainsString('tuples_deleted', $out);
        self::assertStringContainsString('Planned role revocations NOT carried out (1):', $out);
        self::assertStringContainsString('[FAILED] user u1: calendar_editor — not carried out: the sweep stopped early', $out);
    }

    // ------------------------------------------------------------------
    // Zitadel role reads, the outbox, and the script's output
    // ------------------------------------------------------------------

    public function testPlanAbortsWhenZitadelRolesCannotBeRead(): void
    {
        $this->store = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];

        // A real ZitadelService whose token is stale: the roles search answers 401.
        $zitadel = new ZitadelService(
            'https://zitadel.test',
            'project-123',
            'stale-token',
            null,
            null,
            new GuzzleClient([
                'handler'  => HandlerStack::create(new MockHandler([
                    new GuzzleResponse(401, [], '{"message":"Errors.Token.Invalid"}'),
                    new GuzzleResponse(401, [], '{"message":"Errors.Token.Invalid"}'),
                ])),
                'base_uri' => 'https://zitadel.test',
            ])
        );

        try {
            $this->sweep(zitadel: $zitadel)->plan();
            self::fail('A 401 must not pass for "no roles"');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Could not read the Zitadel roles of user u1: HTTP 401', $e->getMessage());
        }

        [$code, $out, $err] = $this->runCli($this->sweep(zitadel: $zitadel), false);
        self::assertSame(1, $code);
        self::assertStringContainsString('the plan could not be built, so nothing was changed', $err);
        self::assertStringContainsString('HTTP 401', $err);
        self::assertStringNotContainsString('Dry run', $out);
    }

    public function testPlanSkipsAUserWithAQueuedEditorWrite(): void
    {
        // u1's editor grant is still in the outbox: the cascade would keep the role, so the plan must not list it.
        $this->store = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u2', 'viewer', 'national_calendar:FR'),
        ];
        $this->roles = ['u1' => ['calendar_editor'], 'u2' => ['calendar_editor']];

        $pendingWrites = $this->createMock(OutboxPendingWritesInterface::class);
        $pendingWrites->method('listPendingPeopleViewerWrites')->willReturn([]);
        $pendingWrites->expects($this->exactly(2))->method('hasPendingWrite')->willReturnCallback(
            static function (string $user, array $relations, array $types): bool {
                self::assertSame(['admin', 'editor'], $relations);
                self::assertContains('national_calendar', $types);
                return $user === 'user:u1';
            }
        );

        $plan = $this->sweep(pendingWrites: $pendingWrites)->plan();

        self::assertSame([['user' => 'u2', 'role' => 'calendar_editor']], $plan->roleRevocations);
    }

    public function testDryRunListsRolesReadAndQueuedViewerWrites(): void
    {
        $this->store   = [
            self::tuple('user:u1', 'viewer', 'national_calendar:IT'),
            self::tuple('user:u2', 'viewer', 'national_calendar_test:IT'),
        ];
        $this->roles   = ['u1' => ['calendar_editor', 'admin']];
        $this->pending = [
            self::request('r1', 'u1', 'calendar_editor', [
                self::perm('national_calendar', 'IT', 'viewer'),
                self::perm('national_calendar', 'FR', 'editor'),
            ]),
        ];

        $pendingWrites = $this->createStub(OutboxPendingWritesInterface::class);
        $pendingWrites->method('hasPendingWrite')->willReturn(false);
        $pendingWrites->method('listPendingPeopleViewerWrites')->willReturn([
            ['id' => 42, 'user' => 'user:u3', 'object' => 'diocesan_calendar:roman/romamo_it', 'status' => 'retrying'],
        ]);

        $sweep = $this->sweep(pendingWrites: $pendingWrites);
        $plan  = $sweep->plan();
        self::assertSame(['u1' => ['calendar_editor', 'admin'], 'u2' => []], $plan->rolesRead);
        self::assertCount(1, $plan->queuedViewerWrites);

        [$code, $out] = $this->runCli($sweep, false);

        self::assertSame(0, $code);
        self::assertStringContainsString('  - user u1: 2 role(s) — calendar_editor, admin', $out);
        self::assertStringContainsString('  - user u2: 0 role(s)', $out);
        self::assertStringContainsString('Viewer writes for people still queued in the outbox (1)', $out);
        self::assertStringContainsString('outbox row 42 [retrying]: user:u3 viewer diocesan_calendar:roman/romamo_it', $out);
        self::assertStringContainsString('Dry run: nothing was changed.', $out);
    }

    public function testApplyPrintsTheReasonOfEveryRevocationNotCarriedOut(): void
    {
        $this->store       = [self::tuple('user:u1', 'viewer', 'national_calendar_test:IT')];
        $this->roles       = ['u1' => ['developer']];
        $this->failDeletes = true;

        [$code, $out] = $this->runCli($this->sweep(), true);

        self::assertSame(2, $code);
        self::assertStringContainsString(
            '  - [DEFERRED] user u1: developer — a viewer delete in scope was enqueued or failed',
            $out
        );
    }

    public function testApplyWithEverythingDoneExitsZero(): void
    {
        $this->store = [self::tuple('user:u1', 'viewer', 'national_calendar:IT')];
        $this->roles = ['u1' => ['calendar_editor']];

        $sweep  = $this->sweep();
        $result = $sweep->apply($sweep->plan());

        self::assertSame([], $result['unrealised_revocations']);
        self::assertSame(0, ViewerRetirementSweep::exitCode($result));
    }

    public function testPlanKeepsMalformedPermissionEntries(): void
    {
        $malformed                         = ['object_type' => 'national_calendar', 'relation' => 'editor'];
        $this->pending                     = [
            self::request('r1', 'u1', 'calendar_editor', [self::perm('national_calendar', 'IT', 'viewer')]),
        ];
        $this->pending[0]['permissions'][] = $malformed;

        $sweep = $this->sweep();
        $plan  = $sweep->plan();

        self::assertCount(1, $plan->pendingRequests);
        self::assertSame('rewrite', $plan->pendingRequests[0]['action']);
        self::assertSame([$malformed], $plan->pendingRequests[0]['keep']);

        $sweep->apply($plan);
        self::assertSame([$malformed], $this->pending[0]['permissions']);
        self::assertSame('pending', $this->pending[0]['status']);
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

    /**
     * @return list<array{user: ?string, action: string, type: string, id: ?string, details: ?array<string, mixed>}>
     */
    private function roleAudit(): array
    {
        return array_values(array_filter(
            $this->audit,
            static fn (array $a): bool => $a['action'] === 'viewer_retirement_revoke_role'
        ));
    }

    /**
     * Run the script's CLI over a sweep. With $store, the fake store is reset to it first, so a fresh run sees it.
     *
     * @param list<array{user: string, relation: string, object: string}>|null $store
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runCli(ViewerRetirementSweep $sweep, bool $apply, ?array $store = null): array
    {
        if ($store !== null) {
            $this->store = $store;
        }
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = ( new ViewerRetirementCli($sweep, $out, $err) )->run($apply);

        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /**
     * A logger that throws when a message containing $needle is logged: something apply() does not guard.
     */
    private static function throwingLogger(string $needle): LoggerInterface
    {
        return new class ($needle) extends AbstractLogger {
            public function __construct(private readonly string $needle)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (str_contains((string) $message, $this->needle)) {
                    throw new \RuntimeException('log sink is gone');
                }
            }
        };
    }

    private function sweep(
        ?RoleCascadeService $cascade = null,
        ?ZitadelService $zitadel = null,
        ?LoggerInterface $logger = null,
        ?OutboxPendingWritesInterface $pendingWrites = null,
    ): ViewerRetirementSweep {
        $requests = $this->createStub(AccessRequestRepository::class);
        $requests->method('getPending')->willReturnCallback(function (): array {
            $this->events[] = 'repo:getPending';
            return array_values(array_filter($this->pending, static fn (array $r): bool => $r['status'] === 'pending'));
        });
        $requests->method('replacePendingPermissions')->willReturnCallback(function (string $id, array $perms): bool {
            $this->events[] = "repo:replacePendingPermissions {$id}";
            if ($this->rewriteThrows) {
                throw new \PDOException('SQLSTATE[08006]: connection lost');
            }
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

        if ($zitadel === null) {
            $zitadel = $this->createStub(ZitadelService::class);
            $zitadel->method('getUserRoles')->willReturnCallback(static fn (): array => self::fail('the sweep must use the strict read'));
            $zitadel->method('getUserRolesOrFail')->willReturnCallback(function (string $u): array {
                $this->events[] = "zitadel:getUserRoles {$u}";
                if ($this->roleReadsBeforeFailure === 0) {
                    throw new \RuntimeException("Could not read the Zitadel roles of user {$u}: HTTP 401");
                }
                if ($this->roleReadsBeforeFailure > 0) {
                    $this->roleReadsBeforeFailure--;
                }
                return $this->roles[$u] ?? [];
            });
        }

        if ($cascade === null) {
            $cascade = $this->createStub(RoleCascadeService::class);
            $cascade->method('maybeCascadeRoleRevoke')->willReturnCallback(function (string $u, string $role): bool {
                $this->events[] = "cascade:maybeCascadeRoleRevoke {$u} {$role}";
                if (!$this->zitadelKeepsRoles) {
                    $this->roles[$u] = array_values(array_diff($this->roles[$u] ?? [], [$role]));
                }
                return true;
            });
        }

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
            $logger,
            $pendingWrites,
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
