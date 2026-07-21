<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

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
 */
final class AmbrosianPrecedenceResolver implements PrecedenceResolver
{
    public function resolve(PrecedenceContext $ctx): void
    {
        /** @var array<string,array<string,LiturgicalEvent>> $eventsByDate keyed by 'Y-m-d', then by event_key */
        $eventsByDate = [];
        foreach ($ctx->cal->getLiturgicalEvents()->getEvents() as $key => $event) {
            $eventsByDate[$event->date->format('Y-m-d')][$key] = $event;
        }

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
                $this->resolveLoser($winner, $loser, $ctx);
            }
        }
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
     *    must win over the generic solemnity fallback below.
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
     */
    private function resolveLoser(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        if (self::isAdventLentEasterSunday($winner)) {
            if (self::isLordSolemnity($loser)) {
                $this->transferLordSolemnityToMonday($winner, $loser, $ctx);
                return;
            }

            if (self::isLordFeast($loser)) {
                $this->omitImpededLordFeast($winner, $loser, $ctx);
                return;
            }

            if (self::isSaintSolemnity($loser)) {
                $this->transferSaintSolemnity($winner, $loser, $ctx);
                return;
            }
        }

        if (self::isLentenFerie($loser) && false === self::isAnnunciationOrStJoseph($winner)) {
            $this->protectLentenFerie($winner, $loser, $ctx);
            return;
        }

        if (
            self::isAnnunciationOrStJoseph($loser)
            && self::isInAnnunciationStJosephTransferWindow($loser->date, $ctx->params->Year)
        ) {
            $this->transferAnnunciationOrStJoseph($winner, $loser, $ctx);
            return;
        }

        if (self::isSolemnity($loser)) {
            $this->transferSolemnityToNextFreeDay($winner, $loser, $ctx);
            return;
        }

        $this->suppress($winner, $loser, $ctx);
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
     */
    private function transferLordSolemnityToMonday(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        $loserKey = $loser->event_key;
        $monday   = ( clone $loser->date )->add(new \DateInterval('P1D'));

        $ctx->cal->moveLiturgicalEventDate($loserKey, $monday);

        $ctx->addMessage(sprintf(
            '%s (%s), a Solemnity of the Lord, is impeded by the higher-ranking Sunday %s (%s) on %s and is transferred to the following Monday %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d'),
            $monday->format('Y-m-d')
        ));
    }

    /**
     * Omits (suppresses without transferring) a Feast of the Lord that is
     * impeded by a privileged Sunday. Mechanically identical to
     * {@see self::suppress()} but carries a message specific to this outcome,
     * since the brief requires each outcome to explain itself distinctly.
     */
    private function omitImpededLordFeast(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
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
    }

    /**
     * Transfers a superseded Solemnity of a saint (or the Blessed Virgin
     * Mary) to the Monday immediately following the privileged Sunday that
     * impeded it -- unless that Monday is itself already occupied by a
     * solemnity, in which case the Tabella instead anticipates the
     * celebration to the Saturday immediately preceding the Sunday.
     */
    private function transferSaintSolemnity(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        $loserKey = $loser->event_key;
        $monday   = ( clone $loser->date )->add(new \DateInterval('P1D'));

        if ($ctx->cal->inSolemnities($monday)) {
            $saturday = ( clone $loser->date )->sub(new \DateInterval('P1D'));

            $ctx->cal->moveLiturgicalEventDate($loserKey, $saturday);

            $ctx->addMessage(sprintf(
                '%s (%s), a Solemnity, is impeded by the higher-ranking Sunday %s (%s) on %s; the following Monday %s is already occupied by another solemnity, so it is anticipated to the preceding Saturday %s.',
                $loser->name,
                $loserKey,
                $winner->name,
                $winner->event_key,
                $loser->date->format('Y-m-d'),
                $monday->format('Y-m-d'),
                $saturday->format('Y-m-d')
            ));
            return;
        }

        $ctx->cal->moveLiturgicalEventDate($loserKey, $monday);

        $ctx->addMessage(sprintf(
            '%s (%s), a Solemnity, is impeded by the higher-ranking Sunday %s (%s) on %s and is transferred to the following Monday %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d'),
            $monday->format('Y-m-d')
        ));
    }

    /**
     * Protects a superseded ferie of Lent (norm 4): leaves it untouched in
     * the active collection -- NOT suppressed, NOT transferred -- because a
     * Lenten ferie yields only to the Annunciation or St Joseph (handled by
     * the dispatch guard in `resolveLoser()`, not here). Every other
     * higher-ranked winner that reaches this branch does not displace the
     * ferie's own liturgical precedence for the day.
     */
    private function protectLentenFerie(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        $ctx->addMessage(sprintf(
            '%s (%s), a ferie of Lent, is contested by the higher-ranking %s (%s) on %s but is protected per norm 4 '
                . '(a Lenten ferie yields only to the Annunciation or St Joseph); it retains its liturgical '
                . 'precedence and is not suppressed.',
            $loser->name,
            $loser->event_key,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d')
        ));
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
     */
    private function transferAnnunciationOrStJoseph(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        $loserKey   = $loser->event_key;
        $isStJoseph = $loserKey === 'StJoseph';
        $offset     = $isStJoseph ? 9 : 8;
        $easter     = Utilities::calcGregEaster($ctx->params->Year);
        $newDate    = ( clone $easter )->add(new \DateInterval("P{$offset}D"));

        $ctx->cal->moveLiturgicalEventDate($loserKey, $newDate);

        $ctx->addMessage(sprintf(
            '%s (%s) is impeded by the higher-ranking %s (%s) on %s, falling within the Sabato in traditione '
                . 'symboli/settimana autentica window; per norm 4 it is transferred to the %s after the Easter '
                . 'octave, %s.',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d'),
            $isStJoseph ? 'Tuesday' : 'Monday',
            $newDate->format('Y-m-d')
        ));
    }

    /**
     * The generic "n.56" transfer: a superseded solemnity with no more
     * specific rule above it moves to the first subsequent day that is free
     * of ranks 1-10 ({@see AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen()}),
     * walking forward one day at a time from the impeded date.
     *
     * The walk is capped at 366 iterations as a defensive guard -- a free
     * day (a day with no occupant ranked 1-10) is expected well within a
     * single year for any real Ambrosian calendar, so hitting the cap
     * signals a genuine anomaly rather than a normal outcome. If the cap is
     * reached, the event is suppressed outright with a warning message
     * rather than transferred nowhere or looping indefinitely.
     */
    private function transferSolemnityToNextFreeDay(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        $loserKey      = $loser->event_key;
        $maxIterations = 366;
        $candidate     = clone $loser->date;

        for ($i = 0; $i < $maxIterations; $i++) {
            $candidate = ( clone $candidate )->add(new \DateInterval('P1D'));

            $isFree = true;
            foreach ($ctx->cal->getCalEventsFromDate($candidate) as $occupant) {
                if (false === AmbrosianLiturgicalDayRank::isFreeOfRanksOneThroughTen(AmbrosianLiturgicalDayRank::rankOf($occupant))) {
                    $isFree = false;
                    break;
                }
            }

            if ($isFree) {
                $ctx->cal->moveLiturgicalEventDate($loserKey, $candidate);

                $ctx->addMessage(sprintf(
                    '%s (%s) is impeded by the higher-ranking %s (%s) on %s and, per n.56, is transferred to the '
                        . 'first subsequent day free of ranks 1-10, %s.',
                    $loser->name,
                    $loserKey,
                    $winner->name,
                    $winner->event_key,
                    $loser->date->format('Y-m-d'),
                    $candidate->format('Y-m-d')
                ));
                return;
            }
        }

        // Guard: should not happen for any real calendar -- suppress with a
        // distinct warning message rather than transferring nowhere.
        $ctx->cal->removeLiturgicalEvent($loserKey);
        $ctx->cal->addSuppressedEvent($loser);

        $ctx->addMessage(sprintf(
            '%s (%s) is impeded by the higher-ranking %s (%s) on %s and no day free of ranks 1-10 was found within '
                . '%d days; suppressed as a guard (this should not happen for a real calendar).',
            $loser->name,
            $loserKey,
            $winner->name,
            $winner->event_key,
            $loser->date->format('Y-m-d'),
            $maxIterations
        ));
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
     */
    private function suppress(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
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
    }
}
