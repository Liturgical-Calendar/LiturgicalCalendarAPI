<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\AuthorizationMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'reached-handler');
            }
        };
    }

    private function request(): ServerRequestInterface
    {
        return ( new Psr17Factory() )->createServerRequest('GET', '/');
    }

    public function testRejectsMissingOidcUser(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Not authenticated');
        ( new AuthorizationMiddleware('admin') )
            ->process($this->request(), $this->handler());
    }

    public function testRejectsTokenWithoutSubject(): void
    {
        $request = $this->request()->withAttribute('oidc_user', ['roles' => ['admin']]);
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid user token');
        ( new AuthorizationMiddleware('admin') )->process($request, $this->handler());
    }

    public function testAdminBypassesRoleCheck(): void
    {
        $request  = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['admin']]);
        $response = ( new AuthorizationMiddleware('calendar_editor') )
            ->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRequiredRolePresentLetsThrough(): void
    {
        $request  = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['calendar_editor']]);
        $response = ( new AuthorizationMiddleware('calendar_editor') )
            ->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRequiredRoleMissingThrowsForbidden(): void
    {
        $request = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['viewer']]);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Missing required role: calendar_editor');
        ( new AuthorizationMiddleware('calendar_editor') )->process($request, $this->handler());
    }

    public function testFactoryHelpers(): void
    {
        // Each factory should produce a middleware that accepts only its own role
        // (or admin). We verify via a developer user against each.
        $devRequest = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['developer']]);
        $editorReq  = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['calendar_editor']]);
        $testReq    = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['test_editor']]);
        $adminReq   = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['admin']]);

        self::assertSame(200, AuthorizationMiddleware::forDeveloper()->process($devRequest, $this->handler())->getStatusCode());
        self::assertSame(200, AuthorizationMiddleware::forCalendarEditor()->process($editorReq, $this->handler())->getStatusCode());
        self::assertSame(200, AuthorizationMiddleware::forTestEditor()->process($testReq, $this->handler())->getStatusCode());
        self::assertSame(200, AuthorizationMiddleware::forAdmin()->process($adminReq, $this->handler())->getStatusCode());
    }
}
