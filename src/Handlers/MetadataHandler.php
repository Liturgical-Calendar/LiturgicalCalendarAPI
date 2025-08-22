<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ImplementationException;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedMediaTypeException;
use LiturgicalCalendar\Api\Http\Exception\YamlException;
use LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite\CatholicDiocesesMap;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @phpstan-import-type CatholicDiocesesLatinRite from \LiturgicalCalendar\Api\Handlers\CalendarHandler
 * @phpstan-import-type NationalCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData
 * @phpstan-import-type DiocesanCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData
 */
final class MetadataHandler extends AbstractHandler
{
    private static MetadataCalendars $metadataCalendars;

    private static CatholicDiocesesMap $worldDiocesesLatinRite;

    private const array FULLY_TRANSLATED_LOCALES = ['en', 'fr', 'it', 'nl', 'la'];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Scans the JsonData::NATIONAL_CALENDARS_FOLDER directory and builds an index of all National calendars,
     * their metadata and their supported locales.
     *
     * Each National calendar is identified by a folder name and a JSON file of the same name within that folder.
     * The JSON file must contain a "metadata" section with a "region" attribute.
     * The folder name is used as the National calendar identifier.
     * The JSON file is used to retrieve the supported locales for the National calendar.
     * The supported locales are stored in the MetadataHandler::$baseNationalCalendars array.
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

        $folderGlob = glob(JsonData::NATIONAL_CALENDARS_FOLDER->path() . '/*', GLOB_ONLYDIR);
        if (false === $folderGlob) {
            throw new \RuntimeException('MetadataHandler::buildNationalCalendarData: glob failed');
        }

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
        if (false === isset(MetadataHandler::$worldDiocesesLatinRite)) {
            $worldDiocesesFile = JsonData::FOLDER->path() . '/world_dioceses.json';
            $worldDiocesesData = Utilities::jsonFileToObject($worldDiocesesFile);

            MetadataHandler::$worldDiocesesLatinRite = CatholicDiocesesMap::fromObject($worldDiocesesData);
        }
        return MetadataHandler::$worldDiocesesLatinRite->dioceseNameFromId($nation, $id);
    }

    /**
     * Builds an index of all diocesan calendars.
     *
     * @return void
     */
    private static function buildDiocesanCalendarData(): void
    {
        $countryFolders = glob(JsonData::DIOCESAN_CALENDARS_FOLDER->path() . '/*', GLOB_ONLYDIR);
        if (false === $countryFolders) {
            throw new \RuntimeException('MetadataHandler::buildDiocesanCalendarData: diocesan calendars folder glob failed');
        }

        foreach ($countryFolders as $countryFolder) {
            $nation         = basename($countryFolder);
            $dioceseFolders = glob($countryFolder . '/*', GLOB_ONLYDIR);
            if (false === $dioceseFolders) {
                throw new \RuntimeException('MetadataHandler::buildDiocesanCalendarData: countryFolder glob failed');
            }

            /** @var string[] $dioceseIDs */
            $dioceseIDs = array_map('basename', $dioceseFolders);
            foreach ($dioceseIDs as $calendar_id) {
                $dioceseName = MetadataHandler::dioceseIdToName($nation, $calendar_id);
                if (null === $dioceseName) {
                    throw new \RuntimeException("MetadataHandler::buildDiocesanCalendarData: diocese name not found for nation = `{$nation}` and calendar_id = `{$calendar_id}`");
                }
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
                self::$metadataCalendars->pushDiocesanCalendarMetadata($metadataDiocesanCalendarItem);
            }
        }
    }


    /**
     * Scans the {@see \LiturgicalCalendar\Api\Enum\JsonData::WIDER_REGIONS_FOLDER} directory and build an index of all Wider regions,
     * their supported locales and their data files.
     *
     * Each Wider region is identified by a folder name and a JSON file of the same name within that folder.
     * Wider region identifiers are added to the MetadataHandler::$widerRegionsNames array.
     * Supported locales are retrieved by scanning the `i18n` subfolder for each Wider region,
     * based on the JSON files present.
     *
     * @return void
     */
    private static function buildWiderRegionData(): void
    {
        $folderGlob = glob(JsonData::WIDER_REGIONS_FOLDER->path() . '/*', GLOB_ONLYDIR);
        if (false === $folderGlob) {
            throw new \RuntimeException('MetadataHandler::buildWiderRegionData: wider regions folder glob failed');
        }

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

                $folderGlob = glob($widerRegionI18nFolder . '/*.json');
                if (false === $folderGlob) {
                    throw new \RuntimeException('MetadataHandler::buildWiderRegionData: wider region i18n folder glob failed');
                }

                $locales = array_map(
                    fn (string $filename) => pathinfo($filename, PATHINFO_FILENAME),
                    $folderGlob
                );

                $metadataWiderRegionItem = MetadataWiderRegionItem::fromArray([
                    'name'     => $widerRegionId,
                    'locales'  => $locales,
                    'api_path' => Router::$apiPath . Route::DATA_WIDERREGION->value . '/' . $widerRegionId . '?locale={locale}'
                ]);
                self::$metadataCalendars->pushWiderRegionMetadata($metadataWiderRegionItem);
            }
        }
    }

    /**
     * Populates the MetadataHandler::$locales array with the list of supported locales.
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
        $folderGlob = glob(Router::$apiFilePath . 'i18n/*', GLOB_ONLYDIR);
        if (false === $folderGlob) {
            throw new \RuntimeException('MetadataHandler::buildLocales: i18n folder glob failed');
        }

        self::$metadataCalendars->locales = array_values(array_intersect(
            array_merge(['en'], array_map('basename', $folderGlob)),
            MetadataHandler::FULLY_TRANSLATED_LOCALES
        ));
    }

    /**
     * Builds an index of all National and Diocesan calendars,
     * and of locales supported for the General Roman Calendar
     */
    private static function buildIndex(): void
    {
        self::$metadataCalendars = new MetadataCalendars();
        MetadataHandler::buildNationalCalendarData();
        MetadataHandler::buildDiocesanCalendarData();
        MetadataHandler::buildWiderRegionData();
        MetadataHandler::buildLocales();
    }

    /**
     * Handles requests to the /api/metadata endpoint
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // We instantiate a Response object with minimum state
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

        // For all other request methods, validate that they are supported by the endpoint
        $this->validateRequestMethod($request);

        // First of all we validate that the Content-Type requested in the Accept header is supported by the endpoint:
        //   if set we negotiate the best Content-Type, if not set we default to the first supported by the current handler
        switch ($method) {
            case RequestMethod::GET:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
                break;
            default:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::INTERMEDIATE);
        }

        $response = $response->withHeader('Content-Type', $mime);

        MetadataHandler::buildIndex();

        $responseBody = json_encode(['litcal_metadata' => self::$metadataCalendars], JSON_THROW_ON_ERROR);
        $responseHash = md5($responseBody);
        $etag         = '"' . $responseHash . '"';
        $response     = $response->withHeader('ETag', $etag);

        if (
            isset($_SERVER['HTTP_IF_NONE_MATCH'])
            && is_string($_SERVER['HTTP_IF_NONE_MATCH'])
            && !empty($_SERVER['HTTP_IF_NONE_MATCH'])
            && trim($_SERVER['HTTP_IF_NONE_MATCH'], " \t\"") === $responseHash
        ) {
            return $response->withStatus(StatusCode::NOT_MODIFIED->value, StatusCode::NOT_MODIFIED->reason())
                            ->withHeader('Content-Length', '0');
        } else {
            $contentType = explode(';', $response->getHeaderLine('Content-Type'))[0];
            switch ($contentType) {
                case AcceptHeader::JSON->value:
                    return $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason())->withBody(Stream::create($responseBody));
                    // no break needed
                case AcceptHeader::YAML->value:
                    if (!extension_loaded('yaml')) {
                        throw new ImplementationException('YAML extension not loaded');
                    }
                    $responseBodyObj = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

                    set_error_handler([static::class, 'warningHandler'], E_WARNING);
                    try {
                        $yamlEncodedResponse = yaml_emit($responseBodyObj, YAML_UTF8_ENCODING);
                    } catch (\ErrorException $e) {
                        throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                    } finally {
                        restore_error_handler();
                    }
                    return $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason())->withBody(Stream::create($yamlEncodedResponse));
                    // no break needed
                default:
                    throw new UnsupportedMediaTypeException();
            }
        }
    }
}
