<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
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
final class SourceDataChangeRequestSupersedeRegressionTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    private SourceDataChangeRequestRepository $repo;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var list<string> */
    private array $extraFixturePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

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
        // correct and intended: there is at most one pending proposal per (path, submitter).
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
