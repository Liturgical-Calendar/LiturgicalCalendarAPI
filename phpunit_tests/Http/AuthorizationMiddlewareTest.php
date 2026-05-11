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

    /**
     * @return iterable<string, array{0:\Closure():AuthorizationMiddleware,1:string,2:string}>
     */
    public static function factoryProvider(): iterable
    {
        // tuple = [factory closure, role that should be accepted, role that should NOT]
        yield 'developer'       => [static fn (): AuthorizationMiddleware => AuthorizationMiddleware::forDeveloper(), 'developer', 'calendar_editor'];
        yield 'calendar_editor' => [static fn (): AuthorizationMiddleware => AuthorizationMiddleware::forCalendarEditor(), 'calendar_editor', 'developer'];
        yield 'test_editor'     => [static fn (): AuthorizationMiddleware => AuthorizationMiddleware::forTestEditor(), 'test_editor', 'developer'];
        yield 'admin'           => [static fn (): AuthorizationMiddleware => AuthorizationMiddleware::forAdmin(), 'admin', 'developer'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('factoryProvider')]
    public function testFactoryAcceptsItsOwnRole(\Closure $make, string $allowed, string $denied): void
    {
        $request  = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => [$allowed]]);
        $response = $make()->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('factoryProvider')]
    public function testFactoryRejectsMismatchedRole(\Closure $make, string $allowed, string $denied): void
    {
        $request = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => [$denied]]);
        $this->expectException(ForbiddenException::class);
        $make()->process($request, $this->handler());
    }

    public function testAllFactoriesLetAdminThrough(): void
    {
        // Admin role bypasses every factory's required-role check.
        $adminReq = $this->request()->withAttribute('oidc_user', ['sub' => 'u1', 'roles' => ['admin']]);
        foreach (
            [
                AuthorizationMiddleware::forDeveloper(),
                AuthorizationMiddleware::forCalendarEditor(),
                AuthorizationMiddleware::forTestEditor(),
                AuthorizationMiddleware::forAdmin(),
            ] as $mw
        ) {
            self::assertSame(200, $mw->process($adminReq, $this->handler())->getStatusCode());
        }
    }
}
