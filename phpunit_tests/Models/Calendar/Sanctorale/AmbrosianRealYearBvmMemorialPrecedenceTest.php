<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianLiturgicalDayRank;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the five real days on which placing the two Pentecost-anchored BVM
 * memorials (`MaryMotherChurch`, `ImmaculateHeart`) into the Ambrosian temporale made them
 * coincide with a comune sanctorale celebration of a saint.
 *
 * The OUTCOMES on these days were already correct before the `is_bvm` split -- a memorial of
 * the Blessed Virgin Mary ranks above a memorial of a saint, so the BVM celebration winning is
 * right. What was missing was the RULE. `rankOf()` returned a flat rank 10 for every comune
 * memorial, so on the three days where the collider is itself a comune MEMORIAL the winner was
 * decided by `uasort`'s stability rather than by anything stated, and the resolver's
 * "suppressed by the higher-ranking X" message was describing a numerical tie. A future data or
 * ordering change could have silently flipped any of them.
 *
 * Each case therefore asserts BOTH halves:
 *
 * 1. the RULE -- `rankOf(BVM) < rankOf(saint)` strictly, before resolution;
 * 2. the OUTCOME -- after `resolve()`, the BVM celebration is active on the date and the saint
 *    is the one suppressed.
 *
 * Assertion (1) is what fails without the split. Assertion (2) is what must NEVER change.
 *
 * Note on the third column below: two of the five colliders (`StBernardineOfSiena`,
 * `StEphremDeacon`) are OPTIONAL memorials, not memorials, so they already sat a full tier
 * below the BVM memorial and were never ambiguous. They are pinned here anyway because the task
 * brief tabulates all five days as the affected set.
 *
 * Deliberately NOT marked `@group slow`: assembling and resolving these three civil years costs
 * well under a second, and this is precisely the kind of lock that should run in
 * `composer test:quick`.
 */
#[CoversClass(AmbrosianLiturgicalDayRank::class)]
final class AmbrosianRealYearBvmMemorialPrecedenceTest extends TestCase
{
    use AmbrosianRealYearHarnessTrait;

    /**
     * The five days from the task brief's table.
     *
     * @return array<string,array{int,string,string,string}> year, date, saint key, BVM key
     */
    public static function bvmCollisionDays(): array
    {
        return [
            '2024-05-20 StBernardineOfSiena vs MaryMotherChurch' => [2024, '2024-05-20', 'StBernardineOfSiena', 'MaryMotherChurch'],
            '2025-06-09 StEphremDeacon vs MaryMotherChurch'      => [2025, '2025-06-09', 'StEphremDeacon', 'MaryMotherChurch'],
            '2025-06-28 StIrenaeus vs ImmaculateHeart'           => [2025, '2025-06-28', 'StIrenaeus', 'ImmaculateHeart'],
            '2026-05-25 StDionysius vs MaryMotherChurch'         => [2026, '2026-05-25', 'StDionysius', 'MaryMotherChurch'],
            '2026-06-13 StAnthonyOfPadua vs ImmaculateHeart'     => [2026, '2026-06-13', 'StAnthonyOfPadua', 'ImmaculateHeart'],
        ];
    }

    /**
     * Builds a PrecedenceContext around an already-assembled collection, mirroring
     * `AmbrosianRealYearPrecedenceTest::buildContextFor()`.
     *
     * @param array<string> $messages
     */
    private function buildContextFor(LiturgicalEventCollection $cal, int $year, array &$messages): PrecedenceContext
    {
        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        return new PrecedenceContext(
            $cal,
            $params,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    #[DataProvider('bvmCollisionDays')]
    public function testBvmMemorialOutranksAndSuppressesCoincidingSaint(int $year, string $date, string $saintKey, string $bvmKey): void
    {
        $cal = $this->assembleAmbrosianYear($year);

        // Premise: the two really do coincide on this date before resolution.
        $saint = $cal->getLiturgicalEvent($saintKey);
        $bvm   = $cal->getLiturgicalEvent($bvmKey);
        self::assertNotNull($saint, "Expected a LiturgicalEvent for $saintKey ($year)");
        self::assertNotNull($bvm, "Expected a LiturgicalEvent for $bvmKey ($year)");
        self::assertSame($date, $saint->date->format('Y-m-d'), "$saintKey must fall on $date");
        self::assertSame($date, $bvm->date->format('Y-m-d'), "$bvmKey must fall on $date");

        // The BVM celebration must be flagged as such all the way through to LiturgicalEvent.
        self::assertTrue($bvm->is_bvm, "$bvmKey must carry is_bvm === true");

        // (1) THE RULE: the BVM celebration outranks the saint strictly, by rank number.
        // This is the assertion that fails without the rank-10 split.
        self::assertLessThan(
            AmbrosianLiturgicalDayRank::rankOf($saint),
            AmbrosianLiturgicalDayRank::rankOf($bvm),
            "$bvmKey must rank strictly above $saintKey on $date, not merely win a tie"
        );

        $messages = [];
        $ctx      = $this->buildContextFor($cal, $year, $messages);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // (2) THE OUTCOME (unchanged, and must stay unchanged): the BVM celebration keeps the
        // date; the saint is the one suppressed.
        self::assertFalse($cal->isSuppressed($bvmKey), "$bvmKey must not be suppressed ($date)");
        $bvmAfter = $cal->getLiturgicalEvent($bvmKey);
        self::assertNotNull($bvmAfter, "$bvmKey must still be active ($date)");
        self::assertSame($date, $bvmAfter->date->format('Y-m-d'), "$bvmKey must not be moved off $date");

        self::assertTrue($cal->isSuppressed($saintKey), "$saintKey must be suppressed by $bvmKey on $date");

        // And the date ends up held by exactly the BVM celebration.
        $occupants = $cal->getCalEventsFromDate(DateTime::fromFormat(
            ( (int) substr($date, 8, 2) ) . '-' . ( (int) substr($date, 5, 2) ) . '-' . $year
        ));
        self::assertSame([$bvmKey], array_keys($occupants), "$date must be held by $bvmKey alone");
    }

    /**
     * The sanctorale half of the `is_bvm` plumbing. The five tabulated days all involve a
     * TEMPORALE BVM celebration, so on their own they would leave
     * `PropriumDeSanctisEvent::$is_bvm -> LiturgicalEvent::$is_bvm` untested.
     *
     * `OurLadyOfTheRosary` (Oct 7) is a comune MEMORIAL flagged in the Ambrosian comune
     * sanctorale by `common: ["Blessed Virgin Mary"]`; it must land in the BVM half of the
     * split (rank 10), while a comune MEMORIAL of a saint from the same source lands in the
     * saint half (rank 11).
     */
    public function testSanctoraleBvmMemorialIsRankedInTheBvmHalfOfTheSplit(): void
    {
        $cal = $this->assembleAmbrosianYear(2025);

        $rosary = $cal->getLiturgicalEvent('OurLadyOfTheRosary');
        self::assertNotNull($rosary);
        self::assertTrue($rosary->is_bvm, 'OurLadyOfTheRosary must carry is_bvm === true from the sanctorale');
        self::assertSame(10, AmbrosianLiturgicalDayRank::rankOf($rosary));

        $saint = $cal->getLiturgicalEvent('StPolycarp');
        self::assertNotNull($saint);
        self::assertNull($saint->is_bvm, 'A saint\'s memorial must not carry is_bvm');
        self::assertSame(11, AmbrosianLiturgicalDayRank::rankOf($saint));
    }

    /**
     * Data invariant: in the Ambrosian comune sanctorale, `is_bvm` and the pre-existing
     * `common: ["Blessed Virgin Mary"]` marker must designate exactly the same rows. `is_bvm`
     * is an ADDITION alongside `common`, not a migration of it, so the two can silently drift
     * apart as rows are added; this pins them together.
     */
    public function testSanctoraleIsBvmMatchesTheBlessedVirginMaryCommonExactly(): void
    {
        $rows = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_SANCTORALE_FILE->path());

        $flagged  = [];
        $byCommon = [];
        foreach ($rows as $row) {
            if (property_exists($row, 'is_bvm') && $row->is_bvm === true) {
                $flagged[] = $row->event_key;
            }
            if (in_array('Blessed Virgin Mary', $row->common, true)) {
                $byCommon[] = $row->event_key;
            }
        }

        sort($flagged);
        sort($byCommon);

        self::assertNotEmpty($byCommon, 'Expected the Ambrosian comune sanctorale to contain BVM rows');
        self::assertSame($byCommon, $flagged);
    }

    /**
     * Data invariant for the temporale half: exactly the two Pentecost-anchored BVM memorials
     * carry `is_bvm`, and nothing else in the Ambrosian proprium de tempore does.
     */
    public function testTemporaleIsBvmFlagsExactlyTheTwoBvmMemorials(): void
    {
        $rows = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());

        $flagged = [];
        foreach ($rows as $row) {
            if (property_exists($row, 'is_bvm') && $row->is_bvm === true) {
                $flagged[] = $row->event_key;
            }
        }

        sort($flagged);
        self::assertSame(['ImmaculateHeart', 'MaryMotherChurch'], $flagged);
    }
}
