<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    private static string $savedApiPath;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        self::$savedApiPath = Router::$apiPath;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
    }

    public function testCaseValues(): void
    {
        self::assertSame('/calendars', Route::CALENDARS->value);
        self::assertSame('/calendar', Route::CALENDAR->value);
        self::assertSame('/calendar/nation', Route::CALENDAR_NATIONAL->value);
        self::assertSame('/calendar/diocese', Route::CALENDAR_DIOCESAN->value);
        self::assertSame('/decrees', Route::DECREES->value);
        self::assertSame('/tests', Route::TESTS->value);
        self::assertSame('/events', Route::EVENTS->value);
        self::assertSame('/events/nation', Route::EVENTS_NATIONAL->value);
        self::assertSame('/events/diocese', Route::EVENTS_DIOCESAN->value);
        self::assertSame('/data', Route::DATA->value);
        self::assertSame('/data/widerregion', Route::DATA_WIDERREGION->value);
        self::assertSame('/data/nation', Route::DATA_NATIONAL->value);
        self::assertSame('/data/diocese', Route::DATA_DIOCESAN->value);
        self::assertSame('/easter', Route::EASTER->value);
        self::assertSame('/schemas', Route::SCHEMAS->value);
        self::assertSame('/missals', Route::MISSALS->value);
        self::assertSame('/_ops/migrate', Route::OPS_MIGRATE->value);
        self::assertSame('/_ops/migrate/status', Route::OPS_MIGRATE_STATUS->value);
    }

    public function testPathPrependsApiPath(): void
    {
        Router::$apiPath = 'https://api.example.test';
        self::assertSame('https://api.example.test/calendar', Route::CALENDAR->path());
        self::assertSame('https://api.example.test/data/diocese', Route::DATA_DIOCESAN->path());
    }
}
