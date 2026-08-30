<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\RedisConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The eleven pre-#919 copies of the Redis connect/auth block had already drifted from one another
 * (four passed a connect timeout, seven did not), so the point of these cases is to pin the
 * decisions the single helper now makes for all of them: socket before host, the host/port
 * defaults, and the connect timeout that reaches every site.
 */
#[CoversClass(RedisConnection::class)]
final class RedisConnectionTest extends TestCase
{
    /**
     * Every variable {@see RedisConnection::fromEnv()} consults, snapshotted and restored around
     * each case so that a developer's `.env.local` (loaded into `$_ENV` by the bootstrap) can
     * neither leak into an assertion nor be clobbered by one.
     *
     * @var list<string>
     */
    private const REDIS_ENV_VARS = [
        'REDIS_SOCKET',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',
    ];

    /** @var array<string, string|null> */
    private array $savedEnv = [];

    /** @var array<string, string|false> */
    private array $savedGetenv = [];

    protected function setUp(): void
    {
        foreach (self::REDIS_ENV_VARS as $name) {
            $value                    = $_ENV[$name] ?? null;
            $this->savedEnv[$name]    = is_string($value) ? $value : null;
            $this->savedGetenv[$name] = getenv($name);
            unset($_ENV[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::REDIS_ENV_VARS as $name) {
            $saved = $this->savedEnv[$name] ?? null;
            if (null === $saved) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $saved;
            }

            $original = $this->savedGetenv[$name] ?? false;
            if (false === $original) {
                putenv($name);
            } else {
                putenv($name . '=' . $original);
            }
        }
    }

    public function testNothingConfiguredIsNotConfigured(): void
    {
        $config = RedisConnection::fromEnv();

        self::assertFalse($config->isConfigured());
        self::assertFalse($config->usesSocket());
        self::assertFalse($config->hasPassword());
    }

    public function testAnEmptyHostDoesNotCountAsConfigured(): void
    {
        $_ENV['REDIS_HOST'] = '   ';

        self::assertFalse(RedisConnection::fromEnv()->isConfigured());
    }

    public function testHostAndPortAreReadFromEnv(): void
    {
        $_ENV['REDIS_HOST'] = 'redis.internal';
        $_ENV['REDIS_PORT'] = '6380';

        $config = RedisConnection::fromEnv();

        self::assertTrue($config->isConfigured());
        self::assertSame('redis.internal', $config->target());
        self::assertSame(6380, $config->port);
        self::assertSame('redis.internal:6380', $config->describe());
    }

    public function testANonNumericPortFallsBackToTheDefault(): void
    {
        $_ENV['REDIS_HOST'] = 'redis.internal';
        $_ENV['REDIS_PORT'] = 'not-a-port';

        self::assertSame(RedisConnection::DEFAULT_PORT, RedisConnection::fromEnv()->port);
    }

    /**
     * Nine of the eleven pre-#919 copies defaulted a missing host to 127.0.0.1, and `Health`
     * relies on that: with nothing configured at all it still tries the loopback default, as
     * `.env.example` documents.
     */
    public function testAMissingHostDefaultsToLoopback(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('connect')
            ->with(
                self::identicalTo(RedisConnection::DEFAULT_HOST),
                self::identicalTo(RedisConnection::DEFAULT_PORT),
                self::identicalTo(RedisConnection::CONNECT_TIMEOUT_SECONDS),
            )
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->connect($redis));
    }

    /**
     * The socket wins over a host that is ALSO configured — the precedence every copy had, and
     * the one an operator relies on when a `.env` file sets both.
     */
    public function testSocketTakesPrecedenceOverHost(): void
    {
        $_ENV['REDIS_SOCKET'] = '/var/run/redis/redis.sock';
        $_ENV['REDIS_HOST']   = 'redis.internal';
        $_ENV['REDIS_PORT']   = '6380';

        $config = RedisConnection::fromEnv();
        self::assertTrue($config->usesSocket());
        self::assertSame('socket: /var/run/redis/redis.sock', $config->describe());

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('connect')
            ->with(
                self::identicalTo('/var/run/redis/redis.sock'),
                self::identicalTo(0),
                self::identicalTo(RedisConnection::CONNECT_TIMEOUT_SECONDS),
            )
            ->willReturn(true);

        self::assertTrue($config->connect($redis));
    }

    /**
     * A systemd `Environment=`/`EnvironmentFile=` variable reaches `getenv()` and never `$_ENV`
     * when PHP CLI runs with `variables_order` excluding `E`. Nine of the eleven copies read
     * `$_ENV` only, and so silently connected to 127.0.0.1 under exactly the unit the
     * change-request runbook ships.
     */
    public function testConfigurationIsReadFromTheProcessEnvironmentToo(): void
    {
        putenv('REDIS_HOST=redis-from-systemd');
        putenv('REDIS_PORT=6399');

        $config = RedisConnection::fromEnv();

        self::assertSame('redis-from-systemd', $config->target());
        self::assertSame(6399, $config->port);
    }

    public function testExplicitEnvWinsOverTheProcessEnvironment(): void
    {
        $_ENV['REDIS_HOST'] = 'from-dotenv';
        putenv('REDIS_HOST=from-systemd');

        self::assertSame('from-dotenv', RedisConnection::fromEnv()->target());
    }

    public function testAuthenticateIsANoOpWithoutAPassword(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('auth');

        self::assertTrue(RedisConnection::fromEnv()->authenticate($redis));
    }

    public function testAuthenticateSendsThePassword(): void
    {
        $_ENV['REDIS_SOCKET']   = '/var/run/redis/redis.sock';
        $_ENV['REDIS_PASSWORD'] = 's3cret';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('auth')
            ->with(self::identicalTo('s3cret'))
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->authenticate($redis));
    }

    public function testAuthenticateReportsAFailedAuth(): void
    {
        $_ENV['REDIS_SOCKET']   = '/var/run/redis/redis.sock';
        $_ENV['REDIS_PASSWORD'] = 'wrong';

        $redis = $this->createStub(\Redis::class);
        $redis->method('auth')->willReturn(false);

        self::assertFalse(RedisConnection::fromEnv()->authenticate($redis));
    }

    /** The password never reaches the log line or the health payload. */
    public function testDescribeNeverLeaksThePassword(): void
    {
        $_ENV['REDIS_HOST']     = 'redis.internal';
        $_ENV['REDIS_PASSWORD'] = 'super-secret';

        self::assertStringNotContainsString('super-secret', RedisConnection::fromEnv()->describe());
    }

    /**
     * The ordinary state for a self-hoster: `.env.example` comments both variables out, so the
     * notifier sites get a null `\Redis` and fall back to their cron/disk path. This must stay
     * true whether or not ext-redis happens to be installed on the machine running the suite.
     */
    public function testBestEffortReturnsNullWhenNothingIsConfigured(): void
    {
        self::assertNull(RedisConnection::bestEffort());
    }

    public function testBestEffortReturnsNullWhenExtRedisIsMissing(): void
    {
        if (extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is installed; the missing-extension branch cannot be reached here.');
        }

        $_ENV['REDIS_HOST'] = 'redis.internal';

        self::assertNull(RedisConnection::bestEffort());
    }
}
