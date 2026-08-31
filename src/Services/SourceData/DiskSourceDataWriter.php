<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Utilities;

/**
 * Writes source data to disk — the behaviour every deployment had before change
 * requests existed, and the behaviour a self-hosted instance without Postgres
 * and OpenFGA keeps.
 *
 * The write/delete primitives and the `ServiceUnavailableException` on failure are
 * lifted from the handler layer rather than rewritten, but not from
 * `RegionalDataHandler`: that handler's bare `file_put_contents()`/`unlink()`, with
 * no locking and no cache invalidation, is the outlier among the write handlers, not
 * the convention. `DecreesHandler` and `TemporaleHandler` both take an exclusive lock
 * on every write (`LOCK_EX`) and call `Utilities::invalidateJsonFileCache()`
 * afterwards, because `Utilities::jsonFileToObject()`/`jsonFileToArray()`/
 * `jsonFileToObjectArray()` cache file contents in APCu for 300 seconds — without
 * invalidation, a file this class just wrote would keep being served from cache for
 * up to five minutes, and without `LOCK_EX`, two overlapping writers could interleave
 * and corrupt the file. This class follows that stricter, more common convention —
 * deliberately stronger than what `RegionalDataHandler` does today — so that Tasks
 * 9–11, which migrate `DecreesHandler`, `TemporaleHandler` and `RegionalDataHandler`
 * onto this writer, do not silently lose locking or cache freshness in the move.
 * Applying both unconditionally is safe even for `RegionalDataHandler`'s callers:
 * `LOCK_EX` is just an exclusive lock held for the duration of the write, and
 * invalidating a cache entry that was never populated is a no-op.
 *
 * A deletion invalidates the cache too, for the same reason in the opposite
 * direction: a file this class just removed must not keep being served from a cache
 * entry a still-live read populated before the delete.
 *
 * Staging is deferred rather than immediate so that both implementations share
 * one contract, and so a request that fails validation half way through has not
 * already half-written the calendar.
 */
final class DiskSourceDataWriter implements SourceDataWriter
{
    /** @var list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private array $staged = [];

    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->staged[] = [
            'path'      => $absolutePath,
            'operation' => $operation,
            'content'   => $content,
        ];
    }

    public function commit(ChangeResource $resource, bool $deletesResource = false): array
    {
        // Ignored deliberately. Disk mode purges inline, in the handler, gated on the write having
        // landed — there is no later moment at which to act on this, because there is no later.
        $staged       = $this->staged;
        $this->staged = [];

        foreach ($staged as $file) {
            if ($file['operation'] === ChangeOperation::DELETE) {
                $this->removeFile($file['path']);
                continue;
            }

            $this->writeFile($file['path'], (string) $file['content']);
        }

        return ['disposition' => 'applied'];
    }

    /**
     * Disk mode has no queue: everything a submitter wrote is already the file itself, and
     * therefore already published. Answering null unconditionally is what keeps
     * read-your-own-unpublished-writes invisible here — a handler asking this question
     * falls straight back to the same disk read it has always done, byte for byte.
     */
    public function unpublishedContent(string $absolutePath): ?string
    {
        return null;
    }

    /**
     * @return list<string> Always empty, for the reason given on {@see unpublishedContent()}.
     */
    public function unpublishedPathsUnder(string $absoluteFolder): array
    {
        return [];
    }

    private function writeFile(string $path, string $content): void
    {
        // The writer owns the directory because it owns the write. Creating it here rather
        // than in the handler is what keeps queue mode off the filesystem entirely: a staged
        // change that is never applied must leave no empty directory tree behind.
        // `@mkdir`: the failure is handled below, so the warning PHP emits alongside it is pure
        // noise — and it is reachable in normal operation, not only in tests. When a plain file
        // occupies the directory's path, mkdir() both returns false AND warns `File exists`; the
        // return value is checked, the trailing is_dir() re-check absorbs a concurrent creation,
        // and either way the outcome becomes a typed exception. Matches EasterHandler's @mkdir.
        $folder = dirname($path);
        if (!is_dir($folder) && false === @mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new ServiceUnavailableException('Failed to create directory ' . $folder);
        }

        if (false === file_put_contents($path, $content, LOCK_EX)) {
            throw new ServiceUnavailableException('Failed to write to file ' . $path);
        }

        Utilities::invalidateJsonFileCache($path);
    }

    /**
     * A missing file is not an error: the previous handler code reached `unlink()`
     * only after its own existence checks, and re-deleting is idempotent from the
     * caller's point of view.
     */
    private function removeFile(string $path): void
    {
        if (false === file_exists($path)) {
            return;
        }

        if (false === unlink($path)) {
            throw new ServiceUnavailableException('Failed to delete file ' . $path);
        }

        Utilities::invalidateJsonFileCache($path);
    }
}
