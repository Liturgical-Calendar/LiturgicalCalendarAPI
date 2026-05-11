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

    /** @var mixed */
    private $savedAppEnv;

    protected function setUp(): void
    {
        $this->savedAppEnv = array_key_exists('APP_ENV', $_ENV) ? $_ENV['APP_ENV'] : self::UNSET;
        unset($_ENV['APP_ENV']);
    }

    protected function tearDown(): void
    {
        if ($this->savedAppEnv === self::UNSET) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->savedAppEnv;
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
}
