<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Models\Calendar\Sanctorale\AmbrosianSanctoraleLoader;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisMap;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rite-scoped Ambrosian sanctorale loader (Plan 5 / Task 6).
 *
 * Mirrors `CalendarHandler::loadPropriumDeSanctisData()` but is scoped to the
 * Ambrosian rite, holds no per-request state, and returns the built
 * `PropriumDeSanctisMap` directly rather than mutating handler state.
 */
#[CoversClass(AmbrosianSanctoraleLoader::class)]
final class AmbrosianSanctoraleLoaderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // AmbrosianMissal::getSanctoraleFileName()/getSanctoraleI18nFilePath() depend on
        // JsonData::path(), which in turn depends on Router::$apiFilePath.
        Router::getApiPaths();
    }

    public function testLoadReturnsPropriumDeSanctisMap(): void
    {
        $loader = new AmbrosianSanctoraleLoader();
        $map    = $loader->load(AmbrosianMissal::EDITIO_2024, 'it');

        $this->assertInstanceOf(PropriumDeSanctisMap::class, $map);
    }

    public function testLoadIncludesStAmbroseWithItalianName(): void
    {
        $loader = new AmbrosianSanctoraleLoader();
        $map    = $loader->load(AmbrosianMissal::EDITIO_2024, 'it');

        $this->assertTrue($map->offsetExists('StAmbrose'), 'Expected StAmbrose (Dec 7) in the Ambrosian sanctorale');
        $event = $map['StAmbrose'];
        $this->assertSame(12, $event->month);
        $this->assertSame(7, $event->day);
        $this->assertNotSame('', $event->name, 'Expected StAmbrose to have a non-empty Italian name applied');
    }

    public function testLoadAppliesLatinNamesForLatinLocale(): void
    {
        $loader = new AmbrosianSanctoraleLoader();
        $map    = $loader->load(AmbrosianMissal::EDITIO_2024, 'la');

        $this->assertTrue($map->offsetExists('StAmbrose'));
        $this->assertNotSame('', $map['StAmbrose']->name);
    }
}
