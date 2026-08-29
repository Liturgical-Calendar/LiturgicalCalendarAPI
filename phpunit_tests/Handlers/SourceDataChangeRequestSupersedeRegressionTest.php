<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

/**
 * Regression coverage for the three defects the final review of
 * feature/sourcedata-change-requests found in the supersede logic:
 *
 * - Defect 1 (silent data loss): superseding by resource, rather than by path,
 *   deleted an unrelated pending file under a resource that is not 1:1 with a
 *   file (`rite_calendar_test:<rite>`, `general_roman_calendar:decrees`).
 * - Defect 2 (uncaught 500): when the same path re-scopes to a different
 *   resource (a PATCH re-scope), the old supersede DELETE (keyed on resource)
 *   missed the prior row and the INSERT then violated
 *   idx_scr_unique_pending_path_submitter, raising an uncaught PDOException.
 * - Defect 3 (silent data loss, one layer up — and the one that survived the fix
 *   for defects 1 and 2): moving to path-keying does nothing for a resource whose
 *   storage is a single AGGREGATE file. Every decree lives in one `decrees.json`,
 *   so two decree writes by one submitter always collide on that path and the
 *   second correctly supersedes the first. The loss was in the REBUILD:
 *   `DecreesHandler::loadDecreesDatabase()` read the aggregate from disk, and in
 *   queue mode disk never received decree A — so decree A vanished behind a `201`
 *   and `disposition: submitted`. The same applied to the `decrees/i18n/<locale>.json`
 *   sidecars, both to their content and to the folder listing that decides which of
 *   them to rebuild. The fix is read-your-own-pending-writes, which turns supersede
 *   into accumulation. This is the exact resource whose loss — the St John Henry
 *   Newman decree — motivated the whole feature.
 *
 * Every other queue-mode test in this suite drives `ChangeRequestSourceDataWriter`
 * through `ChangeRequestTraitHost`, a stand-in for `WritesSourceData` that never
 * touches a real handler. All three defects live in the interaction between a real
 * handler (its routing, and what it reads back before it writes) and the repository's
 * supersede logic, so a stand-in cannot exercise any of them — these tests drive
 * `TestsHandler::handle()` and `DecreesHandler::handle()` directly instead.
 */
#[CoversClass(DecreesHandler::class)]
#[CoversClass(TestsHandler::class)]
#[CoversClass(SourceDataChangeRequestRepository::class)]
// These tests drive real handlers end to end in queue mode, so they exercise the whole
// write path — the mode selection, the queue writer and the FGA review gate — not just
// the handler and the repository. Without naming them here PHPUnit discards that
// coverage, which is why ChangeRequestSourceDataWriter reported 0% while being
// constructed on every one of these requests.
#[CoversClass(ChangeRequestSourceDataWriter::class)]
#[CoversClass(SourceDataWriteMode::class)]
#[CoversClass(ChangeRequestReview::class)]
final class SourceDataChangeRequestSupersedeRegressionTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    private SourceDataChangeRequestRepository $repo;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var list<string> */
    private array $extraFixturePaths = [];

    /**
     * The paths each write REQUEST staged, by the batch id it opened. Distinct from the
     * paths that batch ends up holding: a supersede now carries other rows onto it.
     *
     * @var array<string, list<string>>
     */
    private array $stagedPathsByBatch = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagedPathsByBatch = [];

        // AbstractHandlerTestCase::TABLES does not include sourcedata_change_requests
        // (only RepositoryTestCase::TABLES does — see ChangeRequestAdminHandlerTest's
        // docblock for the same precedent), so truncate it here explicitly.
        self::$pdo?->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);

        foreach (['SOURCEDATA_CHANGE_REQUESTS', 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';
        // Points at the real OpenFGA container this dev/CI stack already runs (see
        // docker-compose.yml), with a store/model id that does not exist there. Every
        // `check()` call therefore fails fast with a real, non-200 HTTP response —
        // OpenFgaApiException (a RuntimeException) — which ChangeRequestReview::
        // administers() catches and turns into `false`. That keeps every submission in
        // this test genuinely `submitted` (never auto-approved) without depending on
        // any real OpenFGA authorization data existing for the test users below.
        $_ENV['OPENFGA_API_URL']  = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID'] = 'no-such-store-supersede-regression-test';
        $_ENV['OPENFGA_MODEL_ID'] = 'no-such-model-supersede-regression-test';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->originalEnv = [];

        // Belt and braces: queue mode never writes these to disk, but if
        // SourceDataWriteMode ever silently fell back to disk mid-test, this stops a
        // stray fixture from being left behind in the shipped test corpus.
        foreach ($this->extraFixturePaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->extraFixturePaths = [];

        parent::tearDown();
    }

    /**
     * Defect 1: two edits to two DIFFERENT files that both resolve to the SAME
     * non-1:1 resource (`rite_calendar_test:roman`, the scope every rite-level test
     * shares) must both stay pending. Before the fix, the second PUT's supersede
     * DELETE matched on resource alone and silently deleted the first PUT's still-
     * pending batch, even though its file was never touched by the second request.
     */
    public function testDefectOneTwoDifferentFilesUnderTheSameRiteLevelResourceBothStayPending(): void
    {
        /** @var array<string, mixed> $template */
        $template = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        self::assertArrayNotHasKey(
            'national_calendar',
            $template['applies_to'],
            'fixture assumption: MaryMotherChurchTest is scoped at the rite level'
        );

        $pathA                     = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzQueueDefectOneATest.json';
        $pathB                     = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzQueueDefectOneBTest.json';
        $this->extraFixturePaths[] = $pathA;
        $this->extraFixturePaths[] = $pathB;
        self::assertFileDoesNotExist($pathA);
        self::assertFileDoesNotExist($pathB);

        $payloadA         = $template;
        $payloadA['name'] = 'ZzzQueueDefectOneATest';
        $responseA        = ( new TestsHandler(['ZzzQueueDefectOneATest'], Rite::ROMAN) )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/tests/roman/ZzzQueueDefectOneATest', [], $payloadA), 'editor-1')
        );
        self::assertSame(201, $responseA->getStatusCode());
        $bodyA = $this->decodeJsonBody($responseA);
        self::assertSame('submitted', $bodyA['disposition'], 'must not be auto-approved for this test to be meaningful');

        // Nothing was ever written to disk: queue mode records a proposal instead.
        self::assertFileDoesNotExist($pathA);

        $payloadB         = $template;
        $payloadB['name'] = 'ZzzQueueDefectOneBTest';
        $responseB        = ( new TestsHandler(['ZzzQueueDefectOneBTest'], Rite::ROMAN) )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/tests/roman/ZzzQueueDefectOneBTest', [], $payloadB), 'editor-1')
        );
        self::assertSame(201, $responseB->getStatusCode());
        $bodyB = $this->decodeJsonBody($responseB);
        self::assertSame('submitted', $bodyB['disposition']);

        // THE regression assertion: both batches, one per file, must still be pending.
        // Before the fix, submitting B deleted A's still-pending batch outright because
        // both resolve to the same resource (rite_calendar_test:roman).
        $batches = $this->repo->listBySubmitter('editor-1');
        self::assertCount(2, $batches, 'both batches must still be pending after the fix');
        foreach ($batches as $batch) {
            self::assertSame('submitted', $batch['review_status']);
            self::assertSame('rite_calendar_test', $batch['resource_type']);
            self::assertSame('roman', $batch['resource_id']);
        }

        $paths = array_merge(...array_column($batches, 'paths'));
        self::assertContains('jsondata/tests/roman/ZzzQueueDefectOneATest.json', $paths);
        self::assertContains('jsondata/tests/roman/ZzzQueueDefectOneBTest.json', $paths);
    }

    /**
     * Defect 2: a PATCH that re-scopes a test (changes `applies_to`, and therefore the
     * `ChangeResource` the change targets) while the file PATH stays fixed by the route.
     * Before the fix, the second PATCH's supersede DELETE matched on the NEW resource,
     * found nothing (the first PATCH's row was filed under the OLD resource), and the
     * INSERT then collided with idx_scr_unique_pending_path_submitter — an uncaught
     * PDOException surfacing as an opaque 500. After the fix the DELETE matches on path
     * and clears the first PATCH's row regardless of resource, so the second PATCH
     * succeeds cleanly and supersedes it.
     */
    public function testDefectTwoPatchReScopingSupersedesTheStaleResourceInsteadOf500(): void
    {
        /** @var array<string, mixed> $template */
        $template = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/PrayerUnbornTest.json'),
            true
        );
        self::assertSame(
            ['rite' => 'roman', 'national_calendar' => 'US'],
            $template['applies_to'],
            'fixture assumption: PrayerUnbornTest is scoped to national_calendar_test:roman/US'
        );

        $testName                  = 'ZzzQueueDefectTwoTest';
        $template['name']          = $testName;
        $fixturePath               = JsonData::testsFolderFor(Rite::ROMAN)->path() . "/{$testName}.json";
        $this->extraFixturePaths[] = $fixturePath;

        // PATCH requires the target file to already exist on disk (it is the record
        // TestsHandler checks for "does this test exist"). Queue mode never writes
        // proposals to disk, so this fixture stands in for content already published
        // through the ordinary review flow, and is untouched by either PATCH below.
        file_put_contents($fixturePath, json_encode($template, JSON_THROW_ON_ERROR));

        // PATCH #1: submitted as-is, still scoped to national_calendar_test:roman/US.
        $responseOne = ( new TestsHandler([$testName], Rite::ROMAN) )->handle(
            $this->withOidcUser($this->requestFor('PATCH', "/tests/roman/{$testName}", [], $template), 'editor-1')
        );
        self::assertSame(200, $responseOne->getStatusCode());
        $bodyOne = $this->decodeJsonBody($responseOne);
        self::assertSame('submitted', $bodyOne['disposition']);
        $batchOneId = $bodyOne['change_request']['batch_id'];
        self::assertSame('national_calendar_test', $bodyOne['change_request']['resource']['type']);
        self::assertSame('roman/US', $bodyOne['change_request']['resource']['id']);
        self::assertCount(1, $this->repo->getBatch($batchOneId));

        // PATCH #2: re-scope to the rite level by dropping national_calendar. Same
        // route, same file path; a DIFFERENT resulting resource. This is what used to
        // raise an uncaught PDOException (23505) instead of responding at all.
        $payloadTwo               = $template;
        $payloadTwo['applies_to'] = ['rite' => 'roman'];
        $responseTwo              = ( new TestsHandler([$testName], Rite::ROMAN) )->handle(
            $this->withOidcUser($this->requestFor('PATCH', "/tests/roman/{$testName}", [], $payloadTwo), 'editor-1')
        );

        self::assertSame(200, $responseTwo->getStatusCode(), 'must be a clean 200, not an uncaught-exception 500');
        $bodyTwo = $this->decodeJsonBody($responseTwo);
        self::assertSame('submitted', $bodyTwo['disposition']);
        self::assertSame('rite_calendar_test', $bodyTwo['change_request']['resource']['type']);
        self::assertSame('roman', $bodyTwo['change_request']['resource']['id']);
        $batchTwoId = $bodyTwo['change_request']['batch_id'];
        self::assertNotSame($batchOneId, $batchTwoId);

        // The stale batch under the OLD resource must be gone (superseded by path),
        // and the NEW batch must be the only thing pending for this path.
        self::assertSame([], $this->repo->getBatch($batchOneId), 'the stale national_calendar_test batch must have been superseded');
        $rowsTwo = $this->repo->getBatch($batchTwoId);
        self::assertCount(1, $rowsTwo);
        self::assertSame("jsondata/tests/roman/{$testName}.json", $rowsTwo[0]['path']);

        $batches = $this->repo->listBySubmitter('editor-1');
        self::assertCount(1, $batches, 'only the re-scoped batch should remain pending for this submitter');
    }

    /**
     * A genuinely concurrent submission — two overlapping transactions both landing an
     * insert for the same (path, submitter) — is still possible despite the supersede
     * fix: idx_scr_unique_pending_path_submitter exists precisely as defence-in-depth
     * for that race. Reproducing real cross-connection concurrency deterministically in
     * a single PHPUnit process is not practical (it requires forking or async I/O to
     * hold one transaction open mid-flight); a batch whose own file list repeats a path
     * hits the exact same SQLSTATE 23505 from the exact same unique index inside the
     * same INSERT loop submitBatch() would run for a real race, and exercises the
     * identical catch-and-convert path in ChangeRequestSourceDataWriter::commit() — so
     * it is used here as a deterministic proxy for the race.
     */
    public function testConcurrentPathCollisionSurfacesAsConflictNotAn500(): void
    {
        $host = new ChangeRequestTraitHost(new SourceDataChangeRequestRepository(self::$pdo));
        $host->setSubmitter([
            'sub'            => 'editor-1',
            'name'           => 'Alice',
            'email'          => 'alice@example.test',
            'email_verified' => true,
        ]);

        $host->stageFile('/app/jsondata/tests/roman/ZzzConflictRace.json', ChangeOperation::CREATE, '{"name":"ZzzConflictRace","v":1}');
        $host->stageFile('/app/jsondata/tests/roman/ZzzConflictRace.json', ChangeOperation::CREATE, '{"name":"ZzzConflictRace","v":2}');

        $this->expectException(ConflictException::class);
        $host->commitStagedFiles(ChangeResource::test(Rite::ROMAN, 'rite_calendar_test', 'roman'));
    }

    /**
     * Defect 3 (the St John Henry Newman defect, in queue mode): two decree
     * submissions in a row must ACCUMULATE, not replace one another.
     *
     * `DecreesHandler::saveDecreesDatabase()` always stages the same single aggregate
     * path — `jsondata/sourcedata/rite/roman/decrees/decrees.json` — and
     * `distributeI18n()` always stages the same `decrees/i18n/<locale>.json` set, so
     * every decree write from one submitter collides on path with every other one.
     * Superseding by path correctly removes the earlier batch; before the fix the loss
     * happened one layer up, because `loadDecreesDatabase()` rebuilt the aggregate from
     * DISK — and in queue mode disk never received decree A. The submitter got 201 with
     * `disposition: submitted` and no warning, and decree A was gone.
     *
     * After the fix the rebuild starts from the submitter's own pending content for that
     * path, so the single pending batch is their cumulative proposal.
     */
    public function testDefectThreeASecondDecreeSubmissionAccumulatesOntoTheFirst(): void
    {
        $onDiskBefore = (string) file_get_contents(JsonData::DECREES_FILE->path());

        $responseA = $this->submitDecree('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');
        self::assertSame(201, $responseA->getStatusCode());
        $bodyA = $this->decodeJsonBody($responseA);
        self::assertSame('submitted', $bodyA['disposition']);
        self::assertSame([], $bodyA['change_request']['superseded_batch_ids'], 'nothing to supersede yet');

        $responseB = $this->submitDecree('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
        self::assertSame(201, $responseB->getStatusCode());
        $bodyB = $this->decodeJsonBody($responseB);
        self::assertSame('submitted', $bodyB['disposition']);

        // Supersession is never invisible: B's response names the batch it replaced.
        self::assertSame(
            [$bodyA['change_request']['batch_id']],
            $bodyB['change_request']['superseded_batch_ids']
        );

        // Supersede-by-path collapses both writes into one pending batch — that part is
        // correct and intended: there is at most one SUBMITTED proposal per (path, submitter).
        $batches = $this->repo->listBySubmitter('editor-1');
        self::assertCount(1, $batches, 'both decree writes stage the same aggregate path, so one cumulative batch is expected');

        $proposal = $this->pendingContentByPath((string) $batches[0]['batch_id']);

        $decreesPath = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        self::assertArrayHasKey($decreesPath, $proposal);
        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);
        $ids     = array_column($decrees, 'decree_id');

        // THE regression assertion. Before the fix this held B but not A.
        self::assertContains('ZzzProbeAlpha_Create', $ids, 'decree A must survive decree B being submitted');
        self::assertContains('ZzzProbeBeta_Create', $ids, 'decree B must be present too');

        // The i18n sidecars rebuild from the same aggregate-per-locale files and had the
        // identical defect: en.json is rewritten wholesale on every decree write.
        $enPath = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        self::assertArrayHasKey($enPath, $proposal);
        /** @var array<string, string> $en */
        $en = json_decode($proposal[$enPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alpha Probe', $en['ZzzProbeAlpha'] ?? null, 'decree A\'s translation must survive decree B');
        self::assertSame('Beta Probe', $en['ZzzProbeBeta'] ?? null);

        // Queue mode still never touches disk.
        self::assertSame($onDiskBefore, (string) file_get_contents(JsonData::DECREES_FILE->path()));
    }
    /**
     * The enumeration half of defect 3.
     *
     * `distributeI18n()` decides which locale sidecars to rebuild by listing the i18n
     * folder on disk. A locale file that exists only as this submitter's pending proposal
     * — decree A carried a `cs` translation and `cs.json` has never been published — is
     * invisible to that listing, so the next submission would not restage it, and the
     * whole-batch supersede would take it away. Accumulating the CONTENT of a file the
     * rebuild never looks at does not help; the folder listing has to see it too.
     */
    public function testDefectThreeALocaleSidecarThatExistsOnlyAsAProposalIsNotDroppedByTheNextSubmission(): void
    {
        $csOnDisk = JsonData::DECREES_I18N_FOLDER->path() . '/cs.json';
        self::assertFileDoesNotExist($csOnDisk, 'fixture assumption: cs has no published decree i18n file');

        self::assertSame(201, $this->submitDecree('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe', ['cs' => 'Alfa'])->getStatusCode());
        self::assertSame(201, $this->submitDecree('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe')->getStatusCode());

        $batches = $this->repo->listBySubmitter('editor-1');
        self::assertCount(1, $batches);
        $proposal = $this->pendingContentByPath((string) $batches[0]['batch_id']);

        $csPath = 'jsondata/sourcedata/rite/roman/decrees/i18n/cs.json';
        self::assertArrayHasKey($csPath, $proposal, 'the pending-only cs sidecar must be carried into the new batch');

        /** @var array<string, string> $cs */
        $cs = json_decode($proposal[$csPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alfa', $cs['ZzzProbeAlpha'] ?? null);

        self::assertFileDoesNotExist($csOnDisk, 'queue mode still writes nothing to disk');
    }


    /**
     * Defect 4 (the same silent data loss, one review step later): an APPROVED batch is
     * not on disk either.
     *
     * Phase 1 has no publisher. `approveBatch()` is a single `UPDATE` that flips
     * `review_status` from `submitted` to `approved` and writes no files; the rows sit in
     * the queue with `publication_status = 'none'` until phase 2 opens a pull request for
     * them. So an approved-but-unpublished batch is exactly as invisible to disk as a
     * submitted one — and the accumulation base for the next rebuild must include it.
     *
     * Before the fix, the accumulation base filtered `review_status = 'submitted'` alone.
     * The moment a batch was approved it dropped out of that base, the next rebuild fell
     * back to still-stale disk, and everything approved-but-unpublished vanished behind a
     * `201`. Worse than the submitted case: the approved rows survive (the partial unique
     * index only covers `submitted`, so they do not collide) holding the contradictory
     * older version, and `superseded_batch_ids` is empty because the supersede's inner
     * SELECT filters `submitted` too — so nothing warns.
     *
     * This is a first-class path, not a corner: `ChangeRequestReview::administers()`
     * auto-approves every write by a resource admin, and the ordinary
     * editor -> reviewer -> editor loop reaches it too.
     *
     * Two approvals in a row are used deliberately: once `approved` rows are in the
     * accumulation base there can legitimately be SEVERAL rows for one
     * `(path, submitter)` — `idx_scr_unique_pending_path_submitter` covers only
     * `submitted` — so the base must take the newest deterministically rather than
     * relying on uniqueness.
     */
    public function testDefectFourAnApprovedButUnpublishedBatchStillAccumulatesIntoTheNextProposal(): void
    {
        $onDiskBefore = (string) file_get_contents(JsonData::DECREES_FILE->path());

        $batchA = $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');
        $this->approve($batchA);

        $batchB = $this->submitDecreeExpectingSubmitted('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
        $this->approve($batchB);

        $batchC = $this->submitDecreeExpectingSubmitted('ZzzProbeGamma_Create', 'ZzzProbeGamma', 'Gamma Probe');

        // The approved batches are still in the queue, holding the older versions: they
        // are not superseded (supersede only removes still-`submitted` batches) and phase 1
        // never publishes them. Nothing but the accumulation base stops them being lost.
        self::assertNotSame([], $this->repo->getBatch($batchA), 'the approved batch A must still be in the queue');
        self::assertNotSame([], $this->repo->getBatch($batchB), 'the approved batch B must still be in the queue');

        $proposal    = $this->pendingContentByPath($batchC);
        $decreesPath = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        self::assertArrayHasKey($decreesPath, $proposal);
        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);
        $ids     = array_column($decrees, 'decree_id');

        // THE regression assertion. Before the fix batch C held only Gamma.
        self::assertContains('ZzzProbeAlpha_Create', $ids, 'the approved-but-unpublished decree A must survive');
        self::assertContains('ZzzProbeBeta_Create', $ids, 'the approved-but-unpublished decree B must survive');
        self::assertContains('ZzzProbeGamma_Create', $ids);

        $enPath = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        self::assertArrayHasKey($enPath, $proposal);
        /** @var array<string, string> $en */
        $en = json_decode($proposal[$enPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alpha Probe', $en['ZzzProbeAlpha'] ?? null);
        self::assertSame('Beta Probe', $en['ZzzProbeBeta'] ?? null);
        self::assertSame('Gamma Probe', $en['ZzzProbeGamma'] ?? null);

        self::assertSame($onDiskBefore, (string) file_get_contents(JsonData::DECREES_FILE->path()));
    }

    /**
     * The enumeration half of defect 4: `findUnpublishedPathsUnder()` must widen too.
     *
     * `distributeI18n()` decides WHICH locale sidecars to rebuild by listing the i18n
     * folder — on disk, plus whatever this submitter has unpublished. A `cs.json` created
     * by an approved-but-unpublished batch is on neither disk nor the submitted list, so
     * with only the content half widened the folder listing would still not see it, the
     * next submission would not restage it, and the locale would be dropped.
     */
    public function testDefectFourALocaleSidecarFromAnApprovedButUnpublishedBatchIsNotDropped(): void
    {
        $csOnDisk = JsonData::DECREES_I18N_FOLDER->path() . '/cs.json';
        self::assertFileDoesNotExist($csOnDisk, 'fixture assumption: cs has no published decree i18n file');

        $batchA = $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe', ['cs' => 'Alfa']);
        $this->approve($batchA);

        $batchB   = $this->submitDecreeExpectingSubmitted('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
        $proposal = $this->pendingContentByPath($batchB);

        $csPath = 'jsondata/sourcedata/rite/roman/decrees/i18n/cs.json';
        self::assertArrayHasKey($csPath, $proposal, 'the cs sidecar from the approved batch must be carried into the new batch');

        /** @var array<string, string> $cs */
        $cs = json_decode($proposal[$csPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alfa', $cs['ZzzProbeAlpha'] ?? null);

        self::assertFileDoesNotExist($csOnDisk, 'queue mode still writes nothing to disk');
    }

    /**
     * Defect 4, in the direction that resurrects deleted data.
     *
     * `Router` gates decree DELETE at the `admin` relation and
     * `ChangeRequestReview::administers()` auto-approves on exactly that relation, so in
     * queue mode EVERY decree DELETE is approved the instant it is submitted. With the
     * pre-fix accumulation base that approved deletion left the queue immediately, and the
     * submitter's very next decree write rebuilt `decrees.json` from a disk that still held
     * the deleted decree — silently putting it back, along with its i18n entries.
     *
     * The auto-approval is simulated with `approveBatch()` rather than by pointing the test
     * at a real OpenFGA tuple: `approveBatch()` is literally what `administers()` triggers
     * and what `POST /admin/change-requests/{batchId}/approve` calls, and this class
     * deliberately runs against an unreachable store so that nothing is auto-approved by
     * accident.
     */
    public function testDefectFourAnApprovedDeletionIsNotRevertedByTheNextProposal(): void
    {
        $onDiskBefore = (string) file_get_contents(JsonData::DECREES_FILE->path());
        self::assertStringContainsString('MaryMotherChurch_Create', $onDiskBefore, 'fixture assumption: this decree is published');

        $deleteResponse = ( new DecreesHandler(['MaryMotherChurch_Create']) )->handle(
            $this->withOidcUser($this->requestFor('DELETE', '/decrees/MaryMotherChurch_Create'), 'editor-1')
        );
        self::assertSame(200, $deleteResponse->getStatusCode());
        $deleteBody = $this->decodeJsonBody($deleteResponse);
        self::assertSame('submitted', $deleteBody['disposition']);
        /** @var array<string, mixed> $deleteChangeRequest */
        $deleteChangeRequest = $deleteBody['change_request'];
        $this->approve((string) $deleteChangeRequest['batch_id']);

        $batchNext = $this->submitDecreeExpectingSubmitted('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
        $proposal  = $this->pendingContentByPath($batchNext);

        $decreesPath = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        self::assertArrayHasKey($decreesPath, $proposal);
        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);
        $ids     = array_column($decrees, 'decree_id');

        // THE regression assertion: the approved deletion must not be undone.
        self::assertNotContains('MaryMotherChurch_Create', $ids, 'an approved deletion must not be resurrected by the next proposal');
        self::assertContains('ZzzProbeBeta_Create', $ids);

        // Its i18n entry was garbage-collected by the DELETE and must stay gone too.
        $enPath = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        self::assertArrayHasKey($enPath, $proposal);
        /** @var array<string, string> $en */
        $en = json_decode($proposal[$enPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('MaryMotherChurch', $en, 'the GC\'d translation must not come back either');

        self::assertSame($onDiskBefore, (string) file_get_contents(JsonData::DECREES_FILE->path()));
    }

    /**
     * The other half of the widened predicate: only work that is genuinely still on its way
     * into the repository accumulates. A REJECTED or WITHDRAWN batch is work the queue has
     * thrown away, and letting it accumulate would silently resurrect content a reviewer
     * refused — the mirror image of the defect above.
     */
    public function testDefectFourARejectedOrWithdrawnBatchNeverAccumulates(): void
    {
        $batchA = $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');
        $this->approve($batchA);

        // Batch B accumulates onto A (Alpha + Beta) and is then rejected outright.
        $batchB = $this->submitDecreeExpectingSubmitted('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
        self::assertGreaterThan(0, $this->repo->rejectBatch($batchB, 'reviewer-1', 'no'));

        $batchC = $this->submitDecreeExpectingSubmitted('ZzzProbeGamma_Create', 'ZzzProbeGamma', 'Gamma Probe');
        $ids    = $this->decreeIdsIn($batchC);
        self::assertContains('ZzzProbeAlpha_Create', $ids, 'the approved batch is still the accumulation base');
        self::assertContains('ZzzProbeGamma_Create', $ids);
        self::assertNotContains('ZzzProbeBeta_Create', $ids, 'a rejected batch must never accumulate');

        // Now withdraw C and submit D: neither the rejected B nor the withdrawn C may return.
        self::assertGreaterThan(0, $this->repo->withdrawBatch($batchC, 'editor-1'));

        $batchD = $this->submitDecreeExpectingSubmitted('ZzzProbeDelta_Create', 'ZzzProbeDelta', 'Delta Probe');
        $ids    = $this->decreeIdsIn($batchD);
        self::assertContains('ZzzProbeAlpha_Create', $ids);
        self::assertContains('ZzzProbeDelta_Create', $ids);
        self::assertNotContains('ZzzProbeBeta_Create', $ids, 'a rejected batch must never accumulate');
        self::assertNotContains('ZzzProbeGamma_Create', $ids, 'a withdrawn batch must never accumulate');
    }

    /**
     * A queued row that will not decode must FAIL CLOSED, exactly as its siblings do.
     *
     * A sidecar is rebuilt by loading it, mutating one key and restaging the whole file, so
     * `return is_array($decoded) ? $decoded : [];` — the old body of
     * `DecreesHandler::loadSidecarArray()` — turned an undecodable row into an EMPTY
     * sidecar and staged it: a `201` that deletes every published decree name for that
     * locale, silently. `loadDecreesDatabase()` throws a 503 on the same input, and the
     * disk branch throws `JsonException` through `JSON_THROW_ON_ERROR`; the queued sidecar
     * branch was the one outlier, and now matches.
     */
    public function testACorruptQueuedSidecarRowFailsClosedInsteadOfEmptyingTheLocale(): void
    {
        $batchA = $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');

        $enPath   = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        $proposal = $this->pendingContentByPath($batchA);
        self::assertArrayHasKey($enPath, $proposal);
        self::assertStringContainsString('StJohnNewman', $proposal[$enPath], 'fixture assumption: en.json carries the published names');

        $corrupt = self::$pdo?->prepare(
            'UPDATE sourcedata_change_requests SET content = :content WHERE batch_id = :batch_id AND path = :path'
        );
        self::assertNotNull($corrupt);
        $corrupt->execute(['content' => '{ this is not JSON', 'batch_id' => $batchA, 'path' => $enPath]);
        self::assertSame(1, $corrupt->rowCount());

        $this->expectException(ServiceUnavailableException::class);
        $this->submitDecree('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
    }

    /**
     * Defect 5 (the last one in this family): a supersede must carry forward what the
     * incoming batch does NOT restage, instead of deleting it with the rest of the batch.
     *
     * Superseding deletes WHOLE batches that collide on any incoming path, and the
     * accumulation of {@see \LiturgicalCalendar\Api\Handlers\DecreesHandler::loadSidecarArray()}
     * only ever reaches the paths the incoming request restages. So every OTHER path the
     * superseded batch held was deleted with nothing carrying it forward.
     *
     * `DecreeWritePayloadGuard` makes `readings` OPTIONAL on PATCH, so this is reachable
     * through the ordinary API with no unusual client at all: create a decree with i18n and
     * readings, then PATCH it with i18n alone (correcting a translation, say). The PATCH
     * stages `decrees.json` and the i18n sidecars but not `decrees/lectionary/en.json`, and
     * the whole-batch supersede then took the readings with it — leaving a proposal that
     * inscribes a brand-new liturgical event with no lectionary readings anywhere.
     *
     * The identical sequence in DISK mode keeps both sidecars, so this was a queue-mode-only
     * divergence, and one that only ever hit `submitted` work: the non-admin editor, who is
     * the primary user of the queue.
     */
    public function testDefectFiveAPatchThatOmitsReadingsMustNotSweepTheReadingsSidecar(): void
    {
        $decreesPath    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        $enPath         = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        $lectionaryPath = 'jsondata/sourcedata/rite/roman/decrees/lectionary/en.json';

        $batchA = $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');
        self::assertArrayHasKey(
            $lectionaryPath,
            $this->pendingContentByPath($batchA),
            'fixture assumption: the creating PUT stages the readings sidecar'
        );

        // The PATCH corrects the translation only. `readings` is optional on PATCH, so a
        // client that is not changing them simply leaves them out — and this request then
        // never restages the lectionary sidecar.
        $batchB   = $this->patchDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe Revised');
        $proposal = $this->pendingContentByPath($batchB);
        self::assertNotContains(
            $lectionaryPath,
            $this->stagedPathsOfRequest($batchB),
            'fixture assumption: the PATCH itself never restages the readings sidecar'
        );

        // THE regression assertion: the readings the PATCH never mentioned must have been
        // carried forward onto the new batch, not deleted with the batch that held them.
        self::assertArrayHasKey($lectionaryPath, $proposal, 'the readings sidecar must survive a PATCH that omits readings');
        /** @var array<string, array<string, string>> $readings */
        $readings = json_decode($proposal[$lectionaryPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('ZzzProbeAlpha', $readings);
        self::assertSame('Genesis 1:1', $readings['ZzzProbeAlpha']['first_reading'] ?? null);

        // ...and the resulting proposal is internally consistent: the event exists in the
        // aggregate, it has a name, and it has readings.
        self::assertArrayHasKey($decreesPath, $proposal);
        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('ZzzProbeAlpha_Create', array_column($decrees, 'decree_id'));

        self::assertArrayHasKey($enPath, $proposal);
        /** @var array<string, string> $en */
        $en = json_decode($proposal[$enPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alpha Probe Revised', $en['ZzzProbeAlpha'] ?? null);

        // Exactly one cumulative proposal for this submitter, as the class docblock promises.
        self::assertCount(1, $this->repo->listBySubmitter('editor-1'));
    }

    /**
     * Defect 5, in its worst form: a `setProperty`/`grade` PUT stages `decrees.json` ALONE.
     *
     * `DecreeWritePayloadGuard` FORBIDS both `i18n` and `readings` for that action — a grade
     * change does not touch the event's name or its lectionary — so the batch consists of a
     * single aggregate row, and the whole-batch supersede swept BOTH sidecars of the prior
     * proposal. What the reviewer was then shown was a `decrees.json` entry inscribing an
     * event with no name in any locale and no readings in any locale: internally
     * inconsistent, and approving it published exactly that.
     */
    public function testDefectFiveASetPropertyGradePutMustNotSweepEitherSidecar(): void
    {
        $decreesPath    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        $enPath         = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        $lectionaryPath = 'jsondata/sourcedata/rite/roman/decrees/lectionary/en.json';

        $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');

        $batchB = $this->submitGradeDecreeExpectingSubmitted('ZzzProbeAlpha_Upgrade', 'ZzzProbeAlpha');
        self::assertSame(
            [$decreesPath],
            $this->stagedPathsOfRequest($batchB),
            'fixture assumption: a setProperty/grade PUT stages the aggregate alone'
        );

        $proposal = $this->pendingContentByPath($batchB);

        // THE regression assertions: both sidecars the grade change never mentioned survive.
        self::assertArrayHasKey($enPath, $proposal, 'the i18n sidecar must survive a setProperty/grade PUT');
        /** @var array<string, string> $en */
        $en = json_decode($proposal[$enPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alpha Probe', $en['ZzzProbeAlpha'] ?? null, 'the new event must still have a name');

        self::assertArrayHasKey($lectionaryPath, $proposal, 'the readings sidecar must survive a setProperty/grade PUT');
        /** @var array<string, array<string, string>> $readings */
        $readings = json_decode($proposal[$lectionaryPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('ZzzProbeAlpha', $readings, 'the new event must still have readings');

        // The aggregate carries both decrees, so the proposal is coherent as a whole.
        self::assertArrayHasKey($decreesPath, $proposal);
        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);
        $ids     = array_column($decrees, 'decree_id');
        self::assertContains('ZzzProbeAlpha_Create', $ids);
        self::assertContains('ZzzProbeAlpha_Upgrade', $ids);

        self::assertCount(1, $this->repo->listBySubmitter('editor-1'));
    }

    /**
     * Three submissions, each staging a DIFFERENT subset of paths, must leave one batch
     * holding the union — which is what the class docblock means by "what that submitter has
     * in flight is always their cumulative proposal".
     *
     * The subsets are chosen so that no single step restages everything:
     * a create with a locale that has no file on disk (`decrees.json` + every i18n sidecar
     * incl. `cs` + `lectionary/en.json`), then a grade change (`decrees.json` alone), then a
     * translation-only PATCH (`decrees.json` + the i18n sidecars, but no readings).
     */
    public function testDefectFiveThreeSubmissionsStagingDifferentSubsetsAccumulateIntoOneProposal(): void
    {
        $decreesPath    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        $enPath         = 'jsondata/sourcedata/rite/roman/decrees/i18n/en.json';
        $csPath         = 'jsondata/sourcedata/rite/roman/decrees/i18n/cs.json';
        $lectionaryPath = 'jsondata/sourcedata/rite/roman/decrees/lectionary/en.json';

        self::assertFileDoesNotExist(
            JsonData::DECREES_I18N_FOLDER->path() . '/cs.json',
            'fixture assumption: cs has no published decree i18n file'
        );

        $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe', ['cs' => 'Alfa']);
        $batchB = $this->submitGradeDecreeExpectingSubmitted('ZzzProbeAlpha_Upgrade', 'ZzzProbeAlpha');
        self::assertSame([$decreesPath], $this->stagedPathsOfRequest($batchB));

        $batchC = $this->patchDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe Revised', ['cs' => 'Alfa Revidovana']);
        self::assertNotContains($lectionaryPath, $this->stagedPathsOfRequest($batchC));

        $batches = $this->repo->listBySubmitter('editor-1');
        self::assertCount(1, $batches, 'one cumulative proposal, not three');

        $proposal = $this->pendingContentByPath($batchC);

        self::assertArrayHasKey($decreesPath, $proposal);
        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);
        $ids     = array_column($decrees, 'decree_id');
        self::assertContains('ZzzProbeAlpha_Create', $ids);
        self::assertContains('ZzzProbeAlpha_Upgrade', $ids);

        self::assertArrayHasKey($enPath, $proposal);
        /** @var array<string, string> $en */
        $en = json_decode($proposal[$enPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alpha Probe Revised', $en['ZzzProbeAlpha'] ?? null);

        self::assertArrayHasKey($csPath, $proposal, 'the queue-only cs sidecar must survive all three steps');
        /** @var array<string, string> $cs */
        $cs = json_decode($proposal[$csPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alfa Revidovana', $cs['ZzzProbeAlpha'] ?? null);

        self::assertArrayHasKey($lectionaryPath, $proposal, 'the readings staged only in step 1 must survive steps 2 and 3');
        /** @var array<string, array<string, string>> $readings */
        $readings = json_decode($proposal[$lectionaryPath], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('ZzzProbeAlpha', $readings);
    }

    /**
     * A carried-forward row belongs to the batch that now owns it, wholly: it is withdrawn
     * with it, rejected with it, and leaves the accumulation base with it.
     *
     * Re-parenting must not create a row that a decision misses — a row left behind at
     * `submitted` while its batch was rejected would both violate the one-status-per-batch
     * property and keep accumulating content a reviewer refused. `withdrawBatch()` and
     * `decideBatch()` both key on `batch_id`, so this holds by construction; it is asserted
     * because the whole point of the fix is that a batch's membership now changes over time.
     */
    public function testDefectFiveCarriedForwardRowsAreWithdrawnAndRejectedWithTheBatchThatOwnsThem(): void
    {
        $lectionaryPath = 'jsondata/sourcedata/rite/roman/decrees/lectionary/en.json';

        // --- withdraw ---
        $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');
        $batchB = $this->submitGradeDecreeExpectingSubmitted('ZzzProbeAlpha_Upgrade', 'ZzzProbeAlpha');
        $rowsB  = $this->repo->getBatch($batchB);
        self::assertGreaterThan(1, count($rowsB), 'batch B must own the rows carried forward from batch A');
        self::assertContains($lectionaryPath, array_column($rowsB, 'path'));

        self::assertSame(
            count($rowsB),
            $this->repo->withdrawBatch($batchB, 'editor-1'),
            'every row of the batch, carried-forward ones included, must transition as one unit'
        );
        self::assertSame(
            ['withdrawn'],
            $this->distinctReviewStatusesOf($batchB),
            'a batch must never be left mixed-status by a withdrawal'
        );

        $batchC = $this->submitDecreeExpectingSubmitted('ZzzProbeGamma_Create', 'ZzzProbeGamma', 'Gamma Probe');
        self::assertNotContains(
            'ZzzProbeAlpha_Create',
            $this->decreeIdsIn($batchC),
            'a withdrawn batch must not accumulate, however its rows got there'
        );

        // --- reject ---
        $batchD = $this->submitGradeDecreeExpectingSubmitted('ZzzProbeGamma_Upgrade', 'ZzzProbeGamma');
        $rowsD  = $this->repo->getBatch($batchD);
        self::assertGreaterThan(1, count($rowsD), 'batch D must own the rows carried forward from batch C');
        self::assertContains($lectionaryPath, array_column($rowsD, 'path'));

        self::assertSame(
            count($rowsD),
            $this->repo->rejectBatch($batchD, 'reviewer-1', 'no'),
            'every row of the batch, carried-forward ones included, must transition as one unit'
        );
        self::assertSame(['rejected'], $this->distinctReviewStatusesOf($batchD));

        $batchE = $this->submitDecreeExpectingSubmitted('ZzzProbeEpsilon_Create', 'ZzzProbeEpsilon', 'Epsilon Probe');
        $ids    = $this->decreeIdsIn($batchE);
        self::assertNotContains('ZzzProbeGamma_Create', $ids, 'a rejected batch must not accumulate, however its rows got there');
        self::assertNotContains('ZzzProbeGamma_Upgrade', $ids);
        self::assertContains('ZzzProbeEpsilon_Create', $ids);
    }

    /**
     * The ordering in {@see SourceDataChangeRequestRepository::findUnpublishedContent()}
     * still selects correctly once a row can be re-parented.
     *
     * A carried-forward row keeps its ORIGINAL `created_at` — that column records when the
     * CONTENT was written, and rewriting it would claim content is newer than it is — so a
     * `submitted` row for a path can now be older than the batch it belongs to. The
     * `( review_status = 'submitted' ) DESC` leading key is what keeps that correct: a row
     * that is submitted NOW has been submitted since it was created (a decision is one-way),
     * so `idx_scr_unique_pending_path_submitter` guarantees no other row for that
     * `(path, submitter)` was created while it existed — every approved-but-unpublished
     * sibling is therefore strictly older, and none can float above it.
     *
     * Here the approved batch A holds `lectionary/en.json` with Alpha alone, and the
     * carried-forward row holds Alpha AND Beta. Reading the approved row instead would
     * silently drop Beta's readings on the very next submission.
     */
    public function testDefectFiveACarriedForwardRowOutranksAnOlderApprovedRowForTheSamePath(): void
    {
        $lectionaryPath = 'jsondata/sourcedata/rite/roman/decrees/lectionary/en.json';

        $batchA = $this->submitDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe');
        $this->approve($batchA);

        $batchB = $this->submitDecreeExpectingSubmitted('ZzzProbeBeta_Create', 'ZzzProbeBeta', 'Beta Probe');
        $batchC = $this->patchDecreeExpectingSubmitted('ZzzProbeAlpha_Create', 'ZzzProbeAlpha', 'Alpha Probe Revised');

        $carried = $this->repo->getBatch($batchC);
        self::assertContains($lectionaryPath, array_column($carried, 'path'), 'batch C must own the carried-forward readings row');
        self::assertNotSame([], $this->repo->getBatch($batchA), 'the approved batch A is still in the queue holding the older readings');
        self::assertSame([], $this->repo->getBatch($batchB), 'batch B was superseded');

        $batchD   = $this->submitDecreeExpectingSubmitted('ZzzProbeGamma_Create', 'ZzzProbeGamma', 'Gamma Probe');
        $proposal = $this->pendingContentByPath($batchD);
        self::assertArrayHasKey($lectionaryPath, $proposal);
        /** @var array<string, array<string, string>> $readings */
        $readings = json_decode($proposal[$lectionaryPath], true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('ZzzProbeAlpha', $readings);
        self::assertArrayHasKey('ZzzProbeBeta', $readings, 'the carried-forward row, not the older approved one, is the accumulation base');
        self::assertArrayHasKey('ZzzProbeGamma', $readings);
    }

    /**
     * The distinct review statuses a batch's rows currently hold. More than one means the
     * batch is incoherent: it can no longer be approved or rejected as a unit.
     *
     * @return list<string>
     */
    private function distinctReviewStatusesOf(string $batchId): array
    {
        $statuses = [];
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertIsString($row['review_status']);
            $statuses[] = $row['review_status'];
        }

        return array_values(array_unique($statuses));
    }

    /**
     * The paths the REQUEST that opened this batch staged, as its own response reported
     * them — which is not the same as the paths the batch holds once a supersede has
     * carried other rows onto it. Recorded per batch id by the submit helpers below.
     *
     * @return list<string>
     */
    private function stagedPathsOfRequest(string $batchId): array
    {
        self::assertArrayHasKey($batchId, $this->stagedPathsByBatch, 'no recorded request for this batch');

        return $this->stagedPathsByBatch[$batchId];
    }

    /**
     * A PATCH that corrects translations only: `readings` is omitted, which
     * `DecreeWritePayloadGuard` permits on PATCH, so the lectionary sidecar is not restaged.
     *
     * @param array<string, string> $extraI18n
     */
    private function patchDecreeExpectingSubmitted(string $decreeId, string $eventKey, string $englishName, array $extraI18n = []): string
    {
        $payload = self::decreePayload($decreeId, $eventKey, $englishName, $extraI18n);
        unset($payload['readings']);

        $response = ( new DecreesHandler([$decreeId]) )->handle(
            $this->withOidcUser(
                $this->requestFor('PATCH', "/decrees/{$decreeId}", ['Accept-Language' => 'en'], $payload),
                'editor-1'
            )
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->recordSubmitted($response);
    }

    /**
     * A `setProperty`/`grade` PUT: `DecreeWritePayloadGuard` forbids both `i18n` and
     * `readings` for this action, so the request stages `decrees.json` and nothing else.
     */
    private function submitGradeDecreeExpectingSubmitted(string $decreeId, string $eventKey): string
    {
        $payload = [
            'decree_id'        => $decreeId,
            'decree_date'      => '2025-02-01',
            'decree_protocol'  => 'Prot. N. 2/25',
            'description'      => 'Queue-mode carry-forward probe: grade change only.',
            'liturgical_event' => [
                'event_key' => $eventKey,
                'grade'     => 4,
                'calendar'  => 'GENERAL ROMAN',
            ],
            'metadata'         => [
                'action'     => 'setProperty',
                'property'   => 'grade',
                'since_year' => 2025,
                'url'        => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html',
            ],
        ];

        $response = ( new DecreesHandler([$decreeId]) )->handle(
            $this->withOidcUser(
                $this->requestFor('PUT', "/decrees/{$decreeId}", ['Accept-Language' => 'en'], $payload),
                'editor-1'
            )
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->recordSubmitted($response);
    }

    /**
     * Assert the write was queued rather than auto-approved, remember which paths the
     * request itself staged, and hand back the batch id.
     */
    private function recordSubmitted(ResponseInterface $response): string
    {
        $body = $this->decodeJsonBody($response);
        self::assertSame('submitted', $body['disposition'], 'must not be auto-approved for this test to be meaningful');

        /** @var array{batch_id: string, paths: list<string>} $changeRequest */
        $changeRequest = $body['change_request'];
        $batchId       = $changeRequest['batch_id'];

        $this->stagedPathsByBatch[$batchId] = $changeRequest['paths'];

        return $batchId;
    }

    /**
     * The decree ids staged for `decrees.json` by a batch.
     *
     * @return list<string>
     */
    private function decreeIdsIn(string $batchId): array
    {
        $proposal    = $this->pendingContentByPath($batchId);
        $decreesPath = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        self::assertArrayHasKey($decreesPath, $proposal);

        /** @var list<array<string, mixed>> $decrees */
        $decrees = json_decode($proposal[$decreesPath], true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> $ids */
        $ids = array_column($decrees, 'decree_id');

        return $ids;
    }

    /**
     * Approve a batch the way auto-approval and `POST /admin/change-requests/{id}/approve`
     * both do: one `UPDATE`, no file I/O, `publication_status` untouched at `none`.
     */
    private function approve(string $batchId): void
    {
        self::assertGreaterThan(0, $this->repo->approveBatch($batchId, 'reviewer-1'), 'the batch must actually transition');
    }

    /** @param array<string, string> $extraI18n */
    private function submitDecreeExpectingSubmitted(string $decreeId, string $eventKey, string $englishName, array $extraI18n = []): string
    {
        $response = $this->submitDecree($decreeId, $eventKey, $englishName, $extraI18n);
        self::assertSame(201, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertSame('submitted', $body['disposition'], 'must not be auto-approved for this test to be meaningful');

        /** @var array<string, mixed> $changeRequest */
        $changeRequest = $body['change_request'];

        return (string) $changeRequest['batch_id'];
    }

    /**
     * A decree PUT payload shaped like the ones the admin-decrees frontend sends.
     *
     * @param array<string, string> $extraI18n Locales beyond `en`, e.g. one with no file on disk yet.
     * @return array<string, mixed>
     */
    private static function decreePayload(string $decreeId, string $eventKey, string $englishName, array $extraI18n = []): array
    {
        return [
            'decree_id'        => $decreeId,
            'decree_date'      => '2025-01-01',
            'decree_protocol'  => 'Prot. N. 1/25',
            'description'      => 'Queue-mode accumulation probe.',
            'liturgical_event' => [
                'event_key' => $eventKey,
                'day'       => 14,
                'month'     => 2,
                'color'     => ['white'],
                'grade'     => 2,
                'common'    => ['Pastors'],
                'type'      => 'fixed',
                'calendar'  => 'GENERAL ROMAN',
            ],
            'metadata'         => [
                'action'     => 'createNew',
                'since_year' => 2025,
                'url'        => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html',
            ],
            'i18n'             => ['en' => $englishName] + $extraI18n,
            'readings'         => [
                'en' => [
                    'first_reading'      => 'Genesis 1:1',
                    'responsorial_psalm' => 'Psalm 1',
                    'gospel_acclamation' => 'John 1:1',
                    'gospel'             => 'John 1:1-14',
                ],
            ],
        ];
    }

    /** @param array<string, string> $extraI18n */
    private function submitDecree(string $decreeId, string $eventKey, string $englishName, array $extraI18n = []): ResponseInterface
    {
        return ( new DecreesHandler([$decreeId]) )->handle(
            $this->withOidcUser(
                $this->requestFor(
                    'PUT',
                    "/decrees/{$decreeId}",
                    ['Accept-Language' => 'en'],
                    self::decreePayload($decreeId, $eventKey, $englishName, $extraI18n)
                ),
                'editor-1'
            )
        );
    }

    /**
     * The batch's staged content, keyed by repository-relative path.
     *
     * @return array<string, string>
     */
    private function pendingContentByPath(string $batchId): array
    {
        $byPath = [];
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertIsString($row['path']);
            self::assertIsString($row['content']);
            $byPath[$row['path']] = $row['content'];
        }

        return $byPath;
    }
}
