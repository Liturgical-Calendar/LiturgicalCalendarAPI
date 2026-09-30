<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Router::class, 'canonicaliseWiderRegionKey')]
#[CoversMethod(Router::class, 'calendarIdFor')]
#[CoversMethod(Router::class, 'canonicalPathPartsFor')]
final class RouterWiderRegionKeyTest extends TestCase
{
    public function testALegacyRegionKeyIsRewrittenToItsId(): void
    {
        $parts = ['widerregion', 'Europe', 'it_IT'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'europe', 'it_IT'], $parts);
    }

    public function testAnEncodedMultiWordLegacyKeyIsRewritten(): void
    {
        $parts = ['widerregion', 'Middle%20East'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'middle-east'], $parts);
    }

    public function testAnIdIsLeftAlone(): void
    {
        $parts = ['widerregion', 'europe'];
        self::assertFalse(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'europe'], $parts);
    }

    public function testOtherCategoriesAndGarbageAreLeftAlone(): void
    {
        $nation = ['nation', 'IT'];
        self::assertFalse(Router::canonicaliseWiderRegionKey($nation));
        self::assertSame(['nation', 'IT'], $nation);

        $garbage = ['widerregion', 'eu rope'];
        self::assertFalse(Router::canonicaliseWiderRegionKey($garbage));
        self::assertSame(['widerregion', 'eu rope'], $garbage);
    }

    /** The `calendar_id` handed to authorization for `/data/widerregion/Europe` names the region by its id. */
    public function testTheCalendarIdForALegacyRegionPathIsTheId(): void
    {
        self::assertSame('europe', Router::calendarIdFor(['widerregion', 'Europe']));
        self::assertSame('german-language-area', Router::calendarIdFor(['widerregion', 'German%20Language%20Area', 'de_DE']));
        self::assertSame('europe', Router::calendarIdFor(['widerregion', 'europe']));
        self::assertSame('IT', Router::calendarIdFor(['nation', 'IT']));
        self::assertNull(Router::calendarIdFor(['widerregion']));
    }

    /** `Link: rel=canonical` for `/data/widerregion/Europe` names the id, not the legacy key. */
    public function testTheCanonicalPathOfALegacyRegionKeyNamesTheId(): void
    {
        self::assertSame(['widerregion', 'europe'], Router::canonicalPathPartsFor('data', ['widerregion', 'Europe']));
        self::assertSame(['widerregion', 'german-language-area', 'de_DE'], Router::canonicalPathPartsFor('data', ['widerregion', 'German%20Language%20Area', 'de_DE']));
        // Only `/data` carries a region key.
        self::assertSame(['widerregion', 'Europe'], Router::canonicalPathPartsFor('calendar', ['widerregion', 'Europe']));
    }
}
