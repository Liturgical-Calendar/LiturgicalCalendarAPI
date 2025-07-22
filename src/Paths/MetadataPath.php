<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\DiocesanGroup;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;

final class MetadataPath
{
    private static MetadataCalendars $metadataCalendars;

    /** @var CatholicDiocesesLatinRite */
    private static array $worldDiocesesLatinRite = [];

    private const array FULLY_TRANSLATED_LOCALES = ['en', 'fr', 'it', 'nl', 'la'];

    /**
     * Scans the JsonData::NATIONAL_CALENDARS_FOLDER directory and builds an index of all National calendars,
     * their metadata and their supported locales.
     *
     * Each National calendar is identified by a folder name and a JSON file of the same name within that folder.
     * The JSON file must contain a "metadata" section with a "region" attribute.
     * The folder name is used as the National calendar identifier.
     * The JSON file is used to retrieve the supported locales for the National calendar.
     * The supported locales are stored in the MetadataPath::$baseNationalCalendars array.
     *
     * @return void
     */
    private static function buildNationalCalendarData(): void
    {
        $directories = array_map('basename', glob(JsonData::NATIONAL_CALENDARS_FOLDER . '/*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            $nationalCalendarDataFile = JsonData::NATIONAL_CALENDARS_FOLDER . "/$directory/$directory.json";
            if (file_exists($nationalCalendarDataFile)) {
                $nationalCalendarDefinition = file_get_contents($nationalCalendarDataFile);
                $nationalCalendarData       = json_decode($nationalCalendarDefinition);
                if (JSON_ERROR_NONE === json_last_error()) {
                    $nationalCalendarData->metadata->settings = $nationalCalendarData->settings;
                    $nationalCalendarData->metadata->dioceses = [];
                    $metadataNationalCalendarItem             = MetadataNationalCalendarItem::fromObject($nationalCalendarData->metadata);
                    self::$metadataCalendars->pushNationalCalendarMetadata($metadataNationalCalendarItem);
                }
            }
        }
    }

    /**
     * Takes a diocese ID and returns the corresponding diocese name.
     * If the diocese ID is not found, returns null.
     *
     * @param string $id The diocese ID.
     * @return string|null The diocese name or null if not found.
     */
    private static function dioceseIdToName(string $id): ?string
    {
        if (empty(MetadataPath::$worldDiocesesLatinRite)) {
            $worldDiocesesFile                    = JsonData::FOLDER . '/world_dioceses.json';
            MetadataPath::$worldDiocesesLatinRite = json_decode(
                file_get_contents($worldDiocesesFile)
            )->catholic_dioceses_latin_rite;
        }
        $dioceseName = null;
        // Search for the diocese by its ID in the worldDioceseLatinRite data
        foreach (MetadataPath::$worldDiocesesLatinRite as $country) {
            foreach ($country->dioceses as $diocese) {
                if ($diocese->diocese_id === $id) {
                    $dioceseName = $diocese->diocese_name;
                    if (property_exists($diocese, 'province')) {
                        $dioceseName .= ' (' . $diocese->province . ')';
                    }
                    break 2; // Break out of both loops
                }
            }
        }
        return $dioceseName;
    }

    /**
     * Builds an index of all diocesan calendars.
     *
     * @return void
     */
    private static function buildDiocesanCalendarData(): void
    {
        foreach (glob(JsonData::DIOCESAN_CALENDARS_FOLDER . '/*', GLOB_ONLYDIR) as $countryFolder) {
            $nation = basename($countryFolder);
            /** @var string[] $directories */
            $directories = array_map('basename', glob($countryFolder . '/*', GLOB_ONLYDIR));
            foreach ($directories as $calendar_id) {
                $dioceseName          = MetadataPath::dioceseIdToName($calendar_id) ?? $calendar_id;
                $diocesanCalendarFile = JsonData::DIOCESAN_CALENDARS_FOLDER . "/$nation/$calendar_id/$dioceseName.json";
                if (file_exists($diocesanCalendarFile) && is_readable($diocesanCalendarFile)) {
                    $diocesanCalendarDefinition = file_get_contents($diocesanCalendarFile);
                    $diocesanCalendarData       = json_decode($diocesanCalendarDefinition);
                    if (JSON_ERROR_NONE === json_last_error()) {
                        $diocesanCalendarData->metadata->diocese = $dioceseName;
                        if (property_exists($diocesanCalendarData, 'settings')) {
                            $diocesanCalendarData->metadata->settings = $diocesanCalendarData->settings;
                        }
                        $metadataDiocesanCalendarItem = MetadataDiocesanCalendarItem::fromObject($diocesanCalendarData->metadata);
                        self::$metadataCalendars->pushDiocesanCalendarMetadata($metadataDiocesanCalendarItem);
                    }
                }
            }
        }
    }


    /**
     * Scans the {@see JsonData::WIDER_REGIONS_FOLDER} directory and build an index of all Wider regions,
     * their supported locales and their data files.
     *
     * Each Wider region is identified by a folder name and a JSON file of the same name within that folder.
     * Wider region identifiers are added to the MetadataPath::$widerRegionsNames array.
     * Supported locales are retrieved by scanning the `i18n` subfolder for each Wider region,
     * based on the JSON files present.
     *
     * @return void
     */
    private static function buildWiderRegionData(): void
    {
        $directories = array_map('basename', glob(JsonData::WIDER_REGIONS_FOLDER . '/*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            $WiderRegionFile = strtr(JsonData::WIDER_REGIONS_FILE, ['{wider_region}' => $directory]);
            if (file_exists($WiderRegionFile)) {
                $widerRegionI18nFolder   = strtr(JsonData::WIDER_REGIONS_I18N_FOLDER, [ '{wider_region}' => $directory ]);
                $locales                 = array_map(fn ($filename) => pathinfo($filename, PATHINFO_FILENAME), glob($widerRegionI18nFolder . '/*.json'));
                $metadataWiderRegionItem = MetadataWiderRegionItem::fromArray([
                    'name'     => $directory,
                    'locales'  => $locales,
                    'api_path' => API_BASE_PATH . Route::DATA_WIDERREGION->value . '/' . $directory . '?locale={language}'
                ]);
                self::$metadataCalendars->pushWiderRegionMetadata($metadataWiderRegionItem);
            }
        }
    }

    /**
     * Populates the MetadataPath::$locales array with the list of supported locales.
     *
     * It does this by scanning the i18n/ folder and retrieving the folder names
     * of all its subfolders. The result is an array of strings, where each string
     * is a locale code. The locale code is in the format of a single string
     * containing the language code (optionally followed by an underscore and the
     * region code; for now none of the locales have regional identifiers).
     */
    private static function buildLocales(): void
    {
        // Since we can't actually request the General Roman Calendar for locales that are not fully translated,
        // we remove those locales from the list of supported locales
        self::$metadataCalendars->locales = array_values(array_intersect(
            array_merge(['en'], array_map('basename', glob('i18n/*', GLOB_ONLYDIR))),
            MetadataPath::FULLY_TRANSLATED_LOCALES
        ));
    }

    /**
     * Builds an index of all National and Diocesan calendars,
     * and of locales supported for the General Roman Calendar
     *
     * @return int Returns the HTTP Status Code for the Response
     */
    private static function buildIndex(): int
    {
        self::$metadataCalendars = new MetadataCalendars();
        MetadataPath::buildNationalCalendarData();
        MetadataPath::buildDiocesanCalendarData();
        MetadataPath::buildWiderRegionData();
        MetadataPath::buildLocales();
        return 200;
    }

    public static function response(): void
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
                'epiphany'            => Epiphany::JAN6->value,
                'ascension'           => Ascension::THURSDAY->value,
                'corpus_christi'      => Ascension::THURSDAY->value,
                'eternal_high_priest' => false
            ]
        ]);

        $response     = json_encode([
            'litcal_metadata' => self::$metadataCalendars
        ], JSON_PRETTY_PRINT);
        $responseHash = md5($response);

        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
            header('Content-Length: 0');
        } else {
            echo $response;
        }
        die();
    }

    /**
     * Initialization function for the metadata API.
     *
     * It sets the appropriate CORS headers and calls the `buildIndex` and `response` methods.
     *
     * @return void
     */
    public static function init()
    {
        if (isset($_SERVER['REQUEST_METHOD'])) {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header('Access-Control-Allow-Methods: OPTIONS,GET,POST');
            }
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ]}");
            }
        }

        /*
        $requestHeaders = getallheaders();
        if (isset($requestHeaders[ "Origin" ])) {
            header("Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}");
            header('Access-Control-Allow-Credentials: true');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        */
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
        header('Cache-Control: must-revalidate, max-age=259200');
        header('Content-Type: application/json');

        $indexResult = MetadataPath::buildIndex();

        if (200 === $indexResult) {
            MetadataPath::response();
        } else {
            http_response_code($indexResult);
        }
    }
}
