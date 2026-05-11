<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Router::route() is end-to-end (constructs handlers, emits, calls die())
 * so it isn't directly testable in-process. These tests target the
 * stateless / static helpers exposed on Router that the broader codebase
 * (and Route, JsonData enum, handler tests) lean on.
 */
#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedServer = [];

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private const UNSET = "\0__unset__\0";

    private const SERVER_KEYS = [
        'REQUEST_SCHEME',
        'HTTPS',
        'SERVER_PORT',
        'SERVER_ADDR',
        'REMOTE_ADDR',
        'SERVER_NAME',
    ];

    protected function setUp(): void
    {
        foreach (self::SERVER_KEYS as $k) {
            $this->savedServer[$k] = array_key_exists($k, $_SERVER) ? $_SERVER[$k] : self::UNSET;
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $k => $v) {
            if ($v === self::UNSET) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
    }

    public function testDetectRequestSchemeDefaultsToHttp(): void
    {
        self::assertSame('http', Router::detectRequestScheme());
    }

    public function testDetectRequestSchemeViaRequestScheme(): void
    {
        $_SERVER['REQUEST_SCHEME'] = 'https';
        self::assertSame('https', Router::detectRequestScheme());
    }

    public function testDetectRequestSchemeViaHttpsOn(): void
    {
        $_SERVER['HTTPS'] = 'on';
        self::assertSame('https', Router::detectRequestScheme());
    }

    public function testDetectRequestSchemeViaPort443(): void
    {
        $_SERVER['SERVER_PORT'] = '443';
        self::assertSame('https', Router::detectRequestScheme());
    }

    public function testIsLocalhostFromServerAddr(): void
    {
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        self::assertTrue(Router::isLocalhost());
    }

    public function testIsLocalhostFromRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '::1';
        self::assertTrue(Router::isLocalhost());
    }

    public function testIsLocalhostFromServerName(): void
    {
        $_SERVER['SERVER_NAME'] = 'localhost';
        self::assertTrue(Router::isLocalhost());
    }

    public function testIsLocalhostFalseForExternalServer(): void
    {
        $_SERVER['SERVER_NAME'] = 'api.example.com';
        $_SERVER['SERVER_ADDR'] = '203.0.113.42';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        self::assertFalse(Router::isLocalhost());
    }

    public function testGetApiPathsCliMode(): void
    {
        // In CLI mode (PHP_SAPI === 'cli', which is true under PHPUnit),
        // getApiPaths reads API_PROTOCOL/HOST/PORT/BASE_PATH from $_ENV.
        // The bootstrap already populated everything, so just call and
        // verify the static state is well-formed.
        Router::getApiPaths();
        self::assertIsString(Router::$apiPath);
        self::assertIsString(Router::$apiBase);
        self::assertIsString(Router::$apiFilePath);
        // $apiPath should not end with a slash (rtrim'd).
        self::assertStringEndsNotWith('/', Router::$apiPath);
        // $apiFilePath should be the project root (where composer.json lives) with trailing separator.
        self::assertFileExists(Router::$apiFilePath . 'composer.json');
    }
}
