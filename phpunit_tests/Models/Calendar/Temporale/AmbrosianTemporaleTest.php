<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
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

    public function testAfterEpiphanySundays2025(): void
    {
        $d = $this->runEngine(2025);
        self::assertSame('2025-01-19', $d['AfterEpiphany2']);
        self::assertSame('2025-01-26', $d['AfterEpiphany3']);
        self::assertSame('2025-02-02', $d['AfterEpiphany4']);
        self::assertSame('2025-02-09', $d['AfterEpiphany5']);
        self::assertSame('2025-02-16', $d['AfterEpiphany6']);
        self::assertSame('2025-02-23', $d['AfterEpiphany7']);
        self::assertSame('2025-03-02', $d['AfterEpiphany8']);
        self::assertArrayNotHasKey('AfterEpiphany9', $d); // Mar 9 is Lent1
    }

    public function testAnchorBlockSeasonsStamped2025(): void
    {
        $events = $this->runEngineEvents(2025);
        self::assertSame(LitSeason::ADVENT, $events['Advent1']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['Christmas']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['Circoncisione']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['Epiphany']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['BaptismLord']->liturgical_season);
        self::assertSame(LitSeason::LENT, $events['Lent1']->liturgical_season);
        self::assertSame(LitSeason::LENT, $events['AshesMonday']->liturgical_season);
        self::assertSame(LitSeason::LENT, $events['SabatoTradSymb']->liturgical_season);
        self::assertSame(LitSeason::EASTER_TRIDUUM, $events['HolyThurs']->liturgical_season);
        self::assertSame(LitSeason::EASTER, $events['Easter']->liturgical_season);
        self::assertSame(LitSeason::EASTER, $events['Pentecost']->liturgical_season);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['DedicationDuomo']->liturgical_season);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['ChristKing']->liturgical_season);
    }

    public function testAfterPentecostSubBlocks2025(): void
    {
        $d = $this->runEngine(2025);

        // (a) dopo Pentecoste: 1st Sunday after Pentecost (Jun 15) .. Sat before Aug 31
        self::assertSame('2025-06-15', $d['AfterPentecost1']);
        self::assertSame('2025-08-24', $d['AfterPentecost11']); // last before the Martyrdom Sunday
        self::assertArrayNotHasKey('AfterPentecost12', $d);

        // (b) dopo il Martirio: Aug 31 .. Sat before Oct 19 (Dedication)
        self::assertSame('2025-08-31', $d['AfterPentecostMartyrdom1']);
        self::assertSame('2025-10-12', $d['AfterPentecostMartyrdom7']);
        self::assertArrayNotHasKey('AfterPentecostMartyrdom8', $d);

        // (c) dopo la Dedicazione: 1st Sunday after Dedication (Oct 26) .. Sat before Advent I;
        // Christ the King (Nov 9) is the terminal anchor, not re-emitted as a numbered Sunday.
        self::assertSame('2025-10-26', $d['AfterPentecostDedication1']);
        self::assertSame('2025-11-02', $d['AfterPentecostDedication2']);
        self::assertArrayNotHasKey('AfterPentecostDedication3', $d); // Nov 9 = ChristKing
        self::assertSame('2025-11-09', $d['ChristKing']);
    }

    public function testAfterPentecostSundaysStamped2025(): void
    {
        $events = $this->runEngineEvents(2025);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['AfterPentecost1']->liturgical_season);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['AfterPentecostMartyrdom1']->liturgical_season);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['AfterPentecostDedication1']->liturgical_season);
    }

    /**
     * `martyrdomAnchor()` (n. 42a) postpones the Martyrdom of St John the
     * Baptist from Aug 29 to Sep 1 whenever Aug 29 falls on a Sunday, so the
     * "dopo il Martirio" block never overlaps a privileged Sunday. 2027 is
     * the nearest civil year in which Aug 29 is a Sunday (verified via
     * `date -d '2027-08-29' +%u` => 7), so the block's first Sunday
     * (`AfterPentecostMartyrdom1`) must be measured from the postponed Sep 1
     * anchor, not from Aug 29 itself: Sep 1, 2027 is a Wednesday, and the
     * first Sunday on/after it is Sep 5, 2027.
     */
    public function testMartyrdomPostponedWhenAug29IsSunday(): void
    {
        $d = $this->runEngine(2027);

        // Sanity: Aug 29, 2027 really is a Sunday -- the edge this test targets.
        self::assertSame('7', ( new \DateTime('2027-08-29') )->format('N'), 'Expected Aug 29, 2027 to be a Sunday');

        self::assertSame('2027-09-05', $d['AfterPentecostMartyrdom1']);
    }

    public function testAfterEpiphanyWeekdaysFillGaps2025(): void
    {
        $d = $this->runEngine(2025);
        // After-Epiphany block: Mon after Baptism (2025-01-13) .. Sat before Lent1 (2025-03-08).
        // Mondays are weekdays; assert a representative weekday exists and Sundays are NOT overwritten.
        self::assertArrayHasKey('AfterEpiphanyWeekday2Monday', $d);   // week 2 Monday = 2025-01-13
        self::assertSame('2025-01-13', $d['AfterEpiphanyWeekday2Monday']);
        self::assertArrayHasKey('AfterEpiphanyWeekday3Saturday', $d); // 2025-01-25
        self::assertSame('2025-01-25', $d['AfterEpiphanyWeekday3Saturday']);
        // The Sunday 2025-01-19 remains AfterEpiphany2 (a weekday fill must never take a Sunday)
        self::assertSame('2025-01-19', $d['AfterEpiphany2']);
    }

    public function testAdventDeExceptatoFerie2025(): void
    {
        $d = $this->runEngine(2025);
        // 2025: Dec 17 is Wednesday, so de Exceptáto = Dec 17..23 (Sundays excluded).
        // Keys are month+day ('md'), not bare day-of-month: the Advent block spans
        // Nov and Dec, and day-of-month alone collides (e.g. both Nov 17 and Dec 17
        // fall inside the block) -- see calculateAdventWeekdays()'s doc comment.
        self::assertArrayHasKey('AdventWeekday1217', $d);
        self::assertSame('2025-12-17', $d['AdventWeekday1217']);
        self::assertArrayHasKey('AdventWeekday1223', $d);
        self::assertSame('2025-12-23', $d['AdventWeekday1223']);
        // Sanity: the Nov 17/Dec 17 collision case does NOT silently drop the
        // November occurrence (both are distinct 'md' keys).
        self::assertArrayHasKey('AdventWeekday1117', $d);
        self::assertSame('2025-11-17', $d['AdventWeekday1117']);
    }

    public function testChristmasFerieSkipAnchors2025(): void
    {
        $d = $this->runEngine(2025);
        self::assertArrayHasKey('ChristmasWeekday1229', $d); // Dec 29 2025 (Mon)
        self::assertSame('2025-12-29', $d['ChristmasWeekday1229']);
        // Jan 1 (Circoncisione) and Jan 6 (Epiphany) stay anchors, never overwritten:
        // the fill is bounded to Dec 31 of the same civil year and never reaches
        // January at all -- see calculateChristmasWeekdays()'s doc comment.
        self::assertArrayNotHasKey('ChristmasWeekday0101', $d);
        self::assertArrayNotHasKey('ChristmasWeekday0106', $d);
    }

    public function testLentenFridaysAreAliturgical2025(): void
    {
        $events  = $this->runEngineEvents(2025);
        $fridays = array_filter(
            $events,
            fn ($e) => $e->liturgical_season === LitSeason::LENT
                && (int) $e->date->format('N') === 5
                && $e->grade === LitGrade::WEEKDAY
        );
        self::assertNotEmpty($fridays);
        foreach ($fridays as $key => $e) {
            self::assertTrue($e->is_aliturgical, "$key should be aliturgical");
        }
        // A Lenten non-Friday ferial is not aliturgical:
        $someThursday = $events['LentWeekday1Thursday'] ?? null;
        self::assertNotNull($someThursday);
        self::assertNull($someThursday->is_aliturgical);
    }

    public function testEasterFerieAfterOctave2025(): void
    {
        $d = $this->runEngine(2025);
        self::assertArrayHasKey('EasterWeekday2Monday', $d);
        self::assertSame('2025-04-28', $d['EasterWeekday2Monday']);
        // Ascension (Thu 2025-05-29) stays its own anchor:
        self::assertSame('2025-05-29', $d['Ascension']);
        // Octave weekdays remain their own anchors, not re-emitted:
        self::assertArrayHasKey('MonOctaveEaster', $d);
    }
}
