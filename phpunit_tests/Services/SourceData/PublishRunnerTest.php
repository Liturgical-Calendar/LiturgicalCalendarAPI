<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunner;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises `PublishRunner`'s orchestration — claim, publish, release-and-stop-on-failure —
 * against a real Postgres-backed {@see SourceDataChangeRequestRepository} (a skipping
 * repository test would prove nothing about the claim/release invariant this suite exists
 * to pin down) and a lightweight {@see FakeSourceDataPublisher} standing in for the real,
 * `final` {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher} — see
 * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface} for why a
 * fake is used here rather than a mocked Guzzle stack. No network, no credentials.
 */
#[CoversClass(PublishRunner::class)]
final class PublishRunnerTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    // -- Fixtures -----------------------------------------------------------------------------

    private function approveOne(string $sub, string $nation = 'US'): string
    {
        $submission = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            $sub,
            'Editor',
            $sub . '@example.test',
            true
        );
        $batchId    = $submission['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');

        return $batchId;
    }

    private function runner(?FakeSourceDataPublisher $publisher = null): PublishRunner
    {
        return new PublishRunner($this->repo, $publisher ?? new FakeSourceDataPublisher($this->repo));
    }

    private function runnerThatThrows(\Throwable $exception): PublishRunner
    {
        return $this->runner(new FakeSourceDataPublisher($this->repo, $exception));
    }

    // -- Tests --------------------------------------------------------------------------------

    public function testASuccessfulPublishRecordsTheBranchCommitAndPr(): void
    {
        $batchId = $this->approveOne('editor-1');

        self::assertSame(1, $this->runner()->runOnce());

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertNotNull($row['commit_sha']);
            self::assertSame(FakeSourceDataPublisher::PR_NUMBER, $row['pr_number']);
        }
    }

    public function testAFailedPublishReleasesTheClaimSoItIsRetried(): void
    {
        $batchId = $this->approveOne('editor-1');

        $runner = $this->runnerThatThrows(new GitHubApiException(422, 'Update is not a fast forward'));
        self::assertSame(0, $runner->runOnce());

        // Back to `none`, not stranded in `queued`: a batch nobody will ever pick up again is
        // worse than one that retries, because it is invisible to the operator and to the
        // editor alike.
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    /**
     * The brief is explicit that this must hold for ANY `\Throwable`, not only the expected
     * `GitHubApiException` — a `TypeError` or an out-of-memory condition inside the publisher
     * must not leave a batch stranded in `queued` either.
     */
    public function testANonGitHubThrowableAlsoReleasesTheClaim(): void
    {
        $batchId = $this->approveOne('editor-1');

        $runner = $this->runnerThatThrows(new \TypeError('unexpected null'));
        self::assertSame(0, $runner->runOnce());

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    public function testAnEmptyQueueIsANoOp(): void
    {
        self::assertSame(0, $this->runner()->runOnce());
    }

    public function testALimitOfZeroClaimsNothing(): void
    {
        $batchId = $this->approveOne('editor-1');

        self::assertSame(0, $this->runner()->runOnce(0));

        // Never even claimed: still exactly as approveOne() left it, not queued-then-released.
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    /**
     * On failure the loop stops rather than moving on to the next approved batch — hammering
     * a failing GitHub API with every remaining batch is how rate limits get exhausted. The
     * fake publisher counts its own invocations so this test can assert the second batch was
     * never even attempted, not merely that it also ended up back at `none` (which claim-then-
     * release would produce too, and so would not distinguish the two).
     */
    public function testAFailureStopsTheLoopRatherThanTryingTheNextBatch(): void
    {
        $this->approveOne('editor-1', 'US');
        $this->approveOne('editor-2', 'DE');

        $publisher = new FakeSourceDataPublisher($this->repo, new GitHubApiException(500, 'boom'));
        $runner    = $this->runner($publisher);

        self::assertSame(0, $runner->runOnce());
        self::assertSame(1, $publisher->calls, 'the second approved batch must never be attempted after the first failure');
    }

    public function testRunOnceStopsAtTheGivenLimit(): void
    {
        $this->approveOne('editor-1', 'US');
        $this->approveOne('editor-2', 'DE');
        $this->approveOne('editor-3', 'FR');

        self::assertSame(2, $this->runner()->runOnce(2));
    }
}
