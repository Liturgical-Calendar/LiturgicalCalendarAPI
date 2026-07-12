<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Handlers\Auth\ClientIpTrait;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\JsonFormatter;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCollection;
use LiturgicalCalendar\Api\Models\Decrees\DecreeWritePayloadGuard;
use LiturgicalCalendar\Api\Params\DecreesParams;
use LiturgicalCalendar\Api\Utilities;
use Monolog\Logger;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swaggest\JsonSchema\Schema;

/**
 * @phpstan-import-type DecreeItemFromObject from \LiturgicalCalendar\Api\Models\Decrees\DecreeItem
 */
final class DecreesHandler extends AbstractHandler
{
    use ClientIpTrait;

    public static DecreeItemCollection $decreesIndex;
    public DecreesParams $params;
    private ServerRequestInterface $request;
    private Logger $auditLogger;
    private string $clientIp = 'unknown';

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
        $this->auditLogger = LoggerFactory::create('audit', null, 90, false, true, false);
    }

    /*
    private static function initRequestParams(): array
    {
        $data = [];
        if (in_array(self::$Core->getRequestMethod(), [RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH])) {
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
                        self::produceErrorResponse(StatusCode::BAD_REQUEST, "Decrees::initRequestParams: Expected payload in body of request, either JSON encoded, YAML encoded, or Form Data encoded");
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
    */

    /**
     * Handles the request for the Decrees endpoint.
     *
     * This function:
     *  - Validates the Accept header if the request method is GET.
     *  - Sets the response content type header.
     *  - Encodes the decrees index to JSON and outputs the response if the request path is empty.
     *  - Otherwise, handles the path parameters.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Store request for access by private helpers (audit logging, etc.)
        $this->request = $request;

        // Capture client IP for audit logging
        /** @var array<string,mixed> $serverParams */
        $serverParams   = $request->getServerParams();
        $this->clientIp = $this->getClientIp($request, $serverParams);

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

        /** @var array{locale?:string,payload?:\stdClass} $params */
        $params = [];

        // Second of all, we check if an Accept-Language header was set in the request
        $locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        if ($method === RequestMethod::GET) {
            /** @var array{locale?:string} $params */
            $params = array_merge($params, $this->getScalarQueryParams($request));
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array{locale?:string} $params */
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

        /** @var array{locale?:string,payload?:\stdClass} $params */
        $this->params = new DecreesParams($params);

        $this->validateRequestMethod($request);

        switch ($method) {
            case RequestMethod::GET:
                // no break (intentional fallthrough)
            case RequestMethod::POST:
                return $this->handleGetRequest($response);
                // no break needed
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

    private function handleGetRequest(ResponseInterface $response): ResponseInterface
    {
        $decreesI18nFile = strtr(
            JsonData::DECREES_I18N_FILE->path(),
            ['{locale}' => $this->params->Locale]
        );

        /** @var DecreeItemFromObject[] $decrees */
        $decrees = Utilities::jsonFileToObjectArray(JsonData::DECREES_FILE->path());
        $names   = Utilities::jsonFileToArray($decreesI18nFile);
        if (array_filter(array_keys($names), 'is_string') !== array_keys($names)) {
            $description = Stream::create("DecreesHandler: We expected all the keys of the i18n array from file {$decreesI18nFile} to be strings.");
            return $response
                ->withStatus(StatusCode::SERVICE_UNAVAILABLE->value, StatusCode::SERVICE_UNAVAILABLE->reason())
                ->withBody($description);
        }

        if (array_filter($names, 'is_string') !== $names) {
            $description = Stream::create("DecreesHandler: We expected all the values of the i18n array from file {$decreesI18nFile} to be strings.");
            return $response
                ->withStatus(StatusCode::SERVICE_UNAVAILABLE->value, StatusCode::SERVICE_UNAVAILABLE->reason())
                ->withBody($description);
        }

        /** @var array<string,string> $names */
        DecreeItemCollection::setNames($decrees, $names);

        self::$decreesIndex = DecreeItemCollection::fromObject($decrees);

        // DecreesParams normalizes Locale to the primary language (e.g. `en_US` -> `en`),
        // so the lectionary file lookup never needs a base-locale fallback here.
        $locale         = $this->params->Locale ?? LitLocale::LATIN_PRIMARY_LANGUAGE;
        $lectionaryFile = strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => $locale]);
        if (file_exists($lectionaryFile)) {
            $readings = Utilities::jsonFileToObject($lectionaryFile);
            foreach (self::$decreesIndex as $decree) {
                $eventKey      = $decree->liturgical_event->event_key;
                $eventReadings = property_exists($readings, $eventKey) ? $readings->{$eventKey} : null;
                if ($eventReadings instanceof \stdClass) {
                    $decree->liturgical_event->setReadings($eventReadings);
                }
            }
        }

        $countPathParams = count($this->requestPathParams);
        if ($countPathParams === 0) {
            $decreesIndex                 = new \stdClass();
            $decreesIndex->litcal_decrees = self::$decreesIndex->decreeItems;
            return $this->encodeResponseBody($response, $decreesIndex);
        } elseif ($countPathParams > 1) {
            throw new ValidationException('Only one path parameter expected on the `/decrees` path, instead ' . $countPathParams . ' found');
        } else {
            $decreeId = $this->requestPathParams[0];
            $decree   = array_find(self::$decreesIndex->decreeItems, fn ($decree) => $decree->decree_id === $decreeId);
            if (null === $decree) {
                $decreeIDs = array_column(self::$decreesIndex->decreeItems, 'decree_id');
                $error     = 'No Decree of the Dicastery for Divine Worship and the Discipline of the Sacraments found corresponding to '
                    . $decreeId
                    . ', valid values are found in the `decree_id` properties of the `litcal_decrees` collection: ' . implode(', ', $decreeIDs);
                throw new NotFoundException($error);
            }

            // Single-decree GET is enriched with the full translation and readings
            // sets (every locale, not just the request locale) so the admin editor
            // can prefill all defined translations. Shape mirrors the PUT/PATCH body.
            $eventKey = $decree->liturgical_event->event_key;
            $encoded  = json_encode($decree);
            if ($encoded === false) {
                throw new ServiceUnavailableException('Could not serialize decree');
            }
            $out = json_decode($encoded);
            if (!$out instanceof \stdClass) {
                throw new ServiceUnavailableException('Could not build decree response');
            }
            $out->i18n     = $this->aggregateI18nForEvent($eventKey);
            $out->readings = $this->aggregateReadingsForEvent($eventKey);
            return $this->encodeResponseBody($response, $out);
        }
    }

    /**
     * Aggregate the non-empty translated names for an event across every decree
     * i18n locale file, keyed by locale. Returned on the single-decree GET so
     * the admin editor sees all defined translations, GRC-supported or not.
     *
     * @return \stdClass locale => translated name
     */
    private function aggregateI18nForEvent(string $eventKey): \stdClass
    {
        $out    = new \stdClass();
        $folder = JsonData::DECREES_I18N_FOLDER->path();
        $files  = glob($folder . '/*.json');
        if ($files === false) {
            return $out;
        }
        sort($files);
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $arr    = Utilities::jsonFileToArray($file);
            if (isset($arr[$eventKey]) && is_string($arr[$eventKey]) && $arr[$eventKey] !== '') {
                $out->{$locale} = $arr[$eventKey];
            }
        }
        return $out;
    }

    /**
     * Aggregate the readings for an event across every lectionary locale file,
     * keyed by locale, including only locales whose readings have at least one
     * non-empty field. Returned on the single-decree GET for editor prefill.
     *
     * @return \stdClass locale => readings object
     */
    private function aggregateReadingsForEvent(string $eventKey): \stdClass
    {
        $out    = new \stdClass();
        $folder = JsonData::LECTIONARY_DECREES_FOLDER->path();
        $files  = glob($folder . '/*.json');
        if ($files === false) {
            return $out;
        }
        sort($files);
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $obj    = Utilities::jsonFileToObject($file);
            if (false === property_exists($obj, $eventKey) || false === ( $obj->{$eventKey} instanceof \stdClass )) {
                continue;
            }
            $readings   = $obj->{$eventKey};
            $hasContent = false;
            foreach (get_object_vars($readings) as $value) {
                if (is_string($value) && $value !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if ($hasContent) {
                $out->{$locale} = $readings;
            }
        }
        return $out;
    }

    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        $decreeId = $this->requireSinglePathParam();
        $payload  = $this->requireValidatedPayload($decreeId, isCreate: true);

        return $this->withDecreesLock(function () use ($decreeId, $payload, $response): ResponseInterface {
            $prior = $this->loadDecreesDatabase();
            if (null !== array_find($prior, fn ($d) => $d->decree_id === $decreeId)) {
                throw new ConflictException("Decree `{$decreeId}` already exists. Use PATCH to update it.");
            }

            $decrees   = $prior;
            $decrees[] = $this->stripSidecars($payload);
            $this->saveDecreesDatabase($decrees);
            $this->applySidecarsWithRollback($payload, $prior, $decreeId);
            // FINDING 7: include touched files in audit context.
            $auditFiles   = [JsonData::DECREES_FILE->path()];
            $auditFiles[] = JsonData::DECREES_I18N_FOLDER->path();
            if (property_exists($payload, 'readings') && $payload->readings instanceof \stdClass) {
                $auditFiles[] = JsonData::LECTIONARY_DECREES_FOLDER->path();
            }
            $this->auditLog('CREATE', $decreeId, $auditFiles);

            $result          = new \stdClass();
            $result->success = "Decree `{$decreeId}` created";
            $result->decree  = $this->stripSidecars($payload);
            return $this->encodeResponseBody($response, $result, StatusCode::CREATED);
        });
    }

    /**
     * Execute a callable while holding an exclusive advisory lock on the decrees lockfile,
     * serializing concurrent load->mutate->save sequences on decrees.json.
     *
     * A separate `.lock` file is used (not decrees.json itself) because saveDecreesDatabase()
     * takes LOCK_EX on decrees.json via file_put_contents from this same process, and nesting
     * flock on the same file would conflict.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withDecreesLock(callable $fn): mixed
    {
        $lockPath = JsonData::DECREES_FILE->path() . '.lock';
        $fh       = fopen($lockPath, 'c');
        if ($fh === false) {
            throw new ServiceUnavailableException('Could not open decrees lock file');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new ServiceUnavailableException('Could not acquire exclusive lock on decrees database');
            }
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Apply the i18n/readings sidecars; if sidecar distribution fails after the decrees
     * database was already persisted, roll the database back to its prior state so that
     * decrees.json (the source of truth) never diverges silently. Partial sidecar files
     * may remain, which is acceptable.
     *
     * @param \stdClass[] $prior The decrees array as it was before the mutation.
     */
    private function applySidecarsWithRollback(\stdClass $payload, array $prior, string $decreeId): void
    {
        try {
            $this->applySidecars($payload);
        } catch (ServiceUnavailableException $e) {
            $this->rollbackDecreesDatabase($prior, $decreeId);
            throw $e;
        }
    }

    /**
     * Best-effort restore of the decrees database to its prior state; a rollback failure
     * is logged but never masks the original exception being propagated by the caller.
     *
     * @param \stdClass[] $prior The decrees array as it was before the mutation.
     */
    private function rollbackDecreesDatabase(array $prior, string $decreeId): void
    {
        try {
            $this->saveDecreesDatabase($prior);
        } catch (\Throwable $rollbackEx) {
            $this->auditLogger->error('Decree database rollback failed after sidecar write error', [
                'operation'      => 'ROLLBACK',
                'resource'       => 'decrees',
                'decree_id'      => $decreeId,
                'rollback_error' => $rollbackEx->getMessage(),
            ]);
        }
    }

    private function requireSinglePathParam(): string
    {
        if (count($this->requestPathParams) !== 1) {
            throw new ValidationException('Write operations on the `/decrees` path require exactly one path parameter: /decrees/{decree_id}');
        }
        return $this->requestPathParams[0];
    }

    private function requireValidatedPayload(string $decreeId, bool $isCreate): \stdClass
    {
        // $this->params->Payload is guaranteed to be set as \stdClass by handle()
        // before this helper is called (handle() validates the parsed body at line ~172).
        $payload = $this->params->Payload;
        try {
            $schema = Schema::import(LitSchema::DECREE_WRITE->path());
            $schema->in($payload);
        } catch (\Swaggest\JsonSchema\Exception $e) {
            throw new ValidationException('Decree write payload failed schema validation: ' . $e->getMessage());
        }
        $payloadDecreeId = property_exists($payload, 'decree_id') && is_string($payload->decree_id) ? $payload->decree_id : '';
        if ($payloadDecreeId !== $decreeId) {
            throw new ValidationException("The `decree_id` in the request body (`{$payloadDecreeId}`) must match the decree_id in the URL (`{$decreeId}`)");
        }
        $locale     = $this->params->Locale;
        $baseLocale = $locale !== null ? explode('_', $locale)[0] : 'la';
        DecreeWritePayloadGuard::assertSidecars($payload, $baseLocale, $isCreate);
        // Validate DTO invariants (fixed vs mobile, setProperty->property, etc.).
        // DecreeItemCollection::fromObject() requires name-bearing events (createNew/makeDoctor/setProperty name)
        // to have a `name` property already set (normally done by setNames()). For write payloads, name comes
        // from i18n; inject a synthetic placeholder so the structural validation can proceed.
        // FINDING 1: use a deep copy so the placeholder never leaks into the original payload
        //            (stripSidecars() is a shallow clone — liturgical_event is shared without deep copy).
        $stripped    = $this->stripSidecars($payload);
        $jsonEncoded = json_encode($stripped);
        if ($jsonEncoded === false) {
            throw new ValidationException('Could not serialize payload for validation');
        }
        $forValidation = json_decode($jsonEncoded);
        if (!$forValidation instanceof \stdClass) {
            throw new ValidationException('Could not deep-copy payload for validation');
        }
        $metadata     = property_exists($payload, 'metadata') && $payload->metadata instanceof \stdClass ? $payload->metadata : null;
        $action       = $metadata !== null && property_exists($metadata, 'action') && is_string($metadata->action) ? $metadata->action : null;
        $propertyProp = $metadata !== null && property_exists($metadata, 'property') && is_string($metadata->property) ? $metadata->property : null;
        if (
            in_array($action, ['createNew', 'makeDoctor'], true)
            || ( $action === 'setProperty' && $propertyProp === 'name' )
        ) {
            $litEvent = property_exists($forValidation, 'liturgical_event') && $forValidation->liturgical_event instanceof \stdClass
                ? $forValidation->liturgical_event
                : null;
            if ($litEvent !== null) {
                $litEvent->name = '__placeholder__';
            }
        }
        DecreeItemCollection::fromObject([$forValidation]);
        return $payload;
    }

    private function applySidecars(\stdClass $payload): void
    {
        $litEvent = property_exists($payload, 'liturgical_event') && $payload->liturgical_event instanceof \stdClass
            ? $payload->liturgical_event
            : null;
        $eventKey = $litEvent !== null && property_exists($litEvent, 'event_key') && is_string($litEvent->event_key)
            ? $litEvent->event_key
            : '';
        if ($eventKey === '') {
            return;
        }
        if (property_exists($payload, 'i18n') && $payload->i18n instanceof \stdClass) {
            $this->distributeI18n($eventKey, $payload->i18n);
        }
        if (property_exists($payload, 'readings') && $payload->readings instanceof \stdClass) {
            $this->distributeReadings($eventKey, $payload->readings);
        }
    }

    /** @return \stdClass[] */
    private function loadDecreesDatabase(): array
    {
        return Utilities::jsonFileToObjectArray(JsonData::DECREES_FILE->path());
    }

    /** @param \stdClass[] $decrees */
    private function saveDecreesDatabase(array $decrees): void
    {
        $path   = JsonData::DECREES_FILE->path();
        $result = file_put_contents($path, JsonFormatter::encode(array_values($decrees)) . PHP_EOL, LOCK_EX);
        if ($result === false) {
            throw new ServiceUnavailableException('Could not write decrees database');
        }
        Utilities::invalidateJsonFileCache($path);
    }

    private function stripSidecars(\stdClass $payload): \stdClass
    {
        $clone = clone $payload;
        unset($clone->i18n, $clone->readings);
        return $clone;
    }

    private function distributeI18n(string $eventKey, \stdClass $i18n): void
    {
        $folder = JsonData::DECREES_I18N_FOLDER->path();
        $files  = glob($folder . '/*.json');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $arr    = Utilities::jsonFileToArray($file);
            /** @var array<string,string> $arr */
            // FINDING 2: preserve existing translation when the payload doesn't provide this locale.
            $arr[$eventKey] = property_exists($i18n, $locale) && is_string($i18n->{$locale})
                ? $i18n->{$locale}
                : ( isset($arr[$eventKey]) && is_string($arr[$eventKey]) ? $arr[$eventKey] : '' );
            ksort($arr);
            // FINDING 5: check write result; silent partial writes can corrupt sidecar files.
            $result = file_put_contents($file, JsonFormatter::encode($arr) . PHP_EOL, LOCK_EX);
            if ($result === false) {
                throw new ServiceUnavailableException("Could not write i18n file: {$file}");
            }
            Utilities::invalidateJsonFileCache($file);
        }
    }

    private function distributeReadings(string $eventKey, \stdClass $readings): void
    {
        $folder = JsonData::LECTIONARY_DECREES_FOLDER->path();
        $files  = glob($folder . '/*.json');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            if (!property_exists($readings, $locale)) {
                continue;
            }
            $arr = Utilities::jsonFileToArray($file);
            /** @var array<string,mixed> $arr */
            $arr[$eventKey] = $readings->{$locale};
            ksort($arr);
            // FINDING 5: check write result to avoid silent partial writes.
            $result = file_put_contents($file, JsonFormatter::encode($arr) . PHP_EOL, LOCK_EX);
            if ($result === false) {
                throw new ServiceUnavailableException("Could not write lectionary file: {$file}");
            }
            Utilities::invalidateJsonFileCache($file);
        }
    }

    /**
     * @param string[] $files Paths (files or folders) touched by the operation.
     */
    private function auditLog(string $operation, string $decreeId, array $files = []): void
    {
        /** @var array{sub?:string}|null $oidcUser */
        $oidcUser = $this->request->getAttribute('oidc_user');
        $userSub  = is_array($oidcUser) && isset($oidcUser['sub']) && is_string($oidcUser['sub'])
            ? $oidcUser['sub']
            : 'anonymous';
        $this->auditLogger->info('Decree ' . strtolower($operation), [
            'operation' => $operation,
            'resource'  => 'decrees',
            'decree_id' => $decreeId,
            'user'      => $userSub,
            'ip'        => $this->clientIp,
            'files'     => $files,
        ]);
    }

    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        $decreeId = $this->requireSinglePathParam();
        $payload  = $this->requireValidatedPayload($decreeId, isCreate: false);

        return $this->withDecreesLock(function () use ($decreeId, $payload, $response): ResponseInterface {
            $prior = $this->loadDecreesDatabase();
            $idx   = null;
            foreach ($prior as $i => $decree) {
                if ($decree->decree_id === $decreeId) {
                    $idx = $i;
                    break;
                }
            }
            if (null === $idx) {
                throw new NotFoundException("No decree found with decree_id `{$decreeId}`; use PUT to create it.");
            }

            // FINDING 3: reject event_key changes — orphans i18n/lectionary entries permanently.
            $storedLitEvent  = property_exists($prior[$idx], 'liturgical_event') && $prior[$idx]->liturgical_event instanceof \stdClass
                ? $prior[$idx]->liturgical_event
                : null;
            $storedEventKey  = $storedLitEvent !== null && property_exists($storedLitEvent, 'event_key') && is_string($storedLitEvent->event_key)
                ? $storedLitEvent->event_key
                : '';
            $payloadLitEvent = property_exists($payload, 'liturgical_event') && $payload->liturgical_event instanceof \stdClass
                ? $payload->liturgical_event
                : null;
            $payloadEventKey = $payloadLitEvent !== null && property_exists($payloadLitEvent, 'event_key') && is_string($payloadLitEvent->event_key)
                ? $payloadLitEvent->event_key
                : '';
            if ($storedEventKey !== '' && $payloadEventKey !== '' && $payloadEventKey !== $storedEventKey) {
                throw new ValidationException(
                    "Changing `liturgical_event.event_key` via PATCH is not allowed (stored: `{$storedEventKey}`, payload: `{$payloadEventKey}`). "
                    . 'To change the event_key, DELETE the decree and re-create it with PUT.'
                );
            }

            $decrees       = $prior;
            $decrees[$idx] = $this->stripSidecars($payload);
            $this->saveDecreesDatabase($decrees);
            $this->applySidecarsWithRollback($payload, $prior, $decreeId);
            // FINDING 7: include touched files in audit context.
            $auditFiles   = [JsonData::DECREES_FILE->path()];
            $auditFiles[] = JsonData::DECREES_I18N_FOLDER->path();
            if (property_exists($payload, 'readings') && $payload->readings instanceof \stdClass) {
                $auditFiles[] = JsonData::LECTIONARY_DECREES_FOLDER->path();
            }
            $this->auditLog('UPDATE', $decreeId, $auditFiles);

            $result          = new \stdClass();
            $result->success = "Decree `{$decreeId}` updated";
            $result->decree  = $this->stripSidecars($payload);
            return $this->encodeResponseBody($response, $result);
        });
    }

    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        $decreeId = $this->requireSinglePathParam();

        return $this->withDecreesLock(function () use ($decreeId, $response): ResponseInterface {
            $prior  = $this->loadDecreesDatabase();
            $target = array_find($prior, fn ($d) => $d->decree_id === $decreeId);
            if (null === $target) {
                throw new NotFoundException("No decree found with decree_id `{$decreeId}`");
            }

            $surviving = array_values(array_filter($prior, fn ($d) => $d->decree_id !== $decreeId));
            $this->saveDecreesDatabase($surviving);

            $litEvent = property_exists($target, 'liturgical_event') && $target->liturgical_event instanceof \stdClass
                ? $target->liturgical_event
                : null;
            $eventKey = $litEvent !== null && property_exists($litEvent, 'event_key') && is_string($litEvent->event_key)
                ? $litEvent->event_key
                : '';
            // FINDING 7: track which folders are GC'd for the audit entry.
            $gcFolders = [];
            if ($eventKey !== '') {
                $stillReferenced = null !== array_find(
                    $surviving,
                    fn ($d) => property_exists($d, 'liturgical_event')
                        && $d->liturgical_event instanceof \stdClass
                        && property_exists($d->liturgical_event, 'event_key')
                        && $d->liturgical_event->event_key === $eventKey
                );
                if (false === $stillReferenced) {
                    try {
                        $this->removeKeyFromLocaleFiles($eventKey, JsonData::DECREES_I18N_FOLDER->path());
                        $this->removeKeyFromLocaleFiles($eventKey, JsonData::LECTIONARY_DECREES_FOLDER->path());
                    } catch (ServiceUnavailableException $e) {
                        // Restore the decrees database so it never diverges from the sidecars silently.
                        $this->rollbackDecreesDatabase($prior, $decreeId);
                        throw $e;
                    }
                    $gcFolders = [JsonData::DECREES_I18N_FOLDER->path(), JsonData::LECTIONARY_DECREES_FOLDER->path()];
                }
            }
            $this->auditLog('DELETE', $decreeId, array_merge([JsonData::DECREES_FILE->path()], $gcFolders));

            $result          = new \stdClass();
            $result->success = "Decree `{$decreeId}` deleted";
            return $this->encodeResponseBody($response, $result);
        });
    }

    private function removeKeyFromLocaleFiles(string $eventKey, string $folder): void
    {
        $files = glob(rtrim($folder, '/') . '/*.json');
        if (false === $files) {
            return;
        }
        foreach ($files as $file) {
            $arr = Utilities::jsonFileToArray($file);
            if (array_key_exists($eventKey, $arr)) {
                unset($arr[$eventKey]);
                // FINDING 5: check write result to avoid silent partial writes.
                $result = file_put_contents($file, JsonFormatter::encode($arr) . PHP_EOL, LOCK_EX);
                if ($result === false) {
                    throw new ServiceUnavailableException("Could not write locale file during key removal: {$file}");
                }
                Utilities::invalidateJsonFileCache($file);
            }
        }
    }
}
