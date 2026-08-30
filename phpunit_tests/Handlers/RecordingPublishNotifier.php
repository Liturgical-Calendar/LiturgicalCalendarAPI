<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;

/**
 * Records every batch id `notify()` was called with instead of touching Redis — the seam
 * {@see SourceDataPublishNotifier}'s own docblock reserves subclassing for. Mirrors
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\RecordingAuditLogRepository}'s
 * recording-spy shape.
 *
 * Shared by {@see ChangeRequestAdminHandlerTest} (the admin-approve call site) and
 * {@see RegionalDataChangeRequestTest} (the auto-approval write-path call site) — the two
 * places a batch can become publishable.
 */
final class RecordingPublishNotifier extends SourceDataPublishNotifier
{
    /** @var list<string> */
    public array $notified = [];

    public function __construct()
    {
        parent::__construct(null, 'litcal:sourcedata-publish-stream');
    }

    public function notify(string $batchId): void
    {
        $this->notified[] = $batchId;
    }
}
