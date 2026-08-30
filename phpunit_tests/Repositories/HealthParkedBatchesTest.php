<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `/health`'s `source_data_publisher` block must report batches the publisher has STOPPED
 * attempting.
 *
 * The attempt bound is what keeps one deterministically-failing batch from stranding every
 * other editor's approved work — but a batch that is silently skipped is the same class of
 * defect as one silently stranded: no error, no editor-visible symptom, and a review workflow
 * that completed normally. This is the out-of-band signal that makes it visible, so it is
 * asserted against a real Postgres rather than a mocked repository; the rest of that block is
 * decided by environment alone and is covered by
 * {@see \LiturgicalCalendar\Tests\HealthSourceDataPublisherTest}.
 */
#[CoversClass(Health::class)]
final class HealthParkedBatchesTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    private function approveOne(string $nation): string
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

        return $batchId;
    }

    public function testAnUnattemptedQueueIsQuiet(): void
    {
        $this->approveOne('US');

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(0, $status['parked_batches']);
        self::assertSame('ok', $status['status']);
    }

    public function testAParkedBatchIsReportedRatherThanSilentlySkipped(): void
    {
        $parked = $this->approveOne('US');
        $this->approveOne('DE');

        self::$pdo->prepare('UPDATE sourcedata_change_requests SET publish_attempts = :n WHERE batch_id = :b')
            ->execute(['n' => SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS, 'b' => $parked]);

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(1, $status['parked_batches'], 'only the parked batch counts, not the queue behind it');
        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('no longer being attempted', $status['message']);
        self::assertStringContainsString('Parked batches', $status['message'], 'the operator needs somewhere to go');
    }
}
