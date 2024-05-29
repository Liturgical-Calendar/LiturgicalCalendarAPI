<?php

namespace LitCal;

use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use LitCal\APICore;
use LitCal\enum\RequestMethod;
use LitCal\enum\LitSchema;

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
    private object $data;
    private object $response;
    //The General Index is currently only used for diocesan calendars
    private ?\stdClass $generalIndex      = null;
    private array $allowedRequestMethods = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ];

    public APICore $APICore;

    /**
     * LitCalRegionalData Constructor
     */
    public function __construct()
    {
        $this->APICore                              = new APICore();
        $this->response                             = new \stdClass();
        $this->response->requestHeadersReceived     = $this->APICore->getJsonEncodedRequestHeaders();
    }

    /**
     * Function _handleRequestedMethod
     *
     * @return void
     */
    private function handleRequestedMethod()
    {
        switch (strtoupper($_SERVER[ "REQUEST_METHOD" ])) {
            case RequestMethod::GET:
                $this->handleGetPostRequests($_GET);
                break;
            case RequestMethod::POST:
                $this->handleGetPostRequests($_POST);
                break;
            case RequestMethod::PUT:
            case RequestMethod::PATCH:
                $this->handlePutPatchDeleteRequests(RequestMethod::PUT);
                break;
            case RequestMethod::DELETE:
                $this->handlePutPatchDeleteRequests(RequestMethod::DELETE);
                break;
            case RequestMethod::OPTIONS:
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: " . implode(', ', $this->allowedRequestMethods));
                }
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                    header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
                }
                continue;
            default:
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 405 Method Not Allowed", true, 405);
                $response = new \stdClass();
                $response->error = "You seem to be forming a strange kind of request? Allowed Request Methods are "
                    . implode(',', $this->allowedRequestMethods)
                    . " but your Request Method was "
                    . strtoupper($_SERVER[ 'REQUEST_METHOD' ]);
                die(json_encode($response));
        }
    }

    /**
     * Function _handleGetPostRequests
     *
     * @param array $REQUEST represents the Request Body object
     *
     * @return void
     */
    private function handleGetPostRequests(array $REQUEST)
    {

        $this->APICore->validateAcceptHeader(true);
        if ($this->APICore->getRequestContentType() === 'application/json') {
            $this->data = $this->APICore->retrieveRequestParamsFromJsonBody();
        } else {
            $this->data = (object)$REQUEST;
        }
        $this->retrieveRegionalCalendar();
    }

    /**
     * Function _handlePutPatchDeleteRequests
     *
     * @param string $requestMethod Method of the current request
     *
     * @return void
     */
    private function handlePutPatchDeleteRequests(string $requestMethod)
    {
        $this->APICore->validateAcceptHeader(false);
        $this->APICore->enforceAjaxRequest();
        $this->APICore->enforceReferer();
        if ($this->APICore->getRequestContentType() === 'application/json') {
            $this->data = $this->APICore->retrieveRequestParamsFromJsonBody();
            if (RequestMethod::PUT === $requestMethod) {
                $this->writeRegionalCalendar();
            } elseif (RequestMethod::DELETE === $requestMethod) {
                $this->deleteRegionalCalendar();
            }
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 415 Unsupported Media Type", true, 415);
            $response = new \stdClass();
            $response->error = "You seem to be forming a strange kind of request?"
                . " Only 'application/json' is allowed as the Content Type"
                . " for the body of the Request when using Request Methods PUT, PATCH, or DELETE:"
                . " the Content Type for the body of your Request was {$_SERVER[ 'CONTENT_TYPE' ]}"
                . " and you are using Request Method {$_SERVER[ 'REQUEST_METHOD' ]}";
            die(json_encode($response));
        }
    }

    /**
     * Function _retrieveRegionalCalendar
     *
     * @return void
     */
    private function retrieveRegionalCalendar()
    {
        if (property_exists($this->data, 'category') && property_exists($this->data, 'key')) {
            $category = strtolower($this->data->category);
            $key = $this->data->key;
            switch ($category) {
                case "diocesancalendar":
                    $calendarDataFile = "../" . $this->generalIndex->$key->path;
                    break;
                case "widerregioncalendar":
                    $calendarDataFile = "../nations/{$key}.json";
                    break;
                case "nationalcalendar":
                    $calendarDataFile = "../nations/{$key}/{$key}.json";
                    break;
            }

            if (file_exists($calendarDataFile)) {
                if ($category === "diocesanCalendar") {
                    echo file_get_contents($calendarDataFile);
                    die();
                } else {
                    $this->response = json_decode(file_get_contents($calendarDataFile));
                    $uKey = strtoupper($key);
                    if ($category === "widerRegionCalendar") {
                        $this->response->isMultilingual = is_dir("nations/{$uKey}");
                        $locale = strtolower($this->data->locale);
                        if (file_exists("../nations/{$uKey}/{$locale}.json")) {
                            $localeData = json_decode(file_get_contents("../nations/{$uKey}/{$locale}.json"));
                            foreach ($this->response->LitCal as $idx => $el) {
                                $this->response->LitCal[$idx]->Festivity->name =
                                    $localeData->{$this->response->LitCal[$idx]->Festivity->tag};
                            }
                        }
                    }
                    echo json_encode($this->response);
                    die();
                }
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 404 Not Found", true, 404);
                $response = new \stdClass();
                $response->message = "file $calendarDataFile does not exist";
                $response->cwd = getcwd();
                die(json_encode($response));
            }
        } else {
            $missingParams = [];
            if (false === property_exists($this->data, 'category')) {
                array_push($missingParams, 'category');
            }
            if (false === property_exists($this->data, 'key')) {
                array_push($missingParams, 'key');
            }
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
            $response = new \stdClass();
            $response->error = "Missing required parameter(s) `" . implode("` and `", $missingParams) . "`";
            die(json_encode($response));
        }
    }

    /**
     * Function _writeRegionalCalendar
     *
     * @return void
     */
    private function writeRegionalCalendar()
    {
        $response = new \stdClass();
        if (
            property_exists($this->data, 'LitCal')
            && property_exists($this->data, 'Metadata')
            && property_exists($this->data, 'Settings')
        ) {
            $region = $this->data->Metadata->Region;
            if ($region === 'UNITED STATES') {
                $region = 'USA';
            }
            $path = "../nations/{$region}";
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }

            $test = $this->validateDataAgainstSchema($this->data, LitSchema::NATIONAL);
            if ($test === true) {
                $data = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents($path . "/{$region}.json", $data . PHP_EOL);
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
                $response->success = "National calendar created or updated"
                    . " for nation \"{$this->data->Metadata->Region}\"";
                die(json_encode($response));
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
                die(json_encode($test));
            }
        } elseif (
            property_exists($this->data, 'LitCal')
            && property_exists($this->data, 'Metadata')
            && property_exists($this->data, 'NationalCalendars')
        ) {
            $this->data->Metadata->WiderRegion = ucfirst(strtolower($this->data->Metadata->WiderRegion));
            $widerRegion = strtoupper($this->data->Metadata->WiderRegion);
            if ($this->data->Metadata->IsMultilingual === true) {
                $path = "../nations/{$widerRegion}";
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }
                $translationJSON = new \stdClass();
                foreach ($this->data->LitCal as $CalEvent) {
                    $translationJSON->{ $CalEvent->Festivity->tag } = '';
                }
                if (count($this->data->Metadata->Languages) > 0) {
                    foreach ($this->data->Metadata->Languages as $iso) {
                        if (!file_exists("../nations/{$widerRegion}/{$iso}.json")) {
                            file_put_contents(
                                "../nations/{$widerRegion}/{$iso}.json",
                                json_encode($translationJSON, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                            );
                        }
                    }
                }
            }

            $test = $this->validateDataAgainstSchema($this->data, LitSchema::WIDERREGION);
            if ($test === true) {
                $data = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents("../nations/{$this->data->Metadata->WiderRegion}.json", $data . PHP_EOL);
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
                $response->success = "Wider region calendar created or updated"
                    . " for region \"{$this->data->Metadata->WiderRegion}\"";
                die(json_encode($response));
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
                die(json_encode($test));
            }
        } elseif (
            property_exists($this->data, 'LitCal')
            && property_exists($this->data, 'Diocese')
            && property_exists($this->data, 'Nation')
        ) {
            $this->response->Nation = strip_tags($this->data->Nation);
            $this->response->Diocese = strip_tags($this->data->Diocese);
            $CalData = json_decode($this->data->LitCal);
            if (json_last_error() !== JSON_ERROR_NONE) {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
                $response->error = "Malformed data received in <LitCal> parameters";
                die(json_encode($response));
            }
            if (property_exists($this->data, 'Overrides')) {
                $CalData->Overrides = $this->data->Overrides;
            }
            $this->response->Calendar = json_encode($CalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (property_exists($this->data, 'group')) {
                $this->response->Group = strip_tags($this->data->group);
            }
            $path = "../nations/{$this->response->Nation}";
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }

            $test = $this->validateDataAgainstSchema($CalData, LitSchema::DIOCESAN);
            if ($test === true) {
                file_put_contents(
                    $path . "/{$this->response->Diocese}.json",
                    $this->response->Calendar . PHP_EOL
                );
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
                die(json_encode($test));
            }

            $this->createOrUpdateIndex($path);
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
            $response->success = "Diocesan calendar created or updated for diocese \"{$this->response->Diocese}\"";
            die(json_encode($response));
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
            $response->error = "Not all required parameters were received"
                . " (LitCal, Metadata, Settings|NationalCalendars OR LitCal, Diocese, Nation)";
            die(json_encode($response));
        }
    }

    /**
     * Function _deleteRegionalCalendar
     *
     * @return void
     */
    private function deleteRegionalCalendar()
    {
        $response = new \stdClass();
        if (
            !property_exists($this->data, 'LitCal')
            || !property_exists($this->data, 'Diocese')
            || !property_exists($this->data, 'Nation')
        ) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
            $response->error = "Required parameters were not received";
            die(json_encode($response));
        } else {
            $this->response->Nation = strip_tags($this->data->Nation);
            $this->response->Diocese = strip_tags($this->data->Diocese);
            $path = "../nations/{$this->response->Nation}";
            if (file_exists($path . "/{$this->response->Diocese}.json")) {
                unlink($path . "/{$this->response->Diocese}.json");
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 404 Not Found", true, 404);
                $response->error = "The resource requested for deletion was not found on this server";
                die(json_encode($response));
            }

            $this->createOrUpdateIndex($path, true);
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 200 OK", true, 200);
            $response->success = "Diocesan calendar \"{$this->response->Diocese}\""
                . " deleted from nation \"{$this->response->Nation}\"";
            die(json_encode($response));
        }
    }

    /**
     * Function _loadIndex
     *
     * @return void
     */
    private function loadIndex()
    {
        if (file_exists("../nations/index.json")) {
            $this->generalIndex = json_decode(file_get_contents("../nations/index.json"));
        }
    }

    /**
     * Function _createOrUpdateIndex
     *
     * @param string  $path   Path of the resource file
     * @param boolean $delete Delete from the index rather than create/update the index
     *
     * @return void
     */
    private function createOrUpdateIndex(string $path, bool $delete = false)
    {
        if (null === $this->generalIndex) {
            $this->generalIndex = new \stdClass();
        }
        $key = strtoupper(preg_replace("/[^a-zA-Z]/", "", $this->response->Diocese));

        if ($delete) {
            if (property_exists($this->generalIndex, $key)) {
                unset($this->generalIndex->$key);
            }
        } else {
            if (!property_exists($this->generalIndex, $key)) {
                $this->generalIndex->$key = new \stdClass();
            }
            $this->generalIndex->$key->path = $path . "/{$this->response->Diocese}.json";
            $this->generalIndex->$key->nation = $this->response->Nation;
            $this->generalIndex->$key->diocese = $this->response->Diocese;
            if (property_exists($this->response, 'Group')) {
                $this->generalIndex->$key->group = $this->response->Group;
            }
        }

        $test = $this->validateDataAgainstSchema($this->generalIndex, LitSchema::INDEX);
        if ($test === true) {
            $jsonEncodedContents = json_encode($this->generalIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents("../nations/index.json", $jsonEncodedContents . PHP_EOL);
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
            die(json_encode($test));
        }
    }

    /**
     * Function _validateDataAgainstSchema
     *
     * @param object $data      Data to validate
     * @param string $schemaUrl Schema to validate against
     *
     * @return boolean
     */
    private function validateDataAgainstSchema(object $data, string $schemaUrl): bool
    {
        $result = new \stdClass();
        $schema = Schema::import($schemaUrl);
        try {
            $schema->in($data);
            return true;
        } catch (InvalidValue | \Exception $e) {
            $result->error = LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage();
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
            die(json_encode($result));
        }
    }

    /**
     * Function init
     *
     * @return void
     */
    public function init()
    {
        $this->APICore->init();
        $this->APICore->setResponseContentTypeHeader();
        $this->loadIndex();
        $this->handleRequestedMethod();
    }
}
