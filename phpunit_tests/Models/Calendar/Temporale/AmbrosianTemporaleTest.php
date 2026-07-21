<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Engine unit tests for the Ambrosian temporale skeleton + Advent block.
 * Tasks 5-8 will extend this class with the remaining seasons.
 */
#[CoversClass(AmbrosianTemporale::class)]
final class AmbrosianTemporaleTest extends TestCase
{
    use AmbrosianTemporaleHarnessTrait;

    public function testAdvent2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-11-16', $d['Advent1']);
        $this->assertSame('2025-11-23', $d['Advent2']);
        $this->assertSame('2025-11-30', $d['Advent3']);
        $this->assertSame('2025-12-07', $d['Advent4']);
        $this->assertSame('2025-12-14', $d['Advent5']);
        $this->assertSame('2025-12-21', $d['Advent6']);
    }

    public function testAdvent2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-11-17', $d['Advent1']);
        $this->assertSame('2024-12-22', $d['Advent6']);
    }

    public function testChristmasEpiphany2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-12-25', $d['Christmas']);
        $this->assertSame('2025-01-01', $d['Circoncisione']);
        $this->assertSame('2025-01-06', $d['Epiphany']);
        $this->assertSame('2025-01-12', $d['BaptismLord']);
    }

    public function testBaptism2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-01-07', $d['BaptismLord']);
    }

    public function testLent2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-03-09', $d['Lent1']);
        $this->assertSame('2025-03-16', $d['Lent2']);
        $this->assertSame('2025-03-23', $d['Lent3']);
        $this->assertSame('2025-03-30', $d['Lent4']);
        $this->assertSame('2025-04-06', $d['Lent5']);
        $this->assertSame('2025-03-10', $d['AshesMonday']);
        $this->assertSame('2025-04-13', $d['PalmSun']);
        $this->assertSame('2025-04-12', $d['SabatoTradSymb']);
    }

    public function testNoAshWednesday2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertArrayNotHasKey('AshWednesday', $d);
    }

    public function testLent2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-02-18', $d['Lent1']);
        $this->assertSame('2024-02-19', $d['AshesMonday']);
        $this->assertSame('2024-03-23', $d['SabatoTradSymb']);
    }

    public function testEasterCycle2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-04-17', $d['HolyThurs']);
        $this->assertSame('2025-04-18', $d['GoodFri']);
        $this->assertSame('2025-04-19', $d['EasterVigil']);
        $this->assertSame('2025-04-20', $d['Easter']);
        $this->assertSame('2025-04-21', $d['MonOctaveEaster']);
        $this->assertSame('2025-04-26', $d['SatOctaveEaster']);
        $this->assertSame('2025-04-27', $d['Easter2']);
        $this->assertSame('2025-06-01', $d['Easter7']);
        $this->assertSame('2025-05-29', $d['Ascension']);
        $this->assertSame('2025-06-08', $d['Pentecost']);
    }

    public function testAfterPentecostAnchors2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-10-19', $d['DedicationDuomo']);
        $this->assertSame('2025-11-09', $d['ChristKing']);
    }

    public function testAfterPentecostAnchors2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-10-20', $d['DedicationDuomo']);
        $this->assertSame('2024-11-10', $d['ChristKing']);
    }

    public function testChristKingIsSundayBeforeAdvent1(): void
    {
        foreach ([2024, 2025] as $year) {
            $d  = $this->runEngine($year);
            $ck = new \DateTimeImmutable($d['ChristKing']);
            $a1 = new \DateTimeImmutable($d['Advent1']);
            $this->assertSame(7, (int) $ck->format('N'), "Christ the King must be a Sunday ($year)");
            $this->assertSame($a1->modify('-7 days')->format('Y-m-d'), $d['ChristKing']);
        }
    }
}
