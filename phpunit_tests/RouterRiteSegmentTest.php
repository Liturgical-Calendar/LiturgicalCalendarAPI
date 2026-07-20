<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * The Router recognises an optional leading rite segment on the calendar route.
 * `/calendar` and `/calendar/roman` are equivalent (both Roman); `/calendar/ambrosian`
 * selects the Ambrosian rite. Any other leading segment (a year, `nation`, `diocese`)
 * is left untouched for the existing shape parsing.
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

    public function testRiteSegmentOnlyAppliesToTheCalendarRoute(): void
    {
        // A non-calendar route must never treat a leading 'ambrosian'/'roman' as a rite.
        $parts = ['ambrosian'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('metadata', $parts));
        self::assertSame(['ambrosian'], $parts);
    }
}
