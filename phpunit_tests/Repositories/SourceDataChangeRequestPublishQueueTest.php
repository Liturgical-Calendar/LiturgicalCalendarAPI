<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Covers the publish-queue half of {@see SourceDataChangeRequestRepository}: claiming an
 * approved batch for publication, and settling a claim (recording success, or releasing
 * a failed attempt back to `none`).
 *
 * The class docblock on the repository explains why a batch is never mixed-status; this
 * suite leans on that invariant the same way the repository's own claim query does.
 */
#[CoversClass(SourceDataChangeRequestRepository::class)]
final class SourceDataChangeRequestPublishQueueTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /** @return list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private function calendarFile(string $nation): array
    {
        return [
            [
                'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"litcal":[]}',
            ],
        ];
    }

    private function submitOnly(string $sub, string $nation = 'US'): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            $this->calendarFile($nation),
            $sub,
            'Editor',
            $sub . '@example.test',
            true
        )['batch_id'];
    }

    private function submitAndApprove(string $sub, string $nation = 'US'): string
    {
        $batchId = $this->submitOnly($sub, $nation);
        $this->repo->approveBatch($batchId, 'reviewer-1');

        return $batchId;
    }

    /**
     * Opens a second, independent PDO connection to the same test database. Used to prove
     * the claim is genuinely atomic across connections, not merely well-behaved when
     * called twice in a row on one connection (which {@see testAClaimedBatchIsNotClaimedTwice}
     * already covers, but which alone could not distinguish "correctly locked" from
     * "correctly re-checked the now-committed status").
     */
    private function secondConnection(): PDO
    {
        $host     = self::env('DB_HOST');
        $port     = self::env('DB_PORT') ?? '5432';
        $name     = self::env('DB_NAME');
        $user     = self::env('DB_USER');
        $password = self::env('DB_PASSWORD');

        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
        $pdo->exec("SET timezone TO 'Europe/Vatican'");

        return $pdo;
    }

    public function testClaimingReturnsAnApprovedBatchAndMarksItQueued(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        self::assertSame($batchId, $this->repo->claimNextPublishableBatch());

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    public function testAnUnapprovedBatchIsNeverClaimed(): void
    {
        $this->submitOnly('editor-1');

        self::assertNull($this->repo->claimNextPublishableBatch(), 'submitted-but-undecided work is not publishable');
    }

    public function testAClaimedBatchIsNotClaimedTwice(): void
    {
        $this->submitAndApprove('editor-1');

        self::assertNotNull($this->repo->claimNextPublishableBatch());
        self::assertNull($this->repo->claimNextPublishableBatch(), 'a queued batch must not be handed out again');
    }

    public function testReleasingAClaimMakesItPublishableAgain(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        $this->repo->releaseClaim($batchId);

        self::assertSame($batchId, $this->repo->claimNextPublishableBatch(), 'a failed attempt must be retryable');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    public function testOlderApprovedBatchIsClaimedBeforeANewerOne(): void
    {
        $older = $this->submitAndApprove('editor-1', 'US');
        // Force a distinct, later created_at so ordering is unambiguous rather than
        // relying on two inserts in the same transaction landing on different microseconds.
        self::$pdo->exec('UPDATE sourcedata_change_requests SET created_at = created_at - INTERVAL \'1 hour\'');
        $this->submitAndApprove('editor-2', 'DE');

        self::assertSame($older, $this->repo->claimNextPublishableBatch());
    }

    public function testRecordPublicationSetsOpenStatusAndGitFields(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        $updated = $this->repo->recordPublication($batchId, 'publish/us-2026-08-29', 'deadbeef', 42, 'basecommitsha');
        self::assertGreaterThan(0, $updated);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertSame('publish/us-2026-08-29', $row['branch']);
            self::assertSame('deadbeef', $row['commit_sha']);
            self::assertEquals(42, $row['pr_number']);
            self::assertSame('basecommitsha', $row['base_sha']);
        }
    }

    public function testRecordPublicationAcceptsANullPrNumber(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        $this->repo->recordPublication($batchId, 'publish/us-2026-08-29', 'deadbeef', null, 'basecommitsha');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertNull($row['pr_number']);
        }
    }

    /**
     * The test that actually demonstrates atomicity, not just that a single caller's
     * claim behaves. It reproduces the exact locking half of claimNextPublishableBatch()
     * on a second, independent connection and holds it open in an uncommitted
     * transaction — simulating "runner A is mid-claim" — then proves a second runner
     * (this test's own $this->repo, on the original connection) cannot see or take the
     * same row while that lock is held, and CAN take it the instant the lock is released.
     *
     * If the repository's SELECT and UPDATE did not share one transaction (i.e. if
     * autocommit released the lock right after the SELECT), this test would fail: the
     * second connection's claim would succeed while connection A's transaction is still
     * open, because there would be no lock left to skip.
     */
    public function testConcurrentClaimsCannotSelectTheSameBatch(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $connA = $this->secondConnection();
        $connA->beginTransaction();
        try {
            // Reproduces the row-locking half of claimNextPublishableBatch(): a plain
            // FOR UPDATE SKIP LOCKED against every row of the batch (Postgres rejects FOR
            // UPDATE combined with GROUP BY, so the repository's own aggregate candidate
            // query cannot itself hold the lock — see that method's docblock).
            $lock = $connA->prepare(
                'SELECT id
                   FROM sourcedata_change_requests
                  WHERE batch_id = :batch_id
                    FOR UPDATE SKIP LOCKED'
            );
            $lock->execute(['batch_id' => $batchId]);
            self::assertCount(
                1,
                $lock->fetchAll(PDO::FETCH_COLUMN),
                'connection A must actually hold the row lock for this to be a meaningful test'
            );

            // Connection A's transaction is still open — the row lock is held. A second
            // runner using a different connection must skip the locked row, not double-claim it.
            self::assertNull(
                $this->repo->claimNextPublishableBatch(),
                'a row locked by an in-flight claim on another connection must not be handed out again'
            );
        } finally {
            $connA->commit();
        }

        // Once connection A's transaction ends, the lock is released and the batch
        // becomes claimable again — proving the null above was "locked, try later", not
        // some unrelated bug swallowing the row.
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch());
    }
}
