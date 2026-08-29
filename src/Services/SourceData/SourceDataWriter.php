<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Services\ChangeResource;

/**
 * How a write handler puts source data somewhere.
 *
 * Two implementations exist: {@see DiskSourceDataWriter} writes files, which is
 * what a self-hosted deployment without Postgres and OpenFGA does and always
 * has; {@see ChangeRequestSourceDataWriter} records a reviewable proposal and
 * touches no files.
 *
 * Handlers stage each file and commit once per request, and never know which
 * implementation is behind the interface.
 */
interface SourceDataWriter
{
    /**
     * Record a file this request would write.
     *
     * @param string  $absolutePath The on-disk path the handler targets.
     * @param ?string $content      Null only for ChangeOperation::DELETE.
     */
    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void;

    /**
     * Apply everything staged so far, and describe what happened.
     *
     * @return array<string, mixed> Always carries a `disposition` key: `applied`
     *                              when the files are now on disk, `submitted` or
     *                              `approved` when a proposal was recorded.
     */
    public function commit(ChangeResource $resource): array;

    /**
     * What this submitter has in flight for `$absolutePath` that is not yet in the
     * repository, if anything.
     *
     * A handler that rebuilds an AGGREGATE file — one file holding many editable items,
     * such as the whole decree corpus in `decrees.json` — must start from this rather
     * than from disk, because in queue mode the submitter's previous edit never reached
     * disk and would be silently dropped by the next one.
     *
     * "Unpublished", not "pending": approval alone puts nothing on disk. Phase 1's approve
     * is a status `UPDATE` with no file I/O and no publisher behind it, so an approved
     * batch is exactly as absent from the repository as a submitted one and must keep
     * answering here. Rejected and withdrawn work must not.
     *
     * Null means "nothing in flight; read the file the way you always have", which is what
     * disk mode always answers.
     */
    public function unpublishedContent(string $absolutePath): ?string;

    /**
     * Which files beneath `$absoluteFolder` this submitter has in flight and not yet in
     * the repository.
     *
     * The companion to {@see unpublishedContent()} for handlers that enumerate a folder to
     * decide what to rebuild: a file that exists only as queued work is invisible to
     * `glob()` and would be dropped on the next submission. Empty in disk mode, where
     * every proposal is already a real file.
     *
     * @return list<string> Absolute paths, ascending.
     */
    public function unpublishedPathsUnder(string $absoluteFolder): array;
}
