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

    public function testIsFreeOfRanksOneThroughTen(): void
    {
        self::assertFalse(AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen(10));
        self::assertTrue(AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen(11));
        self::assertTrue(AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen(13));
    }
}
