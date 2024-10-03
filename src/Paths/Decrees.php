<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Params\DecreesParams;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\StatusCode;

class Decrees
{
    public static Core $Core;
    public static object $decreesIndex;
    private static array $requestPathParts = [];

    private static function initRequestParams(): array
    {
        $data = [];
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
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
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Expected payload in body of request, either JSON encoded or YAML encoded");
                    }
            }
            if (self::$Core->getRequestMethod() === RequestMethod::POST) {
                if ($payload !== null && property_exists($payload, 'locale')) {
                    $data["LOCALE"] = $payload->locale;
                } else {
                    $data["LOCALE"] = LitLocale::LATIN;
                }
            } else {
                $data["PAYLOAD"] = $payload;
            }
        } elseif (self::$Core->getRequestMethod() === RequestMethod::GET) {
            $_GET = array_change_key_case($_GET, CASE_LOWER);
            if (isset($_GET['locale'])) {
                $data["LOCALE"] = $_GET['locale'];
            } else {
                $data["LOCALE"] = LitLocale::LATIN;
            }
        }
        return $data;
    }

    private static function handlePathParams()
    {
        $numPathParts = count(self::$requestPathParts);
        if ($numPathParts > 0) {
            switch ($numPathParts) {
                case 1:
                    $decreeIds = [];
                    foreach (self::$decreesIndex->litcal_decrees as $idx => $decree) {
                        if ($decree->decree_id === self::$requestPathParts[0]) {
                            self::produceResponse(json_encode(self::$decreesIndex->litcal_decrees[$idx]));
                        }
                        $decreeIds[] = $decree->decree_id;
                    }

                    $error = "No Decree of the Congregation for Divine Worship found corresponding to "
                        . self::$requestPathParts[0]
                        . ", valid values are found in the `decree_id` properties of the `litcal_decrees` collection: " . implode(', ', $decreeIds);
                    self::produceErrorResponse(StatusCode::NOT_FOUND, $error);
                    break;
                default:
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Only one path parameter expected on the `/decrees` path, instead $numPathParts found");
            }
        }
    }

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
                $statusMessage = "Resource not found";
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

    public static function init(array $requestPathParts = [])
    {
        if (count($requestPathParts)) {
            self::$requestPathParts = $requestPathParts;
        }
        $decreesFile = 'data/decrees/decrees.json';
        if (file_exists($decreesFile)) {
            $rawData = file_get_contents($decreesFile);
            self::$decreesIndex = new \stdClass();
            self::$decreesIndex->litcal_decrees = json_decode($rawData);
            foreach (self::$decreesIndex->litcal_decrees as $idx => $decree) {
                $decreeId = $decree->decree_id;
                self::$decreesIndex->litcal_decrees[$idx]->api_path = API_BASE_PATH . "/decrees/$decreeId";
            }
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 404 Not Found", true, 404);
            die('Decrees file not found');
        }
        self::$Core = new Core();
    }

    public static function handleRequest()
    {
        self::$Core->init();
        if (self::$Core->getRequestMethod() === RequestMethod::GET) {
            self::$Core->validateAcceptHeader(true);
        } else {
            self::$Core->validateAcceptHeader(false);
        }
        self::$Core->setResponseContentTypeHeader();
        if (count(self::$requestPathParts) === 0) {
            self::produceResponse(json_encode(self::$decreesIndex));
        }
        self::handlePathParams();
    }
}
