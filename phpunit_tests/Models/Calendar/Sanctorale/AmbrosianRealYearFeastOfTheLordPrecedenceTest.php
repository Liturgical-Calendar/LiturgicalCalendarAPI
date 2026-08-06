<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianLiturgicalDayRank;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression lock for a defect Task 7's whole-branch verification found but that no test
 * pinned: five PRE-EXISTING comune sanctorale Feasts of the Lord --
 * `PresentationOfTheLord` (Feb 2), `VisitationBVM` (May 31), `Transfiguration` (Aug 6),
 * `ExaltationHolyCross` (Sep 14), `DedicationLateran` (Nov 9) -- were silently losing to a
 * generic `AfterEpiphanyWeekdayN...`/`AfterPentecostWeekdayN...` ferial filler on their own
 * Missal-fixed dates whenever those dates fell within the Ambrosian `AFTER_EPIPHANY` or
 * `AFTER_PENTECOST` season window on a weekday. This was already true at the branch's merge
 * base (`ae55a6bb`), not something this branch introduced.
 *
 * The bug and its fix are the exact same shape as
 * `AmbrosianRealYearPrecedenceTest::testCorpusChristiLandsOnMissalThursdayUnimpeded()` /
 * `::testSacredHeartLandsOnMissalFridayUnimpeded()`: `AmbrosianLiturgicalDayRank::rankOf()`'s
 * rank-3 clause originally excluded any event whose `liturgical_season` fell in
 * `AFTER_EPIPHANY`/`AFTER_PENTECOST`, by season alone, regardless of whether the event
 * actually qualified for rank 4 (a real Sunday). All five events above are comune,
 * `is_dominical === true`, grade `FEAST_LORD` -- `RANK_3_GRADES` already included
 * `FEAST_LORD` at the merge base -- so on a non-Sunday date inside that season window they
 * matched no rank clause at all and fell to the rank-13 floor, tying (and frequently losing,
 * by insertion order) against a same-rank ferial filler. Task 4's fix (commit `57bbbf0f`)
 * conditioned the exclusion on `isDominicalSunday($e)` rather than season alone. That fix
 * was written and tested against `CorpusChristi`/`SacredHeart` only; this test proves its
 * correctness is general by pinning the five OTHER pre-existing events it also repairs.
 *
 * Until now nothing asserted these five feasts survive onto their dates in the RESOLVED
 * calendar -- `AmbrosianSanctoraleDataTest` only pins `grade`/`is_dominical` in the source
 * file, which says nothing about precedence outcomes. A future edit to the rank-3 clause
 * (or to `RANK_3_GRADES`, or to `isDominicalSunday()`) could silently reintroduce the bug
 * and every existing test would stay green.
 *
 * ## Why 2024, and why these five dates only as weekdays
 *
 * Each of these five celebrations has a Gregorian FIXED date but a floating liturgical
 * SEASON classification (Easter-relative), so whether a given civil year's occurrence is a
 * plain weekday or a Sunday varies year to year. When one of them lands on a Sunday within
 * `AFTER_EPIPHANY`/`AFTER_PENTECOST`, it competes for RANK 4 (not rank 3) against a numbered
 * temporale Sunday sharing that date (e.g. `PresentationOfTheLord` vs `AfterEpiphany4` on
 * 2025-02-02; `ExaltationHolyCross` vs `AfterPentecostMartyrdom3` on 2025-09-14;
 * `Transfiguration` vs a numbered Sunday on 2028-08-06) -- a SEPARATE, pre-existing
 * rank-4-vs-rank-4 tie that Task 4's fix never touches and that this test deliberately does
 * NOT exercise or assert on. That collision is a distinct issue, now escalated to the
 * project owner; it must not be "fixed" here, and this test must not encode an assumption
 * about how it is eventually resolved.
 *
 * 2024 was chosen because all five dates fall on a weekday that year (Fri, Fri, Tue, Sat,
 * Sat respectively) -- confirmed directly against a PHP `DateTime` weekday computation for
 * 2024-02-02, 2024-05-31, 2024-08-06, 2024-09-14, 2024-11-09 -- and because 2024 is already
 * an established year in this test suite (`AmbrosianRealYearPrecedenceTest`'s Corpus
 * Christi/Sacred Heart regression cases both use it). None of the five dates coincides with
 * any of the five Pentecost-anchored celebrations' own Missal dates in 2024 (`Trinity`
 * 05-26, `CorpusChristi` 05-30, `SacredHeart` 06-07, `ImmaculateHeart` 06-08,
 * `MaryMotherChurch` 05-20), so the collisions asserted below are exclusively "feast vs.
 * generic ferial filler," the exact shape of the bug being pinned.
 *
 * Deliberately NOT marked `#[Group('slow')]`: this assembles and resolves a single civil
 * year for five data-provider rows, well under a second, mirroring
 * `AmbrosianRealYearBvmMemorialPrecedenceTest`'s reasoning for why a lock like this belongs
 * in `composer test:quick` rather than being excluded from it.
 *
 * ## Why this test calls `stampAmbrosianSeasonOnSanctorale()` itself
 *
 * `AmbrosianRealYearHarnessTrait::assembleAmbrosianYear()` builds the temporale anchor block
 * and the comune sanctorale, but does NOT call
 * `LiturgicalEventCollection::stampAmbrosianSeasonOnSanctorale()` -- the pass the real
 * `CalendarHandler::calculateAmbrosianCalendar()` orchestrator runs (call-order step 4, see
 * that method's docblock) to copy `liturgical_season` from a co-located temporale event onto
 * a sanctorale event before resolution. Without it, every comune sanctorale event's
 * `liturgical_season` stays `null` in the harness-assembled collection, and the whole bug
 * this test targets is unreachable: `rankOf()`'s rank-3 clause tests
 * `in_array($e->liturgical_season, RANK_4_SUNDAY_SEASONS, true)`, which is trivially `false`
 * for a `null` season either way, so the pre-fix and post-fix predicates become
 * indistinguishable and a red/green check against this harness alone would pass unchanged in
 * BOTH states -- confirmed directly: reverting `rankOf()`'s rank-3 clause to its pre-fix,
 * season-alone exclusion left this test green until the explicit
 * `stampAmbrosianSeasonOnSanctorale()` call below was added, at which point it went red as
 * expected (see the Task 7 report's "Fix round 1" section for the full red/green transcript).
 * Each test method therefore calls it explicitly, immediately after assembly, exactly where
 * the real orchestrator calls it relative to `resolveAmbrosianPrecedence()` -- rather than
 * changing the shared trait (which other already-green tests in this directory depend on
 * exactly as it stands today, and which is out of scope for this task).
 */
#[CoversClass(AmbrosianLiturgicalDayRank::class)]
final class AmbrosianRealYearFeastOfTheLordPrecedenceTest extends TestCase
{
    use AmbrosianRealYearHarnessTrait;

    /**
     * The five pre-existing comune Feasts of the Lord repaired by Task 4's rank-3 fix,
     * each paired with the year civil-calendar date and the generic ferial filler
     * event_key it displaces in 2024 -- see the class docblock for why 2024 and why only
     * these weekday occurrences are asserted.
     *
     * @return array<string,array{0:int,1:string,2:string,3:string}> year, date, feast key, displaced ferial filler key
     */
    public static function comuneFeastOfTheLordWeekdays(): array
    {
        return [
            '2024-02-02 PresentationOfTheLord (Friday, AFTER_EPIPHANY)'  => [2024, '2024-02-02', 'PresentationOfTheLord', 'AfterEpiphanyWeekday5Friday'],
            '2024-05-31 VisitationBVM (Friday, AFTER_PENTECOST)'         => [2024, '2024-05-31', 'VisitationBVM', 'AfterPentecostWeekday2Friday'],
            '2024-08-06 Transfiguration (Tuesday, AFTER_PENTECOST)'      => [2024, '2024-08-06', 'Transfiguration', 'AfterPentecostWeekday12Tuesday'],
            '2024-09-14 ExaltationHolyCross (Saturday, AFTER_PENTECOST)' => [2024, '2024-09-14', 'ExaltationHolyCross', 'AfterPentecostMartyrdomWeekday2Saturday'],
            '2024-11-09 DedicationLateran (Saturday, AFTER_PENTECOST)'   => [2024, '2024-11-09', 'DedicationLateran', 'AfterPentecostDedicationWeekday3Saturday'],
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

    #[DataProvider('comuneFeastOfTheLordWeekdays')]
    public function testComuneFeastOfTheLordSurvivesItsMissalFixedWeekday(int $year, string $date, string $feastKey, string $ferialFillerKey): void
    {
        $cal = $this->assembleAmbrosianYear($year);

        // The real orchestrator's call-order step 4 (CalendarHandler::calculateAmbrosianCalendar()),
        // which the test-only harness does not run -- see the class docblock's "Why this test
        // calls stampAmbrosianSeasonOnSanctorale() itself" section. Without this, liturgical_season
        // stays null on every sanctorale event and the bug this test targets is unreachable.
        $cal->stampAmbrosianSeasonOnSanctorale();

        // Premise: before resolution, the feast really is a comune, dominical FEAST_LORD
        // whose date coincides with the generic ferial filler -- the exact shape of the bug.
        $feast  = $cal->getLiturgicalEvent($feastKey);
        $filler = $cal->getLiturgicalEvent($ferialFillerKey);
        self::assertNotNull($feast, "Expected a LiturgicalEvent for $feastKey ($year)");
        self::assertNotNull($filler, "Expected a LiturgicalEvent for $ferialFillerKey ($year)");
        self::assertSame($date, $feast->date->format('Y-m-d'), "$feastKey ($year)");
        self::assertSame($date, $filler->date->format('Y-m-d'), "Expected $ferialFillerKey to coincide with $feastKey on $date ($year)");
        self::assertTrue($feast->is_dominical, "$feastKey must be is_dominical === true");
        self::assertContains(
            $feast->liturgical_season,
            [\LiturgicalCalendar\Api\Enum\LitSeason::AFTER_EPIPHANY, \LiturgicalCalendar\Api\Enum\LitSeason::AFTER_PENTECOST],
            "$feastKey must be stamped into the season window the rank-3 bug excluded by season alone ($year)"
        );
        self::assertSame(3, AmbrosianLiturgicalDayRank::rankOf($feast), "$feastKey must classify at rank 3, not fall through to the floor");

        $messages = [];
        $ctx      = $this->buildContextFor($cal, $year, $messages);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The feast wins outright: stays active, on its Missal-fixed date, never suppressed.
        self::assertFalse($cal->isSuppressed($feastKey), "$feastKey must not be suppressed ($date)");
        $feastAfter = $cal->getLiturgicalEvent($feastKey);
        self::assertNotNull($feastAfter, "$feastKey must still be active ($date)");
        self::assertSame($date, $feastAfter->date->format('Y-m-d'), "$feastKey must not be moved off $date");

        // The date ends up held by exactly the feast -- the ferial filler never independently
        // occupies a date once a real celebration is placed there.
        $occupants = $cal->getCalEventsFromDate(DateTime::fromFormat($feastAfter->date->format('j-n-Y')));
        self::assertSame([$feastKey], array_keys($occupants), "$date must be held by $feastKey alone");
    }
}
