<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * Where a change request sits in OUR review workflow.
 *
 * Deliberately separate from {@see ChangePublicationStatus}, which tracks
 * GitHub's view of the same change. Flattening the two would make
 * "approved but the push failed" indistinguishable from "approved, awaiting
 * review on the pull request".
 */
enum ChangeReviewStatus: string
{
    case SUBMITTED = 'submitted';
    case APPROVED  = 'approved';
    case REJECTED  = 'rejected';
    case WITHDRAWN = 'withdrawn';
}
