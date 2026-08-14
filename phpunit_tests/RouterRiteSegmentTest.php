<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * The Router recognises an optional leading rite segment on the calendar and events
 * routes. `/calendar` and `/calendar/roman` are equivalent (both Roman); `/calendar/ambrosian`
 * selects the Ambrosian rite — and likewise `/events/ambrosian` for the events route
 * (Plan 7 Task 12). Any other leading segment (a year, `nation`, `diocese`) is left
 * untouched for the existing shape parsing.
 */
#[CoversMethod(Router::class, 'extractRiteSegment')]
final class RouterRiteSegmentTest extends TestCase
{
    public function testAmbrosianSegmentSelectsAmbrosianAndIsStripped(): void
    {
        $parts = ['ambrosian', 'diocese', 'milano_it'];
        self::assertSame(Rite::AMBROSIAN, Router::extractRiteSegment('calendar', $parts));
        self::assertSame(['diocese', 'milano_it'], $parts);
    }

    public function testRomanSegmentSelectsRomanAndIsStripped(): void
    {
        $parts = ['roman', '2025'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('calendar', $parts));
        self::assertSame(['2025'], $parts);
    }

    public function testBareRomanSegmentIsStripped(): void
    {
        $parts = ['roman'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('calendar', $parts));
        self::assertSame([], $parts);
    }

    public function testNoRiteSegmentDefaultsToRomanAndLeavesPartsIntact(): void
    {
        $parts = ['2025'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('calendar', $parts));
        self::assertSame(['2025'], $parts);
    }

    public function testBareCalendarDefaultsToRoman(): void
    {
        $parts = [];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('calendar', $parts));
        self::assertSame([], $parts);
    }

    public function testNationSegmentIsNotARite(): void
    {
        $parts = ['nation', 'US'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('calendar', $parts));
        self::assertSame(['nation', 'US'], $parts);
    }

    public function testRiteSegmentOnlyAppliesToTheRiteCarryingRoutes(): void
    {
        // A route that carries no rite segment must never treat a leading
        // 'ambrosian'/'roman' as a rite.
        $parts = ['ambrosian'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('metadata', $parts));
        self::assertSame(['ambrosian'], $parts);
    }

    public function testEventsRouteAlsoSupportsAnAmbrosianRiteSegment(): void
    {
        // Plan 7 Task 12: the events route gained the same optional leading rite
        // segment the calendar route already had.
        $parts = ['ambrosian'];
        self::assertSame(Rite::AMBROSIAN, Router::extractRiteSegment('events', $parts));
        self::assertSame([], $parts);
    }

    public function testEventsRouteWithNoRiteSegmentDefaultsToRoman(): void
    {
        $parts = ['nation', 'US'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('events', $parts));
        self::assertSame(['nation', 'US'], $parts);
    }

    /**
     * Issue #786: `/data` gained the same optional leading rite segment, so the
     * Ambrosian diocesan calendars became addressable — `/data/diocese/lugano_ch`
     * was a 404 before, because the handler could only read the Roman partition.
     */
    public function testDataRouteSupportsAnAmbrosianRiteSegment(): void
    {
        $parts = ['ambrosian', 'diocese', 'lugano_ch'];
        self::assertSame(Rite::AMBROSIAN, Router::extractRiteSegment('data', $parts));
        self::assertSame(['diocese', 'lugano_ch'], $parts);
    }

    public function testDataRouteExplicitRomanIsEquivalentToBare(): void
    {
        $explicit = ['roman', 'diocese', 'rotter_nl'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('data', $explicit));

        $bare = ['diocese', 'rotter_nl'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('data', $bare));

        // Both forms leave the handler the same 2-part shape to parse.
        self::assertSame($bare, $explicit);
    }

    public function testDataRouteWithNoRiteSegmentLeavesTheCategoryIntact(): void
    {
        $parts = ['widerregion', 'Europe'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('data', $parts));
        self::assertSame(['widerregion', 'Europe'], $parts);
    }
}
