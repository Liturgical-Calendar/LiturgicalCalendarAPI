<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\EngineCache;

use LiturgicalCalendar\Api\Services\EngineCache\EngineCachePruner;
use LiturgicalCalendar\Api\Services\EngineCache\EngineCachePruneResult;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pruner against real temporary trees whose mtimes are set with touch(): what it deletes, what it leaves,
 * and that it never reaches outside the cache through a symbolic link.
 */
#[CoversClass(EngineCachePruner::class)]
#[CoversClass(EngineCachePruneResult::class)]
final class EngineCachePrunerTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DAY = 86400;

    private string $base;
    private string $cache;
    private string $outside;

    protected function setUp(): void
    {
        $this->base    = sys_get_temp_dir() . '/ecp_' . uniqid('', true);
        $this->cache   = $this->base . '/public/engineCache';
        $this->outside = $this->base . '/outside';
        mkdir($this->cache, 0777, true);
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                self::removeTree($path);
            }
        }
        @rmdir($dir);
    }

    /** Writes a file whose mtime is `$ageDays` days before NOW. */
    private function file(string $relative, int $ageDays, string $contents = 'x', ?string $root = null): string
    {
        $path = ( $root ?? $this->cache ) . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        touch($path, self::NOW - $ageDays * self::DAY);

        return $path;
    }

    /** @param list<string> $keep */
    private function pruner(int $maxAgeDays = 30, array $keep = [], ?string $root = null): EngineCachePruner
    {
        return new EngineCachePruner($root ?? $this->cache, $maxAgeDays, $keep, static fn (): int => self::NOW);
    }

    public function testDeletesOnlyFilesOlderThanTheMaximumAge(): void
    {
        $old    = $this->file('v5_7-aaaaaaaaaaaa/old.json', 31, str_repeat('a', 100));
        $older  = $this->file('easter/en.json', 400, str_repeat('b', 23));
        $fresh  = $this->file('v5_7-bbbbbbbbbbbb/fresh.json', 29);
        $recent = $this->file('v5_7-aaaaaaaaaaaa/recent.json', 1);

        $result = $this->pruner()->prune();

        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($older);
        self::assertFileExists($fresh);
        self::assertFileExists($recent);
        self::assertSame(2, $result->deletedFiles);
        self::assertSame(123, $result->freedBytes);
        self::assertSame(2, $result->keptFiles);
        self::assertSame(0, $result->failed);
    }

    public function testAFileExactlyAtTheCutoffIsKept(): void
    {
        $edge = $this->file('v5_7-aaaaaaaaaaaa/edge.json', 30);

        $result = $this->pruner()->prune();

        self::assertFileExists($edge);
        self::assertSame(0, $result->deletedFiles);
    }

    public function testRemovesDirectoriesLeftEmptyButNeverTheRoot(): void
    {
        $this->file('v5_6-aaaaaaaaaaaa/a.json', 90);
        $this->file('v5_6-aaaaaaaaaaaa/nested/deeper/b.json', 90);
        mkdir($this->cache . '/already-empty');
        $this->file('v5_7-bbbbbbbbbbbb/old.json', 90);
        $this->file('v5_7-bbbbbbbbbbbb/fresh.json', 2);

        $result = $this->pruner()->prune();

        self::assertDirectoryDoesNotExist($this->cache . '/v5_6-aaaaaaaaaaaa');
        self::assertDirectoryDoesNotExist($this->cache . '/already-empty');
        self::assertDirectoryExists($this->cache . '/v5_7-bbbbbbbbbbbb', 'a directory with a fresh file stays');
        self::assertSame(4, $result->removedDirectories, 'v5_6, nested, deeper, already-empty');
        self::assertDirectoryExists($this->cache, 'the cache root is never removed');
        self::assertSame(['.', '..', 'v5_7-bbbbbbbbbbbb'], scandir($this->cache));
    }

    public function testTheRootSurvivesEvenWhenEverythingInItExpired(): void
    {
        $this->file('v5_6-aaaaaaaaaaaa/a.json', 90);

        $this->pruner()->prune();

        self::assertDirectoryExists($this->cache);
        self::assertSame(['.', '..'], scandir($this->cache));
    }

    public function testKeptDirectoriesStayEvenWhenEmpty(): void
    {
        $this->file('v5_7-current/old.json', 90);
        $this->file('easter/en.json', 90);
        $this->file('v5_6-stale/old.json', 90);

        $result = $this->pruner(30, ['v5_7-current', '/easter/'])->prune();

        self::assertDirectoryExists($this->cache . '/v5_7-current');
        self::assertDirectoryExists($this->cache . '/easter');
        self::assertDirectoryDoesNotExist($this->cache . '/v5_6-stale');
        self::assertSame(3, $result->deletedFiles);
        self::assertSame(1, $result->removedDirectories);
    }

    public function testNeverFollowsOrDeletesThroughASymlinkInsideTheCache(): void
    {
        $victimFile = $this->file('victim.json', 400, 'keep me', $this->outside);
        $victimDir  = $this->file('dir/victim.json', 400, 'keep me too', $this->outside);
        $this->file('v5_7-aaaaaaaaaaaa/old.json', 90);
        mkdir($this->cache . '/links');
        if (!@symlink($victimFile, $this->cache . '/links/file-link') || !@symlink(dirname($victimDir), $this->cache . '/links/dir-link')) {
            self::markTestSkipped('This filesystem does not support symbolic links.');
        }
        // An old link: its own mtime must not make it a candidate either.
        @touch($this->cache . '/links/file-link', self::NOW - 400 * self::DAY);

        $result = $this->pruner()->prune();

        self::assertFileExists($victimFile);
        self::assertFileExists($victimDir);
        self::assertSame('keep me', file_get_contents($victimFile));
        self::assertTrue(is_link($this->cache . '/links/file-link'), 'the link itself is left in place');
        self::assertTrue(is_link($this->cache . '/links/dir-link'));
        self::assertDirectoryExists($this->cache . '/links', 'a directory holding links is not empty');
        self::assertSame(2, $result->skipped);
        self::assertSame(1, $result->deletedFiles);
    }

    /**
     * A directory swapped for a link after it was listed: the re-check before unlink() sees that the directory no
     * longer resolves to the path walked, and skips instead of deleting through the link.
     */
    public function testADirectorySwappedForALinkMidRunIsNotDeletedThrough(): void
    {
        $this->file('v5_7-abc/a.json', 400);
        $this->file('v5_7-abc/b.json', 400);
        $victimA = $this->file('victims/a.json', 400, 'x', $this->outside);
        $victimB = $this->file('victims/b.json', 400, 'x', $this->outside);
        $dir     = $this->cache . '/v5_7-abc';
        $calls   = 0;
        $swapped = false;

        $result = $this->pruner()->prune(false, static function () use (&$calls, &$swapped, $dir, $victimA): bool {
            // Call 1 is for `v5_7-abc` in the root; call 2 is for its first entry, after it has been listed.
            if (++$calls === 2) {
                rename($dir, $dir . '-moved');
                $swapped = @symlink(dirname($victimA), $dir);
            }

            return false;
        });

        if (!$swapped) {
            self::markTestSkipped('symlink() is not available here.');
        }
        self::assertFileExists($victimA, 'deleted through a directory swapped for a link');
        self::assertFileExists($victimB, 'deleted through a directory swapped for a link');
        self::assertSame(0, $result->deletedFiles);
        self::assertGreaterThan(0, $result->skipped);
    }

    public function testRefusesACacheRootThatIsASymlink(): void
    {
        $this->file('target-cache/old.json', 400, 'x', $this->outside);
        $link = $this->base . '/linked-cache';
        if (!@symlink($this->outside . '/target-cache', $link)) {
            self::markTestSkipped('This filesystem does not support symbolic links.');
        }

        $pruner = $this->pruner(30, [], $link);
        self::assertNotNull($pruner->refusalReason());
        self::assertStringContainsString('symbolic link', (string) $pruner->refusalReason());

        try {
            $pruner->prune();
            self::fail('pruned through a symlinked root');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }
        self::assertFileExists($this->outside . '/target-cache/old.json');
    }

    public function testRefusesACacheRootThatIsAFile(): void
    {
        $path = $this->base . '/not-a-dir';
        file_put_contents($path, 'x');

        self::assertStringContainsString('not a directory', (string) $this->pruner(30, [], $path)->refusalReason());
    }

    public function testAMissingCacheIsNothingToDo(): void
    {
        $pruner = $this->pruner(30, [], $this->base . '/does-not-exist');

        self::assertNull($pruner->refusalReason());
        $result = $pruner->prune();
        self::assertSame(0, $result->deletedFiles);
        self::assertSame(0, $result->failed);
    }

    public function testZeroDisablesPruning(): void
    {
        $old    = $this->file('v5_6-aaaaaaaaaaaa/a.json', 4000);
        $pruner = $this->pruner(0);

        self::assertFalse($pruner->isEnabled());
        $result = $pruner->prune();

        self::assertFileExists($old);
        self::assertSame(0, $result->deletedFiles);
        self::assertSame(0, $result->removedDirectories);
    }

    public function testANegativeAgeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EngineCachePruner($this->cache, -1);
    }

    public function testADryRunReportsButDeletesNothing(): void
    {
        $old = $this->file('v5_6-aaaaaaaaaaaa/a.json', 90, '12345');
        $this->file('v5_7-bbbbbbbbbbbb/fresh.json', 1);

        $result = $this->pruner()->prune(true);

        self::assertFileExists($old);
        self::assertDirectoryExists($this->cache . '/v5_6-aaaaaaaaaaaa');
        self::assertSame(1, $result->deletedFiles);
        self::assertSame(5, $result->freedBytes);
        self::assertSame(1, $result->removedDirectories, 'the directory it would leave empty');
        self::assertStringContainsString('dry run', $result->summary(30, true));
    }

    public function testStopsWhenAskedAndLeavesTheRestForTheNextRun(): void
    {
        $this->file('a/1.json', 90);
        $this->file('a/2.json', 90);
        $this->file('b/3.json', 90);
        $calls = 0;

        $result = $this->pruner()->prune(false, static function () use (&$calls): bool {
            return ++$calls > 2;
        });

        self::assertTrue($result->stoppedEarly);
        self::assertSame(1, $result->deletedFiles);
        self::assertFileExists($this->cache . '/a/2.json');
        self::assertFileExists($this->cache . '/b/3.json');
        self::assertDirectoryExists($this->cache . '/a', 'a stopped pass removes no directory');
    }

    public function testAFileThatVanishesMidScanIsNotAFailure(): void
    {
        $this->file('v5_6-aaaaaaaaaaaa/a.json', 90);
        $doomed = $this->file('v5_6-aaaaaaaaaaaa/b.json', 90);
        $first  = true;

        // The stop check runs before every entry, which makes it a seam to delete a listed file under the walk.
        $result = $this->pruner()->prune(false, static function () use (&$first, $doomed): bool {
            if ($first) {
                $first = false;
            } else {
                @unlink($doomed);
            }

            return false;
        });

        self::assertSame(0, $result->failed);
        self::assertGreaterThanOrEqual(1, $result->vanished);
        self::assertDirectoryDoesNotExist($this->cache . '/v5_6-aaaaaaaaaaaa');
    }

    public function testAFileThatCannotBeDeletedIsCountedAndLogged(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can delete from a read-only directory.');
        }
        $locked = $this->file('locked/old.json', 90);
        chmod(dirname($locked), 0555);
        $logger = new RecordingLogger();
        try {
            $result = $this->pruner()->prune(false, null, $logger);
        } finally {
            chmod(dirname($locked), 0777);
        }

        self::assertFileExists($locked);
        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->deletedFiles);
        self::assertSame(1, $logger->count('Could not delete an engine cache file.'));
        self::assertDirectoryExists(dirname($locked));
    }

    public function testTheSummaryIsOneKeyValueLine(): void
    {
        $line = ( new EngineCachePruneResult(3, 4096, 1, 7, 0, 2, 0) )->summary(30, false);

        self::assertSame(
            'engine-cache-prune max_age_days=30 deleted_files=3 freed_bytes=4096 removed_dirs=1 kept_files=7 vanished=0 skipped=2 failed=0 stopped_early=false',
            $line
        );
    }
}
