<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
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

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
            unset($_ENV[$key]);
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
        $this->originalEnv = [];

        // Drop the placeholder-credential connection (or the failed attempt at one) so the next
        // suite reconnects from the restored environment.
        Connection::close();
    }

    private function enableQueueMode(): void
    {
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['DB_HOST']                 = 'localhost';
        $_ENV['DB_NAME']                 = 'litcal';
        $_ENV['DB_USER']                 = 'litcal';
        $_ENV['DB_PASSWORD']             = 'secret';
        $_ENV['OPENFGA_API_URL']         = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID']        = 'store';
        $_ENV['OPENFGA_MODEL_ID']        = 'model';
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
}
