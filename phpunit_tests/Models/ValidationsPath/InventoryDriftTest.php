<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Source data that exists on disk but appears in no inventory entry — for the directories this
 * test walks.
 *
 * This is the failure #800 was: the Ambrosian temporale was present and unvalidatable, because
 * nothing listed it. Divergence between the data and the list that describes it was silent, and
 * stayed silent until someone went looking. Here it is a red test.
 *
 * Only this direction is asserted. An inventory entry with nothing on disk is deliberately NOT a
 * failure here: that is what the `exists` step reports at check time, and asserting it would stop
 * the inventory from advertising data a given deployment is missing — reintroducing the same
 * blindness from the other side.
 *
 * Coverage is not exhaustive. This test walks only the `missals` and `decrees` directories under
 * each `rite`. The `calendars` tree under each rite, and the `lectionary` tree under `roman`, are
 * outside `CheckableInventory`'s scope entirely and are never visited here. Within the directories
 * that are walked, only top-level JSON files and an `i18n` subfolder are checked — a `lectionary`
 * subfolder nested inside a missal or decrees directory is not covered either.
 */
#[CoversClass(CheckableInventory::class)]
final class InventoryDriftTest extends TestCase
{
    private static string $savedApiPath = '';

    public static function setUpBeforeClass(): void
    {
        // JsonData cases build filesystem paths from this prefix.
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        // CalendarMetadataProvider::create() (pulled in via CheckableInventory::nationalCalendarItems()
        // and ::widerRegionItems(), now exercised by every test in this class) reads Router::$apiPath
        // while building each wider region's api_path. isset() is false for typed-uninitialised
        // properties, so save whatever was there before falling back to an initialised default.
        self::$savedApiPath = isset(Router::$apiPath) ? Router::$apiPath : '';
        Router::$apiPath    = '';
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
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

        $i18nDirsFound = 0;
        foreach ($missalDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::assertContains(
                    $file,
                    $covered,
                    "source data with no inventory entry: {$file} — add it to CheckableInventory"
                );
            }
            if (is_dir($dir . '/i18n')) {
                ++$i18nDirsFound;
                self::assertContains(
                    $dir . '/i18n',
                    $covered,
                    "i18n folder with no inventory entry: {$dir}/i18n — add it to CheckableInventory"
                );
            }
        }

        self::assertGreaterThan(
            0,
            $i18nDirsFound,
            'no i18n directories found under any missal; if the convention moved, this test has stopped checking translations'
        );
    }

    public function testEveryDecreesDataFileAndI18nFolderIsCovered(): void
    {
        $covered = self::coveredPaths();
        $root    = rtrim(JsonData::SOURCEDATA_FOLDER->path(), '/');

        $decreeDirs = glob($root . '/rite/*/decrees', GLOB_ONLYDIR);
        self::assertNotEmpty($decreeDirs, 'no decrees directories found — is the glob root right?');

        $i18nDirsFound = 0;
        foreach ($decreeDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::assertContains($file, $covered, "source data with no inventory entry: {$file}");
            }
            if (is_dir($dir . '/i18n')) {
                ++$i18nDirsFound;
                self::assertContains($dir . '/i18n', $covered, "i18n folder with no inventory entry: {$dir}/i18n");
            }
        }

        self::assertGreaterThan(
            0,
            $i18nDirsFound,
            'no i18n directories found under any decrees folder; if the convention moved, this test has stopped checking translations'
        );
    }

    /**
     * Every registered calendar is a checkable target.
     *
     * The other tests in this class walk the filesystem; this one walks the calendar index, because the
     * per-calendar half of the inventory is enumerated from that index rather than from disk. A diocese
     * added to source data and picked up by /calendars, but missed here, would otherwise be
     * unvalidatable by any client with no failure anywhere — which is #800 exactly.
     *
     * The Vatican is a deliberate, documented exception: `buildNationalCalendarData()` announces it as
     * a national calendar, but it is served by the General Roman Calendar and has no source folder of
     * its own — `CheckableInventory::nationalCalendarItems()` skips it for the same reason
     * `ResourceExistenceChecker` does, keyed on the same `VATICAN_NATIONAL_CALENDAR_ID` constant rather
     * than a bare `'VA'` literal. Skipping it here without proof would let a second, accidental omission
     * hide behind that one deliberate exception, so this test collects every national calendar missing
     * an entry and asserts that set is exactly `[VA]` — not merely that VA is *among* the misses.
     */
    public function testEveryRegisteredCalendarHasAnInventoryEntry(): void
    {
        $metadata = CalendarMetadataProvider::create();

        self::assertNotEmpty($metadata->national_calendars, 'no national calendars in the index');
        self::assertNotEmpty($metadata->diocesan_calendars, 'no diocesan calendars in the index');
        self::assertNotEmpty($metadata->wider_regions, 'no wider regions in the index');

        $missingNationalCalendars = [];
        foreach ($metadata->national_calendars as $nation) {
            if (null === CheckableInventory::byId("nation:roman:{$nation->calendar_id}")) {
                $missingNationalCalendars[] = $nation->calendar_id;
            }
        }
        self::assertSame(
            [ResourceExistenceChecker::VATICAN_NATIONAL_CALENDAR_ID],
            $missingNationalCalendars,
            'the only national calendar allowed to be missing an inventory entry is the Vatican — '
                . 'it has no source data of its own, being served by the General Roman Calendar'
        );

        foreach ($metadata->diocesan_calendars as $diocese) {
            $id = "diocese:{$diocese->rite->value}:{$diocese->calendar_id}";
            self::assertNotNull(
                CheckableInventory::byId($id),
                "diocesan calendar {$diocese->calendar_id} is registered but not checkable as {$id}"
            );
        }

        foreach ($metadata->wider_regions as $region) {
            self::assertNotNull(
                CheckableInventory::byId("widerregion:roman:{$region->name}"),
                "wider region {$region->name} is registered but not checkable"
            );
        }
    }

    /**
     * The test items the inventory advertises must be exactly the test definitions `/tests` serves.
     *
     * `CheckableInventory::testDefinitionItems()` enumerates via its own `glob(... . '/*Test.json')`,
     * matching `TestsHandler::collectTests()`'s glob by construction rather than by shared code. A
     * regression that widened either glob back to `*.json` would go uncaught by the drift tests above,
     * because every fixture under `jsondata/tests/{roman,ambrosian}/` already ends in `Test.json` — a
     * wider pattern would match the same files today and pass unnoticed.
     *
     * Driving both sides through the live handler, rather than re-implementing its glob, pins them
     * together directly: this test fails the moment what `/tests` actually serves diverges from what
     * the inventory advertises, regardless of which side's pattern moved.
     */
    public function testTestDefinitionItemsMatchWhatTestsEndpointServes(): void
    {
        foreach (Rite::cases() as $rite) {
            $response = ( new TestsHandler([], $rite) )->handle(
                new ServerRequest('GET', '/tests/' . $rite->value, ['Accept' => 'application/json'])
            );
            self::assertSame(200, $response->getStatusCode());

            /** @var array{litcal_tests?: list<array<string,mixed>>} $body */
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('litcal_tests', $body);

            $servedIds = array_map(
                static fn (array $test): string => "test:{$rite->value}:{$test['name']}",
                $body['litcal_tests']
            );
            sort($servedIds);

            $inventoryIds = array_values(array_filter(
                array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all()),
                static fn (string $id): bool => str_starts_with($id, "test:{$rite->value}:")
            ));
            sort($inventoryIds);

            self::assertSame(
                $servedIds,
                $inventoryIds,
                "test inventory for rite {$rite->value} diverges from what GET /tests actually serves"
            );
        }
    }
}
