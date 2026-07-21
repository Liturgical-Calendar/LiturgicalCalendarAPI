<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

/**
 * Classifies a `LiturgicalEvent` against the 13-rank Ambrosian *Tabella dei
 * giorni liturgici* (the Ambrosian analog of the Roman `LitGrade` precedence
 * ladder, cf. `LitGrade`'s own Mysterii Paschalis docblock). Lower rank number
 * means higher precedence (rank 1 = Easter Triduum, rank 13 = plain weekday).
 *
 * Implemented as an ORDERED LIST OF PREDICATES evaluated top to bottom inside
 * {@see self::rankOf()}, first match wins, one `if` block per rank in
 * ascending numeric order -- so the code can be read side by side with the
 * Tabella and inspected rank by rank, exactly mirroring the design already
 * used by `LitGrade`'s own docblock table.
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
 * excludes the two season values reserved for rank 4
 * (`AFTER_EPIPHANY`/`AFTER_PENTECOST`). Because rank 2 already fully absorbs
 * the Advent/Lent/Easter Sunday case earlier in the same ordered evaluation,
 * no equivalent exclusion is needed there.
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
 *   dedicated rank here and falls through to the rank-13 floor by default,
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
     * nothing else may be added (ranks 1-10: everything through the comune
     * memorial tier). Exposed so a resolver can ask "is a day free of ranks
     * 1-10?" (i.e. is only a proper memorial, optional memorial, or plain
     * weekday sitting on this date) without re-deriving the boundary.
     */
    public const int OCCUPIED_RANK_CEILING = 10;

    /**
     * No instances: this is a pure static classifier.
     */
    private function __construct()
    {
    }

    /**
     * Classify a liturgical event against the 13-rank Ambrosian Tabella.
     * Ordered predicates, first match wins; see the class docblock for the
     * design rationale behind each deviation from a literal one-line-per-
     * rank reading of the Tabella.
     *
     * @param LiturgicalEvent $e
     * @return int The rank, 1 (highest precedence) through 13 (lowest).
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

        // rank 3: comune dominical solemnity/feast-of-the-Lord (excluding the rank-4
        // ordinary-Sunday seasons, see class docblock), All Souls
        if (
            $e->event_key === self::ALL_SOULS_KEY
            || (
                self::isComune($e)
                && $e->is_dominical === true
                && in_array($e->grade, self::RANK_3_GRADES, true)
                && false === in_array($e->liturgical_season, self::RANK_4_SUNDAY_SEASONS, true)
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

        // rank 10: comune memorial
        if (self::isComune($e) && $e->grade === LitGrade::MEMORIAL) {
            return 10;
        }

        // rank 11: proper memorial
        if ($e->is_proper === true && $e->grade === LitGrade::MEMORIAL) {
            return 11;
        }

        // rank 12: optional memorial
        if ($e->grade === LitGrade::MEMORIAL_OPT) {
            return 12;
        }

        // rank 13: default floor (any remaining ferie/Saturday, including COMMEMORATION-grade
        // events -- see class docblock)
        return 13;
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
     * comune memorial tier (i.e. rank > 10: proper memorial, optional
     * memorial, or plain weekday).
     *
     * @param int $rank
     * @return bool
     */
    public static function isFreeOfRanksOneThroughTen(int $rank): bool
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
