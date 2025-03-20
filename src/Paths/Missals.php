<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Params\MissalsParams;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\RomanMissal;

class Missals
{
    public static Core $Core;
    public static MissalsParams $params;
    public static object $missalsIndex;
    private static array $requestPathParts = [];

    /**
     * Initialize the payload from the request body.
     *
     * If the request body is a JSON or YAML encoded object, it will attempt to
     * retrieve the locale, region, and year from the object.
     * Else if the request body is Form encoded and the method is POST, it will attempt to
     * retrieve the locale, region, and year from the form encoded values.
     * If however the method is PUT or PATCH, the request body must be a JSON or YAML encoded object,
     * otherwise it will produce a 404 Bad Request error.
     *
     * If the object or form does not have a 'locale' property, it will attempt to
     * retrieve the locale from the 'Accept-Language' header of the request.
     * If it does not find a valid locale, it will default to 'la' (Latin).
     *
     * If the object or form does not have a 'region' property, it will not set the
     * 'REGION' key in the returned array.
     *
     * If the object or form does not have a 'year' property, it will not set the
     * 'YEAR' key in the returned array.
     *
     * If the request did not provide a 'locale' parameter and there is no
     * 'Accept-Language' header, it will default to 'la' (Latin).
     *
     * If the request did not provide a 'locale' parameter and the 'Accept-Language'
     * header is not in the list of supported locales, it will default to 'la' (Latin).
     *
     * @return ?object the initialized payload, or null if the request body was not a JSON or YAML encoded object or a Form encoded object
     */
    private static function initPayloadFromRequestBody(): ?object
    {
        $payload = null;
        $required = in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH]);
        switch (self::$Core->getRequestContentType()) {
            case RequestContentType::JSON:
                $payload = self::$Core->readJsonBody($required);
                break;
            case RequestContentType::YAML:
                $payload = self::$Core->readYamlBody($required);
                break;
            case RequestContentType::FORMDATA:
                $payload = (object)$_POST;
                break;
            default:
                if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
                    // the payload MUST be in the body of the request, either JSON encoded or YAML encoded
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, "Expected payload in body of request, either JSON or YAML encoded");
                }
        }
        return $payload;
    }

    /**
     * Handles the GET and POST request payload for the Missals endpoint.
     *
     * If the request body is a JSON or YAML encoded object, it will attempt to
     * retrieve the locale, region, and year from the object.
     *
     * If the object does not have a 'locale' property, it will attempt to
     * retrieve the locale from the 'Accept-Language' header of the request.
     * If it does not find a valid locale, it will default to 'la' (Latin).
     *
     * If the object does not have a 'region' property, it will not set the
     * 'region' key in the returned array.
     *
     * If the object does not have a 'year' property, it will not set the
     * 'year' key in the returned array.
     *
     * @param ?object $payload the JSON or YAML encoded object or null if the
     *                          request body was not a JSON or YAML encoded object
     * @return array the initialized request parameters
     */
    private static function initGetPostParams(?object $payload): array
    {
        $data = [];
        if ($payload !== null && property_exists($payload, 'locale')) {
            $data["locale"] = $payload->locale;
        } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $locale = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if ($locale && LitLocale::isValid($locale)) {
                $data["locale"] = $locale;
            } else {
                $data["locale"] = LitLocale::LATIN;
            }
        }
        if (property_exists($payload, 'region')) {
            $data["region"] = $payload->region;
        }
        if (property_exists($payload, 'year')) {
            $data["year"] = $payload->year;
        }
        if (property_exists($payload, 'include_empty')) {
            $data["include_empty"] = $payload->include_empty;
        }
        return $data;
    }


    /**
     * Initialize the request parameters for the Missals endpoint.
     *
     * When the request method is POST, PUT or PATCH, the request body is expected to be a JSON or YAML encoded object,
     * or in the case of a POST request method, possible a Form encoded object.
     * The object (or form data) may contain the following properties:
     * - locale: a string indicating the locale of the calendar
     * - region: a string indicating the region of the calendar
     * - year: an integer indicating the year for which the calendar should be calculated
     *
     * When the request method is GET, the query parameters are expected to have the same structure as the request body in the previous case.
     *
     * @return array the initialized request parameters
     */
    private static function initRequestParams(): array
    {
        $data = [];
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
            $payload = self::initPayloadFromRequestBody();
            if (self::$Core->getRequestMethod() === RequestMethod::POST) {
                $data = self::initGetPostParams($payload);
            } else {
                $data["PAYLOAD"] = $payload;
            }
        } elseif (self::$Core->getRequestMethod() === RequestMethod::GET) {
            $data = self::initGetPostParams((object)$_GET);
        }
        return $data;
    }

    /**
     * Handles the path parameter(s) for the /missals path, if there are any.
     *
     * If there is more than one path parameter, it will produce an error response with a status code of 404.
     * If there is one path parameter, it will attempt to retrieve the Missal with the given ID, and if found:
     * - if the Missal has localized data, it will attempt to retrieve the localized data for the base locale,
     *   and if found, it will return the localized data.
     * - if the Missal does not have localized data, or if the localized data for the base locale was not found,
     *   it will return the Missal data.
     * If the Missal was not found, it will produce an error response with a status code of 404, listing the available
     * Missal IDs.
     *
     * @return void
     */
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
                    $missalData = file_get_contents(RomanMissal::$jsonFiles[$missal->missal_id]);
                    if ($missalData) {
                        if (property_exists($missal, 'locales') && self::$params->baseLocale !== null) {
                            if (in_array(self::$params->baseLocale, $missal->locales)) {
                                $i18nFile = RomanMissal::$i18nPath[$missal->missal_id] . self::$params->baseLocale . ".json";
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
                $statusMessage = StatusCode::toString($statusCode);
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
     * Outputs the response for the /missals endpoint.
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
     * Handles the request for the /missals endpoint.
     *
     * If the request method is GET, it will validate the Accept header and set the
     * response content type header.
     * If the request method is POST, PUT, or PATCH, it will validate the request body
     * and set the response content type header.
     * If there are no path parameters, it will return all the Missal metadata.
     * If there is one path parameter, it will attempt to retrieve the Missal with the
     * given ID, and if found:
     * - if the Missal has localized data, it will attempt to retrieve the localized
     *   data for the base locale, and if found, it will return the localized data.
     * - if the Missal does not have localized data, or if the localized data for the
     *   base locale was not found, it will return the Missal data.
     * If the Missal was not found, it will produce an error response with a status code
     * of 404, listing the available Missal IDs.
     */
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
                    $filteredResults->litcal_missals = array_values(array_filter(
                        $filteredResults->litcal_missals,
                        fn ($missal) => $missal->region === self::$params->Region
                    ));
                    header("X-Litcal-Missals-Region: " . self::$params->Region, false);
                }
                if (null !== self::$params->Year) {
                    $filteredResults->litcal_missals = array_values(array_filter(
                        $filteredResults->litcal_missals,
                        fn ($missal) => $missal->year_published === self::$params->Year
                    ));
                    header("X-Litcal-Missals-Year: " . self::$params->Year, false);
                }
                self::produceResponse(json_encode($filteredResults));
            }
        }
        self::handlePathParams();
    }

    /**
     * Initializes the Missals class.
     *
     * @param array $requestPathParts the path parameters from the request
     *
     * This method will:
     * - Create an instance of the Core class
     * - Create an instance of the MissalsParams class
     * - If the $requestPathParts argument is not empty, it will set the request path parts
     * - It will create an empty stdClass object to store the Missal metadata
     * - It will loop over the directories in the 'data' directory and if the directory contains
     *   a file with the same name as the directory and the extension '.json', it will create a
     *   stdClass object with the properties 'missal_id', 'name', 'region', 'year_published',
     *   'locales', and 'api_path', and add it to the
     *   self::$missalsIndex->litcal_missals array.
     * - Finally, it will set the request parameters using the initRequestParams method.
     *
     * @see \LiturgicalCalendar\Api\Paths\Missals::initRequestParams()
     */
    public static function init(array $requestPathParts = [])
    {
        self::$Core = new Core();
        self::$params = new MissalsParams();
        if (count($requestPathParts)) {
            self::$requestPathParts = $requestPathParts;
        }

        self::$missalsIndex = new \stdClass();
        self::$missalsIndex->litcal_missals = [];

        if (false === is_readable('jsondata/sourcedata/missals')) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'Unable to read the jsondata/sourcedata/missals directory');
        }
        $missalFolderPaths = glob('jsondata/sourcedata/missals/propriumdesanctis*', GLOB_ONLYDIR);
        if (false === $missalFolderPaths) {
            self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'Unable to read the jsondata/sourcedata/missals directory contents');
        }
        if (count($missalFolderPaths) === 0) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, 'No Missals found');
        }

        $missalFolderNames = array_map('basename', $missalFolderPaths);
        foreach ($missalFolderNames as $missalFolderName) {
            if (file_exists("jsondata/sourcedata/missals/$missalFolderName/$missalFolderName.json")) {
                $missal = new \stdClass();
                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal->missal_id      = "EDITIO_TYPICA_{$matches[1]}";
                    $missal->region         = "VA";
                    if (is_readable("jsondata/sourcedata/missals/$missalFolderName/i18n")) {
                        $it = new \DirectoryIterator("glob://jsondata/sourcedata/missals/$missalFolderName/i18n/*.json");
                        $locales = [];
                        foreach ($it as $f) {
                            $locales[] = $f->getBasename('.json');
                        }
                        $missal->locales      = $locales;
                    } else {
                        $missal->locales      = null;
                    }
                    //$missal->year_published = intval($matches[1]);
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal->missal_id      = "{$matches[1]}_{$matches[2]}";
                    $missal->region         = $matches[1];
                    //$missal->year_published = intval($matches[2]);
                }
                $missal->name           = RomanMissal::getName($missal->missal_id);
                $missal->year_limits    = RomanMissal::$yearLimits[$missal->missal_id];
                $missal->year_published = RomanMissal::$yearLimits[$missal->missal_id][ "since_year" ];
                $missal->api_path       = API_BASE_PATH . "/missals/$missal->missal_id";
                self::$missalsIndex->litcal_missals[] = $missal;
                self::$params->addMissalRegion($missal->region);
                self::$params->addMissalYear($missal->year_published);
            }
        }
        // we only set the request parameters after we have collected the MissalRegions and MissalYears
        self::$params->setData(self::initRequestParams());

        // If an explicit request is made to include all Missals defined in the RomanMissal enum,
        // even if there is no data for them in the jsondata/sourcedata/missals directory,
        // we add them to the response.
        if (self::$params->IncludeEmpty) {
            $allMissals = RomanMissal::produceMetadata(true);
            foreach ($allMissals as $missal) {
                if (null === array_find(self::$missalsIndex->litcal_missals, function ($m) use ($missal) {
                    return $m->missal_id === $missal->missal_id;
                })) {
                    //$missal->api_path = false;
                    self::$missalsIndex->litcal_missals[] = $missal;
                    self::$params->addMissalRegion($missal->region);
                    self::$params->addMissalYear($missal->year_published);
                }
            }
        }
    }
}
