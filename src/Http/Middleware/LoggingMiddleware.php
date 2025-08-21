<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        error_log('Incoming request: ' . $request->getMethod() . ' ' . $request->getUri());

        $response = $handler->handle($request);

        error_log('Outgoing response: ' . $response->getStatusCode());

        return $response;
    }
}
