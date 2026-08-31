<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\Auth\ClientIpTrait;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesFgaClient;
use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Params\MissalsParams;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SanctoraleKeyIdentity;
use LiturgicalCalendar\Api\Utilities;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swaggest\JsonSchema\Schema;

final class MissalsHandler extends AbstractHandler
{
    use ClientIpTrait;
    use ResolvesFgaClient;
    use WritesSourceData;

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

    /**
     * The built index of every rite that has answered a request in this process, keyed by
     * {@see Rite::value}. `$missalsIndex` above stays the per-request handle the rest of this
     * class reads; this map is what makes it rite-aware. Without it, the first rite to build an
     * index in a process would answer for every later request in it — invisible under php-fpm,
     * where a request is usually its own process, but live in the long-running ReactPHP
     * WebSocket server and in the PHPUnit suite.
     *
     * @var array<string, MissalMetadataMap>
     */
    public static array $missalsIndexes = [];

    private ?ServerRequestInterface $request = null;
    private Logger $auditLogger;
    private string $clientIp = 'unknown';
    private readonly Rite $rite;

    /** @var string[] */
    public static array $availableLangs = [];

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)
    {
        parent::__construct($requestPathParams);
        $this->rite = $rite;
        // Sanctorale writes are cookie-authenticated from the browser-based editor, on
        // deployments where the frontend and the API are different origins. A wildcard
        // Access-Control-Allow-Origin makes the browser reject a credentialed request at
        // preflight, so the validated origin is echoed instead — same as /decrees and /data.
        $this->allowCredentials = true;
        $this->auditLogger      = LoggerFactory::create('audit', null, 90, false, true, false);
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
        // Store the request for the private helpers that need it (audit logging).
        $this->request = $request;

        /** @var array<string,mixed> $serverParams */
        $serverParams   = $request->getServerParams();
        $this->clientIp = $this->getClientIp($request, $serverParams);

        // Capture the authenticated identity for change-request authorship, the same way the
        // client IP is captured just above for audit logging.
        $this->captureSubmitter($request);

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

        if (false === isset(self::$missalsIndexes[$this->rite->value])) {
            self::$missalsIndexes[$this->rite->value] = new MissalMetadataMap($this->rite);
        }
        self::$missalsIndex = self::$missalsIndexes[$this->rite->value];

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

                $source         = MissalCatalog::for($this->rite);
                $missalJsonFile = $source->getSanctoraleFileName($missalId);
                if (false === $missalJsonFile) {
                    throw new NotFoundException('Unable to find missal file for missal ' . $missalId);
                }

                $locale     = $source->isEditioTypica($missalId)
                            ? ( in_array($this->params->baseLocale, $missalMetadata->locales) ? $this->params->baseLocale : LitLocale::LATIN_PRIMARY_LANGUAGE )
                            : ( in_array($this->params->Locale, $missalMetadata->locales) ? $this->params->Locale : $missalMetadata->locales[0] );
                $i18nFile   = $source->getSanctoraleI18nFilePath($missalId) . $locale . '.json';
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

        $source   = MissalCatalog::for($this->rite);
        $i18nPath = $source->getSanctoraleI18nFilePath($missalId);
        if (false === $i18nPath) {
            throw new NotFoundException('The Missal with id ' . $missalId . ' has no i18n data');
        }

        $files = glob(rtrim($i18nPath, '/\\') . DIRECTORY_SEPARATOR . '*.json');
        if (false === $files) {
            throw new ServiceUnavailableException('Unable to read the i18n folder for missal ' . $missalId);
        }
        sort($files);

        $missalJsonFile = $source->getSanctoraleFileName($missalId);
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


    /**
     * Where one Missal's sanctorale lives on disk: the structure file, its per-locale name
     * sidecars, and the lectionary folder that carries its readings.
     *
     * The readings tier is resolved here once rather than at each call site, because it is the
     * one thing about a sanctorale write that is not uniform: a national edition has a
     * `lectionary/` folder of its own, an editio typica does not and its readings live in the
     * rite-wide `lectionary/sanctorum/` corpus. Those are the same two tiers
     * `GET /lectionary/{rite}/sanctorale` reports (#942), so a client that read the readings
     * from one tier writes them back to the same one.
     *
     * @return array{
     *     missal_id:string,
     *     structure_file:string,
     *     i18n_folder:string,
     *     readings_folder:string,
     *     readings_tier:string,
     *     calendar:string
     * }
     */
    private function resolveSanctoraleTarget(string $missalId): array
    {
        if (null === MissalsHandler::$missalsIndex) {
            throw new ServiceUnavailableException('Missals index temporarily unavailable');
        }

        $source = MissalCatalog::for($this->rite);

        if (false === $source->isValid($missalId) || false === MissalsHandler::$missalsIndex->hasMissal($missalId)) {
            $description = "Could not find a Missal with id '" . $missalId . "', available values are: "
                . implode(', ', MissalsHandler::$missalsIndex->getMissalIDs());
            throw new NotFoundException($description);
        }

        $structureFile = $source->getSanctoraleFileName($missalId);
        $i18nFolder    = $source->getSanctoraleI18nFilePath($missalId);
        if (false === $structureFile || false === $i18nFolder) {
            throw new NotFoundException(
                'The Missal with id ' . $missalId . ' carries no sanctorale data, so it has no entries to write. '
                . 'Missals with sanctorale data: ' . implode(', ', MissalsHandler::$missalsIndex->getMissalIDs())
            );
        }

        $missalLectionary = $source->getLectionaryFilePath($missalId);

        return [
            'missal_id'       => $missalId,
            'structure_file'  => $structureFile,
            'i18n_folder'     => rtrim($i18nFolder, '/\\'),
            'readings_folder' => is_string($missalLectionary)
                ? rtrim($missalLectionary, '/\\')
                : JsonData::LECTIONARY_SAINTS_FOLDER->path(),
            'readings_tier'   => is_string($missalLectionary) ? 'missal' : 'rite',
            // Every row of an editio typica says `GENERAL ROMAN`; every row of a national
            // edition says that nation's code; every Ambrosian row says `AMBROSIAN`. Derived
            // here the same way RomanMissal::produceMetadata() derives `region`, so a row
            // cannot be filed under a calendar its own Missal never applies to.
            'calendar'        => $source->calendarLabelFor($missalId),
        ];
    }

    /**
     * The two path parameters a sanctorale write addresses.
     *
     * The entry, not the Missal, is the thing being written, so the `event_key` is a path
     * segment: that is what makes it possible to REFUSE a rename rather than silently apply
     * one (see {@see requireValidatedPayload()}), and it is the same shape
     * `PUT /decrees/{decree_id}` uses for the sibling resource. `i18n` can never be mistaken
     * for an `event_key` — every `event_key` starts with an upper-case letter, per
     * `CommonDef.json#/definitions/EventKey` — but the aggregated-translations sub-resource is
     * excluded explicitly here anyway, so the two never depend on that coincidence.
     *
     * @return array{0:string,1:string} [missal_id, event_key]
     */
    private function requireEntryPathParams(): array
    {
        if (count($this->requestPathParams) !== 2 || $this->requestPathParams[1] === self::I18N_SUBRESOURCE) {
            throw new ValidationException(
                'A sanctorale write addresses one ENTRY of a Missal: /missals/{missal_id}/{event_key}. '
                . 'There is deliberately no whole-file replace: an entry is spread across the structure file, '
                . 'one name per locale and one set of readings per locale, and only an entry-scoped write can '
                . 'keep those consistent.'
            );
        }

        return [$this->requestPathParams[0], $this->requestPathParams[1]];
    }

    /**
     * Validate the request body against `LitCalMissalWritePayload.json` and refuse an
     * `event_key` that disagrees with the URL.
     *
     * This is the guard `DecreesHandler` states as FINDING 3 (`DecreesHandler.php:762`),
     * transplanted: *reject event_key changes — orphans i18n/lectionary entries permanently*.
     * A sanctorale entry is keyed on `event_key` in up to four co-located files, and nothing
     * follows a rename from one to the others, so an edit that changed the key would leave the
     * name and the readings behind under the old key, invisible to every reader and to the
     * calendar. `scripts/lint-missals.php` invariant 3 exists to catch exactly that residue.
     * The remedy is the same one decrees offers, and the same one #939 actually used: DELETE
     * the entry (which garbage-collects its sidecars) and re-create it under the new key.
     */
    private function requireValidatedPayload(string $eventKey, bool $isCreate): \stdClass
    {
        $payload = $this->params->Payload;

        try {
            $schema = Schema::import(LitSchema::MISSAL_WRITE->path());
            $schema->in($payload);
        } catch (\Swaggest\JsonSchema\Exception $e) {
            throw new ValidationException('Missal sanctorale write payload failed schema validation: ' . $e->getMessage());
        }

        if (property_exists($payload, 'event_key') && is_string($payload->event_key) && $payload->event_key !== $eventKey) {
            throw new ValidationException(
                "Changing an entry's `event_key` is not allowed (URL: `{$eventKey}`, payload: `{$payload->event_key}`). "
                . 'The key is what ties the structure row to its name in every locale and its readings in every locale; '
                . 'renaming it here would orphan all of them permanently. To change the event_key, DELETE the entry '
                . 'and re-create it with PUT.'
            );
        }

        if (false === $isCreate) {
            $mutable = array_diff(array_keys(get_object_vars($payload)), ['event_key']);
            if ($mutable === []) {
                throw new ValidationException(
                    'A PATCH must carry at least one property to change; a body holding nothing but `event_key` '
                    . 'would be a no-op recorded as a change.'
                );
            }
        }

        return $payload;
    }

    /**
     * The structure rows of one Missal as THIS request should see them.
     *
     * A sanctorale file is an AGGREGATE — every entry of the Missal lives in the one array —
     * so it needs the same read-your-own-unpublished-writes treatment
     * {@see \LiturgicalCalendar\Api\Handlers\DecreesHandler::loadDecreesDatabase()} needs and
     * for the same reason: in queue mode nothing this submitter has proposed has reached disk,
     * so rebuilding from disk would silently drop the entry they added a moment ago. Disk mode
     * never has unpublished content, so this is exactly the read it has always been.
     *
     * @return list<\stdClass>
     */
    private function loadSanctoraleRows(string $file): array
    {
        $unpublished = $this->unpublishedSourceContent($file);

        if (null === $unpublished) {
            return array_values(Utilities::jsonFileToObjectArray($file));
        }

        $decoded = json_decode($unpublished, false);
        if (!is_array($decoded)) {
            throw new ServiceUnavailableException('The queued proposal for ' . basename($file) . ' is not a JSON array; refusing to rebuild it.');
        }

        foreach ($decoded as $row) {
            if (!$row instanceof \stdClass) {
                throw new ServiceUnavailableException('The queued proposal for ' . basename($file) . ' contains a non-object row; refusing to rebuild it.');
            }
        }

        /** @var list<\stdClass> $rows */
        $rows = array_values($decoded);
        return $rows;
    }

    /**
     * A locale sidecar as THIS request should see it — the same aggregate-file reasoning as
     * {@see loadSanctoraleRows()}, since one file holds every entry's value for a locale.
     *
     * FAILS CLOSED on unreadable content, and must: a sidecar is rebuilt by loading it,
     * mutating one key and restaging the whole file, so treating undecodable bytes as "empty"
     * would stage a file with every other entry's translation deleted.
     *
     * @return array<string, mixed>
     */
    private function loadSidecarArray(string $file): array
    {
        $unpublished = $this->unpublishedSourceContent($file);

        if (null !== $unpublished) {
            $decoded = json_decode($unpublished, true);
            if (!is_array($decoded)) {
                throw new ServiceUnavailableException(
                    'The queued proposal for ' . basename($file) . ' is not a JSON object; refusing to rebuild it.'
                );
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        /** @var array<string, mixed> $onDisk */
        $onDisk = file_exists($file) ? Utilities::jsonFileToArray($file) : [];
        return $onDisk;
    }

    /**
     * Every locale sidecar in `$folder` this request should see: the ones on disk, plus any this
     * submitter has queued and not yet published. `glob()` alone cannot see a file that exists
     * only as queued work, and the next submission would drop it.
     *
     * @return list<string> Absolute paths, ascending.
     */
    private function sidecarFiles(string $folder): array
    {
        $folder      = rtrim($folder, '/\\');
        $onDisk      = glob($folder . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $unpublished = array_filter(
            $this->unpublishedSourcePathsUnder($folder),
            static fn (string $path): bool => str_ends_with($path, '.json')
                && !str_contains(substr($path, strlen($folder) + 1), '/')
        );

        $files = array_values(array_unique(array_merge($onDisk, $unpublished)));
        sort($files);

        return $files;
    }

    /**
     * The locale set a sidecar folder admits, derived from the files it already holds.
     *
     * A payload naming a locale with no file is REFUSED rather than creating the file, and that
     * is a deliberate scope line rather than an omission. The locale set a Missal advertises on
     * `/missals` is derived by globbing its `i18n/` folder
     * ({@see RomanMissal::produceMetadata()}), so creating a file there would silently add a
     * supported locale to a Missal — a different act, with consequences for the index, for
     * `CalendarSelect` and for every client that reads `locales`. It also means every path this
     * handler writes is built from a filename that already exists, never from a request string,
     * so no amount of creativity in a locale key can escape the folder.
     *
     * @return array<string, string> locale => absolute file path, ascending by locale
     */
    private function sidecarLocales(string $folder): array
    {
        $locales = [];
        foreach ($this->sidecarFiles($folder) as $file) {
            $locales[basename($file, '.json')] = $file;
        }
        ksort($locales);

        return $locales;
    }

    /**
     * @param array<string, string> $available locale => file path, as returned by {@see sidecarLocales()}
     * @param \stdClass             $supplied  the payload's `i18n` or `readings` map
     */
    private function assertLocalesExist(\stdClass $supplied, array $available, string $what, string $folder): void
    {
        $unknown = array_diff(array_keys(get_object_vars($supplied)), array_keys($available));
        if ($unknown === []) {
            return;
        }

        throw new ValidationException(sprintf(
            'The %s map names locale(s) this Missal has no %s file for: %s. Available: %s. '
            . 'Adding a locale to a Missal changes the locale set the /missals index advertises for it, '
            . 'so it is a separate act and is not performed as a side effect of writing one entry.',
            $what,
            basename($folder),
            implode(', ', $unknown),
            $available === [] ? '(none)' : implode(', ', array_keys($available))
        ));
    }

    /**
     * Give `$eventKey` an entry in EVERY locale file of `$folder`, writing an empty value where
     * the payload supplies none.
     *
     * Writing the key everywhere — empty included — rather than only where there is something to
     * say is the convention the corpus already follows: every one of the 187 keys of
     * `propriumdesanctis_1970` appears in all fourteen of its `i18n` files, 187 of them empty in
     * `de.json`; every US_2011 entry appears in both of its `lectionary` files, all of them empty.
     * That is what keeps a locale file a complete, diffable inventory of what still needs
     * translating, and it is what lets `GET /missals/{missal_id}/i18n` (#941) distinguish
     * "not translated yet" (present, empty) from "no entry" (absent) at all.
     *
     * An entry that already exists is NEVER overwritten with an empty placeholder. The rite-level
     * `lectionary/sanctorum` corpus is shared by every Roman Missal, so creating `StPeterClaver`
     * in US_2011 — a key the 2002 editio typica already declares, with real readings — must add
     * nothing and destroy nothing.
     *
     * Only files whose content actually changes are staged. A PATCH that touches no sidecar
     * therefore proposes no sidecar file, instead of fourteen identical ones for a reviewer to
     * read through.
     *
     * @param array<string, string>     $available  locale => file path
     * @param ?array<string, mixed>     $supplied   the payload's map for this folder, if any
     * @param callable():mixed          $emptyValue the placeholder for a locale with nothing to say
     * @return list<string>             the files staged
     */
    private function fanOutKey(array $available, string $eventKey, ?array $supplied, callable $emptyValue): array
    {
        $staged = [];

        foreach ($available as $locale => $file) {
            $arr    = $this->loadSidecarArray($file);
            $before = $arr;

            if (null !== $supplied && array_key_exists($locale, $supplied)) {
                $arr[$eventKey] = $supplied[$locale];
            } elseif (false === array_key_exists($eventKey, $arr)) {
                $arr[$eventKey] = $emptyValue();
            }

            if ($arr === $before) {
                continue;
            }

            $this->stageFile($file, ChangeOperation::UPDATE, self::encodeSanctoraleFile($arr));
            $staged[] = $file;
        }

        return $staged;
    }

    /**
     * Drop `$eventKey` from every locale file of `$folder`. The file itself survives — only one
     * key leaves it — so the staged operation is an UPDATE carrying the rewritten body, never a
     * DELETE.
     *
     * This is `DecreesHandler::removeKeyFromLocaleFiles()` (`DecreesHandler.php:856`), lifted:
     * without it a deletion leaves the name and the readings behind under a key nothing declares
     * any more, which is precisely the residue `scripts/lint-missals.php` invariant 3 fails on.
     *
     * @return list<string> the files staged
     */
    private function removeKeyFromLocaleFiles(string $eventKey, string $folder): array
    {
        $staged = [];

        foreach ($this->sidecarFiles($folder) as $file) {
            $arr = $this->loadSidecarArray($file);
            if (false === array_key_exists($eventKey, $arr)) {
                continue;
            }
            unset($arr[$eventKey]);
            $this->stageFile($file, ChangeOperation::UPDATE, self::encodeSanctoraleFile($arr));
            $staged[] = $file;
        }

        return $staged;
    }

    /**
     * Every OTHER sanctorale Missal of THIS request's rite that declares `$eventKey`, and the
     * date it declares it on.
     *
     * Scoped to the current rite deliberately: a shared `event_key` across rites is not a
     * collision, since the rites are separate calendars — `MissalCatalog::for($this->rite)`
     * is the boundary that keeps a Roman and an Ambrosian missal from being compared here.
     *
     * Read through {@see loadSanctoraleRows()} rather than straight off disk, so a key this
     * submitter created in another Missal a moment ago — and which in queue mode is nowhere on
     * disk — still collides. A uniqueness check that cannot see the submitter's own in-flight
     * work would wave through exactly the pair of writes it exists to stop.
     *
     * @return array<string, array{month:int, day:int}> missal_id => date
     */
    private function declarationsInOtherMissals(string $eventKey, string $exceptMissalId): array
    {
        $declarations = [];
        $source       = MissalCatalog::for($this->rite);

        foreach ($source->getMissalIds() as $missalId) {
            if ($missalId === $exceptMissalId) {
                continue;
            }
            $file = $source->getSanctoraleFileName($missalId);
            if (false === $file || false === file_exists($file)) {
                continue;
            }
            foreach ($this->loadSanctoraleRows($file) as $row) {
                if (
                    property_exists($row, 'event_key') && $row->event_key === $eventKey
                    && property_exists($row, 'month') && is_int($row->month)
                    && property_exists($row, 'day') && is_int($row->day)
                ) {
                    $declarations[$missalId] = ['month' => $row->month, 'day' => $row->day];
                    break;
                }
            }
        }

        return $declarations;
    }

    /**
     * Refuse a row that would make one `event_key` denote two different saints.
     *
     * The rule and the reasoning live in {@see SanctoraleKeyIdentity}; it is the same rule
     * `scripts/lint-missals.php` enforces over the corpus (its invariant 2, from #939), asked
     * prospectively of a row about to be written instead of retrospectively of rows already
     * written. Checked on PATCH as well as PUT, because moving an existing entry's date can
     * break the agreement just as easily as adding a new entry can.
     */
    private function assertKeyIdentity(string $missalId, string $eventKey, int $month, int $day): void
    {
        $disagreements = SanctoraleKeyIdentity::dateDisagreements(
            $month,
            $day,
            $this->declarationsInOtherMissals($eventKey, $missalId)
        );

        if ($disagreements !== []) {
            throw new ConflictException(SanctoraleKeyIdentity::conflictMessage($eventKey, $month, $day, $disagreements));
        }
    }

    /**
     * The structure row to store, built in the order the corpus already uses so a write produces
     * a minimal, readable diff rather than a reshuffled object.
     *
     * `name` is never written: a structure file carries no names — they live one per locale in
     * `i18n/` — and `GET /missals/{missal_id}` folds the negotiated one in at read time.
     *
     * @param ?\stdClass $existing the stored row on PATCH, null on PUT
     */
    private function buildRow(string $eventKey, \stdClass $payload, ?\stdClass $existing, string $expectedCalendar): \stdClass
    {
        $merged = $existing instanceof \stdClass ? clone $existing : new \stdClass();
        foreach (get_object_vars($payload) as $property => $value) {
            if (in_array($property, ['i18n', 'readings', 'event_key'], true)) {
                continue;
            }
            $merged->{$property} = $value;
        }
        $merged->event_key = $eventKey;

        $required = ['month', 'day', 'grade', 'common', 'calendar', 'color'];
        $missing  = array_values(array_filter($required, static fn (string $p): bool => false === property_exists($merged, $p)));
        if ($missing !== []) {
            throw new ValidationException(
                'A sanctorale entry needs ' . implode(', ', $required) . '; missing: ' . implode(', ', $missing)
                . '. On PUT the whole entry must be supplied, since PUT creates or replaces it.'
            );
        }

        if ($merged->calendar !== $expectedCalendar) {
            throw new ValidationException(sprintf(
                'This Missal\'s entries belong to the `%s` calendar, but the payload says `%s`. '
                . 'A row filed under a calendar its own Missal never applies to would be loaded for nobody.',
                $expectedCalendar,
                is_string($merged->calendar) ? $merged->calendar : gettype($merged->calendar)
            ));
        }

        // Canonical property order, matching every row already in the corpus.
        $row = new \stdClass();
        foreach (['month', 'day', 'event_key', 'grade', 'grade_display', 'common', 'calendar', 'color', 'is_dominical', 'is_bvm', 'color_ad_libitum'] as $property) {
            if (property_exists($merged, $property)) {
                $row->{$property} = $merged->{$property};
            }
        }

        return $row;
    }

    /**
     * `array<int, …>` rather than `list<…>` because the PATCH path replaces a row in place, which
     * preserves the keys without preserving the proof that they are still 0..n-1. The encoder
     * below re-indexes anyway, so the file is always a JSON array either way.
     *
     * @param array<int, \stdClass> $rows
     */
    private function stageSanctoraleRows(string $file, array $rows): void
    {
        $this->stageFile($file, ChangeOperation::UPDATE, self::encodeSanctoraleFile(array_values($rows)));
    }

    /**
    /**
     * A built row's calendar date, checked rather than asserted.
     *
     * `buildRow()` has already required both properties to be present and the write payload
     * schema constrains them to integers, but a row merged from an existing one carries whatever
     * the file holds. The identity rule compares dates for equality, so a `"4"` that is not a `4`
     * would silently make every comparison fail and wave a genuine collision through — the exact
     * failure mode the rule exists to stop.
     *
     * @return array{0:int, 1:int} [month, day]
     */
    private static function rowDate(\stdClass $row): array
    {
        $month = property_exists($row, 'month') ? $row->month : null;
        $day   = property_exists($row, 'day') ? $row->day : null;

        if (false === is_int($month) || false === is_int($day)) {
            throw new ValidationException('A sanctorale entry needs an integer `month` and `day`.');
        }

        return [$month, $day];
    }

    /**
     * @param list<\stdClass> $rows
     * @return ?int the index of the row declaring `$eventKey`, or null
     */
    private function findRowIndex(array $rows, string $eventKey): ?int
    {
        foreach ($rows as $index => $row) {
            if (property_exists($row, 'event_key') && $row->event_key === $eventKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Serialize concurrent load -> mutate -> stage -> commit sequences on one Missal's sanctorale.
     *
     * A separate `.lock` file is used, not the structure file itself, because
     * {@see WritesSourceData::commitStagedFiles()} writes that file with `LOCK_EX` from this same
     * process in disk mode, and nesting flock on one file would conflict. The same reasoning, and
     * the same shape, as {@see \LiturgicalCalendar\Api\Handlers\DecreesHandler::withDecreesLock()}.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withMissalLock(string $structureFile, callable $fn): mixed
    {
        $fh = fopen($structureFile . '.lock', 'c');
        if ($fh === false) {
            throw new ServiceUnavailableException('Could not open the sanctorale lock file');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new ServiceUnavailableException('Could not acquire exclusive lock on the sanctorale');
            }
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Commit every file staged for this request — the structure file plus whichever sidecars
     * changed — as one batch.
     *
     * {@see \LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter::commit()} is not
     * transactional across a batch: it writes staged files in order and stops at the first
     * failure, so the structure file (staged first by every caller here) can already be on disk
     * when a later sidecar write fails. Roll it back when that happens, so the row and its
     * sidecars never diverge silently. Partial sidecar files may remain, which is acceptable —
     * an entry whose name is missing in one locale is visible; an entry whose readings belong to
     * a different saint is not.
     *
     * @param list<\stdClass> $prior the rows as they were before this request's mutation
     * @return array<string, mixed>
     */
    private function commitMissalChangeRequest(string $missalId, string $structureFile, array $prior): array
    {
        try {
            return $this->commitStagedFiles(ChangeResource::missal($missalId, $this->rite));
        } catch (ServiceUnavailableException $e) {
            try {
                $this->stageSanctoraleRows($structureFile, $prior);
                $this->commitStagedFiles(ChangeResource::missal($missalId, $this->rite));
            } catch (\Throwable $rollbackEx) {
                $this->auditLogger->error('Sanctorale rollback failed after sidecar write error', [
                    'operation'      => 'ROLLBACK',
                    'resource'       => 'missals',
                    'missal_id'      => $missalId,
                    'rollback_error' => $rollbackEx->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    /** @param list<string> $files */
    private function auditLog(string $operation, string $missalId, string $eventKey, array $files): void
    {
        /** @var array{sub?:string}|null $oidcUser */
        $oidcUser = $this->request?->getAttribute('oidc_user');
        $userSub  = is_array($oidcUser) && isset($oidcUser['sub']) && is_string($oidcUser['sub'])
            ? $oidcUser['sub']
            : 'anonymous';

        $this->auditLogger->info('Missal sanctorale entry ' . strtolower($operation), [
            'operation' => $operation,
            'resource'  => 'missals',
            'missal_id' => $missalId,
            'event_key' => $eventKey,
            'user'      => $userSub,
            'ip'        => $this->clientIp,
            'files'     => $files,
        ]);
    }

    /**
     * Encode one sanctorale source file the way the corpus already stores it.
     *
     * NOT {@see \LiturgicalCalendar\Api\JsonFormatter::encode()}, which the decrees corpus uses.
     * That formatter collapses a simple string array onto one line — `[ "white" ]` — and the
     * sanctorale files do not: they spell `common` and `color` expanded, one element per line.
     * Encoding a sanctorale file through it rewrites every row of the file: adding one entry to
     * `propriumdesanctis_1970` would produce a diff touching all 187 of them, and a reviewer
     * approving that change request would have no way to see what actually changed.
     *
     * These flags round-trip all five sanctorale structure files, both lectionary folders and the
     * rite-level `sanctorum` corpus byte-for-byte, which
     * `MissalsHandlerWriteTest::testEncodingAnUntouchedSanctoraleFileReproducesItByteForByte()`
     * asserts so it cannot quietly stop being true. Eight `i18n` files are the exception, and
     * only in whitespace: seven carry CRLF line endings and one is indented with two spaces. A
     * write that adds a key to one of those normalizes it, which is a one-time correction rather
     * than churn — a file that is not touched is not restaged at all.
     */
    private static function encodeSanctoraleFile(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    /**
     * The all-empty readings placeholder, which is what the corpus already stores for an entry
     * whose readings have not been researched yet — every one of the fourteen US_2011 entries
     * carries exactly this in both of its lectionary locale files.
     *
     * @return array<string, string>
     */
    private static function emptyReadings(): array
    {
        return [
            'first_reading'      => '',
            'responsorial_psalm' => '',
            'gospel_acclamation' => '',
            'gospel'             => '',
        ];
    }

    /**
     * Fan an entry's key out across the name and readings sidecars, and report which files changed.
     *
     * @param array{missal_id:string,structure_file:string,i18n_folder:string,readings_folder:string,readings_tier:string,calendar:string} $target
     * @return list<string>
     */
    private function applySidecars(array $target, string $eventKey, \stdClass $payload): array
    {
        $i18nLocales     = $this->sidecarLocales($target['i18n_folder']);
        $readingsLocales = $this->sidecarLocales($target['readings_folder']);

        $i18n     = property_exists($payload, 'i18n') && $payload->i18n instanceof \stdClass ? $payload->i18n : null;
        $readings = property_exists($payload, 'readings') && $payload->readings instanceof \stdClass ? $payload->readings : null;

        if (null !== $i18n) {
            $this->assertLocalesExist($i18n, $i18nLocales, 'i18n', $target['i18n_folder']);
        }
        if (null !== $readings) {
            $this->assertLocalesExist($readings, $readingsLocales, 'readings', $target['readings_folder']);
        }

        return array_merge(
            $this->fanOutKey($i18nLocales, $eventKey, self::toAssoc($i18n), static fn (): string => ''),
            $this->fanOutKey($readingsLocales, $eventKey, self::toAssoc($readings), static fn (): array => self::emptyReadings())
        );
    }

    /**
     * A payload map as nested arrays, so a value that is byte-for-byte what the file already
     * holds compares equal to it and does not restage the file. `===` on two structurally
     * identical `stdClass` instances is false, which would make every write propose every
     * sidecar it merely read.
     *
     * @return ?array<string, mixed>
     */
    private static function toAssoc(?\stdClass $map): ?array
    {
        if (null === $map) {
            return null;
        }
        $encoded = json_encode($map);
        if (false === $encoded) {
            throw new ValidationException('Could not normalize the payload sidecar map');
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * `PUT /missals/{missal_id}/{event_key}` — create a sanctorale entry.
     *
     * Creation is where the two invariants that make this more than a file write both apply.
     * The key must not already denote a different saint in another Missal layer
     * ({@see assertKeyIdentity()}), and the key must be fanned out into EVERY locale file of the
     * Missal, empty where there is nothing to say yet ({@see fanOutKey()}). Everything the
     * request touches — the structure row and both sidecar families — is staged and committed as
     * one batch, so in queue mode a reviewer sees one proposal rather than a row here and a
     * translation there.
     */
    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        [$missalId, $eventKey] = $this->requireEntryPathParams();
        $target                = $this->resolveSanctoraleTarget($missalId);
        $payload               = $this->requireValidatedPayload($eventKey, isCreate: true);

        return $this->withMissalLock($target['structure_file'], function () use ($target, $missalId, $eventKey, $payload, $response): ResponseInterface {
            $prior = $this->loadSanctoraleRows($target['structure_file']);
            if (null !== $this->findRowIndex($prior, $eventKey)) {
                throw new ConflictException(
                    "The Missal `{$missalId}` already declares `{$eventKey}`. Use PATCH to change it."
                );
            }

            $row           = $this->buildRow($eventKey, $payload, null, $target['calendar']);
            [$month, $day] = self::rowDate($row);
            $this->assertKeyIdentity($missalId, $eventKey, $month, $day);

            $rows   = $prior;
            $rows[] = $row;
            $this->stageSanctoraleRows($target['structure_file'], $rows);
            $touched       = $this->applySidecars($target, $eventKey, $payload);
            $changeRequest = $this->commitMissalChangeRequest($missalId, $target['structure_file'], $prior);
            $this->auditLog('CREATE', $missalId, $eventKey, array_merge([$target['structure_file']], $touched));

            return $this->encodeResponseBody(
                $response,
                $this->writeResult("Sanctorale entry `{$eventKey}` created in Missal `{$missalId}`", $missalId, $eventKey, $row, $target, $changeRequest),
                StatusCode::CREATED
            );
        });
    }

    /**
     * `PATCH /missals/{missal_id}/{event_key}` — change an existing entry.
     *
     * An unknown `event_key` is a 404, never an implicit create: a PATCH that created what it
     * could not find would turn a typo into a second saint under a nearly-right key, with its own
     * fan-out across every locale file.
     */
    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        [$missalId, $eventKey] = $this->requireEntryPathParams();
        $target                = $this->resolveSanctoraleTarget($missalId);
        $payload               = $this->requireValidatedPayload($eventKey, isCreate: false);

        return $this->withMissalLock($target['structure_file'], function () use ($target, $missalId, $eventKey, $payload, $response): ResponseInterface {
            $prior = $this->loadSanctoraleRows($target['structure_file']);
            $index = $this->findRowIndex($prior, $eventKey);
            if (null === $index) {
                throw new NotFoundException(
                    "The Missal `{$missalId}` declares no entry `{$eventKey}`; use PUT to create it."
                );
            }

            $row           = $this->buildRow($eventKey, $payload, $prior[$index], $target['calendar']);
            [$month, $day] = self::rowDate($row);
            $this->assertKeyIdentity($missalId, $eventKey, $month, $day);

            $rows         = $prior;
            $rows[$index] = $row;
            $this->stageSanctoraleRows($target['structure_file'], $rows);
            $touched       = $this->applySidecars($target, $eventKey, $payload);
            $changeRequest = $this->commitMissalChangeRequest($missalId, $target['structure_file'], $prior);
            $this->auditLog('UPDATE', $missalId, $eventKey, array_merge([$target['structure_file']], $touched));

            return $this->encodeResponseBody(
                $response,
                $this->writeResult("Sanctorale entry `{$eventKey}` updated in Missal `{$missalId}`", $missalId, $eventKey, $row, $target, $changeRequest)
            );
        });
    }

    /**
     * `DELETE /missals/{missal_id}/{event_key}` — remove an entry and garbage-collect its sidecars.
     *
     * The issue asked whether DELETE has a real use case, since sanctorale entries are rarely
     * removed. It has exactly one, and it is the reason the whole issue exists: an `event_key`
     * cannot be renamed, so the only way to correct one — the way #939 actually corrected
     * `StIsidore` — is to delete the entry and re-create it under the right key. Without a DELETE
     * that garbage-collects, that correction leaves the name and the readings behind under the
     * old key, which is the half-finished rename `scripts/lint-missals.php` invariant 3 fails on.
     *
     * The Missal's own `i18n/` folder is always garbage-collected: it belongs to this Missal and
     * nothing else reads it. The readings folder depends on which tier it is. A national
     * edition's `lectionary/` folder is likewise its own. The rite-level `lectionary/sanctorum/`
     * corpus is NOT: `StPeterClaver` is declared by three Missals and has one set of readings, so
     * dropping the US_2011 entry must leave them exactly where the 2002 editio typica still needs
     * them. This is `DecreesHandler`'s `$stillReferenced` check (`DecreesHandler.php:830`) applied
     * to the tier that is actually shared.
     *
     * `deletesResource` stays FALSE. It reports that the RESOURCE is gone, and the resource here
     * is the Missal, which still exists with all its other entries — passing true would revoke
     * every editor's grant on a live Missal because one saint was removed.
     */
    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        [$missalId, $eventKey] = $this->requireEntryPathParams();
        $target                = $this->resolveSanctoraleTarget($missalId);

        return $this->withMissalLock($target['structure_file'], function () use ($target, $missalId, $eventKey, $response): ResponseInterface {
            $prior = $this->loadSanctoraleRows($target['structure_file']);
            $index = $this->findRowIndex($prior, $eventKey);
            if (null === $index) {
                throw new NotFoundException("The Missal `{$missalId}` declares no entry `{$eventKey}`.");
            }

            $rows = array_values(array_filter(
                $prior,
                static fn (\stdClass $row): bool => false === property_exists($row, 'event_key') || $row->event_key !== $eventKey
            ));
            $this->stageSanctoraleRows($target['structure_file'], $rows);

            $touched = $this->removeKeyFromLocaleFiles($eventKey, $target['i18n_folder']);

            $stillDeclaredElsewhere = $target['readings_tier'] === 'rite'
                && $this->declarationsInOtherMissals($eventKey, $missalId) !== [];
            if (false === $stillDeclaredElsewhere) {
                $touched = array_merge($touched, $this->removeKeyFromLocaleFiles($eventKey, $target['readings_folder']));
            }

            $changeRequest = $this->commitMissalChangeRequest($missalId, $target['structure_file'], $prior);
            $this->auditLog('DELETE', $missalId, $eventKey, array_merge([$target['structure_file']], $touched));

            $result                    = new \stdClass();
            $result->success           = "Sanctorale entry `{$eventKey}` deleted from Missal `{$missalId}`";
            $result->missal_id         = $missalId;
            $result->event_key         = $eventKey;
            $result->readings_tier     = $target['readings_tier'];
            $result->readings_retained = $stillDeclaredElsewhere;
            foreach ($changeRequest as $key => $value) {
                $result->{$key} = $value;
            }

            return $this->encodeResponseBody($response, $result);
        });
    }

    /**
     * @param array{missal_id:string,structure_file:string,i18n_folder:string,readings_folder:string,readings_tier:string,calendar:string} $target
     * @param array<string, mixed> $changeRequest
     */
    private function writeResult(string $success, string $missalId, string $eventKey, \stdClass $row, array $target, array $changeRequest): \stdClass
    {
        $result                = new \stdClass();
        $result->success       = $success;
        $result->missal_id     = $missalId;
        $result->event_key     = $eventKey;
        $result->entry         = $row;
        $result->readings_tier = $target['readings_tier'];
        foreach ($changeRequest as $key => $value) {
            $result->{$key} = $value;
        }

        return $result;
    }
}
