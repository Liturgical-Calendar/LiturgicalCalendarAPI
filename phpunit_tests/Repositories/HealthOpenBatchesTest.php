<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `/health`'s `source_data_publisher` block must report batches still awaiting a merge
 * decision, and say something once the oldest of them has waited far longer than any review
 * plausibly takes.
 *
 * An open batch is the ORDINARY state — a reviewer has not got to its pull request yet — so it
 * must never alarm on its own; only the age, past {@see Health::STALE_OPEN_BATCH_SECONDS}, is a
 * signal. What that threshold actually catches is invisible from every other angle: an
 * undetected merge (no cron entry for `scripts/poll-sourcedata-merges.php`, no consumer
 * running) looks EXACTLY like an unreviewed pull request from this side, so this is asserted
 * against a real Postgres rather than a mocked repository — a sibling of
 * {@see HealthParkedBatchesTest}, which covers the other DB-backed reading of this same block.
 * The environment-decided branches (queue mode, publisher configuration) are covered by
 * {@see \LiturgicalCalendar\Tests\HealthSourceDataPublisherTest}.
 */
#[CoversClass(Health::class)]
final class HealthOpenBatchesTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /**
     * Submits, approves, and publication-marks a batch `open`, then backdates `updated_at` so
     * its age is exactly `$ageSeconds` as read by {@see SourceDataChangeRequestRepository::openBatchStats()}.
     */
    private function openOneAgedSeconds(string $nation, int $ageSeconds): string
    {
        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'editor-' . $nation,
            'Editor',
            'editor@example.test',
            true
        )['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $this->repo->markBatchPublicationStatus($batchId, ChangePublicationStatus::OPEN);

        self::$pdo->prepare(
            "UPDATE sourcedata_change_requests SET updated_at = NOW() - (:age * INTERVAL '1 second') WHERE batch_id = :b"
        )->execute(['age' => $ageSeconds, 'b' => $batchId]);

        return $batchId;
    }

    /**
     * A pull request awaiting review is the ordinary state — a reviewer has not got to it yet —
     * so a freshly-opened batch must be counted but must not itself alarm.
     */
    public function testAnOpenBatchIsCountedButIsNotItselfAWarning(): void
    {
        $this->openOneAgedSeconds('US', 3600);

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(1, $status['open_batches']);
        self::assertNotSame('warning', $status['status'], 'a pull request awaiting review is not a fault');
    }

    /**
     * Past the threshold, the block warns — and the message must name both plausible readings
     * (a slow reviewer, or a stopped poller) rather than accusing the poller outright, because
     * at this age either is genuinely possible.
     */
    public function testAVeryOldOpenBatchWarnsWithoutBlamingTheReviewer(): void
    {
        $this->openOneAgedSeconds('US', Health::STALE_OPEN_BATCH_SECONDS + 60);

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('poll-sourcedata-merges', $status['message']);
        self::assertStringContainsString('reviewer', $status['message']);
    }

    /**
     * `open_batches` counts distinct batches; `oldest_open_age_seconds` reports the oldest of
     * them, not an average or the newest.
     */
    public function testMultipleOpenBatchesReportTheOldestAge(): void
    {
        $this->openOneAgedSeconds('US', 3600);
        $this->openOneAgedSeconds('DE', 7200);

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(2, $status['open_batches']);
        self::assertGreaterThanOrEqual(7200, $status['oldest_open_age_seconds']);
    }
}
