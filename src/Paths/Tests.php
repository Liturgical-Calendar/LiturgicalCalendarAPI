<?php

namespace LiturgicalCalendar\Api\Paths;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\AcceptHeader;

class Tests
{
    public static Core $Core;
    /** @var string[] */ private static array $requestPathParts = [];
    /** @var string[] */ private static array $propsToSanitize  = [
        "description",
        "applies_to",
        "excludes",
        "assertions",
        "national_calendar",
        "diocesan_calendar",
        "national_calendars",
        "diocesan_calendars",
        "assertion",
        "comment"
    ];

    private static function sanitizeString(string $str): string
    {
        return htmlspecialchars(strip_tags($str));
    }

    private static function sanitizeObjectValues(object &$data): void
    {
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

    private static function handleGetRequest(): string
    {
        $testsFolder = 'jsondata/tests/';
        if (count(self::$requestPathParts) === 0) {
            try {
                $response  = new \stdClass();
                $testSuite = [];
                $it        = new \DirectoryIterator("glob://{$testsFolder}*Test.json");
                foreach ($it as $f) {
                    $fileName     = $f->getFilename();
                    $testContents = file_get_contents("{$testsFolder}$fileName");
                    $testSuite[]  = json_decode($testContents, true);
                }
                $response->litcal_tests = $testSuite;
                return json_encode($response, JSON_PRETTY_PRINT);
            } catch (\UnexpectedValueException $e) {
                return self::produceErrorResponse(StatusCode::NOT_FOUND, "Tests folder path cannot be opened: " . $e->getMessage());
            }
        } elseif (count(self::$requestPathParts) > 1) {
            return self::produceErrorResponse(StatusCode::BAD_REQUEST, "Too many path parameters, only one is expected");
        } else {
            $testFile = array_shift(self::$requestPathParts);
            if (file_exists("{$testsFolder}{$testFile}.json")) {
                $testContents = file_get_contents("{$testsFolder}{$testFile}.json");
                return $testContents;
            } else {
                return self::produceErrorResponse(StatusCode::NOT_FOUND, "Test {$testFile} not found");
            }
        }
    }

    private static function handleDeleteRequest(): string
    {
        $testsFolder = 'jsondata/tests/';
        if (count(self::$requestPathParts) === 1) {
            $testName = self::$requestPathParts[0];
            if (file_exists("{$testsFolder}{$testName}.json")) {
                if (unlink("{$testsFolder}{$testName}.json")) {
                    $message           = new \stdClass();
                    $message->status   = "OK";
                    $message->response = "Resource Deleted";
                    return json_encode($message, JSON_PRETTY_PRINT);
                } else {
                    return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "For some reason the server did not succeed in deleting the Test $testName");
                }
            } else {
                return self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find test to delete {$testName}");
            }
        } else {
            return self::produceErrorResponse(StatusCode::BAD_REQUEST, "Cannot process a DELETE request without one and only one path parameter containing the name of the Test to delete");
        }
    }

    private static function handlePutRequest(): string
    {
        if (count(self::$requestPathParts)) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "Path parameters not acceptable, please use the base path `/tests` for PUT or PATCH requests");
        }
        $json = file_get_contents('php://input');
        $data = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "The Unit Test you are attempting to create was not valid JSON:" . json_last_error_msg());
        }

        // Validate incoming data against unit test schema
        $schemaFile     = 'jsondata/schemas/LitCalTest.json';
        $schemaContents = file_get_contents($schemaFile);
        $jsonSchema     = json_decode($schemaContents);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The server errored out while attempting to process your request; this a server error, not an error in the request. Please report the incident to the system administrator:" . json_last_error_msg());
        }

        try {
            $schema = Schema::import($jsonSchema);
            $schema->in($data);
        } catch (InvalidValue | \Exception $e) {
            return self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "The Unit Test you are attempting to create was incorrectly validated against schema " . $schemaFile . ": " . $e->getMessage());
        }

        // Sanitize data to avoid any possibility of script injection
        self::sanitizeObjectValues($data);

        $bytesWritten = file_put_contents('jsondata/tests/' . $data->name . '.json', json_encode($data, JSON_PRETTY_PRINT));
        if (false === $bytesWritten) {
            return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.");
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
            $message           = new \stdClass();
            $message->status   = "OK";
            $message->response = self::$Core->getRequestMethod() === RequestMethod::PUT ? "Resource Created" : "Resource Updated";
            return json_encode($message, JSON_PRETTY_PRINT);
        }
    }

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
                $response = self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, "The method " . $_SERVER['REQUEST_METHOD'] . " cannot be handled by this endpoint");
        }
        self::produceResponse($response);
    }

    private static function produceErrorResponse(int $statusCode, string $description): string
    {
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message         = new \stdClass();
        $message->status = "ERROR";
        $statusMessage   = "";
        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::PUT:
                $statusMessage = "Resource not Created";
                break;
            case RequestMethod::PATCH:
                $statusMessage = "Resource not Updated";
                break;
            case RequestMethod::DELETE:
                $statusMessage = "Resource not Deleted";
                break;
            default:
                $statusMessage = "Sorry what was it you wanted to do with this resource?";
        }
        $message->response    = $statusCode === 404 ? "Resource not Found" : $statusMessage;
        $message->description = $description;
        return json_encode($message);
    }

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
