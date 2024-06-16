<?php

namespace Johnrdorazio\LitCal\Paths;

use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use Johnrdorazio\LitCal\APICore;
use Johnrdorazio\LitCal\Enum\RequestMethod;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Enum\LitSchema;
use Johnrdorazio\LitCal\Enum\RequestContentType;
use Johnrdorazio\LitCal\Params\RegionalDataParams;

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
    private ?object $generalIndex = null;
    private RegionalDataParams $params;
    public static APICore $APICore;

    /**
     * LitCalRegionalData Constructor
     */
    public function __construct()
    {
        self::$APICore                              = new APICore();
        $this->params                               = new RegionalDataParams();
    }

    /**
     * Function handleRequestMethod
     *
     * @return void
     */
    private function handleRequestMethod()
    {
        switch (self::$APICore->getRequestMethod()) {
            case RequestMethod::GET:
            case RequestMethod::POST:
                $this->handleGetPostRequests();
                break;
            case RequestMethod::PUT:
            case RequestMethod::PATCH:
                $this->handlePutPatchDeleteRequests(RequestMethod::PUT);
                break;
            case RequestMethod::DELETE:
                $this->handlePutPatchDeleteRequests(RequestMethod::DELETE);
                break;
            case RequestMethod::OPTIONS:
                // nothing to do here, should be handled by APICore
                break;
            default:
                self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, "The method " . $_SERVER['REQUEST_METHOD'] . " cannot be handled by this endpoint");
        }
    }

    /**
     * Function handleGetPostRequests
     *
     * @return void
     */
    private function handleGetPostRequests(): void
    {
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $calendarDataFile = $this->generalIndex->{$this->params->key}->path;
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile = "nations/{$this->params->key}.json";
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile = "nations/{$this->params->key}/{$this->params->key}.json";
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
                    $isMultilingual = is_dir("nations/{$uKey}");
                    if ($isMultilingual) {
                        if (false === in_array($this->params->locale, $response->Metadata->Languages)) {
                            self::produceErrorResponse(StatusCode::BAD_REQUEST, "Invalid value `{$this->params->locale}` for param `locale`. Valid values for current requested Wider region calendar data `{$this->params->key}` are: " . implode(', ', $response->Metadata->Languages));
                        }
                        if (file_exists("nations/{$uKey}/{$this->params->locale}.json")) {
                            $localeData = json_decode(file_get_contents("nations/{$uKey}/{$this->params->locale}.json"));
                            foreach ($response->LitCal as $idx => $el) {
                                $response->LitCal[$idx]->Festivity->name = $localeData->{$response->LitCal[$idx]->Festivity->tag};
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
     * Function handlePutPatchDeleteRequests
     *
     * @param string $requestMethod Method of the current request
     *
     * @return void
     */
    private function handlePutPatchDeleteRequests(string $requestMethod)
    {
        self::$APICore->enforceAjaxRequest();
        self::$APICore->enforceReferer();
        if (RequestMethod::PUT === $requestMethod) {
            $this->writeRegionalCalendar();
        } elseif (RequestMethod::DELETE === $requestMethod) {
            $this->deleteRegionalCalendar();
        }
    }


    /**
     * Function writeRegionalCalendar
     *
     * @return void
     */
    private function writeRegionalCalendar()
    {
        $response = new \stdClass();
        switch ($this->params->category) {
            case 'NATIONALCALENDAR':
                $region = $this->params->payload->Metadata->Region;
                if ($region === 'UNITED STATES') {
                    $region = 'USA';
                }
                $path = "nations/{$region}";
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }

                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::NATIONAL);
                if ($test === true) {
                    $data = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    file_put_contents($path . "/{$region}.json", $data . PHP_EOL);
                    $response->success = "Calendar data created or updated for Nation \"{$this->params->payload->Metadata->Region}\"";
                    self::produceResponse(json_encode($response));
                } else {
                    self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
                }
                break;
            case 'WIDERREGIONCALENDAR':
                $this->params->payload->Metadata->WiderRegion = ucfirst(strtolower($this->params->payload->Metadata->WiderRegion));
                $widerRegion = strtoupper($this->params->payload->Metadata->WiderRegion);
                if ($this->params->payload->Metadata->IsMultilingual === true) {
                    $path = "nations/{$widerRegion}";
                    if (!file_exists($path)) {
                        mkdir($path, 0755, true);
                    }
                    $translationJSON = new \stdClass();
                    foreach ($this->params->payload->LitCal as $CalEvent) {
                        $translationJSON->{ $CalEvent->Festivity->tag } = '';
                    }
                    if (count($this->params->payload->Metadata->Languages) > 0) {
                        foreach ($this->params->payload->Metadata->Languages as $iso) {
                            if (!file_exists("nations/{$widerRegion}/{$iso}.json")) {
                                file_put_contents(
                                    "nations/{$widerRegion}/{$iso}.json",
                                    json_encode($translationJSON, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                );
                            }
                        }
                    }
                }

                $test = $this->validateDataAgainstSchema($this->params->payload, LitSchema::WIDERREGION);
                if ($test === true) {
                    $data = json_encode($this->params->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    file_put_contents("nations/{$this->params->payload->Metadata->WiderRegion}.json", $data . PHP_EOL);
                    $response->success = "Calendar data created or updated for Wider Region \"{$this->params->payload->Metadata->WiderRegion}\"";
                    self::produceResponse(json_encode($response));
                } else {
                    self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
                }
                break;
            case 'DIOCESANCALENDAR':
                $updateData = new \stdClass();
                if (
                    gettype($this->params->payload->Nation) !== 'string'
                    || gettype($this->params->payload->Diocese !== 'string')
                ) {
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Params `Nation` and `Diocese` in payload are expected to be of type string");
                }
                $updateData->Nation = strip_tags($this->params->payload->Nation);
                $updateData->Diocese = strip_tags($this->params->payload->Diocese);
                $CalData = json_decode($this->params->payload->LitCal);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Malformed data received in `LitCal` parameter: " . json_last_error_msg());
                }
                if (property_exists($this->params->payload, 'Overrides')) {
                    $CalData->Overrides = $this->params->payload->Overrides;
                }
                if (property_exists($this->params->payload, 'group')) {
                    $updateData->Group = strip_tags($this->params->payload->group);
                }
                $updateData->path = "nations/{$updateData->Nation}";
                if (!file_exists($updateData->path)) {
                    mkdir($updateData->path, 0755, true);
                }

                $test = $this->validateDataAgainstSchema($CalData, LitSchema::DIOCESAN);
                if ($test === true) {
                    $calendarData = json_encode($CalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    file_put_contents(
                        $updateData->path . "/{$updateData->Diocese}.json",
                        $calendarData . PHP_EOL
                    );
                    $this->createOrUpdateIndex($updateData);
                    $response->success = "Calendar data created or updated for Diocese \"{$updateData->Diocese}\" (Nation: \"$updateData->Nation\")";
                    self::produceResponse(json_encode($response));
                } else {
                    self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $test);
                }
        }
    }

    /**
     * Function deleteRegionalCalendar
     *
     * @return void
     */
    private function deleteRegionalCalendar()
    {
        $response = new \stdClass();
        switch ($this->params->category) {
            case "DIOCESANCALENDAR":
                $calendarDataFile = $this->generalIndex->{$this->params->key}->path;
                break;
            case "WIDERREGIONCALENDAR":
                $calendarDataFile = "nations/{$this->params->key}.json";
                break;
            case "NATIONALCALENDAR":
                $calendarDataFile = "nations/{$this->params->key}/{$this->params->key}.json";
                break;
        }
        if (file_exists($calendarDataFile)) {
            unlink($calendarDataFile);
            if ($this->params->category === 'DIOCESANCALENDAR') {
                $this->createOrUpdateIndex(null, true);
            }
        } else {
            self::produceErrorResponse(StatusCode::NOT_FOUND, "The resource '{$this->params->key}' requested for deletion was not found on this server");
        }
        $response->success = "Calendar data \"{$this->params->key}\" deleted successfully";
        self::produceResponse(json_encode($response));
    }

    /**
     * Function loadIndex
     *
     * @return void
     */
    private function loadIndex()
    {
        if (file_exists("nations/index.json")) {
            $this->generalIndex = json_decode(file_get_contents("nations/index.json"));
        }
    }

    /**
     * Function createOrUpdateIndex
     *  only needed when a diocesan calendar is created, updated or deleted
     * @param object  $data   Path of the resource file, Nation, Diocese, Group
     * @param boolean $delete Delete from the index rather than create/update the index
     *
     * @return void
     */
    private function createOrUpdateIndex(?object $data = null, bool $delete = false)
    {
        if (null === $this->generalIndex) {
            $this->generalIndex = new \stdClass();
        }
        $key = strtoupper(preg_replace("/[^a-zA-Z]/", "", $data->Diocese));

        if ($delete) {
            if (property_exists($this->generalIndex, $key)) {
                unset($this->generalIndex->$key);
            }
        } else {
            if (!property_exists($this->generalIndex, $key)) {
                $this->generalIndex->$key = new \stdClass();
            }
            $this->generalIndex->$key->path = $data->path . "/{$data->Diocese}.json";
            $this->generalIndex->$key->nation = $data->Nation;
            $this->generalIndex->$key->diocese = $data->Diocese;
            if (property_exists($data, 'Group')) {
                $this->generalIndex->$key->group = $data->Group;
            }
        }

        $test = $this->validateDataAgainstSchema($this->generalIndex, LitSchema::INDEX);
        if ($test === true) {
            $jsonEncodedContents = json_encode($this->generalIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents("nations/index.json", $jsonEncodedContents . PHP_EOL);
        } else {
            self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, json_encode($test));
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

    private function handleRequestParams(array $requestPathParts = []): void
    {
        if (count($requestPathParts)) {
            if (count($requestPathParts) !== 2) {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "Expected two and exactly two path params, received " . count($requestPathParts));
            } else {
                $data = new \stdClass();
                if (false === array_key_exists($requestPathParts[0], RegionalDataParams::EXPECTED_CATEGORIES)) {
                    self::produceErrorResponse(
                        StatusCode::BAD_REQUEST,
                        "Unexpected path param {$requestPathParts[0]}, acceptable values are: " . implode(', ', array_keys(RegionalDataParams::EXPECTED_CATEGORIES))
                    );
                } else {
                    $data->category = RegionalDataParams::EXPECTED_CATEGORIES[$requestPathParts[0]];
                    $data->key = $requestPathParts[1];
                }
            }
            if (in_array(self::$APICore->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
                $payload = null;
                switch (self::$APICore->getRequestContentType()) {
                    case RequestContentType::JSON:
                        $payload = self::$APICore->retrieveRequestParamsFromJsonBody();
                        break;
                    case RequestContentType::YAML:
                        $payload = self::$APICore->retrieveRequestParamsFromYamlBody();
                        break;
                    case RequestContentType::FORMDATA:
                        $payload = (object)$_POST;
                        break;
                    default:
                        if (in_array(self::$APICore->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
                            // the payload MUST be in the body of the request, either JSON encoded or YAML encoded
                            self::produceErrorResponse(StatusCode::BAD_REQUEST, "Expected payload in body of request, either JSON encoded or YAML encoded");
                        }
                }
                if (self::$APICore->getRequestMethod() === RequestMethod::POST && $payload !== null) {
                    if (property_exists($payload, 'locale')) {
                        $data->locale = $payload->locale;
                    }
                } else {
                    $data->payload = $payload;
                }
            } elseif (self::$APICore->getRequestMethod() === RequestMethod::GET) {
                if (isset($_GET['locale'])) {
                    $data->locale = $_GET['locale'];
                }
            }
        } elseif (self::$APICore->getRequestContentType() === RequestContentType::JSON) {
            $data = self::$APICore->retrieveRequestParamsFromJsonBody();
        } elseif (self::$APICore->getRequestContentType() === RequestContentType::YAML) {
            $data = self::$APICore->retrieveRequestParamsFromYamlBody();
        } else {
            $data = (object)$_REQUEST;
        }
        if (false === $this->params->setData($data)) {
            self::produceErrorResponse(StatusCode::BAD_REQUEST, "The params do not seem to be correct, must have params `category` and `key` with acceptable values");
        }
    }

    public static function produceErrorResponse(int $statusCode, string $description): void
    {
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message = new \stdClass();
        $message->status = "ERROR";
        $statusMessage = "";
        switch (self::$APICore->getRequestMethod()) {
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
        switch (self::$APICore->getResponseContentType()) {
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

    private static function produceResponse(string $jsonEncodedResponse): void
    {
        if (in_array(self::$APICore->getRequestMethod(), ['PUT','PATCH'])) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
        }
        switch (self::$APICore->getRequestContentType()) {
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
     * Function init
     *
     * @return void
     */
    public function init(array $requestPathParts = [])
    {
        self::$APICore->init();
        if (self::$APICore->getRequestMethod() === RequestMethod::GET) {
            self::$APICore->validateAcceptHeader(true);
        } else {
            self::$APICore->validateAcceptHeader(false);
        }
        self::$APICore->setResponseContentTypeHeader();
        $this->loadIndex();
        $this->handleRequestParams($requestPathParts);
        $this->handleRequestMethod();
    }
}
