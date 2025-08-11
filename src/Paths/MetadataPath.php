<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite\CatholicDiocesesMap;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use LiturgicalCalendar\Api\Utilities;

/**
 * @phpstan-import-type CatholicDiocesesLatinRite from \LiturgicalCalendar\Api\Paths\CalendarPath
 * @phpstan-import-type NationalCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData
 * @phpstan-import-type DiocesanCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData
 */
final class MetadataPath
{
    public static Core $Core;

    private static MetadataCalendars $metadataCalendars;

    private static CatholicDiocesesMap $worldDiocesesLatinRite;

    private const array FULLY_TRANSLATED_LOCALES = ['en', 'fr', 'it', 'nl', 'la'];

    public function __construct()
    {
        self::$Core = new Core();
    }

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
        self::$metadataCalendars->pushNationalCalendarMetadata($metadataNationalCalendarItem);

        $folderGlob = glob(JsonData::NATIONAL_CALENDARS_FOLDER . '/*', GLOB_ONLYDIR);
        if (false === $folderGlob) {
            throw new \RuntimeException('MetadataPath::buildNationalCalendarData: glob failed');
        }

        /** @var string[] $countryISOs */
        $countryISOs = array_map('basename', $folderGlob);
        foreach ($countryISOs as $countryISO) {
            $nationalCalendarDataFile = JsonData::NATIONAL_CALENDARS_FOLDER . "/$countryISO/$countryISO.json";
            /** @var NationalCalendarDataObject $nationalCalendarData */
            $nationalCalendarData                        = Utilities::jsonFileToObject($nationalCalendarDataFile);
            $nationalCalendarData->metadata->settings    = $nationalCalendarData->settings;
            $nationalCalendarData->metadata->calendar_id = $nationalCalendarData->metadata->nation;
            unset($nationalCalendarData->metadata->nation);
            $nationalCalendarData->metadata->dioceses = [];
            $metadataNationalCalendarItem             = MetadataNationalCalendarItem::fromObject($nationalCalendarData->metadata);
            self::$metadataCalendars->pushNationalCalendarMetadata($metadataNationalCalendarItem);
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
        if (false === isset(MetadataPath::$worldDiocesesLatinRite)) {
            $worldDiocesesFile                    = JsonData::FOLDER . '/world_dioceses.json';
            $worldDiocesesData                    = Utilities::jsonFileToObject($worldDiocesesFile);
            MetadataPath::$worldDiocesesLatinRite = CatholicDiocesesMap::fromObject($worldDiocesesData);
        }
        return MetadataPath::$worldDiocesesLatinRite->dioceseNameFromId($nation, $id);
    }

    /**
     * Builds an index of all diocesan calendars.
     *
     * @return void
     */
    private static function buildDiocesanCalendarData(): void
    {
        $countryFolders = glob(JsonData::DIOCESAN_CALENDARS_FOLDER . '/*', GLOB_ONLYDIR);
        if (false === $countryFolders) {
            throw new \RuntimeException('MetadataPath::buildDiocesanCalendarData: diocesan calendars folder glob failed');
        }

        foreach ($countryFolders as $countryFolder) {
            $nation         = basename($countryFolder);
            $dioceseFolders = glob($countryFolder . '/*', GLOB_ONLYDIR);
            if (false === $dioceseFolders) {
                throw new \RuntimeException('MetadataPath::buildDiocesanCalendarData: countryFolder glob failed');
            }

            /** @var string[] $dioceseIDs */
            $dioceseIDs = array_map('basename', $dioceseFolders);
            foreach ($dioceseIDs as $calendar_id) {
                $dioceseName = MetadataPath::dioceseIdToName($nation, $calendar_id);
                if (null === $dioceseName) {
                    throw new \RuntimeException("MetadataPath::buildDiocesanCalendarData: diocese name not found for nation = `{$nation}` and calendar_id = `{$calendar_id}`");
                }
                $diocesanCalendarFile = JsonData::DIOCESAN_CALENDARS_FOLDER . "/$nation/$calendar_id/$dioceseName.json";
                $diocesanCalendarData = Utilities::jsonFileToObject($diocesanCalendarFile);
                /** @var DiocesanCalendarDataObject $diocesanCalendarData */
                $diocesanCalendarData->metadata->diocese = $dioceseName;
                if (property_exists($diocesanCalendarData, 'settings')) {
                    $diocesanCalendarData->metadata->settings = $diocesanCalendarData->settings;
                }
                $diocesanCalendarData->metadata->calendar_id = $diocesanCalendarData->metadata->diocese_id;
                unset($diocesanCalendarData->metadata->diocese_id);
                $metadataDiocesanCalendarItem = MetadataDiocesanCalendarItem::fromObject($diocesanCalendarData->metadata);
                self::$metadataCalendars->pushDiocesanCalendarMetadata($metadataDiocesanCalendarItem);
            }
        }
    }


    /**
     * Scans the {@see \LiturgicalCalendar\Api\Enum\JsonData::WIDER_REGIONS_FOLDER} directory and build an index of all Wider regions,
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
        $folderGlob = glob(JsonData::WIDER_REGIONS_FOLDER . '/*', GLOB_ONLYDIR);
        if (false === $folderGlob) {
            throw new \RuntimeException('MetadataPath::buildWiderRegionData: wider regions folder glob failed');
        }

        /** @var string[] $widerRegionIDs */
        $widerRegionIDs = array_map('basename', $folderGlob);
        foreach ($widerRegionIDs as $widerRegionId) {
            $WiderRegionFile = strtr(
                JsonData::WIDER_REGION_FILE,
                ['{wider_region}' => $widerRegionId]
            );

            if (file_exists($WiderRegionFile)) {
                $widerRegionI18nFolder = strtr(
                    JsonData::WIDER_REGION_I18N_FOLDER,
                    [ '{wider_region}' => $widerRegionId ]
                );

                $folderGlob = glob($widerRegionI18nFolder . '/*.json');
                if (false === $folderGlob) {
                    throw new \RuntimeException('MetadataPath::buildWiderRegionData: wider region i18n folder glob failed');
                }

                $locales = array_map(
                    fn (string $filename) => pathinfo($filename, PATHINFO_FILENAME),
                    $folderGlob
                );

                $metadataWiderRegionItem = MetadataWiderRegionItem::fromArray([
                    'name'     => $widerRegionId,
                    'locales'  => $locales,
                    'api_path' => API_BASE_PATH . Route::DATA_WIDERREGION->value . '/' . $widerRegionId . '?locale={locale}'
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
        $folderGlob = glob('i18n/*', GLOB_ONLYDIR);
        if (false === $folderGlob) {
            throw new \RuntimeException('MetadataPath::buildLocales: i18n folder glob failed');
        }

        self::$metadataCalendars->locales = array_values(array_intersect(
            array_merge(['en'], array_map('basename', $folderGlob)),
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

    /**
     * Generates the HTTP Response for the /calendars path.
     *
     * The response is a JSON object containing the list of supported National and Diocesan calendars,
     * and the list of locales supported for the General Roman Calendar.
     * The response is cached by the client and by the server (if the server is configured to do so).
     * The response also includes an Etag header containing the MD5 hash of the response.
     * If the client sends an If-None-Match header with the same value as the Etag,
     * the response is a 304 Not Modified response with a Content-Length of 0,
     * indicating that the client can use its cached copy of the response.
     * Otherwise, the response is the full JSON object.
     *
     * @return never
     */
    public static function produceResponse(): never
    {
        $response = json_encode(['litcal_metadata' => self::$metadataCalendars], JSON_PRETTY_PRINT);
        if (JSON_ERROR_NONE !== json_last_error() || false === $response) {
            throw new \ValueError('JSON error: ' . json_last_error_msg());
        }

        $responseHash = md5($response);

        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            $serverProtocol = isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
            header($serverProtocol . ' 304 Not Modified');
            header('Content-Length: 0');
        } else {
            MetadataPath::$Core->setResponseContentTypeHeader();

            if (false === in_array(self::$Core->getRequestMethod(), self::$Core->getAllowedRequestMethods())) {
                $description = 'Allowed Request Methods are '
                    . implode(' and ', array_column(self::$Core->getAllowedRequestMethods(), 'value'))
                    . ', but your Request Method was '
                    . self::$Core->getRequestMethod()->value;
                self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, $description);
            }

            switch (self::$Core->getResponseContentType()) {
                case AcceptHeader::JSON:
                    echo $response;
                    break;
                case AcceptHeader::YAML:
                    echo yaml_emit(json_decode($response, true, 512, JSON_THROW_ON_ERROR), YAML_UTF8_ENCODING);
                    break;
                default:
                    if (null === self::$Core->getResponseContentType()) {
                        throw new \ValueError('Response content type was not set?');
                    }
                    throw new \ValueError('Response content type not allowed: ' . self::$Core->getResponseContentType()->value);
            }
        }
        die();
    }

    /**
     * Produce an error response with the given HTTP status code and description.
     *
     * The description is a short string that should be used to give more context to the error.
     *
     * The function will output the error in the response format specified by the Accept header
     * of the request (JSON or YAML) and terminate the script execution with a call to die().
     *
     * @param int $statusCode the HTTP status code to return
     * @param string $description a short description of the error
     * @return never
     */
    public static function produceErrorResponse(int $statusCode, string $description): never
    {
        $serverProtocol = isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1 ';
        header($serverProtocol . StatusCode::toString($statusCode), true, $statusCode);
        $message              = new \stdClass();
        $message->status      = 'ERROR';
        $message->response    = $statusCode === 404 ? 'Resource not Found' : 'Resource unavailable';
        $message->description = $description;
        $response             = json_encode($message);
        if ($response === false) {
            $response = '{"status":"ERROR","response":"Internal Server Error","description":"Failed to encode error message to JSON"}';
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($response, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $response;
        }
        die();
    }

    /**
     * Initialization function for the metadata API.
     *
     * @return never
     */
    public function init(): never
    {
        self::$Core->init();
        if (self::$Core->getRequestMethod() === RequestMethod::OPTIONS) {
            die();
        }
        if (self::$Core->getRequestMethod() === RequestMethod::GET) {
            self::$Core->validateAcceptHeader(true);
        } else {
            self::$Core->validateAcceptHeader(false);
        }

        $indexResult = MetadataPath::buildIndex();

        if (200 === $indexResult) {
            MetadataPath::produceResponse();
        } else {
            http_response_code($indexResult);
            die();
        }
    }
}
