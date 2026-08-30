<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\GitBlobSha;
use LiturgicalCalendar\Tests\Handlers\ChangeRequestTraitHost;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `base_sha` end to end: the writer captures the blob sha of the file the edit was authored
 * against, and it survives everything that happens to the row afterwards.
 *
 * This is the bookkeeping half of
 * {@see https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/917}. Before it,
 * nothing wrote a per-file base sha at all and `recordPublication()` overwrote the column on
 * every row with the batch-level branch head, so no row anywhere could answer "did this file
 * move underneath the proposal?".
 */
#[CoversClass(ChangeRequestSourceDataWriter::class)]
#[CoversClass(SourceDataChangeRequestRepository::class)]
final class ChangeRequestBaseShaTest extends RepositoryTestCase
{
    private const CALENDAR_FILE = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';

    private const I18N_FILE = 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json';

    private SourceDataChangeRequestRepository $repo;

    private ChangeRequestTraitHost $host;

    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo        = new SourceDataChangeRequestRepository(self::$pdo);
        $this->projectRoot = sys_get_temp_dir() . '/litcal-basesha-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->projectRoot . dirname(self::CALENDAR_FILE), 0o777, true);
        mkdir($this->projectRoot . dirname(self::I18N_FILE), 0o777, true);

        $this->host = new ChangeRequestTraitHost($this->repo);
        $this->host->setProjectRoot($this->projectRoot);
        $this->host->setSubmitter(['sub' => 'editor-1', 'name' => 'Alice', 'email' => 'alice@example.test', 'email_verified' => true]);
        $this->host->setAdministers(false);
    }

    protected function tearDown(): void
    {
        if ($this->projectRoot !== '' && is_dir($this->projectRoot)) {
            self::removeTree(rtrim($this->projectRoot, '/'));
        }

        parent::tearDown();
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeOnDisk(string $repoRelativePath, string $content): void
    {
        file_put_contents($this->projectRoot . $repoRelativePath, $content);
    }

    /** @return array<string, ?string> base_sha keyed by path */
    private function baseShasOf(string $batchId): array
    {
        $bases = [];
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertIsString($row['path']);
            $baseSha             = $row['base_sha'];
            $bases[$row['path']] = is_string($baseSha) ? $baseSha : null;
        }

        return $bases;
    }

    private function submit(string $calendarContent, string $i18nContent = '{"key":"value"}'): string
    {
        $this->host->stageFile($this->projectRoot . self::CALENDAR_FILE, ChangeOperation::UPDATE, $calendarContent);
        $this->host->stageFile($this->projectRoot . self::I18N_FILE, ChangeOperation::UPDATE, $i18nContent);

        $result = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));
        self::assertIsArray($result['change_request']);
        self::assertIsString($result['change_request']['batch_id']);

        return $result['change_request']['batch_id'];
    }

    public function testStageCapturesTheBlobShaOfTheFileOnDisk(): void
    {
        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"upstream\"]}\n");
        $this->writeOnDisk(self::I18N_FILE, "{\"key\":\"upstream\"}\n");

        $bases = $this->baseShasOf($this->submit('{"litcal":["mine"]}'));

        self::assertSame(GitBlobSha::ofContent("{\"litcal\":[\"upstream\"]}\n"), $bases[self::CALENDAR_FILE]);
        self::assertSame(GitBlobSha::ofContent("{\"key\":\"upstream\"}\n"), $bases[self::I18N_FILE]);
    }

    /**
     * A create has no upstream blob, and null is how that is recorded. The row's `operation`
     * is what tells a later reader that this null means "there was no file", rather than
     * "unknown".
     */
    public function testBaseShaIsNullWhenThePathHasNoFileOnDisk(): void
    {
        $bases = $this->baseShasOf($this->submit('{"litcal":["mine"]}'));

        self::assertNull($bases[self::CALENDAR_FILE]);
        self::assertNull($bases[self::I18N_FILE]);
    }

    /**
     * The regression this issue is about: `recordPublication()` used to stamp the batch-level
     * branch head across every row's `base_sha`, so the per-file value did not survive
     * publication and no rebase check could ever be built on it.
     */
    public function testRecordPublicationLeavesThePerFileBaseShaAlone(): void
    {
        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"upstream\"]}\n");
        $this->writeOnDisk(self::I18N_FILE, "{\"key\":\"upstream\"}\n");

        $batchId = $this->submit('{"litcal":["mine"]}');
        $before  = $this->baseShasOf($batchId);

        $this->repo->approveBatch($batchId, 'admin-1');
        self::assertNotNull($this->repo->claimNextPublishableBatch());
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'commitsha', 42, 'branchheadsha');

        self::assertSame($before, $this->baseShasOf($batchId), 'per-file base_sha must survive publication');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame('branchheadsha', $row['publish_base_sha'], 'the branch head belongs in its own column');
        }
    }

    /**
     * The accumulation case, and the reason `submitBatch()` overrides the writer's value.
     *
     * The second submission's content was built from the FIRST submission's content
     * (`findUnpublishedContent()`), not re-read from disk — so it descends from the base the
     * chain started at, whatever disk holds now. Recording the new disk sha would assert the
     * proposal was authored against a state it has never seen, and a rebase check would then
     * answer "not stale" in exactly the case it exists to catch.
     */
    public function testAccumulatingOntoOwnUnpublishedRowKeepsTheOriginalBaseSha(): void
    {
        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"v1\"]}\n");
        $this->writeOnDisk(self::I18N_FILE, "{\"key\":\"v1\"}\n");
        $originalBase = GitBlobSha::ofContent("{\"litcal\":[\"v1\"]}\n");

        $first = $this->submit('{"litcal":["mine-1"]}');
        self::assertSame($originalBase, $this->baseShasOf($first)[self::CALENDAR_FILE]);

        // A deploy lands, moving the file underneath the in-flight proposal.
        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"v2\"]}\n");
        self::assertNotSame($originalBase, GitBlobSha::ofContent("{\"litcal\":[\"v2\"]}\n"));

        $second = $this->submit('{"litcal":["mine-2"]}');

        self::assertSame(
            $originalBase,
            $this->baseShasOf($second)[self::CALENDAR_FILE],
            'the accumulation ancestor\'s base must win over the current disk sha'
        );
    }

    /**
     * The mirror image, and why the carry-forward tests `array_key_exists()` rather than `??`:
     * an ancestor with a NULL base is a meaningful answer ("there was no upstream file"), and
     * must not silently fall back to a disk sha that only appeared after the chain started.
     */
    public function testANullAncestorBaseShaIsCarriedForwardRatherThanRefreshedFromDisk(): void
    {
        $first = $this->submit('{"litcal":["mine-1"]}');
        self::assertNull($this->baseShasOf($first)[self::CALENDAR_FILE]);

        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"appeared-upstream\"]}\n");

        $second = $this->submit('{"litcal":["mine-2"]}');
        self::assertNull(
            $this->baseShasOf($second)[self::CALENDAR_FILE],
            'a null ancestor base is an answer, not an absence to be refilled from disk'
        );
    }

    /**
     * A row the incoming request does not restage is re-parented, not re-inserted, so its
     * `base_sha` travels with it untouched — no carry-forward logic involved, but the
     * guarantee is the same one and is worth pinning.
     */
    public function testACarriedForwardRowKeepsItsOwnBaseSha(): void
    {
        $this->writeOnDisk(self::I18N_FILE, "{\"key\":\"upstream\"}\n");
        $i18nBase = GitBlobSha::ofContent("{\"key\":\"upstream\"}\n");

        $first = $this->submit('{"litcal":["mine-1"]}');
        self::assertSame($i18nBase, $this->baseShasOf($first)[self::I18N_FILE]);

        // Restage ONLY the calendar file; the i18n row is re-parented onto the new batch.
        $this->host->stageFile($this->projectRoot . self::CALENDAR_FILE, ChangeOperation::UPDATE, '{"litcal":["mine-2"]}');
        $result = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));
        self::assertIsArray($result['change_request']);
        self::assertIsString($result['change_request']['batch_id']);

        $bases = $this->baseShasOf($result['change_request']['batch_id']);
        self::assertArrayHasKey(self::I18N_FILE, $bases, 'the non-restaged row must have been carried forward');
        self::assertSame($i18nBase, $bases[self::I18N_FILE]);
    }

    /**
     * Scoping: another submitter's in-flight row is never an accumulation ancestor, so it must
     * not supply a base sha either. Editor B authored against disk and their row must say so.
     */
    public function testAnotherSubmittersRowIsNotAnAccumulationAncestor(): void
    {
        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"v1\"]}\n");
        $this->submit('{"litcal":["alice"]}');

        $this->writeOnDisk(self::CALENDAR_FILE, "{\"litcal\":[\"v2\"]}\n");
        $this->host->setSubmitter(['sub' => 'editor-2', 'name' => 'Bob', 'email' => 'bob@example.test', 'email_verified' => true]);

        $bob = $this->submit('{"litcal":["bob"]}');

        self::assertSame(
            GitBlobSha::ofContent("{\"litcal\":[\"v2\"]}\n"),
            $this->baseShasOf($bob)[self::CALENDAR_FILE],
            'editor-2 authored against disk, not against editor-1\'s in-flight proposal'
        );
    }
}
