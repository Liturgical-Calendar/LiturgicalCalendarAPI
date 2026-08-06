<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

/**
 * Classifies a `LiturgicalEvent` against the Ambrosian *Tabella dei giorni
 * liturgici* (the Ambrosian analog of the Roman `LitGrade` precedence ladder,
 * cf. `LitGrade`'s own Mysterii Paschalis docblock). Lower rank number means
 * higher precedence (rank 1 = Easter Triduum, the floor = plain weekday).
 *
 * Implemented as an ORDERED LIST OF PREDICATES evaluated top to bottom inside
 * {@see self::rankOf()}, first match wins, one `if` block per rank in
 * ascending numeric order -- so the code can be read side by side with the
 * Tabella and inspected rank by rank, exactly mirroring the design already
 * used by `LitGrade`'s own docblock table.
 *
 * ## The rank ladder, and where it refines the Tabella
 *
 * The Tabella itself has 13 ranks; this classifier returns 14, because the
 * Tabella's rank 10 ("comune memorial") is split in two -- see "The `is_bvm`
 * split" below. Everything above the split keeps the Tabella's own numbering;
 * everything below it is shifted down by one:
 *
 * | Tabella | here | day                                                          |
 * |---------|------|--------------------------------------------------------------|
 * |       1 |    1 | Easter Triduum                                               |
 * |       2 |    2 | fixed Solemnities of the Lord; Sundays of Advent/Lent/Easter; |
 * |         |      | settimana autentica ferie; Easter and Christmas octaves       |
 * |       3 |    3 | comune dominical solemnity / Feast of the Lord; All Souls     |
 * |       4 |    4 | Sundays after Epiphany / after Pentecost                      |
 * |       5 |    5 | comune, non-dominical BVM/saint solemnity                     |
 * |       6 |    6 | proper solemnity                                              |
 * |       7 |    7 | ferie of Lent                                                 |
 * |       8 |    8 | comune feast                                                  |
 * |       9 |    9 | proper feast                                                  |
 * |      10 |   10 | comune memorial OF THE BLESSED VIRGIN MARY   <-- split        |
 * |      10 |   11 | comune memorial of a saint                   <-- split        |
 * |      11 |   12 | proper memorial                                              |
 * |      12 |   13 | optional memorial                                            |
 * |      13 |   14 | plain ferie/Saturday (the floor)                             |
 *
 * Because the split lives at Tabella rank 10, every consumer that compares
 * against a rank number ABOVE 10 is unaffected ({@see self::SOLEMNITY_RANK_CEILING},
 * still 6). The one boundary that had to move is
 * {@see self::OCCUPIED_RANK_CEILING}: Praenotanda n. 56's "first day free of
 * ranks 1-10" means "free of everything through the comune memorial tier",
 * which is now internal rank 11. The n. 56 wording in
 * `AmbrosianPrecedenceResolver`'s user-facing messages deliberately keeps
 * citing the Tabella's own "1-10", which remains exactly true: a day held by
 * either half of the split is held at Tabella rank 10 either way.
 *
 * ## The `is_bvm` split (Tabella rank 10)
 *
 * A memorial of the Blessed Virgin Mary ranks above a memorial of a saint.
 * Praenotanda n. 53's "medesimo grado" coexistence rule governs saints among
 * themselves; it does not put a BVM memorial on a level with a saint's. Before
 * this distinction was encoded, `rankOf()` returned a flat 10 for both, so on
 * the days where an Ambrosian BVM memorial coincides with a saint's memorial
 * (`MaryMotherChurch` / `ImmaculateHeart` against the comune sanctorale) the
 * correct winner emerged only from `uasort`'s stability, not from any stated
 * rule -- and the resolver's "suppressed by the higher-ranking X" message was
 * describing a tie. The classifier now reads `$e->is_bvm`, an additive
 * source-carried flag on `LiturgicalEvent` set from the Ambrosian proprium de
 * tempore and the Ambrosian comune sanctorale (whose BVM rows are marked by
 * `common: ["Blessed Virgin Mary"]`). `null`/absent -- the default, and the
 * value for ALL Roman-rite data -- means "not a BVM celebration", so the
 * saint's tier is the fallback exactly as before.
 *
 * Only the COMUNE memorial tier is split. The proper memorial tier is left
 * whole: no proper Ambrosian data exists yet (Plan 5+), so splitting it would
 * be an untested guess rather than an encoded rule.
 *
 * ## The `isProper` (comune vs. proper) distinction
 *
 * The Tabella splits several ranks into a "comune" (taken from the General/
 * universal Ambrosian calendar) tier and a "proper" (particular to a diocese,
 * parish, or religious family) tier, with proper always ranking one notch
 * below its comune counterpart at the same grade (ranks 5/6, 8/9, 10/11).
 * `LiturgicalEvent` has no origin/proper attribute prior to this class, so an
 * additive `public ?bool $is_proper = null` was added to it, mirroring
 * exactly how `is_dominical` was added in an earlier task: declared beside
 * the sibling optional flags, emitted in `jsonSerialize()` only when
 * non-null, and NOT threaded through `fromObject()`/`fromArray()` (those
 * sibling optional flags are not source-carried either -- they are set
 * programmatically by resolvers, not read from JSON calendar definitions).
 * `null` defaults to comune. This classifier never infers proper-ness from
 * event_key naming; it only ever reads `$e->is_proper`.
 *
 * ## The "dominical Sunday" check
 *
 * `$e->is_dominical` means "of the Lord" (Christological), not "falls on a
 * Sunday" by itself -- see the docblock on `LiturgicalEvent::$is_dominical`.
 * Ranks 2 and 4 both need to recognize an actual *Sunday* that is also "of
 * the Lord's day": {@see self::isDominicalSunday()} requires BOTH
 * `is_dominical === true` AND the date's ISO-8601 weekday being 7 (Sunday),
 * per the task brief's explicit guidance. Rank 3, by contrast, uses the bare
 * `is_dominical === true` flag without a weekday check, because it also
 * needs to match non-Sunday Solemnities/Feasts of the Lord (e.g. Ascension on
 * a Thursday) that are already fully absorbed by rank 2's fixed key list --
 * so in practice the only additional dominical/grade-matching events rank 3
 * needs to catch are Sunday-based Feasts/Solemnities of the Lord that are
 * NOT part of rank 2's Advent/Lent/Easter or rank 4's after-Epiphany/after-
 * Pentecost season groups (e.g. Trinity Sunday, Corpus Christi, Holy Family).
 *
 * ## Rank 3 vs. rank 4: resolving the FEAST_LORD collision
 *
 * `LitGrade::FEAST_LORD` (per its own docblock, "II.5-6: Feasts of the Lord
 * in General Calendar; Sundays of Christmas and Ordinary Time") is shared
 * between two very different kinds of days: named Feasts of the Lord (Holy
 * Family, Baptism of the Lord) and plain numbered Sundays (after Epiphany,
 * after Pentecost). Both kinds can be `is_dominical === true` (every Sunday
 * is, in the literal sense, "the Lord's day"). Without an extra guard, rank
 * 3's `dom AND g ∈ {..., FEAST_LORD}` clause -- evaluated before rank 4 in
 * the ordered list -- would swallow an ordinary after-Pentecost/after-
 * Epiphany Sunday and never let rank 4 fire, contradicting the brief's
 * required test ("an after-Pentecost Sunday → rank 4"). The task brief
 * explicitly authorizes resolving this by choosing "the check that makes the
 * rank-2-vs-3-vs-4 distinction correct": rank 3's predicate therefore
 * excludes only events that actually qualify for rank 4 --
 * {@see self::isDominicalSunday()} true AND the season is one of the two
 * reserved for rank 4 (`AFTER_EPIPHANY`/`AFTER_PENTECOST`) -- rather than
 * excluding by season alone. Excluding by season alone was tried first and
 * was wrong: it also caught non-Sunday Feasts/Solemnities of the Lord that
 * merely fall within those seasons (e.g. `CorpusChristi` on a Thursday,
 * `SacredHeart` on a Friday), pushing them all the way down to the rank-14
 * floor instead of rank 3 -- discovered when the Ambrosian temporale engine
 * started placing Pentecost-anchored celebrations that are `is_dominical`
 * but not Sunday. Conditioning the exclusion on `isDominicalSunday()` fixes
 * that without changing anything for plain numbered Sundays (still excluded,
 * still rank 4) or for `ChristKing`/`Trinity` (both Sundays, still excluded,
 * still rank 4). Because rank 2 already fully absorbs the Advent/Lent/Easter
 * Sunday case earlier in the same ordered evaluation, no equivalent exclusion
 * is needed there.
 *
 * ## Known gaps (documented, not implemented -- no requirement/test covers them)
 *
 * - "Own-church dedication" (rank 3): the Tabella grants every parish/
 *   diocese a Solemnity for the dedication of its own church, universally at
 *   the same comune-tier precedence as All Souls -- but which day this is,
 *   and how to flag it, is diocesan (Plan 5+) data not yet modeled on
 *   `LiturgicalEvent`. Left as a documented extension point.
 * - "de Exceptáto" ferie (rank 8): certain privileged Ambrosian ferias that
 *   carry FEAST grade directly are already handled generically by rank 8's
 *   `g === FEAST` check; no separate key-set is needed unless/until a ferie
 *   needs FEAST grade without also being `is_proper`.
 * - `LitGrade::COMMEMORATION` (an API-internal refinement documented on
 *   `LitGrade` itself as "not in the original 13-point table") has no
 *   dedicated rank here and falls through to the rank-14 floor by default,
 *   consistent with it representing a demoted, collect-only memorial with no
 *   independent precedence of its own.
 */
final class AmbrosianLiturgicalDayRank
{
    /** Rank 1: the Easter Triduum. */
    public const array TRIDUUM_KEYS = ['HolyThurs', 'GoodFri', 'EasterVigil', 'Easter'];

    /** Rank 2: fixed-date Solemnities of the Lord plus the two Ambrosian-proper fixed solemnities. */
    public const array FIXED_RANK_2_KEYS = ['Christmas', 'Epiphany', 'Ascension', 'Pentecost', 'DedicationDuomo', 'SabatoTradSymb'];

    /**
     * Rank 2: ferie of the "settimana autentica" (Monday-Wednesday of Holy
     * Week). Holy Thursday is deliberately NOT repeated here: it is already
     * covered by {@see self::TRIDUUM_KEYS} (rank 1).
     */
    public const array SETTIMANA_AUTENTICA_FERIE_KEYS = ['MonHolyWeek', 'TueHolyWeek', 'WedHolyWeek'];

    /** Rank 2: the six weekdays of the Octave of Easter. */
    public const array EASTER_OCTAVE_KEYS = [
        'MonOctaveEaster',
        'TueOctaveEaster',
        'WedOctaveEaster',
        'ThuOctaveEaster',
        'FriOctaveEaster',
        'SatOctaveEaster',
    ];

    /**
     * Rank 2: the six weekdays of the Octave of Christmas (Dec 26-31), keyed
     * exactly as `CalendarHandler::calculateWeekdaysChristmasOctave()`
     * produces them (`'ChristmasWeekdayDec' . $dayOfTheMonth`).
     */
    public const array CHRISTMAS_OCTAVE_KEYS = [
        'ChristmasWeekdayDec26',
        'ChristmasWeekdayDec27',
        'ChristmasWeekdayDec28',
        'ChristmasWeekdayDec29',
        'ChristmasWeekdayDec30',
        'ChristmasWeekdayDec31',
    ];

    /** Rank 2: seasons whose Sundays are HIGHER_SOLEMNITY-tier (Mysterii Paschalis I.2). */
    private const array RANK_2_SUNDAY_SEASONS = [LitSeason::ADVENT, LitSeason::LENT, LitSeason::EASTER];

    /** Rank 4: seasons whose Sundays are plain numbered/ordinary Sundays (Mysterii Paschalis II.6). */
    private const array RANK_4_SUNDAY_SEASONS = [LitSeason::AFTER_EPIPHANY, LitSeason::AFTER_PENTECOST];

    /** Rank 3: the Commemoration of All the Faithful Departed, universally comune-tier. */
    private const string ALL_SOULS_KEY = 'AllSouls';

    /** Rank 3/5 grade set: solemnities and Feasts of the Lord. */
    private const array RANK_3_GRADES = [LitGrade::HIGHER_SOLEMNITY, LitGrade::SOLEMNITY, LitGrade::FEAST_LORD];

    /** Rank 5/6 grade set: solemnities only (no Feast-of-the-Lord tier). */
    private const array SOLEMNITY_GRADES = [LitGrade::HIGHER_SOLEMNITY, LitGrade::SOLEMNITY];

    /**
     * Highest rank number still considered "of solemnity tier" (ranks 1-6:
     * Triduum, fixed/dominical Solemnities-tier days, comune and proper
     * solemnities). Exposed so a resolver can ask "is this rank a
     * solemnity?" without re-deriving the boundary.
     */
    public const int SOLEMNITY_RANK_CEILING = 6;

    /**
     * Highest rank number that still "occupies" a day tightly enough that
     * nothing else may be added: everything through the comune memorial tier,
     * i.e. Praenotanda n. 56's "ranks 1-10" of the Tabella, which after the
     * `is_bvm` split of Tabella rank 10 is internal ranks 1-11 (see the class
     * docblock's ladder table). Exposed so a resolver can ask "is this day
     * free?" (i.e. is only a proper memorial, optional memorial, or plain
     * weekday sitting on this date) without re-deriving the boundary.
     */
    public const int OCCUPIED_RANK_CEILING = 11;

    /**
     * No instances: this is a pure static classifier.
     */
    private function __construct()
    {
    }

    /**
     * Classify a liturgical event against the Ambrosian Tabella.
     * Ordered predicates, first match wins; see the class docblock for the
     * design rationale behind each deviation from a literal one-line-per-
     * rank reading of the Tabella.
     *
     * @param LiturgicalEvent $e
     * @return int The rank, 1 (highest precedence) through 14 (lowest).
     */
    public static function rankOf(LiturgicalEvent $e): int
    {
        // rank 1: Easter Triduum
        if (in_array($e->event_key, self::TRIDUUM_KEYS, true)) {
            return 1;
        }

        // rank 2: fixed Solemnities of the Lord, dominical Sundays of Advent/Lent/Easter,
        // settimana-autentica ferie, Easter octave, Christmas octave
        if (
            in_array($e->event_key, self::FIXED_RANK_2_KEYS, true)
            || ( self::isDominicalSunday($e) && in_array($e->liturgical_season, self::RANK_2_SUNDAY_SEASONS, true) )
            || in_array($e->event_key, self::SETTIMANA_AUTENTICA_FERIE_KEYS, true)
            || in_array($e->event_key, self::EASTER_OCTAVE_KEYS, true)
            || in_array($e->event_key, self::CHRISTMAS_OCTAVE_KEYS, true)
        ) {
            return 2;
        }

        // rank 3: comune dominical solemnity/feast-of-the-Lord (excluding events that
        // actually qualify for rank 4 -- plain numbered Sundays after Epiphany/Pentecost,
        // see class docblock), All Souls
        if (
            $e->event_key === self::ALL_SOULS_KEY
            || (
                self::isComune($e)
                && $e->is_dominical === true
                && in_array($e->grade, self::RANK_3_GRADES, true)
                && false === ( self::isDominicalSunday($e) && in_array($e->liturgical_season, self::RANK_4_SUNDAY_SEASONS, true) )
            )
        ) {
            return 3;
        }

        // rank 4: dominical Sundays after Epiphany / after Pentecost (plain numbered Sundays)
        if (self::isDominicalSunday($e) && in_array($e->liturgical_season, self::RANK_4_SUNDAY_SEASONS, true)) {
            return 4;
        }

        // rank 5: comune, non-dominical BVM/saint solemnity
        if (
            self::isComune($e)
            && $e->is_dominical !== true
            && in_array($e->grade, self::SOLEMNITY_GRADES, true)
        ) {
            return 5;
        }

        // rank 6: proper solemnity
        if ($e->is_proper === true && in_array($e->grade, self::SOLEMNITY_GRADES, true)) {
            return 6;
        }

        // rank 7: ferie of Lent
        if ($e->liturgical_season === LitSeason::LENT && $e->grade === LitGrade::WEEKDAY) {
            return 7;
        }

        // rank 8: comune feast
        if (self::isComune($e) && $e->grade === LitGrade::FEAST) {
            return 8;
        }

        // rank 9: proper feast
        if ($e->is_proper === true && $e->grade === LitGrade::FEAST) {
            return 9;
        }

        // rank 10: comune memorial of the Blessed Virgin Mary (upper half of the
        // Tabella's rank 10 -- see the class docblock's "`is_bvm` split" section)
        if (self::isComune($e) && $e->grade === LitGrade::MEMORIAL && $e->is_bvm === true) {
            return 10;
        }

        // rank 11: comune memorial of a saint (lower half of the Tabella's rank 10)
        if (self::isComune($e) && $e->grade === LitGrade::MEMORIAL) {
            return 11;
        }

        // rank 12: proper memorial
        if ($e->is_proper === true && $e->grade === LitGrade::MEMORIAL) {
            return 12;
        }

        // rank 13: optional memorial
        if ($e->grade === LitGrade::MEMORIAL_OPT) {
            return 13;
        }

        // rank 14: default floor (any remaining ferie/Saturday, including COMMEMORATION-grade
        // events -- see class docblock)
        return 14;
    }

    /**
     * Whether the given rank still belongs to the "solemnity tier" (ranks 1-6).
     *
     * @param int $rank
     * @return bool
     */
    public static function isSolemnityRank(int $rank): bool
    {
        return $rank >= 1 && $rank <= self::SOLEMNITY_RANK_CEILING;
    }

    /**
     * Whether the given rank leaves a day "free" of everything through the
     * comune memorial tier -- Praenotanda n. 56's "free of ranks 1-10" of the
     * Tabella, i.e. rank > {@see self::OCCUPIED_RANK_CEILING}: a proper
     * memorial, an optional memorial, or a plain weekday.
     *
     * Named without a rank literal on purpose: the previous name
     * (`isFreeOfRanksOneThroughTen`) baked in a boundary that the `is_bvm`
     * split of Tabella rank 10 has since moved.
     *
     * @param int $rank
     * @return bool
     */
    public static function isFreeOfOccupiedRanks(int $rank): bool
    {
        return $rank > self::OCCUPIED_RANK_CEILING;
    }

    /**
     * A "dominical Sunday": `is_dominical === true` AND the date's ISO-8601
     * weekday is 7 (Sunday). See the class docblock for why both checks are
     * required (as opposed to relying on `is_dominical` alone).
     *
     * @param LiturgicalEvent $e
     * @return bool
     */
    private static function isDominicalSunday(LiturgicalEvent $e): bool
    {
        return $e->is_dominical === true && (int) $e->date->format('N') === 7;
    }

    /**
     * "Comune" (taken from the General/universal calendar) is the default:
     * anything not explicitly flagged `is_proper === true`.
     *
     * @param LiturgicalEvent $e
     * @return bool
     */
    private static function isComune(LiturgicalEvent $e): bool
    {
        return $e->is_proper !== true;
    }
}
