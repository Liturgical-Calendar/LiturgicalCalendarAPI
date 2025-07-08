<?php

namespace LiturgicalCalendar\Api\Paths;

use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Params\RegionalDataParams;

/**
 * RegionalData
 *
 */
class RegionalData
{
    private readonly ?object $CalendarsMetadata;
    private RegionalDataParams $params;
    public static Core $Core;

    /**
     * Constructor
     *
     * Initializes the RegionalData class.
     *
     * @return void
     */
    public function __construct()
    {
        self::$Core              = new Core();
        $this->params            = new RegionalDataParams();
        $this->CalendarsMetadata = json_decode(file_get_contents(API_BASE_PATH . Route::CALENDARS->value))->litcal_metadata;
    }

    /**
     * Handle the request method.
     *
     * Depending on the request method, it will call the appropriate class method to handle the request.
     *
     * @return void
     */
    private function handleRequestMethod()
    {
        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::GET:
            case RequestMethod::POST:
                if (null !== $this->params->i18nRequest) {
                    // If a simple i18n data request was made, retrieve the i18n data
                    $this->getI18nData();
                } else {
                    // Else retrieve the calendar data
                    $this->getCalendar();
                }
                break;
            case RequestMethod::PUT:
                $this->createCalendar();
                break;
            case RequestMethod::PATCH:
                $this->updateCalendar();
                break;
            case RequestMethod::DELETE:
                $this->deleteCalendar();
                break;
            default:
                self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, "The method " . $_SERVER['REQUEST_METHOD'] . " cannot be handled by this endpoint");
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
     *
     * @return void
     */
    private function getI18nData(): void
    {
        $i18nDataFile = null;
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                });
                if (null === $dioceseEntry) {
                    self::produceErrorResponse(StatusCode::NOT_FOUND, "The requested resource {$this->params->key} was not found in the index");
                }
                $i18nDataFile = strtr(JsonData::DIOCESAN_CALENDARS_I18N_FILE, [
                    '{nation}'  => $dioceseEntry->nation,
                    '{diocese}' => $this->params->key,
                    '{locale}'  => $this->params->i18nRequest
                ]);
                break;
            case "WIDERREGIONCALENDAR":
                $i18nDataFile = strtr(JsonData::WIDER_REGIONS_I18N_FILE, [
                    '{wider_region}' => $this->params->key,
                    '{locale}'       => $this->params->i18nRequest
                ]);
                break;
            case "NATIONALCALENDAR":
                $i18nDataFile = strtr(JsonData::NATIONAL_CALENDARS_I18N_FILE, [
                    '{nation}' => $this->params->key,
                    '{locale}' => $this->params->i18nRequest
                ]);
                break;
            default:
                self::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "RegionalData::getI18nData: invalid value <{$this->params->category}> for param `category`: valid values are: "
                        . implode(', ', array_values(RegionalDataParams::EXPECTED_CATEGORIES))
                        . " which are set based on the corresponding path parameters "
                        . implode(', ', array_keys(RegionalDataParams::EXPECTED_CATEGORIES))
                );
        }
        if (null !== $i18nDataFile && file_exists($i18nDataFile)) {
            self::produceResponse(file_get_contents($i18nDataFile));
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "RegionalData::getI18nData: file $i18nDataFile does not exist");
        }
    }

    /**
     * Handle GET and POST requests to retrieve a Regional Calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The `category` parameter is required and must be one of the following values:
     * - DIOCESANCALENDAR
     * - WIDERREGIONCALENDAR
     * - NATIONALCALENDAR
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
    private function getCalendar(): void
    {
        $calendarDataFile = null;
        $dioceseEntry     = null;
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                });
                if (null === $dioceseEntry) {
                    self::produceErrorResponse(StatusCode::NOT_FOUND, "The requested resource {$this->params->key} was not found in the index");
                }

                $calendarDataFile = strtr(JsonData::DIOCESAN_CALENDARS_FILE, [
                    '{nation}'       => $dioceseEntry->nation,
                    '{diocese}'      => $this->params->key,
                    '{diocese_name}' => $dioceseEntry->diocese
                ]);
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile = strtr(JsonData::WIDER_REGIONS_FILE, [
                    '{wider_region}' => $this->params->key
                ]);
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile = strtr(JsonData::NATIONAL_CALENDARS_FILE, [
                    '{nation}' => $this->params->key
                ]);
                break;
            default:
                self::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Invalid value <{$this->params->category}> for param `category`: valid values are: "
                        . implode(', ', array_values(RegionalDataParams::EXPECTED_CATEGORIES))
                );
        }

        if (null !== $calendarDataFile && file_exists($calendarDataFile)) {
            $CalendarData = json_decode(file_get_contents($calendarDataFile));

            // If a locale was not requested, use the first valid locale for the current requested calendar data
            // Else if a locale was requested, make sure it is a valid locale for the current requested calendar data
            if (null === $this->params->locale) {
                $this->params->locale = $CalendarData->metadata->locales[0];
            } elseif (false === in_array($this->params->locale, $CalendarData->metadata->locales, true)) {
                self::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Invalid value `{$this->params->locale}` for param `locale`. Valid values for current requested Wider region calendar data `{$this->params->key}` are: "
                        . implode(', ', $CalendarData->metadata->locales)
                );
            }

            // Based on the locale requested, retrieve the appropriate locale data
            switch ($this->params->category) {
                case 'DIOCESANCALENDAR':
                    $CalendarDataI18nFile = strtr(JsonData::DIOCESAN_CALENDARS_I18N_FILE, [
                        '{nation}'  => $dioceseEntry->nation,
                        '{diocese}' => $this->params->key,
                        '{locale}'  => $this->params->locale
                    ]);
                    break;
                case 'WIDERREGIONCALENDAR':
                    $CalendarDataI18nFile = strtr(JsonData::WIDER_REGIONS_I18N_FILE, [
                        '{wider_region}' => $this->params->key,
                        '{locale}'       => $this->params->locale
                    ]);
                    break;
                case 'NATIONALCALENDAR':
                    $CalendarDataI18nFile = strtr(JsonData::NATIONAL_CALENDARS_I18N_FILE, [
                        '{nation}' => $this->params->key,
                        '{locale}' => $this->params->locale
                    ]);
                    break;
                default:
                    $CalendarDataI18nFile = null;
            }
            if (null !== $CalendarDataI18nFile && file_exists($CalendarDataI18nFile)) {
                $localeData = json_decode(file_get_contents($CalendarDataI18nFile));
                foreach ($CalendarData->litcal as $idx => $el) {
                    if (property_exists($localeData, $CalendarData->litcal[$idx]->liturgical_event->event_key)) {
                        $CalendarData->litcal[$idx]->liturgical_event->name = $localeData->{$CalendarData->litcal[$idx]->liturgical_event->event_key};
                    }
                }
            } else {
                self::produceErrorResponse(StatusCode::NOT_FOUND, "RegionalData::getCalendar: file $CalendarDataI18nFile does not exist");
            }
            self::produceResponse(json_encode($CalendarData));
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "RegionalData::getCalendar: file $calendarDataFile does not exist");
        }
    }

    /**
     * Handle PUT requests to create a diocesan calendar data resource.
     *
     * This is a private method and should only be called from {@see createCalendar}.
     *
     * The diocesan calendar data resource is created in the `jsondata/sourcedata/calendars/dioceses/` directory.
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
    private function createDiocesanCalendar(): void
    {
        $response = new \stdClass();
        // Ensure we have all the necessary folders in place
        // Since we are passing `true` to the `i18n` mkdir, all missing parent folders will also be created,
        // so we don't have to worry about manually checking and creating each one individually
        $diocesanCalendarI18nFolder = strtr(JsonData::DIOCESAN_CALENDARS_I18N_FOLDER, [
            '{nation}'  => $this->params->payload->metadata->nation,
            '{diocese}' => $this->params->payload->metadata->diocese_id
        ]);
        if (!file_exists($diocesanCalendarI18nFolder)) {
            mkdir($diocesanCalendarI18nFolder, 0755, true);
        }

        foreach ($this->params->payload->i18n as $locale => $litCalEventsI18n) {
            $diocesanCalendarI18nFile = strtr(
                JsonData::DIOCESAN_CALENDARS_I18N_FILE,
                [
                    '{nation}'  => $this->params->payload->metadata->nation,
                    '{diocese}' => $this->params->payload->metadata->diocese_id,
                    '{locale}'  => $locale
                ]
            );
            if (
                false === file_put_contents(
                    $diocesanCalendarI18nFile,
                    json_encode($litCalEventsI18n, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
                )
            ) {
                self::produceErrorResponse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "RegionalData::createDiocesanCalendar: failed to write to file $diocesanCalendarI18nFile"
                );
            }
        }

        // We no longer need the i18n data, we can now remove it
        unset($this->params->payload->i18n);

        $diocesanCalendarFile = strtr(
            JsonData::DIOCESAN_CALENDARS_FILE,
            [
                '{nation}'       => $this->params->payload->metadata->nation,
                '{diocese}'      => $this->params->payload->metadata->diocese_id,
                '{diocese_name}' => $this->params->payload->metadata->diocese_name
            ]
        );

        $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (
            false === file_put_contents(
                $diocesanCalendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            self::produceErrorResponse(
                StatusCode::SERVICE_UNAVAILABLE,
                "RegionalData::createDiocesanCalendar: failed to write to file $diocesanCalendarFile"
            );
        }

        $response->success = "Calendar data created or updated for Diocese \"{$this->params->payload->metadata->diocese_name}\" (Nation: \"{$this->params->payload->metadata->nation}\")";
        $response->data    = $this->params->payload;
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PUT requests to create or update a national calendar data resource.
     *
     * This is a private method and should only be called from {@see createCalendar}.
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
    private function createNationalCalendar(): void
    {
        $response = new \stdClass();
        // Ensure we have all the necessary folders in place
        // Since we are passing `true` to the `i18n` mkdir, all missing parent folders will also be created,
        // so we don't have to worry about manually checking and creating each one individually
        $nationalCalendarI18nFolder = strtr(JsonData::NATIONAL_CALENDARS_I18N_FOLDER, [
            '{nation}' => $this->params->payload->metadata->nation
        ]);
        if (!file_exists($nationalCalendarI18nFolder)) {
            mkdir($nationalCalendarI18nFolder, 0755, true);
        }

        foreach ($this->params->payload->i18n as $locale => $litCalEventsI18n) {
            $nationalCalendarI18nFile = strtr(
                JsonData::NATIONAL_CALENDARS_I18N_FILE,
                [
                    '{nation}' => $this->params->payload->metadata->nation,
                    '{locale}' => $locale
                ]
            );
            file_put_contents($nationalCalendarI18nFile, json_encode($litCalEventsI18n, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        }

        // We no longer need the i18n data, we can now remove it
        unset($this->params->payload->i18n);

        $nationalCalendarFile = strtr(
            JsonData::NATIONAL_CALENDARS_FILE,
            [
                '{nation}' => $this->params->payload->metadata->nation
            ]
        );

        $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents(
            $nationalCalendarFile,
            $calendarData . PHP_EOL
        );
        // get the nation name in English from the two letter iso code
        $nationEnglish     = \Locale::getDisplayRegion('-' . $this->params->payload->metadata->nation, 'en');
        $response->success = "Calendar data created or updated for Nation \"{$nationEnglish}\" (\"{$this->params->payload->metadata->nation}\")";
        $response->data    = $this->params->payload;
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PUT requests to create a wider region calendar data resource.
     *
     * This is a private method and should only be called from {@see createCalendar}.
     *
     * The resource is created in the `jsondata/sourcedata/calendars/wider_regions/` directory.
     * TODO: implement
     */
    private function createWiderRegionCalendar(): void
    {
        $response = new \stdClass();
        // implementation here
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PUT requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/calendars/` directory.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 Unprocessable Content status code.
     *
     * If the payload is valid according to the associated schema,
     * the resource creation will continue according to the calendar type.
     */
    private function createCalendar(): void
    {
        if (false === $this->params->payload instanceof \stdClass) {
            $payloadType = gettype($this->params->payload);
            self::produceErrorResponse(StatusCode::BAD_REQUEST, "`payload` param expected to be serialized object, instead it was of type `{$payloadType}` after unserialization");
        }

        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::DIOCESAN);
                if (true === $test) {
                    $this->createDiocesanCalendar();
                }
                break;
            case "NATIONALCALENDAR":
                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::NATIONAL);
                if (true === $test) {
                    $this->createNationalCalendar();
                }
                break;
            case "WIDERREGIONCALENDAR":
                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::WIDERREGION);
                if (true === $test) {
                    $this->createWiderRegionCalendar();
                }
                break;
            default:
                self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "Unknown calendar category \"{$this->params->category}\"");
        }
    }

    /**
     * Handle PATCH requests to create or update a national calendar data resource.
     *
     * It is private as it is called from {@see updateCalendar}.
     *
     * The resource is updated in the `jsondata/sourcedata/calendars/nations/` directory.
     *
     * If the resource to update is not found in the national calendars index, the response will be a JSON error response with a status code of 404 Not Found.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     *
     * If the update is successful, the response will be a JSON success response with a status code of 201 Created.
     */
    private function updateNationalCalendar(): void
    {
        $nationEntry = array_find($this->CalendarsMetadata->national_calendars, function ($item) {
            return $item->calendar_id === $this->params->key;
        });

        if (null === $nationEntry) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update unknown national calendar resource {$this->params->key}.");
        }

        foreach ($this->params->payload->i18n as $locale => $i18nData) {
            $calendarI18nFile = strtr(
                JsonData::NATIONAL_CALENDARS_I18N_FILE,
                [
                    '{nation}' => $this->params->key,
                    '{locale}' => $locale
                ]
            );

            if (file_exists($calendarI18nFile) && false === is_writable($calendarI18nFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update national calendar i18n resource for {$this->params->key} at {$calendarI18nFile}, check file and folder permissions.");
            }

            // Update national calendar i18n data for locale
            $calendarI18nData = json_encode($i18nData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (
                false === file_put_contents(
                    $calendarI18nFile,
                    $calendarI18nData . PHP_EOL
                )
            ) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update national calendar i18n resource {$this->params->key} in path {$calendarI18nFile}.");
            }
        }

        // We also want to clean up any unneeded locale files, if a locale was removed
        $calendarI18nFolder = strtr(
            JsonData::NATIONAL_CALENDARS_I18N_FOLDER,
            [
                '{nation}' => $this->params->key
            ]
        );

        // Get all .json files in the folder
        $jsonFiles = glob("{$calendarI18nFolder}/*.json");
        foreach ($jsonFiles as $jsonFile) {
            $filename = pathinfo($jsonFile, PATHINFO_FILENAME);
            if (false === in_array($filename, $this->params->payload->metadata->locales)) {
                unlink($jsonFile);
            }
        }

        unset($this->params->payload->i18n);

        $calendarFile = strtr(
            JsonData::NATIONAL_CALENDARS_FILE,
            [
                '{nation}' => $this->params->key
            ]
        );

        if (false === file_exists($calendarFile)) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update unknown national calendar resource for {$this->params->key} at {$calendarFile}, file not found.");
        }

        if (false === is_writable($calendarFile)) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update national calendar resource for {$this->params->key} at {$calendarFile}, check file and folder permissions.");
        }

        $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (
            false === file_put_contents(
                $calendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update national calendar resource {$this->params->key} in path {$calendarFile}.");
        }

        $response          = new \stdClass();
        $response->success = "Calendar data created or updated for Nation \"{$this->params->key}\"";
        $response->data    = $this->params->payload;
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PATCH requests to update a wider region calendar data resource.
     *
     * It is private as it is called from {@see updateCalendar}.
     *
     * The resource is updated in the `jsondata/sourcedata/wider_regions/` directory.
     *
     * If the resource to update is not found in the wider region calendars index, the response will be a JSON error response with a status code of 404 Not Found.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     *
     * If the update is successful, the response will be a JSON success response with a status code of 201 Created.
     */
    private function updateWiderRegionCalendar(): void
    {
        $widerRegionEntry = array_find($this->CalendarsMetadata->wider_regions, function ($item) {
            return $item->name === $this->params->key;
        });

        if (null === $widerRegionEntry) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update unknown wider region calendar resource {$this->params->key}.");
        }

        foreach ($this->params->payload->i18n as $locale => $i18nData) {
            $widerRegionI18nFile = strtr(
                JsonData::WIDER_REGIONS_I18N_FILE,
                [
                    '{wider_region}' => $this->params->key,
                    '{locale}'       => $locale
                ]
            );

            if (file_exists($widerRegionI18nFile) && false === is_writable($widerRegionI18nFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update wider region calendar i18n resource for {$this->params->key} at {$widerRegionI18nFile}, check file and folder permissions.");
            }

            // Update wider region calendar i18n data for locale
            $widerRegionI18nData = json_encode($i18nData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (
                false === file_put_contents(
                    $widerRegionI18nFile,
                    $widerRegionI18nData . PHP_EOL
                )
            ) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update wider region calendar i18n resource {$this->params->key} in path {$widerRegionI18nFile}.");
            }
        }

        // We also want to clean up any unneeded locale files, if a locale has been removed
        $widerRegionI18nFolder = strtr(
            JsonData::WIDER_REGIONS_I18N_FOLDER,
            [
                '{wider_region}' => $this->params->key
            ]
        );

        // Get all .json files in the folder
        $jsonFiles = glob($widerRegionI18nFolder . '/*.json');
        foreach ($jsonFiles as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            if (false === in_array($filename, $this->params->payload->metadata->locales)) {
                unlink($file);
            }
        }

        unset($this->params->payload->i18n);

        $widerRegionFile = strtr(
            JsonData::WIDER_REGIONS_FILE,
            [
                '{wider_region}' => $this->params->key
            ]
        );

        if (false === file_exists($widerRegionFile)) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update unknown wider region calendar resource for {$this->params->key} at {$widerRegionFile}, file not found.");
        }

        if (false === is_writable($widerRegionFile)) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update wider region calendar resource for {$this->params->key} at {$widerRegionFile}, check file and folder permissions.");
        }

        $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (
            false === file_put_contents(
                $widerRegionFile,
                $calendarData . PHP_EOL
            )
        ) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update wider region calendar resource {$this->params->key} in path {$widerRegionFile}.");
        }

        $response          = new \stdClass();
        $response->success = "Calendar data created or updated for Wider Region \"{$this->params->key}\"";
        $response->data    = $this->params->payload;
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PATCH requests to update a diocesan calendar data resource.
     *
     * It is private as it is called from {@see updateCalendar}.
     *
     * The resource is updated in the `jsondata/sourcedata/calendars/dioceses/` directory.
     *
     * If the resource to update is not found in the diocesan calendars index, the response will be a JSON error response with a status code of 404 Not Found.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     *
     * If the update is successful, the response will be a JSON success response with a status code of 201 Created.
     */
    private function updateDiocesanCalendar(): void
    {
        $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($item) {
            return $item->calendar_id === $this->params->key;
        });

        if (null === $dioceseEntry) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update unknown diocesan calendar resource {$this->params->key}.");
        }

        foreach ($this->params->payload->i18n as $locale => $i18nData) {
            $DiocesanCalendarI18nFile = strtr(
                JsonData::DIOCESAN_CALENDARS_I18N_FILE,
                [
                    '{nation}'  => $dioceseEntry->nation,
                    '{diocese}' => $this->params->key,
                    '{locale}'  => $locale
                ]
            );

            if (file_exists($DiocesanCalendarI18nFile) && false === is_writable($DiocesanCalendarI18nFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update diocesan calendar i18n resource for {$this->params->key} at {$DiocesanCalendarI18nFile}, check file and folder permissions.");
            }

            // Update diocesan calendar i18n data for locale
            $calendarI18nData = json_encode($i18nData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (
                false === file_put_contents(
                    $DiocesanCalendarI18nFile,
                    $calendarI18nData . PHP_EOL
                )
            ) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update diocesan calendar i18n resource {$this->params->key} in path {$DiocesanCalendarI18nFile}.");
            }
        }

        // We also want to clean up any unneeded locale files, if a locale has been removed
        $diocesanCalendarI18nFolder = strtr(
            JsonData::DIOCESAN_CALENDARS_I18N_FOLDER,
            [
                '{nation}'  => $dioceseEntry->nation,
                '{diocese}' => $this->params->key
            ]
        );

        // Get all .json files in the folder
        $jsonFiles = glob($diocesanCalendarI18nFolder . '/*.json');
        foreach ($jsonFiles as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            if (false === in_array($filename, $this->params->payload->metadata->locales)) {
                unlink($file);
            }
        }

        unset($this->params->payload->i18n);

        $DiocesanCalendarFile = strtr(
            JsonData::DIOCESAN_CALENDARS_FILE,
            [
                '{nation}'       => $dioceseEntry->nation,
                '{diocese}'      => $this->params->key,
                '{diocese_name}' => $dioceseEntry->diocese
            ]
        );

        if (false === file_exists($DiocesanCalendarFile)) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update diocesan calendar resource at {$DiocesanCalendarFile}, file not found.");
        }

        if (false === is_writable($DiocesanCalendarFile)) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update diocesan calendar resource for {$this->params->key} at {$DiocesanCalendarFile}, check file and folder permissions.");
        }

        $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (
            false === file_put_contents(
                $DiocesanCalendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update diocesan calendar resource {$this->params->key} in path {$DiocesanCalendarFile}.");
        }

        $response          = new \stdClass();
        $response->success = "Calendar data created or updated for Diocese \"{$dioceseEntry[0]->diocese}\" (Nation: \"{$dioceseEntry[0]->nation}\")";
        $response->data    = $this->params->payload;
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PATCH requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is updated in the `jsondata/sourcedata/` directory.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 Unprocessable Content status code.
     *
     * If the payload is valid, the update process will continue according to the calendar type.
     */
    private function updateCalendar(): void
    {
        if (false === $this->params->payload instanceof \stdClass) {
            $payloadType = gettype($this->params->payload);
            self::produceErrorResponse(StatusCode::BAD_REQUEST, "`payload` param expected to be serialized object, instead it was of type `{$payloadType}` after unserialization");
        }

        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::DIOCESAN);
                if (true === $test) {
                    $this->updateDiocesanCalendar();
                }
                break;
            case "NATIONALCALENDAR":
                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::NATIONAL);
                if (true === $test) {
                    $this->updateNationalCalendar();
                }
                break;
            case "WIDERREGIONCALENDAR":
                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::WIDERREGION);
                if (true === $test) {
                    $this->updateWiderRegionCalendar();
                }
                break;
            default:
                self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "Unknown calendar category \"{$this->params->category}\"");
        }
    }

    /**
     * Get the paths for deleting a regional calendar data resource.
     *
     * The return value is an array with two elements:
     * - The first element is the path to the JSON file containing the calendar data.
     * - The second element is the path to the folder containing the i18n data for the calendar.
     *
     * This is a private method and should only be called from {@see deleteCalendar}.
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
            case "DIOCESANCALENDAR":
                $dioceseEntry = array_find($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                });
                if (null === $dioceseEntry) {
                    self::produceErrorResponse(StatusCode::NOT_FOUND, "The resource requested for deletion {$this->params->key} is not known.");
                }
                $calendarDataFile   = strtr(
                    JsonData::DIOCESAN_CALENDARS_FILE,
                    [
                        '{nation}'       => $dioceseEntry->nation,
                        '{diocese}'      => $dioceseEntry->calendar_id,
                        '{diocese_name}' => $dioceseEntry->diocese
                    ]
                );
                $calendarI18nFolder = strtr(
                    JsonData::DIOCESAN_CALENDARS_I18N_FOLDER,
                    [
                        '{nation}'  => $dioceseEntry->nation,
                        '{diocese}' => $dioceseEntry->calendar_id
                    ]
                );
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile   = strtr(
                    JsonData::WIDER_REGIONS_FILE,
                    [
                        '{wider_region}' => $this->params->key
                    ]
                );
                $calendarI18nFolder = strtr(
                    JsonData::WIDER_REGIONS_I18N_FOLDER,
                    [
                        '{wider_region}' => $this->params->key
                    ]
                );
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile   = strtr(
                    JsonData::NATIONAL_CALENDARS_FILE,
                    [
                        '{nation}' => $this->params->key
                    ]
                );
                $calendarI18nFolder = strtr(
                    JsonData::NATIONAL_CALENDARS_I18N_FOLDER,
                    [
                        '{nation}' => $this->params->key
                    ]
                );
                break;
            default:
                $calendarDataFile   = null;
                $calendarI18nFolder = null;
        }

        return [$calendarDataFile, $calendarI18nFolder];
    }

    /**
     * Handle DELETE requests to delete a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is deleted from the `jsondata/sourcedata/calendars/` directory.
     *
     * If the resource is successfully deleted, the response will be a JSON object
     * containing a success message.
     *
     * If the resource does not exist, a 404 error will be returned.
     */
    private function deleteCalendar(): void
    {
        $response            = new \stdClass();
        $dioceseNationFolder = null;

        [$calendarDataFile, $calendarI18nFolder] = $this->getPathsForCalendarDelete();

        if (file_exists($calendarDataFile) && file_exists($calendarI18nFolder)) {
            if (false === is_writable($calendarDataFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, check file and folder permissions.");
            }

            // We want to make sure to also remove the containing folder, let's get the parent folder for later removal
            $calendarDataFolder = dirname($calendarDataFile);

            // And in the case of a diocesan calendar, if the parent `nation_id` folder is empty, remove it as well
            // so let's get a reference to the parent folder to check later
            if ($this->params->category === "DIOCESANCALENDAR") {
                $dioceseNationFolder = dirname($calendarDataFolder);
            }

            if (false === unlink($calendarDataFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully.");
            };
            foreach (glob($calendarI18nFolder . "/*.json") as $file) {
                if (false === is_writable($file)) {
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, check i18n file and folder permissions.");
                }
                if (false === unlink($file)) {
                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, i18n file could not be removed.");
                };
            }
            if (false === rmdir($calendarI18nFolder)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, i18n folder could not be removed.");
            }
            if (false === rmdir($calendarDataFolder)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, data folder could not be removed.");
            }
            if ($this->params->category === "DIOCESANCALENDAR" && $dioceseNationFolder !== null) {
                // Check if the parent `nation_id` folder is empty, if it is, remove it too
                if (count(scandir($dioceseNationFolder)) === 2) { // only . and ..
                    if (false === rmdir($dioceseNationFolder)) {
                        self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, diocese nation folder could not be removed.");
                    }
                }
            }
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "The resource '{$this->params->key}' requested for deletion (or the relative i18n folder) was not found on this server.");
        }
        $response->success = "Calendar data \"{$this->params->category}/{$this->params->key}\" deletion successful.";
        self::produceResponse(json_encode($response));
    }



    /**
     * Validate payload data against a schema
     *
     * @param object $data Data to validate
     * @param string $schemaUrl  Schema to validate against
     *
     * @return boolean
     */
    private function validateDataAgainstSchema(object $data, string $schemaUrl): bool
    {
        $schema = Schema::import($schemaUrl);
        try {
            $schema->in($data);
            return true;
        } catch (InvalidValue | \Exception $e) {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage());
            // the return here is superfluous, because produceErrorResponse terminates the script
            // it's only here to make intelephense happy
            return false;
        }
    }

    /**
     * Retrieves the payload from the request body, either JSON or YAML encoded, for PUT, PATCH, and POST requests.
     *
     * If the request method is POST, it will also retrieve the locale from the payload, if present, and set it on the
     * `$data` object passed as argument.
     *
     * If the request method is PUT or PATCH, and the payload is not either JSON or YAML encoded,
     * it will produce a 400 Bad Request error.
     *
     * @param array{category: string, key?: string, locale?: string, i18n?: string, payload?: object} $params the object on which to set the locale and payload
     *
     * @return array{locale: string, payload: object} the associative array with the locale and payload set
     */
    private static function retrievePayloadFromPostPutPatchRequest(array $params): array
    {
        $payload  = null;
        $required = self::$Core->getRequestMethod() !== RequestMethod::POST;
        switch (self::$Core->getRequestContentType()) {
            case RequestContentType::JSON:
                $payload = self::$Core->readJsonBody($required);
                break;
            case RequestContentType::YAML:
                $payload = self::$Core->readYamlBody($required);
                break;
            case RequestContentType::FORMDATA:
                if ($required && count($_REQUEST) === 0) {
                    self::produceErrorResponse(
                        StatusCode::BAD_REQUEST,
                        "Expected non empty payload in body of request, either JSON encoded or YAML encoded or form encoded."
                    );
                }
                $payload = (object)$_REQUEST;
                break;
            default:
                if ($required) {
                    // the payload MUST be in the body of the request, either JSON encoded or YAML encoded or form encoded
                    self::produceErrorResponse(
                        StatusCode::BAD_REQUEST,
                        "Expected non empty payload in body of request, either JSON encoded or YAML encoded or form encoded."
                    );
                }
        }

        if (self::$Core->getRequestMethod() === RequestMethod::POST && is_object($payload)) {
            if (property_exists($payload, 'locale')) {
                $params['locale'] = $payload->locale;
            }
        }

        if ($payload !== null) {
            $params['payload'] = $payload;
        }

        return $params;
    }

    /**
     * Set the category, key (if applicable), and locale (if applicable) based on the request path parts and method.
     *
     * @param string[] $requestPathParts the parts of the request path
     *
     * @return array{category: string, key?: string, locale?: string, i18n?: string, payload?: object} an associative array with the category, key, and locale set
     */
    private static function setParamsFromPath(array $requestPathParts): array
    {
        $params = [
            'category' => RegionalDataParams::EXPECTED_CATEGORIES[$requestPathParts[0]]
        ];

        if (self::$Core->getRequestMethod() !== RequestMethod::PUT) {
            $params['key'] = $requestPathParts[1];
        }

        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::GET, RequestMethod::POST], true)) {
            // For GET and POST request, there may be an optional third path parameter (= locale),
            // which will determine whether we are requesting calendar data or i18n data
            if (isset($requestPathParts[2])) {
                $params['i18n'] = $requestPathParts[2];
            }
            // For GET requests, we attempt to retrieve the locale from the query string if present
            if (self::$Core->getRequestMethod() === RequestMethod::GET && isset($_GET['locale'])) {
                $params['locale'] = \Locale::canonicalize($_GET['locale']);
            }
        }

        // If the request method is PATCH, or PUT, we expect a payload
        // For POST requests, there might be a payload
        // So in all these cases, we attempt to retrieve the payload from the request body if present
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH], true)) {
            $params = RegionalData::retrievePayloadFromPostPutPatchRequest($params);
        }
        return $params;
    }

    /**
     * Validate the request path parts for the RegionalData resource.
     *
     * Will produce a 400 Bad Request error response if the request path parts are invalid.
     *
     * @param string[] $requestPathParts the parts of the request path
     */
    private static function validateRequestPath(array $requestPathParts): void
    {
        // For GET and POST requests, we expect at least two path params (= category and key) for Calendar Data requests,
        // and at most three path params (= category, key, and locale) for I18n Data requests
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::GET, RequestMethod::POST], true)) {
            if (count($requestPathParts) < 2 || count($requestPathParts) > 3) {
                self::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Expected at least two and at most three path params for GET and POST requests, received " . count($requestPathParts)
                );
            }
        }
        // For PUT requests, we expect exactly one path param (= category)
        elseif (self::$Core->getRequestMethod() === RequestMethod::PUT) {
            if (count($requestPathParts) !== 1) {
                self::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Expected one path param for PUT requests, received " . count($requestPathParts)
                );
            }
        }
        // For PATCH and DELETE requests, we expect exactly two path params
        elseif (count($requestPathParts) !== 2) {
            self::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                "Expected two and exactly two path params for PATCH and DELETE requests, received " . count($requestPathParts)
            );
        }

        // In all cases, we check if the category param is valid
        if (false === array_key_exists($requestPathParts[0], RegionalDataParams::EXPECTED_CATEGORIES)) {
            self::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                "Unexpected path param {$requestPathParts[0]}, acceptable values are: "
                    . implode(', ', array_keys(RegionalDataParams::EXPECTED_CATEGORIES))
            );
        }
    }

    /**
     * Handle the request parameters for the RegionalData resource.
     *
     * The request parameters are expected to be in the request path (or possibly in the request body when the request content type is JSON or YAML).
     * The request body is expected to be a JSON or YAML encoded object, with the following properties:
     * - category: a string indicating the category of the Regional Calendar data, one of the values in RegionalDataParams::EXPECTED_CATEGORIES
     * - key: a string indicating the key of the Regional Calendar data
     * - locale: a string indicating the locale of the Regional Calendar data, only applicable for the WIDERREGIONCALENDAR category
     *
     * If the request parameters are invalid, it will produce an error response with a status code of 400.
     *
     * @param string[] $requestPathParts the parts of the request path
     */
    private function handleRequestParams(array $requestPathParts = []): void
    {
        RegionalData::validateRequestPath($requestPathParts);
        $params = RegionalData::setParamsFromPath($requestPathParts);

        // Validate the payload for PUT and PATCH requests, based on category.
        // For PUT requests, the key is retrieved from the payload rather than from the path,
        // whereas for PATCH requests, the key should already have been set from the path.
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH], true)) {
            switch ($params['category']) {
                case 'DIOCESANCALENDAR':
                    if (
                        false === array_key_exists('payload', $params)
                        || false === $params['payload'] instanceof \stdClass
                        || false === property_exists($params['payload'], 'litcal')
                        || false === property_exists($params['payload'], 'i18n')
                        || false === property_exists($params['payload'], 'metadata')
                        || false === property_exists($params['payload']->metadata, 'diocese_id')
                    ) {
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid payload in request. Must receive non empty payload in body of request, in JSON or YAML or form encoded format, with properties `payload`, `payload.litcal`, `payload.i18n`, `payload.metadata`, and `payload.metadata.diocese_id`, instead payload was: " . json_encode($params['payload']));
                    }
                    if (RequestMethod::PUT === self::$Core->getRequestMethod()) {
                        $params['key'] = $params['payload']->metadata->diocese_id;
                    }
                    break;
                case 'NATIONALCALENDAR':
                    if (
                        false === array_key_exists('payload', $params)
                        || false === $params['payload'] instanceof \stdClass
                        || false === property_exists($params['payload'], 'litcal')
                        || false === property_exists($params['payload'], 'i18n')
                        || false === property_exists($params['payload'], 'settings')
                        || false === property_exists($params['payload'], 'metadata')
                        || false === property_exists($params['payload']->metadata, 'nation')
                    ) {
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid payload in request. Must receive non empty payload in body of request, in JSON or YAML or form encoded format, with properties `payload`, `payload.litcal`, `payload.i18n`, `payload.settings`, `payload.metadata`, and `payload.metadata.nation`, instead payload was: " . json_encode($params['payload']));
                    }
                    if (RequestMethod::PUT === self::$Core->getRequestMethod()) {
                        $params['key'] = $params['payload']->metadata->nation;
                    }
                    break;
                case 'WIDERREGIONCALENDAR':
                    if (
                        false === array_key_exists('payload', $params)
                        || false === $params['payload'] instanceof \stdClass
                        || false === property_exists($params['payload'], 'litcal')
                        || false === property_exists($params['payload'], 'i18n')
                        || false === property_exists($params['payload'], 'national_calendars')
                        || false === property_exists($params['payload'], 'metadata')
                        || false === property_exists($params['payload']->metadata, 'wider_region')
                        || false === property_exists($params['payload']->metadata, 'locales')
                    ) {
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid payload in request. Must receive non empty payload in body of request, in JSON or YAML or form encoded format, with properties `payload`, `payload.litcal`, `payload.i18n`, `payload.national_calendars`, `payload.metadata`, `payload.metadata.wider_region`, and `payload.metadata.locales`");
                    }
                    if (RequestMethod::PUT === self::$Core->getRequestMethod()) {
                        $params['key'] = $params['payload']->metadata->wider_region;
                    }
                    break;
            }
        }

        $this->params->setParams($params);
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
     */
    public static function produceErrorResponse(int $statusCode, string $description): void
    {
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message         = new \stdClass();
        $message->status = "ERROR";
        $statusMessage   = "";
        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::PUT:
                $statusMessage = "Resource not Created";
                break;
            case RequestMethod::PATCH:
                $statusMessage = "Resource not Updated";
                break;
            case RequestMethod::DELETE:
                $statusMessage = "Resource not Deleted";
                break;
            default:
                $statusMessage = "Sorry what was it you wanted to do with this resource?";
        }
        $message->response    = $statusCode === 404 ? "Resource not Found" : $statusMessage;
        $message->description = $description;
        $response             = json_encode($message);
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
     * Outputs the response for the /data endpoint.
     *
     * Outputs the response as either JSON or YAML, depending on the value of
     * self::$Core->getResponseContentType(). If the request method was PUT or
     * PATCH, it also sets a 201 Created status code.
     *
     * @param string $jsonEncodedResponse the response as a JSON encoded string
     */
    private static function produceResponse(string $jsonEncodedResponse): void
    {
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH], true)) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($jsonEncodedResponse, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $jsonEncodedResponse;
        }
        //die();
    }

    /**
     * Initializes the RegionalData class.
     *
     * @param string[] $requestPathParts the path parameters from the request
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - If the $requestPathParts argument is not empty, it will set the request path parts
     * - It will validate the request content type
     * - It will set the request headers
     * - It will load the Diocesan Calendars index
     * - It will handle the request method
     */
    public function init(array $requestPathParts = []): void
    {
        self::$Core->init();
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::GET, RequestMethod::OPTIONS], true)) {
            self::$Core->validateAcceptHeader(true);
        } else {
            self::$Core->validateAcceptHeader(false);
        }
        if (self::$Core->getRequestMethod() === RequestMethod::OPTIONS) {
            die();
        }
        self::$Core->setResponseContentTypeHeader();
        $this->handleRequestParams($requestPathParts);
        $this->handleRequestMethod();
    }
}
