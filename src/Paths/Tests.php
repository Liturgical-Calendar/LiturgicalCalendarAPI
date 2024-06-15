<?php

namespace Johnrdorazio\LitCal\Paths;

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;
use Johnrdorazio\LitCal\APICore;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Enum\RequestMethod;
use Johnrdorazio\LitCal\Enum\AcceptHeader;

class Tests
{
    public static APICore $APICore;
    private static array $requestPathParts = [];
    private static array $propsToSanitize = [
        "description",
        "appliesTo",
        "excludes",
        "assertions",
        "nationalcalendar",
        "diocesancalendar",
        "nationalcalendars",
        "diocesancalendars",
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
        $testsFolder = 'tests/';
        if (count(self::$requestPathParts) === 0) {
            try {
                $testSuite = [];
                $it = new \DirectoryIterator("glob://{$testsFolder}*Test.json");
                foreach ($it as $f) {
                    $fileName       = $f->getFilename();
                    $testContents   = file_get_contents("{$testsFolder}$fileName");
                    $testSuite[]    = json_decode($testContents, true);
                }
                return json_encode($testSuite, JSON_PRETTY_PRINT);
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
        $testsFolder = 'tests/';
        if (count(self::$requestPathParts) === 1) {
            $testName = self::$requestPathParts[0];
            if (file_exists("{$testsFolder}{$testName}.json")) {
                if (unlink("{$testsFolder}{$testName}.json")) {
                    $message = new \stdClass();
                    $message->status = "OK";
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
        $schemaFile = 'schemas/LitCalTest.json';
        $schemaContents = file_get_contents($schemaFile);
        $jsonSchema = json_decode($schemaContents);

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

        $bytesWritten = file_put_contents('tests/' . $data->name . '.json', json_encode($data, JSON_PRETTY_PRINT));
        if (false === $bytesWritten) {
            return self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.");
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 201 Created", true, 201);
            $message = new \stdClass();
            $message->status = "OK";
            $message->response = self::$APICore->getRequestMethod() === RequestMethod::PUT ? "Resource Created" : "Resource Updated";
            return json_encode($message, JSON_PRETTY_PRINT);
        }
    }

    public static function handleRequest(): void
    {
        self::$APICore->init();
        self::$APICore->validateAcceptHeader(true);
        self::$APICore->setResponseContentTypeHeader();
        $response = '';
        switch (self::$APICore->getRequestMethod()) {
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
                // nothing to do here, should be handled by APICore
                break;
            default:
                $response = self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, "The method " . $_SERVER['REQUEST_METHOD'] . " cannot be handled by this endpoint");
        }
        self::produceResponse($response);
    }

    private static function produceErrorResponse(int $statusCode, string $description): string
    {
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message = new \stdClass();
        $message->status = "ERROR";
        $statusMessage = "";
        switch (self::$APICore->getRequestMethod()) {
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
        $message->response = $statusCode === 404 ? "Resource not Found" : $statusMessage;
        $message->description = $description;
        return json_encode($message);
    }

    private static function produceResponse(string $response): void
    {
        switch (self::$APICore->getResponseContentType()) {
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

    public static function init(array $requestPathParts = []): void
    {
        self::$APICore = new APICore();
        self::$requestPathParts = $requestPathParts;
    }
}
