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
        $this->logger = LoggerFactory::createApiLogger($debug);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId   = $request->getAttribute('request_id');
        $requestBody = $request->getBody();

        $this->logger->debug('Incoming request', [
            'request_id'   => $requestId,
            'type'         => 'request',
            'request'      => $request,
            'request_body' => self::readBody($requestBody)
        ]);

        $response     = $handler->handle($request);
        $responseBody = $response->getBody();

        // Add response context to log entries
        $this->logger->debug('Outgoing response', [
            'request_id'    => $requestId,
            'type'          => 'response',
            'response'      => $response,
            'response_body' => self::readBody($responseBody)
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
