<?php

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for OpenFgaAuthorizationMiddleware.
 *
 * Uses a mock OpenFgaClient to test middleware behavior without a running OpenFGA server.
 */
class OpenFgaAuthorizationMiddlewareTest extends TestCase
{
    private RequestHandlerInterface $nextHandler;

    /** @var list<string> Temp paths created during a test, cleaned up in tearDown(). */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPaths = [];
        // Create a simple next handler that returns 200
        $this->nextHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    protected function tearDown(): void
    {
        // Clean up any temp files/dirs created by tests, even on assertion failure.
        foreach (array_reverse($this->tempPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    public function testThrowsUnauthorizedWhenNoOidcUser(): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = new ServerRequest('PUT', '/data/nation/IT');

        $this->expectException(UnauthorizedException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testThrowsUnauthorizedWhenNoSubClaim(): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['roles' => ['calendar_editor']]);

        $this->expectException(UnauthorizedException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testAdminBypassesOpenFgaCheck(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['sub' => 'admin-user', 'roles' => ['admin']])
            ->withAttribute('calendar_id', 'IT');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAllowsWhenOpenFgaReturnsTrue(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'admin', 'national_calendar:IT')
            ->willReturn(true);

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'IT');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDeniesWhenOpenFgaReturnsFalse(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'admin', 'national_calendar:IT')
            ->willReturn(false);

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'IT');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('No admin permission for national_calendar:IT');
        $middleware->process($request, $this->nextHandler);
    }

    public function testDeleteMapsToAdminRelation(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'admin', 'national_calendar:IT')
            ->willReturn(true);

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('DELETE', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'IT');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPatchMapsToEditorRelation(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'diocesan_calendar:BOSTON')
            ->willReturn(true);

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'diocesan_calendar');

        $request = ( new ServerRequest('PATCH', '/data/diocese/BOSTON') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'BOSTON');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDeniesWhenNoResourceId(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']]);
        // No calendar_id attribute set — should fail closed

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Missing resource ID');
        $middleware->process($request, $this->nextHandler);
    }

    public function testPassesThroughForGetMethod(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('GET', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'IT');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForCalendarDataMapsNationToNationalCalendar(): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'nation');

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForCalendarDataMapsDioceseToDiocesanCalendar(): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'diocese');

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForCalendarDataMapsWiderRegionToWiderRegion(): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'widerregion');

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForCalendarDataReturnsNullForUnknownCategory(): void
    {
        $client     = $this->createStub(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'unknown');

        $this->assertNull($middleware);
    }

    public function testForRiteCalendarChecksARiteQualifiedFixedObjectId(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'admin', 'rite_calendar:roman/temporale')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar($client, Rite::ROMAN, 'temporale');
        $request    = ( new ServerRequest('PUT', '/temporale') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForMissalsEditioTypicaChecksRiteCalendar(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'editor', 'rite_calendar:roman/EDITIO_TYPICA_2002')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_2002');
        $request    = ( new ServerRequest('PATCH', '/missals/EDITIO_TYPICA_2002') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Issue #955: an Ambrosian typical edition is a sub-resource of the Ambrosian rite's own
     * calendar, so it is `rite_calendar:ambrosian/{missalId}` — not a bare id on a type named
     * after the Roman rite, as it was under #953. Missal ids remain unique across rites
     * (MissalCatalogTest::testTheRitesDoNotShareIds), so the qualifier disambiguates nothing for
     * this id specifically; it is carried so one uniform rule covers the whole tier, whose other
     * sub-resources are per-rite kinds that genuinely do need it.
     */
    public function testForMissalsAmbrosianEditioTypicaIsRiteQualified(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'editor', 'rite_calendar:ambrosian/EDITIO_TYPICA_2024')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_2024', Rite::AMBROSIAN);
        $request    = ( new ServerRequest('PATCH', '/missals/ambrosian/EDITIO_TYPICA_2024') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Issue #786: the /data object id carries the route's rite, so a grant on an
     * Ambrosian diocese cannot be satisfied by a Roman one of the same id.
     */
    public function testForCalendarDataQualifiesTheObjectIdWithTheRite(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'editor', 'diocesan_calendar:ambrosian/lugano_ch')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'diocese', Rite::AMBROSIAN);
        self::assertNotNull($middleware);

        $request = ( new ServerRequest('PATCH', '/data/ambrosian/diocese/lugano_ch') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'lugano_ch');

        $this->assertEquals(200, $middleware->process($request, $this->nextHandler)->getStatusCode());
    }

    public function testForCalendarDataDefaultsToTheRomanRite(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'editor', 'diocesan_calendar:roman/rotter_nl')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'diocese');
        self::assertNotNull($middleware);

        $request = ( new ServerRequest('PATCH', '/data/diocese/rotter_nl') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'rotter_nl');

        $this->assertEquals(200, $middleware->process($request, $this->nextHandler)->getStatusCode());
    }

    public function testForCalendarDataFailsClosedWithoutACalendarId(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'diocese', Rite::ROMAN);
        self::assertNotNull($middleware);

        $request = ( new ServerRequest('PATCH', '/data/diocese/') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testForMissalsNationalChecksNationalCalendarByPrefix(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'admin', 'national_calendar:roman/IT')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'IT_1983');
        $request    = ( new ServerRequest('PUT', '/missals/IT_1983') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Object-resolver mode tests (Task 5)
    // -----------------------------------------------------------------

    public function testObjectResolverCheckPassesWhenTuplePresent(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(true);

        $resolver   = static fn () => ['national_calendar_test', 'roman/US'];
        $middleware = new OpenFgaAuthorizationMiddleware(
            $client,
            'test_definition',
            'test_id',
            null,
            $resolver
        );

        $request = ( new ServerRequest('PATCH', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testObjectResolverCheckFailsWhenTupleAbsent(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(false);

        $resolver   = static fn () => ['national_calendar_test', 'roman/US'];
        $middleware = new OpenFgaAuthorizationMiddleware(
            $client,
            'test_definition',
            'test_id',
            null,
            $resolver
        );

        $request = ( new ServerRequest('PATCH', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('national_calendar_test:roman/US');
        $middleware->process($request, $this->nextHandler);
    }

    public function testObjectResolverReturnsNullThrowsForbidden(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $resolver   = static fn () => null;
        $middleware = new OpenFgaAuthorizationMiddleware(
            $client,
            'test_definition',
            'test_id',
            null,
            $resolver
        );

        $request = ( new ServerRequest('PATCH', '/tests/unknown-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'unknown-test');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testAdminBypassesObjectResolver(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $resolverCalled = false;
        $resolver       = static function () use (&$resolverCalled): array {
            $resolverCalled = true;
            return ['national_calendar_test', 'US'];
        };
        $middleware     = new OpenFgaAuthorizationMiddleware(
            $client,
            'test_definition',
            'test_id',
            null,
            $resolver
        );

        $request = ( new ServerRequest('PATCH', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'admin-user', 'roles' => ['admin']])
            ->withAttribute('test_id', 'some-test');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($resolverCalled, 'Resolver must not be called for admin users');
    }

    public function testForTestScopesFactory(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(true);

        // TestScopeResolver is final; use a real instance backed by a temp dir.
        // The corpus is partitioned by rite (#787), so the fixture lives under the
        // rite subdirectory the resolver will look in.
        // Track created paths so tearDown() cleans them up even on assertion failure.
        $tempDir     = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempRiteDir = $tempDir . '/roman';
        $tempFile    = $tempRiteDir . '/some-test.json';
        mkdir($tempRiteDir, 0777, true);
        // Append dir before file so tearDown()'s array_reverse() removes the file first,
        // then the now-empty dir (rmdir fails on a non-empty dir).
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempRiteDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['national_calendar' => 'US']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PATCH', '/tests/roman/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test')
            ->withAttribute('test_rite', 'roman');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopesFactoryMissingTestIdThrowsForbidden(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        // TestScopeResolver is final; use a real instance — resolve() won't be called
        // because the closure returns null before reaching it (no test_id attribute)
        $scopeResolver = new TestScopeResolver(sys_get_temp_dir());

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PATCH', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']]);
        // No test_id attribute — closure returns null before calling resolve(), fail closed

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    /**
     * Twin of testForTestScopesFactoryMissingTestIdThrowsForbidden(), for the
     * rite guard added by #787: a valid test_id with a MISSING test_rite
     * attribute must still fail closed. A fixture that would resolve happily
     * once a rite is supplied is deliberately present, so a passing check()
     * call can only mean the guard was bypassed and a partition was guessed.
     */
    public function testForTestScopesFactoryMissingTestRiteThrowsForbidden(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $tempDir     = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempRiteDir = $tempDir . '/roman';
        $tempFile    = $tempRiteDir . '/some-test.json';
        mkdir($tempRiteDir, 0777, true);
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempRiteDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['national_calendar' => 'US']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PATCH', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');
        // No test_rite attribute — the guard must return null before resolve() is
        // ever called, regardless of whether the name would resolve under some rite.

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    /**
     * Same guard, exercised via an INVALID test_rite value (one Rite::tryFrom()
     * cannot parse) rather than a missing attribute. Rite::tryFrom() returning
     * null must be treated identically to the attribute being absent.
     */
    public function testForTestScopesFactoryInvalidTestRiteThrowsForbidden(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $tempDir     = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempRiteDir = $tempDir . '/roman';
        $tempFile    = $tempRiteDir . '/some-test.json';
        mkdir($tempRiteDir, 0777, true);
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempRiteDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['national_calendar' => 'US']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PATCH', '/tests/byzantine/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test')
            ->withAttribute('test_rite', 'byzantine');
        // test_rite is present but not a value Rite::tryFrom() recognises —
        // must fail closed exactly like the absent-attribute case.

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testForTestScopesFactoryPutMapsToEditor(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(true);

        // TestScopeResolver is final; use a real instance backed by a temp dir.
        // The corpus is partitioned by rite (#787), so the fixture lives under the
        // rite subdirectory the resolver will look in.
        // Track created paths so tearDown() cleans them up even on assertion failure.
        $tempDir     = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempRiteDir = $tempDir . '/roman';
        $tempFile    = $tempRiteDir . '/some-test.json';
        mkdir($tempRiteDir, 0777, true);
        // Append dir before file so tearDown()'s array_reverse() removes the file first,
        // then the now-empty dir (rmdir fails on a non-empty dir).
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempRiteDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['national_calendar' => 'US']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PUT', '/tests/roman/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test')
            ->withAttribute('test_rite', 'roman');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopesPutMapsToEditor(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(true);

        $resolver   = static fn () => ['national_calendar_test', 'roman/US'];
        $middleware = new OpenFgaAuthorizationMiddleware(
            $client,
            'test_definition',
            'test_id',
            null,
            $resolver,
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        $request = ( new ServerRequest('PUT', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForRiteCalendarAcceptsCustomRelationMap(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:someone', 'editor', 'rite_calendar:roman/decrees')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::ROMAN,
            'decrees',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        $request = ( new ServerRequest('PUT', '/decrees/some-decree') )
            ->withAttribute('oidc_user', ['sub' => 'someone', 'roles' => []]);

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopesPutCreateResolvesScopeFromPayload(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/NL')
            ->willReturn(true);

        // Empty temp dir: the test file does NOT exist (create flow).
        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        $this->tempPaths[] = $tempDir;
        $scopeResolver     = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $payload = ['applies_to' => ['national_calendar' => 'NL']];
        $body    = (string) json_encode($payload);
        $request = ( new ServerRequest('PUT', '/tests/roman/BrandNewTest', [], $body) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest')
            ->withAttribute('test_rite', 'roman');

        // The middleware resolves the FGA scope from getParsedBody() (populated by
        // JsonBodyParserMiddleware in production) and must not consume the stream.
        // Pin that the downstream handler can still read the raw body afterwards.
        $downstreamHandler = new class ($body) implements RequestHandlerInterface {
            public function __construct(private string $expectedBody)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Use getContents() (like AbstractHandler::parseBodyPayload), NOT (string) casting:
                // StreamTrait::__toString() rewinds unconditionally, which would mask any
                // stream consumption in the middleware and make this assertion tautological.
                $received = $request->getBody()->getContents();
                if ($received === '') {
                    throw new \RuntimeException('Downstream handler received an empty body.');
                }

                $decoded         = json_decode($received, true);
                $expectedDecoded = json_decode($this->expectedBody, true);
                if ($decoded !== $expectedDecoded) {
                    throw new \RuntimeException('Downstream handler received a different body than expected.');
                }

                return new Response(200);
            }
        };

        $response = $middleware->process($request, $downstreamHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopesPutCreateUnparseableBodyIsForbidden(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        $this->tempPaths[] = $tempDir;
        $scopeResolver     = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        // No withParsedBody(): an unparseable body means JsonBodyParserMiddleware
        // leaves getParsedBody() null, so the scope fallback fails closed.
        $request = ( new ServerRequest('PUT', '/tests/roman/BrandNewTest', [], 'not-json') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest')
            ->withAttribute('test_rite', 'roman');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testForTestScopesPatchMissingFileStillFailsClosed(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        $this->tempPaths[] = $tempDir;
        $scopeResolver     = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        // PATCH must NOT fall back to the payload: the resource must already exist.
        $body    = (string) json_encode(['applies_to' => ['national_calendar' => 'NL']]);
        $request = ( new ServerRequest('PATCH', '/tests/roman/BrandNewTest', [], $body) )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest')
            ->withAttribute('test_rite', 'roman');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    // -----------------------------------------------------------------
    // forTestScopePayloadTarget() — issue #790 union check
    // -----------------------------------------------------------------

    public function testForTestScopePayloadTargetDeniesWhenNotAuthorizedForPayloadScope(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(false);

        $scopeResolver = new TestScopeResolver(sys_get_temp_dir());
        $middleware    = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        $payload = ['applies_to' => ['rite' => 'roman', 'national_calendar' => 'US']];
        $request = ( new ServerRequest('PATCH', '/tests/roman/SomeItTest', [], (string) json_encode($payload)) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('national_calendar_test:roman/US');
        $middleware->process($request, $this->nextHandler);
    }

    public function testForTestScopePayloadTargetAllowsWhenAuthorizedForPayloadScope(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(true);

        $scopeResolver = new TestScopeResolver(sys_get_temp_dir());
        $middleware    = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        $payload = ['applies_to' => ['rite' => 'roman', 'national_calendar' => 'US']];
        $request = ( new ServerRequest('PATCH', '/tests/roman/SomeItTest', [], (string) json_encode($payload)) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopePayloadTargetPassesThroughForPutWithoutCallingCheck(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $scopeResolver = new TestScopeResolver(sys_get_temp_dir());
        $middleware    = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        $payload = ['applies_to' => ['rite' => 'roman', 'national_calendar' => 'US']];
        $request = ( new ServerRequest('PUT', '/tests/roman/SomeItTest', [], (string) json_encode($payload)) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        // The relation map only defines PATCH, so PUT resolves to a null relation and
        // process() passes through before the resolver (and thus check()) is ever reached.
        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopePayloadTargetPassesThroughForDeleteWithoutCallingCheck(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $scopeResolver = new TestScopeResolver(sys_get_temp_dir());
        $middleware    = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        $request = ( new ServerRequest('DELETE', '/tests/roman/SomeItTest') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopePayloadTargetFailsClosedOnUnparseableBody(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $scopeResolver = new TestScopeResolver(sys_get_temp_dir());
        $middleware    = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        // No withParsedBody(): getParsedBody() is null, so resolveFromPayload() returns
        // null and the resolver fails closed.
        $request = ( new ServerRequest('PATCH', '/tests/roman/SomeItTest', [], 'not-json') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    /**
     * Issue #790's actual exploit, reproduced at the middleware layer: a caller scoped
     * to national_calendar_test:roman/IT holds `editor` on the STORED scope (satisfying
     * forTestScopes()) but NOT on the payload-derived TARGET scope
     * (national_calendar_test:roman/US). Piping both middleware instances — exactly as
     * Router::configureAuthorizationPipeline() does — must deny the request.
     */
    public function testUnionDeniesWhenCallerHoldsStoredScopeButNotPayloadTarget(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->exactly(2))
            ->method('check')
            ->willReturnMap([
                ['user:user-123', 'editor', 'national_calendar_test:roman/IT', true],
                ['user:user-123', 'editor', 'national_calendar_test:roman/US', false],
            ]);

        // Stored file: scoped to IT (the caller's granted scope).
        $tempDir     = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempRiteDir = $tempDir . '/roman';
        $tempFile    = $tempRiteDir . '/SomeItTest.json';
        mkdir($tempRiteDir, 0777, true);
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempRiteDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['rite' => 'roman', 'national_calendar' => 'IT']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $storedScopeMiddleware   = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);
        $payloadTargetMiddleware = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        // Payload's applies_to attempts to move the test to US.
        $payload = ['applies_to' => ['rite' => 'roman', 'national_calendar' => 'US']];
        $request = ( new ServerRequest('PATCH', '/tests/roman/SomeItTest', [], (string) json_encode($payload)) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        // Simulate the pipeline: forTestScopes() runs first (would pass), and its handler
        // is forTestScopePayloadTarget() (which must deny) — mirroring Router's ordering.
        $innerHandler = new class ($payloadTargetMiddleware, $this->nextHandler) implements RequestHandlerInterface {
            public function __construct(
                private OpenFgaAuthorizationMiddleware $middleware,
                private RequestHandlerInterface $next
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('national_calendar_test:roman/US');
        $storedScopeMiddleware->process($request, $innerHandler);
    }

    /**
     * Twin of the exploit test above: the caller holds `editor` on BOTH the stored scope
     * and the payload-derived target scope, so the re-scoping PATCH is allowed through.
     */
    public function testUnionAllowsWhenCallerHoldsBothStoredAndPayloadTargetScopes(): void
    {
        // Record the actual objects check() was called with, not just the call count: a
        // resolver-collapsing mutant (e.g. both middleware instances resolving to the
        // SAME object) would still trigger exactly 2 calls here — one per middleware
        // instance — and a bare exactly(2) assertion would not notice. Asserting the
        // recorded objects afterwards makes that distinction observable.
        $checkedObjects = [];
        $client         = $this->createMock(OpenFgaClient::class);
        $client->expects($this->exactly(2))
            ->method('check')
            ->willReturnCallback(function (string $user, string $relation, string $object) use (&$checkedObjects): bool {
                $checkedObjects[] = $object;
                return match ($object) {
                    'national_calendar_test:roman/IT', 'national_calendar_test:roman/US' => true,
                    default => false,
                };
            });

        $tempDir     = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempRiteDir = $tempDir . '/roman';
        $tempFile    = $tempRiteDir . '/SomeItTest.json';
        mkdir($tempRiteDir, 0777, true);
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempRiteDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['rite' => 'roman', 'national_calendar' => 'IT']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $storedScopeMiddleware   = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);
        $payloadTargetMiddleware = OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($client, $scopeResolver);

        $payload = ['applies_to' => ['rite' => 'roman', 'national_calendar' => 'US']];
        $request = ( new ServerRequest('PATCH', '/tests/roman/SomeItTest', [], (string) json_encode($payload)) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'SomeItTest')
            ->withAttribute('test_rite', 'roman');

        $innerHandler = new class ($payloadTargetMiddleware, $this->nextHandler) implements RequestHandlerInterface {
            public function __construct(
                private OpenFgaAuthorizationMiddleware $middleware,
                private RequestHandlerInterface $next
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };

        $response = $storedScopeMiddleware->process($request, $innerHandler);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEqualsCanonicalizing(
            ['national_calendar_test:roman/IT', 'national_calendar_test:roman/US'],
            $checkedObjects,
            'Expected one check() against the STORED scope and one against the payload-derived TARGET scope'
        );
    }
    // -----------------------------------------------------------------
    // rite_calendar tier and the pre-#955 legacy fallback (issue #955)
    // -----------------------------------------------------------------

    /**
     * A mocked client that answers true for exactly one (user, relation, object) triple and
     * false for every other check — including the legacy-fallback one. That is what lets the
     * two fallback tests below prove opposite directions with the same harness: each grants
     * only ONE of the two objects and still expects the request through.
     */
    private function clientAllowingOnly(string $user, string $relation, string $object): OpenFgaClient
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->method('check')->willReturnCallback(
            static fn (string $checkUser, string $checkRelation, string $checkObject): bool
                => $checkUser === $user && $checkRelation === $relation && $checkObject === $object
        );

        return $client;
    }

    private function clientAllowingNothing(): OpenFgaClient
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->method('check')->willReturn(false);

        return $client;
    }

    private function runsThrough(OpenFgaAuthorizationMiddleware $middleware, string $method, string $sub): bool
    {
        $request = ( new ServerRequest($method, '/') )
            ->withAttribute('oidc_user', ['sub' => $sub, 'roles' => ['calendar_editor']]);

        return $middleware->process($request, $this->nextHandler)->getStatusCode() === 200;
    }

    public function testRiteCalendarChecksARiteQualifiedObject(): void
    {
        $client = $this->clientAllowingOnly('user:alice', 'editor', 'rite_calendar:roman/decrees');

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::ROMAN,
            'decrees',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        self::assertTrue($this->runsThrough($middleware, 'PATCH', 'alice'));
    }

    public function testNeitherTupleStillDenies(): void
    {
        $client = $this->clientAllowingNothing();

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar($client, Rite::ROMAN, 'decrees');

        $this->expectException(ForbiddenException::class);
        $this->runsThrough($middleware, 'PATCH', 'alice');
    }

    /**
     * A rite that has no such sub-resource is refused on both halves of the pair: the primary
     * object `rite_calendar:ambrosian/decrees` appears in no valid id set so it can hold no
     * tuple, and a non-Roman rite is given no legacy object at all (legacy ids were Roman-only —
     * see forRiteCalendar()). Neither refusal is a special case for the rite.
     *
     * This stub denies everything, so it pins only that the refusal happens. What makes the
     * refusal survive a rite-BLIND fallback is proved separately by
     * testTheLegacyRomanTupleDoesNotAuthorizeAnotherRite().
     */
    public function testARiteWithoutTheSubResourceIsRefused(): void
    {
        $client = $this->clientAllowingOnly('user:alice', 'editor', 'rite_calendar:roman/decrees');

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar($client, Rite::AMBROSIAN, 'decrees');

        $this->expectException(ForbiddenException::class);
        $this->runsThrough($middleware, 'PATCH', 'alice');
    }

    /**
     * The legacy fallback must not carry a Roman grant across rites. A caller holding ONLY the
     * pre-#955 `general_roman_calendar:decrees` tuple is refused on the AMBROSIAN rite's
     * sub-resource, because the legacy pair is supplied only for Rite::ROMAN.
     *
     * Pairing it unconditionally would have authorized the Roman grant against another rite's
     * resource — precisely the un-qualification this branch exists to remove — and no other test
     * would have caught it: testARiteWithoutTheSubResourceIsRefused() denies everything, so it
     * passes under a rite-blind fallback too. Unreachable through the Router today (there is no
     * rite segment on /decrees or /temporale), but that is a routing accident, not a guarantee.
     */
    public function testTheLegacyRomanTupleDoesNotAuthorizeAnotherRite(): void
    {
        $client = $this->clientAllowingOnly('user:alice', 'editor', 'general_roman_calendar:decrees');

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::AMBROSIAN,
            'decrees',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        $this->expectException(ForbiddenException::class);
        $this->runsThrough($middleware, 'PATCH', 'alice');
    }

    /**
     * The same rule seen from the call-count side: a non-Roman rite has no legacy object, so
     * even a DENIED request makes exactly one OpenFGA call. A second call here would mean a
     * legacy object was paired for a rite that never had one.
     */
    public function testANonRomanRiteMakesNoLegacyFallbackCall(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:alice', 'editor', 'rite_calendar:ambrosian/temporale')
            ->willReturn(false);

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::AMBROSIAN,
            'temporale',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        $this->expectException(ForbiddenException::class);
        $this->runsThrough($middleware, 'PATCH', 'alice');
    }

    /**
     * The fallback is a deny-path-only cost: an allowed request must still make exactly ONE
     * OpenFGA call. If this ever starts asserting two, the fallback has been hoisted above the
     * primary check and every authorized write on the tier has silently doubled its latency.
     */
    public function testTheLegacyObjectIsNotCheckedWhenThePrimaryCheckAllows(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:alice', 'editor', 'rite_calendar:roman/decrees')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::ROMAN,
            'decrees',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        self::assertTrue($this->runsThrough($middleware, 'PATCH', 'alice'));
    }

    /**
     * The denial names the object the caller actually needs a grant on — the new one — rather
     * than the legacy object the fallback happened to consult last. The message is what an
     * operator reads when granting the missing permission, so pointing it at the type being
     * retired would send them to write a tuple the prune milestone deletes.
     */
    public function testTheDenialNamesTheRiteCalendarObjectNotTheLegacyOne(): void
    {
        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar($this->clientAllowingNothing(), Rite::ROMAN, 'temporale');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('No admin permission for rite_calendar:roman/temporale');
        $this->runsThrough($middleware, 'PUT', 'alice');
    }
}
