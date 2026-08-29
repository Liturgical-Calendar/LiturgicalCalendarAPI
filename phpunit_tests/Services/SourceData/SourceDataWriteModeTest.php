<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceDataWriteMode::class)]
final class SourceDataWriteModeTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['SOURCEDATA_CHANGE_REQUESTS', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function withFullStack(): void
    {
        $_ENV['DB_HOST']          = 'localhost';
        $_ENV['DB_NAME']          = 'litcal';
        $_ENV['DB_USER']          = 'litcal';
        $_ENV['DB_PASSWORD']      = 'secret';
        $_ENV['OPENFGA_API_URL']  = 'http://openfga.test';
        $_ENV['OPENFGA_STORE_ID'] = 'store';
        $_ENV['OPENFGA_MODEL_ID'] = 'model';
    }

    public function testDefaultsToDiskWhenTheFlagIsAbsent(): void
    {
        $this->withFullStack();

        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testIsDiskWhenTheFlagIsExplicitlyFalse(): void
    {
        $this->withFullStack();
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'false';

        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testIsQueueWhenTheFlagIsTrueAndTheStackIsPresent(): void
    {
        $this->withFullStack();
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';

        self::assertTrue(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testFallsBackToDiskWhenTheFlagIsTrueButPostgresIsMissing(): void
    {
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';
        $_ENV['OPENFGA_API_URL']            = 'http://openfga.test';
        $_ENV['OPENFGA_STORE_ID']           = 'store';
        $_ENV['OPENFGA_MODEL_ID']           = 'model';

        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testFallsBackToDiskWhenTheFlagIsTrueButOpenFgaIsMissing(): void
    {
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';
        $_ENV['DB_HOST']                    = 'localhost';
        $_ENV['DB_NAME']                    = 'litcal';
        $_ENV['DB_USER']                    = 'litcal';
        $_ENV['DB_PASSWORD']                = 'secret';

        // Queue mode without OpenFGA would accept edits nobody could ever approve,
        // because ChangeRequestReview::administers() fails closed.
        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testAMisconfiguredFlagIsReportedSoItCanBeLogged(): void
    {
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';

        self::assertTrue(SourceDataWriteMode::isMisconfigured());
        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testDiskModeWithNoStackIsNotMisconfigured(): void
    {
        self::assertFalse(SourceDataWriteMode::isMisconfigured());
    }
}
