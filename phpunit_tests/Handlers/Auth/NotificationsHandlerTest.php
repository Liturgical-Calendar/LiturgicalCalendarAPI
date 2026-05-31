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

    public function testPostSeenRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        ( new NotificationsHandler() )->handle(
            $this->requestFor('POST', '/auth/notifications/seen', [], [])
        );
    }

    public function testPostSeenInsertsBookmarkAndReturnsTimestamp(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-seen-1'
            )
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $body['seen_at']
        );

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM user_notification_state WHERE user_id = :u'
        );
        $stmt->execute(['u' => 'zitadel-user-seen-1']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testPostSeenTwiceAdvancesTimestamp(): void
    {
        $h = new NotificationsHandler();

        $resp1 = $h->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-seen-2'
            )
        );
        $body1 = $this->decodeJsonBody($resp1);

        usleep(1_100_000);

        $resp2 = $h->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-seen-2'
            )
        );
        $body2 = $this->decodeJsonBody($resp2);

        self::assertGreaterThan($body1['seen_at'], $body2['seen_at']);
    }

    public function testPostSeenUnknownSubPathReturns404(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/bogus', [], []),
                'zitadel-user-seen-3'
            )
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPostSeenAtBaseUrlReturns404(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications', [], []),
                'zitadel-user-seen-4'
            )
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRejectsEmptyStringSub(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');

        $request = $this->requestFor('GET', '/auth/notifications')
            ->withAttribute('oidc_user', ['sub' => '']);

        ( new NotificationsHandler() )->handle($request);
    }

    public function testRejectsWhitespaceOnlySub(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');

        $request = $this->requestFor('GET', '/auth/notifications')
            ->withAttribute('oidc_user', ['sub' => '   ']);

        ( new NotificationsHandler() )->handle($request);
    }

    public function testRejectsMissingSub(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');

        // oidc_user without a 'sub' key — $userId resolves to null,
        // triggering the !is_string($userId) branch.
        $request = $this->requestFor('GET', '/auth/notifications')
            ->withAttribute('oidc_user', ['email' => 'x@example.test']);

        ( new NotificationsHandler() )->handle($request);
    }

    public function testHandlesOptionsPreflight(): void
    {
        $request  = $this->requestFor('OPTIONS', '/auth/notifications')
            ->withHeader('Origin', 'https://example.test')
            ->withHeader('Access-Control-Request-Method', 'GET');
        $response = ( new NotificationsHandler() )->handle($request);

        // Preflight should short-circuit before auth, so no UnauthorizedException
        // is thrown. Status should be 2xx (typically 204 No Content).
        self::assertLessThan(300, $response->getStatusCode());
        self::assertGreaterThanOrEqual(200, $response->getStatusCode());
    }

    public function testUnknownHttpMethodReturns405(): void
    {
        // Method outside the RequestMethod enum (e.g. 'BANANA') makes
        // RequestMethod::tryFrom return null; validateRequestMethod then
        // throws a MethodNotAllowedException that surfaces as 405.
        $this->expectException(\Throwable::class);

        $request = $this->requestFor('BANANA', '/auth/notifications');

        ( new NotificationsHandler() )->handle($request);
    }

    public function testExtractSubPathFallbackWhenApiBasePathUnset(): void
    {
        // The fallback branch in extractSubPath fires when API_BASE_PATH is
        // absent or non-string. .env.local normally provides API_BASE_PATH=''
        // so we have to temporarily unset it to exercise the fallback.
        $originalEnv    = $_ENV['API_BASE_PATH'] ?? null;
        $originalServer = $_SERVER['API_BASE_PATH'] ?? null;
        unset($_ENV['API_BASE_PATH'], $_SERVER['API_BASE_PATH']);

        try {
            $response = ( new NotificationsHandler() )->handle(
                $this->withOidcUser(
                    $this->requestFor('GET', '/auth/notifications'),
                    'zitadel-user-fallback'
                )
            );
            self::assertSame(200, $response->getStatusCode());
        } finally {
            if ($originalEnv !== null) {
                $_ENV['API_BASE_PATH'] = $originalEnv;
            }
            if ($originalServer !== null) {
                $_SERVER['API_BASE_PATH'] = $originalServer;
            }
        }
    }

    public function testGetThenSeenThenGetFlipsUnreadFlag(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create(
            'zitadel-user-rt-1',
            'rt@example.test',
            'RT',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $repo->approve($id, 'admin-x', null);

        $h = new NotificationsHandler();

        $body1 = $this->decodeJsonBody($h->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-rt-1'
            )
        ));
        self::assertSame(1, $body1['unread_count']);
        self::assertTrue($body1['items'][0]['unread']);

        usleep(1_100_000);

        $h->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-rt-1'
            )
        );

        $body2 = $this->decodeJsonBody($h->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-rt-1'
            )
        ));
        self::assertSame(0, $body2['unread_count']);
        self::assertFalse($body2['items'][0]['unread']);
        self::assertSame(1, $body2['total']);
    }
}
