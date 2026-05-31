<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\NotificationsHandler;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;

final class NotificationsHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    public function testGetInboxRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        ( new NotificationsHandler() )->handle(
            $this->requestFor('GET', '/auth/notifications')
        );
    }

    public function testGetInboxReturnsEmptyShapeForNewUser(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-x'
            )
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = $this->decodeJsonBody($response);
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
        self::assertSame(0, $body['unread_count']);
        self::assertSame('1970-01-01T00:00:00+00:00', $body['last_seen_at']);
    }

    public function testGetInboxReturnsReviewedItemsWithUnreadFlag(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create(
            'zitadel-user-y',
            'y@example.test',
            'Y',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $repo->approve($id, 'admin-z', 'welcome');

        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-y'
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['items']);
        self::assertSame($id, $body['items'][0]['request_id']);
        self::assertSame('access_request_reviewed', $body['items'][0]['type']);
        self::assertSame('approved', $body['items'][0]['status']);
        self::assertSame('welcome', $body['items'][0]['review_notes']);
        self::assertSame('calendar_editor', $body['items'][0]['requested_role']);
        self::assertTrue($body['items'][0]['unread']);
        self::assertSame(1, $body['total']);
        self::assertSame(1, $body['unread_count']);
    }

    public function testGetInboxUnknownSubPathReturns404(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications/bogus'),
                'zitadel-user-x'
            )
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
