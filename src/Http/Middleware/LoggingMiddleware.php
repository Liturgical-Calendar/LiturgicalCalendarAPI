<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    private Logger $logger;

    public function __construct(bool $debug = false)
    {
        $this->logger = LoggerFactory::create('api', null, 30, $debug, true, true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId      = $request->getAttribute('request_id');
        $reqContentType = $request->getHeaderLine('Content-Type');
        $requestBody    = $request->getBody();
        $safeReqBody    = str_starts_with($reqContentType, 'application/json') || str_starts_with($reqContentType, 'application/yaml') || str_starts_with($reqContentType, 'text/')
            ? self::readBody($requestBody)
            : '[body omitted]';

        $this->logger->debug('Incoming request', [
            'request_id'   => $requestId,
            'type'         => 'request',
            'request'      => $request,
            'request_body' => $safeReqBody
        ]);

        $response       = $handler->handle($request);
        $resContentType = $response->getHeaderLine('Content-Type');
        $responseBody   = $response->getBody();
        $safeResBody    = str_starts_with($resContentType, 'application/json') || str_starts_with($resContentType, 'application/yaml') || str_starts_with($resContentType, 'text/')
            ? self::readBody($responseBody)
            : '[body omitted]';

// Add response context to log entries
        $this->logger->debug('Outgoing response', [
            'request_id'    => $requestId,
            'type'          => 'response',
            'response'      => $response,
            'response_body' => $safeResBody
        ]);

        return $response;
    }

    private static function readBody(StreamInterface $body): string
    {
        if ($body->isSeekable()) {
            $position = $body->tell();
            $body->rewind();
            $contents = (string) $body;
            $body->seek($position);
        } else {
            // Fallback for non-seekable streams
            $contents = $body->getContents();
            // Can't rewind, so just log what we got
        }

        return $contents;
    }
}
