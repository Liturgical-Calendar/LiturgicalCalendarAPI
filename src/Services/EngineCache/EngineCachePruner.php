<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\EngineCache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Deletes engine-cache files older than a maximum age, then removes the directories that leaves empty.
 *
 * Every entry of the engine cache is regenerated on demand, so deleting one costs at most the time to compute it
 * again. CalendarHandler already removes sibling version directories when it creates a new one, but only on the
 * request that creates it, and only for directories that process can delete; nothing ever removed old entries of
 * the current version, nor the Easter cache. This is the backstop.
 *
 * Defensive by construction:
 *
 * - the walk uses `lstat()` and never follows a symbolic link — a link is left in place and counted as skipped,
 *   whatever it points at;
 * - before descending into a directory, and again before every `unlink()` and `rmdir()`, it checks that
 *   `realpath()` of the containing directory is still exactly the path it walked, so a directory swapped for a link
 *   after it was listed is detected and skipped rather than deleted through.
 *
 *   This narrows the window but does not close it: PHP has no `unlinkat()`/`O_NOFOLLOW` directory handles, so a
 *   process that can write to the cache directory and swaps a directory for a link in the instant between that
 *   check and the deletion could still steer it. Only the web user can write there, and anyone who can is
 *   already able to make the API serve arbitrary content, so this is not a privilege boundary — but the
 *   traversal is not claimed to be race-free;
 * - only regular files are deleted; the cache root itself, and the directories in `$keep`, are never removed;
 * - an entry that disappears mid-scan (another worker, or CalendarHandler's own version pruning) is counted as
 *   `vanished`, not as a failure; a directory that gained a file since it was listed simply fails `rmdir()`.
 */
final class EngineCachePruner
{
    private const S_IFMT  = 0o170000;
    private const S_IFREG = 0o100000;
    private const S_IFDIR = 0o040000;
    private const S_IFLNK = 0o120000;

    /** At most this many failed deletions are logged one by one; the summary still counts them all. */
    private const MAX_LOGGED_FAILURES = 10;

    /** @var array<string, true> */
    private readonly array $keep;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private string $rootReal = '';
    private int $cutoff      = 0;
    private bool $dryRun     = false;

    /** @var \Closure(): bool */
    private \Closure $shouldStop;

    private LoggerInterface $logger;

    private int $deletedFiles       = 0;
    private int $freedBytes         = 0;
    private int $removedDirectories = 0;
    private int $keptFiles          = 0;
    private int $vanished           = 0;
    private int $skipped            = 0;
    private int $failed             = 0;
    private bool $stoppedEarly      = false;

    /**
     * @param string               $root       The cache directory (see {@see EngineCache::directory()}).
     * @param int                  $maxAgeDays Files whose mtime is more than this many days old are deleted; 0 disables pruning.
     * @param list<string>         $keep       Directories, relative to `$root`, never removed even when empty.
     * @param (\Closure(): int)|null $clock      The current Unix time; `time()` when null.
     */
    public function __construct(
        private readonly string $root,
        private readonly int $maxAgeDays,
        array $keep = [],
        ?\Closure $clock = null
    ) {
        if ($maxAgeDays < 0) {
            throw new \InvalidArgumentException('The maximum age must not be negative.');
        }
        $normalized = [];
        foreach ($keep as $relative) {
            $relative = trim(str_replace('\\', '/', $relative), '/');
            if ($relative !== '') {
                $normalized[$relative] = true;
            }
        }
        $this->keep       = $normalized;
        $this->clock      = $clock ?? static fn (): int => time();
        $this->shouldStop = static fn (): bool => false;
        $this->logger     = new NullLogger();
    }

    public function isEnabled(): bool
    {
        return $this->maxAgeDays > 0;
    }

    public function maxAgeDays(): int
    {
        return $this->maxAgeDays;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Null when it is safe to prune (including when there is no cache yet); otherwise why it is not.
     *
     * A cache root that is itself a symbolic link is refused rather than followed: the pruner's promise is that it
     * never deletes through a link, and it has no way to know where an operator meant one to lead.
     */
    public function refusalReason(): ?string
    {
        clearstatcache(true, $this->root);
        $stat = @lstat($this->root);
        if ($stat === false) {
            return null;
        }
        $type = $stat['mode'] & self::S_IFMT;
        if ($type === self::S_IFLNK) {
            return "The engine cache path {$this->root} is a symbolic link; refusing to prune through it.";
        }
        if ($type !== self::S_IFDIR) {
            return "The engine cache path {$this->root} exists but is not a directory.";
        }

        return null;
    }

    /**
     * One pass over the cache.
     *
     * @param (\Closure(): bool)|null $shouldStop Checked before every entry; when it returns true the pass stops,
     *                                            leaving the rest for the next run.
     * @param LoggerInterface|null    $logger     Where a file that cannot be deleted is reported.
     * @throws \RuntimeException When the cache root cannot be pruned at all (see {@see self::refusalReason()}) or listed.
     */
    public function prune(bool $dryRun = false, ?\Closure $shouldStop = null, ?LoggerInterface $logger = null): EngineCachePruneResult
    {
        $this->reset();
        if (!$this->isEnabled()) {
            return new EngineCachePruneResult();
        }
        $refusal = $this->refusalReason();
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }
        if (@lstat($this->root) === false) {
            return new EngineCachePruneResult();
        }
        $rootReal = realpath($this->root);
        if ($rootReal === false) {
            return new EngineCachePruneResult();
        }

        $this->rootReal   = $rootReal;
        $this->dryRun     = $dryRun;
        $this->cutoff     = ( $this->clock )() - $this->maxAgeDays * 86400;
        $this->shouldStop = $shouldStop ?? static fn (): bool => false;
        $this->logger     = $logger ?? new NullLogger();

        $entries = @scandir($rootReal);
        if ($entries === false) {
            throw new \RuntimeException("Cannot list the engine cache directory {$rootReal}.");
        }
        $this->walk($rootReal, '', $entries);

        return new EngineCachePruneResult(
            $this->deletedFiles,
            $this->freedBytes,
            $this->removedDirectories,
            $this->keptFiles,
            $this->vanished,
            $this->skipped,
            $this->failed,
            $this->stoppedEarly
        );
    }

    private function reset(): void
    {
        $this->deletedFiles       = 0;
        $this->freedBytes         = 0;
        $this->removedDirectories = 0;
        $this->keptFiles          = 0;
        $this->vanished           = 0;
        $this->skipped            = 0;
        $this->failed             = 0;
        $this->stoppedEarly       = false;
    }

    /**
     * Prunes one directory's entries and returns how many of them remain (in a dry run, would remain).
     *
     * @param list<string> $entries The directory's listing, from scandir().
     */
    private function walk(string $dir, string $relative, array $entries): int
    {
        $remaining = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($this->stoppedEarly || ( $this->shouldStop )()) {
                $this->stoppedEarly = true;
                ++$remaining;
                continue;
            }

            $path     = $dir . DIRECTORY_SEPARATOR . $name;
            $childRel = $relative === '' ? $name : $relative . '/' . $name;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) {
                ++$this->vanished;
                continue;
            }

            $type = $stat['mode'] & self::S_IFMT;
            if ($type === self::S_IFREG) {
                $remaining += $this->pruneFile($dir, $path, (int) $stat['mtime'], (int) $stat['size']);
            } elseif ($type === self::S_IFDIR) {
                $remaining += $this->pruneDirectory($path, $childRel);
            } else {
                // A symbolic link (never followed, never deleted), a socket, a FIFO: not ours to touch.
                ++$this->skipped;
                ++$remaining;
            }
        }

        return $remaining;
    }

    /**
     * Whether `$dir` — always built from the real cache root plus real entry names — still resolves to itself, i.e.
     * none of its components has been swapped for a symbolic link since it was walked.
     */
    private function stillUnswapped(string $dir): bool
    {
        clearstatcache(true, $dir);

        return realpath($dir) === $dir;
    }

    /** @return int 1 when the file remains, 0 when it is gone. */
    private function pruneFile(string $dir, string $path, int $mtime, int $size): int
    {
        if ($mtime >= $this->cutoff) {
            ++$this->keptFiles;

            return 1;
        }
        if ($this->dryRun) {
            ++$this->deletedFiles;
            $this->freedBytes += $size;

            return 0;
        }
        if (!$this->stillUnswapped($dir)) {
            ++$this->skipped;

            return 1;
        }
        if (@unlink($path)) {
            ++$this->deletedFiles;
            $this->freedBytes += $size;

            return 0;
        }
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            ++$this->vanished;

            return 0;
        }
        ++$this->failed;
        if ($this->failed <= self::MAX_LOGGED_FAILURES) {
            $error = error_get_last();
            $this->logger->warning('Could not delete an engine cache file.', [
                'path'    => $path,
                'message' => $error['message'] ?? 'unknown error',
            ]);
        }

        return 1;
    }

    /** @return int 1 when the directory remains, 0 when it is gone (or, in a dry run, would be). */
    private function pruneDirectory(string $path, string $relative): int
    {
        // Descend only into a directory that still resolves to itself inside the cache root: if it was swapped for
        // a link since lstat(), scandir() would follow the link.
        $real = realpath($path);
        if ($real === false) {
            ++$this->vanished;

            return 0;
        }
        if ($real !== $this->rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) {
            ++$this->skipped;

            return 1;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            clearstatcache(true, $path);
            if (@lstat($path) === false) {
                ++$this->vanished;

                return 0;
            }
            ++$this->failed;
            $this->logger->warning('Could not list an engine cache directory.', ['path' => $path]);

            return 1;
        }

        $left = $this->walk($path, $relative, $entries);
        if ($left > 0 || $this->stoppedEarly || isset($this->keep[$relative])) {
            return 1;
        }
        if ($this->dryRun) {
            ++$this->removedDirectories;

            return 0;
        }
        // rmdir() only ever removes an empty directory, so a file a request wrote since the listing makes it fail
        // harmlessly, and the directory stays for the next run.
        if (!$this->stillUnswapped($path)) {
            ++$this->skipped;

            return 1;
        }
        if (@rmdir($path)) {
            ++$this->removedDirectories;

            return 0;
        }
        clearstatcache(true, $path);

        return @lstat($path) === false ? 0 : 1;
    }
}
