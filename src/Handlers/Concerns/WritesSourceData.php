<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gives a write handler one way to put source data somewhere, whichever mode the
 * deployment is in.
 *
 * The handler stages each file it would write and commits once per request. It
 * never asks which writer is behind the interface — that is the whole point:
 * a self-hosted instance without Postgres and OpenFGA keeps writing files, and
 * the same handler code records proposals where the stack is present.
 */
trait WritesSourceData
{
    private ?SourceDataWriter $sourceDataWriter = null;

    /** @var array<string, mixed>|null */
    private ?array $submitterOidcUser = null;

    /**
     * Capture the authenticated identity for the duration of the request, the same
     * way handle() already captures the client IP for audit logging.
     */
    protected function captureSubmitter(ServerRequestInterface $request): void
    {
        /** @var array<string, mixed>|null $oidcUser */
        $oidcUser                = $request->getAttribute('oidc_user');
        $this->submitterOidcUser = is_array($oidcUser) ? $oidcUser : null;
    }

    protected function stageFile(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->sourceDataWriter()->stage($absolutePath, $operation, $content);
    }

    /**
     * @return array<string, mixed> Always carries a `disposition` key.
     */
    protected function commitStagedFiles(ChangeResource $resource): array
    {
        return $this->sourceDataWriter()->commit($resource);
    }

    /**
     * Memoised per request, so every staged file in one request lands in one
     * writer — and therefore, in queue mode, in one batch.
     */
    protected function sourceDataWriter(): SourceDataWriter
    {
        return $this->sourceDataWriter ??= SourceDataWriteMode::changeRequestsEnabled()
            ? new ChangeRequestSourceDataWriter(
                new SourceDataChangeRequestRepository(),
                new ChangeRequestReview(new ResourceAdminService($this->getFgaClient())),
                $this->submitterOidcUser ?? []
            )
            : new DiskSourceDataWriter();
    }
}
