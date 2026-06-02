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
}
