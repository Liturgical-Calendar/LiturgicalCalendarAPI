<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Middleware\AuthorizationMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Http\Server\MiddlewarePipeline;
use LiturgicalCalendar\Api\Router;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the temporale / decrees / missals branches in the private
 * Router::configureAuthorizationPipeline() method.
 *
 * We use reflection to:
 *   1. Instantiate Router without its constructor side-effects.
 *   2. Call the private method directly.
 *   3. Inspect the MiddlewarePipeline's internal middleware queue.
 *
 * Environment variables are set before each test and restored after so
 * that other tests are not polluted.
 */
#[CoversClass(Router::class)]
final class RouterPipelineTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const ENV_VARS = [
        'ZITADEL_ISSUER'    => 'https://issuer.example',
        'ZITADEL_CLIENT_ID' => 'test-client-id',
        'OPENFGA_API_URL'   => 'http://fga.example',
        'OPENFGA_STORE_ID'  => '01ABC',
        'OPENFGA_MODEL_ID'  => '01DEF',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Save current values (getenv returns false when unset)
        foreach (array_keys(self::ENV_VARS) as $name) {
            $this->savedEnv[$name] = getenv($name);
        }

        // Inject fake values so isConfigured() / isOidcConfigured() return true
        // and fromEnv() constructs without throwing (just builds a Guzzle client object)
        foreach (self::ENV_VARS as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $previous) {
            if ($previous === false) {
                putenv($name);             // unset the env var
                unset($_ENV[$name]);
            } else {
                putenv("{$name}={$previous}");
                $_ENV[$name] = $previous;
            }
        }

        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a Router instance without calling the constructor.
     */
    private function routerWithoutConstructor(): Router
    {
        return ( new ReflectionClass(Router::class) )->newInstanceWithoutConstructor();
    }

    /**
     * Build a MiddlewarePipeline with a no-op default handler.
     */
    private function emptyPipeline(): MiddlewarePipeline
    {
        $noop = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        return new MiddlewarePipeline($noop);
    }

    /**
     * Call the private configureAuthorizationPipeline method via reflection.
     *
     * @param array<int, string> $pathParts
     */
    private function callConfigurePipeline(
        Router $router,
        MiddlewarePipeline $pipeline,
        string $route,
        array $pathParts
    ): void {
        $method = new ReflectionMethod(Router::class, 'configureAuthorizationPipeline');
        $method->invoke($router, $pipeline, $route, $pathParts);
    }

    /**
     * Extract the private middlewareQueue from a MiddlewarePipeline.
     *
     * @return list<object>
     */
    private function getQueue(MiddlewarePipeline $pipeline): array
    {
        $prop = ( new ReflectionClass(MiddlewarePipeline::class) )->getProperty('middlewareQueue');
        /** @var list<object> */
        return $prop->getValue($pipeline);
    }

    /**
     * Extract a named private property from an object via reflection.
     */
    private function getPrivateProp(object $obj, string $propName): mixed
    {
        $prop = ( new ReflectionClass($obj) )->getProperty($propName);
        return $prop->getValue($obj);
    }

    // ── tests: temporale ─────────────────────────────────────────────────────

    public function testTemporaleAddsCalendarEditorMiddleware(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'temporale', []);

        $queue = $this->getQueue($pipeline);
        $types = array_map('get_class', $queue);

        self::assertContains(AuthorizationMiddleware::class, $types, 'Expected AuthorizationMiddleware in pipeline');
    }

    public function testTemporaleAddsOpenFgaMiddlewareForGeneralRomanCalendar(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'temporale', []);

        $queue = $this->getQueue($pipeline);
        $fgaMw = null;
        foreach ($queue as $mw) {
            if ($mw instanceof OpenFgaAuthorizationMiddleware) {
                $fgaMw = $mw;
                break;
            }
        }

        self::assertNotNull($fgaMw, 'Expected OpenFgaAuthorizationMiddleware in pipeline for temporale');
        self::assertSame('general_roman_calendar', $this->getPrivateProp($fgaMw, 'objectType'));
        self::assertSame('temporale', $this->getPrivateProp($fgaMw, 'fixedObjectId'));
    }

    // ── tests: decrees ───────────────────────────────────────────────────────

    public function testDecreesAddsCalendarEditorMiddleware(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'decrees', []);

        $queue = $this->getQueue($pipeline);
        $types = array_map('get_class', $queue);

        self::assertContains(AuthorizationMiddleware::class, $types, 'Expected AuthorizationMiddleware in pipeline');
    }

    public function testDecreesAddsOpenFgaMiddlewareForGeneralRomanCalendar(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'decrees', []);

        $queue = $this->getQueue($pipeline);
        $fgaMw = null;
        foreach ($queue as $mw) {
            if ($mw instanceof OpenFgaAuthorizationMiddleware) {
                $fgaMw = $mw;
                break;
            }
        }

        self::assertNotNull($fgaMw, 'Expected OpenFgaAuthorizationMiddleware in pipeline for decrees');
        self::assertSame('general_roman_calendar', $this->getPrivateProp($fgaMw, 'objectType'));
        self::assertSame('decrees', $this->getPrivateProp($fgaMw, 'fixedObjectId'));
    }

    // ── tests: missals ───────────────────────────────────────────────────────

    public function testMissalsAddsCalendarEditorMiddleware(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        // Pass a Latin Editio Typica missal ID as the first path part
        $this->callConfigurePipeline($router, $pipeline, 'missals', ['EDITIO_TYPICA_2002']);

        $queue = $this->getQueue($pipeline);
        $types = array_map('get_class', $queue);

        self::assertContains(AuthorizationMiddleware::class, $types, 'Expected AuthorizationMiddleware in pipeline');
    }

    public function testMissalsAddsOpenFgaMiddlewareForLatinMissal(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        // EDITIO_TYPICA_2002 is a Latin missal → general_roman_calendar object type
        $this->callConfigurePipeline($router, $pipeline, 'missals', ['EDITIO_TYPICA_2002']);

        $queue = $this->getQueue($pipeline);
        $fgaMw = null;
        foreach ($queue as $mw) {
            if ($mw instanceof OpenFgaAuthorizationMiddleware) {
                $fgaMw = $mw;
                break;
            }
        }

        self::assertNotNull($fgaMw, 'Expected OpenFgaAuthorizationMiddleware in pipeline for missals');
        self::assertSame('general_roman_calendar', $this->getPrivateProp($fgaMw, 'objectType'));
        self::assertSame('EDITIO_TYPICA_2002', $this->getPrivateProp($fgaMw, 'fixedObjectId'));
    }

    public function testMissalsWithoutIdRequiresAdminAndSkipsFga(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        // A collection-level write (no missal id) cannot be resource-authorized, so it must
        // fail closed to admin rather than fall back to calendar_editor + no FGA check.
        $this->callConfigurePipeline($router, $pipeline, 'missals', []);

        $queue = $this->getQueue($pipeline);

        $authMw = null;
        foreach ($queue as $mw) {
            if ($mw instanceof AuthorizationMiddleware) {
                $authMw = $mw;
                break;
            }
        }
        self::assertNotNull($authMw, 'Expected an AuthorizationMiddleware for an id-less missals write');
        self::assertSame('admin', $this->getPrivateProp($authMw, 'requiredRole'));

        foreach ($queue as $mw) {
            self::assertNotInstanceOf(
                OpenFgaAuthorizationMiddleware::class,
                $mw,
                'No fine-grained FGA middleware should be added when the missal id is absent'
            );
        }
    }
}
