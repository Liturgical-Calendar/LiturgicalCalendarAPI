<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\AccessRequestHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(AccessRequestHandler::class)]
final class AccessRequestHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    /** @return array<string,mixed> */
    private function oidcUser(string $sub = 'user-alice', string $email = 'alice@x.test', string $name = 'Alice'): array
    {
        return [
            'sub'   => $sub,
            'email' => $email,
            'name'  => $name,
            'roles' => [],
        ];
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new AccessRequestHandler() )->handle(
            $this->requestFor('OPTIONS', '/auth/access-requests', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'POST',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testUnknownMethodIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new AccessRequestHandler() )->handle($this->requestFor('DELETE', '/auth/access-requests'));
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new AccessRequestHandler() )->handle($this->requestFor('GET', '/auth/access-requests'));
    }

    public function testMissingSubIsUnauthorized(): void
    {
        $request = $this->requestFor('GET', '/auth/access-requests')
            ->withAttribute('oidc_user', ['email' => 'a@b.test']);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testGetWithoutPathSuffixListsOwnRequests(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-alice', 'a@x.test', null, 'developer', []);
        $repo->create('user-alice', 'a@x.test', null, 'calendar_editor', []);
        $repo->create('user-bob', 'b@x.test', null, 'developer', []); // not Alice's

        $request = $this->requestFor('GET', '/auth/access-requests')
            ->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(2, $body['count']);
        self::assertCount(2, $body['requests']);
    }

    public function testGetStatusReportsCountsAndNeedsAccess(): void
    {
        // Fresh user with no requests should be told they need to request access.
        $request = $this->requestFor('GET', '/auth/access-requests/status')
            ->withAttribute('oidc_user', $this->oidcUser('user-new'));

        $response = ( new AccessRequestHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertFalse($body['has_roles']);
        self::assertSame(0, $body['pending_requests']);
        self::assertSame(0, $body['approved_requests']);
        self::assertSame(0, $body['rejected_requests']);
        self::assertTrue($body['needs_access_request']);
        self::assertSame(AccessRequestRepository::VALID_ROLES, $body['valid_roles']);
    }

    public function testCreateRequestRequiresRequestedRole(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            ['permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requested_role is required');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testCreateRejectsUnknownRole(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'galactic_overlord',
                'permissions'    => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid requested_role');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testCreateRejectsEmptyPermissions(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            ['requested_role' => 'developer', 'permissions' => []]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('permissions is required');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testCreateRejectsInvalidObjectType(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'developer',
                'permissions'    => [['object_type' => 'not_a_type', 'object_id' => 'IT', 'relation' => 'editor']],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('object_type');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testCreateRejectsCalendarEditorRequestingTestPermission(): void
    {
        // role-permission consistency check: calendar_editor restricted to calendar types.
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'calendar_editor',
                'permissions'    => [['object_type' => 'test_definition', 'object_id' => 'foo', 'relation' => 'editor']],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('calendar_editor');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testCreateRejectsTestEditorRequestingCalendarPermission(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'test_editor',
                'permissions'    => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('test_editor');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testCreateHappyPathReturnsRequestId(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'developer',
                'permissions'    => [
                    ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
                ],
                'justification'  => 'I help maintain the IT calendar.',
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $body['request_id']
        );
    }

    public function testCreateBlocksDuplicatePendingRequest(): void
    {
        $handler = new AccessRequestHandler();
        $first   = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'developer',
                'permissions'    => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $handler->handle($first); // succeeds

        $second = $this->requestFor(
            'POST',
            '/auth/access-requests',
            [],
            [
                'requested_role' => 'developer',
                'permissions'    => [['object_type' => 'national_calendar', 'object_id' => 'US', 'relation' => 'editor']],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already have a pending request');
        $handler->handle($second);
    }

    public function testResubmitRequiresUuidFormat(): void
    {
        $request = $this->requestFor(
            'POST',
            '/auth/access-requests/not-a-uuid/resubmit',
            [],
            ['permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid request ID format');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testResubmitForbidsNonOwner(): void
    {
        $repo  = new AccessRequestRepository(self::$pdo);
        $reqId = $repo->create('user-bob', 'b@x.test', null, 'developer', []);
        $repo->reject($reqId, 'admin');

        $request = $this->requestFor(
            'POST',
            '/auth/access-requests/' . $reqId . '/resubmit',
            [],
            ['permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]]
        )->withAttribute('oidc_user', $this->oidcUser('user-alice'));

        $this->expectException(ForbiddenException::class);
        ( new AccessRequestHandler() )->handle($request);
    }

    public function testResubmitHappyPathFlipsStatusToPending(): void
    {
        $repo  = new AccessRequestRepository(self::$pdo);
        $reqId = $repo->create('user-alice', 'a@x.test', null, 'developer', []);
        $repo->reject($reqId, 'admin');

        $request = $this->requestFor(
            'POST',
            '/auth/access-requests/' . $reqId . '/resubmit',
            [],
            [
                'permissions' => [
                    ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
                ],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);

        $row = $repo->getById($reqId);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
    }

    // --- Pagination (issue #572) -----------------------------------------

    public function testListOwnRequestsWithoutParamsReturnsFirstPage(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-alice', 'a@x.test', null, 'developer', []);
        $repo->create('user-alice', 'a@x.test', null, 'calendar_editor', []);
        $repo->create('user-bob', 'b@x.test', null, 'developer', []); // not Alice's

        $request = $this->requestFor('GET', '/auth/access-requests')
            ->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertCount(2, $body['requests']);
        self::assertSame(2, $body['count']);
        self::assertSame(2, $body['total']);
        self::assertSame(100, $body['limit']);
        self::assertSame(0, $body['offset']);
        self::assertFalse($body['has_more']);
    }

    public function testListOwnRequestsWithLimitAndOffsetReturnsSlice(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        // Three Alice rows, ordered newest-to-oldest by created_at after inserts.
        $repo->create('user-alice', 'a@x.test', null, 'developer', []);
        usleep(2000);
        $repo->create('user-alice', 'a@x.test', null, 'calendar_editor', []);
        usleep(2000);
        $repo->create('user-alice', 'a@x.test', null, 'test_editor', []);

        $request = $this->requestFor('GET', '/auth/access-requests?limit=1&offset=1')
            ->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['requests']);
        self::assertSame(1, $body['count']);
        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['limit']);
        self::assertSame(1, $body['offset']);
        self::assertTrue($body['has_more']); // offset(1) + count(1) = 2 < total(3)
    }

    public function testListOwnRequestsHasMoreFalseOnLastPage(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-alice', 'a@x.test', null, 'developer', []);
        usleep(2000);
        $repo->create('user-alice', 'a@x.test', null, 'calendar_editor', []);
        usleep(2000);
        $repo->create('user-alice', 'a@x.test', null, 'test_editor', []);

        // Page 2 of 2 with limit=2: offset=2, expect 1 row, has_more=false.
        $request = $this->requestFor('GET', '/auth/access-requests?limit=2&offset=2')
            ->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);

        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['count']);
        self::assertSame(3, $body['total']);
        self::assertFalse($body['has_more']);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function invalidLimitProvider(): iterable
    {
        yield 'zero'         => ['limit=0', 'between 1 and 500'];
        yield 'too-large'    => ['limit=501', 'between 1 and 500'];
        yield 'non-numeric'  => ['limit=abc', 'must be a positive integer'];
        yield 'negative'     => ['limit=-1', 'must be a positive integer'];
    }

    #[DataProvider('invalidLimitProvider')]
    public function testListOwnRequestsRejectsInvalidLimit(string $query, string $messageFragment): void
    {
        $request = $this->requestFor('GET', '/auth/access-requests?' . $query)
            ->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($messageFragment);

        ( new AccessRequestHandler() )->handle($request);
    }

    /** @return iterable<string, array{0:string}> */
    public static function invalidOffsetProvider(): iterable
    {
        yield 'negative'    => ['offset=-1'];
        yield 'non-numeric' => ['offset=abc'];
    }

    #[DataProvider('invalidOffsetProvider')]
    public function testListOwnRequestsRejectsInvalidOffset(string $query): void
    {
        $request = $this->requestFor('GET', '/auth/access-requests?' . $query)
            ->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('offset must be a non-negative integer');

        ( new AccessRequestHandler() )->handle($request);
    }
}
