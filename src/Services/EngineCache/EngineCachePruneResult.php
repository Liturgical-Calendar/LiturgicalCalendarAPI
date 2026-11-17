<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\EngineCache;

/**
 * What one pass of {@see EngineCachePruner} did, or in a dry run would have done.
 */
final class EngineCachePruneResult
{
    public function __construct(
        /** Regular files deleted (or, in a dry run, that would be). */
        public readonly int $deletedFiles = 0,
        /** Their total size, from the scan. */
        public readonly int $freedBytes = 0,
        /** Directories removed because the pass left them empty. */
        public readonly int $removedDirectories = 0,
        /** Regular files young enough to keep. */
        public readonly int $keptFiles = 0,
        /** Entries that disappeared between being listed and being handled — another worker got there first. */
        public readonly int $vanished = 0,
        /** Symbolic links and other non-regular entries, left alone and never followed. */
        public readonly int $skipped = 0,
        /** Files that should have been deleted and could not be. */
        public readonly int $failed = 0,
        /** Whether the pass was asked to stop before it had walked the whole tree. */
        public readonly bool $stoppedEarly = false
    ) {
    }

    /** One key=value line, for the job's output and log. */
    public function summary(int $maxAgeDays, bool $dryRun): string
    {
        return sprintf(
            'engine-cache-prune%s max_age_days=%d deleted_files=%d freed_bytes=%d removed_dirs=%d kept_files=%d vanished=%d skipped=%d failed=%d stopped_early=%s',
            $dryRun ? ' (dry run, nothing deleted)' : '',
            $maxAgeDays,
            $this->deletedFiles,
            $this->freedBytes,
            $this->removedDirectories,
            $this->keptFiles,
            $this->vanished,
            $this->skipped,
            $this->failed,
            $this->stoppedEarly ? 'true' : 'false'
        );
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'deleted_files' => $this->deletedFiles,
            'freed_bytes'   => $this->freedBytes,
            'removed_dirs'  => $this->removedDirectories,
            'kept_files'    => $this->keptFiles,
            'vanished'      => $this->vanished,
            'skipped'       => $this->skipped,
            'failed'        => $this->failed,
            'stopped_early' => $this->stoppedEarly,
        ];
    }
}
