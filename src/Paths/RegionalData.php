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
 * PHP version 8.3
 *
 * @package  LitCal
 * @author   John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license  https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @version  GIT: 3.9
 * @link     https://litcal.johnromanodorazio.com
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
        self::$Core = new Core();
        $this->params  = new RegionalDataParams();
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
                $this->getRegionalCalendar();
                break;
            case RequestMethod::PUT:
                $this->createRegionalCalendar();
                break;
            case RequestMethod::PATCH:
                $this->updateRegionalCalendar();
                break;
            case RequestMethod::DELETE:
                $this->deleteRegionalCalendar();
                break;
            default:
                self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, "The method " . $_SERVER['REQUEST_METHOD'] . " cannot be handled by this endpoint");
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
    private function getRegionalCalendar(): void
    {
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $dioceseEntry = array_values(array_filter($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                }));
                if (empty($dioceseEntry)) {
                    self::produceErrorResponse(StatusCode::NOT_FOUND, "The requested resource {$this->params->key} was not found in the index");
                }
                $calendarDataFile = strtr(JsonData::DIOCESAN_CALENDARS_FILE, [
                    '{nation}' => $dioceseEntry[0]->nation,
                    '{diocese}' => $this->params->key,
                    '{diocese_name}' => $dioceseEntry[0]->diocese
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

        if (file_exists($calendarDataFile)) {
            $CalendarData = json_decode(file_get_contents($calendarDataFile));
            if (null === $this->params->locale) {
                $this->params->locale = $CalendarData->metadata->locales[0];
            } elseif (false === in_array($this->params->locale, $CalendarData->metadata->locales)) {
                self::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Invalid value `{$this->params->locale}` for param `locale`. Valid values for current requested Wider region calendar data `{$this->params->key}` are: "
                        . implode(', ', $CalendarData->metadata->locales)
                );
            }

            switch ($this->params->category) {
                case 'DIOCESANCALENDAR':
                    $CalendarDataI18nFile = strtr(JsonData::DIOCESAN_CALENDARS_I18N_FILE, [
                        '{nation}' => $dioceseEntry[0]->nation,
                        '{diocese}' => $this->params->key,
                        '{locale}' => $this->params->locale
                    ]);
                    break;
                case 'WIDERREGIONCALENDAR':
                    $CalendarDataI18nFile = strtr(JsonData::WIDER_REGIONS_I18N_FILE, [
                        '{wider_region}' => $this->params->key,
                        '{locale}' => $this->params->locale
                    ]);
                    break;
                case 'NATIONALCALENDAR':
                    $CalendarDataI18nFile = strtr(JsonData::NATIONAL_CALENDARS_I18N_FILE, [
                        '{nation}' => $this->params->key,
                        '{locale}' => $this->params->locale
                    ]);
                    break;
            }
            if (file_exists($CalendarDataI18nFile)) {
                $localeData = json_decode(file_get_contents($CalendarDataI18nFile));
                foreach ($CalendarData->litcal as $idx => $el) {
                    if (property_exists($localeData, $CalendarData->litcal[$idx]->festivity->event_key)) {
                        $CalendarData->litcal[$idx]->festivity->name = $localeData->{$CalendarData->litcal[$idx]->festivity->event_key};
                    }
                }
            } else {
                self::produceErrorResponse(StatusCode::NOT_FOUND, "file $CalendarDataI18nFile does not exist");
            }
            self::produceResponse(json_encode($CalendarData));
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "file $calendarDataFile does not exist");
        }
    }

    /**
     * Handle PUT requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/` directory.
     *
     * If the payload is valid according to the associated schema, the response will be a JSON object
     * containing a success message.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 status code.
     */
    private function createRegionalCalendar(): void
    {
        $response = new \stdClass();
        $updateData = new \stdClass();
        switch ($this->params->category) {
            case 'DIOCESANCALENDAR':
                $nationType = gettype($this->params->payload->nation);
                $dioceseType = gettype($this->params->payload->diocese);
                if ($nationType !== 'string' || $dioceseType !== 'string') {
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Params `nation` and `key` in payload are expected to be of type string, instead `nation` was of type `{$nationType}` and `key` was of type `{$dioceseType}`");
                }
                $updateData->nation = strip_tags($this->params->payload->nation);
                $updateData->diocese = strip_tags($this->params->payload->diocese);
                if (false === $this->params->payload instanceof \stdClass) {
                    $calType = gettype($this->params->payload);
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "`caldata` param in payload expected to be serialized object, instead it was of type `{$calType}` after unserialization");
                }
                if (property_exists($this->params->payload, 'group')) {
                    $groupType = gettype($this->params->payload->group);
                    if ($groupType !== 'string') {
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Param `group` in payload is expected to be of type `string`, instead it was of type `{$groupType}`");
                    }
                    $updateData->group = strip_tags($this->params->payload->group);
                }

                // make sure we have all the necessary folders in place
                if (!file_exists(JsonData::DIOCESAN_CALENDARS_FOLDER . $this->params->payload->nation)) {
                    mkdir(JsonData::DIOCESAN_CALENDARS_FOLDER . $this->params->payload->nation, 0755, true);
                }
                if (!file_exists(JsonData::DIOCESAN_CALENDARS_FOLDER . $this->params->payload->nation . '/' . $this->params->payload->diocese)) {
                    mkdir(JsonData::DIOCESAN_CALENDARS_FOLDER . $this->params->payload->nation . '/' . $this->params->payload->diocese, 0755, true);
                }
                $diocesanCalendarI18nFolder = strtr(JsonData::DIOCESAN_CALENDARS_I18N_FOLDER, [
                    '{nation}' => $this->params->payload->nation,
                    '{diocese}' => $this->params->payload->diocese
                ]);
                if (!file_exists($diocesanCalendarI18nFolder)) {
                    mkdir($diocesanCalendarI18nFolder, 0755, true);
                }

                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::DIOCESAN);
                if ($test === true) {
                    $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    file_put_contents(
                        $updateData->path . "/{$updateData->diocese}.json",
                        $calendarData . PHP_EOL
                    );
                    $response->success = "Calendar data created or updated for Diocese \"{$updateData->diocese}\" (Nation: \"$updateData->nation\")";
                    self::produceResponse(json_encode($response));
                } else {
                    self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
                }
                break;
        }
    }

    /**
     * Handle PATCH requests to create or update a national calendar data resource.
     *
     * It is private as it is called from {@see updateRegionalCalendar}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/nations/` directory.
     *
     * If the payload is valid according to {@see LitSchema::NATIONAL}, the response will be a JSON object
     * containing a success message.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 status code.
     */
    private function handleNationalCalendarUpdate()
    {
        $response     = new \stdClass();
        $calendarFile = strtr(
            JsonData::NATIONAL_CALENDARS_FILE,
            [
                '{nation}' => $this->params->key
            ]
        );

        $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::NATIONAL);
        if ($test === true) {
            $data = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($calendarFile, $data . PHP_EOL);
            $response->success = "Calendar data created or updated for Nation \"{$this->params->key}\"";
            self::produceResponse(json_encode($response));
        } else {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
        }
    }

    /**
     * Handle PATCH requests to create or update a wider region calendar data resource.
     *
     * It is private as it is called from {@see updateRegionalCalendar}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/wider_regions/` directory.
     *
     * If the payload is valid according to {@see LitSchema::WIDERREGION}, the response will be a JSON object
     * containing a success message.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 status code.
     */
    private function handleWiderRegionCalendarUpdate()
    {
        $response = new \stdClass();
        $this->params->payload->metadata->wider_region = ucfirst(strtolower($this->params->payload->metadata->wider_region));
        $widerRegion = $this->params->payload->metadata->wider_region;
        $widerRegionPath = JsonData::WIDER_REGIONS_FOLDER . '/' . $widerRegion;
        $i18npath = strtr(JsonData::WIDER_REGIONS_I18N_FOLDER, [
            '{wider_region}' => $widerRegion
        ]);
        if (!file_exists($widerRegionPath)) {
            mkdir($widerRegionPath, 0755, true);
        }
        if (!file_exists($i18npath)) {
            mkdir($i18npath, 0755, true);
        }
        $translationJSON = new \stdClass();
        foreach ($this->params->payload->litcal as $CalEvent) {
            $translationJSON->{ $CalEvent->festivity->event_key } = '';
        }
        if (count($this->params->payload->metadata->locales) > 0) {
            foreach ($this->params->payload->metadata->locales as $iso) {
                $widerRegionI18nFile = strtr(JsonData::WIDER_REGIONS_I18N_FILE, [
                    '{wider_region}' => $widerRegion,
                    '{locale}' => $iso
                ]);
                if (!file_exists($widerRegionI18nFile)) {
                    file_put_contents(
                        $widerRegionI18nFile,
                        json_encode($translationJSON, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }
            }
        }

        $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::WIDERREGION);
        if ($test === true) {
            $data = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $widerRegionFile = strtr(
                JsonData::WIDER_REGIONS_FILE,
                [
                    '{wider_region}' => $widerRegion
                ]
            );
            file_put_contents($widerRegionFile, $data . PHP_EOL);
            $response->success = "Calendar data created or updated for Wider Region \"{$widerRegion}\"";
            self::produceResponse(json_encode($response));
        } else {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
        }
    }

    /**
     * Handle PATCH requests to create or update a diocesan calendar data resource.
     *
     * It is private as it is called from {@see updateRegionalCalendar}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/nations/` directory.
     *
     * If the payload is valid according to {@see LitSchema::DIOCESAN}, the response will be a JSON object
     * containing a success message.
     *
     * If the resource to update is not found in the diocesan calendars index or in the `jsondata/sourcedata/nations/{nation}/` directory, the response will be a JSON error response with a status code of 404 Not Found.
     * If the payload is not an object, the response will be a JSON error response with a status code of 400 Bad Request.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     * If the payload is not valid according to {@see LitSchema::DIOCESAN}, the response will be a JSON error response with a status code of 422 Unprocessable Content.
     *
     */
    private function handleDiocesanCalendarUpdate()
    {
        $dioceseEntry = array_values(array_filter($this->CalendarsMetadata->diocesan_calendars, function ($item) {
            return $item->calendar_id === $this->params->key;
        }));
        if (
            empty($dioceseEntry)
        ) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update diocesan calendar resource {$this->params->key}.");
        }
        $DiocesanCalendarFile = strtr(
            JsonData::DIOCESAN_CALENDARS_FILE,
            [
                '{nation}' => $dioceseEntry[0]->nation,
                '{diocese}' => $dioceseEntry[0]->calendar_id,
                '{diocese_name}' => $dioceseEntry[0]->diocese
            ]
        );
        if (false === file_exists($DiocesanCalendarFile)) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update diocesan calendar resource, not found in path {$dioceseEntry[0]->path}.");
        }

        if (false === is_writable($DiocesanCalendarFile)) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update diocesan calendar resource for {$this->params->key} in path {$dioceseEntry[0]->path}, check file and folder permissions.");
        }

        // Validate payload against schema
        $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::DIOCESAN);
        if (false === $test) {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
        }

        // Update diocesan calendar data
        $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (
            false === file_put_contents(
                $DiocesanCalendarFile,
                $calendarData . PHP_EOL
            )
        ) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update diocesan calendar resource {$this->params->key} in path {$dioceseEntry[0]->path}.");
        }

        $response = new \stdClass();
        $response->success = "Calendar data created or updated for Diocese \"{$this->params->key}\" (Nation: \"{$dioceseEntry[0]->nation}\")";
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PATCH requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is created or updated in the `jsondata/sourcedata/` directory.
     *
     * If the payload is valid according to the associated schema, the response will be a JSON object
     * containing a success message.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 status code.
     */
    private function updateRegionalCalendar()
    {
        switch ($this->params->category) {
            case 'NATIONALCALENDAR':
                $this->handleNationalCalendarUpdate();
                break;
            case 'WIDERREGIONCALENDAR':
                $this->handleWiderRegionCalendarUpdate();
                break;
            case 'DIOCESANCALENDAR':
                $this->handleDiocesanCalendarUpdate();
        }
    }

    /**
     * Handle DELETE requests to delete a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is deleted from the `jsondata/sourcedata/` directory.
     *
     * If the resource is successfully deleted, the response will be a JSON object
     * containing a success message.
     *
     * If the resource does not exist, a 404 error will be returned.
     */
    private function deleteRegionalCalendar()
    {
        $response = new \stdClass();
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $dioceseEntry = array_values(array_filter($this->CalendarsMetadata->diocesan_calendars, function ($el) {
                    return $el->calendar_id === $this->params->key;
                }));
                if (empty($dioceseEntry)) {
                    self::produceErrorResponse(StatusCode::NOT_FOUND, "The resource requested for deletion {$this->params->key} is not known.");
                }
                $calendarDataFile = strtr(
                    JsonData::DIOCESAN_CALENDARS_FILE,
                    [
                        '{nation}' => $dioceseEntry[0]->nation,
                        '{diocese}' => $dioceseEntry[0]->calendar_id,
                        '{diocese_name}' => $dioceseEntry[0]->diocese
                    ]
                );
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile = strtr(
                    JsonData::WIDER_REGIONS_FILE,
                    [
                        '{wider_region}' => $this->params->key
                    ]
                );
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile = strtr(
                    JsonData::NATIONAL_CALENDARS_FILE,
                    [
                        '{nation}' => $this->params->key
                    ]
                );
                break;
        }
        if (file_exists($calendarDataFile)) {
            if (false === is_writable($calendarDataFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, check file and folder permissions.");
            }
            if (false === unlink($calendarDataFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully.");
            };
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "The resource '{$this->params->key}' requested for deletion was not found on this server.");
        }
        $response->success = "Calendar data \"{$this->params->key}\" deleted successfully.";
        self::produceResponse(json_encode($response));
    }



    /**
     * Function validateDataAgainstSchema
     *
     * @param array|object $data Data to validate
     * @param string $schemaUrl  Schema to validate against
     *
     * @return boolean
     */
    private function validateDataAgainstSchema(array|object $data, string $schemaUrl): bool
    {
        $schema = Schema::import($schemaUrl);
        try {
            $schema->in($data);
            return true;
        } catch (InvalidValue | \Exception $e) {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage());
        }
    }

    /**
     * Retrieves the payload from the request body, either JSON or YAML encoded, for PUT, PATCH, and POST requests.
     *
     * If the request method is POST, it will also retrieve the locale from the payload, if present, and set it on the
     * `$data` object passed as argument.
     *
     * If the request method is PUT or PATCH, and the payload is not either JSON or YAML encoded, it will produce a
     * 400 Bad Request error.
     *
     * @param object $data the object to set the locale and payload on
     *
     * @return object the object with the locale and payload set
     */
    private static function retrievePayloadFromPostPutPatchRequest(object $data): ?object
    {
        $payload = null;
        switch (self::$Core->getRequestContentType()) {
            case RequestContentType::JSON:
                $payload = self::$Core->readJsonBody();
                break;
            case RequestContentType::YAML:
                $payload = self::$Core->readYamlBody();
                break;
            case RequestContentType::FORMDATA:
                $payload = (object)$_POST;
                break;
            default:
                if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
                    // the payload MUST be in the body of the request, either JSON encoded or YAML encoded
                    self::produceErrorResponse(
                        StatusCode::BAD_REQUEST,
                        "Expected payload in body of request, either JSON encoded or YAML encoded"
                    );
                }
        }
        if (self::$Core->getRequestMethod() === RequestMethod::POST && $payload !== null) {
            if (property_exists($payload, 'locale')) {
                $data->locale = $payload->locale;
            }
        } else {
            $data->payload = $payload;
        }
        return $data;
    }

    /**
     * Set the category, key, and locale (if applicable) based on the request path parts and method.
     *
     * @param array $requestPathParts the parts of the request path
     *
     * @return object the object with the category, key, and locale set
     */
    private static function setDataFromPath(array $requestPathParts): object
    {
        $data = new \stdClass();
        $data->category = RegionalDataParams::EXPECTED_CATEGORIES[$requestPathParts[0]];
        $data->key = $requestPathParts[1];

        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
            $data = RegionalData::retrievePayloadFromPostPutPatchRequest($data);
        } elseif (
            self::$Core->getRequestMethod() === RequestMethod::GET
            && isset($_GET['locale'])
        ) {
            $data->locale = \Locale::canonicalize($_GET['locale']);
        }
        return $data;
    }

    /**
     * Validate the request path parts for the RegionalData resource.
     *
     * Will produce a 400 error response if the request path parts are invalid.
     *
     * @param array $requestPathParts the parts of the request path
     */
    private static function validateRequestPath(array $requestPathParts): void
    {
        if (count($requestPathParts) !== 2) {
            self::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                "Expected two and exactly two path params, received " . count($requestPathParts)
            );
        }

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
     * @param array $requestPathParts the parts of the request path
     */
    private function handleRequestParams(array $requestPathParts = []): void
    {
        $data = null;

        // In the case of a PUT request, we don't expect any PATH parameters, we only retrieve the payload from the request body
        if (self::$Core->getRequestMethod() === RequestMethod::PUT) {
            switch (self::$Core->getRequestContentType()) {
                case RequestContentType::JSON:
                    $data = self::$Core->readJsonBody();
                    break;
                case RequestContentType::YAML:
                    $data = self::$Core->readYamlBody();
                    break;
                default:
                    $data = (object)$_REQUEST;
            }
            if (null === $data || !property_exists($data, 'payload')) {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "No payload received. Must receive payload in body of request, in JSON or YAML format, with properties `key` and `caldata`");
            }
            if (false === count($requestPathParts)) {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "No request path received. Must receive request path with path param `category`");
            }
            switch ($requestPathParts[0]) {
                case 'nation':
                    $data->category = 'NATIONALCALENDAR';
                    break;
                case 'diocese':
                    $data->category = 'DIOCESANCALENDAR';
                    break;
                case 'widerregion':
                    $data->category = 'WIDERREGIONCALENDAR';
                    break;
                default:
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Unexpected path param {$requestPathParts[0]}, acceptable values are: nation, diocese, widerregion");
            }
        } else {
            RegionalData::validateRequestPath($requestPathParts);
            $data = RegionalData::setDataFromPath($requestPathParts);
        }

        // In the case of a PATCH request, we expect the request body to contain the payload with calendar data,
        // either in JSON format, in YAML format, or as form data
        if (self::$Core->getRequestMethod() === RequestMethod::PATCH) {
            $bodyData = null;
            switch (self::$Core->getRequestContentType()) {
                case RequestContentType::JSON:
                    $bodyData = self::$Core->readJsonBody();
                    break;
                case RequestContentType::YAML:
                    $bodyData = self::$Core->readYamlBody();
                    break;
                default:
                    $bodyData = (object)$_REQUEST;
            }
            if (null === $bodyData) {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "No payload received. Must receive payload in body of request, in JSON or YAML format, with properties `key` and `caldata`");
            }
            $data->payload = $bodyData;
        }

        if (false === $this->params->setData($data)) {
            self::produceErrorResponse(StatusCode::BAD_REQUEST, "The params do not seem to be correct, must have params `category` and `key` with acceptable values");
        }
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
        $message = new \stdClass();
        $message->status = "ERROR";
        $statusMessage = "";
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
        $message->response = $statusCode === 404 ? "Resource not Found" : $statusMessage;
        $message->description = $description;
        $response = json_encode($message);
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
        if (in_array(self::$Core->getRequestMethod(), ['PUT','PATCH'])) {
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
        die();
    }

    /**
     * Initializes the RegionalData class.
     *
     * @param array $requestPathParts the path parameters from the request
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - If the $requestPathParts argument is not empty, it will set the request path parts
     * - It will validate the request content type
     * - It will set the request headers
     * - It will load the Diocesan Calendars index
     * - It will handle the request method
     */
    public function init(array $requestPathParts = [])
    {
        self::$Core->init();
        if (self::$Core->getRequestMethod() === RequestMethod::GET || self::$Core->getRequestMethod() === RequestMethod::OPTIONS) {
            self::$Core->validateAcceptHeader(true);
        } else {
            self::$Core->validateAcceptHeader(false);
        }
        if (self::$Core->getRequestMethod() === RequestMethod::OPTIONS) {
            return;
        }
        self::$Core->setResponseContentTypeHeader();
        $this->handleRequestParams($requestPathParts);
        $this->handleRequestMethod();
    }
}
