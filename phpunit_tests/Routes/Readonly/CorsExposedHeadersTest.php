<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Live HTTP integration test for `Access-Control-Expose-Headers`.
 *
 * A cross-origin browser client can only read the CORS-safelisted response headers
 * (Cache-Control, Content-Language, Content-Type, Expires, Last-Modified, Pragma). Every header
 * this API sets itself — `X-Request-Id`, `ETag`, and the canonical `Link` — is invisible to
 * JavaScript unless it is named in `Access-Control-Expose-Headers`. Without it, `X-Request-Id`
 * cannot be quoted in a bug report from the browser and `ETag` cannot be echoed back as
 * `If-None-Match` by a client doing its own conditional requests.
 */
final class CorsExposedHeadersTest extends ApiTestCase
{
    /**
     * @return list<string> the exposed header names, lowercased
     */
    private static function exposed(string $headerLine): array
    {
        if (trim($headerLine) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', $headerLine)
        )));
    }

    public function testEveryResponseExposesTheRequestIdHeader(): void
    {
        $response = self::$http->get('/calendars', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertContains('x-request-id', self::exposed($response->getHeaderLine('Access-Control-Expose-Headers')));
    }

    public function testAResponseCarryingAnETagExposesIt(): void
    {
        $response = self::$http->get('/calendars', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('ETag'), 'precondition: /calendars is an ETag-bearing route');
        self::assertContains('etag', self::exposed($response->getHeaderLine('Access-Control-Expose-Headers')));
    }

    public function testTheBareCalendarRouteExposesRequestIdETagAndCanonicalLinkTogether(): void
    {
        $response = self::$http->get('/calendar/2026', []);
        $exposed  = self::exposed($response->getHeaderLine('Access-Control-Expose-Headers'));

        self::assertSame(200, $response->getStatusCode());
        self::assertContains('x-request-id', $exposed);
        self::assertContains('etag', $exposed);
        self::assertContains('link', $exposed);
    }

    public function testTheCanonicalFormExposesRequestIdAndETagButNamesNoLink(): void
    {
        // The explicit-rite form carries no canonical Link, so naming one here would advertise a
        // header that is never sent.
        $response = self::$http->get('/calendar/roman/2026', []);
        $exposed  = self::exposed($response->getHeaderLine('Access-Control-Expose-Headers'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
        self::assertContains('x-request-id', $exposed);
        self::assertContains('etag', $exposed);
        self::assertNotContains('link', $exposed);
    }

    public function testAnErrorResponseStillExposesItsRequestId(): void
    {
        // The request id is the one thing a client needs most on a failure, and error responses
        // carry neither an ETag nor a canonical Link. `XX` is a well-formed but unsupported
        // national calendar id (valid: VA, CA, HR, IT, NL, US), so it is a deterministic 400.
        $response = self::$http->get('/calendar/nation/XX/2026', ['http_errors' => false]);
        $exposed  = self::exposed($response->getHeaderLine('Access-Control-Expose-Headers'));

        self::assertSame(400, $response->getStatusCode());
        self::assertContains('x-request-id', $exposed);
        self::assertNotContains('etag', $exposed);
        self::assertNotContains('link', $exposed);
    }
}
