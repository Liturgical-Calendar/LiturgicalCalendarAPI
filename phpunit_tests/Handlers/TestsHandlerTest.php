<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedMediaTypeException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestsHandler::class)]
final class TestsHandlerTest extends AbstractHandlerTestCase
{
    /** Absolute path to a temporary test fixture created during a test; cleaned up in tearDown. */
    private ?string $testFixturePath = null;

    /**
     * Additional fixture paths to clean up in tearDown, for tests that touch more than one
     * partition (e.g. asserting a file was NOT written to a sibling rite's folder). A failing
     * assertion on such a path must not leave a stray fixture behind in the shipped corpus.
     *
     * @var list<string>
     */
    private array $extraFixturePaths = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->testFixturePath !== null && file_exists($this->testFixturePath)) {
            unlink($this->testFixturePath);
        }
        $this->testFixturePath = null;
        foreach ($this->extraFixturePaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->extraFixturePaths = [];
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new TestsHandler() )->handle(
            $this->requestFor('OPTIONS', '/tests', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsTestSuiteIndex(): void
    {
        $response = ( new TestsHandler() )->handle($this->requestFor('GET', '/tests'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_tests', $body);
        self::assertNotEmpty($body['litcal_tests']);
    }

    /**
     * The corpus-wide index must actually span every rite partition, not just be
     * non-empty. `Rite::cases()` includes both `roman` and `ambrosian`; if the
     * null-rite branch in `TestsHandler::collectTests()` ever regressed to a single
     * hard-coded rite, the suite would still be non-empty (the Roman partition alone
     * has dozens of files) and this would go unnoticed without an assertion that
     * checks for partitions plural. `MaryMotherChurchTest` (roman) and
     * `StIgnatiusOfLoyolaTest` (ambrosian) both resolve to a rite-level
     * `rite_calendar_test` scope, so their `scope.object_id` doubles as a
     * per-partition marker.
     */
    public function testGetIndexSpansAllRitePartitions(): void
    {
        $response = ( new TestsHandler() )->handle($this->requestFor('GET', '/tests'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_tests', $body);

        $scopeIds = array_map(
            static fn (array $test): string => $test['scope']['object_type'] . ':' . $test['scope']['object_id'],
            $body['litcal_tests']
        );

        self::assertContains('rite_calendar_test:roman', $scopeIds);
        self::assertContains('rite_calendar_test:ambrosian', $scopeIds);
    }

    public function testGetSingleTestByNameReturnsThatTest(): void
    {
        // MaryMotherChurchTest ships with the repo per jsondata/tests/.
        $handler  = new TestsHandler(['MaryMotherChurchTest'], Rite::ROMAN);
        $response = $handler->handle($this->requestFor('GET', '/tests/roman/MaryMotherChurchTest'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
    }

    public function testUnknownTestIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new TestsHandler(['NotARealTest'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/tests/roman/NotARealTest'));
    }

    public function testTooManyPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['a', 'b'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/tests/roman/a/b'));
    }

    public function testPutWithMalformedPayloadIsValidationError(): void
    {
        // PUT (writes), with a JSON array of scalars (not objects). parseBodyPayload
        // surfaces this as a ValidationException at the AbstractHandler layer before
        // TestsHandler's own object check fires.
        $this->expectException(ValidationException::class);
        $req = $this->requestFor('PUT', '/tests/roman/SomeTest', [], '[1, 2, 3]')
            ->withHeader('Content-Type', 'application/json');
        ( new TestsHandler(['SomeTest'], Rite::ROMAN) )->handle($req);
    }

    public function testPutCreatesTestAtPathName(): void
    {
        /** @var array<string,mixed> $payload */
        $payload         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name'] = 'ZzzPutCreatedTest';

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzPutCreatedTest.json';

        $response = ( new TestsHandler(['ZzzPutCreatedTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzPutCreatedTest', [], $payload)
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertFileExists($this->testFixturePath);
    }

    public function testPutWithNoPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler() )->handle(
            $this->requestFor('PUT', '/tests', [], ['name' => 'SomeTest'])
        );
    }

    public function testPutExistingTestConflicts(): void
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );

        $this->expectException(ConflictException::class);
        ( new TestsHandler(['MaryMotherChurchTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/MaryMotherChurchTest', [], $payload)
        );
    }

    public function testPutBodyNameMismatchIsRejected(): void
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        // Body says MaryMotherChurchTest, path says ZzzOtherTest.

        $this->expectException(UnprocessableContentException::class);
        ( new TestsHandler(['ZzzOtherTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzOtherTest', [], $payload)
        );
    }

    public function testPutUnsafePathNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['..'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/..', [], ['name' => '..'])
        );
    }

    public function testPatchUpdatesExistingTest(): void
    {
        /** @var array<string,mixed> $payload */
        $payload         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name'] = 'ZzzPatchTargetTest';

        // Seed the fixture on disk, then PATCH it with an updated description.
        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzPatchTargetTest.json';
        file_put_contents($this->testFixturePath, json_encode($payload, JSON_THROW_ON_ERROR));

        $payload['description'] = 'Updated description via PATCH';

        $response = ( new TestsHandler(['ZzzPatchTargetTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzPatchTargetTest', [], $payload)
        );

        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string,mixed> $written */
        $written = json_decode((string) file_get_contents($this->testFixturePath), true);
        $this->assertSame('Updated description via PATCH', $written['description']);
    }

    public function testPatchWithNoPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler() )->handle(
            $this->requestFor('PATCH', '/tests', [], ['name' => 'SomeTest'])
        );
    }

    /**
     * Issue #790 follow-up: Router::route() restricts /tests writes to application/json
     * only. OpenFgaAuthorizationMiddleware's scope resolvers (forTestScopes()'s PUT-create
     * fallback, and forTestScopePayloadTarget()) only ever see getParsedBody(), which
     * JsonBodyParserMiddleware never populates for a YAML body — so a YAML PUT/PATCH was
     * never actually authorizable; it just failed unpredictably depending on whether
     * OpenFGA was configured (403 when it was, an inconsistent "worked" when it wasn't).
     * Mirroring the Router's restriction directly on the handler here (this test harness
     * bypasses the middleware pipeline entirely) pins that a YAML body is now rejected
     * with a clear, content-type-specific 415 rather than either of those — a
     * UnsupportedMediaTypeException, never a ForbiddenException.
     */
    public function testPatchWithYamlContentTypeIsRejectedAsUnsupportedMediaType(): void
    {
        $handler = ( new TestsHandler(['SomeTest'], Rite::ROMAN) )
            ->setAllowedRequestContentTypes([RequestContentType::JSON]);

        $request = $this->requestFor(
            'PATCH',
            '/tests/roman/SomeTest',
            ['Content-Type' => 'application/yaml'],
            "name: SomeTest\n"
        );

        $this->expectException(UnsupportedMediaTypeException::class);
        $handler->handle($request);
    }

    public function testPatchNonexistentTestIsUnprocessable(): void
    {
        /** @var array<string,mixed> $payload */
        $payload         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name'] = 'ZzzNoSuchTest';

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('does not exist');
        ( new TestsHandler(['ZzzNoSuchTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzNoSuchTest', [], $payload)
        );
    }

    public function testPatchBodyNameMismatchIsRejected(): void
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        // Body names an existing test, but the path addresses a different one.

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('This is not allowed');
        ( new TestsHandler(['ZzzOtherTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzOtherTest', [], $payload)
        );
    }

    public function testDeleteRejectsWrongPathParamCount(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['a', 'b'], Rite::ROMAN) )->handle($this->requestFor('DELETE', '/tests/roman/a/b'));
    }

    public function testDeleteUnknownOrUnsafeNameReturnsNotFound(): void
    {
        // TestScopeResolver::resolve() returns null for a non-existent (or unsafe
        // path-traversal) name, so the handler must 404 and never reach unlink().
        $name = 'NoSuchTest_' . bin2hex(random_bytes(4));
        $this->expectException(NotFoundException::class);
        ( new TestsHandler([$name], Rite::ROMAN) )->handle($this->requestFor('DELETE', "/tests/roman/{$name}"));
    }

    public function testDeletePurgeFailureDoesNotFailDeletion(): void
    {
        $testName              = 'PurgeFailFixture_' . bin2hex(random_bytes(6));
        $fixturePath           = JsonData::testsFolderFor(Rite::ROMAN)->path() . DIRECTORY_SEPARATOR . $testName . '.json';
        $this->testFixturePath = $fixturePath;
        file_put_contents($fixturePath, json_encode(
            ['name' => $testName, 'applies_to' => ['national_calendar' => 'US']],
            JSON_THROW_ON_ERROR
        ));

        // The purge throws, but the file is already deleted — the DELETE must
        // still succeed (204); the failure is logged and the reconciler retries.
        $purge = $this->createStub(ResourceTuplePurgeServiceInterface::class);
        $purge->method('purgeForObject')->willThrowException(new \RuntimeException('FGA unavailable'));

        $handler = new TestsHandler([$testName], Rite::ROMAN);
        $handler->setPurgeService($purge);

        $response = $handler->handle($this->requestFor('DELETE', "/tests/roman/{$testName}"));

        self::assertSame(204, $response->getStatusCode());
        $this->testFixturePath = null; // handler already removed the file
    }

    /**
     * After a successful test DELETE, the handler must call
     * ResourceTuplePurgeService::purgeForObject() with the correct FGA object
     * identifier so that editor/viewer operational tuples are cleaned up.
     *
     * A temporary fixture file with applies_to.national_calendar = 'US' is
     * created before the test runs; after a successful DELETE the file is gone.
     * tearDown removes the file if the test fails before the DELETE executes.
     */
    public function testDeletePurgesScopedTestOperationalTuples(): void
    {
        // --- Arrange: create a temp fixture that TestScopeResolver maps to national_calendar_test:roman/US ---
        // Unique per-run name so the fixture cannot collide with a real or
        // shared test file (the handler reads from the real JsonData::testsFolderFor(Rite::ROMAN),
        // so the fixture must live there). tearDown removes it via $testFixturePath.
        $testName              = 'PurgeFixture_' . bin2hex(random_bytes(6));
        $testsDir              = JsonData::testsFolderFor(Rite::ROMAN)->path();
        $fixturePath           = $testsDir . DIRECTORY_SEPARATOR . $testName . '.json';
        $this->testFixturePath = $fixturePath;

        file_put_contents($fixturePath, json_encode(
            ['name' => $testName, 'applies_to' => ['national_calendar' => 'US']],
            JSON_THROW_ON_ERROR
        ));

        // --- Build handler with injected mock purge service ------------------
        $handler = new TestsHandler([$testName], Rite::ROMAN);

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar_test:roman/US');
        $handler->setPurgeService($purge);

        // --- Act: issue DELETE (bypasses JWT middleware — in-process) --------
        $request  = $this->requestFor('DELETE', "/tests/roman/{$testName}");
        $response = $handler->handle($request);

        // --- Assert ----------------------------------------------------------
        self::assertSame(204, $response->getStatusCode());
        $this->testFixturePath = null; // handler already removed the file
        // purgeForObject assertion enforced by mock expectation above
    }

    public function testBareTestNameWithoutRiteSegmentIsRejected(): void
    {
        // The #787 hard break: /tests/MaryMotherChurchTest no longer addresses a test.
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['MaryMotherChurchTest'], null) )
            ->handle($this->requestFor('GET', '/tests/MaryMotherChurchTest'));
    }

    public function testCollectionWithoutRiteSegmentIsStillAllowed(): void
    {
        $response = ( new TestsHandler([], null) )->handle($this->requestFor('GET', '/tests'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPutRejectsPayloadWhoseRiteContradictsThePath(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']       = 'ZzzRiteMismatchTest';
        $payload['applies_to'] = ['rite' => 'roman'];

        // Guard against the guard silently not firing and the payload getting written anyway.
        $this->testFixturePath = JsonData::testsFolderFor(Rite::AMBROSIAN)->path() . '/ZzzRiteMismatchTest.json';

        // Addressed under /tests/ambrosian/... but the body says roman.
        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('The rite in the path and the rite in the body must match.');
        ( new TestsHandler(['ZzzRiteMismatchTest'], Rite::AMBROSIAN) )->handle(
            $this->requestFor('PUT', '/tests/ambrosian/ZzzRiteMismatchTest', [], $payload)
        );
    }

    public function testPutAcceptsPayloadWhoseRiteMatchesThePath(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']       = 'ZzzRiteMatchTest';
        $payload['applies_to'] = ['rite' => 'roman'];

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzRiteMatchTest.json';

        $response = ( new TestsHandler(['ZzzRiteMatchTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzRiteMatchTest', [], $payload)
        );
        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * A PUT addressed under /tests/ambrosian/... must land in the Ambrosian
     * partition (jsondata/tests/ambrosian/), not the Roman one. The sibling test
     * above (testPutAcceptsPayloadWhoseRiteMatchesThePath) uses roman on both
     * sides and only asserts the 201, so it would not catch a regression that
     * always wrote to `Rite::default()`'s (roman's) folder regardless of the
     * request's own rite.
     */
    public function testPutAmbrosianTestLandsInAmbrosianPartition(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::AMBROSIAN)->path() . '/StIgnatiusOfLoyolaTest.json'),
            true
        );
        $payload['name']       = 'ZzzAmbrosianPutTest';
        $payload['applies_to'] = ['rite' => 'ambrosian'];

        $ambrosianFixturePath  = JsonData::testsFolderFor(Rite::AMBROSIAN)->path() . '/ZzzAmbrosianPutTest.json';
        $romanFixturePath      = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzAmbrosianPutTest.json';
        $this->testFixturePath = $ambrosianFixturePath;
        // Not expected to exist (that's what assertFileDoesNotExist below checks), but if the
        // assertion ever fails because of a regression, this must still be cleaned up rather
        // than left behind as a stray fixture in the shipped corpus.
        $this->extraFixturePaths[] = $romanFixturePath;

        $response = ( new TestsHandler(['ZzzAmbrosianPutTest'], Rite::AMBROSIAN) )->handle(
            $this->requestFor('PUT', '/tests/ambrosian/ZzzAmbrosianPutTest', [], $payload)
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertFileExists($ambrosianFixturePath);
        self::assertFileDoesNotExist($romanFixturePath);
    }

    public function testPatchRejectsPayloadWhoseRiteContradictsThePath(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['applies_to'] = ['rite' => 'roman'];

        // Guard against the guard silently not firing and the payload getting written anyway.
        $this->testFixturePath = JsonData::testsFolderFor(Rite::AMBROSIAN)->path() . '/MaryMotherChurchTest.json';

        // MaryMotherChurchTest exists under roman/, but is addressed here under ambrosian/. Without
        // the rite guard, the pre-existing "does the file exist in this rite's partition?" check
        // would ALSO throw UnprocessableContentException (for the unrelated reason that no such
        // file exists under ambrosian/), so the exception message is asserted to make sure it is
        // actually the rite guard that fires, not that unrelated check.
        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('The rite in the path and the rite in the body must match.');
        ( new TestsHandler(['MaryMotherChurchTest'], Rite::AMBROSIAN) )->handle(
            $this->requestFor('PATCH', '/tests/ambrosian/MaryMotherChurchTest', [], $payload)
        );
    }

    public function testCollectionCarriesTheResolvedScope(): void
    {
        $response = ( new TestsHandler([], Rite::AMBROSIAN) )->handle($this->requestFor('GET', '/tests/ambrosian'));
        $body     = $this->decodeJsonBody($response);

        self::assertNotEmpty($body['litcal_tests']);

        // Assert on a specific, known test rather than looping over the whole collection:
        // the Ambrosian partition currently holds only StIgnatiusOfLoyolaTest, so a loop
        // assertion would hold vacuously true on a single iteration and would not actually
        // exercise per-item scope resolution if the partition ever grows.
        $stIgnatius = null;
        foreach ($body['litcal_tests'] as $test) {
            if (( $test['name'] ?? null ) === 'StIgnatiusOfLoyolaTest') {
                $stIgnatius = $test;
                break;
            }
        }
        self::assertNotNull($stIgnatius, 'StIgnatiusOfLoyolaTest was not found in the Ambrosian collection.');
        self::assertArrayHasKey('scope', $stIgnatius);
        self::assertSame('rite_calendar_test', $stIgnatius['scope']['object_type']);
        self::assertSame('ambrosian', $stIgnatius['scope']['object_id']);
    }

    public function testSingleTestCarriesTheResolvedScope(): void
    {
        $response = ( new TestsHandler(['PrayerUnbornTest'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/tests/roman/PrayerUnbornTest'));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('national_calendar_test', $body['scope']['object_type']);
        self::assertSame('roman/US', $body['scope']['object_id']);
    }

    public function testWritePayloadEchoingTheCorrectScopeIsAccepted(): void
    {
        // The ordinary load-edit-save cycle: the client GETs a test, edits one field, and
        // PUTs the whole object back with the server's own `scope` still attached. That
        // must not 422 — no legitimate client originates a scope value, so an echo is benign.
        /** @var array<string,mixed> $payload */
        $payload          = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']  = 'ZzzScopeEchoTest';
        $payload['scope'] = ['object_type' => 'rite_calendar_test', 'object_id' => 'roman'];

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzScopeEchoTest.json';

        $response = ( new TestsHandler(['ZzzScopeEchoTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzScopeEchoTest', [], $payload)
        );
        self::assertSame(201, $response->getStatusCode());

        // The echoed scope must NOT be persisted — it is derived, not stored.
        /** @var array<string,mixed> $stored */
        $stored = json_decode((string) file_get_contents($this->testFixturePath), true);
        self::assertArrayNotHasKey('scope', $stored);
    }

    public function testWritePayloadWithAContradictoryScopeIsRejected(): void
    {
        // A client that hand-derived the scope and got it wrong still gets a loud 422 —
        // silent divergence is what must be impossible, not the echo.
        /** @var array<string,mixed> $payload */
        $payload          = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']  = 'ZzzScopeMismatchTest';
        $payload['scope'] = ['object_type' => 'diocesan_calendar_test', 'object_id' => 'roman/rotter_nl'];

        // Guard against the guard silently not firing and the payload getting written anyway
        // (same defensive registration as the sibling rite-mismatch tests).
        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzScopeMismatchTest.json';

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('does not match the scope this test resolves to');
        ( new TestsHandler(['ZzzScopeMismatchTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzScopeMismatchTest', [], $payload)
        );
    }

    public function testPatchUnchangedScopeEchoIsAcceptedAndStripped(): void
    {
        // PATCH counterpart of the PUT echo test above: the ordinary load-edit-save cycle
        // where applies_to is untouched, so the payload-derived scope equals the stored
        // scope. Designed to kill the mutant that deletes the assertPayloadScopeAgrees()
        // call from handlePatchRequest(): with nothing validating/stripping `scope`, it
        // would simply be written to disk verbatim.
        /** @var array<string,mixed> $onDisk */
        $onDisk         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/PrayerUnbornTest.json'),
            true
        );
        $onDisk['name'] = 'ZzzScopePatchEchoTest';

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzScopePatchEchoTest.json';
        file_put_contents($this->testFixturePath, json_encode($onDisk, JSON_THROW_ON_ERROR));

        // applies_to is unchanged, and `scope` echoes exactly what a prior GET would have
        // returned for it (national_calendar_test:roman/US, from PrayerUnbornTest's applies_to).
        $payload          = $onDisk;
        $payload['scope'] = ['object_type' => 'national_calendar_test', 'object_id' => 'roman/US'];

        $response = ( new TestsHandler(['ZzzScopePatchEchoTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzScopePatchEchoTest', [], $payload)
        );
        self::assertSame(200, $response->getStatusCode());

        // The echoed scope must NOT be persisted on the PATCH path either.
        /** @var array<string,mixed> $stored */
        $stored = json_decode((string) file_get_contents($this->testFixturePath), true);
        self::assertArrayNotHasKey('scope', $stored);
        self::assertSame($onDisk['applies_to'], $stored['applies_to']);
    }

    /**
     * Issue #790: once PATCH is allowed to re-scope a test, the `scope` echo it accepts
     * must be the scope the payload's `applies_to` resolves to (the NEW scope) — never the
     * scope the stored file had before the write. Accepting the stale echo would be
     * incoherent with what `OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget()`
     * actually authorized (the new scope), and would let a client keep proving it once knew
     * the old scope rather than the one this write is landing in.
     *
     * The fixture is seeded scoped to a national calendar (national_calendar_test:roman/US,
     * PrayerUnbornTest's applies_to) and the PATCH re-scopes it to the rite level (drops
     * applies_to.national_calendar) while echoing the NEW scope the payload resolves to.
     */
    public function testPatchReScopingWithNewScopeEchoIsAcceptedAndStripped(): void
    {
        /** @var array<string,mixed> $onDisk */
        $onDisk         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/PrayerUnbornTest.json'),
            true
        );
        $onDisk['name'] = 'ZzzScopeReScopeNewEchoTest';

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzScopeReScopeNewEchoTest.json';
        file_put_contents($this->testFixturePath, json_encode($onDisk, JSON_THROW_ON_ERROR));

        $payload               = $onDisk;
        $payload['applies_to'] = ['rite' => 'roman']; // drops national_calendar => re-scopes to the rite level
        $payload['scope']      = ['object_type' => 'rite_calendar_test', 'object_id' => 'roman']; // the NEW scope

        $response = ( new TestsHandler(['ZzzScopeReScopeNewEchoTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzScopeReScopeNewEchoTest', [], $payload)
        );
        self::assertSame(200, $response->getStatusCode());

        // The echoed scope must NOT be persisted, and the re-scoping itself took effect.
        /** @var array<string,mixed> $stored */
        $stored = json_decode((string) file_get_contents($this->testFixturePath), true);
        self::assertArrayNotHasKey('scope', $stored);
        self::assertSame(['rite' => 'roman'], $stored['applies_to']);
    }

    /**
     * Twin of testPatchReScopingWithNewScopeEchoIsAcceptedAndStripped(): the same re-scoping
     * PATCH, but echoing the STALE (pre-write, on-disk) scope instead of the new one. That
     * must now be rejected — accepting it would be exactly the incoherence #790's fix closes.
     */
    public function testPatchReScopingWithStaleScopeEchoIsRejected(): void
    {
        /** @var array<string,mixed> $onDisk */
        $onDisk         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/PrayerUnbornTest.json'),
            true
        );
        $onDisk['name'] = 'ZzzScopeReScopeStaleEchoTest';

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzScopeReScopeStaleEchoTest.json';
        file_put_contents($this->testFixturePath, json_encode($onDisk, JSON_THROW_ON_ERROR));

        $payload               = $onDisk;
        $payload['applies_to'] = ['rite' => 'roman']; // drops national_calendar => re-scopes to the rite level
        // Echoes the OLD, now-obsolete scope — what a client's prior GET (before this edit)
        // would have returned.
        $payload['scope'] = ['object_type' => 'national_calendar_test', 'object_id' => 'roman/US'];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('does not match the scope this test resolves to');
        ( new TestsHandler(['ZzzScopeReScopeStaleEchoTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzScopeReScopeStaleEchoTest', [], $payload)
        );
    }
}
