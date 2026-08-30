<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\MergePollRunner;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wiring itself, not the runners it builds — those are covered by
 * {@see PublishRunnerTest} and {@see MergePollRunnerTest}. What matters here is that this class
 * is the ONE place that wiring lives, and that it gets the load-bearing details right: the
 * non-throwing logger, a real {@see MergePollRunner} when the GitHub App is configured, a
 * rejection (not a fatal) for a malformed `GITHUB_REPOSITORY`, and a quiet no-op notifier when
 * Redis is not configured.
 */
#[CoversClass(SourceDataPublisherFactory::class)]
final class SourceDataPublisherFactoryTest extends TestCase
{
    private const GITHUB_APP_ENV_KEYS = [
        'GITHUB_APP_ID',
        'GITHUB_APP_INSTALLATION_ID',
        'GITHUB_APP_PRIVATE_KEY_PATH',
        'GITHUB_REPOSITORY',
    ];

    /**
     * Sets a well-formed GitHub App credential and `GITHUB_REPOSITORY` in BOTH `$_ENV` and
     * `putenv()`, runs `$callback`, then restores both in a `finally` — regardless of whether
     * either was previously set. Both are set (not just `$_ENV`) because `GITHUB_REPOSITORY` is
     * a GitHub Actions built-in injected into every job via the process environment: clearing
     * only `$_ENV` would leave `getenv()` still serving CI's real value, passing locally and
     * failing in CI every time.
     */
    private function withGithubAppEnv(callable $callback): void
    {
        $previousEnv    = [];
        $previousGetenv = [];
        foreach (self::GITHUB_APP_ENV_KEYS as $key) {
            $previousEnv[$key]    = $_ENV[$key] ?? null;
            $previousGetenv[$key] = getenv($key);
        }

        $values = [
            'GITHUB_APP_ID'               => '12345',
            'GITHUB_APP_INSTALLATION_ID'  => '67890',
            'GITHUB_APP_PRIVATE_KEY_PATH' => '/nonexistent/github-app.pem',
            'GITHUB_REPOSITORY'           => 'Liturgical-Calendar/LiturgicalCalendarAPI',
        ];
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            $callback();
        } finally {
            foreach (self::GITHUB_APP_ENV_KEYS as $key) {
                if (null === $previousEnv[$key]) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $previousEnv[$key];
                }

                if (false === $previousGetenv[$key]) {
                    putenv($key);
                } else {
                    putenv("{$key}={$previousGetenv[$key]}");
                }
            }
        }
    }

    /**
     * The defect this factory exists to prevent, pinned directly. LoggerFactory's default
     * attaches RequestResponseProcessor, which THROWS on any record whose context lacks
     * type => request|response — and the runners log batch ids. If this ever regresses, every
     * log call in production throws, including the ones inside the catch blocks, stranding the
     * batch.
     */
    public function testTheLoggerDoesNotThrowOnARunnerShapedRecord(): void
    {
        $logger = ( new SourceDataPublisherFactory() )->logger('publish-sourcedata-test');

        $logger->info('a runner-shaped record', ['batch_id' => 'batch-1', 'exception' => 'RuntimeException']);

        self::assertTrue(true, 'no exception was thrown');
    }

    public function testMergePollRunnerIsBuiltWhenTheGithubAppIsConfigured(): void
    {
        $this->withGithubAppEnv(function (): void {
            $factory = new SourceDataPublisherFactory();
            self::assertInstanceOf(
                MergePollRunner::class,
                $factory->mergePollRunner($factory->logger('poll-sourcedata-merges-test'))
            );
        });
    }

    /**
     * GITHUB_REPOSITORY is a GitHub Actions built-in injected into every job as owner/repo.
     * Clearing only $_ENV leaves getenv() serving it, which passes locally and fails CI — every
     * time.
     */
    public function testAMalformedRepositoryIsRejectedRatherThanFatal(): void
    {
        $this->withGithubAppEnv(function (): void {
            $_ENV['GITHUB_REPOSITORY'] = 'https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI';
            putenv('GITHUB_REPOSITORY=https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI');

            $factory = new SourceDataPublisherFactory();

            $this->expectException(\InvalidArgumentException::class);
            $factory->mergePollRunner($factory->logger('poll-sourcedata-merges-test'));
        });
    }

    /**
     * Restores BOTH `$_ENV` and `getenv()` for `REDIS_SOCKET`/`REDIS_HOST`, mirroring
     * {@see withGithubAppEnv()} above and for the identical reason: a variable set only via
     * `putenv()` (no `$_ENV` entry) would otherwise be left cleared by this test's `finally` for
     * every test that runs after it in the same process.
     */
    public function testPublishNotifierIsANoOpWithoutRedisConfiguration(): void
    {
        $previousEnvSocket    = $_ENV['REDIS_SOCKET'] ?? null;
        $previousEnvHost      = $_ENV['REDIS_HOST'] ?? null;
        $previousGetenvSocket = getenv('REDIS_SOCKET');
        $previousGetenvHost   = getenv('REDIS_HOST');

        unset($_ENV['REDIS_SOCKET'], $_ENV['REDIS_HOST']);
        putenv('REDIS_SOCKET');
        putenv('REDIS_HOST');

        try {
            // Must not throw, must not connect.
            ( new SourceDataPublisherFactory() )->publishNotifier()->notify('batch-1');

            self::assertTrue(true);
        } finally {
            if (null !== $previousEnvSocket) {
                $_ENV['REDIS_SOCKET'] = $previousEnvSocket;
            }
            if (null !== $previousEnvHost) {
                $_ENV['REDIS_HOST'] = $previousEnvHost;
            }

            if (false === $previousGetenvSocket) {
                putenv('REDIS_SOCKET');
            } else {
                putenv("REDIS_SOCKET={$previousGetenvSocket}");
            }

            if (false === $previousGetenvHost) {
                putenv('REDIS_HOST');
            } else {
                putenv("REDIS_HOST={$previousGetenvHost}");
            }
        }
    }

    /**
     * `envString()` must read BOTH layers. Dotenv fills `$_ENV` from the `.env*` files, but PHP CLI
     * commonly runs with `variables_order` excluding `E`, so a variable set by a systemd
     * `Environment=` directive reaches `getenv()` and NEVER `$_ENV` — and the change-request
     * runbook ships exactly such a unit for `bin/publish-sourcedata-consumer`. Reading only `$_ENV`
     * would silently ignore it and fall back to the defaults.
     */
    public function testEnvStringReadsTheProcessEnvironmentWhenEnvArrayIsAbsent(): void
    {
        unset($_ENV['LITCAL_ENVSTRING_PROBE']);
        putenv('LITCAL_ENVSTRING_PROBE=from-getenv');

        try {
            self::assertSame('from-getenv', SourceDataPublisherFactory::envString('LITCAL_ENVSTRING_PROBE'));
        } finally {
            putenv('LITCAL_ENVSTRING_PROBE');
        }
    }

    public function testEnvStringPrefersTheEnvArrayAndTrimsIt(): void
    {
        $_ENV['LITCAL_ENVSTRING_PROBE'] = '  from-env-array  ';
        putenv('LITCAL_ENVSTRING_PROBE=from-getenv');

        try {
            self::assertSame('from-env-array', SourceDataPublisherFactory::envString('LITCAL_ENVSTRING_PROBE'));
        } finally {
            unset($_ENV['LITCAL_ENVSTRING_PROBE']);
            putenv('LITCAL_ENVSTRING_PROBE');
        }
    }

    /**
     * An EMPTY value is treated as unset, in both layers. `.env.example` ships
     * `REDIS_SOURCEDATA_PUBLISH_CONSUMER` with an empty value and the comment "default: hostname",
     * so following the documented configuration must reach the fallback, not hand an empty consumer
     * name to `RedisStreamConsumer`.
     */
    public function testEnvStringTreatsAnEmptyValueAsUnsetInBothLayers(): void
    {
        $_ENV['LITCAL_ENVSTRING_PROBE'] = '   ';
        putenv('LITCAL_ENVSTRING_PROBE=');

        try {
            self::assertSame('', SourceDataPublisherFactory::envString('LITCAL_ENVSTRING_PROBE'));
        } finally {
            unset($_ENV['LITCAL_ENVSTRING_PROBE']);
            putenv('LITCAL_ENVSTRING_PROBE');
        }
    }

    public function testEnvStringFallsThroughAnEmptyEnvArrayValueToTheProcessEnvironment(): void
    {
        $_ENV['LITCAL_ENVSTRING_PROBE'] = '';
        putenv('LITCAL_ENVSTRING_PROBE=from-getenv');

        try {
            self::assertSame('from-getenv', SourceDataPublisherFactory::envString('LITCAL_ENVSTRING_PROBE'));
        } finally {
            unset($_ENV['LITCAL_ENVSTRING_PROBE']);
            putenv('LITCAL_ENVSTRING_PROBE');
        }
    }
}
