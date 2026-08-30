<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;

/**
 * Per-batch retry spacing for the source-data publisher.
 *
 * # Why this is not {@see \LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff}
 *
 * The sibling outbox subsystem's schedule is `1 << min($attempts - 1, 9)` — 1s, 2s, 4s … 512s,
 * budgeted across `OUTBOX_MAX_ATTEMPTS` (10) attempts. Reusing it verbatim here, which is the
 * obvious reading of "borrow the outbox's scheduler", would be a silent regression rather than a
 * neutral copy, and the arithmetic is worth writing down because it is not obvious:
 *
 * {@see SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS} is **5**, not 10. Under the
 * outbox schedule, five attempts are spaced 1s + 2s + 4s + 8s, so a batch failing
 * deterministically — or a batch caught in a GitHub outage — would exhaust its whole attempt
 * budget and PARK in **15 seconds**. That bound was sized against cron: five attempts at the
 * runbook's documented five-minute interval is twenty minutes of real time, during which a
 * transient GitHub outage has a fair chance of ending. Fifteen seconds does not; the batch parks
 * on an outage that a human would call brief, and an operator then has to un-park it by hand.
 *
 * So the schedule's floor is the cron interval it replaces, not one second:
 *
 * | New attempt count | Wait before the next attempt |
 * |-------------------|------------------------------|
 * | 1                 | 300s (5 min)                 |
 * | 2                 | 600s (10 min)                |
 * | 3                 | 1200s (20 min)               |
 * | 4                 | 2400s (40 min)               |
 * | 5+                | 4800s (80 min)               |
 *
 * The first four gaps are what a batch actually experiences before parking (the fifth failure
 * parks it), so the budget to park is 300 + 600 + 1200 + 2400 = 4500s, 75 minutes. That is
 * strictly more patient than the twenty minutes cron gave it, never less — which is the property
 * that matters: adding scheduling must not make the attempt bound HARSHER than the interval it
 * was derived against. Doubling on top of that floor buys additional relief during a sustained
 * outage, at the cost of a slower recovery for a batch whose failure was transient. That trade is
 * the right way round here, because a batch is never lost by waiting — it stays `approved` and
 * claimable — whereas a parked batch is invisible work an operator must notice and revive.
 *
 * # Why the cap exists at all
 *
 * `min($attempts - 1, 4)` caps the shift at the attempt bound, so the table above is total rather
 * than open-ended. Attempts past `MAX_PUBLISH_ATTEMPTS` are unreachable through the claim path (a
 * parked batch is not claimable), so the `5+` row is only reached if the bound is later raised or
 * a row's counter is edited by hand; capping keeps that case bounded instead of doubling without
 * limit.
 *
 * Pure function, same as its outbox sibling — in its own file so the schedule is editable in one
 * place, and so {@see SourceDataChangeRequestRepository} can render it into SQL without embedding
 * the arithmetic there. See that class's `backoffCaseSql()` for how a PHP-side schedule reaches a
 * single-statement `UPDATE`.
 */
final class PublishBackoff
{
    /**
     * The floor, in seconds: the publish cron interval documented in
     * `docs/ops/change-request-runbook.md` ("The two cron entries"). Deriving the floor from that
     * interval is the whole point — see the class docblock. If the runbook's interval changes,
     * this is the number that has to move with it.
     */
    public const BASE_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * @param int $attempts The new attempt count (just incremented), 1..n.
     */
    public static function secondsForAttempt(int $attempts): int
    {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('attempts must be >= 1');
        }

        return self::BASE_SECONDS * ( 1 << min($attempts - 1, 4) );
    }
}
