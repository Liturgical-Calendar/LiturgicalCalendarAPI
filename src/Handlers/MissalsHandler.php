<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Params\MissalsParams;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MissalsHandler extends AbstractHandler
{
    public MissalsParams $params;
    public static MissalMetadataMap $missalsIndex;
    /** @var string[] */ public static array $availableLangs = [];
    /** @var string[] */ public static array $MissalRegions  = [];
    /** @var int[]    */ public static array $MissalYears    = [];

    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);

        self::$missalsIndex = new MissalMetadataMap();
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
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // We instantiate a Response object with minimum state
        $response = static::createResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        // For all other request methods, validate that they are supported by the endpoint
        $this->validateRequestMethod($request);

        // First of all we validate that the Content-Type requested in the Accept header is supported by the endpoint:
        //   if set we negotiate the best Content-Type, if not set we default to the first supported by the current handler
        switch ($method) {
            case RequestMethod::GET:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
                break;
            default:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::INTERMEDIATE);
        }

        $response = $response->withHeader('Content-Type', $mime);

        self::buildMissalsIndex();

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is both read and write:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters
        //   - for PUT and PATCH requests we will have a payload in the request body
        //   - for DELETE requests we will have neither payload nor request parameters, only path parameters

        /** @var array{locale?:string,region?:string,year?:int,include_empty?:bool}|array{PAYLOAD:\stdClass} $params */
        $params = [];

        // Second of all, we check if an Accept-Language header was set in the request
        $acceptLanguageHeader = $request->getHeaderLine('Accept-Language');
        $locale               = '' !== $acceptLanguageHeader
            ? \Locale::acceptFromHttp($acceptLanguageHeader)
            : LitLocale::LATIN;
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        if ($method === RequestMethod::GET) {
            $params = array_merge($params, $this->getScalarQueryParams($request));
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array<string,scalar|null> $params */
                $params = array_merge($params, $parsedBodyParams);
            }
        } elseif ($method === RequestMethod::PUT || $method === RequestMethod::PATCH) {
            $params['payload'] = $this->parseBodyPayload($request);
        }

        $this->params = new MissalsParams($params);

        switch ($method) {
            case RequestMethod::GET:
                // no break (intentional fallthrough)
            case RequestMethod::POST:
                return $this->handleGetRequest($response);
            case RequestMethod::PUT:
                return $this->handlePutRequest($response);
            case RequestMethod::PATCH:
                return $this->handlePatchRequest($response);
            case RequestMethod::DELETE:
                return $this->handleDeleteRequest($response);
            default:
                throw new MethodNotAllowedException();
        }
    }

    private static function buildMissalsIndex(): void
    {
        if (false === is_readable(JsonData::MISSALS_FOLDER->path())) {
            $description = 'Unable to read the ' . JsonData::MISSALS_FOLDER->path() . ' directory';
            throw new ServiceUnavailableException($description);
        }

        $missalFolderPaths = glob(JsonData::MISSALS_FOLDER->path() . '/propriumdesanctis*', GLOB_ONLYDIR);
        if (false === $missalFolderPaths) {
            $description = 'Unable to read the ' . JsonData::MISSALS_FOLDER->path() . ' directory contents';
            throw new ServiceUnavailableException($description);
        }

        if (count($missalFolderPaths) === 0) {
            $description = 'No Missals found';
            throw new NotFoundException($description);
        }

        $missalFolderNames = array_map('basename', $missalFolderPaths);
        foreach ($missalFolderNames as $missalFolderName) {
            if (file_exists(JsonData::MISSALS_FOLDER->path() . "/$missalFolderName/$missalFolderName.json")) {
                $missal = [];

                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal['missal_id'] = "EDITIO_TYPICA_{$matches[1]}";
                    $missal['region']    = 'VA';
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal['missal_id'] = "{$matches[1]}_{$matches[2]}";
                    $missal['region']    = $matches[1];
                } else {
                    $description = 'Unable to parse missal folder name: ' . $missalFolderName;
                    throw new ServiceUnavailableException($description);
                }

                if (is_readable(JsonData::MISSALS_FOLDER->path() . "/$missalFolderName/i18n")) {
                    $iterator = new \DirectoryIterator('glob://' . JsonData::MISSALS_FOLDER->path() . "/$missalFolderName/i18n/*.json");
                    $locales  = [];
                    foreach ($iterator as $f) {
                        $locales[] = $f->getBasename('.json');
                    }
                    $missal['locales'] = $locales;
                } else {
                    $missal['locales'] = null;
                }

                $missal['name']           = RomanMissal::getName($missal['missal_id']);
                $missal['year_limits']    = RomanMissal::getYearLimits($missal['missal_id']);
                $missal['year_published'] = $missal['year_limits']['since_year'];
                $missal['api_path']       = Router::$apiPath . "/missals/{$missal['missal_id']}";
                self::$missalsIndex->addMissal(MissalMetadata::fromArray($missal));
                self::addMissalRegion($missal['region']);
                self::addMissalYear($missal['year_published']);
            }
        }
    }

    /**
     * Adds a region to the list of valid regions for the requested missal.
     */
    public static function addMissalRegion(string $region): void
    {
        if (false === in_array($region, self::$MissalRegions)) {
            self::$MissalRegions[] = $region;
        }
    }

    /**
     * Adds a year to the list of valid years for the requested missal.
     */
    public static function addMissalYear(int $year): void
    {
        if (false === in_array($year, self::$MissalYears)) {
            self::$MissalYears[] = $year;
        }
    }

    /**
     * Sets the list of available languages for the requested missal.
     *
     * @param string[] $langs An array of locales, e.g. ['en_US', 'es_ES', 'pt_PT']
     */
    public static function setAvailableLangs(array $langs): void
    {
        self::$availableLangs = $langs;
    }

    private function handleGetRequest(ResponseInterface $response): ResponseInterface
    {
        $numPathParams = count($this->requestPathParams);

        // If no path parameters are set, we are ready to produce the response
        if ($numPathParams === 0) {
            if (null !== $this->params->Locale) {
                $response = $response->withHeader('X-Litcal-Missals-Locale', $this->params->Locale);
            } else {
                $response = $response->withHeader('X-Litcal-Missals-Locale', 'none');
            }

            if (null === $this->params->Region && null === $this->params->Year) {
                // if no filters are set, just encode the whole missals index as is
                return $this->encodeResponseBody($response, MissalsHandler::$missalsIndex);
            } else {
                if (null !== $this->params->Region) {
                    MissalsHandler::$missalsIndex->setRegionFilter($this->params->Region);
                    $response = $response->withHeader('X-Litcal-Missals-Region', $this->params->Region);
                }

                if (null !== $this->params->Year) {
                    MissalsHandler::$missalsIndex->setYearFilter($this->params->Year);
                    $response = $response->withHeader('X-Litcal-Missals-Year', $this->params->Year);
                }

                // if filters are set, the results are internally filtered by the jsonSerializer
                // of the MissalMetadataMap instance
                return $this->encodeResponseBody($response, MissalsHandler::$missalsIndex);
            }
        } elseif ($numPathParams > 1) {
            throw new ValidationException('Only one path parameter expected for the `/missals` path but ' . $numPathParams . ' path parameters were found');
        } else {
            // the only path parameter we expect is the ID of the Missal
            $missalId = $this->requestPathParams[0];
            if (MissalsHandler::$missalsIndex->hasMissal($missalId)) {
                $missalMetadata = MissalsHandler::$missalsIndex->getMissalMetadata($missalId);
                if (null === $missalMetadata) {
                    throw new NotFoundException('Unable to find missal metadata for missal ' . $missalId);
                }

                $missalJsonFile = RomanMissal::getSanctoraleFileName($missalId);
                if (false === $missalJsonFile) {
                    throw new NotFoundException('Unable to find missal file for missal ' . $missalId);
                }

                $locale     = RomanMissal::isLatinMissal($missalId)
                            ? ( in_array($this->params->baseLocale, $missalMetadata->locales) ? $this->params->baseLocale : LitLocale::LATIN_PRIMARY_LANGUAGE )
                            : ( in_array($this->params->Locale, $missalMetadata->locales) ? $this->params->Locale : $missalMetadata->locales[0] );
                $i18nFile   = RomanMissal::getSanctoraleI18nFilePath($missalId) . $locale . '.json';
                $i18nObj    = Utilities::jsonFileToObject($i18nFile);
                $missalRows = Utilities::jsonFileToObjectArray($missalJsonFile);

                /** @var array<int,\stdClass&object{month:int,day:int,event_key:string,grade:int,common:string[],calendar:string,color:string[],grade_display?:?string}> $missalRows */
                foreach ($missalRows as $idx => $row) {
                    $key = $row->event_key;
                    if (property_exists($i18nObj, $key)) {
                        $missalRows[$idx]->name = $i18nObj->{$key};
                    }
                }

                return $this->encodeResponseBody($response, $missalRows);
            }
            $description = "Could not find a Missal with id '" . $missalId . "', available values are: " . implode(', ', MissalsHandler::$missalsIndex->getMissalIDs());
            throw new NotFoundException($description);
        }
    }


    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        // TODO: implement creation of a Missal resource
        throw new MethodNotAllowedException('Not yet implemented');
    }

    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        // TODO: implement updating of a Missal resource
        throw new MethodNotAllowedException('Not yet implemented');
    }

    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        // TODO: implement deletion of a Missal resource
        throw new MethodNotAllowedException('Not yet implemented');
    }
}
