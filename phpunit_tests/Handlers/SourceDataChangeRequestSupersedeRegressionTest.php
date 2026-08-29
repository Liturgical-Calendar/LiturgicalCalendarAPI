<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Regression coverage for the two defects the final review of
 * feature/sourcedata-change-requests found in the supersede logic:
 *
 * - Defect 1 (silent data loss): superseding by resource, rather than by path,
 *   deleted an unrelated pending file under a resource that is not 1:1 with a
 *   file (`rite_calendar_test:<rite>`, `general_roman_calendar:decrees`).
 * - Defect 2 (uncaught 500): when the same path re-scopes to a different
 *   resource (a PATCH re-scope), the old supersede DELETE (keyed on resource)
 *   missed the prior row and the INSERT then violated
 *   idx_scr_unique_pending_path_submitter, raising an uncaught PDOException.
 *
 * Every other queue-mode test in this suite drives `ChangeRequestSourceDataWriter`
 * through `ChangeRequestTraitHost`, a stand-in for `WritesSourceData` that never
 * touches a real handler. Both defects live in the interaction between a real
 * handler's routing (path is fixed by the route; resource is derived from the
 * payload) and the repository's supersede logic, so a stand-in cannot exercise
 * either — these tests drive `TestsHandler::handle()` directly instead.
 */
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
}
