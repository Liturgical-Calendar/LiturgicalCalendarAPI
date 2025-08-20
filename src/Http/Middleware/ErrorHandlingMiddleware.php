<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ApiException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ErrorHandlingMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private bool $debug;

    public function __construct(ResponseFactoryInterface $responseFactory, bool $debug = false)
    {
        $this->responseFactory = $responseFactory;
        $this->debug           = $debug;
    }


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            // Default values
            $status  = 500;
            $problem = [
                'type'   => 'about:blank',
                'title'  => 'Internal Server Error',
                'status' => $status,
                'detail' => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
            ];

            if ($e instanceof ApiException) {
                // Let the ApiException define its structure
                $status  = $e->getStatus();
                $problem = $e->toArray($this->debug);
            } elseif ($this->debug) {
                // For non-ApiExceptions in debug mode, add trace info
                $problem['file']  = $e->getFile();
                $problem['line']  = $e->getLine();
                $problem['trace'] = $e->getTrace();
            }

            $response = $this->responseFactory->createResponse($status);
            $response
                ->getBody()
                ->write(json_encode($problem, JSON_PRETTY_PRINT));

            return $response
                ->withHeader('Content-Type', 'application/problem+json')
                ->withHeader('Access-Control-Allow-Origin', '*');
        }
    }
}
