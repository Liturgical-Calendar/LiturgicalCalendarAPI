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

    public function testForGeneralRomanCalendarChecksFixedObjectId(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'admin', 'general_roman_calendar:temporale')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar($client, 'temporale');
        $request    = ( new ServerRequest('PUT', '/temporale') )
            ->withAttribute('oidc_user', ['sub' => 'abc', 'roles' => ['calendar_editor']]);

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForMissalsEditioTypicaChecksGeneralRomanCalendar(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'editor', 'general_roman_calendar:EDITIO_TYPICA_2002')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_2002');
        $request    = ( new ServerRequest('PATCH', '/missals/EDITIO_TYPICA_2002') )
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
        // Track created paths so tearDown() cleans them up even on assertion failure.
        $tempDir  = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempFile = $tempDir . '/some-test.json';
        mkdir($tempDir);
        // Append dir before file so tearDown()'s array_reverse() removes the file first,
        // then the now-empty dir (rmdir fails on a non-empty dir).
        $this->tempPaths[] = $tempDir;
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

    public function testForTestScopesFactoryPutMapsToEditor(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:roman/US')
            ->willReturn(true);

        // TestScopeResolver is final; use a real instance backed by a temp dir.
        // Track created paths so tearDown() cleans them up even on assertion failure.
        $tempDir  = sys_get_temp_dir() . '/fga_test_' . uniqid();
        $tempFile = $tempDir . '/some-test.json';
        mkdir($tempDir);
        // Append dir before file so tearDown()'s array_reverse() removes the file first,
        // then the now-empty dir (rmdir fails on a non-empty dir).
        $this->tempPaths[] = $tempDir;
        $this->tempPaths[] = $tempFile;
        file_put_contents(
            $tempFile,
            (string) json_encode(['applies_to' => ['national_calendar' => 'US']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PUT', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');

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

    public function testForGeneralRomanCalendarAcceptsCustomRelationMap(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:someone', 'editor', 'general_roman_calendar:decrees')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar(
            $client,
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
        $request = ( new ServerRequest('PUT', '/tests/BrandNewTest', [], $body) )
            ->withParsedBody($payload)
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest');

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
        $request = ( new ServerRequest('PUT', '/tests/BrandNewTest', [], 'not-json') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest');

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
        $request = ( new ServerRequest('PATCH', '/tests/BrandNewTest', [], $body) )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }
}
