<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Database;

use LiturgicalCalendar\Api\Database\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    /** @var array<string,mixed> Map of env var name → original $_ENV entry (or "unset" sentinel). */
    private array $savedEnv = [];

    private const UNSET_SENTINEL = "\0__unset__\0";

    protected function setUp(): void
    {
        // Connection reads from $_ENV (populated by Dotenv at bootstrap and by
        // PHP from the process env). Snapshot the relevant keys so each test
        // starts from a clean, restorable slate.
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
            $this->savedEnv[$name] = array_key_exists($name, $_ENV) ? $_ENV[$name] : self::UNSET_SENTINEL;
            unset($_ENV[$name]);
        }
        Connection::close();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === self::UNSET_SENTINEL) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        Connection::close();
    }

    public function testIsConfiguredReturnsFalseWhenEnvUnset(): void
    {
        self::assertFalse(Connection::isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenAnyRequiredVarMissing(): void
    {
        $_ENV['DB_HOST'] = 'db.example.test';
        $_ENV['DB_NAME'] = 'litcal';
        $_ENV['DB_USER'] = 'postgres';
        // DB_PASSWORD intentionally left unset

        self::assertFalse(Connection::isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenRequiredVarIsEmpty(): void
    {
        $_ENV['DB_HOST']     = '';
        $_ENV['DB_NAME']     = 'litcal';
        $_ENV['DB_USER']     = 'postgres';
        $_ENV['DB_PASSWORD'] = 'secret';

        self::assertFalse(Connection::isConfigured());
    }

    public function testIsConfiguredAcceptsEmptyPassword(): void
    {
        // Connection only requires that DB_PASSWORD exist as an env var,
        // an empty string is permitted (some local Postgres setups use trust auth).
        $_ENV['DB_HOST']     = 'db.example.test';
        $_ENV['DB_NAME']     = 'litcal';
        $_ENV['DB_USER']     = 'postgres';
        $_ENV['DB_PASSWORD'] = '';

        self::assertTrue(Connection::isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenAllRequiredVarsPresent(): void
    {
        $_ENV['DB_HOST']     = 'db.example.test';
        $_ENV['DB_NAME']     = 'litcal';
        $_ENV['DB_USER']     = 'postgres';
        $_ENV['DB_PASSWORD'] = 'secret';

        self::assertTrue(Connection::isConfigured());
    }

    public function testIsInitializedFalseBeforeFirstUse(): void
    {
        self::assertFalse(Connection::isInitialized());
    }

    public function testGetInstanceThrowsWhenConfigurationMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database configuration missing');

        Connection::getInstance();
    }

    public function testGetInstanceWrapsPdoExceptionAsRuntimeException(): void
    {
        // Point at a host that cannot accept connections so PDO fails fast.
        // The wrapper should surface the failure as RuntimeException with
        // the PDO message preserved in the chain.
        $_ENV['DB_HOST']     = '127.0.0.1';
        $_ENV['DB_PORT']     = '1';
        $_ENV['DB_NAME']     = 'nonexistent';
        $_ENV['DB_USER']     = 'nobody';
        $_ENV['DB_PASSWORD'] = 'none';

        try {
            Connection::getInstance();
            self::fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('Failed to connect to database', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
            self::assertFalse(
                Connection::isInitialized(),
                'A failed connection attempt must not mark the singleton as initialized'
            );
        }
    }

    public function testCloseResetsInitializationFlag(): void
    {
        // Manually drive the singleton into an "initialized" state via reflection
        // because we cannot rely on a live Postgres in unit tests. close() is the
        // hook tests use to reset state between cases — verify it actually resets.
        $reflection = new \ReflectionClass(Connection::class);
        $reflection->setStaticPropertyValue('initialized', true);
        $reflection->setStaticPropertyValue('instance', null);

        Connection::close();

        self::assertFalse(Connection::isInitialized());
    }

    public function testWakeupThrowsRuntimeException(): void
    {
        // The class is final and uses a private constructor, so we use
        // reflection to obtain an unserialized instance and invoke __wakeup
        // explicitly to verify the singleton-protection behaviour.
        $reflection = new \ReflectionClass(Connection::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');

        $instance->__wakeup();
    }
}
