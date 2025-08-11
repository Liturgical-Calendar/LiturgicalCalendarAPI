<?php

/**
 * Output requested JSON schema resource
 *
 * @author    John Romano D'Orazio <priest@johnromanodorazio.com>
 * @copyright 2024 John Romano D'Orazio
 * @license   https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @link      https://litcal.johnromanodorazio.com
 */

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\StatusCode;

final class SchemasPath
{
    public Core $Core;

    public function __construct()
    {
        $this->Core = new Core();
    }


    /**
     * Retrieves JSON schema resources based on the provided request path parts.
     *
     * This function enforces the origin and request method headers before proceeding.
     * Depending on the number of path parameters provided, it either returns an index
     * of available JSON schema files or the contents of a specified schema file.
     *
     * @param string[] $requestPathParts An array of path parts derived from the request URI.
     *                                   If empty, the function returns an index of all schemas.
     *                                   If containing one element, it attempts to return the
     *                                   specified schema file's content.
     *
     * @return never Outputs the JSON schema index or the contents of a specific schema.
     *               If the schema file is not found, it responds with a 404 error.
     */
    public function produceResponse(array $requestPathParts = []): never
    {
        $pathParamCount = count($requestPathParts);
        switch ($pathParamCount) {
            case 0:
                $schemaIndex                 = new \stdClass();
                $schemaIndex->litcal_schemas = [];
                $it                          = new \DirectoryIterator('glob://' . JsonData::SCHEMAS_FOLDER . '/*.json');
                foreach ($it as $f) {
                    $schemaIndex->litcal_schemas[] = API_BASE_PATH . Route::SCHEMAS->value . '/' . $f->getFilename();
                }
                $response = json_encode($schemaIndex);
                if ($response === false) {
                    $this->produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'Failed to encode schema index to JSON');
                }
                break;
            case 1:
                if (file_exists(JsonData::SCHEMAS_FOLDER . '/' . $requestPathParts[0])) {
                    $response = file_get_contents(JsonData::SCHEMAS_FOLDER . '/' . $requestPathParts[0]);
                    if ($response === false) {
                        $this->produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "Schema file '{$requestPathParts[0]}' not readable");
                    }
                } else {
                    $this->produceErrorResponse(StatusCode::NOT_FOUND, "Schema file '{$requestPathParts[0]}' not found");
                }
                break;
            default:
                $this->produceErrorResponse(StatusCode::BAD_REQUEST, 'Invalid number of path parameters, expected at most 1, received ' . $pathParamCount);
        }

        switch ($this->Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                if (!extension_loaded('yaml')) {
                    $this->produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'YAML extension not loaded');
                }
                $responseObj = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->produceErrorResponse(
                        StatusCode::SERVICE_UNAVAILABLE,
                        'Failed to decode JSON: ' . json_last_error_msg()
                    );
                }
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $response;
        }
        die();
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
     * @return never
     */
    public function produceErrorResponse(int $statusCode, string $description): never
    {
        $serverProtocol = isset($_SERVER['SERVER_PROTOCOL']) && is_string($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1 ';
        header($serverProtocol . StatusCode::toString($statusCode), true, $statusCode);
        $message              = new \stdClass();
        $message->status      = 'ERROR';
        $message->response    = $statusCode === 404 ? 'Resource not Found' : 'Resource unavailable';
        $message->description = $description;
        $response             = json_encode($message);
        if ($response === false) {
            $response = '{"status":"ERROR","response":"Internal Server Error","description":"Failed to encode error message to JSON"}';
        }
        switch ($this->Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                if (!extension_loaded('yaml')) {
                    $this->produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, 'YAML extension not loaded');
                }
                $responseObj = json_decode($response, true);
                echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                echo $response;
        }
        die();
    }

    /**
     * Initializes the SchemasPath class.
     *
     * This function is intended to be called from the Router class.
     *
     * @param string[] $requestPathParts An array of path parts derived from the request URI.
     *                                   If empty, the function returns an index of all schemas.
     *                                   If containing one element, it attempts to return the
     *                                   specified schema file's content.
     *
     * @return never Outputs the JSON schema index or the contents of a specific schema.
     *               If the schema file is not found, it responds with a 404 error.
     */
    public function init(array $requestPathParts = []): never
    {
        $this->Core->init();
        if ($this->Core->getRequestMethod() === RequestMethod::OPTIONS) {
            die();
        }
        if ($this->Core->getRequestMethod() === RequestMethod::GET) {
            $this->Core->validateAcceptHeader(true);
        } else {
            $this->Core->validateAcceptHeader(false);
        }
        $this->Core->setResponseContentTypeHeader();
        if (false === in_array($this->Core->getRequestMethod(), $this->Core->getAllowedRequestMethods())) {
            $description = 'Allowed Request Methods are '
                . implode(' and ', array_column($this->Core->getAllowedRequestMethods(), 'value'))
                . ', but your Request Method was '
                . $this->Core->getRequestMethod()->value;
            self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, $description);
        }

        $this->produceResponse($requestPathParts);
    }
}
