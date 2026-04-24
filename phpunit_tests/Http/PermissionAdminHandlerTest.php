<?php

namespace LiturgicalCalendar\Api\Tests\Http;

use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PermissionAdminHandler.
 *
 * Tests request validation and routing without a running OpenFGA server.
 * The handler requires OIDC authentication, so we test that unauthenticated
 * and non-admin requests are rejected correctly.
 */
class PermissionAdminHandlerTest extends TestCase
{
    private PermissionAdminHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new PermissionAdminHandler();
    }

    public function testRejectsUnauthenticatedRequest(): void
    {
        $request = new ServerRequest('GET', '/admin/permissions');
        $request = $request->withHeader('Accept', 'application/json');

        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\UnauthorizedException::class);
        $this->handler->handle($request);
    }

    public function testRejectsNonAdminUser(): void
    {
        $request = ( new ServerRequest('GET', '/admin/permissions') )
            ->withHeader('Accept', 'application/json')
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['calendar_editor']]);

        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\ForbiddenException::class);
        $this->handler->handle($request);
    }

    public function testOptionsReturnsPreflightResponse(): void
    {
        $request = ( new ServerRequest('OPTIONS', '/admin/permissions') )
            ->withHeader('Origin', 'http://localhost:3000')
            ->withHeader('Access-Control-Request-Method', 'GET');

        $response = $this->handler->handle($request);
        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testRejectsDisallowedMethod(): void
    {
        $request = ( new ServerRequest('PATCH', '/admin/permissions') )
            ->withHeader('Accept', 'application/json')
            ->withAttribute('oidc_user', ['sub' => 'admin-1', 'roles' => ['admin']]);

        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException::class);
        $this->handler->handle($request);
    }
}
