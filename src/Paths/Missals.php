<?php

namespace Johnrdorazio\LitCal\Paths;

use Johnrdorazio\LitCal\APICore;
use Johnrdorazio\LitCal\Params\MissalsParams;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\RequestContentType;
use Johnrdorazio\LitCal\Enum\RequestMethod;
use Johnrdorazio\LitCal\Enum\StatusCode;

class Missals
{
    public static APICore $APICore;
    public static MissalsParams $params;
    public static object $missalsIndex;
    private static array $requestPathParts = [];

    private static function initRequestParams(): array
    {
        $data = [];
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
            if (self::$APICore->getRequestMethod() === RequestMethod::POST) {
                if ($payload !== null && property_exists($payload, 'locale')) {
                    $data["LOCALE"] = $payload->locale;
                } else {
                    $data["LOCALE"] = LitLocale::LATIN;
                }
            } else {
                $data["PAYLOAD"] = $payload;
            }
        } elseif (self::$APICore->getRequestMethod() === RequestMethod::GET) {
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
                    if (property_exists(self::$missalsIndex->LitCalMissals, self::$requestPathParts[0])) {
                        self::produceResponse(json_encode(self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}));
                    } else {
                        $missals = array_keys(get_object_vars(self::$missalsIndex));
                        $error = "No Roman Missal found corresponding to " . self::$requestPathParts[0] . ", valid values are: " . implode(', ', $missals);
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                    }
                    break;
                case 2:
                    if (false === property_exists(self::$missalsIndex->LitCalMissals, self::$requestPathParts[0])) {
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "No Roman Missal found corresponding to " . self::$requestPathParts[0]);
                    }
                    self::$params = new MissalsParams(["YEAR" => self::$requestPathParts[1]]);
                    if (property_exists(self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}, self::$params->Year)) {
                        $dataPath = self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}->{self::$params->Year}->path;
                        $i18n = null;
                        if (property_exists(self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}->{self::$params->Year}, 'languages')) {
                            $languages = self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}->{self::$params->Year}->languages;
                            self::$params->setData(self::initRequestParams());
                            if (null !== self::$params->Locale) {
                                $baseLocale = \Locale::getPrimaryLanguage(self::$params->Locale);
                                if (in_array($baseLocale, $languages) && property_exists(self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}->{self::$params->Year}, 'i18nPath')) {
                                    $i18nFile = self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}->{self::$params->Year}->i18nPath . $baseLocale . ".json";
                                    $i18nData = file_get_contents($i18nFile);
                                    if ($i18nData) {
                                        $i18n = json_decode($i18nData);
                                        if (JSON_ERROR_NONE !== json_last_error()) {
                                            $error = "Error while processing localized data from file {$i18nFile}: " . json_last_error_msg();
                                            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $error);
                                        }
                                    } else {
                                        self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Unable to read localized data from file {$i18nFile}");
                                    }
                                }
                            }
                        }
                        if (file_exists($dataPath)) {
                            $dataRaw = file_get_contents($dataPath);
                            if ($dataRaw) {
                                if (null !== $i18n) {
                                    $data = json_decode($dataRaw);
                                    if (JSON_ERROR_NONE !== json_last_error()) {
                                        $error = "Error while processing decoding json data from file {$dataPath}: " . json_last_error_msg();
                                        self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $error);
                                    } else {
                                        foreach ($data as $idx => $row) {
                                            $key = $row->TAG;
                                            $data[$idx]->NAME = property_exists($i18n, $key) ? $i18n->{$key} : '';
                                        }
                                        self::produceResponse(json_encode($data));
                                    }
                                } else {
                                    self::produceResponse($dataRaw);
                                }
                            } else {
                                self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Unable to read Missal data from file {$dataPath}");
                            }
                        } else {
                            self::produceErrorResponse(StatusCode::NOT_FOUND, "This is a server error, not a request error: the expected file {$dataPath} was not found");
                        }
                    } else {
                        $RomanMissalYears = array_keys(get_object_vars(self::$missalsIndex->LitCalMissals->{self::$requestPathParts[0]}));
                        $error = "No Roman Missal was found for the year " . self::$params->Year . ", valid values are: " . implode(', ', $RomanMissalYears);
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                    }
                    break;
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
        switch (self::$APICore->getResponseContentType()) {
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
        self::$missalsIndex->description = "Roman Missals that contribute to generating the Liturgical Calendar are found under the `LitCalMissals` property. Use those property names as the next parameter on the API request path to refine your request.";
        self::$missalsIndex->LitCalMissals = new \stdClass();
        self::$missalsIndex->{'$schema'} = "https://litcal.johnromanodorazio.com/api/dev/schemas/MissalsIndex.json";
        self::$missalsIndex->LitCalMissals->EditioTypica = new \stdClass();
        $directories = array_map('basename', glob('data/propriumdesanctis*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            if (file_exists("data/$directory/$directory.json")) {
                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $directory, $matches)) {
                    self::$missalsIndex->LitCalMissals->EditioTypica->{$matches[1]} = new \stdClass();
                    self::$missalsIndex->LitCalMissals->EditioTypica->{$matches[1]}->path = "data/$directory/$directory.json";
                    $it = new \DirectoryIterator("glob://data/$directory/i18n/*.json");
                    $languages = [];
                    foreach ($it as $f) {
                        $languages[] = $f->getBasename('.json');
                    }
                    self::$missalsIndex->LitCalMissals->EditioTypica->{$matches[1]}->languages = $languages;
                    self::$missalsIndex->LitCalMissals->EditioTypica->{$matches[1]}->i18nPath = "data/$directory/i18n/";
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $directory, $matches)) {
                    if (false === property_exists(self::$missalsIndex, $matches[1])) {
                        self::$missalsIndex->LitCalMissals->{$matches[1]} = new \stdClass();
                    }
                    self::$missalsIndex->LitCalMissals->{$matches[1]}->{$matches[2]} = new \stdClass();
                    self::$missalsIndex->LitCalMissals->{$matches[1]}->{$matches[2]}->path = "data/$directory/$directory.json";
                }
            }
        }
        self::$APICore = new APICore();
    }

    public static function handleRequest()
    {
        self::$APICore->init();
        if (self::$APICore->getRequestMethod() === RequestMethod::GET) {
            self::$APICore->validateAcceptHeader(true);
        } else {
            self::$APICore->validateAcceptHeader(false);
        }
        self::$APICore->setResponseContentTypeHeader();
        if (count(self::$requestPathParts) === 0) {
            self::produceResponse(json_encode(self::$missalsIndex));
        }
        self::handlePathParams();
    }
}
