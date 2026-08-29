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
}
