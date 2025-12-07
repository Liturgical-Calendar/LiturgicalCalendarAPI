<?php

namespace LiturgicalCalendar\Api\Handlers;

use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Handlers\Auth\ClientIpTrait;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ResourceConflictException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite\CatholicDiocesesMap;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Params\RegionalDataParams;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Stream;

/**
 * Handles the `/data` path of the API
 *
 * This is the path that handles source data for national and diocesan calendars.
 * The source data for these calendars can be created (PUT), or updated (PATCH),
 * or retrieved (GET), or deleted (DELETE).
 *
 * @phpstan-import-type MetadataCalendarsObject from \LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars
 * @phpstan-import-type LitCalItemObject from \LiturgicalCalendar\Api\Models\LitCalItem
 * @phpstan-import-type DiocesanLitCalItemObject from \LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItem
 * @phpstan-import-type NationalCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData
 * @phpstan-import-type DiocesanCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData
 * @phpstan-import-type WiderRegionCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData
 * @phpstan-import-type LitCalItemCreateNewFixedObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed
 * @phpstan-import-type LitCalItemCreateNewMobileObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile
 */
final class RegionalDataHandler extends AbstractHandler
{
    use ClientIpTrait;

    private readonly MetadataCalendars $CalendarsMetadata;
    private RegionalDataParams $params;
    private Logger $auditLogger;
    private string $clientIp = 'unknown';

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
        // Allow credentials for cross-origin cookie requests (required for authenticated PUT/PATCH/DELETE)
        $this->allowCredentials = true;
        /** @var \stdClass&object{litcal_metadata:MetadataCalendarsObject} $metadataObj */
        $metadataObj             = Utilities::jsonUrlToObject(Route::CALENDARS->path());
        $this->CalendarsMetadata = MetadataCalendars::fromObject($metadataObj->litcal_metadata);
        // Initialize the list of available locales
        LitLocale::init();
        // Initialize audit logger for write operations
        $this->auditLogger = LoggerFactory::create('audit', null, 90, false, true, false);
    }

    /**
     * Handle the request method.
     *
     * Depending on the request method, it will call the appropriate class method to handle the request.
     */
    private function handleRequestMethod(RequestMethod $method, ResponseInterface $response): ResponseInterface
    {
        switch ($method) {
            case RequestMethod::GET:
                // no break (intentional fallthrough)
            case RequestMethod::POST:
                if (null !== $this->params->i18nRequest) {
                    // If a simple i18n data request was made, retrieve the i18n data
                    return $this->getI18nData($response);
                } else {
                    // Else retrieve the calendar data
                    return $this->getCalendar($response);
                }
                // no break (always terminates)
            case RequestMethod::PUT:
                return $this->createCalendar($response);
                // no break (always terminates)
            case RequestMethod::PATCH:
                return $this->updateCalendar($response);
                // no break (always terminates)
            case RequestMethod::DELETE:
                return $this->deleteCalendar($response);
                // no break (always terminates)
            default:
                throw new MethodNotAllowedException();
        }
    }

    /**
     * Handle GET and POST requests for i18n data.
     *
     * The request params should include the following values:
     * - `category`: the category of regional data to retrieve (DIOCESANCALENDAR, WIDERREGIONCALENDAR or NATIONALCALENDAR)
     * - `key`: the ID of the regional calendar to retrieve i18n data for
     * - `i18nRequest`: the locale to retrieve the i18n data for
     *
     * The method will return the i18n data for the requested calendar in the requested locale.
     * If the requested resource exists, it will be returned as JSON.
     * If the resource does not exist, a 404 error will be returned.
     */
    private function getI18nData(ResponseInterface $response): ResponseInterface
    {
        $i18nDataFile = null;
        switch ($this->params->category) {
            case PathCategory::DIOCESE:
                $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                });
                if (null === $dioceseEntry) {
                    $description = "The requested resource {$this->params->key} was not found in the index";
                    throw new NotFoundException($description);
                }
                $i18nDataFile = strtr(JsonData::DIOCESAN_CALENDAR_I18N_FILE->path(), [
                    '{nation}'  => $dioceseEntry->nation,
                    '{diocese}' => $this->params->key,
                    '{locale}'  => $this->params->i18nRequest
                ]);
                break;
            case PathCategory::WIDERREGION:
                $i18nDataFile = strtr(JsonData::WIDER_REGION_I18N_FILE->path(), [
                    '{wider_region}' => $this->params->key,
                    '{locale}'       => $this->params->i18nRequest
                ]);
                break;
            case PathCategory::NATION:
                $i18nDataFile = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), [
                    '{nation}' => $this->params->key,
                    '{locale}' => $this->params->i18nRequest
                ]);
                break;
        }
        if (file_exists($i18nDataFile)) {
            $i18nDataFileContents = Utilities::rawContentsFromFile($i18nDataFile);
            if ($response->getHeaderLine('Content-Type') === AcceptHeader::JSON->value) {
                return $response
                    ->withStatus(StatusCode::OK->value, StatusCode::OK->reason())
                    ->withBody(Stream::create($i18nDataFileContents));
            } else {
                /** @var array<string,string> $responseObj */
                $responseObj = json_decode($i18nDataFileContents, true, 512, JSON_THROW_ON_ERROR);
                return $this->encodeResponseBody($response, $responseObj);
            }
        } else {
            $description = "The requested resource {$i18nDataFile} was not found";
            throw new NotFoundException($description);
        }
    }

    /**
     * Handle GET and POST requests to retrieve a Regional Calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::handleRequestMethod()}.
     *
     * The `category` parameter is required and must be a valid case from the `PathCategory` enum.
     *
     * The `key` parameter is required and must be a valid key for the requested category.
     *
     * The `locale` parameter is optional.
     * If present, it must be a valid locale listed in the metadata of the requested calendar.
     * If not present, the first valid locale for the requested category will be used.
     *
     * If the requested resource exists, it will be returned as JSON.
     * If the resource does not exist, a 404 error will be returned.
     * If the `category` or `locale` parameters are invalid, a 400 error will be returned.
     */
    private function getCalendar(ResponseInterface $response): ResponseInterface
    {
        $calendarDataFile = null;
        $dioceseEntry     = null;
        switch ($this->params->category) {
            case PathCategory::DIOCESE:
                $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                });
                if (null === $dioceseEntry) {
                    $description = "The requested resource {$this->params->key} was not found in the index";
                    throw new NotFoundException($description);
                }

                $calendarDataFile = strtr(JsonData::DIOCESAN_CALENDAR_FILE->path(), [
                    '{nation}'       => $dioceseEntry->nation,
                    '{diocese}'      => $this->params->key,
                    '{diocese_name}' => $dioceseEntry->diocese
                ]);
                break;
            case PathCategory::WIDERREGION:
                $calendarDataFile = strtr(JsonData::WIDER_REGION_FILE->path(), [
                    '{wider_region}' => $this->params->key
                ]);
                break;
            case PathCategory::NATION:
                $calendarDataFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), [
                    '{nation}' => $this->params->key
                ]);
                break;
        }

        if (file_exists($calendarDataFile)) {
            /** @var NationalCalendarDataObject|DiocesanCalendarDataObject|WiderRegionCalendarDataObject $CalendarData */
            $CalendarData = Utilities::jsonFileToObject($calendarDataFile);

            // If a locale was not requested, use the first valid locale for the current requested calendar data
            // Else if a locale was requested, make sure it is a valid locale for the current requested calendar data
            if (null === $this->params->locale) {
                $this->params->locale = $CalendarData->metadata->locales[0];
            } elseif (false === in_array($this->params->locale, $CalendarData->metadata->locales, true)) {
                $description = "Invalid value `{$this->params->locale}` for param `locale`. Valid values for current requested Wider region calendar data `{$this->params->key}` are: "
                        . implode(', ', $CalendarData->metadata->locales);
                throw new ValidationException($description);
            }

            // Based on the locale requested, retrieve the appropriate locale data
            switch ($this->params->category) {
                case PathCategory::DIOCESE:
                    /** @var MetadataDiocesanCalendarItem $dioceseEntry */
                    $CalendarDataI18nFile = strtr(JsonData::DIOCESAN_CALENDAR_I18N_FILE->path(), [
                        '{nation}'  => $dioceseEntry->nation,
                        '{diocese}' => $this->params->key,
                        '{locale}'  => $this->params->locale
                    ]);
                    break;
                case PathCategory::WIDERREGION:
                    $CalendarDataI18nFile = strtr(JsonData::WIDER_REGION_I18N_FILE->path(), [
                        '{wider_region}' => $this->params->key,
                        '{locale}'       => $this->params->locale
                    ]);
                    break;
                case PathCategory::NATION:
                    $CalendarDataI18nFile = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), [
                        '{nation}' => $this->params->key,
                        '{locale}' => $this->params->locale
                    ]);
                    break;
                default:
                    $CalendarDataI18nFile = null;
            }

            if (null !== $CalendarDataI18nFile) {
                $localeData = Utilities::jsonFileToObject($CalendarDataI18nFile);
                /** @var array<LitCalItemObject|DiocesanLitCalItemObject> $litCalItems */
                $litCalItems = $CalendarData->litcal;
                foreach ($litCalItems as $litCalItem) {
                    /** @var LitCalItemCreateNewFixedObject|LitCalItemCreateNewMobileObject $liturgicalEvent */
                    $liturgicalEvent = $litCalItem->liturgical_event;
                    /** @var string $eventKey */
                    $eventKey = $liturgicalEvent->event_key;
                    if (property_exists($localeData, $eventKey)) {
                        $liturgicalEvent->name = $localeData->{$eventKey};
                    }
                }
            } else {
                $description = "Requested file {$CalendarDataI18nFile} does not exist";
                throw new NotFoundException($description);
            }

            return $this->encodeResponseBody($response, $CalendarData);
        } else {
            $description = "Requested file {$calendarDataFile} does not exist";
            throw new NotFoundException($description);
        }
    }

    /**
     * Handle PUT requests to create a diocesan calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::createCalendar()}.
     *
     * The diocesan calendar data resource is created in the `JsonData::DIOCESAN_CALENDARS_FOLDER` directory.
     *
     * This method ensures the necessary directories for storing diocesan calendar data are created.
     * It processes the internationalization (i18n) data provided in the payload, saving it to the appropriate
     * locale-specific files within the diocesan calendar directory structure.
     *
     * After processing and saving the i18n data, it removes it from the payload and writes the diocesan
     * calendar data to a JSON file named after the diocese, within a folder named after the diocese identifier,
     * within a folder named after the nation identifier.
     *
     * If the resource to create is not writable or the write was not successful,
     * a 503 Service Unavailable response is sent.
     *
     * On success, a 201 Created response is sent containing a success message.
     */
    private function createDiocesanCalendar(ResponseInterface $response): ResponseInterface
    {
        $payload    = $this->params->payload;
        $rawPayload = $this->params->rawPayload;
        if (false === $payload instanceof DiocesanData) {
            throw new UnprocessableContentException('Payload is not an instance of DiocesanData');
        }

        // Before creating a diocesan calendar, we verify that the diocese_id is a valid diocese identifier
        //  from our JSON database of Catholic dioceses of Latin Rite
        $diocese_id                = $payload->metadata->diocese_id;
        $nation                    = $payload->metadata->nation;
        $diocese_name              = $payload->metadata->diocese_name;
        $rawDiocesesCollection     = Utilities::jsonFileToObject(JsonData::CATHOLIC_DIOCESES_LATIN_RITE->path());
        $catholicDiocesesLatinRite = CatholicDiocesesMap::fromObject($rawDiocesesCollection);

        // Verify that the country ISO is valid
        if (false === $catholicDiocesesLatinRite->hasKey($nation)) {
            $description = "Invalid nation identifier $nation. Valid identifiers are: " . implode(', ', $catholicDiocesesLatinRite->getKeys());
            throw new UnprocessableContentException($description);
        }

        // Verify that the diocese identifier is valid for the given country ISO
        if (false === $catholicDiocesesLatinRite->isValidDioceseIdForCountry($nation, $diocese_id)) {
            $description = "Invalid diocese identifier: $diocese_id for diocese $diocese_name in nation $nation. Valid identifiers are: " . implode(', ', $catholicDiocesesLatinRite->getValidDioceseIdsForCountry($nation));
            throw new UnprocessableContentException($description);
        }

        // Ensure we have all the necessary folders in place
        // Since we are passing `true` to the `i18n` mkdir, all missing parent folders will also be created,
        // so we don't have to worry about manually checking and creating each one individually
        $diocesanCalendarI18nFolder = strtr(JsonData::DIOCESAN_CALENDAR_I18N_FOLDER->path(), [
            '{nation}'  => $nation,
            '{diocese}' => $diocese_id
        ]);
        if (!file_exists($diocesanCalendarI18nFolder)) {
            if (false === mkdir($diocesanCalendarI18nFolder, 0755, true)) {
                $description = "Failed to create directory {$diocesanCalendarI18nFolder}";
                throw new ServiceUnavailableException($description);
            }
        }

        // Write i18n files and capture locales for audit logging
        $i18nLocales = $this->writeI18nFiles(
            $rawPayload,
            JsonData::DIOCESAN_CALENDAR_I18N_FILE,
            ['{nation}' => $nation, '{diocese}' => $diocese_id]
        );

        $diocesanCalendarFile = strtr(
            JsonData::DIOCESAN_CALENDAR_FILE->path(),
            [
                '{nation}'       => $nation,
                '{diocese}'      => $diocese_id,
                '{diocese_name}' => $diocese_name
            ]
        );

        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (
            false === file_put_contents(
                $diocesanCalendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            $description = "Failed to write to file {$diocesanCalendarFile}";
            throw new ServiceUnavailableException($description);
        }

        // Log successful creation
        $this->auditLogger->info('Diocesan calendar created', [
            'operation'    => 'PUT',
            'category'     => 'diocese',
            'diocese_id'   => $diocese_id,
            'diocese_name' => $diocese_name,
            'nation'       => $nation,
            'client_ip'    => $this->clientIp,
            'files'        => [
                'calendar' => $diocesanCalendarFile,
                'i18n'     => $i18nLocales
            ]
        ]);

        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data created or updated for Diocese \"{$diocese_name}\" (Nation: \"{$nation}\")";
        $responseObj->data    = $rawPayload;
        return $this->encodeResponseBody($response, $responseObj, StatusCode::CREATED);
    }

    /**
     * Handle PUT requests to create or update a national calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::createCalendar()}.
     *
     * The national calendar data resource is created in the `jsondata/sourcedata/calendars/nations/` directory.
     *
     * This method ensures the necessary directories for storing national calendar data are created.
     * It processes the internationalization (i18n) data provided in the payload, saving it to the appropriate
     * locale-specific files within the national calendar directory structure.
     *
     * After processing and saving the i18n data, it removes it from the payload and writes the national
     * calendar data to a JSON file named after the nation identifier.
     *
     * On successful creation of the national calendar data,
     * a 201 Created response is sent containing a success message.
     */
    private function createNationalCalendar(ResponseInterface $response): ResponseInterface
    {
        $payload    = $this->params->payload;
        $rawPayload = $this->params->rawPayload;
        if (false === $payload instanceof NationalData) {
            throw new UnprocessableContentException('Payload is not an instance of NationalData');
        }

        $nation = $payload->metadata->nation;

        // Ensure we have all the necessary folders in place
        // Since we are passing `true` to the `i18n` mkdir, all missing parent folders will also be created,
        // so we don't have to worry about manually checking and creating each one individually
        $nationalCalendarI18nFolder = strtr(JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(), [
            '{nation}' => $nation
        ]);
        if (!file_exists($nationalCalendarI18nFolder)) {
            if (false === mkdir($nationalCalendarI18nFolder, 0755, true)) {
                $description = "Failed to create directory {$nationalCalendarI18nFolder}";
                throw new ServiceUnavailableException($description);
            }
        }

        // Write i18n files and capture locales for audit logging
        $i18nLocales = $this->writeI18nFiles(
            $rawPayload,
            JsonData::NATIONAL_CALENDAR_I18N_FILE,
            ['{nation}' => $nation]
        );

        $nationalCalendarFile = strtr(
            JsonData::NATIONAL_CALENDAR_FILE->path(),
            [
                '{nation}' => $nation
            ]
        );

        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (
            false === file_put_contents(
                $nationalCalendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            $description = "Failed to write to file {$nationalCalendarFile}";
            throw new ServiceUnavailableException($description);
        }

        // get the nation name in English from the two letter iso code
        $nationEnglish = \Locale::getDisplayRegion('-' . $nation, 'en');

        // Log successful creation
        $this->auditLogger->info('National calendar created', [
            'operation'   => 'PUT',
            'category'    => 'nation',
            'nation'      => $nation,
            'nation_name' => $nationEnglish,
            'client_ip'   => $this->clientIp,
            'files'       => [
                'calendar' => $nationalCalendarFile,
                'i18n'     => $i18nLocales
            ]
        ]);

        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data created or updated for Nation \"{$nationEnglish}\" (\"{$nation}\")";
        $responseObj->data    = $rawPayload;
        return $this->encodeResponseBody($response, $responseObj, StatusCode::CREATED);
    }

    /**
     * Handle PUT requests to create a wider region calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::createCalendar()}.
     *
     * The resource is created in the `jsondata/sourcedata/calendars/wider_regions/` directory.
     *
     * This method ensures the necessary directories for storing wider region calendar data are created.
     * It processes the internationalization (i18n) data provided in the payload, saving it to the appropriate
     * locale-specific files within the wider region calendar directory structure.
     *
     * After processing and saving the i18n data, it removes it from the payload and writes the wider region
     * calendar data to a JSON file named after the wider region identifier.
     *
     * On successful creation of the wider region calendar data,
     * a 201 Created response is sent containing a success message.
     */
    private function createWiderRegionCalendar(ResponseInterface $response): ResponseInterface
    {
        $payload    = $this->params->payload;
        $rawPayload = $this->params->rawPayload;
        if (false === $payload instanceof WiderRegionData) {
            $description = 'Payload is not an instance of WiderRegionData';
            throw new UnprocessableContentException($description);
        }

        $widerRegion = $payload->metadata->wider_region;

        // Ensure we have all the necessary folders in place
        // Since we are passing `true` to the `i18n` mkdir, all missing parent folders will also be created,
        // so we don't have to worry about manually checking and creating each one individually
        $widerRegionI18nFolder = strtr(JsonData::WIDER_REGION_I18N_FOLDER->path(), [
            '{wider_region}' => $widerRegion
        ]);
        if (!file_exists($widerRegionI18nFolder)) {
            if (false === mkdir($widerRegionI18nFolder, 0755, true)) {
                $description = "Failed to create directory {$widerRegionI18nFolder}";
                throw new ServiceUnavailableException($description);
            }
        }

        // Write i18n files and capture locales for audit logging
        $i18nLocales = $this->writeI18nFiles(
            $rawPayload,
            JsonData::WIDER_REGION_I18N_FILE,
            ['{wider_region}' => $widerRegion]
        );

        $widerRegionFile = strtr(
            JsonData::WIDER_REGION_FILE->path(),
            [
                '{wider_region}' => $widerRegion
            ]
        );

        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (
            false === file_put_contents(
                $widerRegionFile,
                $calendarData . PHP_EOL
            )
        ) {
            $description = "Failed to write to file {$widerRegionFile}";
            throw new ServiceUnavailableException($description);
        }

        // Log successful creation
        $this->auditLogger->info('Wider region calendar created', [
            'operation'    => 'PUT',
            'category'     => 'wider_region',
            'wider_region' => $widerRegion,
            'client_ip'    => $this->clientIp,
            'files'        => [
                'calendar' => $widerRegionFile,
                'i18n'     => $i18nLocales
            ]
        ]);

        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data created for Wider Region \"{$widerRegion}\"";
        $responseObj->data    = $rawPayload;
        return $this->encodeResponseBody($response, $responseObj, StatusCode::CREATED);
    }

    /**
     * Handle PUT requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::handleRequestMethod()}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/calendars/` directory.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 Unprocessable Content status code.
     *
     * If the payload is valid according to the associated schema,
     * the resource creation will continue according to the calendar type.
     */
    private function createCalendar(ResponseInterface $response): ResponseInterface
    {
        switch ($this->params->category) {
            case PathCategory::DIOCESE:
                return $this->createDiocesanCalendar($response);
                // no break (always terminates)
            case PathCategory::NATION:
                return $this->createNationalCalendar($response);
                // no break (always terminates)
            case PathCategory::WIDERREGION:
                return $this->createWiderRegionCalendar($response);
                // no break (always terminates)
            default:
                throw new UnprocessableContentException('unknown calendar category');
        }
    }

    /**
     * Handle PATCH requests to create or update a national calendar data resource.
     *
     * It is private as it is called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::updateCalendar()}.
     *
     * The resource is updated in the `jsondata/sourcedata/calendars/nations/` directory.
     *
     * If the resource to update is not found in the national calendars index, the response will be a JSON error response with a status code of 404 Not Found.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     *
     * If the update is successful, the response will be a JSON success response with a status code of 201 Created.
     */
    private function updateNationalCalendar(ResponseInterface $response): ResponseInterface
    {
        $payload    = $this->params->payload;
        $rawPayload = $this->params->rawPayload;
        if (false === $payload instanceof NationalData) {
            $description = 'Payload is not an instance of NationalData';
            throw new UnprocessableContentException($description);
        }

        $nationEntry = array_find($this->CalendarsMetadata->national_calendars, function ($item) {
            return $item->calendar_id === $this->params->key;
        });

        if (null === $nationEntry) {
            $description = "Cannot update unknown national calendar resource {$this->params->key}.";
            throw new NotFoundException($description);
        }

        // Update i18n files and clean up removed locales
        /** @var string $key Already validated to exist */
        $key         = $this->params->key;
        $i18nLocales = $this->updateI18nFiles(
            $rawPayload,
            JsonData::NATIONAL_CALENDAR_I18N_FILE,
            JsonData::NATIONAL_CALENDAR_I18N_FOLDER,
            ['{nation}' => $key],
            $payload->metadata->locales,
            "national calendar {$key}"
        );

        $calendarFile = strtr(
            JsonData::NATIONAL_CALENDAR_FILE->path(),
            [
                '{nation}' => $this->params->key
            ]
        );

        if (false === file_exists($calendarFile)) {
            $description = "Cannot update unknown national calendar resource {$this->params->key}, file {$calendarFile} does not exist.";
            throw new NotFoundException($description);
        }

        if (false === is_writable($calendarFile)) {
            $description = "Cannot update national calendar resource {$this->params->key}, file {$calendarFile} is not writable.";
            throw new ServiceUnavailableException($description);
        }

        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (
            false === file_put_contents(
                $calendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            $description = "Could not update national calendar resource {$this->params->key}, file {$calendarFile}.";
            throw new ServiceUnavailableException($description);
        }

        // get the nation name in English from the two letter iso code
        $nationEnglish = \Locale::getDisplayRegion('-' . $this->params->key, 'en');

        // Log successful update
        $this->auditLogger->info('National calendar updated', [
            'operation'   => 'PATCH',
            'category'    => 'nation',
            'nation'      => $this->params->key,
            'nation_name' => $nationEnglish,
            'client_ip'   => $this->clientIp,
            'files'       => [
                'calendar' => $calendarFile,
                'i18n'     => $i18nLocales
            ]
        ]);

        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data created or updated for Nation \"{$this->params->key}\"";
        $responseObj->data    = $rawPayload;
        return $this->encodeResponseBody($response, $responseObj, StatusCode::CREATED);
    }

    /**
     * Handle PATCH requests to update a wider region calendar data resource.
     *
     * It is private as it is called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::updateCalendar()}.
     *
     * The resource is updated in the `jsondata/sourcedata/wider_regions/` directory.
     *
     * If the resource to update is not found in the wider region calendars index, the response will be a JSON error response with a status code of 404 Not Found.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     *
     * If the update is successful, the response will be a JSON success response with a status code of 201 Created.
     */
    private function updateWiderRegionCalendar(ResponseInterface $response): ResponseInterface
    {
        $payload    = $this->params->payload;
        $rawPayload = $this->params->rawPayload;
        if (false === $payload instanceof WiderRegionData) {
            $description = 'Payload is not an instance of WiderRegionData';
            throw new UnprocessableContentException($description);
        }

        $widerRegionEntry = array_find($this->CalendarsMetadata->wider_regions, function ($item) {
            return $item->name === $this->params->key;
        });

        if (null === $widerRegionEntry) {
            $description = "Cannot update unknown wider region calendar resource {$this->params->key}.";
            throw new NotFoundException($description);
        }

        // Update i18n files and clean up removed locales
        /** @var string $key Already validated to exist */
        $key         = $this->params->key;
        $i18nLocales = $this->updateI18nFiles(
            $rawPayload,
            JsonData::WIDER_REGION_I18N_FILE,
            JsonData::WIDER_REGION_I18N_FOLDER,
            ['{wider_region}' => $key],
            $payload->metadata->locales,
            "wider region calendar {$key}"
        );

        $widerRegionFile = strtr(
            JsonData::WIDER_REGION_FILE->path(),
            [
                '{wider_region}' => $this->params->key
            ]
        );

        if (false === file_exists($widerRegionFile)) {
            $description = "Cannot update wider region calendar resource for {$this->params->key} at {$widerRegionFile}, file does not exist.";
            throw new NotFoundException($description);
        }

        if (false === is_writable($widerRegionFile)) {
            $description = "Cannot update wider region calendar resource for {$this->params->key} at {$widerRegionFile}, file is not writable.";
            throw new ServiceUnavailableException($description);
        }

        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (
            false === file_put_contents(
                $widerRegionFile,
                $calendarData . PHP_EOL
            )
        ) {
            $description = "Could not update wider region calendar resource for {$this->params->key} at {$widerRegionFile}.";
            throw new ServiceUnavailableException($description);
        }

        // Log successful update
        $this->auditLogger->info('Wider region calendar updated', [
            'operation'    => 'PATCH',
            'category'     => 'wider_region',
            'wider_region' => $this->params->key,
            'client_ip'    => $this->clientIp,
            'files'        => [
                'calendar' => $widerRegionFile,
                'i18n'     => $i18nLocales
            ]
        ]);

        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data created or updated for Wider Region \"{$this->params->key}\"";
        $responseObj->data    = $rawPayload;
        return $this->encodeResponseBody($response, $responseObj, StatusCode::CREATED);
    }

    /**
     * Handle PATCH requests to update a diocesan calendar data resource.
     *
     * It is private as it is called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::updateCalendar()}.
     *
     * The resource is updated in the {@see \LiturgicalCalendar\Api\Enum\JsonData::DIOCESAN_CALENDARS_FOLDER} folder.
     *
     * If the resource to update is not found in the diocesan calendars index, the response will be a JSON error response with a status code of 404 Not Found.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     *
     * If the update is successful, the response will be a JSON success response with a status code of 201 Created.
     */
    private function updateDiocesanCalendar(ResponseInterface $response): ResponseInterface
    {
        $payload    = $this->params->payload;
        $rawPayload = $this->params->rawPayload;
        if (false === $payload instanceof DiocesanData) {
            $description = 'Payload is not an instance of DiocesanData';
            throw new UnprocessableContentException($description);
        }

        $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($item) {
            return $item->calendar_id === $this->params->key;
        });

        if (null === $dioceseEntry) {
            $description = "Cannot update unknown diocesan calendar resource {$this->params->key}.";
            throw new NotFoundException($description);
        }

        // Update i18n files and clean up removed locales
        /** @var string $key Already validated to exist */
        $key         = $this->params->key;
        $i18nLocales = $this->updateI18nFiles(
            $rawPayload,
            JsonData::DIOCESAN_CALENDAR_I18N_FILE,
            JsonData::DIOCESAN_CALENDAR_I18N_FOLDER,
            ['{nation}' => $dioceseEntry->nation, '{diocese}' => $key],
            $payload->metadata->locales,
            "diocesan calendar {$key}"
        );

        $DiocesanCalendarFile = strtr(
            JsonData::DIOCESAN_CALENDAR_FILE->path(),
            [
                '{nation}'       => $dioceseEntry->nation,
                '{diocese}'      => $this->params->key,
                '{diocese_name}' => $dioceseEntry->diocese
            ]
        );

        if (false === file_exists($DiocesanCalendarFile)) {
            $description = "Cannot update diocesan calendar resource at {$DiocesanCalendarFile}, file not found.";
            throw new NotFoundException($description);
        }

        if (false === is_writable($DiocesanCalendarFile)) {
            $description = "Cannot update diocesan calendar resource for {$this->params->key} at {$DiocesanCalendarFile}, check file and folder permissions.";
            throw new ServiceUnavailableException($description);
        }

        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (
            false === file_put_contents(
                $DiocesanCalendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            $description = "Could not update diocesan calendar resource {$this->params->key} in path {$DiocesanCalendarFile}.";
            throw new ServiceUnavailableException($description);
        }

        // Log successful update
        $this->auditLogger->info('Diocesan calendar updated', [
            'operation'    => 'PATCH',
            'category'     => 'diocese',
            'diocese_id'   => $this->params->key,
            'diocese_name' => $dioceseEntry->diocese,
            'nation'       => $dioceseEntry->nation,
            'client_ip'    => $this->clientIp,
            'files'        => [
                'calendar' => $DiocesanCalendarFile,
                'i18n'     => $i18nLocales
            ]
        ]);

        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data created or updated for Diocese \"{$dioceseEntry->diocese}\" (Nation: \"{$dioceseEntry->nation}\")";
        $responseObj->data    = $rawPayload;
        return $this->encodeResponseBody($response, $responseObj, StatusCode::CREATED);
    }

    /**
     * Handle PATCH requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::handleRequestMethod()}.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 Unprocessable Content status code.
     *
     * If the payload is valid, the update process will continue according to the calendar type.
     */
    private function updateCalendar(ResponseInterface $response): ResponseInterface
    {
        switch ($this->params->category) {
            case PathCategory::DIOCESE:
                return $this->updateDiocesanCalendar($response);
                // no break (always terminates)
            case PathCategory::NATION:
                return $this->updateNationalCalendar($response);
                // no break (always terminates)
            case PathCategory::WIDERREGION:
                return $this->updateWiderRegionCalendar($response);
                // no break (always terminates)
            default:
                throw new ValidationException('Unknown calendar category');
        }
    }

    /**
     * Get the paths for deleting a regional calendar data resource.
     *
     * The return value is an array with two elements:
     * - The first element is the path to the JSON file containing the calendar data.
     * - The second element is the path to the folder containing the i18n data for the calendar.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::deleteCalendar()}.
     *
     * If the calendar for which deletion is requested is a diocesan calendar,
     * but a correponding entry is not found in the `/calendars` metadata index,
     * a 404 Not Found error response will be produced.
     *
     * @return string[] The paths for deleting a regional calendar data resource.
     */
    private function getPathsForCalendarDelete(): array
    {
        switch ($this->params->category) {
            case PathCategory::DIOCESE:
                $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                });
                if (null === $dioceseEntry) {
                    $description = "The resource requested for deletion {$this->params->key} is not known.";
                    throw new NotFoundException($description);
                }
                $calendarDataFile   = strtr(
                    JsonData::DIOCESAN_CALENDAR_FILE->path(),
                    [
                        '{nation}'       => $dioceseEntry->nation,
                        '{diocese}'      => $dioceseEntry->calendar_id,
                        '{diocese_name}' => $dioceseEntry->diocese
                    ]
                );
                $calendarI18nFolder = strtr(
                    JsonData::DIOCESAN_CALENDAR_I18N_FOLDER->path(),
                    [
                        '{nation}'  => $dioceseEntry->nation,
                        '{diocese}' => $dioceseEntry->calendar_id
                    ]
                );
                break;
            case PathCategory::WIDERREGION:
                $calendarDataFile   = strtr(
                    JsonData::WIDER_REGION_FILE->path(),
                    [
                        '{wider_region}' => $this->params->key
                    ]
                );
                $calendarI18nFolder = strtr(
                    JsonData::WIDER_REGION_I18N_FOLDER->path(),
                    [
                        '{wider_region}' => $this->params->key
                    ]
                );
                break;
            case PathCategory::NATION:
                $calendarDataFile   = strtr(
                    JsonData::NATIONAL_CALENDAR_FILE->path(),
                    [
                        '{nation}' => $this->params->key
                    ]
                );
                $calendarI18nFolder = strtr(
                    JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(),
                    [
                        '{nation}' => $this->params->key
                    ]
                );
                break;
            default:
                throw new \RuntimeException('Stupefy yourselves and stay stupid; blind yourselves and stay blind! - Isaiah 29:9');
        }

        return [$calendarDataFile, $calendarI18nFolder];
    }

    /**
     * Handle DELETE requests to delete a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler::handleRequestMethod()}.
     *
     * The resource is deleted from the `jsondata/sourcedata/calendars/` directory.
     *
     * If the resource is successfully deleted, the response will be a JSON object
     * containing a success message.
     *
     * If the resource does not exist, a 404 error will be returned.
     */
    private function deleteCalendar(ResponseInterface $response): ResponseInterface
    {
        $dioceseNationFolder = null;

        [$calendarDataFile, $calendarI18nFolder] = $this->getPathsForCalendarDelete();

        if (file_exists($calendarDataFile) && file_exists($calendarI18nFolder)) {
            if (false === is_writable($calendarDataFile)) {
                $description = "The resource '{$this->params->key}' requested for deletion is not writable.";
                throw new ServiceUnavailableException($description);
            }

            // We want to make sure to also remove the containing folder, let's get the parent folder for later removal
            $calendarDataFolder = dirname($calendarDataFile);

            // And in the case of a diocesan calendar, if the parent `nation_id` folder is empty, remove it as well
            // so let's get a reference to the parent folder to check later
            if ($this->params->category === PathCategory::DIOCESE) {
                $dioceseNationFolder = dirname($calendarDataFolder);
            }

            if (false === unlink($calendarDataFile)) {
                $description = "The resource '{$this->params->key}' requested for deletion was not removed successfully.";
                throw new ServiceUnavailableException($description);
            };

            $calendarI18nFiles = glob($calendarI18nFolder . '/*.json');
            if (false === $calendarI18nFiles) {
                $description = "Unable to list i18n files from the folder {$calendarI18nFolder}.";
                throw new ServiceUnavailableException($description);
            }

            foreach ($calendarI18nFiles as $file) {
                if (false === is_writable($file)) {
                    $description = "The i18n file '{$file}' is not writable, cannot remove.";
                    throw new ServiceUnavailableException($description);
                }
                if (false === unlink($file)) {
                    $description = "The i18n file '{$file}' could not be removed.";
                    throw new ServiceUnavailableException($description);
                };
            }
            if (false === rmdir($calendarI18nFolder)) {
                $description = "The i18n folder '{$calendarI18nFolder}' could not be removed.";
                throw new ServiceUnavailableException($description);
            }
            if (false === rmdir($calendarDataFolder)) {
                $description = "The resource '{$this->params->key}' requested for deletion was not removed successfully, data folder could not be removed.";
                throw new ServiceUnavailableException($description);
            }
            if ($this->params->category === PathCategory::DIOCESE && $dioceseNationFolder !== null) {
                // Check if the parent `nation_id` folder is empty, if it is, remove it too
                if (count(scandir($dioceseNationFolder)) === 2) { // only . and ..
                    if (false === rmdir($dioceseNationFolder)) {
                        $description = "The resource '{$this->params->key}' requested for deletion was not removed successfully, diocese nation folder could not be removed.";
                        throw new ServiceUnavailableException($description);
                    }
                }
            }
        } else {
            $description = "The resource '{$this->params->key}' requested for deletion (or the relative i18n folder) was not found on this server.";
            throw new NotFoundException($description);
        }

        // Log successful deletion
        $this->auditLogger->info('Calendar deleted', [
            'operation' => 'DELETE',
            'category'  => $this->params->category->value,
            'key'       => $this->params->key,
            'client_ip' => $this->clientIp,
            'files'     => [
                'calendar'    => $calendarDataFile,
                'i18n_folder' => $calendarI18nFolder
            ]
        ]);

        // RFC 9110 Section 9.3.5: "a 200 (OK) status code if the action has been enacted
        // and the response message includes a representation describing the status."
        // We use 200 (not 204) because we include a success message in the response body.
        // 204 No Content cannot have content per RFC 9110 Section 15.3.5.
        $responseObj          = new \stdClass();
        $responseObj->success = "Calendar data \"{$this->params->category->value}/{$this->params->key}\" deletion successful.";
        return $this->encodeResponseBody($response, $responseObj, StatusCode::OK);
    }


    /**
     * Write i18n data from raw payload to locale-specific files.
     *
     * Extracts i18n data from the raw payload, writes each locale's translations
     * to a separate JSON file, and removes the i18n property from the payload.
     *
     * @param \stdClass $rawPayload The raw payload containing i18n data
     * @param JsonData $i18nFileEnum The JsonData enum case for the i18n file path pattern
     * @param array<string, string> $baseSubstitutions Substitutions for the file path (without {locale})
     * @return string[] Array of locale codes that were written
     * @throws ServiceUnavailableException If writing to a file fails
     */
    private function writeI18nFiles(\stdClass $rawPayload, JsonData $i18nFileEnum, array $baseSubstitutions): array
    {
        /** @var array<string, \stdClass> $rawI18n */
        $rawI18n = (array) $rawPayload->i18n;

        foreach ($rawI18n as $locale => $litCalEventsI18n) {
            $substitutions             = $baseSubstitutions;
            $substitutions['{locale}'] = $locale;
            $i18nFile                  = strtr($i18nFileEnum->path(), $substitutions);

            if (
                false === file_put_contents(
                    $i18nFile,
                    json_encode($litCalEventsI18n, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL
                )
            ) {
                throw new ServiceUnavailableException("Failed to write to file {$i18nFile}");
            }
        }

        // Remove i18n from raw payload before writing calendar file
        unset($rawPayload->i18n);

        return array_keys($rawI18n);
    }

    /**
     * Update i18n files for a calendar resource (PATCH operations).
     *
     * This helper handles the more complex i18n update logic required for PATCH:
     * 1. Iterates over rawPayload->i18n and updates each locale's file (with existence/writability checks)
     * 2. Cleans up removed locale files by comparing existing files with metadata->locales
     * 3. Removes i18n from the raw payload before returning
     *
     * @param \stdClass $rawPayload The raw payload containing i18n data
     * @param JsonData $i18nFileEnum The JsonData enum case for the i18n file path pattern
     * @param JsonData $i18nFolderEnum The JsonData enum case for the i18n folder path pattern
     * @param array<string, string> $baseSubstitutions Substitutions for the file path (without {locale})
     * @param string[] $metadataLocales The locales from metadata (to determine which files to keep)
     * @param string $resourceDescription Description of the resource for error messages
     * @return string[] Array of locale codes that were written
     * @throws NotFoundException If an i18n file to update does not exist
     * @throws ServiceUnavailableException If a file is not writable or write/delete fails
     */
    private function updateI18nFiles(
        \stdClass $rawPayload,
        JsonData $i18nFileEnum,
        JsonData $i18nFolderEnum,
        array $baseSubstitutions,
        array $metadataLocales,
        string $resourceDescription
    ): array {
        /** @var array<string, \stdClass> $rawI18n */
        $rawI18n = (array) $rawPayload->i18n;

        // Update existing i18n files
        foreach ($rawI18n as $locale => $i18nData) {
            $substitutions             = $baseSubstitutions;
            $substitutions['{locale}'] = $locale;
            $i18nFile                  = strtr($i18nFileEnum->path(), $substitutions);

            if (false === file_exists($i18nFile)) {
                throw new NotFoundException(
                    "Cannot update {$resourceDescription} i18n resource, file {$i18nFile} does not exist."
                );
            }

            if (false === is_writable($i18nFile)) {
                throw new ServiceUnavailableException(
                    "Cannot update {$resourceDescription} i18n resource, file {$i18nFile} is not writable."
                );
            }

            $i18nContent = json_encode($i18nData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (false === file_put_contents($i18nFile, $i18nContent . PHP_EOL)) {
                throw new ServiceUnavailableException(
                    "Could not update {$resourceDescription} i18n resource at {$i18nFile}."
                );
            }
        }

        // Clean up removed locale files
        $i18nFolder = strtr($i18nFolderEnum->path(), $baseSubstitutions);
        $jsonFiles  = glob("{$i18nFolder}/*.json");

        if (false === $jsonFiles) {
            throw new ServiceUnavailableException(
                "Unable to list {$resourceDescription} i18n files in folder {$i18nFolder}."
            );
        }

        foreach ($jsonFiles as $jsonFile) {
            $filename = pathinfo($jsonFile, PATHINFO_FILENAME);
            if (false === in_array($filename, $metadataLocales)) {
                if (false === unlink($jsonFile)) {
                    throw new ServiceUnavailableException(
                        "Unable to delete {$resourceDescription} i18n file {$jsonFile}."
                    );
                }
            }
        }

        // Remove i18n from raw payload before writing calendar file
        unset($rawPayload->i18n);

        return array_keys($rawI18n);
    }

    /**
     * Validate payload data against a schema
     *
     * @param \stdClass $data Data to validate
     * @param string $schemaUrl  Schema to validate against
     *
     * @return boolean
     */
    private static function validateDataAgainstSchema(\stdClass $data, string $schemaUrl): bool
    {
        $schema = Schema::import($schemaUrl);
        try {
            $schema->in($data);
            return true;
        } catch (InvalidValue | \Exception $e) {
            $litSchema = LitSchema::fromURL($schemaUrl);
            throw new UnprocessableContentException(
                $litSchema->error(),
                $e
            );
        }
    }



    /**
     * Validate the request path parts for the RegionalData resource.
     *
     * @throws ValidationException When the request path is invalid
     */
    private function validateRequestPath(ServerRequestInterface $request): void
    {
        $method = RequestMethod::from($request->getMethod());

        switch ($method) {
            case RequestMethod::GET:
                // no break (intentional fallthrough)
            case RequestMethod::POST:
                if (count($this->requestPathParams) < 2 || count($this->requestPathParams) > 3) {
                    $description = 'Expected at least two and at most three path params for GET and POST requests, received ' . count($this->requestPathParams);
                    throw new ValidationException($description);
                }
                break;
            case RequestMethod::PUT:
                if (count($this->requestPathParams) !== 1) {
                    $description = 'Expected one path param for PUT requests, received ' . count($this->requestPathParams);
                    throw new ValidationException($description);
                }
                break;
            case RequestMethod::PATCH:
                // no break (intentional fallthrough)
            case RequestMethod::DELETE:
                if (count($this->requestPathParams) !== 2) {
                    $description = 'Expected two path params for PATCH and DELETE requests, received ' . count($this->requestPathParams);
                    throw new ValidationException($description);
                }
                break;
        }

        // In all cases, we check if the category param is valid
        if (false === PathCategory::isValid($this->requestPathParams[0])) {
            $description = "Unexpected path param {$this->requestPathParams[0]}, acceptable values are: "
                . implode(', ', PathCategory::values());
            throw new ValidationException($description);
        }
    }

    /**
     * Validate the parameters provided to the RegionalData class for a National Calendar.
     *
     * The method checks the following, given that the `category` parameter is "NATIONALCALENDAR":
     * - The `key` parameter is a valid nation.
     * - The `locale` parameter is a valid locale for the given nation.
     * - If the request method is PUT, the National Calendar data does not already exist.
     * - If the request method is DELETE, the National Calendar data is not in use by a Diocesan calendar.
     *
     * If any of the checks fail, the method will produce an error response with a 400 status code.
     */
    private function checkNationalCalendarConditions(RequestMethod $method, RegionalDataParams $params): void
    {
        if ($method === RequestMethod::PUT) {
            // Cannot PUT National calendar data if it already exists
            if (in_array($params->key, $this->CalendarsMetadata->national_calendars_keys)) {
                $description = 'National calendar data already exists for nation with ID: ' . $params->key;
                throw new ResourceConflictException($description);
            }

            $uniqueRegions = array_values(array_unique(array_filter(
                array_map(static function (string $locale): string {
                    $region = \Locale::getRegion($locale);
                    if (null === $region) {
                        throw new ServiceUnavailableException('Unable to determine region for locale ' . $locale);
                    }
                    return $region;
                }, LitLocale::$AllAvailableLocales),
                static fn (string $r): bool => $r !== ''
            )));
            if (false === in_array($params->key, $uniqueRegions, true)) {
                $description = "Cannot PUT National Calendar data for invalid nation ID {$params->key}. Valid nation IDs (as supported by the current server configuration) are: " . implode(', ', $uniqueRegions);
                throw new UnprocessableContentException($description);
            }
        } elseif ($method === RequestMethod::DELETE) {
            // Cannot DELETE a National calendar data if it is still in use by a Diocesan calendar
            foreach ($this->CalendarsMetadata->diocesan_calendars as $diocesanCalendar) {
                if ($diocesanCalendar->nation === $params->key) {
                    $description = "Cannot DELETE National Calendar data while there are Diocesan calendars that depend on it. Currently, {$params->key} is in use by Diocesan calendar {$diocesanCalendar->calendar_id}.";
                    throw new UnprocessableContentException($description);
                }
            }
        }

        // We must verify the `key` parameter for any request that is not PUT
        $currentNation = null;
        if ($method !== RequestMethod::PUT) {
            if (false === in_array($params->key, $this->CalendarsMetadata->national_calendars_keys)) {
                $validVals   = implode(', ', $this->CalendarsMetadata->national_calendars_keys);
                $description = "Invalid value {$params->key} for param `key`, valid values are: {$validVals}";
                throw new UnprocessableContentException($description);
            } else {
                $currentNation = array_find($this->CalendarsMetadata->national_calendars, fn (MetadataNationalCalendarItem $el) => $el->calendar_id === $params->key);
                if (null === $currentNation) {
                    $description = "Invalid value {$params->key} for param `key`, valid values are: "
                        . implode(', ', $this->CalendarsMetadata->national_calendars_keys);
                    throw new UnprocessableContentException($description);
                }
            }
        }


        // we don't care about locale for DELETE or PUT requests
        if (false === in_array($method, [RequestMethod::DELETE, RequestMethod::PUT], true)) {
            /** @var MetadataNationalCalendarItem $currentNation */
            $this->validateLocaleForCalendar($params, $currentNation->locales);
        }
    }

    /**
     * Validate the parameters provided to the RegionalData class for a Diocesan Calendar.
     *
     * The method checks the following, given that the `category` parameter is "DIOCESANCALENDAR":
     * - The `key` parameter is a valid diocesan calendar key.
     * - The `locale` parameter is a valid locale for the given diocesan calendar. ::TODO::
     * - If the request method is PUT, the Diocesan Calendar data does not already exist.
     *
     * If any of the checks fail, the method will produce an error response with a 400 status code.
     */
    private function checkDiocesanCalendarConditions(RequestMethod $method, RegionalDataParams $params): void
    {
        if ($method === RequestMethod::PUT) {
            // Cannot PUT Diocesan calendar data if it already exists
            if (in_array($params->key, $this->CalendarsMetadata->diocesan_calendars_keys)) {
                $description = 'Diocesan calendar data already exists for diocese with ID: ' . $params->key;
                throw new ResourceConflictException($description);
            }
        }

        // For all requests other than PUT, we expect the diocese_id to exist
        $currentDiocese = null;
        if ($method !== RequestMethod::PUT) {
            if (false === in_array($params->key, $this->CalendarsMetadata->diocesan_calendars_keys)) {
                $validVals   = implode(', ', $this->CalendarsMetadata->diocesan_calendars_keys);
                $description = "Invalid value {$params->key} for param `key`, valid values are: {$validVals}";
                throw new UnprocessableContentException($description);
            } else {
                $currentDiocese = array_find($this->CalendarsMetadata->diocesan_calendars, fn (MetadataDiocesanCalendarItem $el) => $el->calendar_id === $params->key);
                if (null === $currentDiocese) {
                    $description = "Invalid value {$params->key} for param `key`, valid values are: "
                        . implode(', ', $this->CalendarsMetadata->diocesan_calendars_keys);
                    throw new UnprocessableContentException($description);
                }
            }
        }

        // we don't care about locale for DELETE or PUT requests
        if (false === in_array($method, [RequestMethod::DELETE, RequestMethod::PUT], true)) {
            /** @var MetadataDiocesanCalendarItem $currentDiocese */
            $this->validateLocaleForCalendar($params, $currentDiocese->locales);
        }
    }

    /**
     * Validate the parameters provided to the RegionalData class for a Wider Region Calendar.
     *
     * The method checks the following, given that the `category` parameter is "WIDERREGIONCALENDAR":
     * - The `key` parameter is a valid wider region calendar key.
     * - The `locale` parameter is a valid locale for the given wider region calendar.
     * - If the request method is PUT, the Wider Region Calendar data does not already exist. ::TODO::
     * - If the request method is DELETE, there are no national calendars that depend on the wider region calendar.
     *
     * If any of the checks fail, the method will produce an error response with a 400 status code.
     */
    private function checkWiderRegionCalendarConditions(RequestMethod $method, RegionalDataParams $params): void
    {
        if ($method === RequestMethod::PUT) {
            // Cannot PUT Wider Region calendar data if it already exists
            if (in_array($params->key, $this->CalendarsMetadata->wider_regions_keys)) {
                $description = "Cannot create Wider Region calendar with id: {$params->key}, since there is already a resource with that id. Perhaps you meant to use PATCH?";
                throw new ResourceConflictException($description);
            }
        } elseif ($method === RequestMethod::DELETE) {
            // Cannot DELETE Wider Region calendar data if there are national calendars that depend on it
            $national_calendars_within_wider_region = array_values(array_filter(
                $this->CalendarsMetadata->national_calendars,
                fn ($el) => $el->wider_region === $params->key
            ));
            if (count($national_calendars_within_wider_region) > 0) {
                $description = 'Cannot DELETE Wider Region calendar data while there are National calendars that depend on it. '
                    . "Currently {$params->key} is in use by the National Calendars: " . implode(', ', array_map(fn ($el) => \Locale::getDisplayRegion('-' . $el->calendar_id, 'en'), $national_calendars_within_wider_region));
                throw new UnprocessableContentException($description);
            }
        }

        // For methods other than PUT, check that the key is valid
        $currentWiderRegion = null;
        if ($method !== RequestMethod::PUT) {
            if (
                false === in_array($params->key, $this->CalendarsMetadata->wider_regions_keys, true)
            ) {
                $validVals   = implode(', ', $this->CalendarsMetadata->wider_regions_keys);
                $description = "Invalid value {$params->key} for param `key`, valid values are: {$validVals}";
                throw new UnprocessableContentException($description);
            } else {
                $currentWiderRegion = array_find($this->CalendarsMetadata->wider_regions, fn (MetadataWiderRegionItem $el) => $el->name === $params->key);
                if (null === $currentWiderRegion) {
                    $description = "Could not find Wider Region metadata for wider region {$params->key}.";
                    throw new UnprocessableContentException($description);
                }
            }
        }

        // we don't care about locale for DELETE or PUT requests
        if (false === in_array($method, [RequestMethod::DELETE, RequestMethod::PUT], true)) {
            /** @var MetadataWiderRegionItem $currentWiderRegion */
            $this->validateLocaleForCalendar($params, $currentWiderRegion->locales);
        }
    }

    /**
     * Validate the locale parameter for a regional calendar request.
     *
     * Checks that the locale is valid for the given calendar, unless this is an i18n request.
     * Also validates that locale is present when required for i18n requests.
     *
     * @param RegionalDataParams $params The request parameters containing locale and key.
     * @param string[] $validLangs The valid locales for the calendar.
     * @throws UnprocessableContentException If locale validation fails.
     */
    private function validateLocaleForCalendar(RegionalDataParams $params, array $validLangs): void
    {
        if (isset($params->locale)) {
            if (
                null === $params->i18nRequest // short circuit for i18n requests
                && false === in_array($params->locale, $validLangs, true)
            ) {
                $message = "Invalid value {$params->locale} for param `locale`, valid values for calendar {$params->key} are: "
                            . implode(', ', $validLangs);
                throw new UnprocessableContentException($message);
            }
        } else {
            if (null !== $params->i18nRequest) {
                $description = 'Missing param `locale`';
                throw new UnprocessableContentException($description);
            }
        }
    }

    /**
     * Initializes the RegionalData class.
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - If the $requestPathParts argument is not empty, it will set the request path parts
     * - It will validate the request content type
     * - It will set the request headers
     * - It will load the Diocesan Calendars index
     * - It will handle the request method
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

        // Capture client IP for audit logging
        /** @var array<string, mixed> $serverParams */
        $serverParams   = $request->getServerParams();
        $this->clientIp = $this->getClientIp($request, $serverParams);

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

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is both read and write:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters
        //   - for PUT and PATCH requests we will have a payload in the request body
        //   - for DELETE requests we will have neither payload nor request parameters, only path parameters

        /** @var array{category:PathCategory,key:string,i18n?:string,locale?:string,payload?:DiocesanData|NationalData|WiderRegionData} $params */
        $params = [];

        // We always expect the category to be set in the request path
        // We expect the key to be set in the request path for GET, POST, PATCH and DELETE requests
        $this->validateRequestPath($request);

        $params['category'] = PathCategory::from($this->requestPathParams[0]);
        if (in_array($method, [RequestMethod::GET, RequestMethod::POST, RequestMethod::PATCH, RequestMethod::DELETE], true)) {
            $params['key'] = $this->requestPathParams[1];
        }

        // Second of all, we check if an Accept-Language header was set in the request
        $locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        if ($method === RequestMethod::GET) {
            /** @var array{category:PathCategory,key:string,i18n?:string,locale:string,payload?:DiocesanData|NationalData|WiderRegionData} $params */
            $params = array_merge($params, $this->getScalarQueryParams($request));
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array{category:PathCategory,key:string,i18n?:string,locale:string,payload?:DiocesanData|NationalData|WiderRegionData} $params */
                $params = array_merge($params, $parsedBodyParams);
            }
        } elseif ($method === RequestMethod::PUT || $method === RequestMethod::PATCH) {
            $payload = $this->parseBodyPayload($request, false);
            if (false === ( $payload instanceof \stdClass )) {
                throw new ValidationException('Invalid payload');
            }
            switch ($params['category']) {
                case PathCategory::DIOCESE:
                    if (RegionalDataHandler::validateDataAgainstSchema($payload, LitSchema::DIOCESAN->path())) {
                        // Schema marks i18n as optional (for stored files), but it's required for PUT/PATCH
                        if (!property_exists($payload, 'i18n')) {
                            throw new UnprocessableContentException('The i18n property is required for PUT/PATCH operations');
                        }
                        // A calendar must have at least one liturgical event
                        if (empty($payload->litcal)) {
                            throw new UnprocessableContentException('The litcal array must contain at least one liturgical event');
                        }
                        $params['rawPayload'] = $payload;  // Store raw for writing to disk
                        $params['payload']    = DiocesanData::fromObject($payload);  // DTO for property access
                        $key                  = $params['payload']->metadata->diocese_id;
                    }
                    break;
                case PathCategory::NATION:
                    if (RegionalDataHandler::validateDataAgainstSchema($payload, LitSchema::NATIONAL->path())) {
                        // Schema marks i18n as optional (for stored files), but it's required for PUT/PATCH
                        if (!property_exists($payload, 'i18n')) {
                            throw new UnprocessableContentException('The i18n property is required for PUT/PATCH operations');
                        }
                        // A calendar must have at least one liturgical event
                        if (empty($payload->litcal)) {
                            throw new UnprocessableContentException('The litcal array must contain at least one liturgical event');
                        }
                        $params['rawPayload'] = $payload;  // Store raw for writing to disk
                        $params['payload']    = NationalData::fromObject($payload);  // DTO for property access
                        $key                  = $params['payload']->metadata->nation;
                    }
                    break;
                case PathCategory::WIDERREGION:
                    if (RegionalDataHandler::validateDataAgainstSchema($payload, LitSchema::WIDERREGION->path())) {
                        // Schema marks i18n as optional (for stored files), but it's required for PUT/PATCH
                        if (!property_exists($payload, 'i18n')) {
                            throw new UnprocessableContentException('The i18n property is required for PUT/PATCH operations');
                        }
                        // A calendar must have at least one liturgical event
                        if (empty($payload->litcal)) {
                            throw new UnprocessableContentException('The litcal array must contain at least one liturgical event');
                        }
                        $params['rawPayload'] = $payload;  // Store raw for writing to disk
                        $params['payload']    = WiderRegionData::fromObject($payload);  // DTO for property access
                        $key                  = $params['payload']->metadata->wider_region;
                    }
                    break;
                default:
                    throw new ValidationException("Invalid category: {$this->requestPathParams[0]}");
            }
            if (false === isset($key)) {
                throw new ValidationException('Invalid payload, could not extract diocese_id, nation or wider_region accordingly');
            }
            if ($method === RequestMethod::PUT) {
                $params['key'] = $key;
            } else {
                if ($params['key'] !== $key) {
                    throw new UnprocessableContentException('The key in the request path does not match the key in the payload');
                }
            }
            /** @var array{category:PathCategory,key:string,i18n?:string,locale:string,payload:DiocesanData|NationalData|WiderRegionData,rawPayload:\stdClass} $params */
        }

        if (in_array($method, [RequestMethod::GET, RequestMethod::POST], true)) {
            if (isset($this->requestPathParams[2])) {
                $params['i18n']        = $this->requestPathParams[2];
                $params['i18nRequest'] = $params['i18n'];
            }
            /** @var array{category:PathCategory,key:string,i18n:string,locale:string,payload?:DiocesanData|NationalData|WiderRegionData} $params */
        }
        $this->params = new RegionalDataParams($params);

        switch ($this->params->category) {
            case PathCategory::NATION:
                $this->checkNationalCalendarConditions($method, $this->params);
                break;
            case PathCategory::DIOCESE:
                $this->checkDiocesanCalendarConditions($method, $this->params);
                break;
            case PathCategory::WIDERREGION:
                $this->checkWiderRegionCalendarConditions($method, $this->params);
                break;
        }

        $this->validateRequestMethod($request);

        return $this->handleRequestMethod($method, $response);
    }
}
