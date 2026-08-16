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
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name'] = 'ZzzPutCreatedTest';

        $this->testFixturePath = JsonData::TESTS_FOLDER->path() . '/ZzzPutCreatedTest.json';

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
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
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
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
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
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name'] = 'ZzzPatchTargetTest';

        // Seed the fixture on disk, then PATCH it with an updated description.
        $this->testFixturePath = JsonData::TESTS_FOLDER->path() . '/ZzzPatchTargetTest.json';
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
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
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
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
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
        $fixturePath           = JsonData::TESTS_FOLDER->path() . DIRECTORY_SEPARATOR . $testName . '.json';
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
        // shared test file (the handler reads from the real JsonData::TESTS_FOLDER,
        // so the fixture must live there). tearDown removes it via $testFixturePath.
        $testName              = 'PurgeFixture_' . bin2hex(random_bytes(6));
        $testsDir              = JsonData::TESTS_FOLDER->path();
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
}
