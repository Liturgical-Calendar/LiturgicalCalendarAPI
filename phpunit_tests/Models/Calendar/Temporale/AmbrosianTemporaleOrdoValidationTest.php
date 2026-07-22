<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ordo-validation acceptance gate for the Ambrosian temporale engine (spec §7.4).
 *
 * No printed ordo exists for the 2024-edition Ambrosian rite. The
 * chiesadimilano.it daily-liturgy widget covers only the two liturgical years
 * that overlap civil years 2024-2026: Year C (2024-2025) and Year A
 * (2025-2026). This test pins the engine's anchor-block output for the three
 * composing civil years (2024, 2025, 2026) against dates spot-checked on that
 * site (see docs/superpowers/plans/2026-07-20-ambrosian-03-temporale-engine.md,
 * "Ordo-validation findings").
 *
 * Marked @group slow per project convention for full-engine acceptance runs;
 * excluded from `composer test:quick`.
 */
#[CoversClass(AmbrosianTemporale::class)]
#[Group('slow')]
final class AmbrosianTemporaleOrdoValidationTest extends TestCase
{
    use AmbrosianTemporaleHarnessTrait;

    /**
     * Expected anchor dates for the three civil years spanning Year C (2024-25)
     * and Year A (2025-26), verified against chiesadimilano.it spot-checks
     * (2024/2025) and re-derived with the same engine rules (2026, since the
     * 2024-edition widget does not yet cover Advent 2026).
     *
     * @var array<int, array<string,string>>
     */
    private const EXPECTED = [
        2024 => [
            'Advent1'         => '2024-11-17',
            'Advent6'         => '2024-12-22',
            'DedicationDuomo' => '2024-10-20',
            'ChristKing'      => '2024-11-10',
            'Lent1'           => '2024-02-18',
            'Ascension'       => '2024-05-09',
            'Pentecost'       => '2024-05-19',
        ],
        2025 => [
            'Advent1'         => '2025-11-16',
            'Advent6'         => '2025-12-21',
            'DedicationDuomo' => '2025-10-19',
            'ChristKing'      => '2025-11-09',
            'Lent1'           => '2025-03-09',
            'Ascension'       => '2025-05-29',
            'Pentecost'       => '2025-06-08',
        ],
        // REGRESSION PIN (engine-derived, not an external ordo cross-check):
        // chiesadimilano.it does not cover Advent 2026, so these values were
        // computed from the engine's own rules rather than spot-checked
        // against a printed/published ordo like the 2024/2025 rows above.
        // This guards against accidental drift, not against a wrong rule.
        2026 => [
            'Advent1'         => '2026-11-15',
            'DedicationDuomo' => '2026-10-18',
            'ChristKing'      => '2026-11-08',
        ],
    ];

    /**
     * First Sunday of each after-Pentecost sub-block (n. 42a-c) for the three
     * composing civil years. These are anchor-derived (1st Sunday after
     * Pentecost / after the Martyrdom / after the Dedication) and therefore
     * stable regression pins, unlike the last-in-block ordinal counts (which
     * vary year to year with the Pentecost/Dedication/Advent-I gap and are
     * covered by the engine-level sub-block tests in AmbrosianTemporaleTest).
     *
     * REGRESSION PIN for all three years: chiesadimilano.it's daily-liturgy
     * widget does not expose a "Nth Sunday after Pentecost/Martyrdom/Dedication"
     * ordo listing, so these are computed from the engine's own rules
     * (Pentecost = Easter+49d, Martyrdom = Aug 29 postponed to Sep 1 on a
     * Sunday, Dedication = 3rd Sunday of October) rather than spot-checked
     * against a printed/published ordo.
     *
     * @var array<int, array<string,string>>
     */
    private const EXPECTED_SUB_BLOCK_FIRST_SUNDAYS = [
        2024 => [
            'AfterPentecost1'           => '2024-05-26',
            'AfterPentecostMartyrdom1'  => '2024-09-01',
            'AfterPentecostDedication1' => '2024-10-27',
        ],
        2025 => [
            'AfterPentecost1'           => '2025-06-15',
            'AfterPentecostMartyrdom1'  => '2025-08-31',
            'AfterPentecostDedication1' => '2025-10-26',
        ],
        2026 => [
            'AfterPentecost1'           => '2026-05-31',
            'AfterPentecostMartyrdom1'  => '2026-08-30',
            'AfterPentecostDedication1' => '2026-10-25',
        ],
    ];

    public function testAnchorsAcrossValidatedYears(): void
    {
        foreach (self::EXPECTED as $year => $expected) {
            $d = $this->runEngine($year);
            foreach ($expected as $key => $date) {
                $this->assertSame($date, $d[$key], "$key ($year)");
            }
        }
    }

    public function testAfterPentecostSubBlockFirstSundaysAcrossValidatedYears(): void
    {
        foreach (self::EXPECTED_SUB_BLOCK_FIRST_SUNDAYS as $year => $expected) {
            $d = $this->runEngine($year);
            foreach ($expected as $key => $date) {
                $this->assertSame($date, $d[$key], "$key ($year)");
            }
        }
    }

    /**
     * Guards against a silently empty after-Epiphany or after-Pentecost block:
     * asserts the engine emits at least one numbered Sunday of each family for
     * 2025 (the block sizes themselves vary year to year with the date of
     * Easter/Pentecost/Dedication and are covered by the engine-level
     * sub-block tests in AmbrosianTemporaleTest, so only a non-zero count is
     * asserted here).
     */
    public function testAfterEpiphanyAndAfterPentecostBlocksAreNonEmpty2025(): void
    {
        $d = $this->runEngine(2025);

        $afterEpiphanyCount  = 0;
        $afterPentecostCount = 0;
        foreach (array_keys($d) as $key) {
            if (preg_match('/^AfterEpiphany\d+$/', $key)) {
                $afterEpiphanyCount++;
            }
            if (preg_match('/^AfterPentecost(Martyrdom|Dedication)?\d+$/', $key)) {
                $afterPentecostCount++;
            }
        }

        self::assertGreaterThan(0, $afterEpiphanyCount, 'Expected at least one AfterEpiphanyN Sunday for 2025');
        self::assertGreaterThan(0, $afterPentecostCount, 'Expected at least one AfterPentecost(Martyrdom|Dedication)N Sunday for 2025');
    }
}
