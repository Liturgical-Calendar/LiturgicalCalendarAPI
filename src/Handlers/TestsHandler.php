<?php

namespace LiturgicalCalendar\Api\Handlers;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Stream;

final class TestsHandler extends AbstractHandler
{
    /** @var string[] */
    private static array $propsToSanitize = [
        'description',
        'applies_to',
        'excludes',
        'assertions',
        'national_calendar',
        'diocesan_calendar',
        'national_calendars',
        'diocesan_calendars',
        'assertion',
        'comment'
    ];

    private \stdClass $payload;

    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
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
                    return $this->encodeResponseBody($response, json_decode($testContents, true, 512, JSON_THROW_ON_ERROR));
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
        if (count($this->requestPathParams) === 1) {
            $testName = $this->requestPathParams[0];
            if (file_exists(JsonData::TESTS_FOLDER->path() . "/{$testName}.json")) {
                if (unlink(JsonData::TESTS_FOLDER->path() . "/{$testName}.json")) {
                    return $response->withStatus(StatusCode::NO_CONTENT->value, StatusCode::NO_CONTENT->reason());
                } else {
                    $description = "Test {$testName} could not be deleted";
                    throw new ServiceUnavailableException($description);
                }
            } else {
                $description = "Test {$testName} not found, cannot DELETE.";
                throw new NotFoundException($description);
            }
        } else {
            $description = 'Expected one and only one path param for DELETE requests, received ' . count($this->requestPathParams) . '.';
            throw new ValidationException($description);
        }
    }

    /**
     * Handles PUT requests for creating or updating a specific test.
     *
     * This method expects no path parameters. The request body is expected to contain a JSON object
     * which is validated against the LitCalTest JSON schema. If the validation fails, it returns a 422
     * Unprocessable Content error response. If the validation succeeds, it attempts to write the JSON
     * object to disk as a file in the tests directory. If the write fails, it returns a 503 Service Unavailable
     * error response. If the write succeeds, it returns a 201 Created response with a JSON object indicating
     * the resource has been created or updated.
     */
    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams)) {
            $description = 'Expected no path params for PUT requests, received ' . count($this->requestPathParams) . '. Please use the base /tests endpoint for PUT requests.';
            throw new ValidationException($description);
        }

        // Validate incoming data against unit test schema
        $schemaFile     = JsonData::SCHEMAS_FOLDER->path() . '/LitCalTest.json';
        $schemaContents = Utilities::rawContentsFromFile($schemaFile);
        $jsonSchema     = json_decode($schemaContents, null, 512, JSON_THROW_ON_ERROR);

        try {
            $schema = Schema::import($jsonSchema);
            $schema->in($this->payload);
        } catch (InvalidValue | \Exception $e) {
            $description = 'The Unit Test you are attempting to create was incorrectly validated against schema ' . $schemaFile . ': ' . $e->getMessage();
            throw new ValidationException($description);
        }

        // Sanitize data to avoid any possibility of script injection
        self::sanitizeObjectValues($data);

        if (false === property_exists($data, 'name') || false === is_string($data->name)) {
            $description = 'The Unit Test you are attempting to create must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        $testFilePath = JsonData::TESTS_FOLDER->path() . '/' . $data->name . '.json';
        if (file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $data->name . ' already exists. Did you perhaps mean to use a PATCH request?';
            throw new UnprocessableContentException($description);
        }

        $jsonEncodedTest = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $bytesWritten    = file_put_contents($testFilePath, $jsonEncodedTest);
        if (false === $bytesWritten) {
            $description = 'The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.';
            throw new ServiceUnavailableException($description);
        } else {
            return $response->withStatus(StatusCode::CREATED->value, StatusCode::CREATED->reason());
        }
    }

    /**
     * Handles PUT requests for creating or updating a specific test.
     *
     * This method expects no path parameters. The request body is expected to contain a JSON object
     * which is validated against the LitCalTest JSON schema. If the validation fails, it returns a 422
     * Unprocessable Content error response. If the validation succeeds, it attempts to write the JSON
     * object to disk as a file in the tests directory. If the write fails, it returns a 503 Service Unavailable
     * error response. If the write succeeds, it returns a 201 Created response with a JSON object indicating
     * the resource has been created or updated.
     */
    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) !== 1) {
            $description = 'Expected one and only one path param for PATCH requests, received ' . count($this->requestPathParams) . '.';
            throw new ValidationException($description);
        }

        // Validate incoming data against unit test schema
        $schemaFile     = JsonData::SCHEMAS_FOLDER->path() . '/LitCalTest.json';
        $schemaContents = Utilities::rawContentsFromFile($schemaFile);
        $jsonSchema     = json_decode($schemaContents, null, 512, JSON_THROW_ON_ERROR);

        try {
            $schema = Schema::import($jsonSchema);
            $schema->in($this->payload);
        } catch (InvalidValue | \Exception $e) {
            $description = 'The Unit Test you are attempting to update was incorrectly validated against schema ' . $schemaFile . ': ' . $e->getMessage();
            throw new ValidationException($description);
        }

        // Sanitize data to avoid any possibility of script injection
        self::sanitizeObjectValues($data);

        if (false === property_exists($data, 'name') || false === is_string($data->name)) {
            $description = 'The Unit Test you are attempting to update must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        $testFilePath = JsonData::TESTS_FOLDER->path() . '/' . $data->name . '.json';
        if (false === file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $data->name . ' does not exist. Did you perhaps mean to use a PUT request?';
            throw new UnprocessableContentException($description);
        }

        if ($data->name !== $this->requestPathParams[0]) {
            $description = 'You are attempting to update the Unit Test at /tests/' . $this->requestPathParams[0] . ' with a Unit Test that has the name ' . $data->name . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $jsonEncodedTest = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $bytesWritten    = file_put_contents($testFilePath, $jsonEncodedTest);
        if (false === $bytesWritten) {
            $description = 'The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.';
            throw new ServiceUnavailableException($description);
        } else {
            return $response->withStatus(StatusCode::CREATED->value, StatusCode::CREATED->reason());
        }
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
                break;
            case RequestMethod::PUT:
                $payload = $this->parseBodyPayload($request);
                if (false === $payload instanceof \stdClass) {
                    $description = 'The Unit Test you are attempting to create must be an object. Received ' . gettype($payload) . '.';
                    throw new UnprocessableContentException($description);
                }
                $this->payload = $payload;
                return $this->handlePutRequest($response);
                break;
            case RequestMethod::PATCH:
                $payload = $this->parseBodyPayload($request);
                if (false === $payload instanceof \stdClass) {
                    $description = 'The Unit Test you are attempting to create must be an object. Received ' . gettype($payload) . '.';
                    throw new UnprocessableContentException($description);
                }
                $this->payload = $payload;
                return $this->handlePatchRequest($response);
                break;
            case RequestMethod::DELETE:
                return $this->handleDeleteRequest($response);
                break;
            default:
                throw new MethodNotAllowedException();
        }
    }
}
