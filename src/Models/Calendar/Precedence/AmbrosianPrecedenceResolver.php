<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Utilities;

/**
 * Resolves same-day coincidences between Ambrosian liturgical events against
 * the 13-rank Tabella dei giorni liturgici ({@see AmbrosianLiturgicalDayRank}).
 *
 * ## Algorithm
 *
 * 1. Group the collection's events by calendar date (`Y-m-d`).
 * 2. For every date holding more than one event ("contested" dates), sort the
 *    group by {@see AmbrosianLiturgicalDayRank::rankOf()} ascending (lower
 *    rank number = higher precedence) and keep the first event as the day's
 *    winner.
 * 3. Every other event on that date ("losers") is handed to
 *    {@see self::resolveLoser()}, one at a time, paired with the winner.
 *
 * ## `resolveLoser()` as an extension point
 *
 * Plan 4, Task 5 implemented only the simplest possible outcome: outright
 * suppression. Plan 4, Task 6 added the three Sunday-impeded transfer rules
 * that the Ambrosian Tabella prescribes ahead of plain suppression when the
 * winner is a privileged (Advent/Lent/Easter) Sunday:
 *
 * - a superseded **Solemnity of the Lord** moves to the following Monday
 *   ({@see self::transferLordSolemnityToMonday()});
 * - a superseded **Feast of the Lord** is omitted outright, not transferred
 *   ({@see self::omitImpededLordFeast()});
 * - a superseded **Solemnity of a saint** moves to the following Monday,
 *   unless that Monday is itself already a solemnity, in which case it is
 *   anticipated to the preceding Saturday instead
 *   ({@see self::transferSaintSolemnity()}).
 *
 * Plan 4, Task 7 (this revision) adds the last three branches (norms 4, 56):
 *
 * - a superseded **ferie of Lent** (rank 7) is protected: per norm 4 it
 *   yields ONLY to the Annunciation or St Joseph, so against any other
 *   winner it is left untouched, not suppressed
 *   ({@see self::protectLentenFerie()});
 * - the **Annunciation/St Joseph**, when superseded specifically within the
 *   *Sabato in traditione symboli* or the *settimana autentica* (Holy Week
 *   Mon-Thu), transfer to the Monday/Tuesday after the Easter octave
 *   respectively ({@see self::transferAnnunciationOrStJoseph()});
 * - any other superseded **solemnity**, with no more specific rule matching,
 *   transfers to the first subsequent day free of ranks 1-10 (the generic
 *   "n.56" rule) ({@see self::transferSolemnityToNextFreeDay()}).
 *
 * `resolveLoser()` is deliberately written as an ordered dispatch: each
 * transfer rule is an early-return branch ABOVE the final call to
 * {@see self::suppress()}, exactly mirroring the ordered-predicate style
 * already used by `AmbrosianLiturgicalDayRank::rankOf()`. Any loser not
 * matched by an earlier branch falls through to the suppression fallback.
 *
 * Plan 5, Task 8 (this revision) hardens the above against REAL
 * coincidences found by running the resolver on assembled real-year data
 * (issue #727):
 *
 * - `resolve()` no longer resolves a single up-front date-group snapshot.
 *   A TRANSFER can land an event on a date that already holds another
 *   event -- a brand new coincidence the old single pass never
 *   re-examined (suppression, by contrast, only ever REMOVES an event, so
 *   it can never create a new coincidence). `resolve()` now repeats the
 *   full "rebuild groups from current dates, resolve every contested
 *   date once" pass until a pass performs zero moves (a fixpoint), capped
 *   at {@see self::MAX_RE_RESOLUTION_PASSES} passes as a defensive guard
 *   ({@see self::resolveOnePass()}).
 * - {@see self::protectLentenFerie()} no longer leaves both the Lenten
 *   ferie and the impeding solemnity on the same date (issue #727 item
 *   1): per norm 4/56 a Lenten ferie yields ONLY to the Annunciation/St
 *   Joseph, so any OTHER impeding solemnity is itself impeded and must
 *   move -- via the same generic n.56 walk `transferSolemnityToNextFreeDay()`
 *   already used elsewhere, just applied to the WINNER instead of the
 *   loser. The walk itself is factored out into
 *   {@see self::findNextFreeDay()} so both call sites share it.
 */
final class AmbrosianPrecedenceResolver implements PrecedenceResolver
{
    /**
     * Safety cap on {@see self::resolve()}'s re-resolution loop. A real
     * Ambrosian calendar is expected to reach a fixpoint (a pass with zero
     * moves) in at most a couple of passes -- transfer cascades are local
     * (a handful of days deep at most) -- so hitting this cap signals a
     * genuine anomaly (e.g. a pathological cycle of transfers) rather than
     * a normal outcome, and is reported via `addMessage()` rather than
     * looped on indefinitely.
     */
    private const int MAX_RE_RESOLUTION_PASSES = 12;

    /**
     * Safety cap, in days, on {@see self::findNextFreeDay()}'s forward walk.
     * Shared by the generic n.56 transfer and the Lenten-ferie-protection
     * transfer -- see {@see self::findNextFreeDay()} for why 366 is a safe
     * defensive bound rather than a normally-reachable limit.
     */
    private const int MAX_FREE_DAY_WALK_DAYS = 366;

    public function resolve(PrecedenceContext $ctx): void
    {
        for ($pass = 1; $pass <= self::MAX_RE_RESOLUTION_PASSES; $pass++) {
            if (false === $this->resolveOnePass($ctx)) {
                // Fixpoint reached: the last pass moved nothing, so no
                // coincidence remains that this resolver's rules can act on.
                return;
            }
        }

        $ctx->addMessage(sprintf(
            'AmbrosianPrecedenceResolver: reached the %d-pass re-resolution cap without reaching a fixpoint; the '
                . 'calendar may still contain an unresolved coincidence (this signals an anomaly and should not '
                . 'happen for a real calendar).',
            self::MAX_RE_RESOLUTION_PASSES
        ));
    }

    /**
     * One full resolution pass: rebuilds the date-group snapshot from the
     * collection's CURRENT event dates (reflecting any moves made by
     * earlier passes), resolves every contested date exactly once (same
     * per-group logic as before Task 8: sort by `rankOf()`, keep the
     * winner, hand every other event to {@see self::resolveLoser()}), and
     * reports whether any call to `resolveLoser()` performed a MOVE (a
     * transfer that could have created a new coincidence at its
     * destination) -- the signal {@see self::resolve()} uses to decide
     * whether another pass is needed.
     */
    private function resolveOnePass(PrecedenceContext $ctx): bool
    {
        /** @var array<string,array<string,LiturgicalEvent>> $eventsByDate keyed by 'Y-m-d', then by event_key */
        $eventsByDate = [];
        foreach ($ctx->cal->getLiturgicalEvents()->getEvents() as $key => $event) {
            $eventsByDate[$event->date->format('Y-m-d')][$key] = $event;
        }

        $movedAny = false;

        foreach ($eventsByDate as $group) {
            if (count($group) < 2) {
                // Uncontested date: nothing to resolve.
                continue;
            }

            uasort(
                $group,
                static fn (LiturgicalEvent $a, LiturgicalEvent $b): int
                    => AmbrosianLiturgicalDayRank::rankOf($a) <=> AmbrosianLiturgicalDayRank::rankOf($b)
            );

            $winner = array_shift($group);
            $losers = $group;

            foreach ($losers as $loser) {
                if ($this->resolveLoser($winner, $loser, $ctx)) {
                    $movedAny = true;
                }
            }
        }

        return $movedAny;
    }

    /**
     * Decides what happens to a single `$loser` event that was outranked by
     * `$winner` on the same date. This is the dispatch point for Tasks 6-7's
     * transfer rules (Lord/saint Sunday->Monday, Lenten ferie, Annunciation/
     * St. Joseph, generic n.56) -- each is inserted here as an early return
     * ABOVE the suppression fallback.
     *
     * Task 6 handles the case where `$winner` is an Advent/Lent/Easter Sunday
     * (rank 2's dominical-Sunday clause): a superseded Solemnity/Feast of the
     * Lord or Solemnity of a saint is transferred/omitted per the Tabella.
     *
     * Task 7 adds three more branches, evaluated in this order:
     *
     * 1. A Lenten ferie loser is protected UNLESS the winner is specifically
     *    the Annunciation or St Joseph (norm 4) -- checked first because it
     *    is the most restrictive predicate (`$loser`'s grade/season) and
     *    must win over the generic solemnity fallback below. Since Task 8,
     *    "protected" means the IMPEDING WINNER is transferred away (the
     *    ferie itself never moves) -- see {@see self::protectLentenFerie()}.
     * 2. The Annunciation/St Joseph loser, when superseded specifically
     *    within the Sabato in traditione symboli/settimana autentica window,
     *    transfers to the fixed Monday/Tuesday-after-the-octave target. This
     *    does not overlap with branch 1 (the Annunciation/St Joseph are
     *    never `grade === WEEKDAY`, so they can never match the Lenten-ferie
     *    predicate) nor with the Task 6 block above in the tested scenarios
     *    (the window's ferial winners are never a *Sunday*, so
     *    `isAdventLentEasterSunday()` is already false for them).
     * 3. Any other solemnity loser not caught by the above falls to the
     *    generic n.56 "first free day" transfer.
     *
     * Anything still unmatched falls through to plain suppression.
     *
     * @return bool `true` if this call MOVED an event (winner or loser) to
     *     a new date -- the signal {@see self::resolveOnePass()} uses to
     *     decide whether a transfer created a fresh coincidence that needs
     *     another pass. Plain suppression/omission never creates a new
     *     coincidence (it only removes an event), so those branches return
     *     `false`.
     */
    private function resolveLoser(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        if (self::isAdventLentEasterSunday($winner)) {
            if (self::isLordSolemnity($loser)) {
                return $this->transferLordSolemnityToMonday($winner, $loser, $ctx);
            }

            if (self::isLordFeast($loser)) {
                return $this->omitImpededLordFeast($winner, $loser, $ctx);
            }

            if (self::isSaintSolemnity($loser)) {
                return $this->transferSaintSolemnity($winner, $loser, $ctx);
            }
        }

        if (self::isLentenFerie($loser) && false === self::isAnnunciationOrStJoseph($winner)) {
            return $this->protectLentenFerie($winner, $loser, $ctx);
        }

        if (
            self::isAnnunciationOrStJoseph($loser)
            && self::isInAnnunciationStJosephTransferWindow($loser->date, $ctx->params->Year)
        ) {
            return $this->transferAnnunciationOrStJoseph($winner, $loser, $ctx);
        }

        if (self::isSolemnity($loser)) {
            return $this->transferSolemnityToNextFreeDay($winner, $loser, $ctx);
        }

        return $this->suppress($winner, $loser, $ctx);
    }

    /**
     * Whether `$e` is the kind of privileged Sunday that outranks even a
     * Solemnity/Feast of the Lord or a saint's Solemnity: a dominical event
     * that actually falls on a Sunday, in Advent, Lent, or Easter -- i.e.
     * exactly the predicate `AmbrosianLiturgicalDayRank::rankOf()` uses for
     * its rank-2 dominical-Sunday clause (that predicate is private there, so
     * it is re-expressed here rather than exposed solely for this call site).
     */
    private static function isAdventLentEasterSunday(LiturgicalEvent $e): bool
    {
        return $e->is_dominical === true
            && (int) $e->date->format('N') === 7
            && in_array($e->liturgical_season, [LitSeason::ADVENT, LitSeason::LENT, LitSeason::EASTER], true);
    }

    /**
     * Whether `$e` is a Solemnity of the Lord: dominical, grade Solemnity or
     * Higher Solemnity (but not the lesser Feast-of-the-Lord tier).
     */
    private static function isLordSolemnity(LiturgicalEvent $e): bool
    {
        return $e->is_dominical === true
            && in_array($e->grade, [LitGrade::HIGHER_SOLEMNITY, LitGrade::SOLEMNITY], true);
    }

    /**
     * Whether `$e` is a Feast of the Lord: dominical, grade Feast-of-the-Lord
     * or plain Feast.
     */
    private static function isLordFeast(LiturgicalEvent $e): bool
    {
        return $e->is_dominical === true
            && in_array($e->grade, [LitGrade::FEAST_LORD, LitGrade::FEAST], true);
    }

    /**
     * Whether `$e` is a Solemnity of a saint (or the Blessed Virgin Mary):
     * grade Solemnity or Higher Solemnity, but NOT dominical.
     */
    private static function isSaintSolemnity(LiturgicalEvent $e): bool
    {
        return $e->is_dominical !== true
            && in_array($e->grade, [LitGrade::HIGHER_SOLEMNITY, LitGrade::SOLEMNITY], true);
    }

    /**
     * The two `event_key`s this resolver recognizes as "the Annunciation" and
     * "St Joseph" for the norm-4 Lenten-ferie exception and the Holy-Week
     * transfer window below. This is the stable key convention the task
     * brief asked to document: fixtures (and, eventually, real Ambrosian
     * calendar source data) must use exactly these two keys for the two
     * solemnities to be recognized by this resolver.
     */
    private const array ANNUNCIATION_ST_JOSEPH_KEYS = ['Annunciation', 'StJoseph'];

    /**
     * Whether `$e` is the Annunciation or St Joseph solemnity, identified by
     * the stable `event_key` convention in {@see self::ANNUNCIATION_ST_JOSEPH_KEYS}.
     */
    private static function isAnnunciationOrStJoseph(LiturgicalEvent $e): bool
    {
        return in_array($e->event_key, self::ANNUNCIATION_ST_JOSEPH_KEYS, true);
    }

    /**
     * Whether `$e` is a ferie of Lent (rank 7): the same predicate
     * {@see AmbrosianLiturgicalDayRank::rankOf()} uses for its rank-7 clause,
     * re-expressed here for the same reason `isAdventLentEasterSunday()` is
     * (that predicate is private on the rank classifier).
     */
    private static function isLentenFerie(LiturgicalEvent $e): bool
    {
        return $e->liturgical_season === LitSeason::LENT && $e->grade === LitGrade::WEEKDAY;
    }

    /**
     * Whether `$e` is a solemnity (Higher Solemnity or Solemnity), regardless
     * of the `is_dominical`/`is_proper` distinctions the more specific
     * `isLordSolemnity()`/`isSaintSolemnity()` predicates make -- used only
     * by the generic n.56 fallback, which applies uniformly to any
     * solemnity not already claimed by a more specific branch above it.
     */
    private static function isSolemnity(LiturgicalEvent $e): bool
    {
        return in_array($e->grade, [LitGrade::HIGHER_SOLEMNITY, LitGrade::SOLEMNITY], true);
    }

    /**
     * Whether `$date` falls within the Sabato in traditione symboli (the
     * Saturday before Palm Sunday, Easter - 8 days) or the settimana
     * autentica (Holy Week Monday through Thursday, Easter - 6 through
     * Easter - 3 days) -- the two windows in which norm 4 requires the
     * Annunciation/St Joseph to transfer to a fixed post-octave target
     * rather than falling back to the generic Sunday-impeded or n.56 rules.
     *
     * Deliberately date-range based (not event-key based): the Tabella's
     * rule is anchored to the calendar position relative to Easter, not to
     * which specific event happens to occupy the winning slot that year.
     */
    private static function isInAnnunciationStJosephTransferWindow(\DateTimeInterface $date, int $year): bool
    {
        $easter         = Utilities::calcGregEaster($year);
        $satTradSymb    = ( clone $easter )->sub(new \DateInterval('P8D'));
        $settimanaStart = ( clone $easter )->sub(new \DateInterval('P6D')); // Monday of Holy Week
        $settimanaEnd   = ( clone $easter )->sub(new \DateInterval('P3D')); // Thursday of Holy Week

        return $date->format('Y-m-d') === $satTradSymb->format('Y-m-d')
            || ( $date >= $settimanaStart && $date <= $settimanaEnd );
    }

    /**
     * Transfers a superseded Solemnity of the Lord to the Monday immediately
     * following the privileged Sunday that impeded it.
     *
     * @return bool always `true`: this branch always moves `$loser`.
     */
    private function transferLordSolemnityToMonday(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $loserKey = $loser->event_key;
        // Captured BEFORE the move: moveLiturgicalEventDate() mutates $loser's
        // ->date property in place (same object reference), so reading it
        // AFTER the move would report the new date twice instead of "impeded
        // on X, transferred to Y".
        $originalDate = $loser->date->format('Y-m-d');
        $monday       = ( clone $loser->date )->add(new \DateInterval('P1D'));

        $ctx->cal->moveLiturgicalEventDate($loserKey, $monday);

        $ctx->addMessage(sprintf(
            '%s (%s), a Solemnity of the Lord, is impeded by the higher-ranking Sunday %s (%s) on %s and is transferred to the following Monday %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $originalDate,
            $monday->format('Y-m-d')
        ));

        return true;
    }

    /**
     * Omits (suppresses without transferring) a Feast of the Lord that is
     * impeded by a privileged Sunday. Mechanically identical to
     * {@see self::suppress()} but carries a message specific to this outcome,
     * since the brief requires each outcome to explain itself distinctly.
     *
     * @return bool always `false`: omission removes `$loser`, it never
     *     moves it, so it can never create a new coincidence.
     */
    private function omitImpededLordFeast(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $loserKey = $loser->event_key;

        $ctx->cal->removeLiturgicalEvent($loserKey);
        $ctx->cal->addSuppressedEvent($loser);

        $ctx->addMessage(sprintf(
            '%s (%s), a Feast of the Lord, is impeded by the higher-ranking Sunday %s (%s) on %s and is omitted this year (not transferred).',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d')
        ));

        return false;
    }

    /**
     * Transfers a superseded Solemnity of a saint (or the Blessed Virgin
     * Mary) to the Monday immediately following the privileged Sunday that
     * impeded it -- unless that Monday is itself already occupied by a
     * solemnity, in which case the Tabella instead anticipates the
     * celebration to the Saturday immediately preceding the Sunday.
     *
     * @return bool always `true`: both outcomes (Monday or Saturday) move
     *     `$loser`.
     */
    private function transferSaintSolemnity(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $loserKey = $loser->event_key;
        // Captured BEFORE either move below, for the same reason documented
        // in transferLordSolemnityToMonday().
        $originalDate = $loser->date->format('Y-m-d');
        $monday       = ( clone $loser->date )->add(new \DateInterval('P1D'));

        if ($ctx->cal->inSolemnities($monday)) {
            $saturday = ( clone $loser->date )->sub(new \DateInterval('P1D'));

            $ctx->cal->moveLiturgicalEventDate($loserKey, $saturday);

            $ctx->addMessage(sprintf(
                '%s (%s), a Solemnity, is impeded by the higher-ranking Sunday %s (%s) on %s; the following Monday %s is already occupied by another solemnity, so it is anticipated to the preceding Saturday %s.',
                $loser->name,
                $loserKey,
                $winner->name,
                $winner->event_key,
                $originalDate,
                $monday->format('Y-m-d'),
                $saturday->format('Y-m-d')
            ));
            return true;
        }

        $ctx->cal->moveLiturgicalEventDate($loserKey, $monday);

        $ctx->addMessage(sprintf(
            '%s (%s), a Solemnity, is impeded by the higher-ranking Sunday %s (%s) on %s and is transferred to the following Monday %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $originalDate,
            $monday->format('Y-m-d')
        ));

        return true;
    }

    /**
     * Protects a superseded ferie of Lent (norm 4): the ferie ITSELF is
     * never suppressed and never moves, because a Lenten ferie yields only
     * to the Annunciation or St Joseph (already excluded by the dispatch
     * guard in `resolveLoser()`, not here). Task 8 (issue #727 item 1)
     * closes what was previously a no-op that left both events on the
     * date: `$winner` here is the IMPEDING solemnity (it won the rank
     * comparison in `resolveOnePass()`'s sort), and per norm 4 that
     * solemnity is itself impeded by the protected ferie, so it is
     * `$winner` -- not `$loser` -- that is transferred away, via the same
     * generic n.56 "first day free of ranks 1-10" walk used by
     * {@see self::transferSolemnityToNextFreeDay()} (factored out into
     * {@see self::findNextFreeDay()} so both share it). The ferie's own
     * date is never touched.
     *
     * @return bool `true` if the winner was moved; `false` only in the
     *     defensive guard case where no free day was found within
     *     {@see self::MAX_FREE_DAY_WALK_DAYS} and the winner is suppressed
     *     instead (should not happen for a real calendar).
     */
    private function protectLentenFerie(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $winnerKey = $winner->event_key;
        $target    = $this->findNextFreeDay($winner->date, $ctx);

        if (null === $target) {
            $ctx->cal->removeLiturgicalEvent($winnerKey);
            $ctx->cal->addSuppressedEvent($winner);

            $ctx->addMessage(sprintf(
                '%s (%s) impedes the ferie of Lent %s (%s) on %s, but per norm 4 a Lenten ferie yields only to the '
                    . 'Annunciation or St Joseph, so %s is itself impeded; no day free of ranks 1-10 was found '
                    . 'within %d days to transfer it to, so it is suppressed as a guard (this should not happen '
                    . 'for a real calendar).',
                $winner->name,
                $winnerKey,
                $loser->name,
                $loser->event_key,
                $winner->date->format('Y-m-d'),
                $winner->name,
                self::MAX_FREE_DAY_WALK_DAYS
            ));

            return false;
        }

        // Captured BEFORE the move, for the same reason documented in
        // transferLordSolemnityToMonday(): moveLiturgicalEventDate() mutates
        // $winner's ->date property in place.
        $originalDate = $winner->date->format('Y-m-d');

        $ctx->cal->moveLiturgicalEventDate($winnerKey, $target);

        $ctx->addMessage(sprintf(
            '%s (%s) impedes the ferie of Lent %s (%s) on %s, but per norm 4 a Lenten ferie yields only to the '
                . 'Annunciation or St Joseph; the ferie retains its liturgical precedence and %s is instead '
                . 'transferred, per n.56, to the first subsequent day free of ranks 1-10, %s.',
            $winner->name,
            $winnerKey,
            $loser->name,
            $loser->event_key,
            $originalDate,
            $winner->name,
            $target->format('Y-m-d')
        ));

        return true;
    }

    /**
     * Transfers a superseded Annunciation/St Joseph solemnity to its fixed
     * post-octave target: the Monday after the Easter octave (Easter + 8
     * days) for the Annunciation, the Tuesday after (Easter + 9 days) for
     * St Joseph. Only called once {@see self::isInAnnunciationStJosephTransferWindow()}
     * has already confirmed `$loser` falls within the Sabato in traditione
     * symboli/settimana autentica window that norm 4 anchors this transfer
     * to -- SatOctaveEaster (Easter + 6) is the last day of the Easter
     * octave, so +8/+9 are literally "the Monday/Tuesday after the octave".
     *
     * @return bool always `true`: this branch always moves `$loser`.
     */
    private function transferAnnunciationOrStJoseph(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $loserKey   = $loser->event_key;
        $isStJoseph = $loserKey === 'StJoseph';
        $offset     = $isStJoseph ? 9 : 8;
        $easter     = Utilities::calcGregEaster($ctx->params->Year);
        $newDate    = ( clone $easter )->add(new \DateInterval("P{$offset}D"));
        // Captured BEFORE the move, for the same reason documented in
        // transferLordSolemnityToMonday().
        $originalDate = $loser->date->format('Y-m-d');

        $ctx->cal->moveLiturgicalEventDate($loserKey, $newDate);

        $ctx->addMessage(sprintf(
            '%s (%s) is impeded by the higher-ranking %s (%s) on %s, falling within the Sabato in traditione '
                . 'symboli/settimana autentica window; per norm 4 it is transferred to the %s after the Easter '
                . 'octave, %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $originalDate,
            $isStJoseph ? 'Tuesday' : 'Monday',
            $newDate->format('Y-m-d')
        ));

        return true;
    }

    /**
     * The generic "n.56" transfer: a superseded solemnity with no more
     * specific rule above it moves to the first subsequent day that is free
     * of ranks 1-10, via {@see self::findNextFreeDay()}. If no free day is
     * found within the walk's cap, the event is suppressed outright with a
     * distinct warning message rather than transferred nowhere.
     *
     * @return bool `true` if `$loser` was moved; `false` only in the
     *     defensive guard case where it is suppressed instead.
     */
    private function transferSolemnityToNextFreeDay(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $loserKey = $loser->event_key;
        $target   = $this->findNextFreeDay($loser->date, $ctx);

        if (null === $target) {
            // Guard: should not happen for any real calendar -- suppress with a
            // distinct warning message rather than transferring nowhere.
            $ctx->cal->removeLiturgicalEvent($loserKey);
            $ctx->cal->addSuppressedEvent($loser);

            $ctx->addMessage(sprintf(
                '%s (%s) is impeded by the higher-ranking %s (%s) on %s and no day free of ranks 1-10 was found '
                    . 'within %d days; suppressed as a guard (this should not happen for a real calendar).',
                $loser->name,
                $loserKey,
                $winner->name,
                $winner->event_key,
                $loser->date->format('Y-m-d'),
                self::MAX_FREE_DAY_WALK_DAYS
            ));

            return false;
        }

        // Captured BEFORE the move, for the same reason documented in
        // transferLordSolemnityToMonday().
        $originalDate = $loser->date->format('Y-m-d');

        $ctx->cal->moveLiturgicalEventDate($loserKey, $target);

        $ctx->addMessage(sprintf(
            '%s (%s) is impeded by the higher-ranking %s (%s) on %s and, per n.56, is transferred to the '
                . 'first subsequent day free of ranks 1-10, %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $originalDate,
            $target->format('Y-m-d')
        ));

        return true;
    }

    /**
     * Shared forward walk used by both n.56-style transfers
     * ({@see self::transferSolemnityToNextFreeDay()} and
     * {@see self::protectLentenFerie()}'s winner-transfer): starting the day
     * AFTER `$fromDate`, walks forward one day at a time looking for a date
     * with no occupant ranked 1-10
     * ({@see AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen()}), and
     * returns the first such date found.
     *
     * The walk is capped at {@see self::MAX_FREE_DAY_WALK_DAYS} (366) days
     * as a defensive guard -- a free day is expected well within a single
     * year for any real Ambrosian calendar, so returning `null` (exhausting
     * the cap) signals a genuine anomaly rather than a normal outcome; each
     * caller decides how to handle that (both currently suppress the event
     * they would otherwise have moved).
     */
    private function findNextFreeDay(DateTime $fromDate, PrecedenceContext $ctx): ?DateTime
    {
        $candidate = clone $fromDate;

        for ($i = 0; $i < self::MAX_FREE_DAY_WALK_DAYS; $i++) {
            $candidate = ( clone $candidate )->add(new \DateInterval('P1D'));

            $isFree = true;
            foreach ($ctx->cal->getCalEventsFromDate($candidate) as $occupant) {
                if (false === AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen(AmbrosianLiturgicalDayRank::rankOf($occupant))) {
                    $isFree = false;
                    break;
                }
            }

            if ($isFree) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Suppresses `$loser` outright: removes it from the collection's active
     * liturgical events, records it on the suppressed-events ledger, and
     * appends a human-readable explanation to the context's message sink.
     *
     * `removeLiturgicalEvent()` already moves the event onto the suppressed
     * ledger internally; the explicit `addSuppressedEvent()` call afterward
     * is a defensive no-op restating that guarantee at the call site, per the
     * task brief, so the ledger write is not solely an implementation detail
     * of `removeLiturgicalEvent()`.
     *
     * @return bool always `false`: suppression removes `$loser`, it never
     *     moves it, so it can never create a new coincidence.
     */
    private function suppress(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): bool
    {
        $loserKey = $loser->event_key;

        $ctx->cal->removeLiturgicalEvent($loserKey);
        $ctx->cal->addSuppressedEvent($loser);

        $ctx->addMessage(sprintf(
            '%s (%s) is suppressed by the higher-ranking %s (%s) on %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d')
        ));

        return false;
    }
}
