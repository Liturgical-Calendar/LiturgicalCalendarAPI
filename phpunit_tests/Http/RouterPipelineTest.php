<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Middleware\AuthorizationMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Http\Server\MiddlewarePipeline;
use LiturgicalCalendar\Api\Router;
use Nyholm\Psr7\ServerRequest;
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

    /** Whether Router::$apiFilePath was initialized before our setUp(). */
    private bool $apiFilePathWasSet = false;

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

        // Router::$apiFilePath is a public static typed property set by the
        // Router constructor. configureAuthorizationPipeline() for the 'tests'
        // route instantiates TestScopeResolver(), which calls
        // JsonData::TESTS_FOLDER->path() → Router::$apiFilePath. We must
        // initialize it here so those tests don't throw on uninitialized static.
        $this->apiFilePathWasSet = isset(Router::$apiFilePath);
        if (!$this->apiFilePathWasSet) {
            Router::$apiFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
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

        // Router::$apiFilePath is a typed static property: PHP does not allow
        // unsetting typed statics, so we cannot restore it to the uninitialized
        // state if it was unset before setUp. Tests that need the real API file
        // path will set it themselves; none of the tests in this class depend on
        // the exact value, only on the property being initialized.

        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a Router instance without calling the constructor.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|null $request
     *   When supplied, pre-initializes the private $request property so that
     *   configureAuthorizationPipeline() branches that call
     *   `$this->request->withAttribute()` do not throw on uninitialized property.
     */
    private function routerWithoutConstructor(
        ?\Psr\Http\Message\ServerRequestInterface $request = null
    ): Router {
        $rc     = new ReflectionClass(Router::class);
        $router = $rc->newInstanceWithoutConstructor();

        if ($request !== null) {
            $prop = $rc->getProperty('request');
            $prop->setValue($router, $request);
        }

        return $router;
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
     * $rite and $testsRite mirror configureAuthorizationPipeline()'s own defaults
     * (Rite::ROMAN / null) so every existing 4-argument call site is unaffected;
     * only tests that care about the 'tests' route's tri-state rite need to pass
     * $testsRite explicitly.
     *
     * @param array<int, string> $pathParts
     */
    private function callConfigurePipeline(
        Router $router,
        MiddlewarePipeline $pipeline,
        string $route,
        array $pathParts,
        Rite $rite = Rite::ROMAN,
        ?Rite $testsRite = null
    ): void {
        $method = new ReflectionMethod(Router::class, 'configureAuthorizationPipeline');
        $method->invoke($router, $pipeline, $route, $pathParts, $rite, $testsRite);
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

        // Pass an editio typica missal ID as the first path part
        $this->callConfigurePipeline($router, $pipeline, 'missals', ['EDITIO_TYPICA_2002']);

        $queue = $this->getQueue($pipeline);
        $types = array_map('get_class', $queue);

        self::assertContains(AuthorizationMiddleware::class, $types, 'Expected AuthorizationMiddleware in pipeline');
    }

    public function testMissalsAddsOpenFgaMiddlewareForEditioTypicaMissal(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        // EDITIO_TYPICA_2002 is an editio typica missal → general_roman_calendar object type. The
        // id stays bare (issue #953): missal ids are unique across rites, so there is nothing for
        // a rite qualifier to disambiguate, unlike a nation or diocese code.
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

    // ── tests: /tests route ─────────────────────────────────────────────────

    public function testTestsRouteAddsTestEditorMiddleware(): void
    {
        $router   = $this->routerWithoutConstructor(new ServerRequest('PATCH', '/tests/roman/some-test'));
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'tests', ['some-test']);

        $queue = $this->getQueue($pipeline);
        $types = array_map('get_class', $queue);

        self::assertContains(AuthorizationMiddleware::class, $types, 'Expected AuthorizationMiddleware in pipeline');
    }

    public function testTestsRouteAddsOpenFgaMiddlewareWithObjectResolver(): void
    {
        $router   = $this->routerWithoutConstructor(new ServerRequest('PATCH', '/tests/roman/some-test'));
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'tests', ['some-test']);

        $queue = $this->getQueue($pipeline);
        $fgaMw = null;
        foreach ($queue as $mw) {
            if ($mw instanceof OpenFgaAuthorizationMiddleware) {
                $fgaMw = $mw;
                break;
            }
        }

        self::assertNotNull($fgaMw, 'Expected OpenFgaAuthorizationMiddleware in pipeline for /tests write');

        // forTestScopes() uses empty-string sentinels for objectType/resourceIdAttribute
        // because objectResolver is the real source of [type, id] at request time.
        self::assertSame('', $this->getPrivateProp($fgaMw, 'objectType'));
        self::assertNotNull(
            $this->getPrivateProp($fgaMw, 'objectResolver'),
            'objectResolver must be set by forTestScopes()'
        );
    }

    /**
     * The 'tests' branch sets both 'test_id' and 'test_rite' request attributes
     * before piping the FGA middleware. Deleting either the withAttribute() call
     * for 'test_rite' or dropping $testsRite at the route()-level call site would
     * leave this assertion (and only this assertion) failing — it is the one test
     * that observes the resulting request rather than just the pipeline queue.
     */
    public function testTestsRouteSetsTestRiteAttributeOnRequest(): void
    {
        $router   = $this->routerWithoutConstructor(new ServerRequest('PATCH', '/tests/ambrosian/some-test'));
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'tests', ['some-test'], Rite::ROMAN, Rite::AMBROSIAN);

        $request = $this->getPrivateProp($router, 'request');
        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertSame('some-test', $request->getAttribute('test_id'));
        self::assertSame('ambrosian', $request->getAttribute('test_rite'));
    }

    /**
     * Issue #790: a PATCH that re-scopes a test must be authorized against BOTH the
     * stored scope (forTestScopes()) and the payload-derived target scope
     * (forTestScopePayloadTarget()). Piping only the first leaves the union check inert —
     * this test protects the wiring in configureAuthorizationPipeline() itself, which the
     * factory-level unit tests in OpenFgaAuthorizationMiddlewareTest cannot see since they
     * construct middleware instances directly rather than going through the Router.
     */
    public function testTestsRouteAddsBothFgaMiddlewareInstancesForTheUnionCheck(): void
    {
        $router   = $this->routerWithoutConstructor(new ServerRequest('PATCH', '/tests/roman/some-test'));
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'tests', ['some-test']);

        $queue  = $this->getQueue($pipeline);
        $fgaMws = array_values(array_filter(
            $queue,
            static fn (object $mw): bool => $mw instanceof OpenFgaAuthorizationMiddleware
        ));

        self::assertCount(2, $fgaMws, 'Expected both forTestScopes() and forTestScopePayloadTarget() in the pipeline');

        // forTestScopePayloadTarget()'s relation map only defines PATCH, distinguishing it
        // from forTestScopes() (which also maps PUT and DELETE) — see each factory's
        // relationMap argument.
        self::assertSame(
            ['PATCH' => 'editor'],
            $this->getPrivateProp($fgaMws[1], 'relationMap'),
            'Second FGA middleware in the pipeline must be forTestScopePayloadTarget()'
        );
    }

    public function testTestsRouteWithoutPathPartSkipsFga(): void
    {
        // Without a path part (no test id) the fine-grained FGA middleware must not
        // be added — there is nothing to resolve a scope from.
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        $this->callConfigurePipeline($router, $pipeline, 'tests', []);

        $queue = $this->getQueue($pipeline);

        foreach ($queue as $mw) {
            self::assertNotInstanceOf(
                OpenFgaAuthorizationMiddleware::class,
                $mw,
                'No FGA middleware should be added when the test id is absent'
            );
        }
    }

    public function testMissalsWithoutIdUsesCalendarEditorAndSkipsFga(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        // Collection-level writes are no longer routed (PUT moved to /missals/{missal_id});
        // an id-less write is still role-gated but only ever reaches the handler's 405.
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
        self::assertSame('calendar_editor', $this->getPrivateProp($authMw, 'requiredRole'));

        foreach ($queue as $mw) {
            self::assertNotInstanceOf(
                OpenFgaAuthorizationMiddleware::class,
                $mw,
                'No fine-grained FGA middleware should be added when the missal id is absent'
            );
        }
    }
}
