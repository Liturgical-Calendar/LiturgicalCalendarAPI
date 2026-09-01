<?php

namespace LiturgicalCalendar\Api\Handlers;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesOutboxTooling;
use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\JsonFormatter;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class TestsHandler extends AbstractHandler
{
    use ResolvesOutboxTooling;
    use WritesSourceData;

    /** @var string[] */
    private static array $propsToSanitize = [
        'description',
        'applies_to',
        'excludes',
        'assertions',
        'rite',
        'national_calendar',
        'diocesan_calendar',
        'national_calendars',
        'diocesan_calendars',
        'assertion',
        'comment'
    ];

    private \stdClass $payload;

    private ?Rite $rite;

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [], ?Rite $rite = null)
    {
        parent::__construct($requestPathParams);
        $this->rite = $rite;
        // The frontend admin-tests page performs cookie-authenticated writes
        // (PUT / PATCH / DELETE) against /tests from the browser. On split-origin
        // deployments (e.g. the docker e2e stack: frontend :3000 → API :8000) a
        // wildcard Access-Control-Allow-Origin makes the browser reject any
        // credentialed request, so echo the validated origin and allow credentials
        // — same as the /auth and /admin handlers.
        $this->allowCredentials = true;
    }

    /**
     * Sanitizes a given string by removing all HTML tags and converting special characters to HTML entities.
     * @param string $str The string to be sanitized.
     * @return void
     */
    private static function sanitizeString(string &$str): void
    {
        $str = htmlspecialchars(strip_tags($str));
    }

    /**
     * Recursively sanitizes the values of an object's properties that are specified in the $propsToSanitize array.
     * If a property value is an object, the function calls itself recursively to sanitize the nested object's properties.
     * If a property value is an array, it iterates over the array and sanitizes each element.
     * If a property value is a string, it sanitizes the string by removing HTML tags and converting special characters to HTML entities.
     *
     * @param \stdClass $data The object whose properties need to be sanitized. Passed as reference to allow recursive calls.
     * @return void
     */
    private static function sanitizeObjectValues(\stdClass &$data): void
    {
        foreach (get_object_vars($data) as $prop => $value) {
            if (in_array($prop, self::$propsToSanitize)) {
                if ($value instanceof \stdClass) {
                    self::sanitizeObjectValues($value);
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if ($item instanceof \stdClass) {
                            self::sanitizeObjectValues($item);
                        } elseif (is_array($item)) {
                            foreach ($item as $item2) {
                                if (is_string($item2)) {
                                    self::sanitizeString($item2);
                                }
                            }
                        } elseif (is_string($item)) {
                            self::sanitizeString($item);
                        }
                    }
                } elseif (is_string($value)) {
                    self::sanitizeString($value);
                }
            }
        }
    }

    /**
     * Validates the payload against the LitCalTest JSON schema.
     *
     * Uses the schema file path directly so Schema::import() can resolve
     * relative $ref paths (e.g., ./CommonDef.json#/definitions/EventKey).
     *
     * @param string $operation The operation being performed ('create' or 'update')
     * @throws ValidationException If the payload fails schema validation
     */
    private function validatePayloadAgainstTestSchema(string $operation): void
    {
        $schemaFile = JsonData::SCHEMAS_FOLDER->path() . '/LitCalTest.json';

        try {
            $schema = Schema::import($schemaFile);
            $schema->in($this->payload);
        } catch (InvalidValue | \Exception $e) {
            $description = "The Unit Test you are attempting to {$operation} was incorrectly validated against schema {$schemaFile}: {$e->getMessage()}";
            throw new ValidationException($description);
        }
    }

    /**
     * Absolute path to the file backing a test, in the partition for this request's rite.
     *
     * Only ever called once the null-rite guard in handle() has passed, so $this->rite is
     * non-null on every write and single-test read path.
     */
    private function testFilePath(string $testName): string
    {
        return JsonData::testsFolderFor($this->rite ?? Rite::default())->path() . '/' . $testName . '.json';
    }

    /**
     * The FGA scope pair for a test, as a response-only object.
     *
     * @return (\stdClass&object{object_type:string,object_id:string})|null null when no such test exists under that rite
     */
    private function scopeObjectFor(Rite $rite, string $testName): ?\stdClass
    {
        $scope = ( new TestScopeResolver() )->resolve($rite, $testName);
        if ($scope === null) {
            return null;
        }
        /** @var \stdClass&object{object_type:string,object_id:string} $obj */
        $obj = (object) ['object_type' => $scope[0], 'object_id' => $scope[1]];
        return $obj;
    }

    /**
     * Every test in the requested rite, or across every rite when no rite segment was given.
     *
     * @return list<array<string,mixed>>
     */
    private function collectTests(): array
    {
        $rites = $this->rite === null ? Rite::cases() : [$this->rite];
        $suite = [];
        foreach ($rites as $rite) {
            $folder = JsonData::testsFolderFor($rite)->path();
            $files  = glob($folder . '/*Test.json');
            if ($files === false) {
                throw new ServiceUnavailableException("Tests folder {$folder} cannot be opened");
            }
            foreach ($files as $filePath) {
                $testContents = file_get_contents($filePath);
                if ($testContents === false) {
                    throw new ServiceUnavailableException('Test ' . basename($filePath) . ' was not readable');
                }
                /** @var array<string,mixed> $decoded */
                $decoded = json_decode($testContents, true, 512, JSON_THROW_ON_ERROR);
                // resolve() is a file-stem lookup (like the single-test path at handleGetRequest()),
                // so the scope must key off the filename, not the file's internal `name` — the two
                // are enforced equal on write, but reading the content here would silently misattribute
                // scope if they ever diverged.
                $name  = basename($filePath, '.json');
                $scope = ( new TestScopeResolver() )->resolve($rite, $name);
                if ($scope !== null) {
                    $decoded['scope'] = ['object_type' => $scope[0], 'object_id' => $scope[1]];
                }
                $suite[] = $decoded;
            }
        }
        return $suite;
    }

    /**
     * Handles GET requests for tests.
     *
     * If no path parts are provided, this method returns an index of all tests.
     * If one path part is provided, this method returns the contents of the specified test file.
     * If more than one path part is provided, this method responds with a 400 error.
     * If the test file is not found, this method responds with a 404 error.
     */
    private function handleGetRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) === 0) {
            $responseBody               = new \stdClass();
            $responseBody->litcal_tests = $this->collectTests();
            return $this->encodeResponseBody($response, $responseBody);
        } elseif (count($this->requestPathParams) > 1) {
            $description = 'Expected one path param for GET requests, received ' . count($this->requestPathParams);
            throw new ValidationException($description);
        } else {
            $testFile     = array_shift($this->requestPathParams);
            $testFilePath = $this->testFilePath($testFile);
            if (file_exists($testFilePath)) {
                $testContents = file_get_contents($testFilePath);
                if ($testContents === false) {
                    $description = "Test {$testFile} was not readable";
                    throw new ServiceUnavailableException($description);
                }
                $decodedContents = json_decode($testContents, false, 512, JSON_THROW_ON_ERROR);
                if (false === ( $decodedContents instanceof \stdClass )) {
                    throw new ServiceUnavailableException("Failed to decode test {$testFile} as JSON");
                }
                $scopeObject = $this->scopeObjectFor($this->rite ?? Rite::default(), $testFile);
                if ($scopeObject !== null) {
                    $decodedContents->scope = $scopeObject;
                }
                return $this->encodeResponseBody($response, $decodedContents);
            } else {
                $description = "Test {$testFile} not found";
                throw new NotFoundException($description);
            }
        }
    }

    /**
     * Handles DELETE requests for deleting a specific test.
     *
     * This method expects exactly one path parameter which specifies the name of the test to delete.
     * If the test file exists in the tests directory, it attempts to delete the file.
     * Upon successful deletion, it returns a JSON response with a status of "OK" and a message indicating
     * the resource has been deleted. If the deletion fails, it returns a 503 Service Unavailable error.
     * If the test file does not exist, it returns a 404 Not Found error. If the request does not contain
     * exactly one path parameter, it returns a 400 Bad Request error.
     */
    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) !== 1) {
            $description = 'Expected one and only one path param for DELETE requests, received ' . count($this->requestPathParams) . '.';
            throw new ValidationException($description);
        }

        $testName = $this->requestPathParams[0];

        // Resolve the FGA scope FIRST. A null result means the name failed
        // validation (path-traversal / unsafe characters) or the test file is
        // missing/unreadable — in every such case refuse to touch the filesystem,
        // so unsafe names like "../foo" can never reach stageFile()/unlink().
        $scope = ( new TestScopeResolver() )->resolve($this->rite ?? Rite::default(), $testName);
        if ($scope === null) {
            throw new NotFoundException("Test {$testName} not found, cannot DELETE.");
        }

        $this->stageFile($this->testFilePath($testName), ChangeOperation::DELETE, null);
        $changeRequest = $this->commitStagedFiles($this->changeResourceForTest($scope), deletesResource: true);

        // Best-effort purge of operational (editor/viewer) tuples orphaned by the
        // deletion. Gated on the deletion having actually landed: in queue mode the
        // file is still present pending review, so stripping editor/viewer access to
        // it now would revoke permissions on a test that is still in effect (mirrors
        // RegionalDataHandler::deleteCalendar()). When it has landed, the file is
        // already gone, so an OpenFGA/outbox error here must NOT fail the DELETE —
        // the reconciler sweep cleans up any stragglers.
        if (( $changeRequest['disposition'] ?? null ) === 'applied') {
            $purge = $this->getPurgeService();
            if ($purge !== null) {
                [$scopeType, $scopeId] = $scope;
                try {
                    $purge->purgeForObject("{$scopeType}:{$scopeId}");
                } catch (\Throwable $e) {
                    try {
                        LoggerFactory::create('audit', null, 90, false, true, false)->warning(
                            'TestsHandler: post-delete tuple purge failed; reconciler will retry',
                            ['object' => "{$scopeType}:{$scopeId}", 'error' => $e->getMessage()]
                        );
                    } catch (\Throwable) {
                        // Logging is best-effort too; a logger/audit failure must never
                        // fail a DELETE whose file has already been removed.
                    }
                }
            }
        }

        // RFC 9110 Section 9.3.5: "a 200 (OK) status code if the action has been enacted
        // and the response message includes a representation describing the status."
        // We use 200 (not 204) because we include a success message in the response body.
        // 204 No Content cannot have content per RFC 9110 Section 15.3.5. Mirrors
        // RegionalDataHandler::deleteCalendar()'s tail exactly: in queue mode `commit()`
        // never returns 'applied' (not even when auto-approved), so a bare 204 here would
        // tell the client "done, nothing to say" while the test file is still fully present
        // on disk pending review — silently discarding the `disposition` signal
        // SourceDataWriter exists to provide.
        $responseObj          = new \stdClass();
        $responseObj->success = "Unit Test {$testName} deletion successful.";
        foreach ($changeRequest as $crKey => $crValue) {
            $responseObj->{$crKey} = $crValue;
        }
        return $this->encodeResponseBody($response, $responseObj, StatusCode::OK);
    }

    /**
     * Handles PUT requests for creating a specific test at /tests/{rite}/{test_name}.
     *
     * The test name in the request path is authoritative; the payload's `name`
     * must match it. The payload is validated against the LitCalTest JSON schema
     * (**400** on failure — {@see ValidationException}; the 422s on this path come
     * from the semantic guards afterwards: name mismatch, rite disagreement, and a
     * `scope` that contradicts the resolved one). If a test with the same name
     * already exists a 409 Conflict is returned. On success the test is written to
     * disk and a 201 Created response is returned.
     */
    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) !== 1) {
            $description = 'Expected one and only one path param for PUT requests, received ' . count($this->requestPathParams) . '.';
            throw new ValidationException($description);
        }

        $testName = $this->requestPathParams[0];
        if (false === TestScopeResolver::isSafeName($testName)) {
            $description = 'The Unit Test name in the request path may only contain letters, digits, hyphens and underscores.';
            throw new ValidationException($description);
        }

        $this->validatePayloadAgainstTestSchema('create');
        self::sanitizeObjectValues($this->payload);
        $this->assertPayloadRiteMatchesPath();
        $this->assertPayloadScopeAgrees();

        if (false === property_exists($this->payload, 'name') || false === is_string($this->payload->name)) {
            $description = 'The Unit Test you are attempting to create must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        if ($this->payload->name !== $testName) {
            $rite        = $this->rite ?? Rite::default();
            $description = 'You are attempting to create the Unit Test at /tests/' . $rite->value . '/' . $testName . ' with a Unit Test that has the name '
                . $this->payload->name . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $testFilePath = $this->testFilePath($testName);
        if (file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $testName . ' already exists. Did you perhaps mean to use a PATCH request?';
            throw new ConflictException($description);
        }

        $this->stageTestDefinition($testFilePath, ChangeOperation::CREATE);
        $scope = $this->resolvePayloadScope();
        if ($scope === null) {
            // Unreachable in practice: assertPayloadRiteMatchesPath() above already
            // required a valid applies_to.rite, which is all resolveFromPayload()
            // needs. Guarded anyway so a future change to either method fails loud
            // rather than committing an unscoped change request.
            throw new ServiceUnavailableException('Unable to resolve the scope for this Unit Test.');
        }
        $changeRequest = $this->commitStagedFiles($this->changeResourceForTest($scope));

        $responseBody           = new \stdClass();
        $responseBody->response = 'Unit Test ' . $testName . ' created successfully.';
        foreach ($changeRequest as $key => $value) {
            $responseBody->{$key} = $value;
        }
        return $this->encodeResponseBody($response, $responseBody, StatusCode::CREATED);
    }

    /**
     * The path segment and `applies_to.rite` must name the same rite.
     *
     * The directory is the address and `applies_to.rite` is the content. Letting them
     * diverge would file a test under a rite it does not claim, and `TestScopeResolver`
     * reads the content while the route reads the address — so they would authorize and
     * store against different rites.
     */
    private function assertPayloadRiteMatchesPath(): void
    {
        $pathRite = $this->rite ?? Rite::default();

        $payloadRite = null;
        if (
            property_exists($this->payload, 'applies_to')
            && $this->payload->applies_to instanceof \stdClass
            && property_exists($this->payload->applies_to, 'rite')
            && is_string($this->payload->applies_to->rite)
        ) {
            $payloadRite = Rite::tryFrom($this->payload->applies_to->rite);
        }

        if ($payloadRite !== $pathRite) {
            $described   = null === $payloadRite ? 'none' : $payloadRite->value;
            $description = 'You are attempting to write a Unit Test at /tests/' . $pathRite->value
                . '/ whose applies_to.rite is ' . $described . '. The rite in the path and the rite in the body must match.';
            throw new UnprocessableContentException($description);
        }
    }

    /**
     * `scope` is server-computed and read-only, but an *echo* of the correct value is
     * accepted rather than rejected.
     *
     * No legitimate client originates a scope: the field exists precisely so clients stop
     * deriving it. So the only realistic way one reaches a write body is the ordinary
     * load-edit-save cycle handing back what the GET returned. Rejecting that on presence
     * alone would punish the common case while catching nothing that a mismatch check does
     * not — and it is the silent divergence that must be impossible, not the echo (#787).
     *
     * The accepted value is always the scope the payload's own `applies_to` resolves to —
     * the scope the stored file WILL have after this write — never the scope the stored file
     * has *before* it (on PATCH, that would be an already-obsolete value by the time the
     * write completes). PUT and PATCH share this: on create there is no stored file yet, and
     * on update the authorization layer (`OpenFgaAuthorizationMiddleware::
     * forTestScopePayloadTarget()`, #790) has already required `editor` on this same
     * payload-derived scope, so accepting anything else here would be incoherent with what
     * was actually authorized.
     *
     * The property is unset after validation so it is never persisted: the stored file is
     * the source, and `scope` is derived from it.
     */
    private function assertPayloadScopeAgrees(): void
    {
        if (false === property_exists($this->payload, 'scope')) {
            return;
        }

        $fromPayload = $this->resolvePayloadScope();
        $resolved    = $fromPayload === null
            ? null
            : (object) ['object_type' => $fromPayload[0], 'object_id' => $fromPayload[1]];

        $supplied = $this->payload->scope;

        $agrees = $supplied instanceof \stdClass
            && $resolved !== null
            && property_exists($supplied, 'object_type')
            && property_exists($supplied, 'object_id')
            && $supplied->object_type === $resolved->object_type
            && $supplied->object_id === $resolved->object_id;

        if (false === $agrees) {
            $description = 'The `scope` property is computed by the server from `applies_to` and is read-only. '
                . 'The value supplied does not match the scope this test resolves to; omit it, or send back the value the API returned.';
            throw new UnprocessableContentException($description);
        }

        unset($this->payload->scope);
    }

    /**
     * Stages the current payload as a Unit Test JSON file for this request's commit.
     *
     * Does not touch disk itself: writing (or recording a change request) happens
     * once per request in {@see WritesSourceData::commitStagedFiles()}, called by
     * the PUT/PATCH callers of this method after staging.
     */
    private function stageTestDefinition(string $testFilePath, ChangeOperation $operation): void
    {
        $jsonEncodedTest = JsonFormatter::encode($this->payload, false);
        $this->stageFile($testFilePath, $operation, $jsonEncodedTest);
    }

    /**
     * Resolve the FGA scope pair for the current payload's `applies_to`.
     *
     * Used to build the {@see ChangeResource} a create/update commit targets: the
     * scope must be the one the write is landing in. Reading it back from disk via
     * {@see TestScopeResolver::resolve()} would be wrong here — the file does not
     * exist yet on PUT, and still holds the pre-write content on PATCH, since
     * staging is deferred until {@see WritesSourceData::commitStagedFiles()}. This
     * is the same mapping {@see assertPayloadScopeAgrees()} already applies to
     * validate a client-supplied `scope` echo; it is factored out here so both
     * call it once rather than re-deriving it.
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolvePayloadScope(): ?array
    {
        $encodedPayload = json_encode($this->payload);
        if (false === $encodedPayload) {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($encodedPayload, true);

        return ( new TestScopeResolver() )->resolveFromPayload($decoded);
    }

    /**
     * Map a resolved test scope pair onto the {@see ChangeResource} whose
     * administrators review it.
     *
     * `TestScopeResolver` is the authority here, and it does not return the
     * `{rite, objectType, calendarId}` shape a first draft of this method assumed:
     * {@see TestScopeResolver::resolve()} and {@see TestScopeResolver::
     * resolveFromPayload()} both return a flat FGA `[object_type, object_id]` pair.
     * For the two rite-qualified test types (`national_calendar_test`,
     * `diocesan_calendar_test`) the object id is already `<rite>/<calendarId>`
     * (see {@see RiteScopedObjectId::qualify()}), so it is split back apart here
     * and handed to {@see ChangeResource::test()}, which re-qualifies it the same
     * way. For every other type — `rite_calendar_test`, the only one
     * `TestScopeResolver` emits today for an unscoped test, and the legacy
     * `general_roman_calendar_test` it no longer emits but
     * `AccessRequestRepository::VALID_OBJECT_TYPES` still recognises — the id is
     * unqualified and is passed straight through as the calendar id. The legacy
     * type is retired from that allow-list at the #955 prune milestone
     * (`docs/ops/rite-calendar-migration-runbook.md`), once every deployment runs
     * merged code; until then this branch has to keep handling it.
     * `ChangeResource::test()`'s `Rite` parameter only affects the rite-qualified
     * branch, so any `Rite` works there, and the request's own rite is used.
     *
     * @param array{0: string, 1: string} $scope [object_type, object_id], as
     *        returned by {@see TestScopeResolver::resolve()} or
     *        {@see TestScopeResolver::resolveFromPayload()}.
     */
    private function changeResourceForTest(array $scope): ChangeResource
    {
        [$objectType, $objectId] = $scope;

        if (in_array($objectType, ['national_calendar_test', 'diocesan_calendar_test'], true)) {
            $parsed = TestScopeResolver::parseQualifiedId($objectId);
            if (null === $parsed) {
                $description = "Unable to parse the rite-qualified scope '{$objectId}' for object type {$objectType}.";
                throw new ServiceUnavailableException($description);
            }
            [$scopeRite, $calendarId] = $parsed;
            return ChangeResource::test($scopeRite, $objectType, $calendarId);
        }

        return ChangeResource::test($this->rite ?? Rite::default(), $objectType, $objectId);
    }

    /**
     * Handles PATCH requests for updating a specific test at /tests/{rite}/{test_name}.
     *
     * This method expects exactly one path parameter: the name of the test to update. The request body
     * is expected to contain a JSON object which is validated against the LitCalTest JSON schema; if the
     * validation fails, it returns a 400 Bad Request error response ({@see ValidationException}). It returns
     * a 422 Unprocessable Content error instead when the payload is well-formed but semantically wrong: a
     * Unit Test with the given name does not already exist, the `name` in the request body does not match
     * the `test_name` path parameter, or the rite/`scope` guards reject it. If validation succeeds, it
     * attempts to write the JSON object
     * to disk as a file in the tests directory; if the write fails, it returns a 503 Service Unavailable
     * error response. If the write succeeds, it returns a 200 response with a JSON object indicating the
     * resource has been updated.
     */
    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) !== 1) {
            $description = 'Expected one and only one path param for PATCH requests, received ' . count($this->requestPathParams) . '.';
            throw new ValidationException($description);
        }

        $this->validatePayloadAgainstTestSchema('update');
        self::sanitizeObjectValues($this->payload);
        $this->assertPayloadRiteMatchesPath();
        $this->assertPayloadScopeAgrees();

        if (false === property_exists($this->payload, 'name') || false === is_string($this->payload->name)) {
            $description = 'The Unit Test you are attempting to update must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        $testName = $this->payload->name;

        $testFilePath = $this->testFilePath($testName);
        if (false === file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $testName . ' does not exist. Did you perhaps mean to use a PUT request?';
            throw new UnprocessableContentException($description);
        }

        if ($testName !== $this->requestPathParams[0]) {
            $rite        = $this->rite ?? Rite::default();
            $description = 'You are attempting to update the Unit Test at /tests/' . $rite->value . '/' . $this->requestPathParams[0]
                . ' with a Unit Test that has the name ' . $testName . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $this->stageTestDefinition($testFilePath, ChangeOperation::UPDATE);
        $scope = $this->resolvePayloadScope();
        if ($scope === null) {
            // Unreachable in practice: assertPayloadRiteMatchesPath() above already
            // required a valid applies_to.rite, which is all resolveFromPayload()
            // needs. Guarded anyway so a future change to either method fails loud
            // rather than committing an unscoped change request.
            throw new ServiceUnavailableException('Unable to resolve the scope for this Unit Test.');
        }
        $changeRequest = $this->commitStagedFiles($this->changeResourceForTest($scope));

        $responseBody           = new \stdClass();
        $responseBody->response = 'Unit Test ' . $testName . ' updated successfully.';
        foreach ($changeRequest as $key => $value) {
            $responseBody->{$key} = $value;
        }
        return $this->encodeResponseBody($response, $responseBody);
    }

    /**
     * Initializes the Tests class.
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - Set the request path parts
     *
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Capture the authenticated identity for change request authorship, the same
        // way DecreesHandler::handle() does.
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

        // For all other request methods, validate that they are supported by the endpoint
        $this->validateRequestMethod($request);

        // A test is addressed as /tests/{rite}/{name}. The bare /tests/{name} form is
        // gone: names are only unique within a rite now, so a bare name does not identify
        // a test. Bare /tests (no path params) remains the corpus-wide index.
        if ($this->rite === null && count($this->requestPathParams) > 0) {
            $description = 'A Unit Test is addressed as /tests/{rite}/{name}, where {rite} is one of: '
                . implode(', ', array_column(Rite::cases(), 'value'))
                . '. Received /tests/' . implode('/', $this->requestPathParams) . ' with no rite segment.';
            throw new ValidationException($description);
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

        switch ($method) {
            case RequestMethod::GET:
                return $this->handleGetRequest($response);
                // no break needed
            case RequestMethod::PUT:
                $payload = $this->parseBodyPayload($request, false);
                if (false === $payload instanceof \stdClass) {
                    $description = 'The Unit Test you are attempting to create must be an object. Received ' . gettype($payload) . '.';
                    throw new UnprocessableContentException($description);
                }
                $this->payload = $payload;
                return $this->handlePutRequest($response);
                // no break needed
            case RequestMethod::PATCH:
                $payload = $this->parseBodyPayload($request, false);
                if (false === $payload instanceof \stdClass) {
                    $description = 'The Unit Test you are attempting to create must be an object. Received ' . gettype($payload) . '.';
                    throw new UnprocessableContentException($description);
                }
                $this->payload = $payload;
                return $this->handlePatchRequest($response);
                // no break needed
            case RequestMethod::DELETE:
                return $this->handleDeleteRequest($response);
                // no break needed
            default:
                throw new MethodNotAllowedException();
        }
    }
}
