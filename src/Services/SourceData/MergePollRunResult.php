<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * What one {@see MergePollRunner::runOnce()} pass did.
 *
 * Every count is reported on EVERY run, including runs that stopped early, for the same reason
 * {@see PublishRunResult} reports `parkedBatches` unconditionally: the runs most likely to leave
 * work in an odd state are exactly the runs that end before they meant to.
 */
final readonly class MergePollRunResult
{
    public function __construct(
        /** Batches transitioned to `merged`. */
        public int $merged = 0,
        /** Batches transitioned to `closed` (and `rejected`) because their PR closed unmerged. */
        public int $closed = 0,
        /**
         * Batches returned to `none` because their pull request merged WITHOUT them. Not a
         * failure — the design's answer to a concurrent merge — but never silent either, since a
         * non-zero value here means work was overtaken and will be republished.
         */
        public int $reset = 0,
        /**
         * Batches `open` with a NULL `pr_number`: unpollable, and stuck forever unless an operator
         * intervenes. Should always be zero; a non-zero value is an unexplained state, reported
         * rather than filtered out of the query.
         */
        public int $unpollable = 0,
        /** True if the run stopped because a poll or a containment check threw. */
        public bool $stoppedOnFailure = false
    ) {
    }
}
