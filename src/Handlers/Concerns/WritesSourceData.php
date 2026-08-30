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
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
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

    private ?SourceDataPublishNotifier $sourceDataPublishNotifier = null;

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
    protected function commitStagedFiles(ChangeResource $resource, bool $deletesResource = false): array
    {
        return $this->sourceDataWriter()->commit($resource, $deletesResource);
    }

    /**
     * Read-your-own-unpublished-writes, for handlers that rebuild an AGGREGATE source file.
     *
     * Most resources are one file per editable thing, so re-reading disk before a write is
     * correct in either mode. A few are not: the whole decree corpus lives in one
     * `decrees.json`, and every decree translation for a locale lives in one
     * `decrees/i18n/<locale>.json`. Rebuilding one of those from disk in queue mode drops
     * whatever the same submitter has in flight, because queued work never reaches disk —
     * the defect that lost a decree behind a `201`.
     *
     * "Unpublished", not "pending": approving a batch in phase 1 writes no files (there is
     * no publisher until phase 2), so an approved batch is just as absent from disk as a
     * submitted one and must still be read back here. Narrowing this to submitted-only is
     * what let every approved-but-unpublished change be dropped by the submitter's next
     * write — including, on the auto-approved DELETE path, silently resurrecting a deleted
     * resource.
     *
     * The fallback lives here, on the writer seam, rather than in any one handler: it is
     * the same seam `stageFile()` and `commitStagedFiles()` use, so any handler with an
     * aggregate file gets it by asking. It is not applied automatically — a handler knows
     * which of its files are aggregates and which are not, and nothing else does.
     *
     * Returns null when there is nothing in flight, which is ALWAYS the answer in disk
     * mode. Callers must then read the file exactly as they did before, keeping disk-mode
     * behaviour byte-identical.
     */
    protected function unpublishedSourceContent(string $absolutePath): ?string
    {
        return $this->sourceDataWriter()->unpublishedContent($absolutePath);
    }

    /**
     * The queue-side counterpart to `glob()`, for handlers that enumerate a folder before
     * rebuilding what is in it. Always empty in disk mode.
     *
     * @return list<string> Absolute paths, ascending.
     */
    protected function unpublishedSourcePathsUnder(string $absoluteFolder): array
    {
        return $this->sourceDataWriter()->unpublishedPathsUnder($absoluteFolder);
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
                $this->submitterOidcUser ?? [],
                null,
                $this->sourceDataPublishNotifier()
            )
            : new DiskSourceDataWriter();
    }

    private function sourceDataWriteLogger(): LoggerInterface
    {
        return $this->sourceDataWriteLogger ??= LoggerFactory::create('audit', null, 90, false, true, false);
    }

    /**
     * Lazily resolved, mirroring {@see \LiturgicalCalendar\Api\Handlers\Admin\AccessRequestAdminHandler::getOutboxNotifier()}:
     * null when ext-redis is missing or neither `REDIS_SOCKET` nor `REDIS_HOST` is configured, which
     * is the ordinary state for a self-hoster (both are commented out in `.env.example`). A null
     * `\Redis` makes {@see SourceDataPublishNotifier::notify()} a quiet no-op, so this never blocks
     * the auto-approval path it feeds — it only costs the latency of waiting for cron instead.
     */
    private function sourceDataPublishNotifier(): SourceDataPublishNotifier
    {
        if ($this->sourceDataPublishNotifier !== null) {
            return $this->sourceDataPublishNotifier;
        }

        $redis = null;
        if (extension_loaded('redis') && ( isset($_ENV['REDIS_HOST']) || isset($_ENV['REDIS_SOCKET']) )) {
            try {
                $redis = new \Redis();
                if (isset($_ENV['REDIS_SOCKET']) && is_string($_ENV['REDIS_SOCKET']) && $_ENV['REDIS_SOCKET'] !== '') {
                    $redis->connect((string) $_ENV['REDIS_SOCKET']);
                } else {
                    $redisHost = is_string($_ENV['REDIS_HOST'] ?? null) ? $_ENV['REDIS_HOST'] : '127.0.0.1';
                    $redisPort = is_numeric($_ENV['REDIS_PORT'] ?? null) ? (int) $_ENV['REDIS_PORT'] : 6379;
                    $redis->connect($redisHost, $redisPort);
                }
                if (isset($_ENV['REDIS_PASSWORD']) && is_string($_ENV['REDIS_PASSWORD']) && $_ENV['REDIS_PASSWORD'] !== '') {
                    $redis->auth((string) $_ENV['REDIS_PASSWORD']);
                }
            } catch (\Throwable) {
                $redis = null; // Best-effort; fall back to PG-only durability.
            }
        }

        $streamName = is_string($_ENV['REDIS_SOURCEDATA_PUBLISH_STREAM'] ?? null)
            ? $_ENV['REDIS_SOURCEDATA_PUBLISH_STREAM']
            : 'litcal:sourcedata-publish-stream';

        return $this->sourceDataPublishNotifier = new SourceDataPublishNotifier($redis, $streamName);
    }
}
