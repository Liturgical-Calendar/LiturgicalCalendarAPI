<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\ApplicationAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ApplicationAdminHandler::class)]
final class ApplicationAdminHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new ApplicationAdminHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/applications', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/admin/applications'))
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new ApplicationAdminHandler() )->handle($this->requestFor('GET', '/admin/applications'));
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications'), 'viewer', ['viewer'])
        );
    }

    public function testInvalidPathIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid request path');

        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/no-applications-segment-here'))
        );
    }

    public function testListAllApplications(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $repo->create('user-1', 'A');
        $repo->create('user-2', 'B');

        $response = ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications'))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['applications']);
        self::assertNull($body['filter']);
        // The handler enriches with user_name/user_email from Zitadel; since
        // Zitadel isn't configured in tests, those stay as the unenriched values.
        self::assertArrayHasKey('uuid', $body['applications'][0]);
    }

    public function testListApplicationsFiltersByStatus(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $a    = $repo->create('user-1', 'A');
        /** @var string $aId */
        $aId = $a['id'];
        $repo->approveApplication($aId, 'admin');
        $repo->create('user-2', 'B'); // stays pending

        $response = ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications?status=approved'))
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['total']);
        self::assertSame('approved', $body['filter']);
    }

    public function testListApplicationsRejectsInvalidStatus(): void
    {
        $this->expectException(ValidationException::class);
        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications?status=weird'))
        );
    }

    public function testListPendingApplications(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $repo->create('user-1', 'A');
        $b = $repo->create('user-2', 'B');
        /** @var string $bId */
        $bId = $b['id'];
        $repo->approveApplication($bId, 'admin'); // no longer pending

        $response = ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications/pending'))
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['pending_count']);
        self::assertCount(1, $body['pending_applications']);
    }

    public function testGetApplicationByUuid(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $row  = $repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        $response = ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications/' . $id))
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame($id, $body['id']);
        self::assertSame($id, $body['uuid']);
        self::assertSame('X', $body['name']);
    }

    public function testGetApplicationByBadUuidIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications/not-a-uuid'))
        );
    }

    public function testGetApplicationMissingIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/applications/00000000-0000-0000-0000-000000000000'))
        );
    }

    public function testApproveApplication(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $row  = $repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        $response = ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/admin/applications/' . $id . '/approve', [], ['notes' => 'ok'])
            )
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame('approved', $body['application']['status']);
        self::assertSame('ok', $body['application']['review_notes']);
    }

    public function testApproveUnknownApplicationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/admin/applications/00000000-0000-0000-0000-000000000000/approve', [], [])
            )
        );
    }

    public function testInvalidActionIsValidationError(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $row  = $repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid action');

        ( new ApplicationAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/applications/' . $id . '/teleport', [], []))
        );
    }

    public function testRejectAndRevokeWorkflow(): void
    {
        $repo = new ApplicationRepository(self::$pdo);
        $row  = $repo->create('user-1', 'X');
        /** @var string $id */
        $id      = $row['id'];
        $handler = new ApplicationAdminHandler();

        // reject
        $resp = $handler->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/applications/' . $id . '/reject', [], ['notes' => 'nope']))
        );
        self::assertSame('rejected', $this->decodeJsonBody($resp)['application']['status']);

        // Re-approve from rejected.
        $resp = $handler->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/applications/' . $id . '/approve', [], []))
        );
        self::assertSame('approved', $this->decodeJsonBody($resp)['application']['status']);

        // revoke
        $resp = $handler->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/applications/' . $id . '/revoke', [], ['notes' => 'misuse']))
        );
        self::assertSame('revoked', $this->decodeJsonBody($resp)['application']['status']);
    }
}
