<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Most of PermissionAdminHandler's code paths terminate in OpenFGA calls
 * (readTuples / writeTuple / deleteTuple / check), which we can't exercise
 * from in-process tests without an OpenFGA server. These tests therefore
 * focus on the gates that run BEFORE the FGA call: preflight, auth, path
 * routing, and request-shape validation.
 */
#[CoversClass(PermissionAdminHandler::class)]
final class PermissionAdminHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new PermissionAdminHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/permissions', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/admin/permissions'))
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new PermissionAdminHandler() )->handle($this->requestFor('GET', '/admin/permissions'));
    }

    public function testEmptySubIsUnauthorized(): void
    {
        $request = $this->requestFor('GET', '/admin/permissions')
            ->withAttribute('oidc_user', ['sub' => '', 'roles' => ['admin']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testNonAdminWithoutObjectTypeIsValidationError(): void
    {
        // Resource admins (non-global) must specify object_type — the handler
        // rejects with ValidationException before reaching FGA.
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions'),
            'resource-admin-1',
            ['developer'] // not admin
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must specify object_type');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testInvalidObjectTypeIsValidationError(): void
    {
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions?object_type=not_a_type', [])
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid object_type');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testInvalidRelationIsValidationError(): void
    {
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions?object_type=national_calendar&relation=bogus', [])
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid relation');

        ( new PermissionAdminHandler() )->handle($request);
    }
}
