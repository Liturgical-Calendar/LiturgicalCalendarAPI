<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\RedisConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

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
        'REDIS_TLS',
        'REDIS_TLS_CA_FILE',
        'REDIS_TLS_VERIFY_PEER',
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

        RedisConnection::resetPlainTcpWarningState();
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

        RedisConnection::resetPlainTcpWarningState();
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
    // -----------------------------------------------------------------------------------------
    // TLS (#919 option 1): make the secure configuration possible.
    // -----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tlsSchemeProvider(): iterable
    {
        yield 'tls://'      => ['tls://'];
        yield 'ssl://'      => ['ssl://'];
        yield 'mixed case'  => ['TLS://'];
    }

    #[DataProvider('tlsSchemeProvider')]
    public function testASchemePrefixOnTheHostEnablesTls(string $scheme): void
    {
        $_ENV['REDIS_HOST'] = $scheme . 'redis.example.com';
        $_ENV['REDIS_PORT'] = '6380';

        $config = RedisConnection::fromEnv();

        self::assertTrue($config->tls);
        // The scheme is stripped from the host and put back on the connect target, so that
        // loopback detection and the describe() string both see the real host.
        self::assertSame('redis.example.com', $config->host);
        self::assertSame('tls://redis.example.com', $config->target());
        self::assertSame('tls://redis.example.com:6380', $config->describe());
        self::assertTrue($config->isTransportSecure());
    }

    public function testTheRedisTlsFlagEnablesTlsWithoutASchemePrefix(): void
    {
        $_ENV['REDIS_HOST'] = 'redis.example.com';
        $_ENV['REDIS_TLS']  = 'true';

        $config = RedisConnection::fromEnv();

        self::assertTrue($config->tls);
        self::assertSame('tls://redis.example.com', $config->target());
    }

    public function testAMistypedTlsFlagLeavesTlsOffRatherThanPretending(): void
    {
        $_ENV['REDIS_HOST'] = 'redis.example.com';
        $_ENV['REDIS_TLS']  = 'ture';

        self::assertFalse(RedisConnection::fromEnv()->tls);
    }

    /**
     * The plain path must issue exactly the three-argument connect it issued before #919: no
     * stream context, so no dependence on a phpredis new enough to accept one.
     */
    public function testAPlainConnectPassesNoStreamContext(): void
    {
        $_ENV['REDIS_HOST'] = 'redis.example.com';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('connect')
            ->with('redis.example.com', 6379, RedisConnection::CONNECT_TIMEOUT_SECONDS)
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->connect($redis));
    }

    /** TLS with no further options also needs no context — phpredis verifies the peer by default. */
    public function testTlsWithoutOptionsPassesNoStreamContext(): void
    {
        $_ENV['REDIS_HOST'] = 'tls://redis.example.com';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('connect')
            ->with('tls://redis.example.com', 6379, RedisConnection::CONNECT_TIMEOUT_SECONDS)
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->connect($redis));
    }

    public function testACaFileAndDisabledVerificationReachTheStreamContext(): void
    {
        $_ENV['REDIS_HOST']            = 'tls://redis.example.com';
        $_ENV['REDIS_TLS_CA_FILE']     = '/etc/ssl/certs/redis-ca.pem';
        $_ENV['REDIS_TLS_VERIFY_PEER'] = 'false';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('connect')
            ->with(
                'tls://redis.example.com',
                6379,
                RedisConnection::CONNECT_TIMEOUT_SECONDS,
                null,
                0,
                0,
                [
                    'stream' => [
                        'cafile'           => '/etc/ssl/certs/redis-ca.pem',
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                    ],
                ],
            )
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->connect($redis));
    }

    /** A socket ignores TLS entirely: there is no wire for TLS to protect. */
    public function testASocketIgnoresTlsOptions(): void
    {
        $_ENV['REDIS_SOCKET'] = '/var/run/redis/redis.sock';
        $_ENV['REDIS_TLS']    = 'true';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('connect')
            ->with('/var/run/redis/redis.sock', 0, RedisConnection::CONNECT_TIMEOUT_SECONDS)
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->connect($redis));
    }

    // -----------------------------------------------------------------------------------------
    // The plain-TCP password warning (#919 option 3): say something, but never refuse.
    // -----------------------------------------------------------------------------------------

    public function testAPasswordOverPlainTcpWarns(): void
    {
        $_ENV['REDIS_HOST']     = 'redis.example.com';
        $_ENV['REDIS_PASSWORD'] = 's3cret';

        $logger = $this->recordingLogger();

        $redis = $this->createStub(\Redis::class);
        $redis->method('auth')->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->authenticate($redis, $logger));

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('unencrypted TCP', $logger->records[0]);
        self::assertStringContainsString('redis.example.com:6379', $logger->records[0]);
        // The credential itself must never reach the log.
        self::assertStringNotContainsString('s3cret', $logger->records[0]);
    }

    /**
     * "Once" is per process, not per connection: the eleven sites connect lazily on many
     * different request paths and warning at each would bury the message in its own repetition.
     */
    public function testTheWarningIsEmittedOnlyOncePerProcess(): void
    {
        $_ENV['REDIS_HOST']     = 'redis.example.com';
        $_ENV['REDIS_PASSWORD'] = 's3cret';

        $logger = $this->recordingLogger();
        $redis  = $this->createStub(\Redis::class);
        $redis->method('auth')->willReturn(true);

        // Three separate configurations, as three separate call sites would build them.
        RedisConnection::fromEnv()->authenticate($redis, $logger);
        RedisConnection::fromEnv()->authenticate($redis, $logger);
        RedisConnection::fromEnv()->authenticate($redis, $logger);

        self::assertCount(1, $logger->records);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function safeTransportProvider(): iterable
    {
        yield 'unix socket'      => [['REDIS_SOCKET' => '/var/run/redis/redis.sock']];
        yield 'loopback ipv4'    => [['REDIS_HOST' => '127.0.0.1']];
        yield 'loopback /8'      => [['REDIS_HOST' => '127.0.0.53']];
        yield 'localhost'        => [['REDIS_HOST' => 'localhost']];
        yield 'loopback ipv6'    => [['REDIS_HOST' => '[::1]']];
        yield 'expanded ipv6'    => [['REDIS_HOST' => '0:0:0:0:0:0:0:1']];
        yield 'tls scheme'       => [['REDIS_HOST' => 'tls://redis.example.com']];
        yield 'tls flag'         => [['REDIS_HOST' => 'redis.example.com', 'REDIS_TLS' => 'true']];
        yield 'tls over loopback' => [['REDIS_HOST' => 'tls://127.0.0.1']];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('safeTransportProvider')]
    public function testNoWarningWhenTheTransportProtectsThePassword(array $env): void
    {
        foreach ($env as $name => $value) {
            $_ENV[$name] = $value;
        }
        $_ENV['REDIS_PASSWORD'] = 's3cret';

        $config = RedisConnection::fromEnv();
        self::assertTrue($config->isTransportSecure());

        $logger = $this->recordingLogger();
        $redis  = $this->createStub(\Redis::class);
        $redis->method('auth')->willReturn(true);

        self::assertTrue($config->authenticate($redis, $logger));
        self::assertSame([], $logger->records);
    }

    /** No password, no exposure, no warning — however remote and plain the endpoint is. */
    public function testNoWarningWithoutAPassword(): void
    {
        $_ENV['REDIS_HOST'] = 'redis.example.com';

        $logger = $this->recordingLogger();
        RedisConnection::fromEnv()->warnIfPasswordTravelsInClear($logger);

        self::assertSame([], $logger->records);
    }

    /**
     * #919 rejected option 2 (fail closed) as deployment-breaking on upgrade. The unsafe
     * combination must still authenticate.
     */
    public function testTheUnsafeCombinationStillAuthenticates(): void
    {
        $_ENV['REDIS_HOST']     = 'redis.example.com';
        $_ENV['REDIS_PASSWORD'] = 's3cret';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('auth')
            ->with('s3cret')
            ->willReturn(true);

        self::assertTrue(RedisConnection::fromEnv()->authenticate($redis, $this->recordingLogger()));
    }

    /**
     * A PSR-3 logger that keeps every message it is handed, so a test can count them.
     *
     * The return type is left off deliberately: the anonymous class' `$records` property is what
     * the assertions read, and naming `LoggerInterface` here would hide it.
     */
    private function recordingLogger()
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            /**
             * @param mixed             $level
             * @param string|\Stringable $message
             * @param array<mixed>      $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
    }

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
