<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestsHandler::class)]
final class TestsHandlerTest extends AbstractHandlerTestCase
{
    /** Absolute path to a temporary test fixture created during a test; cleaned up in tearDown. */
    private ?string $testFixturePath = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->testFixturePath !== null && file_exists($this->testFixturePath)) {
            unlink($this->testFixturePath);
        }
        $this->testFixturePath = null;
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
        foreach ($body['litcal_tests'] as $test) {
            self::assertArrayHasKey('scope', $test);
            self::assertSame('rite_calendar_test', $test['scope']['object_type']);
            self::assertSame('ambrosian', $test['scope']['object_id']);
        }
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

    public function testPatchPayloadEchoingTheCorrectScopeIsAcceptedAndStripped(): void
    {
        // PATCH counterpart of the PUT echo test above, designed to kill two mutants a
        // plain "unchanged applies_to" PATCH echo would NOT catch:
        //
        //   1. Deleting the assertPayloadScopeAgrees() call from handlePatchRequest():
        //      with nothing validating/stripping `scope`, it would simply be written
        //      to disk verbatim.
        //   2. Forcing `$resolved` to always take the create-time resolveFromPayload()
        //      fallback (i.e. resolve() against the file already on disk is never
        //      consulted): if the payload's own applies_to always agreed with what is
        //      already on disk, that fallback would silently compute the SAME answer
        //      and the mutant would be invisible.
        //
        // To make (2) observable, the fixture is seeded scoped to a national calendar
        // (national_calendar_test:roman/US, PrayerUnbornTest's applies_to) and the PATCH
        // re-scopes it back to the rite level (drops applies_to.national_calendar) while
        // echoing the scope the ORIGINAL, on-disk resource resolves to -- exactly what a
        // client's prior GET would have returned before this edit. The real resolve()
        // path reads the file as it stands *before* the write and accepts the echo; the
        // mutant recomputes from the NEW payload's applies_to (rite_calendar_test:roman)
        // and rejects it as a mismatch, turning the 200 into a 422.
        /** @var array<string,mixed> $onDisk */
        $onDisk         = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/PrayerUnbornTest.json'),
            true
        );
        $onDisk['name'] = 'ZzzScopePatchEchoTest';

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzScopePatchEchoTest.json';
        file_put_contents($this->testFixturePath, json_encode($onDisk, JSON_THROW_ON_ERROR));

        $payload               = $onDisk;
        $payload['applies_to'] = ['rite' => 'roman']; // drops national_calendar => re-scopes to the rite level
        $payload['scope']      = ['object_type' => 'national_calendar_test', 'object_id' => 'roman/US'];

        $response = ( new TestsHandler(['ZzzScopePatchEchoTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PATCH', '/tests/roman/ZzzScopePatchEchoTest', [], $payload)
        );
        self::assertSame(200, $response->getStatusCode());

        // The echoed scope must NOT be persisted on the PATCH path either, and the
        // re-scoping itself must have taken effect.
        /** @var array<string,mixed> $stored */
        $stored = json_decode((string) file_get_contents($this->testFixturePath), true);
        self::assertArrayNotHasKey('scope', $stored);
        self::assertSame(['rite' => 'roman'], $stored['applies_to']);
    }
}
