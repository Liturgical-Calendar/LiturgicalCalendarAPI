<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Http;

use LiturgicalCalendar\Api\Http\Middleware\JsonBodyParserMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for JsonBodyParserMiddleware.
 *
 * Verifies the middleware parses JSON request bodies into getParsedBody()
 * and rewinds the body stream so downstream handlers that re-read via
 * getBody()->getContents() still see the body. The rewind is the
 * regression fix landed in b8bd626f (CI was failing because /auth/login
 * received an empty body after the middleware consumed the stream).
 */
final class JsonBodyParserMiddlewareTest extends TestCase
{
    private JsonBodyParserMiddleware $middleware;
    private Psr17Factory $factory;

    /** @var array{request: ServerRequestInterface}|array{} */
    private array $captured = [];

    /** @var RequestHandlerInterface */
    private RequestHandlerInterface $capturingHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new JsonBodyParserMiddleware();
        $this->factory    = new Psr17Factory();

        // Next handler that captures the (post-middleware) request for inspection.
        $captured               = &$this->captured;
        $this->capturingHandler = new class ($captured) implements RequestHandlerInterface {
            /** @param array{request?: ServerRequestInterface} $captured */
            public function __construct(private array &$captured)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured['request'] = $request;
                return new Response(200);
            }
        };
    }

    private function jsonRequest(string $body, string $contentType = 'application/json'): ServerRequestInterface
    {
        $request = new ServerRequest('POST', '/auth/login', ['Content-Type' => $contentType]);
        return $request->withBody($this->factory->createStream($body));
    }

    public function testParsesJsonBodyIntoParsedBody(): void
    {
        $request = $this->jsonRequest('{"username":"admin","password":"password"}');

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertArrayHasKey('request', $this->captured);
        $parsed = $this->captured['request']->getParsedBody();
        $this->assertSame(['username' => 'admin', 'password' => 'password'], $parsed);
    }

    public function testRewindsBodyStreamSoDownstreamReadsStillSeeIt(): void
    {
        // This is the regression test for b8bd626f. Before that fix, casting the
        // body to string left the stream pointer at EOF, and AbstractHandler::
        // parseBodyParams (which calls getBody()->getContents()) saw an empty
        // string — producing the "Empty body content received" 400 that broke
        // /auth/login on the JsonBodyParserMiddleware regression.
        $request = $this->jsonRequest('{"k":"v"}');

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertArrayHasKey('request', $this->captured);
        $contents = $this->captured['request']->getBody()->getContents();
        $this->assertSame('{"k":"v"}', $contents);
    }

    public function testIgnoresNonJsonContentType(): void
    {
        $request = $this->jsonRequest('{"k":"v"}', 'text/plain');

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertNull($this->captured['request']->getParsedBody());
    }

    public function testHonorsCharsetSuffixOnContentType(): void
    {
        // stripos(..., 'application/json') matches "application/json; charset=utf-8".
        $request = $this->jsonRequest('{"k":"v"}', 'application/json; charset=utf-8');

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertSame(['k' => 'v'], $this->captured['request']->getParsedBody());
    }

    public function testPreservesPreexistingParsedBody(): void
    {
        // A request that already has form-encoded fields parsed should not be
        // overwritten — the middleware's content-type check is a guard for
        // POST /...-encoded forms, but the parsed-body check is the primary one.
        $request = $this->jsonRequest('{"k":"v"}')->withParsedBody(['preexisting' => true]);

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertSame(['preexisting' => true], $this->captured['request']->getParsedBody());
    }

    public function testEmptyBodyIsNoOp(): void
    {
        $request = $this->jsonRequest('');

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertNull($this->captured['request']->getParsedBody());
    }

    public function testInvalidJsonLeavesParsedBodyNullWithoutThrowing(): void
    {
        $request = $this->jsonRequest('{not valid json');

        $response = $this->middleware->process($request, $this->capturingHandler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->captured['request']->getParsedBody());
    }

    public function testJsonScalarIsNotPromotedToParsedBody(): void
    {
        // json_decode('"string"') is a string; json_decode('42') is an int.
        // Only arrays should be set as parsed body.
        $request = $this->jsonRequest('"just a string"');

        $this->middleware->process($request, $this->capturingHandler);

        $this->assertNull($this->captured['request']->getParsedBody());
    }
}
