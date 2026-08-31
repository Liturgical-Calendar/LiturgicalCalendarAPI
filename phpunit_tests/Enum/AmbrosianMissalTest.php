<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmbrosianMissal::class)]
final class AmbrosianMissalTest extends TestCase
{
    private static string $savedApiPath;
    private static string $savedApiFilePath;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        self::$savedApiPath     = Router::$apiPath;
        self::$savedApiFilePath = Router::$apiFilePath;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    public function testIsValid(): void
    {
        self::assertTrue(AmbrosianMissal::isValid(AmbrosianMissal::EDITIO_TYPICA_2024));
        self::assertFalse(AmbrosianMissal::isValid('nope'));
    }

    public function testIsEditioTypica(): void
    {
        self::assertTrue(AmbrosianMissal::isEditioTypica(AmbrosianMissal::EDITIO_TYPICA_2024));
        self::assertFalse(AmbrosianMissal::isEditioTypica('nope'));
    }

    public function testGetNameKnown(): void
    {
        $name = AmbrosianMissal::getName(AmbrosianMissal::EDITIO_TYPICA_2024);
        self::assertIsString($name);
        self::assertNotSame('', $name);
    }

    public function testGetNameUnknownThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid missal_id: nope');
        AmbrosianMissal::getName('nope');
    }

    public function testGetSanctoraleFileNameForKnownEditionReturnsPath(): void
    {
        $path = AmbrosianMissal::getSanctoraleFileName(AmbrosianMissal::EDITIO_TYPICA_2024);
        self::assertIsString($path);
        self::assertStringContainsString(
            'ambrosian/missals/propriumdesanctis_2024/propriumdesanctis_2024.json',
            $path
        );
    }

    public function testGetSanctoraleFileNameRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        AmbrosianMissal::getSanctoraleFileName('nope');
    }

    public function testGetSanctoraleI18nFilePath(): void
    {
        $path = AmbrosianMissal::getSanctoraleI18nFilePath(AmbrosianMissal::EDITIO_TYPICA_2024);
        self::assertIsString($path);
        self::assertStringContainsString('ambrosian/missals/propriumdesanctis_2024/i18n/', $path);
    }

    public function testGetSanctoraleI18nFilePathRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        AmbrosianMissal::getSanctoraleI18nFilePath('nope');
    }

    public function testGetYearLimits(): void
    {
        $limits = AmbrosianMissal::getYearLimits(AmbrosianMissal::EDITIO_TYPICA_2024);
        self::assertSame(2024, $limits['since_year']);
        self::assertArrayNotHasKey('until_year', $limits);
    }

    public function testGetYearLimitsRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        AmbrosianMissal::getYearLimits('nope');
    }

    public function testGetMissalIdsIncludesEditio2024(): void
    {
        $ids = AmbrosianMissal::getMissalIds();
        self::assertContains(AmbrosianMissal::EDITIO_TYPICA_2024, $ids);
    }
}
