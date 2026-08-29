<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * The git-side outcome of publishing one approved change-request batch.
 *
 * Mirrors exactly what {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::recordPublication()}
 * persists, so a caller can pass this straight through without re-deriving any of it.
 */
final readonly class PublishResult
{
    public function __construct(
        /** `litcal-data/<resource_type>/<resource_id>`, stable per resource. */
        public string $branch,
        /** The sha of the commit just created and fast-forwarded onto `$branch`. */
        public string $commitSha,
        /** The rolling pull request's number, whether just opened or already open. */
        public ?int $prNumber,
        /**
         * The commit `$branch` pointed at before this publish — the parent of `$commitSha`,
         * and (on the branch's first publish) the base branch's head at that moment. Distinct
         * from a row's own per-file `base_sha`, which is accumulation-base bookkeeping set at
         * submission time.
         */
        public string $baseSha
    ) {
    }
}
