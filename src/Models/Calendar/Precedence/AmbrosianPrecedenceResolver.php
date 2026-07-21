<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

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
 * suppression. Plan 4, Task 6 (this revision) adds the three Sunday-impeded
 * transfer rules that the Ambrosian Tabella prescribes ahead of plain
 * suppression when the winner is a privileged (Advent/Lent/Easter) Sunday:
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
 * Still future (Plan 4, Task 7): transferring a superseded Lenten ferial
 * commemoration, the Annunciation/St. Joseph transfer rules, and the generic
 * "n.56" transfer of a superseded feast to the next free day.
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
     * Lord or Solemnity of a saint is transferred/omitted per the Tabella
     * before falling back to plain suppression for everything else.
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
