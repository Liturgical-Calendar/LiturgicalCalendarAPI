<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Source data that exists on disk but appears in no inventory entry.
 *
 * This is the failure #800 was: the Ambrosian temporale was present and unvalidatable, because
 * nothing listed it. Divergence between the data and the list that describes it was silent, and
 * stayed silent until someone went looking. Here it is a red test.
 *
 * Only this direction is asserted. An inventory entry with nothing on disk is deliberately NOT a
 * failure here: that is what the `exists` step reports at check time, and asserting it would stop
 * the inventory from advertising data a given deployment is missing — reintroducing the same
 * blindness from the other side.
 */
#[CoversClass(CheckableInventory::class)]
final class InventoryDriftTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /** @return list<string> every path the inventory claims to cover */
    private static function coveredPaths(): array
    {
        return array_map(
            static fn (CheckableItem $i): string => rtrim($i->path, '/'),
            CheckableInventory::all()
        );
    }

    public function testEveryMissalDataFileAndI18nFolderIsCovered(): void
    {
        $covered = self::coveredPaths();
        $root    = rtrim(JsonData::SOURCEDATA_FOLDER->path(), '/');

        $missalDirs = glob($root . '/rite/*/missals/*', GLOB_ONLYDIR);
        self::assertNotEmpty($missalDirs, 'no missal directories found — is the glob root right?');

        foreach ($missalDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::assertContains(
                    $file,
                    $covered,
                    "source data with no inventory entry: {$file} — add it to CheckableInventory"
                );
            }
            if (is_dir($dir . '/i18n')) {
                self::assertContains(
                    $dir . '/i18n',
                    $covered,
                    "i18n folder with no inventory entry: {$dir}/i18n — add it to CheckableInventory"
                );
            }
        }
    }

    public function testEveryDecreesDataFileAndI18nFolderIsCovered(): void
    {
        $covered = self::coveredPaths();
        $root    = rtrim(JsonData::SOURCEDATA_FOLDER->path(), '/');

        $decreeDirs = glob($root . '/rite/*/decrees', GLOB_ONLYDIR);
        self::assertNotEmpty($decreeDirs, 'no decrees directories found — is the glob root right?');

        foreach ($decreeDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::assertContains($file, $covered, "source data with no inventory entry: {$file}");
            }
            if (is_dir($dir . '/i18n')) {
                self::assertContains($dir . '/i18n', $covered, "i18n folder with no inventory entry: {$dir}/i18n");
            }
        }
    }
}
