<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OutboxRepository::class)]
final class OutboxRepositoryTest extends RepositoryTestCase
{
    private OutboxRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new OutboxRepository(self::$pdo);
    }

    /**
     * @return list<array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string, idempotency_key: string, metadata: array<string,mixed>}>
     */
    private function samplePayload(): array
    {
        return [
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'access_request:r1:write_tuple:user:alice:editor:national_calendar:IT',
                'metadata'        => ['access_request_id' => 'r1', 'admin_user' => 'admin:bob'],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'viewer',
                'fga_object'      => 'diocesan_calendar:romamo_it',
                'idempotency_key' => 'access_request:r1:write_tuple:user:alice:viewer:diocesan_calendar:romamo_it',
                'metadata'        => ['access_request_id' => 'r1', 'admin_user' => 'admin:bob'],
            ],
        ];
    }

    public function testInsertBatchReturnsRowIds(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload());

        self::assertCount(2, $ids);
        self::assertContainsOnlyInt($ids);
        self::assertGreaterThan(0, $ids[0]);
        self::assertGreaterThan(0, $ids[1]);
        self::assertNotSame($ids[0], $ids[1]);
    }

    public function testInsertBatchIsIdempotentOnDuplicateKey(): void
    {
        $firstIds = $this->repo->insertBatch($this->samplePayload());
        // Re-insert the same payload — same idempotency keys, no new rows.
        $secondIds = $this->repo->insertBatch($this->samplePayload());

        // Same IDs returned both times.
        self::assertSame($firstIds, $secondIds);
    }

    public function testGetByIdHydratesAllFields(): void
    {
        [$id1] = $this->repo->insertBatch($this->samplePayload());

        $row = $this->repo->getById($id1);

        self::assertNotNull($row);
        self::assertSame($id1, $row->id);
        self::assertSame(OutboxOperation::WRITE_TUPLE, $row->operation);
        self::assertSame('user:alice', $row->fgaUser);
        self::assertSame('editor', $row->fgaRelation);
        self::assertSame('national_calendar:IT', $row->fgaObject);
        self::assertSame(OutboxStatus::PENDING, $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->lastError);
        self::assertNull($row->lastErrorCode);
        self::assertSame('r1', $row->metadata['access_request_id']);
        self::assertNull($row->completedAt);
    }

    public function testGetByIdReturnsNullForMissingRow(): void
    {
        self::assertNull($this->repo->getById(999999));
    }

    public function testMarkSucceededSetsTerminalStateAndCompletedAt(): void
    {
        [$id] = $this->repo->insertBatch($this->samplePayload());

        $this->repo->markSucceeded($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
        self::assertNotNull($row->completedAt);
    }

    public function testMarkSucceededOnAlreadyTerminalIsNoOp(): void
    {
        [$id] = $this->repo->insertBatch($this->samplePayload());
        $this->repo->markSucceeded($id);
        $firstCompleted = $this->repo->getById($id)?->completedAt;

        // Sleep 1 second so completed_at would observably change if a second call rewrote it.
        sleep(1);
        $this->repo->markSucceeded($id);

        $secondCompleted = $this->repo->getById($id)?->completedAt;
        self::assertEquals($firstCompleted, $secondCompleted, 'completed_at must not change on second markSucceeded');
    }

    public function testMarkRetryableIncrementsAttemptsAndSchedulesNext(): void
    {
        [$id]   = $this->repo->insertBatch($this->samplePayload());
        $nextAt = ( new \DateTimeImmutable('now') )->modify('+8 seconds');

        $this->repo->markRetryable($id, attempts: 3, nextAttemptAt: $nextAt, lastError: 'OpenFGA 503', lastErrorCode: null);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::RETRYING, $row->status);
        self::assertSame(3, $row->attempts);
        self::assertSame('OpenFGA 503', $row->lastError);
        self::assertEqualsWithDelta(
            $nextAt->getTimestamp(),
            $row->nextAttemptAt->getTimestamp(),
            1.0,
            'next_attempt_at within 1s of requested',
        );
    }

    public function testMarkFailedTerminalIsSticky(): void
    {
        [$id] = $this->repo->insertBatch($this->samplePayload());

        $this->repo->markFailedTerminal($id, lastError: 'validation_error', lastErrorCode: 'validation_error');

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::FAILED_TERMINAL, $row->status);
        self::assertSame('validation_error', $row->lastErrorCode);

        // Subsequent markRetryable on a terminal row must NOT downgrade it.
        $this->repo->markRetryable($id, attempts: 4, nextAttemptAt: new \DateTimeImmutable(), lastError: 'late retry', lastErrorCode: null);
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(
            OutboxStatus::FAILED_TERMINAL,
            $row->status,
            'markRetryable must not overwrite a terminal status',
        );
    }

    public function testPickupPendingReturnsOnlyEligibleRows(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload());
        $this->repo->markSucceeded($ids[0]); // exclude succeeded
        $this->repo->markFailedTerminal($ids[1], 'x', null); // exclude failed_terminal

        $picked = $this->repo->pickupPending(limit: 10, now: new \DateTimeImmutable());

        self::assertSame([], $picked, 'no eligible rows after both are terminal');
    }

    public function testPickupPendingRespectsNextAttemptAt(): void
    {
        [$id] = $this->repo->insertBatch([$this->samplePayload()[0]]);

        // Schedule the next attempt 60 seconds into the future.
        $this->repo->markRetryable(
            $id,
            attempts: 1,
            nextAttemptAt: ( new \DateTimeImmutable() )->modify('+60 seconds'),
            lastError: 'transient',
            lastErrorCode: null,
        );

        $tooEarly = $this->repo->pickupPending(limit: 10, now: new \DateTimeImmutable());
        self::assertSame([], $tooEarly);

        // Far-future cutoff should pick it up.
        $picked = $this->repo->pickupPending(limit: 10, now: ( new \DateTimeImmutable() )->modify('+120 seconds'));
        self::assertCount(1, $picked);
        self::assertSame($id, $picked[0]->id);
    }

    /**
     * Two concurrent transactions must each get distinct rows
     * thanks to FOR UPDATE SKIP LOCKED. Load-bearing for consumer + backstop topology.
     */
    public function testPickupPendingSkipLockedSeparatesConcurrentRunners(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload()); // 2 rows

        // Open a second PDO connection to the same DB. Use the same env
        // resolution the base class uses (env array first, getenv fallback)
        // so this works in CI where DB_* may live only in getenv().
        $host     = self::env('DB_HOST') ?? 'localhost';
        $port     = self::env('DB_PORT') ?? '5432';
        $name     = self::env('DB_NAME') ?? '';
        $user     = self::env('DB_USER') ?? '';
        $password = self::env('DB_PASSWORD') ?? '';
        $other    = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
            $user,
            $password,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );
        $other->exec("SET timezone TO 'Europe/Vatican'");
        $otherRepo = new OutboxRepository($other);

        // Both runners start a tx and call pickupPending with limit: 1;
        // SKIP LOCKED must give each one a distinct row. Wrap in try/finally
        // so a mid-test assertion failure can't leave open transactions on
        // the shared connections — those would cascade into TRUNCATE failures
        // in the next test's setUp.
        try {
            self::$pdo->beginTransaction();
            $picked1 = $this->repo->pickupPending(limit: 1, now: new \DateTimeImmutable());

            $other->beginTransaction();
            $picked2 = $otherRepo->pickupPending(limit: 1, now: new \DateTimeImmutable());

            self::$pdo->commit();
            $other->commit();
        } finally {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }

        $idsPicked = array_merge(
            array_map(static fn ($r): int => $r->id, $picked1),
            array_map(static fn ($r): int => $r->id, $picked2),
        );
        sort($idsPicked);
        sort($ids);
        self::assertSame($ids, $idsPicked, 'two transactions must collectively pick up every row exactly once');
        self::assertCount(1, $picked1);
        self::assertCount(1, $picked2);
    }

    public function testResetForRetryClearsAttemptsAndStatus(): void
    {
        [$id] = $this->repo->insertBatch([$this->samplePayload()[0]]);
        $this->repo->markFailedTerminal($id, 'validation_error', 'validation_error');

        $changed = $this->repo->resetForRetry($id);

        self::assertTrue($changed, 'resetForRetry must return true when a row was reset');
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::PENDING, $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->lastError);
        self::assertNull($row->lastErrorCode);
        self::assertNull($row->completedAt);
    }

    public function testResetForRetryReturnsFalseForNonTerminalRow(): void
    {
        [$id] = $this->repo->insertBatch([$this->samplePayload()[0]]);
        // Row is still 'pending' — admin retry must refuse.
        $changed = $this->repo->resetForRetry($id);
        self::assertFalse($changed);
    }

    public function testCountByStatusBucketsAllFour(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload());
        $this->repo->markSucceeded($ids[0]);
        $this->repo->markFailedTerminal($ids[1], 'x', null);

        $counts = $this->repo->countByStatus();

        self::assertSame(0, $counts['pending'] ?? 0);
        self::assertSame(0, $counts['retrying'] ?? 0);
        self::assertSame(1, $counts['succeeded'] ?? 0);
        self::assertSame(1, $counts['failed_terminal'] ?? 0);
    }

    public function testMarkRetryableRoundtripsMicrosecondPrecision(): void
    {
        [$id]   = $this->repo->insertBatch([$this->samplePayload()[0]]);
        $nextAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', '2026-06-02 12:34:56.123456');
        self::assertInstanceOf(\DateTimeImmutable::class, $nextAt);

        // ----- list() coverage -----
        // Seeded below by the trailing tests; reset is fine since each test
        // gets a fresh TRUNCATE via RepositoryTestCase.

        $this->repo->markRetryable(
            $id,
            attempts: 1,
            nextAttemptAt: $nextAt,
            lastError: 'transient',
            lastErrorCode: null,
        );

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(
            $nextAt->format('u'),
            $row->nextAttemptAt->format('u'),
            'next_attempt_at microseconds must survive the write/read roundtrip',
        );
    }

    // -----------------------------------------------------------------------
    // list() — used by OutboxAdminHandler::handleGet for the paginated list
    // surface. Exercises both filter dimensions and pagination.
    // -----------------------------------------------------------------------

    /**
     * @return list<int>
     */
    private function seedThreeMixedRows(): array
    {
        // Same `access_request_id` for first two, third has a different one.
        return $this->repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:a',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'list-k1-' . bin2hex(random_bytes(4)),
                'metadata'        => ['access_request_id' => 'r-A'],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:b',
                'fga_relation'    => 'viewer',
                'fga_object'      => 'national_calendar:US',
                'idempotency_key' => 'list-k2-' . bin2hex(random_bytes(4)),
                'metadata'        => ['access_request_id' => 'r-A'],
            ],
            [
                'operation'       => OutboxOperation::DELETE_TUPLE,
                'fga_user'        => 'user:c',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:FR',
                'idempotency_key' => 'list-k3-' . bin2hex(random_bytes(4)),
                'metadata'        => ['access_request_id' => 'r-B'],
            ],
        ]);
    }

    public function testListWithoutFiltersReturnsAllRowsAndTotal(): void
    {
        $this->seedThreeMixedRows();

        $page = $this->repo->list(status: null, accessRequestId: null, limit: 100, offset: 0);

        self::assertSame(3, $page['total']);
        self::assertCount(3, $page['rows']);
    }

    public function testListWithStatusFilterReturnsMatchingRowsOnly(): void
    {
        $ids = $this->seedThreeMixedRows();
        $this->repo->markFailedTerminal($ids[0], 'validation_error', 'validation_error');

        $page = $this->repo->list(status: 'failed_terminal', accessRequestId: null, limit: 100, offset: 0);

        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['rows']);
        self::assertSame($ids[0], $page['rows'][0]->id);
        self::assertSame(OutboxStatus::FAILED_TERMINAL, $page['rows'][0]->status);
    }

    public function testListWithAccessRequestIdFilterReturnsMatchingRowsOnly(): void
    {
        $this->seedThreeMixedRows();

        $page = $this->repo->list(status: null, accessRequestId: 'r-A', limit: 100, offset: 0);

        self::assertSame(2, $page['total']);
        self::assertCount(2, $page['rows']);
        foreach ($page['rows'] as $row) {
            self::assertSame('r-A', $row->metadata['access_request_id']);
        }
    }

    public function testListRespectsLimitAndOffset(): void
    {
        $this->seedThreeMixedRows();

        // total is still 3 regardless of pagination
        $page = $this->repo->list(status: null, accessRequestId: null, limit: 2, offset: 0);
        self::assertSame(3, $page['total']);
        self::assertCount(2, $page['rows']);

        // offset 2 → just the last row
        $page = $this->repo->list(status: null, accessRequestId: null, limit: 2, offset: 2);
        self::assertSame(3, $page['total']);
        self::assertCount(1, $page['rows']);
    }
}
