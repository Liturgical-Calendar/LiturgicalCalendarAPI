<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

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
 * This task (Plan 4, Task 5) implements only the simplest possible outcome:
 * outright suppression. The Ambrosian Tabella actually prescribes several
 * more specific outcomes ahead of plain suppression -- transferring a
 * superseded Sunday/solemnity of the Lord to the following Monday, transferring
 * a superseded Lenten ferial commemoration, the Annunciation/St. Joseph
 * transfer rules, and the generic "n.56" transfer of a superseded feast to the
 * next free day -- each of which is a future task (Plan 4, Tasks 6-7).
 *
 * `resolveLoser()` is deliberately written as an ordered dispatch: each future
 * transfer rule is added as an early-return branch ABOVE the final call to
 * {@see self::suppress()}, exactly mirroring the ordered-predicate style
 * already used by `AmbrosianLiturgicalDayRank::rankOf()`. Because no transfer
 * rule is implemented yet, every loser currently falls through to the
 * suppression fallback.
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
     * St. Joseph, generic n.56) -- each should be inserted here as an early
     * return ABOVE the suppression fallback, e.g.:
     *
     *     if ($this->isTransferableLordOrSaintSunday($loser)) {
     *         $this->transferToFollowingMonday($winner, $loser, $ctx);
     *         return;
     *     }
     *
     * Since none of those rules exist yet, every loser is suppressed.
     */
    private function resolveLoser(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void
    {
        $this->suppress($winner, $loser, $ctx);
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
