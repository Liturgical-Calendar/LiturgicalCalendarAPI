<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * What {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::releaseClaim()}
 * actually observed, as opposed to how many rows it happened to touch.
 *
 * This type exists because a row count cannot answer the only question the caller has. A
 * release is guarded on `publication_status = 'queued'`, so it affects zero rows for
 * MULTIPLE, semantically opposite reasons:
 *
 * - the batch is `open`/`merged`/`closed` — another runner genuinely published it, and this
 *   runner's failed attempt is a harmless duplicate of finished work;
 * - the batch is `none` — another runner's publish failed too (a GitHub outage fails every
 *   runner identically), or {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::reclaimStaleClaims()}
 *   released it. Nothing is published anywhere, and the failure is real;
 * - the batch is gone entirely.
 *
 * Collapsing those into `0` and reading it as "already published elsewhere" is exactly the
 * critical defect the final whole-branch review of this feature found: a genuine outage
 * reported success and defeated the runner's stop-don't-hammer rule. The caller must branch
 * on the OBSERVED STATE, and this enum is what makes those branches explicit at the call site
 * instead of inferred from an integer.
 *
 * @see \LiturgicalCalendar\Api\Services\SourceData\PublishRunner::runOnce()
 */
enum ClaimReleaseOutcome: string
{
    /**
     * The batch was still `queued` and is now back to `none`: this runner's claim was the live
     * one, and its publish genuinely failed. Retryable, and a real failure for the run.
     */
    case RELEASED = 'released';

    /**
     * The batch is `open`, `merged` or `closed`: someone else finished it while this runner's
     * doomed attempt was still in flight. Not this run's failure — its work was simply
     * redundant. Releasing here would have reverted a real, successful publication.
     */
    case SETTLED_ELSEWHERE = 'settled_elsewhere';

    /**
     * The batch is `none`: nobody holds a claim on it and nobody published it. Reached when a
     * concurrent runner's own publish failed and released it first, when the grace-period
     * reclaim fired on this (merely slow, still alive) runner, or when a concurrent
     * transaction moved the row out of `queued` between this statement's snapshot and its row
     * lock. In every one of those readings the work is still unpublished, so this is a real
     * failure — the opposite of {@see self::SETTLED_ELSEWHERE}, despite affecting the same
     * zero rows.
     */
    case NOT_CLAIMED = 'not_claimed';

    /**
     * No row with this `batch_id` exists at all. Not reachable through this feature's own
     * write paths (nothing deletes a claimed batch), so it means something outside them
     * touched the table. Treated as a failure: an unexplained state is never read
     * optimistically.
     */
    case BATCH_MISSING = 'batch_missing';

    /**
     * The batch is still `queued`, but under a DIFFERENT claim token — another runner holds it.
     *
     * Semantically distinct from both neighbours, which is why it is not folded into either.
     * Not {@see SETTLED_ELSEWHERE}: nothing is published, so this is no evidence the work is
     * done. Not {@see NOT_CLAIMED}: the batch is not lying around unclaimed, it is actively
     * being published by someone else.
     *
     * Reached by the sequence `releaseClaim()`'s docblock describes: this runner was merely
     * slow, the grace period elapsed, `reclaimStaleClaims()` freed the batch, and another
     * runner claimed it before this runner's own doomed call returned. The release correctly
     * does nothing — and, the point of the token, spends none of the batch's bounded attempts
     * on a claim it does not hold.
     */
    case CLAIM_LOST = 'claim_lost';

    /**
     * True when the batch's work is known to be finished on GitHub — the ONLY outcome a failed
     * publish attempt may treat as a non-failure.
     */
    public function isSettled(): bool
    {
        // CLAIM_LOST is not settled: nothing is published, another runner simply holds the
        // live claim. Falls through to the default `false` below, same as NOT_CLAIMED and
        // BATCH_MISSING.
        return self::SETTLED_ELSEWHERE === $this;
    }
}
