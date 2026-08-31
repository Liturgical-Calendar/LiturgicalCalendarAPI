<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Params\MissalsParams;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MissalsHandler extends AbstractHandler
{
    /**
     * The sub-resource segment of `GET /missals/{missal_id}/i18n`.
     *
     * `GET /missals/{missal_id}` folds exactly one negotiated locale's name into each sanctorale
     * row, which is the right default for a calendar consumer but lossy for an editor: it carries
     * one name per row, no note of which locale it came from, and no way to see that a key is
     * translated in twelve locales and left empty in two (issue #941).
     *
     * The aggregated view is served here as an opt-in sidecar rather than as a `?locales=all`
     * variant of the row response, for two reasons. It leaves the row shape untouched, so no
     * existing consumer has to learn a second spelling of `name`; and it is the natural target for
     * the corresponding `PUT`/`PATCH` (issue #943), which writes locale files, not rows.
     */
    private const string I18N_SUBRESOURCE = 'i18n';

    public MissalsParams $params;
    public static ?MissalMetadataMap $missalsIndex = null;

    /** @var string[] */
    public static array $availableLangs = [];

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
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
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

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

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is both read and write:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters
        //   - for PUT and PATCH requests we will have a payload in the request body
        //   - for DELETE requests we will have neither payload nor request parameters, only path parameters

        /** @var array{locale?:string,region?:string,year?:int,include_empty?:bool}|array{payload:\stdClass} $params */
        $params = [];

        // Second of all, we check if an Accept-Language header was set in the request
        $locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        if ($method === RequestMethod::GET) {
            /** @var array{locale?:string,region?:string,year?:int,include_empty?:bool}|array{payload:\stdClass} $params */
            $params = array_merge($params, $this->getScalarQueryParams($request));
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array{locale?:string,region?:string,year?:int,include_empty?:bool}|array{payload:\stdClass} $params */
                $params = array_merge($params, $parsedBodyParams);
            }
        } elseif ($method === RequestMethod::PUT || $method === RequestMethod::PATCH) {
            // Pre-validate for methods with bodies/side effects to avoid parsing on disallowed paths
            $this->validateRequestMethod($request);
            $params['payload'] = $this->parseBodyPayload($request, false);
            if (false === ( $params['payload'] instanceof \stdClass )) {
                throw new ValidationException('Invalid payload');
            }
        }

        if (null === self::$missalsIndex) {
            self::$missalsIndex = new MissalMetadataMap();
        }

        try {
            if (self::$missalsIndex->isEmpty()) {
                self::$missalsIndex->buildIndex();
            }
        } catch (\Throwable $e) {
            // Surface a 503 instead of a generic 500 on index build failures
            throw new ServiceUnavailableException('Missals index temporarily unavailable', $e);
        }

        $this->params = new MissalsParams($params);

        // For PUT and PATCH requests we already validated the request method
        // before parsing the body, for all other request methods we validate it here
        if ($method !== RequestMethod::PUT && $method !== RequestMethod::PATCH) {
            $this->validateRequestMethod($request);
        }

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
        if (null === MissalsHandler::$missalsIndex) {
            throw new ServiceUnavailableException('Missals index temporarily unavailable');
        }

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
                    $response = $response->withHeader('X-Litcal-Missals-Year', $this->params->Year . '');
                }

                // if filters are set, the results are internally filtered by the jsonSerializer
                // of the MissalMetadataMap instance
                return $this->encodeResponseBody($response, MissalsHandler::$missalsIndex);
            }
        } elseif ($numPathParams === 2 && $this->requestPathParams[1] === self::I18N_SUBRESOURCE) {
            return $this->handleGetI18nRequest($response, $this->requestPathParams[0]);
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


    /**
     * Serves `GET /missals/{missal_id}/i18n`: every locale's sanctorale names for the missal,
     * keyed by locale then by `event_key` (issue #941).
     *
     * The `i18n` map is the locale files verbatim, which is what makes the two states the corpus
     * actually distinguishes survive the round trip: a key **absent** from a locale's map has no
     * entry in that locale's file at all, while a key mapped to the **empty string** has an entry
     * whose translation has not been written yet. The empty string is an established convention
     * here rather than an accident — `propriumdesanctis_2008/i18n/de.json` carries all three of
     * its keys as `""`, and `hu.json` carries two of the three that way.
     *
     * Because reading that distinction off a fourteen-locale map is exactly the work the issue
     * says clients should not have to do, `coverage` states it directly, per `event_key`: which
     * locales translate it, which carry it empty, and which omit it entirely.
     */
    private function handleGetI18nRequest(ResponseInterface $response, string $missalId): ResponseInterface
    {
        if (null === MissalsHandler::$missalsIndex) {
            throw new ServiceUnavailableException('Missals index temporarily unavailable');
        }

        if (false === MissalsHandler::$missalsIndex->hasMissal($missalId)) {
            $description = "Could not find a Missal with id '" . $missalId . "', available values are: " . implode(', ', MissalsHandler::$missalsIndex->getMissalIDs());
            throw new NotFoundException($description);
        }

        $i18nPath = RomanMissal::getSanctoraleI18nFilePath($missalId);
        if (false === $i18nPath) {
            throw new NotFoundException('The Missal with id ' . $missalId . ' has no i18n data');
        }

        $files = glob(rtrim($i18nPath, '/\\') . DIRECTORY_SEPARATOR . '*.json');
        if (false === $files) {
            throw new ServiceUnavailableException('Unable to read the i18n folder for missal ' . $missalId);
        }
        sort($files);

        $missalJsonFile = RomanMissal::getSanctoraleFileName($missalId);
        if (false === $missalJsonFile) {
            throw new NotFoundException('Unable to find missal file for missal ' . $missalId);
        }

        /** @var array<int,\stdClass&object{event_key:string}> $missalRows */
        $missalRows = Utilities::jsonFileToObjectArray($missalJsonFile);
        $eventKeys  = array_values(array_map(static fn (\stdClass $row): string => (string) $row->event_key, $missalRows));

        $locales  = [];
        $i18n     = new \stdClass();
        $coverage = new \stdClass();

        /** @var array<string,array<string,string>> $namesByLocale */
        $namesByLocale = [];

        foreach ($files as $file) {
            $locale    = basename($file, '.json');
            $locales[] = $locale;

            $names = [];
            foreach (Utilities::jsonFileToArray($file) as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $names[$key] = $value;
                }
            }
            $namesByLocale[$locale] = $names;

            $localeNames = new \stdClass();
            foreach ($names as $key => $value) {
                $localeNames->{$key} = $value;
            }
            $i18n->{$locale} = $localeNames;
        }

        foreach ($eventKeys as $eventKey) {
            $translated = [];
            $empty      = [];
            $missing    = [];
            foreach ($locales as $locale) {
                if (false === array_key_exists($eventKey, $namesByLocale[$locale])) {
                    $missing[] = $locale;
                } elseif ($namesByLocale[$locale][$eventKey] === '') {
                    $empty[] = $locale;
                } else {
                    $translated[] = $locale;
                }
            }
            $eventCoverage             = new \stdClass();
            $eventCoverage->translated = $translated;
            $eventCoverage->empty      = $empty;
            $eventCoverage->missing    = $missing;
            $coverage->{$eventKey}     = $eventCoverage;
        }

        $out             = new \stdClass();
        $out->missal_id  = $missalId;
        $out->locales    = $locales;
        $out->event_keys = $eventKeys;
        $out->i18n       = $i18n;
        $out->coverage   = $coverage;

        return $this->encodeResponseBody($response, $out);
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
