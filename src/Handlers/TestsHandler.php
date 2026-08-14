<?php

namespace LiturgicalCalendar\Api\Handlers;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesOutboxTooling;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\JsonFormatter;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
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
use Nyholm\Psr7\Stream;

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

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
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
            try {
                $responseBody = new \stdClass();
                $testSuite    = [];
                $testFiles    = new \DirectoryIterator('glob://' . JsonData::TESTS_FOLDER->path() . '/*Test.json');
                foreach ($testFiles as $f) {
                    $fileName     = $f->getFilename();
                    $testContents = file_get_contents(JsonData::TESTS_FOLDER->path() . "/$fileName");
                    if ($testContents === false) {
                        $description = "Test {$fileName} was not readable";
                        throw new ServiceUnavailableException($description);
                    }
                    $testSuite[] = json_decode($testContents, true, 512, JSON_THROW_ON_ERROR);
                }
                $responseBody->litcal_tests = $testSuite;
                return $this->encodeResponseBody($response, $responseBody);
            } catch (\UnexpectedValueException $e) {
                throw new ServiceUnavailableException(
                    $description = 'Tests folder path cannot be opened: ' . $e->getMessage(),
                    $e
                );
            }
        } elseif (count($this->requestPathParams) > 1) {
            $description = 'Expected one path param for GET requests, received ' . count($this->requestPathParams);
            throw new ValidationException($description);
        } else {
            $testFile = array_shift($this->requestPathParams);
            if (file_exists(JsonData::TESTS_FOLDER->path() . "/{$testFile}.json")) {
                $testContents = file_get_contents(JsonData::TESTS_FOLDER->path() . "/{$testFile}.json");
                if ($testContents === false) {
                    $description = "Test {$testFile} was not readable";
                    throw new ServiceUnavailableException($description);
                }
                if ($response->getHeaderLine('Content-Type') === AcceptHeader::JSON->value) {
                    return $response
                        ->withStatus(StatusCode::OK->value, StatusCode::OK->reason())
                        ->withBody(Stream::create($testContents));
                } else {
                    $decodedContents = json_decode($testContents, false, 512, JSON_THROW_ON_ERROR);
                    if (false === ( $decodedContents instanceof \stdClass )) {
                        throw new ServiceUnavailableException("Failed to decode test {$testFile} as JSON");
                    }
                    return $this->encodeResponseBody($response, $decodedContents);
                }
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
        $scope = ( new TestScopeResolver() )->resolve($testName);
        if ($scope === null) {
            throw new NotFoundException("Test {$testName} not found, cannot DELETE.");
        }

        if (false === unlink(JsonData::TESTS_FOLDER->path() . "/{$testName}.json")) {
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

        if (false === property_exists($this->payload, 'name') || false === is_string($this->payload->name)) {
            $description = 'The Unit Test you are attempting to create must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        if ($this->payload->name !== $testName) {
            $description = 'You are attempting to create the Unit Test at /tests/' . $testName . ' with a Unit Test that has the name '
                . $this->payload->name . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $testFilePath = JsonData::TESTS_FOLDER->path() . '/' . $testName . '.json';
        if (file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $testName . ' already exists. Did you perhaps mean to use a PATCH request?';
            throw new ConflictException($description);
        }

        $this->writeTestToDisk($testFilePath);

        $responseBody = (object) ['response' => 'Unit Test ' . $testName . ' created successfully.'];
        return $this->encodeResponseBody($response, $responseBody, StatusCode::CREATED);
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

        if (false === property_exists($this->payload, 'name') || false === is_string($this->payload->name)) {
            $description = 'The Unit Test you are attempting to update must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        $testName = $this->payload->name;

        $testFilePath = JsonData::TESTS_FOLDER->path() . '/' . $testName . '.json';
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
