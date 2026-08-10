<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Live HTTP integration test for the RFC 6596 `Link: rel="canonical"` header on the calendar
 * and events routes.
 *
 * The explicit rite form (`/calendar/roman/2026`) is the canonical form; the bare form
 * (`/calendar/2026`) is retained for backwards compatibility and points at the canonical form
 * with a `Link` header rather than a redirect. A redirect is not usable here: these routes
 * accept POST, a 301/302 would downgrade POST to GET and drop the body, and per the Fetch
 * standard a browser treats any redirect response to a preflighted cross-origin request as a
 * network error — which would break the browser clients that build the bare paths.
 *
 * `Link` is not a CORS-safelisted response header, so it is only readable by a cross-origin
 * browser client when the API also names it in `Access-Control-Expose-Headers`.
 */
final class CanonicalRiteLinkHeaderTest extends ApiTestCase
{
    /**
     * Regex fragment matching the API origin as the running dev server reports it.
     */
    private static function originPattern(): string
    {
        return 'https?://' . self::hostRegex() . '(?::\d+)?';
    }

    public function testBareCalendarForYearAdvertisesTheExplicitRomanFormAsCanonical(): void
    {
        $response = self::$http->get('/calendar/2026', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/calendar/roman/2026>; rel="canonical"$#',
            $response->getHeaderLine('Link')
        );
    }

    public function testBareNationalCalendarKeepsItsRemainingSegmentsInTheCanonicalUrl(): void
    {
        $response = self::$http->get('/calendar/nation/IT/2026', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/calendar/roman/nation/IT/2026>; rel="canonical"$#',
            $response->getHeaderLine('Link')
        );
    }

    public function testBareEventsRouteAdvertisesTheExplicitRomanForm(): void
    {
        $response = self::$http->get('/events', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/events/roman>; rel="canonical"$#',
            $response->getHeaderLine('Link')
        );
    }

    public function testTheCanonicalUrlPreservesTheQueryString(): void
    {
        $response = self::$http->get('/calendar/2026?year_type=CIVIL', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/calendar/roman/2026\?year_type=CIVIL>; rel="canonical"$#',
            $response->getHeaderLine('Link')
        );
    }

    public function testTheCanonicalLinkIsReadableByCrossOriginBrowserClients(): void
    {
        $response = self::$http->get('/calendar/2026', ['headers' => ['Origin' => 'https://example.test']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Link'));
        self::assertStringContainsStringIgnoringCase(
            'Link',
            $response->getHeaderLine('Access-Control-Expose-Headers'),
            'Link is not CORS-safelisted, so it must be exposed explicitly or browser clients cannot read it'
        );
    }

    public function testAnExplicitRomanRequestIsAlreadyCanonicalSoCarriesNoLink(): void
    {
        $response = self::$http->get('/calendar/roman/2026', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    public function testAnExplicitAmbrosianRequestIsAlreadyCanonicalSoCarriesNoLink(): void
    {
        $response = self::$http->get('/calendar/ambrosian/2026', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    public function testAPreflightResponseCarriesNoCanonicalLink(): void
    {
        // A CORS preflight is a control response, not a representation of the resource, so a
        // rel="canonical" naming the resource has no meaning on it.
        $response = self::$http->options('/calendar/2026', [
            'headers' => [
                'Origin'                        => 'https://example.test',
                'Access-Control-Request-Method' => 'POST'
            ]
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    public function testARouteWithoutARiteSegmentCarriesNoCanonicalLink(): void
    {
        $response = self::$http->get('/calendars', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    public function testAnErrorResponseCarriesNoCanonicalLink(): void
    {
        // `XX` is a well-formed but unsupported national calendar id (valid: VA, CA, HR, IT, NL,
        // US), so this is a deterministic 400. Advertising a canonical URL for a request that did
        // not resolve would point clients at an equally invalid URL.
        $response = self::$http->get('/calendar/nation/XX/2026', ['http_errors' => false]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
    }
}
