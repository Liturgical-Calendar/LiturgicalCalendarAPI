<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NotificationsHandler::class)]
final class NotificationsHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/notifications', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testPostIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new NotificationsHandler() )->handle($this->requestFor('POST', '/admin/notifications'));
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new NotificationsHandler() )->handle($this->requestFor('GET', '/admin/notifications'));
    }

    public function testNonAdminIsForbidden(): void
    {
        $request = $this->requestFor('GET', '/admin/notifications')
            ->withAttribute('oidc_user', ['sub' => 'user-1', 'roles' => ['viewer']]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Admin role required');

        ( new NotificationsHandler() )->handle($request);
    }

    public function testAdminGetsZeroCountsWhenNoPendingItems(): void
    {
        $request = $this->withOidcUser($this->requestFor('GET', '/admin/notifications'));

        $response = ( new NotificationsHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->decodeJsonBody($response);
        self::assertSame(0, $body['pending_access_requests']);
        self::assertSame(0, $body['pending_applications']);
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);
    }

    public function testAdminGetsCountsWhenPendingItemsExist(): void
    {
        // usleep between inserts so the rows have monotonically increasing
        // created_at timestamps the handler can sort by.
        $accessRepo = new AccessRequestRepository(self::$pdo);
        $accessRepo->create('user-a', 'a@x.test', 'Alice', 'developer', []);
        usleep(2000);
        $accessRepo->create('user-b', 'b@x.test', 'Bob', 'developer', []);
        usleep(2000);

        $appRepo = new ApplicationRepository(self::$pdo);
        $appRepo->create('user-c', 'AppC', 'desc', null, 'read'); // pending by default, newest

        $request  = $this->withOidcUser($this->requestFor('GET', '/admin/notifications'));
        $response = ( new NotificationsHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(2, $body['pending_access_requests']);
        self::assertSame(1, $body['pending_applications']);
        self::assertSame(3, $body['total']);
        self::assertCount(3, $body['items']);

        // Verify items are returned newest-first by created_at, then assert
        // the type sequence matches the insertion order in reverse: application
        // was inserted last (so it's first), the two access_requests follow.
        $timestamps = array_column($body['items'], 'created_at');
        $sorted     = $timestamps;
        rsort($sorted, SORT_STRING);
        self::assertSame($sorted, $timestamps, 'Items must be sorted by created_at descending');

        $types = array_column($body['items'], 'type');
        self::assertSame(['application', 'access_request', 'access_request'], $types);
    }
}
