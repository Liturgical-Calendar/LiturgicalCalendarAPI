<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

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
     * Lazily resolved, so the happy path (flag unset, or the stack genuinely
     * present) never touches the filesystem to open a log file.
     */
    private ?LoggerInterface $sourceDataWriteLogger = null;

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
        if ($this->sourceDataWriter !== null) {
            return $this->sourceDataWriter;
        }

        if (SourceDataWriteMode::isMisconfigured()) {
            // The operator turned the flag on but Postgres and/or OpenFGA are not both
            // reachable: this request is silently falling back to disk. On the exact
            // host this feature exists to protect (one that rsyncs `--delete` from git),
            // the next deploy would revert the edit with no trace it ever happened —
            // so this must not be silent.
            $this->sourceDataWriteLogger()->warning(
                'SOURCEDATA_CHANGE_REQUESTS is set but Postgres and/or OpenFGA are not both '
                . 'reachable; falling back to writing this change straight to disk.'
            );
        }

        return $this->sourceDataWriter = SourceDataWriteMode::changeRequestsEnabled()
            ? new ChangeRequestSourceDataWriter(
                new SourceDataChangeRequestRepository(),
                new ChangeRequestReview(new ResourceAdminService($this->getFgaClient())),
                $this->submitterOidcUser ?? []
            )
            : new DiskSourceDataWriter();
    }

    private function sourceDataWriteLogger(): LoggerInterface
    {
        return $this->sourceDataWriteLogger ??= LoggerFactory::create('audit', null, 90, false, true, false);
    }
}
