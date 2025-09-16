<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        $requestId = $request->getAttribute('request_id');

        $this->logger->debug('Incoming request', [
            'request_id' => $requestId,
            'type'       => 'request',
            'request'    => $request
        ]);

        $response = $handler->handle($request);

        // Add response context to log entries
        $this->logger->debug('Outgoing response', [
            'request_id'    => $requestId,
            'type'          => 'response',
            'response'      => $response,
            'response_body' => (string) $response->getBody()
        ]);

        return $response;
    }
}
