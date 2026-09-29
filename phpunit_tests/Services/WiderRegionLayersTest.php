<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionLayers;
use LiturgicalCalendar\Api\Services\WiderRegionNaming;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Tests\Support\PinsRouterPathsTrait;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionLayers::class)]
final class WiderRegionLayersTest extends TestCase
{
    use PinsRouterPathsTrait;
    use ShadowProjectRootTrait;

    private static string $root = '';

    public static function setUpBeforeClass(): void
    {
        self::pinRouterPaths();
        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-wider-region-layers');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;
        self::writeNordicRegion();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== '') {
            self::removeTree(self::$root);
        }
        self::restoreRouterPaths();
    }

    /** A synthetic Nordic region acting on St Benedict and St Catherine, with Italian names, and Italy as a member. */
    public static function writeNordicRegion(): void
    {
        $europe = json_decode((string) file_get_contents(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Europe'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($europe);
        $nordic           = $europe;
        $nordic['litcal'] = [];
        foreach ($europe['litcal'] as $row) {
            if (in_array($row['liturgical_event']['event_key'], ['StBenedict', 'StCatherineSiena'], true)) {
                $row['liturgical_event']['grade'] = 5;
                $nordic['litcal'][]               = $row;
            }
        }
        $nordic['national_calendars']       = ['Italy' => 'IT'];
        $nordic['metadata']['wider_region'] = 'Nordic';
        $nordic['metadata']['locales']      = ['it_IT'];

        $folder = dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Nordic']));
        if (!is_dir("{$folder}/i18n") && !mkdir("{$folder}/i18n", 0777, true)) {
            throw new \RuntimeException("Cannot create {$folder}/i18n");
        }
        file_put_contents("{$folder}/Nordic.json", json_encode($nordic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        file_put_contents("{$folder}/i18n/it_IT.json", json_encode(['StBenedict' => 'Nordic Benedict', 'StCatherineSiena' => 'Nordic Catherine'], JSON_THROW_ON_ERROR));
    }

    /**
     * A synthetic `Baltic` region acting on the same two events as {@see self::writeNordicRegion()},
     * but whose `it_IT` i18n file OMITS a translation for `StBenedict` (declaring only
     * `StCatherineSiena`) — so a caller can tell `WiderRegionNaming::Lenient` (leaves an untranslated
     * item's name unchanged) apart from `WiderRegionNaming::Strict` (throws) for the exact same source
     * data.
     */
    private static function writeBalticRegionMissingOneName(): void
    {
        $europe = json_decode((string) file_get_contents(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Europe'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($europe);
        $baltic           = $europe;
        $baltic['litcal'] = [];
        foreach ($europe['litcal'] as $row) {
            if (in_array($row['liturgical_event']['event_key'], ['StBenedict', 'StCatherineSiena'], true)) {
                $row['liturgical_event']['grade'] = 5;
                $baltic['litcal'][]               = $row;
            }
        }
        $baltic['national_calendars']       = ['Italy' => 'IT'];
        $baltic['metadata']['wider_region'] = 'Baltic';
        $baltic['metadata']['locales']      = ['it_IT'];

        $folder = dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Baltic']));
        if (!is_dir("{$folder}/i18n") && !mkdir("{$folder}/i18n", 0777, true)) {
            throw new \RuntimeException("Cannot create {$folder}/i18n");
        }
        file_put_contents("{$folder}/Baltic.json", json_encode($baltic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        // StBenedict is deliberately absent: exercises the "no translation for this item" path.
        file_put_contents("{$folder}/i18n/it_IT.json", json_encode(['StCatherineSiena' => 'Baltic Catherine'], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $regions */
    private static function italyIn(array $regions): NationalData
    {
        $it                          = Utilities::jsonFileToObject(strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']));
        $it->metadata->wider_regions = $regions;

        return NationalData::fromObject($it);
    }

    public function testLayersFollowTheDeclaredOrder(): void
    {
        $layers = WiderRegionLayers::for(self::italyIn(['Europe', 'Nordic']), 'it_IT', WiderRegionNaming::Strict);

        self::assertSame(['Europe', 'Nordic'], array_map(static fn ($l) => $l->region, $layers));
    }

    public function testEachLayerIsNamedFromItsOwnI18n(): void
    {
        [, $nordic] = WiderRegionLayers::for(self::italyIn(['Europe', 'Nordic']), 'it_IT', WiderRegionNaming::Strict);

        $names = [];
        foreach ($nordic->data->litcal as $item) {
            $names[$item->getEventKey()] = $item->liturgical_event->name;
        }
        self::assertSame('Nordic Benedict', $names['StBenedict']);
    }

    public function testTheRegionsOwnLectionaryIsFoundAndAbsentOnesAreNull(): void
    {
        [$europe, $nordic] = WiderRegionLayers::for(self::italyIn(['Europe', 'Nordic']), 'it_IT', WiderRegionNaming::Strict);

        self::assertSame(strtr(JsonData::WIDER_REGION_LECTIONARY_FILE->path(), ['{wider_region}' => 'Europe', '{locale}' => 'it_IT']), $europe->lectionaryFile);
        self::assertNull($nordic->lectionaryFile);
    }

    public function testNoRegionMeansNoLayers(): void
    {
        self::assertSame([], WiderRegionLayers::for(self::italyIn([]), 'it_IT', WiderRegionNaming::Strict));
    }

    public function testAMissingRegionFileFailsLoudly(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        WiderRegionLayers::for(self::italyIn(['Atlantis']), 'it_IT', WiderRegionNaming::Strict);
    }

    public function testLenientLeavesAnUntranslatedNameUnchangedButRenamesTheRest(): void
    {
        self::writeBalticRegionMissingOneName();

        [$baltic] = WiderRegionLayers::for(self::italyIn(['Baltic']), 'it_IT', WiderRegionNaming::Lenient);

        $itemsByKey = [];
        foreach ($baltic->data->litcal as $item) {
            $itemsByKey[$item->getEventKey()] = $item;
        }

        // Baltic's i18n has no translation for StBenedict, so Lenient must not touch its name: the
        // `name` property is left exactly as `LiturgicalEventData::fromObject()` leaves it for any item
        // that is never named — uninitialized, since neither the source JSON nor `setName()` ever ran.
        $nameProperty = new \ReflectionProperty($itemsByKey['StBenedict']->liturgical_event, 'name');
        self::assertFalse($nameProperty->isInitialized($itemsByKey['StBenedict']->liturgical_event));

        // StCatherineSiena IS in Baltic's i18n, so Lenient renames it as usual.
        self::assertSame('Baltic Catherine', $itemsByKey['StCatherineSiena']->liturgical_event->name);
    }

    public function testStrictThrowsOnTheSameMissingTranslationLenientTolerates(): void
    {
        self::writeBalticRegionMissingOneName();

        $this->expectException(\ValueError::class);

        WiderRegionLayers::for(self::italyIn(['Baltic']), 'it_IT', WiderRegionNaming::Strict);
    }
}
