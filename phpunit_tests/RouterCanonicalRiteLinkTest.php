<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * The explicit rite form (`/calendar/roman/2026`) is the canonical form; the bare form
 * (`/calendar/2026`) is retained for backwards compatibility and advertises the canonical
 * form with an RFC 6596 `Link: rel="canonical"` header rather than a redirect.
 *
 * A redirect is not usable here: the calendar routes accept POST, a 301/302 would downgrade
 * POST to GET and drop the body, and per the Fetch standard a browser treats any redirect
 * response to a preflighted cross-origin request as a network error.
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
            Router::canonicalRiteUrl('calendar', false, Rite::ROMAN, ['2026'])
        );
    }

    public function testBareCalendarWithNoFurtherSegmentsAdvertisesTheRiteRoot(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman',
            Router::canonicalRiteUrl('calendar', false, Rite::ROMAN, [])
        );
    }

    public function testBareNationalCalendarKeepsTheRemainingSegments(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman/nation/IT/2026',
            Router::canonicalRiteUrl('calendar', false, Rite::ROMAN, ['nation', 'IT', '2026'])
        );
    }

    public function testBareEventsRouteAdvertisesTheExplicitRomanForm(): void
    {
        self::assertSame(
            'https://example.test/api/dev/events/roman',
            Router::canonicalRiteUrl('events', false, Rite::ROMAN, [])
        );
    }

    public function testQueryStringIsPreservedOnTheCanonicalUrl(): void
    {
        self::assertSame(
            'https://example.test/api/dev/calendar/roman/2026?year_type=CIVIL&locale=it',
            Router::canonicalRiteUrl('calendar', false, Rite::ROMAN, ['2026'], 'year_type=CIVIL&locale=it')
        );
    }

    public function testAnExplicitRiteRequestIsAlreadyCanonicalSoNoLinkIsAdvertised(): void
    {
        self::assertNull(Router::canonicalRiteUrl('calendar', true, Rite::ROMAN, ['2026']));
        self::assertNull(Router::canonicalRiteUrl('calendar', true, Rite::AMBROSIAN, ['diocese', 'milano_it']));
    }

    public function testRoutesWithoutARiteSegmentAdvertiseNoCanonicalForm(): void
    {
        // Only the calendar and events routes carry a rite segment (see extractRiteSegment()).
        self::assertNull(Router::canonicalRiteUrl('metadata', false, Rite::ROMAN, []));
        self::assertNull(Router::canonicalRiteUrl('missals', false, Rite::ROMAN, ['EDITIO_TYPICA_1970']));
    }

    public function testTheRootRouteAdvertisesNoCanonicalForm(): void
    {
        // `/` resolves to the calendar handler, but canonicalising it to `/calendar/roman`
        // would rename the endpoint rather than merely make the rite explicit.
        self::assertNull(Router::canonicalRiteUrl('', false, Rite::ROMAN, []));
    }
}
