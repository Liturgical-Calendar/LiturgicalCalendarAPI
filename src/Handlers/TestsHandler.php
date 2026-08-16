<?php

namespace LiturgicalCalendar\Api\Handlers;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesOutboxTooling;
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
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class TestsHandler extends AbstractHandler
{
    use ResolvesOutboxTooling;

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
        // so unsafe names like "../foo" can never reach unlink().
        $scope = ( new TestScopeResolver() )->resolve($this->rite ?? Rite::default(), $testName);
        if ($scope === null) {
            throw new NotFoundException("Test {$testName} not found, cannot DELETE.");
        }

        if (false === unlink($this->testFilePath($testName))) {
            throw new ServiceUnavailableException("Test {$testName} could not be deleted");
        }

        // Best-effort purge of operational (editor/viewer) tuples orphaned by the
        // deletion. The file is already gone, so an OpenFGA/outbox error must NOT
        // fail the DELETE — the reconciler sweep cleans up any stragglers.
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

        return $response->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason());
    }

    /**
     * Handles PUT requests for creating a specific test at /tests/{test_name}.
     *
     * The test name in the request path is authoritative; the payload's `name`
     * must match it. The payload is validated against the LitCalTest JSON schema
     * (422 on failure). If a test with the same name already exists a 409 Conflict
     * is returned. On success the test is written to disk and a 201 Created
     * response is returned.
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
            $description = 'You are attempting to create the Unit Test at /tests/' . $testName . ' with a Unit Test that has the name '
                . $this->payload->name . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $testFilePath = $this->testFilePath($testName);
        if (file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $testName . ' already exists. Did you perhaps mean to use a PATCH request?';
            throw new ConflictException($description);
        }

        $this->writeTestToDisk($testFilePath);

        $responseBody = (object) ['response' => 'Unit Test ' . $testName . ' created successfully.'];
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
     * The property is unset after validation so it is never persisted: the stored file is
     * the source, and `scope` is derived from it.
     */
    private function assertPayloadScopeAgrees(): void
    {
        if (false === property_exists($this->payload, 'scope')) {
            return;
        }

        // `name` is required by the JSON schema, which has already validated this payload
        // by the time this method runs (called right after assertPayloadRiteMatchesPath()).
        // Narrowing it here (rather than trusting that) keeps this method sound on its own.
        $name = property_exists($this->payload, 'name') && is_string($this->payload->name)
            ? $this->payload->name
            : null;

        $resolved = $name === null ? null : $this->scopeObjectFor($this->rite ?? Rite::default(), $name);
        $supplied = $this->payload->scope;

        // On create the test does not exist yet, so resolve the scope the stored file WILL
        // have, from the payload's own applies_to — the same mapping resolve() would apply.
        if ($resolved === null) {
            $encodedPayload = json_encode($this->payload);
            $fromPayload    = $encodedPayload === false
                ? null
                : ( new TestScopeResolver() )->resolveFromPayload(json_decode($encodedPayload, true));
            $resolved       = $fromPayload === null
                ? null
                : (object) ['object_type' => $fromPayload[0], 'object_id' => $fromPayload[1]];
        }

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
     * Writes the current payload to disk as a Unit Test JSON file.
     *
     * @throws ServiceUnavailableException When the write to disk fails
     */
    private function writeTestToDisk(string $testFilePath): void
    {
        $jsonEncodedTest = JsonFormatter::encode($this->payload, false);
        $bytesWritten    = file_put_contents($testFilePath, $jsonEncodedTest);
        if (false === $bytesWritten) {
            $description = 'The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.';
            throw new ServiceUnavailableException($description);
        }
    }

    /**
     * Handles PATCH requests for updating a specific test at /tests/{test_name}.
     *
     * This method expects exactly one path parameter: the name of the test to update. The request body
     * is expected to contain a JSON object which is validated against the LitCalTest JSON schema; if the
     * validation fails, it returns a 422 Unprocessable Content error response. It also returns a 422 error
     * if a Unit Test with the given name does not already exist, or if the `name` in the request body does
     * not match the `test_name` path parameter. If validation succeeds, it attempts to write the JSON object
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
            $description = 'You are attempting to update the Unit Test at /tests/' . $this->requestPathParams[0] . ' with a Unit Test that has the name ' . $testName . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $this->writeTestToDisk($testFilePath);

        $responseBody = (object) ['response' => 'Unit Test ' . $testName . ' updated successfully.'];
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
