<?php
/**
 * LitCal\LitCalRegionalData
 * PHP version 8.3
 * 
 * @category API
 * @package  LitCal
 * @author   John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license  https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @version  GIT: 3.9
 * @link     https://litcal.johnromanodorazio.com
 */

require_once 'includes/enums/AcceptHeader.php';
require_once 'includes/enums/LitSchema.php';
require_once 'includes/enums/RequestMethod.php';
require_once 'includes/enums/RequestContentType.php';
require_once 'includes/enums/ReturnType.php';
require_once 'includes/APICore.php';
require_once 'vendor/autoload.php';

use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;

if (file_exists("allowedOrigins.php")) {
    include_once 'allowedOrigins.php';
}

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com"
];

if (defined('ALLOWED_ORIGINS') && is_array(ALLOWED_ORIGINS)) {
    $allowedOrigins = array_merge($allowedOrigins, ALLOWED_ORIGINS);
}

$LitCalRegionalData = new LitCalRegionalData();

$LitCalRegionalData->APICore->setAllowedOrigins($allowedOrigins);
$LitCalRegionalData->APICore->setAllowedReferers(
    array_map(
        function ($el) {
            return $el . "/";
        },
        $allowedOrigins
    )
);

$LitCalRegionalData->APICore->setAllowedAcceptHeaders([ AcceptHeader::JSON ]);
$LitCalRegionalData->APICore->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
$LitCalRegionalData->init();

/**
 * LitCalRegionalData
 * PHP version 8.3
 * 
 * @category API
 * @package  LitCal
 * @author   John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license  https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @version  GIT: 3.9
 * @link     https://litcal.johnromanodorazio.com
 */
class LitCalRegionalData
{
    private object $_data;
    private object $_response;
    //The General Index is currently only used for diocesan calendars
    private ?stdClass $_generalIndex      = null;
    private array $_allowedRequestMethods = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ];

    public APICore $APICore;

    /**
     * LitCalRegionalData Constructor
     */
    public function __construct()
    {
        $this->APICore                              = new APICore();
        $this->_response                             = new stdClass();
        $this->_response->requestHeadersReceived     = $this->APICore->getJsonEncodedRequestHeaders();
    }

    /**
     * Function _handleRequestedMethod
     *
     * @return void
     */
    private function _handleRequestedMethod()
    {
        switch (strtoupper($_SERVER[ "REQUEST_METHOD" ])) {
            case RequestMethod::GET:
                $this->_handleGetPostRequests($_GET);
                break;
            case RequestMethod::POST:
                $this->_handleGetPostRequests($_POST);
                break;
            case RequestMethod::PUT:
            case RequestMethod::PATCH:
                $this->_handlePutPatchDeleteRequests(RequestMethod::PUT);
                break;
            case RequestMethod::DELETE:
                $this->_handlePutPatchDeleteRequests(RequestMethod::DELETE);
                break;
            case RequestMethod::OPTIONS:
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: " . implode(', ', $this->_allowedRequestMethods));
                }
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                    header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
                }
                continue;
            default:
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 405 Method Not Allowed", true, 405);
                $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                $errorMessage .= implode(' and ', $this->_allowedRequestMethods);
                $errorMessage .= ', but your Request Method was ' . strtoupper($_SERVER[ 'REQUEST_METHOD' ]) . '"}';
                die($errorMessage);
        }
    }

    /**
     * Function _handleGetPostRequests
     *
     * @param array $REQUEST represents the Request Body object
     * 
     * @return void
     */
    private function _handleGetPostRequests(array $REQUEST)
    {

        $this->APICore->validateAcceptHeader(true);
        if ($this->APICore->getRequestContentType() === 'application/json') {
            $this->_data = $this->APICore->retrieveRequestParamsFromJsonBody();
        } else {
            $this->_data = (object)$REQUEST;
        }
        $this->_retrieveRegionalCalendar();
    }

    /**
     * Function _handlePutPatchDeleteRequests
     *
     * @param string $requestMethod Method of the current request
     * 
     * @return void
     */
    private function _handlePutPatchDeleteRequests(string $requestMethod)
    {
        $this->APICore->validateAcceptHeader(false);
        $this->APICore->enforceAjaxRequest();
        $this->APICore->enforceReferer();
        if ($this->APICore->getRequestContentType() === 'application/json') {
            $this->_data = $this->APICore->retrieveRequestParamsFromJsonBody();
            if (RequestMethod::PUT === $requestMethod) {
                $this->_writeRegionalCalendar();
            } elseif (RequestMethod::DELETE === $requestMethod) {
                $this->_deleteRegionalCalendar();
            }
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 415 Unsupported Media Type", true, 415);
            die('{"error":"You seem to be forming a strange kind of request? Only \'application/json\' is allowed as the Content Type for the body of the Request when using Request Methods PUT, PATCH, or DELETE: the Content Type for the body of your Request was ' . $_SERVER[ 'CONTENT_TYPE' ] . ' and you are using Request Method ' . $_SERVER[ 'REQUEST_METHOD' ] . '"}');
        }
    }

    /**
     * Function _retrieveRegionalCalendar
     *
     * @return void
     */
    private function _retrieveRegionalCalendar()
    {
        if (property_exists($this->_data, 'category') && property_exists($this->_data, 'key')) {
            $category = $this->_data->category;
            $key = $this->_data->key;
            switch ($category) {
                case "diocesanCalendar":
                    $calendarDataFile = $this->_generalIndex->$key->path;
                    break;
                case "widerRegionCalendar":
                    $calendarDataFile = "nations/{$key}.json";
                    break;
                case "nationalCalendar":
                    $calendarDataFile = "nations/{$key}/{$key}.json";
                    break;
            }

            if (file_exists($calendarDataFile)) {
                if ($category === "diocesanCalendar") {
                    echo file_get_contents($calendarDataFile);
                    die();
                } else {
                    $this->_response = json_decode(file_get_contents($calendarDataFile));
                    $uKey = strtoupper($key);
                    if ($category === "widerRegionCalendar") {
                        $this->_response->isMultilingual = is_dir("nations/{$uKey}");
                        $locale = strtolower($this->_data->locale);
                        if (file_exists("nations/{$uKey}/{$locale}.json")) {
                            $localeData = json_decode(file_get_contents("nations/{$uKey}/{$locale}.json"));
                            foreach ($this->_response->LitCal as $idx => $el) {
                                $this->_response->LitCal[$idx]->Festivity->name = $localeData->{$this->_response->LitCal[$idx]->Festivity->tag};
                            }
                        }
                    }
                    echo json_encode($this->_response);
                    die();
                }
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 404 Not Found", true, 404);
                echo "{\"message\":\"file $calendarDataFile does not exist\"}";
                die();
            }
        } else {
            $missingParams = [];
            if (false === property_exists($this->_data, 'category') ) {
                array_push($missingParams, 'category');
            }
            if (false === property_exists($this->_data, 'key') ) {
                array_push($missingParams, 'key');
            }
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
            die('{"error":"Missing required parameter(s) `' . implode('` and `', $missingParams) . '`"}');
        }
    }

    /**
     * Function _writeRegionalCalendar
     *
     * @return void
     */
    private function _writeRegionalCalendar()
    {
        if (property_exists($this->_data, 'LitCal') && property_exists($this->_data, 'Metadata') && property_exists($this->_data, 'Settings')) {
            $region = $this->_data->Metadata->Region;
            if ($region === 'UNITED STATES') {
                $region = 'USA';
            }
            $path = "nations/{$region}";
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }

            $test = $this->_validateDataAgainstSchema($this->_data, LitSchema::NATIONAL);
            if ($test === true) {
                $data = json_encode($this->_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents($path . "/{$region}.json", $data . PHP_EOL);
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
                die('{"success":"National calendar created or updated for nation \"' . $this->_data->Metadata->Region . '\""}');
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
                die(json_encode($test));
            }
        } elseif (property_exists($this->_data, 'LitCal') && property_exists($this->_data, 'Metadata') && property_exists($this->_data, 'NationalCalendars')) {
            $this->_data->Metadata->WiderRegion = ucfirst(strtolower($this->_data->Metadata->WiderRegion));
            $widerRegion = strtoupper($this->_data->Metadata->WiderRegion);
            if ($this->_data->Metadata->IsMultilingual === true) {
                $path = "nations/{$widerRegion}";
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }
                $translationJSON = new stdClass();
                foreach ($this->_data->LitCal as $CalEvent) {
                    $translationJSON->{ $CalEvent->Festivity->tag } = '';
                }
                if (count($this->_data->Metadata->Languages) > 0) {
                    foreach ($this->_data->Metadata->Languages as $iso) {
                        if (!file_exists("nations/{$widerRegion}/{$iso}.json")) {
                            file_put_contents("nations/{$widerRegion}/{$iso}.json", json_encode($translationJSON, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }
                }
            }

            $test = $this->_validateDataAgainstSchema($this->_data, LitSchema::WIDERREGION);
            if ($test === true) {
                $data = json_encode($this->_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents("nations/{$this->_data->Metadata->WiderRegion}.json", $data . PHP_EOL);
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
                die('{"success":"Wider region calendar created or updated for region \"' . $this->_data->Metadata->WiderRegion . '\""}');
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
                die(json_encode($test));
            }
        } elseif (property_exists($this->_data, 'LitCal') && property_exists($this->_data, 'Diocese') && property_exists($this->_data, 'Nation')) {
            $this->_response->Nation = strip_tags($this->_data->Nation);
            $this->_response->Diocese = strip_tags($this->_data->Diocese);
            $CalData = json_decode($this->_data->LitCal);
            if (json_last_error() !== JSON_ERROR_NONE) {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
                die('{"error":"Malformed data received in <LitCal> parameters"}');
            }
            if (property_exists($this->_data, 'Overrides')) {
                $CalData->Overrides = $this->_data->Overrides;
            }
            $this->_response->Calendar = json_encode($CalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (property_exists($this->_data, 'group')) {
                $this->_response->Group = strip_tags($this->_data->group);
            }
            $path = "nations/{$this->_response->Nation}";
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }

            $test = $this->_validateDataAgainstSchema($CalData, LitSchema::DIOCESAN);
            if ($test === true) {
                file_put_contents($path . "/{$this->_response->Diocese}.json", $this->_response->Calendar . PHP_EOL);
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 422 Unprocessable Entity", true, 422);
                die(json_encode($test));
            }

            $this->_createOrUpdateIndex($path);
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
            die('{"success":"Diocesan calendar created or updated for diocese \"' . $this->_response->Diocese . '\""}');
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
            die('{"error":"Not all required parameters were received (LitCal, Metadata, Settings|NationalCalendars OR LitCal, Diocese, Nation)"}');
        }
    }

    /**
     * Function _deleteRegionalCalendar
     *
     * @return void
     */
    private function _deleteRegionalCalendar()
    {
        if (!property_exists($this->_data, 'LitCal') || !property_exists($this->_data, 'Diocese') || !property_exists($this->_data, 'Nation')) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad request", true, 400);
            die('{"error":"Required parameters were not received"}');
        } else {
            $this->_response->Nation = strip_tags($this->_data->Nation);
            $this->_response->Diocese = strip_tags($this->_data->Diocese);
            $path = "nations/{$this->_response->Nation}";
            if (file_exists($path . "/{$this->_response->Diocese}.json")) {
                unlink($path . "/{$this->_response->Diocese}.json");
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 404 Not Found", true, 404);
                die('{"error":"The resource requested for deletion was not found on this server"}');
            }

            $this->_createOrUpdateIndex($path, true);
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 200 OK", true, 200);
            die('{"success":"Diocesan calendar \"' . $this->_response->Diocese . '\" deleted from nation \"' . $this->_response->Nation . '\""}');
        }
    }

    /**
     * Function _loadIndex
     *
     * @return void
     */
    private function _loadIndex()
    {
        if (file_exists("nations/index.json")) {
            $this->_generalIndex = json_decode(file_get_contents("nations/index.json"));
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
    private function _createOrUpdateIndex(string $path, bool $delete = false)
    {
        if (null === $this->_generalIndex) {
            $this->_generalIndex = new stdClass();
        }
        $key = strtoupper(preg_replace("/[^a-zA-Z]/", "", $this->_response->Diocese));

        if ($delete) {
            if (property_exists($this->_generalIndex, $key)) {
                unset($this->_generalIndex->$key);
            }
        } else {
            if (!property_exists($this->_generalIndex, $key)) {
                $this->_generalIndex->$key = new stdClass();
            }
            $this->_generalIndex->$key->path = $path . "/{$this->_response->Diocese}.json";
            $this->_generalIndex->$key->nation = $this->_response->Nation;
            $this->_generalIndex->$key->diocese = $this->_response->Diocese;
            if (property_exists($this->_response, 'Group')) {
                $this->_generalIndex->$key->group = $this->_response->Group;
            }
        }

        $test = $this->_validateDataAgainstSchema($this->_generalIndex, LitSchema::INDEX);
        if ($test === true) {
            file_put_contents("nations/index.json", json_encode($this->_generalIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
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
    private function _validateDataAgainstSchema(object $data, string $schemaUrl): bool
    {
        $result = new stdClass();
        $schema = Schema::import($schemaUrl);
        try {
            $schema->in($data);
            return true;
        } catch (InvalidValue|Exception $e) {
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
        $this->APICore->Init();
        $this->APICore->setResponseContentTypeHeader();
        $this->_loadIndex();
        $this->_handleRequestedMethod();
    }
}
