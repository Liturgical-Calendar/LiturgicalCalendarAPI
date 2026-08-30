<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * A held claim on one publishable batch: which batch, and — the part phase 2 lacked — WHICH
 * runner holds it.
 *
 * Phase 2 returned a bare batch id, so `releaseClaim()`'s `publication_status = 'queued'` guard
 * could only ask "is this batch under SOME claim", never "under MINE". The token closes that:
 * see {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::releaseClaim()}.
 */
final readonly class PublishClaim
{
    public function __construct(
        public string $batchId,
        /** Generated inside the claiming transaction; cleared by record and by reclaim. */
        public string $token
    ) {
    }
}
