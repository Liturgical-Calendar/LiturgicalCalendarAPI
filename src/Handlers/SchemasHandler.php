<?php

/**
 * Output requested JSON schema resource
 *
 * @author    John Romano D'Orazio <priest@johnromanodorazio.com>
 * @copyright 2024 John Romano D'Orazio
 * @license   https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @link      https://litcal.johnromanodorazio.com
 */

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\Stream;

final class SchemasHandler extends AbstractHandler
{
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
    }


    /**
     * Initializes the SchemasHandler class.
     *
     * This function is intended to be called from the Router class.
     *
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

        $pathParamCount = count($this->requestPathParams);
        switch ($pathParamCount) {
            case 0:
                $schemaIndex                 = new \stdClass();
                $schemaIndex->litcal_schemas = [];
                $it                          = new \DirectoryIterator('glob://' . JsonData::SCHEMAS_FOLDER->path() . '/*.json');
                foreach ($it as $f) {
                    $schemaIndex->litcal_schemas[] = Route::SCHEMAS->path() . '/' . $f->getFilename();
                }
                return $this->encodeResponseBody($response, $schemaIndex);
                break;
            case 1:
                if (file_exists(JsonData::SCHEMAS_FOLDER->path() . '/' . $this->requestPathParams[0])) {
                    $schema = file_get_contents(JsonData::SCHEMAS_FOLDER->path() . '/' . $this->requestPathParams[0]);
                    if ($schema === false) {
                        throw new ServiceUnavailableException("Schema file '{$this->requestPathParams[0]}' not readable");
                    }
                    // No use in json_decoding if we don't need to
                    if ($response->getHeaderLine('Content-Type') === AcceptHeader::JSON->value) {
                        return $response
                            ->withStatus(StatusCode::OK->value, StatusCode::OK->reason())
                            ->withBody(Stream::create($schema));
                    } else {
                        $schemaObj = json_decode($schema, false, 512, JSON_THROW_ON_ERROR);
                        return $this->encodeResponseBody($response, $schemaObj);
                    }
                } else {
                    throw new NotFoundException("Schema file '{$this->requestPathParams[0]}' not found");
                }
                break;
            default:
                throw new ValidationException('Invalid number of path parameters, expected at most 1, received ' . $pathParamCount);
        }
    }
}
