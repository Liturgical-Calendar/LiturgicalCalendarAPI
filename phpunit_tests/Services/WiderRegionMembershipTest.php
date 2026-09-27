<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionMembership;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Wider region membership, read from the bundled source data: the fact that decides
 * whether a national editor may write a wider region's translations.
 */
#[CoversClass(WiderRegionMembership::class)]
final class WiderRegionMembershipTest extends TestCase
{
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    public function testANationWithACalendarBelongsToItsDeclaredRegion(): void
    {
        self::assertSame(['Americas'], WiderRegionMembership::regionsOf('CA'));
        self::assertSame(['Europe'], WiderRegionMembership::regionsOf('IT'));
    }

    public function testTheRegionsOwnMemberListCountsWithoutANationalCalendar(): void
    {
        // Hungary has no national calendar, but Europe lists it among its nations.
        self::assertSame(['Europe'], WiderRegionMembership::regionsOf('HU'));
    }

    public function testANationInNoRegionHasNone(): void
    {
        self::assertSame([], WiderRegionMembership::regionsOf('VE'));
    }
}
