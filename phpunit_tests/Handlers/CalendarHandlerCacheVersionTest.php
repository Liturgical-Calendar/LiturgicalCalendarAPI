<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the engine-cache data-version digest that keys the calendar
 * cache path. It must be deterministic, change when the source JSON or a
 * compiled translation (.mo) changes, ignore unrelated files, and be stable
 * across deploy locations (it is keyed by path-relative-to-root).
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarHandlerCacheVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'litcal-cacheversion-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
        $this->seed($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree(rtrim($this->root, DIRECTORY_SEPARATOR));
    }

    /**
     * Lay down a representative source-data + i18n tree under $root.
     */
    private function seed(string $root): void
    {
        mkdir($root . 'jsondata/sourcedata/nested', 0777, true);
        mkdir($root . 'i18n/it/LC_MESSAGES', 0777, true);
        file_put_contents($root . 'jsondata/sourcedata/calendar.json', '{"a":1}');
        file_put_contents($root . 'jsondata/sourcedata/nested/missal.json', '{"b":2}');
        file_put_contents($root . 'i18n/it/LC_MESSAGES/litcal.mo', 'MO-IT-v1');
    }

    private function versionFor(string $root): string
    {
        return CalendarHandler::computeEngineCacheDataVersion(
            [
                $root . 'jsondata/sourcedata' => 'json',
                $root . 'i18n'                => 'mo',
            ],
            $root
        );
    }

    private function version(): string
    {
        return $this->versionFor($this->root);
    }

    public function testReturnsTwelveHexAndIsDeterministic(): void
    {
        $version = $this->version();
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
        self::assertSame($version, $this->version());
    }

    public function testChangesWhenSourceJsonChanges(): void
    {
        $before = $this->version();
        file_put_contents($this->root . 'jsondata/sourcedata/calendar.json', '{"a":999}');
        self::assertNotSame($before, $this->version());
    }

    public function testChangesWhenTranslationChanges(): void
    {
        $before = $this->version();
        file_put_contents($this->root . 'i18n/it/LC_MESSAGES/litcal.mo', 'MO-IT-v2');
        self::assertNotSame($before, $this->version());
    }

    public function testChangesWhenFileAddedThenRestoredWhenRemoved(): void
    {
        $before = $this->version();
        file_put_contents($this->root . 'jsondata/sourcedata/extra.json', '{}');
        self::assertNotSame($before, $this->version());

        unlink($this->root . 'jsondata/sourcedata/extra.json');
        self::assertSame($before, $this->version());
    }

    public function testIgnoresUnrelatedExtensions(): void
    {
        $before = $this->version();
        file_put_contents($this->root . 'jsondata/sourcedata/notes.txt', 'ignored');
        file_put_contents($this->root . 'i18n/it/LC_MESSAGES/litcal.po', 'ignored source catalog');
        self::assertSame($before, $this->version());
    }

    public function testStableAcrossRootRelocation(): void
    {
        $other = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'litcal-cacheversion-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
        $this->seed($other);
        try {
            self::assertSame($this->version(), $this->versionFor($other));
        } finally {
            $this->removeTree(rtrim($other, DIRECTORY_SEPARATOR));
        }
    }

    private function removeTree(string $dir): void
    {
        if (false === is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if (false === ( $fileInfo instanceof \SplFileInfo )) {
                continue;
            }
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }
        rmdir($dir);
    }
}
