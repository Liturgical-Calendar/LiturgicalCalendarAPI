<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionMembership;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Wider region membership, read from the bundled source data: the fact that decides
 * whether a national editor may write a wider region's translations.
 */
#[CoversClass(WiderRegionMembership::class)]
final class WiderRegionMembershipTest extends TestCase
{
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    public function testANationWithACalendarBelongsToItsDeclaredRegion(): void
    {
        self::assertSame(['americas'], WiderRegionMembership::regionsOf('CA'));
        self::assertSame(['europe'], WiderRegionMembership::regionsOf('IT'));
    }

    public function testTheRegionsOwnMemberListCountsWithoutANationalCalendar(): void
    {
        // Hungary has no national calendar, but Europe lists it among its nations.
        self::assertSame(['europe'], WiderRegionMembership::regionsOf('HU'));
    }

    public function testANationInNoRegionHasNone(): void
    {
        // Australia (M.49 053, Oceania) is on no region's roster: there is no Oceania region yet.
        self::assertSame([], WiderRegionMembership::regionsOf('AU'));
    }

    public function testEveryRegionTheNationDeclaresCounts(): void
    {
        $root                = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wrm-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        $saved               = Router::$apiFilePath;
        Router::$apiFilePath = $root;
        $nationFolder        = dirname(strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'SE']));
        try {
            self::assertTrue(mkdir($nationFolder, 0777, true));
            self::assertTrue(mkdir(JsonData::WIDER_REGIONS_FOLDER->path(), 0777, true));
            file_put_contents("{$nationFolder}/SE.json", '{"metadata": {"wider_regions": ["europe", "nordic"]}}');

            self::assertSame(['europe', 'nordic'], WiderRegionMembership::regionsOf('SE'));
        } finally {
            Router::$apiFilePath = $saved;
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testALegacySingleWiderRegionStillCounts(): void
    {
        $root                = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wrm-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        $saved               = Router::$apiFilePath;
        Router::$apiFilePath = $root;
        $nationFolder        = dirname(strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'NO']));
        try {
            self::assertTrue(mkdir($nationFolder, 0777, true));
            self::assertTrue(mkdir(JsonData::WIDER_REGIONS_FOLDER->path(), 0777, true));
            file_put_contents("{$nationFolder}/NO.json", '{"metadata": {"wider_region": "nordic"}}');

            self::assertSame(['nordic'], WiderRegionMembership::regionsOf('NO'));
        } finally {
            Router::$apiFilePath = $saved;
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testAMalformedRegionFileThrowsInsteadOfReadingAsNoRegion(): void
    {
        $root                = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wrm-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        $saved               = Router::$apiFilePath;
        Router::$apiFilePath = $root;
        $folder              = JsonData::WIDER_REGIONS_FOLDER->path() . '/Broken';
        try {
            self::assertTrue(mkdir($folder, 0777, true));
            file_put_contents("{$folder}/Broken.json", '{"national_calendars": ');

            $this->expectException(\RuntimeException::class);
            WiderRegionMembership::regionsOf('VE');
        } finally {
            Router::$apiFilePath = $saved;
            @unlink("{$folder}/Broken.json");
            $dir = $folder;
            while (str_starts_with($dir, $root) && $dir !== rtrim($root, DIRECTORY_SEPARATOR)) {
                @rmdir($dir);
                $dir = dirname($dir);
            }
            @rmdir($root);
        }
    }
}
