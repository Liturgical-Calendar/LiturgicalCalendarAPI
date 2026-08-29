<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `source_data_writes` block of `/health` is how an operator finds out that this
 * deployment is not recording edits the way they think it is. Its whole value is in the
 * two warning branches, so those are what these tests pin.
 *
 * Every branch is decided by environment alone, so no database or OpenFGA is touched.
 */
#[CoversClass(Health::class)]
#[CoversClass(SourceDataWriteMode::class)]
final class HealthSourceDataWriteModeTest extends TestCase
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
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }
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
    }

    private function withStack(bool $available): void
    {
        if ($available) {
            $_ENV['DB_HOST']          = 'localhost';
            $_ENV['DB_NAME']          = 'litcal';
            $_ENV['DB_USER']          = 'litcal';
            $_ENV['DB_PASSWORD']      = 'secret';
            $_ENV['OPENFGA_API_URL']  = 'http://localhost:8083';
            $_ENV['OPENFGA_STORE_ID'] = 'store';
            $_ENV['OPENFGA_MODEL_ID'] = 'model';

            return;
        }

        // Postgres present, OpenFGA absent: queue mode still cannot decide who may approve.
        $_ENV['DB_HOST']     = 'localhost';
        $_ENV['DB_NAME']     = 'litcal';
        $_ENV['DB_USER']     = 'litcal';
        $_ENV['DB_PASSWORD'] = 'secret';
        unset($_ENV['OPENFGA_API_URL'], $_ENV['OPENFGA_STORE_ID'], $_ENV['OPENFGA_MODEL_ID']);
    }

    public function testQueueModeReportsOk(): void
    {
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $this->withStack(true);

        $status = Health::buildSourceDataWriteModeStatus();

        self::assertSame('ok', $status['status']);
        self::assertStringContainsString('change requests', $status['message']);
    }

    public function testFlagWithoutTheStackIsAWarning(): void
    {
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $this->withStack(false);

        $status = Health::buildSourceDataWriteModeStatus();

        // The operator asked for queue mode and is silently getting disk writes.
        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('falling back to disk writes', $status['message']);
    }

    public function testStackWithoutTheFlagIsAWarning(): void
    {
        unset($_ENV[SourceDataWriteMode::FLAG]);
        $this->withStack(true);

        $status = Health::buildSourceDataWriteModeStatus();

        // The forgotten-flag case on an rsync --delete host: edits look saved and are not.
        self::assertSame('warning', $status['status']);
        self::assertStringContainsString('reverted on the next', $status['message']);
    }

    public function testPlainDiskDeploymentReportsOk(): void
    {
        unset($_ENV[SourceDataWriteMode::FLAG]);
        $this->withStack(false);

        $status = Health::buildSourceDataWriteModeStatus();

        // A self-hoster with no stack is not misconfigured; this must stay quiet.
        self::assertSame('ok', $status['status']);
        self::assertStringContainsString('no change request stack', $status['message']);
    }

    public function testTheFlagIsReadLeniently(): void
    {
        $this->withStack(true);

        foreach ([' true ', 'TRUE', 'True'] as $value) {
            $_ENV[SourceDataWriteMode::FLAG] = $value;
            self::assertTrue(
                SourceDataWriteMode::changeRequestsEnabled(),
                sprintf('%s should enable queue mode', var_export($value, true))
            );
        }

        foreach (['false', '1', 'yes', ''] as $value) {
            $_ENV[SourceDataWriteMode::FLAG] = $value;
            self::assertFalse(
                SourceDataWriteMode::changeRequestsEnabled(),
                sprintf('%s should not enable queue mode', var_export($value, true))
            );
        }
    }
}
