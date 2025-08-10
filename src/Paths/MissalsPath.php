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
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Utilities;

final class MissalsPath
{
    public static Core $Core;
    public static MissalsParams $params;
    public static MissalMetadataMap $missalsIndex;
    /** @var string[] */
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
     * @return ?\stdClass the initialized payload, or null if the request body was not a JSON or YAML encoded object or a Form encoded object
     */
    private static function initPayloadFromRequestBody(): ?\stdClass
    {
        $payload  = null;
        $required = in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH]);
        switch (self::$Core->getRequestContentType()) {
            case RequestContentType::JSON:
                $payload = self::$Core->readJsonBody($required);
                break;
            case RequestContentType::YAML:
                $payload = self::$Core->readYamlBody($required);
                break;
            case RequestContentType::FORMDATA:
                $payload = (object) $_POST;
                break;
            default:
                if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
                    // the payload MUST be in the body of the request, either JSON encoded or YAML encoded
                    self::produceErrorResponse(StatusCode::BAD_REQUEST, 'Expected payload in body of request, either JSON or YAML encoded');
                }
        }
        if (is_array($payload)) {
            throw new \InvalidArgumentException('Payload should have been cast to a stdClass object, instead we found an array');
        }
        if ($required &&  ( $payload === null || count(array_keys(get_object_vars($payload))) === 0 )) {
            throw new \InvalidArgumentException('Payload does not contain any data');
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
     * @param ?\stdClass $payload the JSON or YAML encoded object or null if the
     *                          request body was not a JSON or YAML encoded object
     * @return array{
     *      locale?: string,
     *      region?: string,
     *      year?: int,
     *      include_empty?: bool
     * } The initialized request parameters
     */
    private static function initGetPostParams(?\stdClass $payload): array
    {
        $params = [];
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $locale = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if ($locale && LitLocale::isValid($locale)) {
                $params['locale'] = $locale;
            } else {
                $params['locale'] = LitLocale::LATIN;
            }
        }
        if ($payload !== null) {
            if (isset($payload->locale) && is_string($payload->locale) && LitLocale::isValid($payload->locale)) {
                $params['locale'] = $payload->locale;
            }
            if (isset($payload->region) && is_string($payload->region)) {
                $params['region'] = $payload->region;
            }
            if (isset($payload->year) && is_int($payload->year)) {
                $params['year'] = $payload->year;
            }
            if (isset($payload->include_empty) && is_bool($payload->include_empty)) {
                $params['include_empty'] = $payload->include_empty;
            }
        }
        /** @var array{locale?:string,region?:string,year?:int,include_empty?:bool} $params */
        return $params;
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
     * @return array{locale?:string,region?:string,year?:int,include_empty?:bool}|array{PAYLOAD:\stdClass} The initialized request parameters
     */
    private static function initRequestParams(): array
    {
        $params = [];
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
            $payload = self::initPayloadFromRequestBody();
            if (self::$Core->getRequestMethod() === RequestMethod::POST) {
                $params = self::initGetPostParams($payload);
            } else {
                /** @var \stdClass $payload */
                $params['PAYLOAD'] = $payload;
            }
        } elseif (self::$Core->getRequestMethod() === RequestMethod::GET) {
            $params = self::initGetPostParams((object) $_GET);
        }
        return $params;
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
     * @return never
     */
    private static function handlePathParams(): never
    {
        $numPathParts = count(self::$requestPathParts);
        if ($numPathParts > 1) {
            self::produceErrorResponse(
                StatusCode::NOT_FOUND,
                "Only one path parameter expected for the `/missals` path but $numPathParts path parameters were found"
            );
        } else {
            // the only path parameter we expect is the ID of the Missal
            $missalId = self::$requestPathParts[0];
            if (self::$missalsIndex->hasMissal($missalId)) {
                $missalMetadata = self::$missalsIndex->getMissalMetadata($missalId);
                if (null === $missalMetadata) {
                    throw new \RuntimeException('Unable to find missal metadata for missal ' . $missalId);
                }

                $missalJsonFile = RomanMissal::$jsonFiles[$missalId];
                if (false === $missalJsonFile) {
                    throw new \RuntimeException("Unable to retrieve metadata for missal $missalId. Now the disciples had forgotten to bring any bread. - Mark 8:14");
                }

                $locale     = RomanMissal::isLatinMissal($missalId)
                            ? ( in_array(self::$params->baseLocale, $missalMetadata->locales) ? self::$params->baseLocale : LitLocale::LATIN_PRIMARY_LANGUAGE )
                            : ( in_array(self::$params->Locale, $missalMetadata->locales) ? self::$params->Locale : $missalMetadata->locales[0] );
                $i18nFile   = RomanMissal::$i18nPath[$missalId] . $locale . '.json';
                $i18nObj    = Utilities::jsonFileToObject($i18nFile);
                $missalRows = Utilities::jsonFileToObjectArray($missalJsonFile);

                /** @var array<int,\stdClass&object{month:int,day:int,event_key:string,grade:int,common:string[],calendar:string,color:string[],grade_display?:?string}> $missalRows */
                foreach ($missalRows as $idx => $row) {
                    $key = $row->event_key;
                    if (property_exists($i18nObj, $key)) {
                        $missalRows[$idx]->name = $i18nObj->{$key};
                    }
                }

                $jsonEncodedResponse = json_encode($missalRows);
                if (json_last_error() !== JSON_ERROR_NONE || false === $jsonEncodedResponse) {
                    $error = "Error while processing localized data from file {$i18nFile}: " . json_last_error_msg();
                    throw new \Exception($error);
                }
                self::produceResponse($jsonEncodedResponse);
            }
            self::produceErrorResponse(
                StatusCode::NOT_FOUND,
                "Could not find a Missal with id '" . $missalId . "', available values are: " . implode(', ', self::$missalsIndex->getMissalIDs())
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
     * @return never
     */
    public static function produceErrorResponse(int $statusCode, string $description): never
    {
        $serverProtocol = isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1 ';
        header($serverProtocol . StatusCode::toString($statusCode), true, $statusCode);
        $message         = new \stdClass();
        $message->status = 'ERROR';
        $statusMessage   = '';
        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::PUT:
                $statusMessage = 'Resource not Created';
                break;
            case RequestMethod::PATCH:
                $statusMessage = 'Resource not Updated';
                break;
            case RequestMethod::DELETE:
                $statusMessage = 'Resource not Deleted';
                break;
            default:
                $statusMessage = StatusCode::toString($statusCode);
        }
        $message->response    = $statusCode === 404 ? 'Resource not Found' : $statusMessage;
        $message->description = $description;
        $response             = json_encode($message);
        if ($response === false) {
            throw new \Exception('Could not encode error response as JSON: ' . json_last_error_msg());
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
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
     * @return never
     */
    private static function produceResponse(string $jsonEncodedResponse): never
    {
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH])) {
            $serverProtocol = isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
            header($serverProtocol . ' 201 Created', true, 201);
        }
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($jsonEncodedResponse, true, 512, JSON_THROW_ON_ERROR);
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
     *
     * @return never
     */
    public static function handleRequest(): never
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
                header('X-Litcal-Missals-Locale: ' . self::$params->Locale, false);
            } else {
                header('X-Litcal-Missals-Locale: none', false);
            }

            if (null === self::$params->Region && null === self::$params->Year) {
                // if not filters are set, just encode the whole missals index as is
                $jsonEncodedResponse = json_encode(self::$missalsIndex);
                if (false === $jsonEncodedResponse) {
                    throw new \Exception('Could not encode missals index as JSON: ' . json_last_error_msg());
                }
                self::produceResponse($jsonEncodedResponse);
            } else {
                if (null !== self::$params->Region) {
                    self::$missalsIndex->setRegionFilter(self::$params->Region);
                    header('X-Litcal-Missals-Region: ' . self::$params->Region, false);
                }

                if (null !== self::$params->Year) {
                    self::$missalsIndex->setYearFilter(self::$params->Year);
                    header('X-Litcal-Missals-Year: ' . self::$params->Year, false);
                }

                // if filters are set, the results are internally filtered by the jsonSerializer
                // of the MissalMetadataMap instance
                $jsonEncodedResponse = json_encode(self::$missalsIndex);
                if (false === $jsonEncodedResponse) {
                    throw new \Exception('Could not encode missals index as JSON: ' . json_last_error_msg());
                }
                self::produceResponse($jsonEncodedResponse);
            }
        }
        self::handlePathParams();
    }

    /**
     * Initializes the Missals class.
     *
     * @param string[] $requestPathParts the path parameters from the request
     *
     * This method will:
     * - Create an instance of the Core class
     * - Create an instance of the MissalsParams class
     * - If the $requestPathParts argument is not empty, it will set the request path parts
     * - It will instantiate an instance of the MissalIndex class to store the Missal metadata
     * - It will loop over the directories in the 'jsondata/sourcedata/missals' directory,
     *   and if the directory contains a file with the same name as the directory and the extension '.json',
     *   it will add a Missal metadata object to the MissalIndex with the properties:
     *   'missal_id', 'name', 'region', 'year_published', 'locales', and 'api_path',
     *   and add it to the self::$missalsIndex collection.
     * - Finally, it will set the request parameters using the {@see \LiturgicalCalendar\Api\Paths\MissalsPath::initRequestParams()} method.
     *
     */
    public static function init(array $requestPathParts = []): void
    {
        self::$Core   = new Core();
        self::$params = new MissalsParams();
        if (count($requestPathParts)) {
            self::$requestPathParts = $requestPathParts;
        }

        self::$missalsIndex = new MissalMetadataMap();

        if (false === is_readable(JsonData::MISSALS_FOLDER)) {
            throw new \RuntimeException('Unable to read the ' . JsonData::MISSALS_FOLDER . ' directory');
        }

        $missalFolderPaths = glob(JsonData::MISSALS_FOLDER . '/propriumdesanctis*', GLOB_ONLYDIR);
        if (false === $missalFolderPaths) {
            throw new \RuntimeException('Unable to read the ' . JsonData::MISSALS_FOLDER . ' directory contents');
        }

        if (count($missalFolderPaths) === 0) {
            self::produceErrorResponse(StatusCode::NOT_FOUND, 'No Missals found');
        }

        $missalFolderNames = array_map('basename', $missalFolderPaths);
        foreach ($missalFolderNames as $missalFolderName) {
            if (file_exists(JsonData::MISSALS_FOLDER . "/$missalFolderName/$missalFolderName.json")) {
                $missal = [];

                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal['missal_id'] = "EDITIO_TYPICA_{$matches[1]}";
                    $missal['region']    = 'VA';
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal['missal_id'] = "{$matches[1]}_{$matches[2]}";
                    $missal['region']    = $matches[1];
                } else {
                    throw new \RuntimeException('Unable to parse missal folder name: ' . $missalFolderName);
                }

                if (is_readable(JsonData::MISSALS_FOLDER . "/$missalFolderName/i18n")) {
                    $iterator = new \DirectoryIterator('glob://' . JsonData::MISSALS_FOLDER . "/$missalFolderName/i18n/*.json");
                    $locales  = [];
                    foreach ($iterator as $f) {
                        $locales[] = $f->getBasename('.json');
                    }
                    $missal['locales'] = $locales;
                } else {
                    $missal['locales'] = null;
                }

                $missal['name']           = RomanMissal::getName($missal['missal_id']);
                $missal['year_limits']    = RomanMissal::$yearLimits[$missal['missal_id']];
                $missal['year_published'] = RomanMissal::$yearLimits[$missal['missal_id']]['since_year'];
                $missal['api_path']       = API_BASE_PATH . "/missals/{$missal['missal_id']}";
                self::$missalsIndex->addMissal(MissalMetadata::fromArray($missal));
                self::$params->addMissalRegion($missal['region']);
                self::$params->addMissalYear($missal['year_published']);
            }
        }
        // we only set the request parameters after we have collected the MissalRegions and MissalYears
        self::$params->setParams(self::initRequestParams());

        // If an explicit request is made to include all Missals defined in the RomanMissal enum,
        // even if there is no data for them in the JsonData::MISSALS_FOLDER directory,
        // we add them to the response.
        if (self::$params->IncludeEmpty) {
            /** @var array<string,MissalMetadata> */
            $allMissals = RomanMissal::produceMetadata(true);
            foreach ($allMissals as $missal) {
                if (false === self::$missalsIndex->hasMissal($missal->missal_id)) {
                    self::$missalsIndex->addMissal($missal);
                    self::$params->addMissalRegion($missal->region);
                    self::$params->addMissalYear($missal->year_published);
                }
            }
        }
    }
}
