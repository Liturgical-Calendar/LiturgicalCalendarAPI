<?php

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Create a simple next handler that returns 200
        $this->nextHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
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
            ->with('user:user-123', 'editor', 'national_calendar:IT')
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
            ->with('user:user-123', 'editor', 'national_calendar:IT')
            ->willReturn(false);

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation/IT') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']])
            ->withAttribute('calendar_id', 'IT');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('No editor permission for national_calendar:IT');
        $middleware->process($request, $this->nextHandler);
    }

    public function testDeleteMapsToDeleterRelation(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'deleter', 'national_calendar:IT')
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
            ->with('user:abc', 'editor', 'general_roman_calendar:temporale')
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

    public function testForMissalsNationalChecksNationalCalendarByPrefix(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:abc', 'editor', 'national_calendar:IT')
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
            ->with('user:user-123', 'editor', 'national_calendar_test:US')
            ->willReturn(true);

        $resolver   = static fn () => ['national_calendar_test', 'US'];
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
            ->with('user:user-123', 'editor', 'national_calendar_test:US')
            ->willReturn(false);

        $resolver   = static fn () => ['national_calendar_test', 'US'];
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
        $this->expectExceptionMessage('national_calendar_test:US');
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
            ->with('user:user-123', 'editor', 'national_calendar_test:US')
            ->willReturn(true);

        // TestScopeResolver is final; use a real instance backed by a temp dir
        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        file_put_contents(
            $tempDir . '/some-test.json',
            (string) json_encode(['applies_to' => ['national_calendar' => 'US']])
        );
        $scopeResolver = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PATCH', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());

        unlink($tempDir . '/some-test.json');
        rmdir($tempDir);
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
}
