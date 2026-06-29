<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Middleware\ApiKeyMiddleware;
use LiturgicalCalendar\Api\Repositories\ApiKeyRepository;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for ApiKeyMiddleware's surfacing of the first-party `is_system` flag.
 *
 * ApiKeyRepository::validate() returns the joined application's `is_system` column aliased as
 * `app_is_system`. The middleware copies it into the `api_key` request attribute and exposes it
 * via the static isSystem() helper, which future FGA read-authorization will consult to bypass
 * per-resource checks for trusted first-party keys.
 */
final class ApiKeyMiddlewareTest extends TestCase
{
    /**
     * Build a middleware whose repository returns the given validate() result.
     *
     * @param array<string, mixed>|null $validateResult
     */
    private function middlewareReturning(?array $validateResult): ApiKeyMiddleware
    {
        $repo = $this->createStub(ApiKeyRepository::class);
        $repo->method('validate')->willReturn($validateResult);

        return new ApiKeyMiddleware($repo);
    }

    /**
     * A handler that records the request it received so attributes can be inspected.
     *
     * @return RequestHandlerInterface&object{captured: ?ServerRequestInterface}
     */
    private function captureHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200);
            }
        };
    }

    /**
     * A representative validate() row, mirroring ApiKeyRepository's SELECT aliases.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validKeyRow(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 'key-uuid',
            'application_id'      => 'app-uuid',
            'app_uuid'            => 'app-uuid',
            'app_name'            => 'LitCal Official UIs',
            'zitadel_user_id'     => 'owner-1',
            'scope'               => 'read',
            'rate_limit_per_hour' => 1000000,
            'app_is_system'       => true,
        ], $overrides);
    }

    public function testSystemKeySurfacesIsSystemTrue(): void
    {
        $handler    = $this->captureHandler();
        $middleware = $this->middlewareReturning($this->validKeyRow(['app_is_system' => true]));

        $middleware->process(new ServerRequest('GET', '/calendar', ['X-Api-Key' => 'litcal_test_abc']), $handler);

        $request = $handler->captured;
        if (!$request instanceof ServerRequestInterface) {
            self::fail('Handler did not capture a request.');
        }
        /** @var array<string, mixed> $attr */
        $attr = $request->getAttribute('api_key');
        $this->assertIsArray($attr);
        $this->assertTrue($attr['is_system']);
        $this->assertTrue(ApiKeyMiddleware::isSystem($request));
    }

    public function testOrdinaryKeySurfacesIsSystemFalse(): void
    {
        $handler    = $this->captureHandler();
        $middleware = $this->middlewareReturning($this->validKeyRow(['app_is_system' => false]));

        $middleware->process(new ServerRequest('GET', '/calendar', ['X-Api-Key' => 'litcal_test_abc']), $handler);

        $request = $handler->captured;
        if (!$request instanceof ServerRequestInterface) {
            self::fail('Handler did not capture a request.');
        }
        /** @var array<string, mixed> $attr */
        $attr = $request->getAttribute('api_key');
        $this->assertIsArray($attr);
        $this->assertFalse($attr['is_system']);
        $this->assertFalse(ApiKeyMiddleware::isSystem($request));
    }

    public function testUnauthenticatedRequestIsNotSystem(): void
    {
        $handler    = $this->captureHandler();
        $middleware = $this->middlewareReturning(null);

        // No X-Api-Key header — the middleware must not set the api_key attribute at all.
        $middleware->process(new ServerRequest('GET', '/calendar'), $handler);

        $request = $handler->captured;
        if (!$request instanceof ServerRequestInterface) {
            self::fail('Handler did not capture a request.');
        }
        $this->assertNull($request->getAttribute('api_key'));
        $this->assertFalse(ApiKeyMiddleware::isSystem($request));
    }

    public function testPostgresStringTrueSurfacesIsSystemTrue(): void
    {
        // pdo_pgsql can surface a boolean column as the string 't'; it must be treated as system.
        $handler    = $this->captureHandler();
        $middleware = $this->middlewareReturning($this->validKeyRow(['app_is_system' => 't']));

        $middleware->process(new ServerRequest('GET', '/calendar', ['X-Api-Key' => 'litcal_test_abc']), $handler);

        $request = $handler->captured;
        if (!$request instanceof ServerRequestInterface) {
            self::fail('Handler did not capture a request.');
        }
        /** @var array<string, mixed> $attr */
        $attr = $request->getAttribute('api_key');
        $this->assertIsArray($attr);
        $this->assertTrue($attr['is_system']);
        $this->assertTrue(ApiKeyMiddleware::isSystem($request));
    }

    public function testPostgresStringFalseIsNotSystem(): void
    {
        // The string 'f' is false-like and must NOT be treated as a trusted system key
        // (the bug a naive !empty() check would introduce).
        $handler    = $this->captureHandler();
        $middleware = $this->middlewareReturning($this->validKeyRow(['app_is_system' => 'f']));

        $middleware->process(new ServerRequest('GET', '/calendar', ['X-Api-Key' => 'litcal_test_abc']), $handler);

        $request = $handler->captured;
        if (!$request instanceof ServerRequestInterface) {
            self::fail('Handler did not capture a request.');
        }
        /** @var array<string, mixed> $attr */
        $attr = $request->getAttribute('api_key');
        $this->assertIsArray($attr);
        $this->assertFalse($attr['is_system']);
        $this->assertFalse(ApiKeyMiddleware::isSystem($request));
    }
}
