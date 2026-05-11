<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Server;

use LiturgicalCalendar\Api\Http\Server\MiddlewarePipeline;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    private function terminalHandler(string $marker = 'inner'): RequestHandlerInterface
    {
        return new class ($marker) implements RequestHandlerInterface {
            public function __construct(private string $marker)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ( new Response(200) )
                    ->withHeader('X-Inner', $this->marker);
            }
        };
    }

    private function decoratingMiddleware(string $name): MiddlewareInterface
    {
        return new class ($name) implements MiddlewareInterface {
            public function __construct(private string $name)
            {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                return $handler->handle($request)
                    ->withAddedHeader('X-Stack', $this->name);
            }
        };
    }

    public function testEmptyPipelineDelegatesToDefaultHandler(): void
    {
        $pipeline = new MiddlewarePipeline($this->terminalHandler('default'));
        $factory  = new Psr17Factory();
        $response = $pipeline->handle($factory->createServerRequest('GET', '/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('default', $response->getHeaderLine('X-Inner'));
    }

    public function testMiddlewareRunsInQueueOrderAroundHandler(): void
    {
        $pipeline = new MiddlewarePipeline($this->terminalHandler());
        $pipeline->pipe($this->decoratingMiddleware('outer'));
        $pipeline->pipe($this->decoratingMiddleware('inner'));

        $response = $pipeline->handle(( new Psr17Factory() )->createServerRequest('GET', '/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('inner', $response->getHeaderLine('X-Inner'));
        // Both middlewares ran — pipeline appends "inner" first (innermost) then
        // "outer" wraps it, so getHeader returns them in chronological order.
        self::assertSame(['inner', 'outer'], $response->getHeader('X-Stack'));
    }

    public function testShortCircuitMiddlewareSkipsTerminalHandler(): void
    {
        $pipeline = new MiddlewarePipeline($this->terminalHandler('should-not-run'));

        // This middleware never calls $handler->handle.
        $short = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                return new Response(418);
            }
        };
        $pipeline->pipe($short);

        $response = $pipeline->handle(( new Psr17Factory() )->createServerRequest('GET', '/'));
        self::assertSame(418, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Inner'));
    }
}
