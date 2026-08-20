<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;

/**
 * Run a closure against a deliberately unreadable source-data inventory.
 *
 * Every consumer of `CheckableInventory` has to survive one malformed calendar file, because
 * `Health` is a long-running ReactPHP process: a `Throwable` that escapes a lookup there does not
 * fail one check, it takes down schema resolution for every unrelated check — and, from inside
 * `onMessage()`, closes the client's connection mid-run. Each consumer contains that blast radius
 * with a retry against the inventory's static half, which cannot have been broken by a calendar
 * file because it never reads one.
 *
 * Untested error handling is how a fallback quietly stops falling back, so the fixture is shared
 * rather than reimplemented per consumer. It lives here because there are now two: the schema
 * resolver (`Health::retrieveSchemaForCategory()` / `::getPathToSchemaFile()`) and the
 * `validateSource` handler.
 */
trait BrokenInventoryTrait
{
    /**
     * Runs `$fn` with `CheckableInventory` pointed at a source tree containing one malformed
     * national calendar file, so that every inventory lookup throws.
     *
     * This is the real failure, not a simulated one: the inventory enumerates per-calendar items via
     * `CalendarMetadataProvider::create()`, which JSON-parses every national and diocesan calendar
     * file, so a single unparseable file makes `all()` — and therefore `byPath()` and `byId()` —
     * throw. Only the malformed file needs to exist: national calendars are built first, so the
     * build aborts before it looks for anything else.
     *
     * The memoized statics are reset on the way in *and* on the way out: in on so the poisoned tree
     * is actually read rather than a good index being served from an earlier test, out so the next
     * test rebuilds against the real tree.
     */
    private static function withBrokenInventory(callable $fn): void
    {
        $savedApiFilePath = Router::$apiFilePath;
        $root             = sys_get_temp_dir() . '/health-broken-inventory-' . getmypid() . '-' . uniqid() . '/';
        $nationFolder     = $root . JsonData::NATIONAL_CALENDARS_FOLDER->value . '/ZZ';

        self::assertTrue(mkdir($nationFolder, 0777, true), 'could not build the fixture source tree');
        file_put_contents($nationFolder . '/ZZ.json', '{ this is not JSON');

        Router::$apiFilePath = $root;
        CheckableInventory::reset();

        try {
            // Guard: if this ever stops throwing, the tests below would pass for the wrong reason.
            try {
                CheckableInventory::all();
                self::fail('the fixture tree no longer breaks the inventory — this test proves nothing');
            } catch (\JsonException) {
                // expected
            }

            $fn();
        } finally {
            Router::$apiFilePath = $savedApiFilePath;
            CheckableInventory::reset();
            @unlink($nationFolder . '/ZZ.json');
            self::removeDirectoryTree($root);
        }
    }

    private static function removeDirectoryTree(string $root): void
    {
        if (false === is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($root);
    }
}
