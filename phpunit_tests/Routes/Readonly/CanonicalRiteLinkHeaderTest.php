<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Live HTTP integration test for the RFC 6596 `Link: rel="canonical"` header on the calendar,
 * events and data routes.
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
 *
 * `/data` carries the header on its read methods only (#848). The write half of that rule is
 * asserted in {@see \LiturgicalCalendar\Tests\RouterCanonicalRiteLinkTest}: proving it here would
 * mean driving an authenticated `PUT` all the way to a 2xx — creating and deleting a calendar
 * definition — where the unit test states the same rule without the side effects.
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

    public function testAResolvedConditionalRequestStillCarriesTheCanonicalLink(): void
    {
        // A 304 stands in for the 200 it would otherwise have been, describing the same resource,
        // so a client driving its own conditional requests should not lose the canonical URL
        // merely because its cache was still fresh.
        //
        // `/events` rather than `/calendar/{year}` because only the former has a stable validator:
        // a calendar response body embeds its own generation timestamp, so its ETag changes on
        // every request and a conditional request against it only yields a 304 when both requests
        // land in the same wall-clock second.
        $first = self::$http->get('/events', []);
        $etag  = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag, 'precondition: the bare events route is ETag-bearing');

        $second = self::$http->get('/events', ['headers' => ['If-None-Match' => $etag]]);

        self::assertSame(304, $second->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/events/roman>; rel="canonical"$#',
            $second->getHeaderLine('Link')
        );
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

    /**
     * `/data` reads a calendar's source definition, and that read wants the same discoverability
     * the other two families have. The header was previously withheld from the whole route on the
     * grounds that it is an admin write surface; scoping it to the read methods keeps that
     * objection intact while serving the reads (#848).
     */
    public function testBareNationalDataReadAdvertisesTheExplicitRomanForm(): void
    {
        $response = self::$http->get('/data/nation/IT', ['headers' => ['Accept-Language' => 'it-IT']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/data/roman/nation/IT>; rel="canonical"$#',
            $response->getHeaderLine('Link')
        );
    }

    /**
     * On `/data`, `POST` is a read verb — the "retrieve with parameters in a request body" form of
     * `GET` — which is why the read surface is `GET`+`POST` rather than `GET` alone.
     */
    public function testBareDiocesanDataPostReadAdvertisesTheExplicitRomanForm(): void
    {
        $response = self::$http->post('/data/diocese/romamo_it', ['headers' => ['Accept-Language' => 'it-IT']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '#^<' . self::originPattern() . '/data/roman/diocese/romamo_it>; rel="canonical"$#',
            $response->getHeaderLine('Link')
        );
    }

    public function testAnExplicitRomanDataReadIsAlreadyCanonicalSoCarriesNoLink(): void
    {
        $response = self::$http->get('/data/roman/nation/IT', ['headers' => ['Accept-Language' => 'it-IT']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Link'));
    }
}
