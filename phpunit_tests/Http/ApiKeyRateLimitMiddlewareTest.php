<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Exception\TooManyRequestsException;
use LiturgicalCalendar\Api\Http\Middleware\ApiKeyRateLimitMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Unit tests for ApiKeyRateLimitMiddleware client-IP resolution.
 *
 * Exercises getClientIp() through process() to cover the env-read branch
 * that distinguishes dev/test (proxy headers honoured) from production
 * (REMOTE_ADDR only, unless trustProxyHeaders is explicitly set).
 */
final class ApiKeyRateLimitMiddlewareTest extends TestCase
{
    private string $storagePath = '';

    /** @var array<string, string> */
    private array $envBackup = [];

    private RequestHandlerInterface $okHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'litcal_test_rl_' . bin2hex(random_bytes(6));

        $this->envBackup = [
            'APP_ENV_env'    => $_ENV['APP_ENV']    ?? '__UNSET__',
            'APP_ENV_getenv' => getenv('APP_ENV') === false ? '__UNSET__' : (string) getenv('APP_ENV'),
        ];

        $this->okHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->envBackup['APP_ENV_env'] === '__UNSET__') {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->envBackup['APP_ENV_env'];
        }
        if ($this->envBackup['APP_ENV_getenv'] === '__UNSET__') {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->envBackup['APP_ENV_getenv']);
        }

        // Cleanup is constrained to the sandbox directory created in setUp().
        // realpath() canonicalizes both sides so symlinks/.. tricks can't
        // escape the sandbox even if the rate-limiter ever wrote unusual paths.
        $sandboxReal = realpath($this->storagePath);
        if ($sandboxReal !== false && is_dir($sandboxReal)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sandboxReal, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                $entryReal = realpath($entry->getPathname());
                if ($entryReal === false || !str_starts_with($entryReal, $sandboxReal . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if ($entry->isDir()) {
                    @rmdir($entryReal);
                } elseif ($entry->isFile()) {
                    @unlink($entryReal);
                }
            }
            @rmdir($sandboxReal);
        }

        parent::tearDown();
    }

    private function makeRequest(string $forwardedFor, string $remoteAddr = '203.0.113.1'): ServerRequestInterface
    {
        return new ServerRequest(
            'GET',
            '/calendar',
            ['X-Forwarded-For' => $forwardedFor],
            null,
            '1.1',
            ['REMOTE_ADDR' => $remoteAddr]
        );
    }

    public function testAppEnvTestHonoursForwardedForAndIsolatesPerIp(): void
    {
        $_ENV['APP_ENV'] = 'test';
        putenv('APP_ENV=test');

        $limit      = 3;
        $middleware = new ApiKeyRateLimitMiddleware($limit, $this->storagePath, false);

        // Saturate the IP 198.51.100.7 budget exactly.
        for ($i = 0; $i < $limit; $i++) {
            $response = $middleware->process($this->makeRequest('198.51.100.7'), $this->okHandler);
            $this->assertSame(200, $response->getStatusCode());
        }

        // A different X-Forwarded-For must have its own untouched budget,
        // proving the middleware keyed on the forwarded value (not REMOTE_ADDR).
        $other = $middleware->process($this->makeRequest('198.51.100.99'), $this->okHandler);
        $this->assertSame(200, $other->getStatusCode());
        $this->assertSame((string) ( $limit - 1 ), $other->getHeaderLine('X-RateLimit-Remaining'));

        // The original IP is now over-limit and must throw.
        $this->expectException(TooManyRequestsException::class);
        $middleware->process($this->makeRequest('198.51.100.7'), $this->okHandler);
    }

    public function testAppEnvProductionIgnoresForwardedForWithoutTrustProxy(): void
    {
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        $limit      = 2;
        $middleware = new ApiKeyRateLimitMiddleware($limit, $this->storagePath, false);

        // Two requests from different X-Forwarded-For values but the same
        // REMOTE_ADDR. With proxy headers untrusted, both must count against
        // the REMOTE_ADDR bucket and exhaust the limit.
        $r1 = $middleware->process($this->makeRequest('198.51.100.10', '203.0.113.50'), $this->okHandler);
        $r2 = $middleware->process($this->makeRequest('198.51.100.20', '203.0.113.50'), $this->okHandler);
        $this->assertSame(200, $r1->getStatusCode());
        $this->assertSame(200, $r2->getStatusCode());

        $this->expectException(TooManyRequestsException::class);
        $middleware->process($this->makeRequest('198.51.100.30', '203.0.113.50'), $this->okHandler);
    }

    public function testEnvFallbackFromGetenvWhenSuperglobalUnset(): void
    {
        // Simulate a runtime where $_ENV doesn't carry APP_ENV (e.g. PHP-FPM
        // with restrictive variables_order) but getenv() does — the new
        // getenv() ?: ($_ENV[...] ?? '') read must still detect dev/test.
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV=test');

        $limit      = 2;
        $middleware = new ApiKeyRateLimitMiddleware($limit, $this->storagePath, false);

        $r1 = $middleware->process($this->makeRequest('198.51.100.40'), $this->okHandler);
        $r2 = $middleware->process($this->makeRequest('198.51.100.40'), $this->okHandler);
        $this->assertSame(200, $r1->getStatusCode());
        $this->assertSame(200, $r2->getStatusCode());

        // If getenv() weren't consulted, APP_ENV would resolve to '' (production-
        // like) and the per-IP forwarded address would be ignored, so requests
        // from the same REMOTE_ADDR would exhaust together. The test asserts
        // the X-Forwarded-For path is taken: a different forwarded IP gets a
        // fresh budget.
        $other = $middleware->process($this->makeRequest('198.51.100.41'), $this->okHandler);
        $this->assertSame(200, $other->getStatusCode());
        $this->assertSame((string) ( $limit - 1 ), $other->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testTrustProxyHeadersOverridesProductionEnv(): void
    {
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        $limit      = 2;
        $middleware = new ApiKeyRateLimitMiddleware($limit, $this->storagePath, true);

        // trustProxyHeaders=true must honour X-Forwarded-For even outside
        // dev/test, so two distinct forwarded IPs each get their own budget.
        $a = $middleware->process($this->makeRequest('198.51.100.50', '203.0.113.99'), $this->okHandler);
        $b = $middleware->process($this->makeRequest('198.51.100.51', '203.0.113.99'), $this->okHandler);
        $this->assertSame((string) ( $limit - 1 ), $a->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame((string) ( $limit - 1 ), $b->getHeaderLine('X-RateLimit-Remaining'));
    }
}
