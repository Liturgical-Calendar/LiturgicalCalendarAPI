<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianLiturgicalDayRank;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One assertion per rank of the Ambrosian 13-rank Tabella dei giorni liturgici,
 * plus the three "of-the-Lord asymmetry" cases that prove a solemnity/feast of
 * the Lord (rank 3) outranks an ordinary numbered Sunday (rank 4), which in
 * turn outranks a saint/BVM solemnity (rank 5) -- even though all three can
 * carry the same LitGrade.
 */
#[CoversClass(AmbrosianLiturgicalDayRank::class)]
final class AmbrosianLiturgicalDayRankTest extends TestCase
{
    use AmbrosianEventFactoryTrait;

    public function testRank1TriduumKey(): void
    {
        $event = $this->makeEvent(['key' => 'HolyThurs', 'date' => '2026-04-02']);

        self::assertSame(1, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank2FixedHigherSolemnityKey(): void
    {
        $event = $this->makeEvent(['key' => 'Christmas', 'date' => '2026-12-25']);

        self::assertSame(2, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank2DedicationDuomo(): void
    {
        $event = $this->makeEvent(['key' => 'DedicationDuomo', 'date' => '2026-10-18']);

        self::assertSame(2, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank2DominicalSundayOfAdvent(): void
    {
        // 2026-11-29 is a Sunday.
        $event = $this->makeEvent([
            'key'       => 'Advent1',
            'date'      => '2026-11-29',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ADVENT,
            'dominical' => true,
        ]);

        self::assertSame(2, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank2SettimanaAutenticaFeria(): void
    {
        $event = $this->makeEvent(['key' => 'MonHolyWeek', 'date' => '2026-03-30']);

        self::assertSame(2, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank2EasterOctaveDay(): void
    {
        $event = $this->makeEvent(['key' => 'MonOctaveEaster', 'date' => '2026-04-06']);

        self::assertSame(2, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank2ChristmasOctaveDay(): void
    {
        $event = $this->makeEvent(['key' => 'ChristmasWeekdayDec27', 'date' => '2026-12-27']);

        self::assertSame(2, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank3AllSouls(): void
    {
        $event = $this->makeEvent(['key' => 'AllSouls', 'date' => '2026-11-02', 'grade' => LitGrade::COMMEMORATION]);

        self::assertSame(3, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank4AfterPentecostSunday(): void
    {
        // 2026-07-19 is a Sunday.
        $event = $this->makeEvent([
            'key'       => 'AfterPentecost3',
            'date'      => '2026-07-19',
            'grade'     => LitGrade::FEAST_LORD,
            'season'    => LitSeason::AFTER_PENTECOST,
            'dominical' => true,
        ]);

        self::assertSame(4, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank5ComuneNonDominicalSolemnity(): void
    {
        $event = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => false,
            'proper'    => false,
        ]);

        self::assertSame(5, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank6ProperSolemnity(): void
    {
        $event = $this->makeEvent([
            'key'    => 'PatronalSolemnity',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::SOLEMNITY,
            'proper' => true,
        ]);

        self::assertSame(6, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank7LentenFeria(): void
    {
        $event = $this->makeEvent([
            'key'    => 'LentWeekday3',
            'date'   => '2026-03-04',
            'grade'  => LitGrade::WEEKDAY,
            'season' => LitSeason::LENT,
        ]);

        self::assertSame(7, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank8ComuneFeast(): void
    {
        $event = $this->makeEvent([
            'key'    => 'SomeSaintFeast',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::FEAST,
            'proper' => false,
        ]);

        self::assertSame(8, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank9ProperFeast(): void
    {
        $event = $this->makeEvent([
            'key'    => 'DiocesanFeast',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::FEAST,
            'proper' => true,
        ]);

        self::assertSame(9, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank10ComuneMemorial(): void
    {
        $event = $this->makeEvent([
            'key'    => 'SomeSaintMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        self::assertSame(10, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank11ProperMemorial(): void
    {
        $event = $this->makeEvent([
            'key'    => 'DiocesanMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => true,
        ]);

        self::assertSame(11, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    /**
     * A comune BVM memorial stays at the Tabella's rank 10, exactly like a comune
     * saint's memorial: the BVM/saint distinction is a WITHIN-rank refinement and must
     * never leak into the Tabella's own numbering.
     */
    public function testComuneBvmMemorialIsStillTabellaRank10(): void
    {
        $event = $this->makeEvent([
            'key'    => 'SomeBvmMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
            'bvm'    => true,
        ]);

        self::assertSame(10, AmbrosianLiturgicalDayRank::rankOf($event));
        self::assertSame(AmbrosianLiturgicalDayRank::TIEBREAK_BVM_MEMORIAL, AmbrosianLiturgicalDayRank::tiebreakOf($event));
    }

    /**
     * The rule this task exists to state: a memorial of the Blessed Virgin Mary takes
     * precedence over a memorial of a saint. Praenotanda n. 53's "medesimo grado"
     * coexistence rule governs saints among themselves; it does not put a BVM memorial
     * on a level with a saint's.
     *
     * Both are Tabella rank 10, so the ordering is NOT expressible through `rankOf()`
     * alone -- it lives in the composite key. Assert it the way the resolver consumes
     * it: through `compare()`, and through `precedenceKeyOf()`'s lexicographic order.
     * Before this rule was encoded, `compare()` did not exist and the two events were
     * indistinguishable, so the correct winner fell out of `uasort` stability -- see
     * `AmbrosianRealYearBvmMemorialPrecedenceTest` for the assembled-calendar lock on
     * the real days where that mattered.
     */
    public function testComuneBvmMemorialTakesPrecedenceOverComuneSaintMemorial(): void
    {
        $bvmMemorial = $this->makeEvent([
            'key'    => 'ImmaculateHeart',
            'date'   => '2025-06-28',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
            'bvm'    => true,
        ]);

        $saintMemorial = $this->makeEvent([
            'key'    => 'StIrenaeus',
            'date'   => '2025-06-28',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        // Same Tabella rank -- the premise of the whole tiebreak design.
        self::assertSame(
            AmbrosianLiturgicalDayRank::rankOf($saintMemorial),
            AmbrosianLiturgicalDayRank::rankOf($bvmMemorial),
            'Both must remain at the same Tabella rank; the ordering belongs in the tiebreak'
        );

        self::assertLessThan(
            0,
            AmbrosianLiturgicalDayRank::compare($bvmMemorial, $saintMemorial),
            'A comune memorial of the BVM must take precedence over a comune memorial of a saint'
        );
        self::assertGreaterThan(
            0,
            AmbrosianLiturgicalDayRank::compare($saintMemorial, $bvmMemorial),
            'compare() must be antisymmetric'
        );
        self::assertLessThan(
            AmbrosianLiturgicalDayRank::precedenceKeyOf($saintMemorial),
            AmbrosianLiturgicalDayRank::precedenceKeyOf($bvmMemorial)
        );
    }

    /**
     * `is_bvm` is an ADDITIVE, optional flag: absent (null) must behave exactly as
     * before -- the baseline tiebreak -- so that every Roman-rite event and every
     * unflagged Ambrosian event keeps its previous ordering.
     */
    public function testComuneMemorialWithoutBvmFlagKeepsTheBaselineTiebreak(): void
    {
        $unflagged = $this->makeEvent([
            'key'   => 'SomeUnflaggedMemorial',
            'date'  => '2026-07-20',
            'grade' => LitGrade::MEMORIAL,
        ]);

        self::assertNull($unflagged->is_bvm);
        self::assertSame(10, AmbrosianLiturgicalDayRank::rankOf($unflagged));
        self::assertSame(AmbrosianLiturgicalDayRank::TIEBREAK_DEFAULT, AmbrosianLiturgicalDayRank::tiebreakOf($unflagged));
    }

    /**
     * Every rank other than the comune memorial tier carries the baseline tiebreak, so
     * `compare()` degenerates to a plain rank comparison there -- i.e. the composite
     * key changed nothing outside rank 10.
     */
    public function testTiebreakIsBaselineOutsideTheComuneMemorialTier(): void
    {
        $cases = [
            'comune solemnity'  => ['grade' => LitGrade::SOLEMNITY, 'proper' => false],
            'comune feast'      => ['grade' => LitGrade::FEAST, 'proper' => false],
            'optional memorial' => ['grade' => LitGrade::MEMORIAL_OPT, 'proper' => false, 'bvm' => true],
            'plain weekday'     => ['grade' => LitGrade::WEEKDAY, 'season' => LitSeason::ORDINARY_TIME],
        ];

        foreach ($cases as $label => $opts) {
            $event = $this->makeEvent($opts + ['key' => 'SomeEvent', 'date' => '2026-07-20']);
            self::assertSame(
                AmbrosianLiturgicalDayRank::TIEBREAK_DEFAULT,
                AmbrosianLiturgicalDayRank::tiebreakOf($event),
                "Expected the baseline tiebreak for: $label"
            );
        }
    }

    /**
     * The tiebreak is deliberately confined to the COMUNE memorial tier: the PROPER
     * memorial tier (rank 11) has no BVM refinement yet, because no proper Ambrosian
     * data exists to test one against. When it lands it is a local addition in
     * `tiebreakOf()`, not another renumbering.
     */
    public function testProperMemorialTierHasNoBvmTiebreakYet(): void
    {
        $properSaint = $this->makeEvent([
            'key'    => 'DiocesanMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => true,
        ]);

        $properBvm = $this->makeEvent([
            'key'    => 'DiocesanBvmMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => true,
            'bvm'    => true,
        ]);

        self::assertSame(
            AmbrosianLiturgicalDayRank::precedenceKeyOf($properSaint),
            AmbrosianLiturgicalDayRank::precedenceKeyOf($properBvm)
        );
        self::assertSame(0, AmbrosianLiturgicalDayRank::compare($properSaint, $properBvm));
    }

    public function testRank12OptionalMemorial(): void
    {
        $event = $this->makeEvent([
            'key'   => 'SomeOptionalMemorial',
            'date'  => '2026-07-20',
            'grade' => LitGrade::MEMORIAL_OPT,
        ]);

        self::assertSame(12, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testRank13PlainWeekday(): void
    {
        $event = $this->makeEvent([
            'key'    => 'OrdWeekday17_3',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::WEEKDAY,
            'season' => LitSeason::ORDINARY_TIME,
        ]);

        self::assertSame(13, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    /**
     * The "of-the-Lord asymmetry": a solemnity/feast of the Lord (dominical, comune)
     * outranks (rank 3) an ordinary numbered Sunday (rank 4), which in turn outranks
     * a saint/BVM solemnity (rank 5) -- even though all three may carry the same
     * grade-7 HIGHER_SOLEMNITY value. The classifier distinguishes them purely via
     * is_dominical + liturgical_season, never via key-name heuristics.
     */
    public function testOfTheLordAsymmetryDominicalComuneSolemnityIsRank3(): void
    {
        $event = $this->makeEvent([
            'key'       => 'SomeLordSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => true,
            'proper'    => false,
        ]);

        self::assertSame(3, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testOfTheLordAsymmetryAfterPentecostSundayIsRank4(): void
    {
        $event = $this->makeEvent([
            'key'       => 'AfterPentecost5',
            'date'      => '2026-07-19',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::AFTER_PENTECOST,
            'dominical' => true,
        ]);

        self::assertSame(4, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    /**
     * The unit-level fixture deferred from the Task 4 review of the "fix round 1" bug.
     *
     * A NON-Sunday Solemnity of the Lord that nonetheless falls inside `AFTER_PENTECOST`
     * (the real shape of `CorpusChristi`, always a Thursday, and `SacredHeart`, always a
     * Friday) must be rank 3. Rank 3's exclusion clause is conditioned on
     * `isDominicalSunday() && season ∈ RANK_4_SUNDAY_SEASONS`; an earlier version excluded
     * by season ALONE, which dropped these events to the rank-13 floor.
     *
     * The two existing "of-the-Lord asymmetry" fixtures both use `ORDINARY_TIME`, so
     * neither exercises this branch; before this test the only coverage was indirect, via
     * `AmbrosianRealYearPrecedenceTest`'s assembled-calendar CorpusChristi/SacredHeart
     * gates. 2025-06-19 is a Thursday.
     */
    public function testNonSundayDominicalSolemnityAfterPentecostIsRank3(): void
    {
        $event = $this->makeEvent([
            'key'       => 'CorpusChristi',
            'date'      => '2025-06-19',
            'grade'     => LitGrade::SOLEMNITY,
            'season'    => LitSeason::AFTER_PENTECOST,
            'dominical' => true,
            'proper'    => false,
        ]);

        // Guard the fixture's own premise: a Sunday here would legitimately be rank 4.
        self::assertNotSame(7, (int) $event->date->format('N'), 'Fixture date must not be a Sunday');

        self::assertSame(3, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testOfTheLordAsymmetryNonDominicalComuneSolemnityIsRank5(): void
    {
        $event = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => false,
            'proper'    => false,
        ]);

        self::assertSame(5, AmbrosianLiturgicalDayRank::rankOf($event));
    }

    public function testIsSolemnityRankCoversRanksOneThroughSix(): void
    {
        self::assertTrue(AmbrosianLiturgicalDayRank::isSolemnityRank(1));
        self::assertTrue(AmbrosianLiturgicalDayRank::isSolemnityRank(6));
        self::assertFalse(AmbrosianLiturgicalDayRank::isSolemnityRank(7));
    }

    /**
     * The n.56 "day free of ranks 1-10" boundary. Occupancy is a question about the
     * Tabella tier, so it reads `rankOf()` only -- a within-rank tiebreak can never
     * make an occupied day free.
     */
    public function testIsFreeOfOccupiedRanks(): void
    {
        self::assertFalse(AmbrosianLiturgicalDayRank::isFreeOfOccupiedRanks(10));
        self::assertTrue(AmbrosianLiturgicalDayRank::isFreeOfOccupiedRanks(11));
        self::assertTrue(AmbrosianLiturgicalDayRank::isFreeOfOccupiedRanks(13));
    }
}
