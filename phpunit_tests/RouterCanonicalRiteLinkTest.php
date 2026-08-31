<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The explicit rite form (`/calendar/roman/2026`) is the canonical form; the bare form
 * (`/calendar/2026`) is retained for backwards compatibility and advertises the canonical
 * form with an RFC 6596 `Link: rel="canonical"` header rather than a redirect.
 *
 * A redirect is not usable here: the calendar routes accept POST, a 301/302 would downgrade
 * POST to GET and drop the body, and per the Fetch standard a browser treats any redirect
 * response to a preflighted cross-origin request as a network error.
 *
 * The header is scoped to the read methods (`GET` and `POST`). `/data` is the route that
 * makes the distinction matter — it is a mixed read/write surface, and `rel="canonical"` on
 * a `PUT` describes nothing the request is doing (#848). The same rule covers `OPTIONS`:
 * a CORS preflight is a control response rather than a representation of the resource.
 */
#[CoversMethod(Router::class, 'canonicalRiteUrl')]
final class RouterCanonicalRiteLinkTest extends TestCase
{
    /**
     * Router::$apiPath is global static state shared with every other test in the process,
     * so it is restored afterwards (same pattern as AbstractHandlerTestCase).
     */
    private static string $savedApiPath = '';

    public static function setUpBeforeClass(): void
    {
        self::$savedApiPath = isset(Router::$apiPath) ? Router::$apiPath : '';
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
    }

    protected function setUp(): void
    {
        Router::$apiPath = 'https://example.test/api/dev';
    }

    public function testBareCalendarYearAdvertisesTheExplicitRomanForm(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman/2026',
            Router::canonicalRiteUrl('calendar', 'GET', false, Rite::ROMAN, ['2026'])
        );
    }

    public function testBareCalendarWithNoFurtherSegmentsAdvertisesTheRiteRoot(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman',
            Router::canonicalRiteUrl('calendar', 'GET', false, Rite::ROMAN, [])
        );
    }

    public function testBareNationalCalendarKeepsTheRemainingSegments(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman/nation/IT/2026',
            Router::canonicalRiteUrl('calendar', 'GET', false, Rite::ROMAN, ['nation', 'IT', '2026'])
        );
    }

    public function testBareEventsRouteAdvertisesTheExplicitRomanForm(): void
    {
        self::assertSame(
            'https://example.test/api/dev/events/roman',
            Router::canonicalRiteUrl('events', 'GET', false, Rite::ROMAN, [])
        );
    }

    /**
     * `/data` reads the source definition of a calendar, and that read wants the same
     * discoverability the other two route families have (#848).
     */
    public function testBareDataRouteAdvertisesTheExplicitRomanForm(): void
    {
        self::assertSame(
            'https://example.test/api/dev/data/roman/nation/IT',
            Router::canonicalRiteUrl('data', 'GET', false, Rite::ROMAN, ['nation', 'IT'])
        );
    }

    /**
     * On `/data`, `POST` is a read verb — the "retrieve with parameters in a request body"
     * spelling of `GET` — which is why the read surface is `GET`+`POST` rather than `GET` alone.
     */
    public function testBareDataRouteAdvertisesTheCanonicalFormOnAPostRead(): void
    {
        self::assertSame(
            'https://example.test/api/dev/data/roman/diocese/romamo_it',
            Router::canonicalRiteUrl('data', 'POST', false, Rite::ROMAN, ['diocese', 'romamo_it'])
        );
    }

    /**
     * `/missals` joins the same allow-list as `/calendar`, `/events`, `/data` and `/lectionary`
     * (#953): a request that omits the rite segment is answered with a `Link: rel="canonical"`
     * naming the explicit `/missals/roman/...` form.
     */
    public function testBareMissalsRouteAdvertisesTheExplicitRomanForm(): void
    {
        self::assertSame(
            'https://example.test/api/dev/missals/roman/EDITIO_TYPICA_1970',
            Router::canonicalRiteUrl('missals', 'GET', false, Rite::ROMAN, ['EDITIO_TYPICA_1970'])
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function writeMethods(): array
    {
        return [
            'PUT'    => [RequestMethod::PUT->value],
            'PATCH'  => [RequestMethod::PATCH->value],
            'DELETE' => [RequestMethod::DELETE->value],
        ];
    }

    /**
     * A write request is not a representation of the resource it is addressed to, so naming a
     * canonical URL for it is noise. This is the objection the `data` route was originally
     * excluded over; scoping by method preserves it exactly.
     */
    #[DataProvider('writeMethods')]
    public function testAWriteRequestAdvertisesNoCanonicalForm(string $method): void
    {
        self::assertNull(Router::canonicalRiteUrl('data', $method, false, Rite::ROMAN, ['nation', 'IT']));
    }

    /**
     * A CORS preflight is a control response rather than a representation of the resource.
     * It needs no separate guard: `OPTIONS` is not a read method.
     */
    public function testAPreflightAdvertisesNoCanonicalForm(): void
    {
        self::assertNull(Router::canonicalRiteUrl('calendar', 'OPTIONS', false, Rite::ROMAN, ['2026']));
        self::assertNull(Router::canonicalRiteUrl('events', 'OPTIONS', false, Rite::ROMAN, []));
        self::assertNull(Router::canonicalRiteUrl('data', 'OPTIONS', false, Rite::ROMAN, ['nation', 'IT']));
    }

    public function testQueryStringIsPreservedOnTheCanonicalUrl(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman/2026?year_type=CIVIL&locale=it',
            Router::canonicalRiteUrl('calendar', 'GET', false, Rite::ROMAN, ['2026'], 'year_type=CIVIL&locale=it')
        );
    }

    public function testAnExplicitRiteRequestIsAlreadyCanonicalSoNoLinkIsAdvertised(): void
    {
        self::assertNull(Router::canonicalRiteUrl('calendar', 'GET', true, Rite::ROMAN, ['2026']));
        self::assertNull(Router::canonicalRiteUrl('calendar', 'GET', true, Rite::AMBROSIAN, ['diocese', 'milano_it']));
        self::assertNull(Router::canonicalRiteUrl('data', 'GET', true, Rite::ROMAN, ['nation', 'IT']));
    }

    public function testRoutesWithoutARiteSegmentAdvertiseNoCanonicalForm(): void
    {
        // `metadata` carries no rite segment at all (see extractRiteSegment()); `missals` does,
        // as of #953, and is covered separately above.
        self::assertNull(Router::canonicalRiteUrl('metadata', 'GET', false, Rite::ROMAN, []));
    }

    public function testTheRootRouteAdvertisesNoCanonicalForm(): void
    {
        // `/` resolves to the calendar handler, but canonicalising it to `/calendar/roman`
        // would rename the endpoint rather than merely make the rite explicit.
        self::assertNull(Router::canonicalRiteUrl('', 'GET', false, Rite::ROMAN, []));
    }
}
