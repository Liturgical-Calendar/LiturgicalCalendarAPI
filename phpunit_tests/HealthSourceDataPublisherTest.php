<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `source_data_publisher` block of `/health` is how an operator finds out that approved
 * change requests are actually being published to GitHub, rather than silently piling up.
 *
 * The dangerous state is queue mode on with the publisher unconfigured: editors submit,
 * admins approve, everything reports success, and nothing is ever published — it looks
 * exactly like a working system from every UI surface. That combination is the one branch
 * that must warn; every other combination is quiet.
 *
 * Every branch is decided by environment alone, so no database, OpenFGA, or GitHub call is
 * touched — mirrors {@see HealthSourceDataWriteModeTest}.
 */
#[CoversClass(Health::class)]
#[CoversClass(SourceDataPublisher::class)]
final class HealthSourceDataPublisherTest extends TestCase
{
    private const KEYS = [
        SourceDataWriteMode::FLAG,
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'OPENFGA_API_URL',
        'OPENFGA_STORE_ID',
        'OPENFGA_MODEL_ID',
        'GITHUB_APP_ID',
        'GITHUB_APP_INSTALLATION_ID',
        'GITHUB_APP_PRIVATE_KEY_PATH',
        'GITHUB_REPOSITORY',
        'GITHUB_BASE_BRANCH',
        'GITHUB_APP_COMMITTER_NAME',
        'GITHUB_APP_COMMITTER_EMAIL',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var array<string, string|false> */
    private array $originalGetenv = [];

    protected function setUp(): void
    {
        // Both layers, not just $_ENV. `getEnvString()` reads $_ENV and falls back to getenv(),
        // so an inherited process value survives an `unset($_ENV[...])` and decides the branch
        // instead of the test doing so.
        //
        // This is not hypothetical, and it is why CI failed while every local run passed:
        // GitHub Actions injects GITHUB_REPOSITORY into every job, as `owner/repo` — which is
        // also a *valid* value for our variable of the same name. So the "one missing variable"
        // case silently found a well-formed repository in the process environment and reported
        // `ok` where the test asserts `warning`.
        foreach (self::KEYS as $key) {
            $this->originalEnv[$key]    = $_ENV[$key] ?? false;
            $processValue               = getenv($key);
            $this->originalGetenv[$key] = $processValue;
            unset($_ENV[$key]);
            putenv($key);
        }

        // The block now also reports parked batches, which is a DB read. These cases are about
        // the ENVIRONMENT-decided branches, so the connection must resolve from the placeholder
        // credentials below (and fail, yielding zero) rather than from a live singleton some
        // earlier test opened against the real database — otherwise whether this suite passes
        // would depend on rows another suite happened to leave behind, and on test order.
        Connection::close();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        foreach ($this->originalGetenv as $key => $value) {
            if (false === $value) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->originalEnv    = [];
        $this->originalGetenv = [];

        // Drop the placeholder-credential connection (or the failed attempt at one) so the next
        // suite reconnects from the restored environment.
        Connection::close();
    }

    private function enableQueueMode(): void
    {
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['DB_HOST']                 = 'localhost';
        // Port 1 is a privileged port nothing on this machine can be listening on for Postgres,
        // so the connect is refused immediately regardless of local auth configuration — see
        // testTheOpenBatchCountsDegradeToZeroWhenTheConfiguredDatabaseIsUnreachable()'s docblock
        // for why DB_HOST/DB_NAME/DB_USER alone were not enough to guarantee that.
        $_ENV['DB_PORT']          = '1';
        $_ENV['DB_NAME']          = 'litcal';
        $_ENV['DB_USER']          = 'litcal';
        $_ENV['DB_PASSWORD']      = 'secret';
        $_ENV['OPENFGA_API_URL']  = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID'] = 'store';
        $_ENV['OPENFGA_MODEL_ID'] = 'model';
    }

    private function configurePublisher(): void
    {
        $_ENV['GITHUB_APP_ID']               = '12345';
        $_ENV['GITHUB_APP_INSTALLATION_ID']  = '67890';
        $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] = '/etc/litcal/github-app.pem';
        $_ENV['GITHUB_REPOSITORY']           = 'Liturgical-Calendar/LiturgicalCalendarAPI';
    }

    public function testQueueModeOnAndPublisherConfiguredReportsOk(): void
    {
        $this->enableQueueMode();
        $this->configurePublisher();

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('ok', $status['status']);
        self::assertStringContainsString('will publish', $status['message']);
        self::assertSame(0, $status['parked_batches'], 'no database reachable here: the count degrades to zero');
    }

    public function testQueueModeOnAndPublisherUnconfiguredIsAWarningNamingTheConsequence(): void
    {
        $this->enableQueueMode();
        // Publisher left entirely unconfigured.

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('accumulating unpublished', $status['message']);
    }

    public function testQueueModeOnAndPublisherMissingOneVariableIsAWarning(): void
    {
        $this->enableQueueMode();
        $this->configurePublisher();
        // A single missing variable is enough to make the publisher unable to run.
        unset($_ENV['GITHUB_REPOSITORY']);
        putenv('GITHUB_REPOSITORY');

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('accumulating unpublished', $status['message']);
    }

    public function testQueueModeOffAndPublisherConfiguredReportsOkNotAnError(): void
    {
        // Queue mode off: flag unset and stack absent.
        $this->configurePublisher();

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('ok', $status['status']);
        self::assertStringContainsString('nothing to publish', $status['message']);
    }

    public function testQueueModeOffAndPublisherUnconfiguredIsOkAndQuiet(): void
    {
        // Neither queue mode nor the publisher configured: the ordinary self-hoster.

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('ok', $status['status']);
        self::assertStringContainsString('nothing to publish', $status['message']);
    }

    /**
     * A GITHUB_REPOSITORY that is set but malformed must NOT read as configured. Testing only
     * for non-emptiness made this block report a healthy publisher for a value no run could
     * ever publish with — the same silent accumulation the unconfigured branch exists to catch,
     * reached through a value that is present rather than absent.
     */
    #[DataProvider('malformedRepositoryProvider')]
    public function testAMalformedRepositoryIsNotConfigured(string $repository): void
    {
        $this->enableQueueMode();
        $this->configurePublisher();
        $_ENV['GITHUB_REPOSITORY'] = $repository;

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('accumulating unpublished', $status['message']);
        self::assertStringContainsString('owner/repo', $status['message'], 'the operator needs to know what is wrong with it');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedRepositoryProvider(): array
    {
        return [
            'pasted repository URL' => ['https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI'],
            'trailing slash'        => ['Liturgical-Calendar/LiturgicalCalendarAPI/'],
            'no owner'              => ['LiturgicalCalendarAPI'],
            'three segments'        => ['github.com/owner/repo'],
        ];
    }

    /**
     * The block always carries `open_batches` and `oldest_open_age_seconds`, on every branch —
     * a deployment in the unconfigured-publisher state, the very state this block exists to
     * catch, must not answer without them.
     */
    public function testTheBlockAlwaysCarriesOpenBatchKeysAsInts(): void
    {
        $status = Health::buildSourceDataPublisherStatus();

        self::assertArrayHasKey('open_batches', $status);
        self::assertArrayHasKey('oldest_open_age_seconds', $status);
        self::assertIsInt($status['open_batches']);
        self::assertIsInt($status['oldest_open_age_seconds']);
    }

    /**
     * This suite has no database — setUp() clears the DB_* keys and closes the connection — so
     * the two counts must degrade to zero via {@see Connection::isConfigured()} returning false,
     * the same guard {@see \LiturgicalCalendar\Api\Health::parkedChangeRequestBatches()} already
     * relies on for `parked_batches`. That degradation IS the "database unreachable" case: it
     * needs no dedicated helper because every test in this file already runs under it, this one
     * with no env configured at all.
     */
    public function testTheOpenBatchCountsDegradeToZeroWhenThereIsNoDatabaseToAsk(): void
    {
        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(0, $status['open_batches']);
        self::assertSame(0, $status['oldest_open_age_seconds']);
    }

    /**
     * Same degradation, reached from the other side: `enableQueueMode()` sets DB_HOST, DB_NAME,
     * and DB_USER to the SAME values as the live development database in `.env.local` — only
     * DB_PASSWORD differs. Placeholder credentials alone are not reliably unreachable: on a
     * machine whose Postgres uses trust auth, or where the placeholder password happens to
     * match, the connection would SUCCEED against a live database, and this test would assert
     * `0` for the wrong reason (or fail confusingly if that database has open batches).
     * `enableQueueMode()` therefore also pins DB_PORT to `1`, a port nothing can be listening on,
     * so the connection attempt is refused immediately regardless of local auth configuration —
     * deterministically unreachable rather than merely unauthenticated. (A real test database,
     * if any, is loaded separately by
     * {@see \LiturgicalCalendar\Tests\Repositories\RepositoryTestCase}, which does not go through
     * `enableQueueMode()`.) `Connection::isConfigured()` now answers true, so this exercises the
     * try/catch around the actual connection attempt rather than the isConfigured()
     * short-circuit above — the same case `testQueueModeOnAndPublisherConfiguredReportsOk()`
     * already covers for `parked_batches`.
     */
    public function testTheOpenBatchCountsDegradeToZeroWhenTheConfiguredDatabaseIsUnreachable(): void
    {
        $this->enableQueueMode();
        $this->configurePublisher();

        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(0, $status['open_batches']);
        self::assertSame(0, $status['oldest_open_age_seconds']);
    }
}
