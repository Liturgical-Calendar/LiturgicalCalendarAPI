<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\AuditLogRepository;

/**
 * Records every `log()` call in memory instead of writing to `audit_log`, so a test can assert
 * on the exact action/resource/details written without a second read-path query. Mirrors
 * {@see \LiturgicalCalendar\Tests\Support\CollectingLogger}'s recording-spy shape, applied to
 * `AuditLogRepository` (not `final`, so overriding `log()` is enough — no HTTP/PSR-3 seam here).
 */
final class RecordingAuditLogRepository extends AuditLogRepository
{
    /** @var list<array{userId: ?string, action: string, resourceType: string, resourceId: ?string, details: ?array<string, mixed>}> */
    public array $entries = [];

    public function log(
        ?string $userId,
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?array $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $success = true
    ): string {
        $this->entries[] = [
            'userId'       => $userId,
            'action'       => $action,
            'resourceType' => $resourceType,
            'resourceId'   => $resourceId,
            'details'      => $details,
        ];

        return 'test-audit-entry-' . count($this->entries);
    }
}
