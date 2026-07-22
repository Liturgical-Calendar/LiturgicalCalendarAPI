<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\AmbrosianReadings;
use LiturgicalCalendar\Tests\Models\Calendar\Sanctorale\AmbrosianRealYearHarnessTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Plan 7 Task 7: `LiturgicalEventCollection::setAmbrosianYearCyclesAndVigils()` (Part A: A/B/C +
 * I/II `liturgical_year` cycles) and `calculateAmbrosianVigilMass()` /
 * `ambrosianEventCanHaveVigil()` (Part B: first-vespers vigils).
 *
 * Reuses `AmbrosianRealYearHarnessTrait::assembleAmbrosianYear()` (Plan 5 Task 8) to build a real
 * civil-year Ambrosian `LiturgicalEventCollection` out of the temporale engine + comune
 * sanctorale, exactly as `AmbrosianRealYearAssemblyTest` does. The temporale engine already
 * self-stamps `liturgical_season` on every event it produces (`LitSeason::forEventKey()`), so the
 * weekday-cycle guard (`liturgical_season` in {AFTER_EPIPHANY, AFTER_PENTECOST}) is already
 * satisfiable without a separate `stampAmbrosianSeasonOnSanctorale()` pass for the temporale-origin
 * events this test exercises.
 *
 * Both new methods are standalone/unit-testable and not yet wired into `CalendarHandler`'s request
 * path (Task 9 wires them); the Ambrosian `/calendar` route stays a 501 until then.
 */
#[CoversClass(LiturgicalEventCollection::class)]
final class LiturgicalEventCollectionAmbrosianYearCyclesAndVigilsTest extends TestCase
{
    use AmbrosianRealYearHarnessTrait;

    /**
     * `createVigilMassFor()` (reused as-is by `calculateAmbrosianVigilMass()`) reads
     * `$eventForWhichIsVigilMass->readings` to decide whether to copy a `->vigil` sub-schema, so
     * every event needs an initialized `readings` property before `setAmbrosianYearCyclesAndVigils()`
     * runs. In the real (future Task 9) request pipeline every Ambrosian event is given the Task
     * 2/4 empty-readings placeholder ({@see AmbrosianReadings::empty()}) — `CalendarHandler::
     * addAmbrosianSanctoraleToCalendar()` already does this for comune sanctorale rows, but the
     * temporale-origin events assembled by `AmbrosianRealYearHarnessTrait` (which does not go
     * through `CalendarHandler`) do not yet carry one. This test-only helper closes that gap by
     * stamping the same placeholder onto any event that doesn't already have one, exactly
     * mirroring what a full pipeline run would have already done.
     */
    private function assembleAmbrosianYearWithReadingsPlaceholder(int $year): LiturgicalEventCollection
    {
        $cal = $this->assembleAmbrosianYear($year);

        foreach ($cal->getLiturgicalEvents() as $event) {
            if (false === isset($event->readings)) {
                $event->setReadings(AmbrosianReadings::empty());
            }
        }

        return $cal;
    }

    // --- Part A: year cycles -------------------------------------------------------------

    /**
     * `PalmSun` (2025-04-13) is a Sunday that falls before `Advent1` (2025-11-16), so its festive
     * cycle is computed from the "before Advent1" branch: `SUNDAY_CYCLE[(Year - 1) % 3]`.
     */
    public function testSunday2025HasCorrectFestiveCycleBeforeAdvent(): void
    {
        $cal = $this->assembleAmbrosianYearWithReadingsPlaceholder(2025);

        $palmSun = $cal->getLiturgicalEvent('PalmSun');
        self::assertNotNull($palmSun, 'Expected a LiturgicalEvent for temporale key PalmSun');
        self::assertSame(7, (int) $palmSun->date->format('N'), 'Expected PalmSun to fall on a Sunday.');

        $cal->setAmbrosianYearCyclesAndVigils();

        $expectedCycle = LiturgicalEventCollection::SUNDAY_CYCLE[( 2025 - 1 ) % 3];
        self::assertNotNull($palmSun->liturgical_year);
        self::assertStringEndsWith(' ' . $expectedCycle, $palmSun->liturgical_year);
    }

    /**
     * `AfterPentecostWeekday1Monday` (2025-06-09) is a ferial weekday whose `liturgical_season` is
     * `AFTER_PENTECOST` (stamped by the temporale engine itself), so it should receive the I/II
     * weekday cycle: `WEEKDAY_CYCLE[(Year - 1) % 2]`.
     */
    public function testAfterPentecostFerialWeekday2025HasCorrectWeekdayCycle(): void
    {
        $cal = $this->assembleAmbrosianYearWithReadingsPlaceholder(2025);

        $weekday = $cal->getLiturgicalEvent('AfterPentecostWeekday1Monday');
        self::assertNotNull($weekday, 'Expected a LiturgicalEvent for AfterPentecostWeekday1Monday');
        self::assertSame(LitGrade::WEEKDAY, $weekday->grade);
        self::assertSame(LitSeason::AFTER_PENTECOST, $weekday->liturgical_season);
        self::assertSame(1, (int) $weekday->date->format('N'), 'Expected AfterPentecostWeekday1Monday to fall on a Monday.');

        $cal->setAmbrosianYearCyclesAndVigils();

        $expectedCycle = LiturgicalEventCollection::WEEKDAY_CYCLE[( 2025 - 1 ) % 2];
        self::assertNotNull($weekday->liturgical_year);
        self::assertStringEndsWith(' ' . $expectedCycle, $weekday->liturgical_year);
    }

    /**
     * `Christmas`, `Circoncisione`, and `Epiphany` are fixed-date temporale events whose readings
     * (and, by the same rule, their `liturgical_year`) do not follow a cycle — the Ambrosian
     * equivalent of the Roman exclusion list (`Christmas`, `MaryMotherOfGod`, `Christmas2`,
     * `Epiphany`, `AshWednesday`). All three still qualify for the Sunday/high-grade branch
     * (grade > FEAST), so this specifically exercises the key-exclusion, not a grade mismatch.
     */
    public function testFixedDateTemporaleEventsHaveNullLiturgicalYear(): void
    {
        $cal = $this->assembleAmbrosianYearWithReadingsPlaceholder(2025);
        $cal->setAmbrosianYearCyclesAndVigils();

        foreach (['Christmas', 'Circoncisione', 'Epiphany'] as $key) {
            $event = $cal->getLiturgicalEvent($key);
            self::assertNotNull($event, "Expected a LiturgicalEvent for temporale key {$key}");
            self::assertGreaterThan(LitGrade::FEAST->value, $event->grade->value, "Expected {$key} to qualify for the Sunday/high-grade branch.");
            self::assertNull($event->liturgical_year, "Expected {$key} to have a null liturgical_year (fixed-date, no cycle).");
        }
    }

    // --- Part B: first-vespers vigils ------------------------------------------------------

    /**
     * `Assumption` (2025-08-15, a Friday) is a Solemnity (`grade === LitGrade::SOLEMNITY`) that
     * does NOT fall on a Sunday, so it specifically exercises the `grade >= SOLEMNITY` half of
     * `ambrosianEventCanHaveVigil()`'s eligibility test, and falls well outside the Triduum
     * exclusion window.
     */
    public function testAssumption2025GetsVigilMassAndVesperFlags(): void
    {
        $cal = $this->assembleAmbrosianYearWithReadingsPlaceholder(2025);

        $assumption = $cal->getLiturgicalEvent('Assumption');
        self::assertNotNull($assumption, 'Expected a LiturgicalEvent for comune sanctorale key Assumption');
        self::assertSame('2025-08-15', $assumption->date->format('Y-m-d'));
        self::assertSame(LitGrade::SOLEMNITY, $assumption->grade);
        self::assertSame(5, (int) $assumption->date->format('N'), 'Expected 2025-08-15 to be a Friday for this fixture year.');
        self::assertNull($assumption->has_vigil_mass);

        $cal->setAmbrosianYearCyclesAndVigils();

        self::assertTrue($assumption->has_vigil_mass);
        self::assertTrue($assumption->has_vesper_i);
        self::assertTrue($assumption->has_vesper_ii);

        $vigil = $cal->getLiturgicalEvent('Assumption_vigil');
        self::assertNotNull($vigil, 'Expected a LiturgicalEvent for the synthesized Assumption_vigil key');
        self::assertSame('2025-08-14', $vigil->date->format('Y-m-d'));
        self::assertTrue($vigil->is_vigil_mass);
        self::assertSame('Assumption', $vigil->is_vigil_for);
    }

    /**
     * `DedicationDuomo` (2025-10-19, a Sunday) is a temporale anchor with grade
     * `HIGHER_SOLEMNITY`; this specifically exercises the `dateIsSunday()` half of
     * `ambrosianEventCanHaveVigil()`'s eligibility test.
     */
    public function testDedicationDuomo2025GetsVigilMassAndVesperFlags(): void
    {
        $cal = $this->assembleAmbrosianYearWithReadingsPlaceholder(2025);

        $dedication = $cal->getLiturgicalEvent('DedicationDuomo');
        self::assertNotNull($dedication, 'Expected a LiturgicalEvent for temporale key DedicationDuomo');
        self::assertSame('2025-10-19', $dedication->date->format('Y-m-d'));
        self::assertSame(7, (int) $dedication->date->format('N'), 'Expected DedicationDuomo 2025 to fall on a Sunday.');

        $cal->setAmbrosianYearCyclesAndVigils();

        self::assertTrue($dedication->has_vigil_mass);
        self::assertTrue($dedication->has_vesper_i);
        self::assertTrue($dedication->has_vesper_ii);

        $vigil = $cal->getLiturgicalEvent('DedicationDuomo_vigil');
        self::assertNotNull($vigil, 'Expected a LiturgicalEvent for the synthesized DedicationDuomo_vigil key');
        self::assertSame('2025-10-18', $vigil->date->format('Y-m-d'));
        self::assertTrue($vigil->is_vigil_mass);
        self::assertSame('DedicationDuomo', $vigil->is_vigil_for);
    }

    /**
     * A plain ferial weekday outside the Ambrosian "ordinary" seasons (e.g. an Advent weekday)
     * must NOT be eligible for a vigil: `ambrosianEventCanHaveVigil()` requires
     * `dateIsSunday() || grade >= SOLEMNITY`, and a ferial weekday satisfies neither.
     */
    public function testAdventFerialWeekdayDoesNotGetVigilMass(): void
    {
        $cal = $this->assembleAmbrosianYearWithReadingsPlaceholder(2025);

        $weekday = $cal->getLiturgicalEvent('AfterPentecostWeekday1Monday');
        self::assertNotNull($weekday);
        self::assertSame(LitGrade::WEEKDAY, $weekday->grade);

        $cal->setAmbrosianYearCyclesAndVigils();

        self::assertNull($cal->getLiturgicalEvent('AfterPentecostWeekday1Monday_vigil'));
    }
}
