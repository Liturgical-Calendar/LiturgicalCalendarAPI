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
                    $locale = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                    if ($locale && LitLocale::isValid($locale)) {
                        $data["LOCALE"] = $locale;
                    } else {
                        $data["LOCALE"] = LitLocale::LATIN;
                    }
                }
                if (property_exists($payload, 'region')) {
                    $data["REGION"] = $payload->region;
                }
                if (property_exists($payload, 'year')) {
                    $data["YEAR"] = $payload->year;
                }
            } else {
                $data["PAYLOAD"] = $payload;
            }
        } elseif (self::$APICore->getRequestMethod() === RequestMethod::GET) {
            if (isset($_GET['locale'])) {
                $data["LOCALE"] = $_GET['locale'];
            } else {
                $locale = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                if ($locale && LitLocale::isValid($locale)) {
                    $data["LOCALE"] = $locale;
                } else {
                    $data["LOCALE"] = LitLocale::LATIN;
                }
            }
            if (isset($_GET['region'])) {
                $data["REGION"] = $_GET["region"];
            }
            if (isset($_GET['year'])) {
                $data["YEAR"] = $_GET['year'];
            }
        }
        return $data;
    }

    private static function handlePathParams()
    {
        $numPathParts = count(self::$requestPathParts);
        $missalIDs = [];
        if ($numPathParts > 1) {
            self::produceErrorResponse(
                StatusCode::NOT_FOUND,
                "Only one path parameter expected for the `/missals` path but $numPathParts path parameters were found"
            );
        } else {
            // the only path parameter we expect is the ID of the Missal
            foreach (self::$missalsIndex->litcal_missals as $idx => $missal) {
                if ($missal->missal_id === self::$requestPathParts[0]) {
                    $missalData = file_get_contents($missal->data_path);
                    if ($missalData) {
                        if (property_exists($missal, 'languages') && self::$params->baseLocale !== null) {
                            if (in_array(self::$params->baseLocale, $missal->languages) && property_exists($missal, 'i18n_path')) {
                                $i18nFile = $missal->i18n_path . self::$params->baseLocale . ".json";
                                $i18nData = file_get_contents($i18nFile);
                                if ($i18nData) {
                                    $i18nObj = json_decode($i18nData);
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        $error = "Error while processing localized data from file {$i18nFile}: " . json_last_error_msg();
                                        self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $error);
                                    }
                                    $missalRows = json_decode($missalData);
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        $error = "Error while processing Missal data from file '{$missalData}': " . json_last_error_msg();
                                        self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, $error);
                                    }
                                    foreach ($missalRows as $idx => $row) {
                                        $key = $row->event_key;
                                        if (property_exists($i18nObj, $key)) {
                                            $missalRows[$idx]->name = $i18nObj->{$key};
                                        }
                                    }
                                    self::produceResponse(json_encode($missalRows));
                                } else {
                                    self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Unable to read localized data from file {$i18nFile}");
                                }
                            }
                        } else {
                            self::produceResponse($missalData);
                        }
                    } else {
                        self::produceErrorResponse(
                            StatusCode::SERVICE_UNAVAILABLE,
                            "Unable to retrieve the Missal for region $missal->region published in the year $missal->year_published"
                        );
                    }
                }
                $missalIDs[] = $missal->missal_id;
            }
            self::produceErrorResponse(
                StatusCode::NOT_FOUND,
                "Could not find a Missal with id '" . self::$requestPathParts[0] . "', available values are: " . implode(', ', $missalIDs)
            );
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
        self::$APICore = new APICore();
        self::$params = new MissalsParams();
        if (count($requestPathParts)) {
            self::$requestPathParts = $requestPathParts;
        }
        self::$missalsIndex = new \stdClass();
        self::$missalsIndex->litcal_missals = [];
        $directories = array_map('basename', glob('data/propriumdesanctis*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            if (file_exists("data/$directory/$directory.json")) {
                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $directory, $matches)) {
                    $missal                 = new \stdClass();
                    $missal->missal_id      = "EDITIO_TYPICA_{$matches[1]}";
                    $missal->region         = "VATICAN";
                    $missal->year_published = intval($matches[1]);
                    $missal->data_path      = "data/$directory/$directory.json";
                    $it = new \DirectoryIterator("glob://data/$directory/i18n/*.json");
                    $languages = [];
                    foreach ($it as $f) {
                        $languages[] = $f->getBasename('.json');
                    }
                    $missal->languages      = $languages;
                    $missal->i18n_path      = "data/$directory/i18n/";
                    $missal->api_path       = API_BASE_PATH . "/missals/EDITIO_TYPICA_{$matches[1]}";
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $directory, $matches)) {
                    $missal                 = new \stdClass();
                    $missal->missal_id      = "{$matches[1]}_{$matches[2]}";
                    $missal->region         = $matches[1];
                    $missal->year_published = intval($matches[2]);
                    $missal->data_path      = "data/$directory/$directory.json";
                    $missal->api_path       = API_BASE_PATH . "/missals/{$matches[1]}_{$matches[2]}";
                }
                self::$missalsIndex->litcal_missals[] = $missal;
                self::$params->setMissalRegion($missal->region);
                self::$params->setMissalYear($missal->year_published);
            }
        }
        // we only set the request parameters after we have collected the MissalRegions and MissalYears
        self::$params->setData(self::initRequestParams());
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
            if (null !== self::$params->Locale) {
                header("X-Litcal-Missals-Locale: " . self::$params->Locale, false);
            } else {
                header("X-Litcal-Missals-Locale: none", false);
            }
            if (null === self::$params->Region && null === self::$params->Year) {
                self::produceResponse(json_encode(self::$missalsIndex));
            } else {
                $filteredResults = self::$missalsIndex;
                if (null !== self::$params->Region) {
                    $filteredResults->litcal_missals = array_filter(
                        $filteredResults->litcal_missals,
                        fn ($missal) => $missal->region === self::$params->Region
                    );
                    header("X-Litcal-Missals-Region: " . self::$params->Region, false);
                }
                if (null !== self::$params->Year) {
                    $filteredResults->litcal_missals = array_filter(
                        $filteredResults->litcal_missals,
                        fn ($missal) => $missal->year_published === self::$params->Year
                    );
                    header("X-Litcal-Missals-Year: " . self::$params->Year, false);
                }
                self::produceResponse(json_encode($filteredResults));
            }
        }
        self::handlePathParams();
    }
}
