<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\UsersHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * UsersHandler hard-blocks when Zitadel isn't configured (the test env
 * doesn't set ZITADEL_* envs), so most of the listing/revoke code paths
 * can't be exercised here. These tests cover everything that runs before
 * the Zitadel gate.
 */
#[CoversClass(UsersHandler::class)]
final class UsersHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new UsersHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/users', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPostIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new UsersHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/users'))
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new UsersHandler() )->handle($this->requestFor('GET', '/admin/users'));
    }

    public function testNonAdminIsForbidden(): void
    {
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/users'),
            'user-1',
            ['viewer']
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Admin role required');

        ( new UsersHandler() )->handle($request);
    }

    public function testZitadelNotConfiguredIsRuntimeError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zitadel service not configured');

        ( new UsersHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/users'))
        );
    }
}
