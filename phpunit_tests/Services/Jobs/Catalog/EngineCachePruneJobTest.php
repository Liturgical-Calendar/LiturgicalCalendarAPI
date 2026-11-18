<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\EngineCache\EngineCachePruner;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\EngineCachePruneJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineCachePruneJob::class)]
final class EngineCachePruneJobTest extends TestCase
{
    /** @var list<string> */
    private array $said = [];

    private RecordingLogger $logger;

    private string $base;

    private string $cache;

    protected function setUp(): void
    {
        $this->said   = [];
        $this->logger = new RecordingLogger();
        $this->base   = sys_get_temp_dir() . '/ecpj_' . uniqid('', true);
        $this->cache  = $this->base . '/engineCache';
        mkdir($this->cache . '/v5_6-old', 0777, true);
        file_put_contents($this->cache . '/v5_6-old/a.json', 'abc');
        touch($this->cache . '/v5_6-old/a.json', time() - 90 * 86400);
    }

    protected function tearDown(): void
    {
        @unlink($this->cache . '/v5_6-old/a.json');
        @rmdir($this->cache . '/v5_6-old');
        @unlink($this->cache);
        @rmdir($this->cache);
        @rmdir($this->base . '/real');
        @rmdir($this->base);
    }

    private function context(bool $dryRun = false): JobContext
    {
        return new JobContext($dryRun, static fn (): bool => false, $this->logger, function (string $line): void {
            $this->said[] = $line;
        });
    }

    public function testItPrunesAndReportsOneSummaryLine(): void
    {
        $job = new EngineCachePruneJob(new EngineCachePruner($this->cache, 30));

        self::assertNull($job->preflight());
        $job->run($this->context());

        self::assertFileDoesNotExist($this->cache . '/v5_6-old/a.json');
        self::assertDirectoryDoesNotExist($this->cache . '/v5_6-old');
        self::assertCount(1, $this->said);
        self::assertStringStartsWith('engine-cache-prune max_age_days=30 deleted_files=1 freed_bytes=3 removed_dirs=1', $this->said[0]);
        self::assertSame(1, $this->logger->count('Engine cache pruned.'));
    }

    public function testADryRunDeletesNothing(): void
    {
        $job = new EngineCachePruneJob(new EngineCachePruner($this->cache, 30));

        $job->run($this->context(true));

        self::assertFileExists($this->cache . '/v5_6-old/a.json');
        self::assertStringContainsString('(dry run, nothing deleted)', $this->said[0]);
        self::assertStringContainsString('deleted_files=1', $this->said[0]);
    }

    public function testZeroDisablesTheJobWithoutTouchingTheCache(): void
    {
        $job = new EngineCachePruneJob(new EngineCachePruner($this->cache, 0));

        self::assertNull($job->preflight());
        $job->run($this->context());

        self::assertFileExists($this->cache . '/v5_6-old/a.json');
        self::assertSame(['engine-cache-prune disabled (ENGINE_CACHE_MAX_AGE_DAYS=0); nothing deleted'], $this->said);
    }

    public function testThePreflightRefusesASymlinkedRoot(): void
    {
        $link = $this->base . '/link';
        mkdir($this->base . '/real');
        if (!@symlink($this->base . '/real', $link)) {
            self::markTestSkipped('This filesystem does not support symbolic links.');
        }
        try {
            $job = new EngineCachePruneJob(new EngineCachePruner($link, 30));
            self::assertStringContainsString('symbolic link', (string) $job->preflight());
        } finally {
            @unlink($link);
        }
    }

    public function testFailedDeletionsFailTheRun(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can delete from a read-only directory.');
        }
        chmod($this->cache . '/v5_6-old', 0555);
        try {
            $job = new EngineCachePruneJob(new EngineCachePruner($this->cache, 30));
            $job->run($this->context());
            self::fail('a run that could not delete an expired file succeeded');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('1 expired engine cache entry could not be deleted', $e->getMessage());
            self::assertStringContainsString('failed=1', $this->said[0] ?? '', 'the summary is written before the failure');
        } finally {
            chmod($this->cache . '/v5_6-old', 0777);
        }
    }
}
