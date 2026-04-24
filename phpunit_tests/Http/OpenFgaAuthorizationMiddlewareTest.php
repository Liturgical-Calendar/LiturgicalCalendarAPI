<?php

namespace LiturgicalCalendar\Api\Tests\Http;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
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
        $client     = $this->createMock(OpenFgaClient::class);
        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = new ServerRequest('PUT', '/data/nation/IT');

        $this->expectException(UnauthorizedException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testThrowsUnauthorizedWhenNoSubClaim(): void
    {
        $client     = $this->createMock(OpenFgaClient::class);
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

    public function testPassesThroughWhenNoResourceId(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $middleware = new OpenFgaAuthorizationMiddleware($client, 'national_calendar');

        $request = ( new ServerRequest('PUT', '/data/nation') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']]);
        // No calendar_id attribute set

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
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
        $client     = $this->createMock(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'nation');

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForCalendarDataMapsDioceseTodiocesanCalendar(): void
    {
        $client     = $this->createMock(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'diocese');

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForCalendarDataMapsWiderregionToWiderRegion(): void
    {
        $client     = $this->createMock(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'widerregion');

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForCalendarDataReturnsNullForUnknownCategory(): void
    {
        $client     = $this->createMock(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forCalendarData($client, 'unknown');

        $this->assertNull($middleware);
    }

    public function testForTestDefinitionCreatesMiddleware(): void
    {
        $client     = $this->createMock(OpenFgaClient::class);
        $middleware = OpenFgaAuthorizationMiddleware::forTestDefinition($client);

        $this->assertInstanceOf(OpenFgaAuthorizationMiddleware::class, $middleware);
    }

    public function testForTestDefinitionUsesTestIdAttribute(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'test_definition:my-test')
            ->willReturn(true);

        $middleware = OpenFgaAuthorizationMiddleware::forTestDefinition($client);

        $request = ( new ServerRequest('PUT', '/tests/my-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'my-test');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
