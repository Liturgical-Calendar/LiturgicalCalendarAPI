<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
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
        $handler  = new TestsHandler(['MaryMotherChurchTest']);
        $response = $handler->handle($this->requestFor('GET', '/tests/MaryMotherChurchTest'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
    }

    public function testUnknownTestIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new TestsHandler(['NotARealTest']) )
            ->handle($this->requestFor('GET', '/tests/NotARealTest'));
    }

    public function testTooManyPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['a', 'b']) )
            ->handle($this->requestFor('GET', '/tests/a/b'));
    }

    public function testPutWithMalformedPayloadIsValidationError(): void
    {
        // PUT (writes), with a JSON array of scalars (not objects). parseBodyPayload
        // surfaces this as a ValidationException at the AbstractHandler layer before
        // TestsHandler's own object check fires.
        $this->expectException(ValidationException::class);
        $req = $this->requestFor('PUT', '/tests', [], '[1, 2, 3]')
            ->withHeader('Content-Type', 'application/json');
        ( new TestsHandler() )->handle($req);
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
    public function testDeleteRejectsWrongPathParamCount(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['a', 'b']) )->handle($this->requestFor('DELETE', '/tests/a/b'));
    }

    public function testDeleteUnknownOrUnsafeNameReturnsNotFound(): void
    {
        // TestScopeResolver::resolve() returns null for a non-existent (or unsafe
        // path-traversal) name, so the handler must 404 and never reach unlink().
        $name = 'NoSuchTest_' . bin2hex(random_bytes(4));
        $this->expectException(NotFoundException::class);
        ( new TestsHandler([$name]) )->handle($this->requestFor('DELETE', "/tests/{$name}"));
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

        $handler = new TestsHandler([$testName]);
        $handler->setPurgeService($purge);

        $response = $handler->handle($this->requestFor('DELETE', "/tests/{$testName}"));

        self::assertSame(204, $response->getStatusCode());
        $this->testFixturePath = null; // handler already removed the file
    }

    public function testDeletePurgesScopedTestOperationalTuples(): void
    {
        // --- Arrange: create a temp fixture that TestScopeResolver maps to national_calendar_test:US ---
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
        $handler = new TestsHandler([$testName]);

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar_test:US');
        $handler->setPurgeService($purge);

        // --- Act: issue DELETE (bypasses JWT middleware — in-process) --------
        $request  = $this->requestFor('DELETE', "/tests/{$testName}");
        $response = $handler->handle($request);

        // --- Assert ----------------------------------------------------------
        self::assertSame(204, $response->getStatusCode());
        $this->testFixturePath = null; // handler already removed the file
        // purgeForObject assertion enforced by mock expectation above
    }
}
