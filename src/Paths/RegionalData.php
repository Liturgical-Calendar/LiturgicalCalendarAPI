<?php

namespace LiturgicalCalendar\Api\Paths;

use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\DioceseIndexAction;
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
    private ?object $diocesanCalendarsIndex = null;
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
     * The `locale` parameter is optional and only applies to WIDERREGIONCALENDAR category requests.
     * If present, it must be a valid locale listed in the metadata of the requested Wider region calendar data.
     *
     * If the requested resource exists, it will be returned as JSON.
     * If the resource does not exist, a 404 error will be returned.
     * If the `category` or `locale` parameters are invalid, a 400 error will be returned.
     */
    private function getRegionalCalendar(): void
    {
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $calendarDataFile = $this->diocesanCalendarsIndex->{$this->params->key}->path;
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile = "data/wider_regions/{$this->params->key}.json";
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile = "data/nations/{$this->params->key}/{$this->params->key}.json";
                break;
            default:
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value <{$this->params->category}> for param `category`: valid values are: " . implode(', ', array_values(RegionalDataParams::EXPECTED_CATEGORIES)));
        }

        if (file_exists($calendarDataFile)) {
            if ($this->params->category === "DIOCESANCALENDAR") {
                self::produceResponse(file_get_contents($calendarDataFile));
            } else {
                $response = json_decode(file_get_contents($calendarDataFile));
                $uKey = strtoupper($this->params->key);
                if ($this->params->category === "WIDERREGIONCALENDAR") {
                    $isMultilingual = is_dir("data/wider_regions/{$uKey}");
                    if ($isMultilingual) {
                        if (false === in_array($this->params->locale, $response->metadata->languages)) {
                            self::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value `{$this->params->locale}` for param `locale`. Valid values for current requested Wider region calendar data `{$this->params->key}` are: " . implode(', ', $response->metadata->languages));
                        }
                        if (file_exists("data/wider_regions/{$uKey}/{$this->params->locale}.json")) {
                            $localeData = json_decode(file_get_contents("data/wider_regions/{$uKey}/{$this->params->locale}.json"));
                            foreach ($response->litcal as $idx => $el) {
                                $response->litcal[$idx]->festivity->name = $localeData->{$response->litcal[$idx]->festivity->event_key};
                            }
                        }
                    }
                }
                self::produceResponse(json_encode($response));
            }
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "file $calendarDataFile does not exist");
        }
    }

    /**
     * Handle PUT requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is created or updated in the `data/` directory.
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
                $updateData->path = "data/nations/{$updateData->nation}";
                if (!file_exists($updateData->path)) {
                    mkdir($updateData->path, 0755, true);
                }

                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::DIOCESAN);
                if ($test === true) {
                    $calendarData = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    file_put_contents(
                        $updateData->path . "/{$updateData->diocese}.json",
                        $calendarData . PHP_EOL
                    );
                    $this->createOrUpdateIndex(DioceseIndexAction::CREATE, $updateData);
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
     * The resource is created or updated in the `data/nations/` directory.
     *
     * If the payload is valid according to {@see LitSchema::NATIONAL}, the response will be a JSON object
     * containing a success message.
     *
     * If the payload is invalid, the response will be a JSON error response with a 422 status code.
     */
    private function handleNationalCalendarUpdate()
    {
        $response = new \stdClass();
        $region = $this->params->payload->metadata->region;
        $path = "data/nations/{$region}";
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::NATIONAL);
        if ($test === true) {
            $data = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($path . "/{$region}.json", $data . PHP_EOL);
            $response->success = "Calendar data created or updated for Nation \"{$this->params->payload->metadata->region}\"";
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
     * The resource is created or updated in the `data/wider_regions/` directory.
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
        $widerRegion = strtoupper($this->params->payload->metadata->wider_region);
        if ($this->params->payload->metadata->multilingual === true) {
            $path = "data/wider_regions/{$widerRegion}";
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
            $translationJSON = new \stdClass();
            foreach ($this->params->payload->litcal as $CalEvent) {
                $translationJSON->{ $CalEvent->festivity->event_key } = '';
            }
            if (count($this->params->payload->netadata->languages) > 0) {
                foreach ($this->params->payload->metadata->languages as $iso) {
                    if (!file_exists("data/wider_regions/{$widerRegion}/{$iso}.json")) {
                        file_put_contents(
                            "data/wider_regions/{$widerRegion}/{$iso}.json",
                            json_encode($translationJSON, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        );
                    }
                }
            }
        }

        $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::WIDERREGION);
        if ($test === true) {
            $data = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents("data/wider_regions/{$this->params->payload->metadata->wider_region}.json", $data . PHP_EOL);
            $response->success = "Calendar data created or updated for Wider Region \"{$this->params->payload->metadata->wider_region}\"";
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
     * The resource is created or updated in the `data/nations/` directory.
     *
     * If the payload is valid according to {@see LitSchema::DIOCESAN}, the response will be a JSON object
     * containing a success message.
     *
     * If the resource to update is not found in the diocesan calendars index or in the `data/nations/{nation}/` directory, the response will be a JSON error response with a status code of 404 Not Found.
     * If the payload is not an object, the response will be a JSON error response with a status code of 400 Bad Request.
     * If the resource to update is not writable or the write was not successful, the response will be a JSON error response with a status code of 503 Service Unavailable.
     * If the payload is not valid according to {@see LitSchema::DIOCESAN}, the response will be a JSON error response with a status code of 422 Unprocessable Content.
     *
     */
    private function handleDiocesanCalendarUpdate()
    {
        if (
            false === property_exists($this->diocesanCalendarsIndex, $this->params->key)
            ||
            false === property_exists($this->diocesanCalendarsIndex->{$this->params->key}, 'path')
        ) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update diocesan calendar resource {$this->params->key}, not found in diocesan calendars index `data/nations/index.json`.");
        }

        if (false === file_exists($this->diocesanCalendarsIndex->{$this->params->key}->path)) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update diocesan calendar resource, not found in path {$this->diocesanCalendarsIndex->{$this->params->key}->path}.");
        }

        if (false === is_writable($this->diocesanCalendarsIndex->{$this->params->key}->path)) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update diocesan calendar resource for {$this->params->key} in path {$this->diocesanCalendarsIndex->{$this->params->key}->path}, check file and folder permissions.");
        }

        // Check whether the "group" property in the Diocese index is getting updated too
        $diocesanGroup = null;
        if (property_exists($this->params->payload, 'group')) {
            $groupType = gettype($this->params->payload->group);
            if ($groupType !== 'string') {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "Param `group` in payload is expected to be of type `string`, instead it was of type `{$groupType}`");
            }
            $diocesanGroup = strip_tags($this->params->payload->group);
            unset($this->params->payload->group);
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
                $this->diocesanCalendarsIndex->{$this->params->key}->path,
                $calendarData . PHP_EOL
            )
        ) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update diocesan calendar resource {$this->params->key} in path {$this->diocesanCalendarsIndex->{$this->params->key}->path}.");
        }

        // In case the "group" property is getting updated, update the diocesan calendar index
        if (
            false === property_exists($this->diocesanCalendarsIndex->{$this->params->key}, 'group')
            && $diocesanGroup !== null
            && $diocesanGroup !== ''
        ) {
            $this->diocesanCalendarsIndex->{$this->params->key}->group = $diocesanGroup;
            $this->createOrUpdateIndex(DioceseIndexAction::UPDATE, $this->diocesanCalendarsIndex->{$this->params->key});
        } elseif ($this->diocesanCalendarsIndex->{$this->params->key}->group !== $diocesanGroup) {
            if ($diocesanGroup === null || $diocesanGroup === '') {
                unset($this->diocesanCalendarsIndex->{$this->params->key}->group);
            } else {
                $this->diocesanCalendarsIndex->{$this->params->key}->group = $diocesanGroup;
            }
            $this->createOrUpdateIndex(DioceseIndexAction::UPDATE, $this->diocesanCalendarsIndex->{$this->params->key});
        }

        $response = new \stdClass();
        $response->success = "Calendar data created or updated for Diocese \"{$this->params->key}\" (Nation: \"{$this->diocesanCalendarsIndex->{$this->params->key}->nation}\")";
        self::produceResponse(json_encode($response));
    }

    /**
     * Handle PATCH requests to create or update a regional calendar data resource.
     *
     * This is a private method and should only be called from {@see handleRequestMethod}.
     *
     * The resource is created or updated in the `data/` directory.
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
     * The resource is deleted from the `data/` directory.
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
                $calendarDataFile = $this->diocesanCalendarsIndex->{$this->params->key}->path;
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile = "data/wider_regions/{$this->params->key}.json";
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile = "data/nations/{$this->params->key}/{$this->params->key}.json";
                break;
        }
        if (file_exists($calendarDataFile)) {
            if (false === is_writable($calendarDataFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully, check file and folder permissions.");
            }
            if (false === unlink($calendarDataFile)) {
                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The resource '{$this->params->key}' requested for deletion was not removed successfully.");
            };
            if ($this->params->category === 'DIOCESANCALENDAR') {
                $this->createOrUpdateIndex(DioceseIndexAction::DELETE);
            }
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "The resource '{$this->params->key}' requested for deletion was not found on this server.");
        }
        $response->success = "Calendar data \"{$this->params->key}\" deleted successfully.";
        self::produceResponse(json_encode($response));
    }

    /**
     * Loads the JSON data for the diocesan calendars index.
     *
     * This file is used to keep track of which diocesan calendars are available
     * and their respective paths.
     */
    private function loadDiocesanCalendarsIndex()
    {
        $diocesanIndexPath = "data/nations/index.json";
        if (file_exists($diocesanIndexPath)) {
            $this->diocesanCalendarsIndex = json_decode(file_get_contents($diocesanIndexPath));
        }
    }

    /**
     * Function createOrUpdateIndex
     *  only needed when a diocesan calendar is created, updated or deleted
     * @param ?object $data   Data to add to index.json: path of the diocesan calendar data, nation, diocese name, and optionally group; null when deleting
     * @param boolean $delete Delete from the index rather than create/update the index
     *
     * @return void
     */
    private function createOrUpdateIndex(DioceseIndexAction $action, ?object $data = null)
    {
        switch ($action) {
            case DioceseIndexAction::DELETE:
                $key = $this->params->key;
                if (property_exists($this->diocesanCalendarsIndex, $key)) {
                    unset($this->diocesanCalendarsIndex->$key);
                }
                break;
            case DioceseIndexAction::CREATE:
                $key = mb_strtoupper(preg_replace("/[^\p{L}]/u", "", $data->diocese));
                if (!property_exists($this->diocesanCalendarsIndex, $key)) {
                    $this->diocesanCalendarsIndex->$key = new \stdClass();
                }
                $this->diocesanCalendarsIndex->$key->path    = $data->path . "/{$data->diocese}.json";
                $this->diocesanCalendarsIndex->$key->nation  = $data->nation;
                $this->diocesanCalendarsIndex->$key->diocese = $data->diocese;
                if (property_exists($data, 'group')) {
                    $this->diocesanCalendarsIndex->$key->group = $data->group;
                }
                break;
            case DioceseIndexAction::UPDATE:
                $key = $this->params->key;
                $this->diocesanCalendarsIndex->$key = $data;
                break;
        }

        $test = $this->validateDataAgainstSchema($this->diocesanCalendarsIndex, LitSchema::INDEX);
        if (false === $test) {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, json_encode($test));
        }

        if (false === file_exists("data/nations/index.json")) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "Cannot update diocesan calendars index, not found in path data/nations/index.json.");
        }

        if (false === is_writable("data/nations/index.json")) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Cannot update diocesan calendars index in path data/nations/index.json, check file and folder permissions.");
        }

        $jsonEncodedContents = json_encode($this->diocesanCalendarsIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (false === file_put_contents("data/nations/index.json", $jsonEncodedContents . PHP_EOL)) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Could not update diocesan calendars index in path data/nations/index.json.");
        }
    }

    /**
     * Function validateDataAgainstSchema
     *
     * @param object $data      Data to validate
     * @param string $schemaUrl Schema to validate against
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
                $payload = self::$Core->retrieveRequestParamsFromJsonBody();
                break;
            case RequestContentType::YAML:
                $payload = self::$Core->retrieveRequestParamsFromYamlBody();
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
            $data->locale = $_GET['locale'];
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
        if (self::$Core->getRequestMethod() === RequestMethod::PUT) {
            switch (self::$Core->getRequestContentType()) {
                case RequestContentType::JSON:
                    $data = self::$Core->retrieveRequestParamsFromJsonBody();
                    break;
                case RequestContentType::YAML:
                    $data = self::$Core->retrieveRequestParamsFromYamlBody();
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
        } elseif (count($requestPathParts)) {
            RegionalData::validateRequestPath($requestPathParts);
            $data = RegionalData::setDataFromPath($requestPathParts);
        }

        if (self::$Core->getRequestMethod() === RequestMethod::PATCH) {
            $bodyData = null;
            switch (self::$Core->getRequestContentType()) {
                case RequestContentType::JSON:
                    $bodyData = self::$Core->retrieveRequestParamsFromJsonBody();
                    break;
                case RequestContentType::YAML:
                    $bodyData = self::$Core->retrieveRequestParamsFromYamlBody();
                    break;
                default:
                    $bodyData = (object)$_REQUEST;
            }
            if (null === $bodyData || !property_exists($bodyData, 'payload')) {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "No payload received. Must receive payload in body of request, in JSON or YAML format, with properties `key` and `caldata`");
            }
            $data->payload = $bodyData->payload;
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
        $this->loadDiocesanCalendarsIndex();
        $this->handleRequestParams($requestPathParts);
        $this->handleRequestMethod();
    }
}
