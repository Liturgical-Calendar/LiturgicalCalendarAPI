<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\GitHub;

/**
 * The three facts merge detection needs about a rolling pull request, plus the one it needs to
 * decide WHICH batches the merge actually carried.
 *
 * `$headSha` is here because "this batch's pull request merged" is not the same as "this batch
 * was in the merge": a reviewer clicking Merge concurrently with a publish leaves a commit on the
 * branch and outside the merge. See
 * {@see \LiturgicalCalendar\Api\Services\SourceData\MergePollRunner} for what is done with it.
 */
final readonly class PullRequestState
{
    public function __construct(
        /** GitHub's `state`: `open` or `closed`. A merged PR is always `closed`. */
        public string $state,
        public bool $merged,
        /** Null while the pull request is open — never the empty string. */
        public ?string $mergeCommitSha,
        /** The head commit at the time of this read; at merge time, what was merged. */
        public string $headSha
    ) {
    }

    public function isClosedUnmerged(): bool
    {
        return 'closed' === $this->state && false === $this->merged;
    }
}
