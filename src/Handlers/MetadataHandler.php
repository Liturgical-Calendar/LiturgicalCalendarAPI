<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedMediaTypeException;
use LiturgicalCalendar\Api\Http\Exception\YamlException;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\DumpException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MetadataHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Handles requests to the /api/metadata endpoint
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

        $metadataCalendars = CalendarMetadataProvider::create();

        $responseBody = json_encode(['litcal_metadata' => $metadataCalendars], JSON_THROW_ON_ERROR);
        $responseHash = md5($responseBody);
        $etag         = '"' . $responseHash . '"';
        $response     = $response->withHeader('ETag', $etag);

        if (
            $request->getHeaderLine('If-None-Match') !== ''
            && trim($request->getHeaderLine('If-None-Match'), " \t\"") === $responseHash
        ) {
            return $response->withStatus(StatusCode::NOT_MODIFIED->value, StatusCode::NOT_MODIFIED->reason())
                            ->withHeader('Content-Length', '0');
        } else {
            $contentType = explode(';', $response->getHeaderLine('Content-Type'))[0];
            switch ($contentType) {
                case AcceptHeader::JSON->value:
                    return $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason())->withBody(Stream::create($responseBody));
                    // no break needed
                case AcceptHeader::YAML->value:
                    $responseBodyObj = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

                    try {
                        $yamlEncodedResponse = Yaml::dump($responseBodyObj, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
                    } catch (DumpException $e) {
                        throw new YamlException($e->getMessage(), StatusCode::UNPROCESSABLE_CONTENT->value, $e);
                    }
                    return $response->withStatus(StatusCode::OK->value, StatusCode::OK->reason())->withBody(Stream::create($yamlEncodedResponse));
                    // no break needed
                default:
                    throw new UnsupportedMediaTypeException();
            }
        }
    }
}
