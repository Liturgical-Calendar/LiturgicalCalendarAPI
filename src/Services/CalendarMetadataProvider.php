<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite\CatholicDiocesesMap;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;

/**
 * Builds the calendars metadata index ({@see MetadataCalendars}) directly from
 * local source data, in-process — without looping back through the network to
 * the API's own `/calendars` endpoint.
 *
 * This is the single source of truth for that index. {@see MetadataHandler}
 * (serving GET /calendars), {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler},
 * {@see \LiturgicalCalendar\Api\Params\EventsParams}, and
 * {@see \LiturgicalCalendar\Api\Params\CalendarParams} all consume the object
 * produced here. The object returned by {@see self::create()} is, by design,
 * identical to what those consumers previously obtained via
 * `MetadataCalendars::fromObject(jsonDecode(GET /calendars))`: the build path
 * populates every field (`*_keys`, `diocesan_groups`, and the per-nation
 * `dioceses` lists) that `jsonSerialize()` emits and `fromObject()` reads back,
 * so the JSON round-trip is idempotent (guarded by CalendarMetadataProviderTest).
 *
 * Building reads the same on-disk source data on every call (no cross-request
 * memoization): the `/data` write endpoints can mutate calendar definition
 * files at runtime, so caching the index across requests would risk serving
 * stale metadata.
 *
 * @phpstan-import-type NationalCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData
 * @phpstan-import-type DiocesanCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData
 */
final class CalendarMetadataProvider
{
    /**
     * Lazily-loaded world dioceses map (reference data, not written by the
     * /data endpoints), memoized for the lifetime of the process so repeated
     * diocese-name lookups within and across builds don't re-read the file.
     */
    private static ?CatholicDiocesesMap $worldDiocesesLatinRite = null;

    private const array FULLY_TRANSLATED_LOCALES = ['en', 'fr', 'it', 'nl', 'la'];

    /**
     * Builds and returns the complete calendars metadata index from local
     * source data.
     */
    public static function create(): MetadataCalendars
    {
        $metadata = new MetadataCalendars();
        self::buildNationalCalendarData($metadata);
        self::buildDiocesanCalendarData($metadata);
        self::buildWiderRegionData($metadata);
        self::buildLocales($metadata);
        return $metadata;
    }

    /**
     * glob() that throws instead of returning the system-error sentinel `false`.
     *
     * glob() returns an empty array (not false) when a readable directory simply
     * has no matches, so the false branch only fires on a genuine filesystem
     * error against a directory we expect to exist — an unreachable defensive
     * guard in normal operation, hence excluded from coverage.
     *
     * @return string[]
     * @codeCoverageIgnore
     */
    private static function globOrThrow(string $pattern, int $flags, string $context): array
    {
        $result = glob($pattern, $flags);
        if (false === $result) {
            throw new \RuntimeException($context . ': glob failed');
        }
        return $result;
    }

    /**
     * Scans the JsonData::NATIONAL_CALENDARS_FOLDER directory and builds an index of all National calendars,
     * their metadata and their supported locales.
     *
     * Each National calendar is identified by a folder name and a JSON file of the same name within that folder.
     * The JSON file must contain a "metadata" section with a "region" attribute.
     * The folder name is used as the National calendar identifier.
     *
     * @return void
     */
    private static function buildNationalCalendarData(MetadataCalendars $metadata): void
    {
        // We add the General Roman Calendar as used in the Vatican to the list of "national" calendars
        $metadataNationalCalendarItem = MetadataNationalCalendarItem::fromArray([
            'calendar_id' => 'VA',
            'locales'     => [ 'la_VA' ],
            'missals'     => [
                'EDITIO_TYPICA_1970',
                'EDITIO_TYPICA_1971',
                'EDITIO_TYPICA_1975',
                'EDITIO_TYPICA_2002',
                'EDITIO_TYPICA_2008'
            ],
            'settings'    => [
                'epiphany'               => Epiphany::JAN6->value,
                'ascension'              => Ascension::THURSDAY->value,
                'corpus_christi'         => Ascension::THURSDAY->value,
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => true,
                    'Ascension'            => true,
                    'CorpusChristi'        => true,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => true,
                    'StsPeterPaulAp'       => true,
                    'AllSaints'            => true
                ]
            ]
        ]);
        $metadata->pushNationalCalendarMetadata($metadataNationalCalendarItem);

        $folderGlob = self::globOrThrow(JsonData::NATIONAL_CALENDARS_FOLDER->path() . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildNationalCalendarData');

        /** @var string[] $countryISOs */
        $countryISOs = array_map('basename', $folderGlob);
        foreach ($countryISOs as $countryISO) {
            $nationalCalendarDataFile = JsonData::NATIONAL_CALENDARS_FOLDER->path() . "/$countryISO/$countryISO.json";
            /** @var NationalCalendarDataObject $nationalCalendarData */
            $nationalCalendarData                        = Utilities::jsonFileToObject($nationalCalendarDataFile);
            $nationalCalendarData->metadata->settings    = $nationalCalendarData->settings;
            $nationalCalendarData->metadata->calendar_id = $nationalCalendarData->metadata->nation;
            unset($nationalCalendarData->metadata->nation);
            $nationalCalendarData->metadata->dioceses = [];
            $metadataNationalCalendarItem             = MetadataNationalCalendarItem::fromObject($nationalCalendarData->metadata);
            $metadata->pushNationalCalendarMetadata($metadataNationalCalendarItem);
        }
    }

    /**
     * Takes a diocese ID and returns the corresponding diocese name.
     * If the diocese ID is not found, returns null.
     *
     * @param string $id The diocese ID.
     * @return string|null The diocese name or null if not found.
     */
    private static function dioceseIdToName(string $nation, string $id): ?string
    {
        if (null === self::$worldDiocesesLatinRite) {
            $worldDiocesesFile = JsonData::FOLDER->path() . '/world_dioceses.json';
            $worldDiocesesData = Utilities::jsonFileToObject($worldDiocesesFile);

            self::$worldDiocesesLatinRite = CatholicDiocesesMap::fromObject($worldDiocesesData);
        }
        return self::$worldDiocesesLatinRite->dioceseNameFromId($nation, $id);
    }

    /**
     * Builds an index of all diocesan calendars.
     *
     * @return void
     */
    private static function buildDiocesanCalendarData(MetadataCalendars $metadata): void
    {
        $countryFolders = self::globOrThrow(JsonData::DIOCESAN_CALENDARS_FOLDER->path() . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildDiocesanCalendarData');

        foreach ($countryFolders as $countryFolder) {
            $nation         = basename($countryFolder);
            $dioceseFolders = self::globOrThrow($countryFolder . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildDiocesanCalendarData');

            /** @var string[] $dioceseIDs */
            $dioceseIDs = array_map('basename', $dioceseFolders);
            foreach ($dioceseIDs as $calendar_id) {
                $dioceseName = self::dioceseIdToName($nation, $calendar_id);
                // @codeCoverageIgnoreStart
                // Defensive: every bundled diocese folder maps to a name in
                // world_dioceses.json, so this guard only fires on inconsistent
                // source data and is unreachable in unit tests.
                if (null === $dioceseName) {
                    throw new \RuntimeException("CalendarMetadataProvider::buildDiocesanCalendarData: diocese name not found for nation = `{$nation}` and calendar_id = `{$calendar_id}`");
                }
                // @codeCoverageIgnoreEnd
                $diocesanCalendarFile = JsonData::DIOCESAN_CALENDARS_FOLDER->path() . "/$nation/$calendar_id/$dioceseName.json";
                $diocesanCalendarData = Utilities::jsonFileToObject($diocesanCalendarFile);
                /** @var DiocesanCalendarDataObject $diocesanCalendarData */
                $diocesanCalendarData->metadata->diocese = $dioceseName;
                if (property_exists($diocesanCalendarData, 'settings')) {
                    $diocesanCalendarData->metadata->settings = $diocesanCalendarData->settings;
                }
                $diocesanCalendarData->metadata->calendar_id = $diocesanCalendarData->metadata->diocese_id;
                unset($diocesanCalendarData->metadata->diocese_id);
                $metadataDiocesanCalendarItem = MetadataDiocesanCalendarItem::fromObject($diocesanCalendarData->metadata);
                $metadata->pushDiocesanCalendarMetadata($metadataDiocesanCalendarItem);
            }
        }
    }


    /**
     * Scans the {@see \LiturgicalCalendar\Api\Enum\JsonData::WIDER_REGIONS_FOLDER} directory and build an index of all Wider regions,
     * their supported locales and their data files.
     *
     * Each Wider region is identified by a folder name and a JSON file of the same name within that folder.
     * Supported locales are retrieved by scanning the `i18n` subfolder for each Wider region,
     * based on the JSON files present.
     *
     * @return void
     */
    private static function buildWiderRegionData(MetadataCalendars $metadata): void
    {
        $folderGlob = self::globOrThrow(JsonData::WIDER_REGIONS_FOLDER->path() . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildWiderRegionData');

        /** @var string[] $widerRegionIDs */
        $widerRegionIDs = array_map('basename', $folderGlob);
        foreach ($widerRegionIDs as $widerRegionId) {
            $WiderRegionFile = strtr(
                JsonData::WIDER_REGION_FILE->path(),
                ['{wider_region}' => $widerRegionId]
            );

            if (file_exists($WiderRegionFile)) {
                $widerRegionI18nFolder = strtr(
                    JsonData::WIDER_REGION_I18N_FOLDER->path(),
                    [ '{wider_region}' => $widerRegionId ]
                );

                $folderGlob = self::globOrThrow($widerRegionI18nFolder . '/*.json', 0, 'CalendarMetadataProvider::buildWiderRegionData');

                $locales = array_map(
                    fn (string $filename) => pathinfo($filename, PATHINFO_FILENAME),
                    $folderGlob
                );

                $metadataWiderRegionItem = MetadataWiderRegionItem::fromArray([
                    'name'     => $widerRegionId,
                    'locales'  => $locales,
                    'api_path' => Router::$apiPath . Route::DATA_WIDERREGION->value . '/' . $widerRegionId . '?locale={locale}'
                ]);
                $metadata->pushWiderRegionMetadata($metadataWiderRegionItem);
            }
        }
    }

    /**
     * Populates the MetadataCalendars::$locales array with the list of supported locales.
     *
     * It does this by scanning the i18n/ folder and retrieving the folder names
     * of all its subfolders. The result is an array of strings, where each string
     * is a locale code. The locale code is in the format of a single string
     * containing the language code (optionally followed by an underscore and the
     * region code; for now none of the locales have regional identifiers).
     */
    private static function buildLocales(MetadataCalendars $metadata): void
    {
        // Since we can't actually request the General Roman Calendar for locales that are not fully translated,
        // we remove those locales from the list of supported locales
        $folderGlob = self::globOrThrow(Router::$apiFilePath . 'i18n/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildLocales');

        $metadata->locales = array_values(array_intersect(
            array_merge(['en'], array_map('basename', $folderGlob)),
            self::FULLY_TRANSLATED_LOCALES
        ));
    }
}
