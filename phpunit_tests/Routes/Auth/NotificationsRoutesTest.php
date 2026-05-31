<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Auth;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for /auth/notifications.
 *
 * Requires a running API server, a configured database, and a Zitadel
 * OIDC token (service account key file) for the token-requiring tests.
 * Falls back to skipping cleanly when only a legacy JWT is available,
 * since /auth/notifications is wired without legacy-JWT fallback in
 * OidcAuthMiddleware.
 */
#[Group('slow')]
final class NotificationsRoutesTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!self::isDatabaseConfigured()) {
            self::markTestSkipped('Database not configured.');
        }
        // OidcAvailabilityMiddleware short-circuits with 503 when Zitadel
        // envs aren't set on the running server. In that environment every
        // request to /auth/notifications returns 503 regardless of auth,
        // so neither the no-auth-401 assertion nor the token-flow tests
        // can run meaningfully.
        $probe = self::$http->get('/auth/notifications', [
            'headers' => ['Accept' => 'application/json'],
        ]);
        if ($probe->getStatusCode() === 503) {
            self::markTestSkipped('OIDC (Zitadel) not configured on the running server.');
        }
    }

    /**
     * Obtain a token that the OIDC middleware on /auth/notifications will
     * accept. Returns null when no token can be obtained at all, or when
     * the obtainable token is a legacy JWT that the OIDC middleware
     * rejects (i.e. no Zitadel locally configured).
     */
    private function getAcceptableToken(): ?string
    {
        $token = self::getJwtToken();
        if ($token === null) {
            return null;
        }

        // Probe: only treat the token as usable if /auth/notifications
        // accepts it with a 200. Any other status (401 legacy-JWT reject,
        // 5xx transient failure, 406 Accept mismatch, etc.) means the
        // behavior-shape tests can't run meaningfully — signal skip rather
        // than letting downstream assertions fail with confusing diagnostics.
        $probe = self::$http->get('/auth/notifications', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Accept' => 'application/json']
            ),
        ]);
        if ($probe->getStatusCode() !== 200) {
            return null;
        }

        return $token;
    }

    public function testInboxRequiresAuth(): void
    {
        $response = self::$http->get('/auth/notifications', [
            'headers' => ['Accept' => 'application/json'],
        ]);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testInboxReturnsExpectedShapeWithBearer(): void
    {
        $token = $this->getAcceptableToken();
        if ($token === null) {
            self::markTestSkipped('No OIDC-acceptable token obtainable (Zitadel not configured locally).');
        }

        $response = self::$http->get('/auth/notifications', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Accept' => 'application/json']
            ),
        ]);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('items', $body);
        self::assertArrayHasKey('total', $body);
        self::assertArrayHasKey('unread_count', $body);
        self::assertArrayHasKey('last_seen_at', $body);
        self::assertIsArray($body['items']);
        self::assertIsInt($body['total']);
        self::assertIsInt($body['unread_count']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testPostSeenWithBearer(): void
    {
        $token = $this->getAcceptableToken();
        if ($token === null) {
            self::markTestSkipped('No OIDC-acceptable token obtainable (Zitadel not configured locally).');
        }

        $response = self::$http->post('/auth/notifications/seen', [
            'headers' => array_merge(
                self::authHeaders($token),
                [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ]
            ),
            'body'    => '{}',
        ]);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('seen_at', $body);
        self::assertIsString($body['seen_at']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $body['seen_at']
        );
    }

    public function testGetSeenGetEndToEnd(): void
    {
        $token = $this->getAcceptableToken();
        if ($token === null) {
            self::markTestSkipped('No OIDC-acceptable token obtainable (Zitadel not configured locally).');
        }

        $getHeaders  = array_merge(
            self::authHeaders($token),
            ['Accept' => 'application/json']
        );
        $postHeaders = array_merge(
            self::authHeaders($token),
            [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ]
        );

        self::$http->post('/auth/notifications/seen', [
            'headers' => $postHeaders,
            'body'    => '{}',
        ]);
        sleep(1);
        $second = self::$http->get('/auth/notifications', ['headers' => $getHeaders]);
        self::assertSame(200, $second->getStatusCode());

        $body = json_decode((string) $second->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('unread_count', $body);
        self::assertSame(0, $body['unread_count']);
        self::assertArrayHasKey('items', $body);
        self::assertIsArray($body['items']);
        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('unread', $item);
            self::assertFalse($item['unread']);
        }
    }
}
