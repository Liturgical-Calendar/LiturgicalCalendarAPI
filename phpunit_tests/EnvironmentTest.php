<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    private const UNSET = "\0__unset__\0";

    /** @var list<string> */
    private const MANAGED = ['APP_ENV', 'WS_HOST', 'WS_PORT', 'LITCAL_ENV_TEST_KEY'];

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, string|false> */
    private array $savedGetenv = [];

    /**
     * Isolate all three environment layers (`$_ENV`, `$_SERVER`, `getenv()`), matching
     * {@see \LiturgicalCalendar\Tests\Services\JwtServiceFactoryTest}: since `Environment::get()`
     * checks `getenv()` ahead of `$_ENV`, clearing only `$_ENV` would let a shell-exported
     * `APP_ENV` / `WS_PORT` leak into assertions.
     */
    protected function setUp(): void
    {
        foreach (self::MANAGED as $k) {
            $this->savedEnv[$k]    = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            $this->savedServer[$k] = array_key_exists($k, $_SERVER) ? $_SERVER[$k] : self::UNSET;
            $this->savedGetenv[$k] = getenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::MANAGED as $k) {
            $env = $this->savedEnv[$k];
            if ($env === self::UNSET) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $env;
            }

            $server = $this->savedServer[$k];
            if ($server === self::UNSET) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $server;
            }

            $orig = $this->savedGetenv[$k];
            if ($orig === false) {
                putenv($k);
            } else {
                putenv($k . '=' . $orig);
            }
        }
    }

    public function testDefaultsToDevelopment(): void
    {
        self::assertSame('development', Environment::getName());
        self::assertTrue(Environment::isDevelopment());
        self::assertFalse(Environment::isProduction());
    }

    public function testNormalisesUppercaseAndWhitespace(): void
    {
        $_ENV['APP_ENV'] = '  PRODUCTION  ';
        self::assertSame('production', Environment::getName());
        self::assertTrue(Environment::isProduction());
        self::assertFalse(Environment::isDevelopment());
    }

    public function testStagingIsProductionLike(): void
    {
        $_ENV['APP_ENV'] = 'staging';
        self::assertTrue(Environment::isProduction());
        self::assertFalse(Environment::isDevelopment());
    }

    public function testTestIsDevelopmentLike(): void
    {
        $_ENV['APP_ENV'] = 'test';
        self::assertTrue(Environment::isDevelopment());
        self::assertFalse(Environment::isProduction());
    }

    public function testNonStringFallsBackToDevelopment(): void
    {
        // Forced non-string assignment to exercise the defensive branch.
        $_ENV['APP_ENV'] = ['nope'];
        self::assertSame('development', Environment::getName());
    }

    public function testGetReadsFromProcessEnvironmentWhenEnvSuperglobalIsAbsent(): void
    {
        putenv('LITCAL_ENV_TEST_KEY=  from-process  ');
        $_SERVER['LITCAL_ENV_TEST_KEY'] = 'from-process';

        self::assertSame('from-process', Environment::get('LITCAL_ENV_TEST_KEY'));
    }

    public function testGetFallsBackToEnvSuperglobalWhenProcessEnvIsAbsent(): void
    {
        $_ENV['LITCAL_ENV_TEST_KEY'] = '  from-dotenv  ';

        self::assertSame('from-dotenv', Environment::get('LITCAL_ENV_TEST_KEY'));
    }

    public function testGetPrefersProcessEnvironmentOverEnvSuperglobal(): void
    {
        putenv('LITCAL_ENV_TEST_KEY=from-process');
        $_ENV['LITCAL_ENV_TEST_KEY'] = 'from-dotenv';

        self::assertSame('from-process', Environment::get('LITCAL_ENV_TEST_KEY'));
    }

    public function testGetFallsThroughBlankProcessEnvironmentToEnvSuperglobal(): void
    {
        putenv('LITCAL_ENV_TEST_KEY=   ');
        $_ENV['LITCAL_ENV_TEST_KEY'] = 'from-dotenv';

        self::assertSame('from-dotenv', Environment::get('LITCAL_ENV_TEST_KEY'));
    }

    public function testGetReturnsNullWhenBlankOrNonStringInBothLayers(): void
    {
        putenv('LITCAL_ENV_TEST_KEY=');
        $_ENV['LITCAL_ENV_TEST_KEY'] = '   ';
        self::assertNull(Environment::get('LITCAL_ENV_TEST_KEY'));

        putenv('LITCAL_ENV_TEST_KEY');
        $_ENV['LITCAL_ENV_TEST_KEY'] = ['not-a-string'];
        self::assertNull(Environment::get('LITCAL_ENV_TEST_KEY'));
    }

    public function testAppEnvFromProcessEnvironmentDrivesHelpers(): void
    {
        putenv('APP_ENV=production');
        self::assertSame('production', Environment::getName());
        self::assertTrue(Environment::isProduction());
        self::assertFalse(Environment::isDevelopment());

        putenv('APP_ENV=test');
        self::assertSame('test', Environment::getName());
        self::assertTrue(Environment::isDevelopment());
        self::assertFalse(Environment::isProduction());
    }

    public function testResolveWebSocketConfigDefaultsWhenUnset(): void
    {
        $config = Environment::resolveWebSocketConfig();

        self::assertSame('127.0.0.1', $config['host']);
        self::assertSame(8082, $config['port']);
        self::assertFalse($config['isDebugEnv']);
    }

    public function testResolveWebSocketConfigReadsProcessEnvironment(): void
    {
        putenv('WS_HOST=0.0.0.0');
        putenv('WS_PORT=9090');
        putenv('APP_ENV=test');
        $_ENV['WS_HOST'] = '127.0.0.1';
        $_ENV['WS_PORT'] = '8082';
        $_ENV['APP_ENV'] = 'production';

        $config = Environment::resolveWebSocketConfig();

        self::assertSame('0.0.0.0', $config['host']);
        self::assertSame(9090, $config['port']);
        self::assertTrue($config['isDebugEnv']);
    }

    public function testResolveWebSocketConfigValidatesPortBoundsAndNonNumeric(): void
    {
        foreach (['0', '65536', '-1', 'not-a-port', '   '] as $invalidPort) {
            putenv('WS_PORT=' . $invalidPort);
            $config = Environment::resolveWebSocketConfig();
            self::assertSame(8082, $config['port'], "Expected invalid port '{$invalidPort}' to fall back to 8082");
        }

        putenv('WS_PORT=1');
        self::assertSame(1, Environment::resolveWebSocketConfig()['port']);

        putenv('WS_PORT=65535');
        self::assertSame(65535, Environment::resolveWebSocketConfig()['port']);
    }

    public function testResolveWebSocketConfigIsDebugEnvOnlyForDevelopmentAndTest(): void
    {
        foreach (['development' => true, 'DEVELOPMENT' => true, 'test' => true, 'staging' => false, 'production' => false] as $env => $expected) {
            putenv('APP_ENV=' . $env);
            self::assertSame($expected, Environment::resolveWebSocketConfig()['isDebugEnv'], "Failed for APP_ENV={$env}");
        }
    }
}
