<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Router::class, 'canonicaliseWiderRegionKey')]
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
}
