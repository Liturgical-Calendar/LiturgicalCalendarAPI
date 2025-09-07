<?php

namespace LiturgicalCalendar\Api\Http\Server;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewareQueue = [];

    private RequestHandlerInterface $defaultHandler;

    public function __construct(RequestHandlerInterface $defaultHandler)
    {
        $this->defaultHandler = $defaultHandler;
    }

    public function pipe(MiddlewareInterface $middleware): void
    {
        $this->middlewareQueue[] = $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($this->middlewareQueue)) {
            return $this->defaultHandler->handle($request);
        }

        $middleware = array_shift($this->middlewareQueue);

        return $middleware->process($request, $this);
    }
}
