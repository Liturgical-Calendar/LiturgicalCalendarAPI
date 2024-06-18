<?php

namespace Johnrdorazio\LitCal\Paths;

use Johnrdorazio\LitCal\APICore;
use Johnrdorazio\LitCal\Params\MissalParams;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Enum\RequestMethod;

class Missal
{
    public static APICore $APICore;
    public static MissalParams $params;
    public static object $missalsIndex;
    private static array $requestPathParts = [];

    public static function initParams(string $route)
    {
        $numPathParts = count(self::$requestPathParts);
        if ($numPathParts > 0) {
            if ($route === 'missals') {
                self::produceErrorResponse(StatusCode::BAD_REQUEST, "No path parameters available for `/missals` route, maybe you meant `/missal`?");
            }
            if ($numPathParts === 1) {
                // We should expect a Year value
                if ('nation' === self::$requestPathParts[0]) {
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Missing value for /missal/nation/{value} path");
                }
                self::$params = new MissalParams(["YEAR" => self::$requestPathParts[0]]);
                if (property_exists(self::$missalsIndex->EditioTypica, self::$params->Year)) {
                    $dataPath = self::$missalsIndex->EditioTypica->{self::$params->Year}->path;
                    if (file_exists($dataPath)) {
                        $dataRaw = file_get_contents($dataPath);
                        if ($dataRaw) {
                            self::produceResponse($dataRaw);
                        }
                    } else {
                        self::produceErrorResponse(StatusCode::NOT_FOUND, "This is a server error, not a request error: the expected file {$dataPath} was not found");
                    }
                } else {
                    $LatinRomanMissalYears = array_keys(get_object_vars(self::$missalsIndex->EditioTypica));
                    $error = "No Latin edition of the Roman Missal was found for the year "
                        . self::$params->Year . ", valid values are: " . implode(', ', $LatinRomanMissalYears);
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                }
            } elseif ($numPathParts === 2) {
                // We should expect a Nation value
                if (self::$requestPathParts[0] !== 'nation') {
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Unexpected path parameter `/" . self::$requestPathParts[0] . "/`, expected `/nation/`");
                } else {
                    self::$params = new MissalParams(["NATION" => self::$requestPathParts[1]]);
                    if (property_exists(self::$missalsIndex, self::$params->Nation)) {
                        self::produceResponse(json_encode(self::$missalsIndex->EditioTypica->{self::$params->Nation}));
                    }
                }
            }
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

    public static function init(array $requestPathParts = [])
    {
        if (count($requestPathParts)) {
            self::$requestPathParts = $requestPathParts;
        }
        self::$missalsIndex = new \stdClass();
        self::$missalsIndex->EditioTypica = new \stdClass();
        $directories = array_map('basename', glob('data/propriumdesanctis*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            if (file_exists("data/$directory/$directory.json")) {
                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $directory, $matches)) {
                    self::$missalsIndex->EditioTypica->{$matches[1]} = new \stdClass();
                    self::$missalsIndex->EditioTypica->{$matches[1]}->path = "data/$directory/$directory.json";
                    $it = new \DirectoryIterator("glob://data/$directory/i18n/*.json");
                    $languages = [];
                    foreach ($it as $f) {
                        $languages[] = $f->getBasename('.json');
                    }
                    self::$missalsIndex->EditioTypica->{$matches[1]}->languages = $languages;
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $directory, $matches)) {
                    if (false === property_exists(self::$missalsIndex, $matches[1])) {
                        self::$missalsIndex->{$matches[1]} = new \stdClass();
                    }
                    self::$missalsIndex->{$matches[1]}->{$matches[2]} = new \stdClass();
                    self::$missalsIndex->{$matches[1]}->{$matches[2]}->path = "data/$directory/$directory.json";
                }
            }
        }
        self::$APICore = new APICore();
    }

    public static function handleRequest(string $route)
    {
        self::$APICore->init();
        if (self::$APICore->getRequestMethod() === RequestMethod::GET) {
            self::$APICore->validateAcceptHeader(true);
        } else {
            self::$APICore->validateAcceptHeader(false);
        }
        self::$APICore->setResponseContentTypeHeader();
        if ($route === 'missals' && count(self::$requestPathParts) === 0) {
            self::produceResponse(json_encode(self::$missalsIndex));
        } elseif ($route === 'missal' && count(self::$requestPathParts) === 0) {
            self::produceResponse(json_encode(self::$missalsIndex->EditioTypica));
        }
        self::initParams($route);
    }
}
