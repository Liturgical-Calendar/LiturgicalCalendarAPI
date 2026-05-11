<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\AccessRequestAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AccessRequestAdminHandler::class)]
final class AccessRequestAdminHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/access-requests', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/admin/access-requests'))
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new AccessRequestAdminHandler() )->handle($this->requestFor('GET', '/admin/access-requests'));
    }

    public function testEmptySubIsUnauthorized(): void
    {
        $request = $this->requestFor('GET', '/admin/access-requests')
            ->withAttribute('oidc_user', ['sub' => '   ', 'roles' => ['admin']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');
        ( new AccessRequestAdminHandler() )->handle($request);
    }

    public function testListReturnsEmptyForFreshDatabase(): void
    {
        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests'))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(0, $body['count']);
        self::assertSame([], $body['requests']);
    }

    public function testListWithStatusFilterReturnsOnlyMatchingRequests(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $b = $repo->create('user-b', 'b@x.test', null, 'developer', []);
        $repo->approve($b, 'admin');

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?status=approved'))
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['count']);
    }

    public function testListWithBadStatusFilterIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?status=weird'))
        );
    }

    public function testPostWithIncompletePathIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid request path');
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests'))
        );
    }

    public function testPostWithBadUuidIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid request ID format');
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/not-a-uuid/approve', [], []))
        );
    }

    public function testApproveUnknownRequestIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/admin/access-requests/00000000-0000-0000-0000-000000000000/approve', [], [])
            )
        );
    }

    public function testRejectNonPendingIsValidationError(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $repo->approve($id, 'someone'); // status is now 'approved'

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot reject');

        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/reject', [], []))
        );
    }

    public function testApproveHappyPathWithoutFgaOrZitadel(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);

        // Neither OpenFGA nor Zitadel envs are set in the test bootstrap, so
        // both isConfigured() gates are false and the handler should still
        // flip the DB status to approved without touching either service.
        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/approve', [], ['notes' => 'ok']))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertFalse($body['role_assigned']);
        self::assertSame([], $body['tuples_created']);
        self::assertNull($body['zitadel_error']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);
    }

    public function testRejectHappyPath(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/reject', [], ['notes' => 'no']))
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('rejected', $row['status']);
    }

    public function testRevokeHappyPathWithoutFgaOrZitadel(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $repo->approve($id, 'admin');

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/revoke', [], []))
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testUnknownActionIsValidationError(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid action');

        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/teleport', [], []))
        );
    }
}
