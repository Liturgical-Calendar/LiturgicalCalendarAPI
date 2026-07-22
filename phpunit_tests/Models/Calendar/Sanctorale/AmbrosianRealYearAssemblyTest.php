<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Sanctorale\AmbrosianSanctoraleLoader;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Plan 5 / Task 7: proves that a real Ambrosian civil year can be assembled
 * out of the temporale engine (Plan 3) and the comune sanctorale (Task 6)
 * into a single `LiturgicalEventCollection`.
 *
 * This is the first test in the Ambrosian rollout where real temporale and
 * real sanctorale data coexist in the same collection; earlier tests exercise
 * each engine in isolation.
 */
#[CoversClass(AmbrosianTemporale::class)]
#[CoversClass(AmbrosianSanctoraleLoader::class)]
#[CoversClass(AmbrosianMissalResolver::class)]
final class AmbrosianRealYearAssemblyTest extends TestCase
{
    use AmbrosianRealYearHarnessTrait;

    /**
     * The comune ambrosiano (2024 edition, `propriumdesanctis.json`) has 254
     * rows. The temporale engine produces 65 anchor/synthesized keys for civil
     * year 2025 (verified via `AmbrosianTemporaleTest`/`AmbrosianTemporaleOrdoValidationTest`):
     * 38 major-block anchors (Advent through Christ the King) + 7 after-Epiphany
     * Sundays (`AfterEpiphany2`..`AfterEpiphany8`) + 20 after-Pentecost Sundays
     * across the three n.42 sub-blocks (`AfterPentecost1`..`11`,
     * `AfterPentecostMartyrdom1`..`7`, `AfterPentecostDedication1`..`2`).
     * Three event keys (`Christmas`, `Circoncisione`, `Epiphany`) are listed in
     * both the temporale anchor block and the comune sanctorale source; adding
     * the sanctorale row for one of these keys overwrites the already-added
     * temporale entry rather than creating a duplicate, so the anchor/Sunday
     * distinct key count is 65 + 254 - 3 = 316, not the plain sum of 319.
     *
     * Plan 6 / Task 5 adds the after-Epiphany ferial-weekday fill
     * (`fillFerialWeekdays()`, wired via `calculateAfterEpiphanyWeekdays()`),
     * which for civil year 2025 emits 48 `AfterEpiphanyWeekday{N}{EnglishDay}`
     * keys (Mon after Baptism 2025-01-13 .. Sat before Lent1 2025-03-08, 6
     * ferial days/week x 8 weeks = 48; none of these dates collide with an
     * existing temporale or sanctorale key, so all 48 are additive). New total:
     * 316 + 48 = 364.
     *
     * Plan 6 / Task 6 adds the Advent (incl. de Exceptáto, n. 39) and Christmas
     * ferial-weekday fills (`calculateAdventWeekdays()` / `calculateChristmasWeekdays()`,
     * both via `fillFerialWeekdays()`). Their keys are `AdventWeekday{md}` /
     * `ChristmasWeekday{md}` (month+day, not bare day-of-month -- the Advent
     * block spans Nov and Dec, where day-of-month alone collides, e.g. both
     * Nov 17 and Dec 17 fall inside the block; see `calculateAdventWeekdays()`'s
     * doc comment). For civil year 2025:
     *   - Advent: Mon after Advent I (2025-11-17) .. Sat before Christmas
     *     (2025-12-24), skipping Sundays (Advent2..6 anchors) = 33 ferial days.
     *   - Christmas: Dec 26 .. Dec 31 of the same civil year (bounded there,
     *     not through Baptism -- Baptism/Circoncisione/Epiphany are always
     *     dated in *January of this same $year*, i.e. structurally before
     *     Dec 26 of that year, not after it; see `calculateChristmasWeekdays()`'s
     *     doc comment), skipping the Dec 28 Sunday = 5 ferial days.
     * None of these 38 dates collide with an existing temporale or sanctorale
     * key, so all 38 are additive. New total: 364 + 38 = 402.
     *
     * Plan 6 / Task 7 adds the Lenten ferial-weekday fill (`calculateLentWeekdays()`,
     * via `fillFerialWeekdays(..., lentenAliturgicalFridays: true)`), which flags
     * every Lenten Friday `is_aliturgical = true` (nn. 24-27). Keys are
     * `LentWeekday{N}{EnglishDay}`, run from the day after Lent I (2025-03-10,
     * exclusive of Lent I itself, but AshesMonday already occupies that date as an
     * anchor) through the Saturday before Palm Sunday (2025-04-12, where
     * SabatoTradSymb already occupies that date as an anchor). For civil year 2025
     * this produces 5 full weeks of Mon-Sat ferie (30 slots) minus the 2 anchor
     * dates (AshesMonday, SabatoTradSymb) already in the calendar = 28 ferial days,
     * including the 5 Lenten Fridays (Mar 14, 21, 28, Apr 4, 11). None of these 28
     * dates collide with an existing temporale or sanctorale key, so all 28 are
     * additive. New total: 402 + 28 = 430.
     */
    private const int EXPECTED_TOTAL_2025 = 430;

    public function testAssembleAmbrosianYear2025IncludesTemporaleAnchor(): void
    {
        $cal   = $this->assembleAmbrosianYear(2025);
        $event = $cal->getLiturgicalEvent('Advent1');

        $this->assertNotNull($event, 'Expected a LiturgicalEvent for temporale key Advent1');
        $this->assertSame('2025-11-16', $event->date->format('Y-m-d'));
    }

    public function testAssembleAmbrosianYear2025IncludesComuneSanctoraleEvent(): void
    {
        $cal   = $this->assembleAmbrosianYear(2025);
        $event = $cal->getLiturgicalEvent('StAmbrose');

        $this->assertNotNull($event, 'Expected a LiturgicalEvent for comune sanctorale key StAmbrose');
        $this->assertSame('2025-12-07', $event->date->format('Y-m-d'));
    }

    public function testAssembleAmbrosianYear2025HasPlausibleTotalEventCount(): void
    {
        $cal  = $this->assembleAmbrosianYear(2025);
        $keys = $cal->getLiturgicalEvents()->getKeys();

        $this->assertCount(self::EXPECTED_TOTAL_2025, $keys);
    }

    public function testAssembleAmbrosianYear2025OverlappingKeysKeepTemporaleDate(): void
    {
        // Christmas is both a temporale anchor and a comune sanctorale row (Dec 25
        // in both sources); the collection should still resolve it to a single,
        // correctly dated event rather than erroring or silently losing the key.
        $cal   = $this->assembleAmbrosianYear(2025);
        $event = $cal->getLiturgicalEvent('Christmas');

        $this->assertNotNull($event, 'Expected a single LiturgicalEvent for the overlapping key Christmas');
        $this->assertSame('2025-12-25', $event->date->format('Y-m-d'));
    }

    public function testAssembleAmbrosianYear2024DatesSanctoraleForRequestedYear(): void
    {
        // Re-running for a different civil year must re-date the (year-less)
        // comune sanctorale events for that year, not reuse a stale 2025 date.
        $cal   = $this->assembleAmbrosianYear(2024);
        $event = $cal->getLiturgicalEvent('StAmbrose');

        $this->assertNotNull($event);
        $this->assertSame('2024-12-07', $event->date->format('Y-m-d'));
    }
}
