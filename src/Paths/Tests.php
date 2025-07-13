<?php

namespace LiturgicalCalendar\Api\Paths;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\JsonData;

class Tests
{
    public static Core $Core;
    /** @var string[] */ private static array $requestPathParts = [];
    /** @var string[] */ private static array $propsToSanitize  = [
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

    /**
     * Sanitizes a given string by removing all HTML tags and converting special characters to HTML entities.
     * @param string $str The string to be sanitized.
     * @return string The sanitized string.
     */
    private static function sanitizeString(string $str): string
    {
        return htmlspecialchars(strip_tags($str));
    }

    /**
     * Recursively sanitizes the values of an object's properties that are specified in the $propsToSanitize array.
     * If a property value is an object, the function calls itself recursively to sanitize the nested object's properties.
     * If a property value is an array, it iterates over the array and sanitizes each element.
     * If a property value is a string, it sanitizes the string by removing HTML tags and converting special characters to HTML entities.
     *
     * @param object $data The object whose properties need to be sanitized. Passed as reference to allow recursive calls.
     * @return void
     */
    private static function sanitizeObjectValues(object &$data): void
    {
        /** @phpstan-ignore foreach.nonIterable */
        foreach ($data as $prop => $value) {
            if (in_array($prop, self::$propsToSanitize)) {
                if (is_object($value)) {
                    self::sanitizeObjectValues($data->{$prop});
                } elseif (is_array($value)) {
                    foreach ($value as $idx => $item) {
                        if (is_object($item)) {
                            self::sanitizeObjectValues($data->{$prop}[ $idx ]);
                        } elseif (is_array($item)) {
                            foreach ($item as $idx2 => $item2) {
                                $data->{$prop}[ $idx ][ $idx2 ] = self::sanitizeString($item2);
                            }
                        } elseif (is_string($item)) {
                            $data->{$prop}[ $idx ] = self::sanitizeString($item);
                        }
                    }
                } elseif (is_string($value)) {
                    $data->{$prop} = self::sanitizeString($value);
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
     *
     * @return string The response body, which is either the JSON index of tests or the contents of a specific test file.
     */
    private static function handleGetRequest(): string
    {
        if (count(self::$requestPathParts) === 0) {
            try {
                $response  = new \stdClass();
                $testSuite = [];
                $it        = new \DirectoryIterator('glob://' . JsonData::TESTS_FOLDER . '/*Test.json');
                foreach ($it as $f) {
                    $fileName     = $f->getFilename();
                    $testContents = file_get_contents(JsonData::TESTS_FOLDER . "/$fileName");
                    if ($testContents === false) {
                        return self::produceErrorResponse(StatusCode::NOT_FOUND, "Test {$fileName} was not readable");
                    }
                    $testSuite[] = json_decode($testContents, true);
                }
                $response->litcal_tests = $testSuite;
                $responseJsonStr        = json_encode($response, JSON_PRETTY_PRINT);
                if ($responseJsonStr === false) {
                    return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The Unit Tests you are attempting to retrieve could not be processed as valid JSON: ' . json_last_error_msg());
                }
                return $responseJsonStr;
            } catch (\UnexpectedValueException $e) {
                return self::produceErrorResponse(StatusCode::NOT_FOUND, 'Tests folder path cannot be opened: ' . $e->getMessage());
            }
        } elseif (count(self::$requestPathParts) > 1) {
            return self::produceErrorResponse(StatusCode::BAD_REQUEST, 'Too many path parameters, only one is expected');
        } else {
            $testFile = array_shift(self::$requestPathParts);
            if (file_exists(JsonData::TESTS_FOLDER . "/{$testFile}.json")) {
                $testContents = file_get_contents(JsonData::TESTS_FOLDER . "/{$testFile}.json");
                if ($testContents === false) {
                    return self::produceErrorResponse(StatusCode::NOT_FOUND, "Test {$testFile} was not readable");
                }
                return $testContents;
            } else {
                return self::produceErrorResponse(StatusCode::NOT_FOUND, "Test {$testFile} not found");
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
     *
     * @return string JSON response indicating the result of the delete operation.
     */
    private static function handleDeleteRequest(): string
    {
        if (count(self::$requestPathParts) === 1) {
            $testName = self::$requestPathParts[0];
            if (file_exists(JsonData::TESTS_FOLDER . "/{$testName}.json")) {
                if (unlink(JsonData::TESTS_FOLDER . "/{$testName}.json")) {
                    $message           = new \stdClass();
                    $message->status   = 'OK';
                    $message->response = 'Resource Deleted';
                    $messageJsonStr    = json_encode($message, JSON_PRETTY_PRINT);
                    if ($messageJsonStr === false) {
                        return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The response that the API is attempting to send could not be processed as valid JSON: ' . json_last_error_msg());
                    }
                    return $messageJsonStr;
                } else {
                    return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "For some reason the server did not succeed in deleting the Test $testName");
                }
            } else {
                return self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find test to delete {$testName}");
            }
        } else {
            return self::produceErrorResponse(StatusCode::BAD_REQUEST, 'Cannot process a DELETE request without one and only one path parameter containing the name of the Test to delete');
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
     *
     * @return string JSON response indicating the result of the create or update operation.
     */
    private static function handlePutRequest(): string
    {
        if (count(self::$requestPathParts)) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'Path parameters not acceptable, please use the base path `/tests` for PUT or PATCH requests');
        }
        $json = file_get_contents('php://input');
        if ($json === false) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'Could not read the body of the request.');
        }
        $data = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The Unit Test you are attempting to create was not valid JSON:' . json_last_error_msg());
        }

        // Validate incoming data against unit test schema
        $schemaFile     = JsonData::SCHEMAS_FOLDER . '/LitCalTest.json';
        $schemaContents = file_get_contents($schemaFile);
        if ($schemaContents === false) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The Unit Test schema was not readable: ' . $schemaFile);
        }
        $jsonSchema = json_decode($schemaContents);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'The server errored out while attempting to process your request; this a server error, not an error in the request. Please report the incident to the system administrator:' . json_last_error_msg());
        }

        try {
            $schema = Schema::import($jsonSchema);
            $schema->in($data);
        } catch (InvalidValue | \Exception $e) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The Unit Test you are attempting to create was incorrectly validated against schema ' . $schemaFile . ': ' . $e->getMessage());
        }

        // Sanitize data to avoid any possibility of script injection
        self::sanitizeObjectValues($data);

        /** @var object{name:string} $data */
        $bytesWritten = file_put_contents(JsonData::TESTS_FOLDER . '/' . $data->name . '.json', json_encode($data, JSON_PRETTY_PRINT));
        if (false === $bytesWritten) {
            return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.');
        } else {
            header($_SERVER[ 'SERVER_PROTOCOL' ] . ' 201 Created', true, 201);
            $message           = new \stdClass();
            $message->status   = 'OK';
            $message->response = self::$Core->getRequestMethod() === RequestMethod::PUT ? 'Resource Created' : 'Resource Updated';
            $messageJsonStr    = json_encode($message, JSON_PRETTY_PRINT);
            if ($messageJsonStr === false) {
                return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The response that the API is attempting to send could not be processed as valid JSON: ' . json_last_error_msg());
            }
            return $messageJsonStr;
        }
    }

    /**
     * Handles the request for this endpoint.
     *
     * Depending on the request method, one of the following methods will be called to handle the request:
     * - GET: LiturgicalCalendar\Api\Paths.Tests.handleGetRequest
     * - PUT|PATCH: LiturgicalCalendar\Api\Paths.Tests.handlePutRequest
     * - DELETE: LiturgicalCalendar\Api\Paths.Tests.handleDeleteRequest
     * - OPTIONS: handled by the LiturgicalCalendar\Core class
     * - any other method: will return a 405 Method Not Allowed status code
     *
     * @throws \Exception
     */
    public static function handleRequest(): void
    {
        self::$Core->init();
        self::$Core->validateAcceptHeader(true);
        self::$Core->setResponseContentTypeHeader();
        $response = '';
        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::GET:
                $response = self::handleGetRequest();
                break;
            case RequestMethod::PUT:
                $response = self::handlePutRequest();
                break;
            case RequestMethod::PATCH:
                $response = self::handlePutRequest();
                break;
            case RequestMethod::DELETE:
                $response = self::handleDeleteRequest();
                break;
            case RequestMethod::OPTIONS:
                // nothing to do here, should be handled by Core
                break;
            default:
                $response = self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, 'The method ' . $_SERVER['REQUEST_METHOD'] . ' cannot be handled by this endpoint');
        }
        self::produceResponse($response);
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
     * @return string the error response as a JSON or YAML encoded string
     */
    private static function produceErrorResponse(int $statusCode, string $description): string
    {
        header($_SERVER[ 'SERVER_PROTOCOL' ] . StatusCode::toString($statusCode), true, $statusCode);
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
                $statusMessage = 'Sorry what was it you wanted to do with this resource?';
        }
        $message->response    = $statusCode === 404 ? 'Resource not Found' : $statusMessage;
        $message->description = $description;
        $messageJsonStr       = json_encode($message, JSON_PRETTY_PRINT);
        if ($messageJsonStr === false) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'The response that the API is attempting to send could not be processed as valid JSON: ' . json_last_error_msg());
        }
        return $messageJsonStr;
    }

    /**
     * Outputs the response for the /tests endpoint.
     *
     * Outputs the response as either JSON or YAML, depending on the value of
     * self::$Core->getResponseContentType(). If the request method was PUT or
     * PATCH, it also sets a 201 Created status code.
     *
     * @param string $response the response as a JSON encoded string
     *
     * @return void
     */
    private static function produceResponse(string $response): void
    {
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $responseObj = json_decode($response, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $response;
                break;
        }
    }

    /**
     * Initializes the Tests class.
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - Set the request path parts
     *
     * @param string[] $requestPathParts the path parameters from the request
     */
    public static function init(array $requestPathParts = []): void
    {
        self::$Core             = new Core();
        self::$requestPathParts = $requestPathParts;
    }
}
