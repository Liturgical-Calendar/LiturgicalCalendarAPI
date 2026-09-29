<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\SourceTreeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceTreeGuard::class)]
final class SourceTreeGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/stg_' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $nationDir) {
            @rmdir($nationDir);
        }
        @rmdir($this->dir);
    }

    public function testAnEmptyFolderIsRefused(): void
    {
        $reason = ( new SourceTreeGuard($this->dir) )->refusalReason();

        self::assertNotNull($reason);
        self::assertStringStartsWith('No national calendar files found in', $reason);
    }

    public function testAMissingFolderIsRefused(): void
    {
        self::assertNotNull(( new SourceTreeGuard($this->dir . '/nope') )->refusalReason());
    }

    /** A folder whose `{N}.json` is missing is a partial tree, not a calendar. */
    public function testFoldersWithoutTheirFileAreRefused(): void
    {
        mkdir($this->dir . '/IT');

        self::assertNotNull(( new SourceTreeGuard($this->dir) )->refusalReason());
    }

    public function testOneNationalCalendarFileIsEnough(): void
    {
        mkdir($this->dir . '/IT');
        mkdir($this->dir . '/XX');
        file_put_contents($this->dir . '/IT/IT.json', '{}');

        self::assertNull(( new SourceTreeGuard($this->dir) )->refusalReason());
    }

    public function testTheDefaultIsTheRealNationsFolder(): void
    {
        Router::getApiPaths();

        self::assertNull(( new SourceTreeGuard() )->refusalReason());
    }
}
