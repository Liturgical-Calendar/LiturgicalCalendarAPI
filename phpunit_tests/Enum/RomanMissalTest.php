<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RomanMissal::class)]
final class RomanMissalTest extends TestCase
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
        self::assertTrue(RomanMissal::isValid(RomanMissal::EDITIO_TYPICA_1970));
        self::assertTrue(RomanMissal::isValid(RomanMissal::USA_EDITION_2011));
        self::assertFalse(RomanMissal::isValid('nope'));
    }

    public function testIsLatinMissal(): void
    {
        self::assertTrue(RomanMissal::isLatinMissal(RomanMissal::EDITIO_TYPICA_1970));
        self::assertTrue(RomanMissal::isLatinMissal(RomanMissal::EDITIO_TYPICA_TERTIA_2002));
        self::assertFalse(RomanMissal::isLatinMissal(RomanMissal::USA_EDITION_2011));
        self::assertFalse(RomanMissal::isLatinMissal('not-a-missal'));
    }

    public function testGetNameKnown(): void
    {
        self::assertSame('Editio Typica 1970', RomanMissal::getName(RomanMissal::EDITIO_TYPICA_1970));
        self::assertSame(
            '2011 Roman Missal issued by the USCCB',
            RomanMissal::getName(RomanMissal::USA_EDITION_2011)
        );
    }

    public function testGetNameUnknownThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid missal_id: nope');
        RomanMissal::getName('nope');
    }

    public function testGetSanctoraleFileNameForKnownEditionReturnsPath(): void
    {
        $path = RomanMissal::getSanctoraleFileName(RomanMissal::EDITIO_TYPICA_1970);
        self::assertIsString($path);
        self::assertStringContainsString('propriumdesanctis_1970/propriumdesanctis_1970.json', $path);
    }

    public function testGetSanctoraleFileNameReturnsFalseWhenNotAvailable(): void
    {
        self::assertFalse(RomanMissal::getSanctoraleFileName(RomanMissal::REIMPRESSIO_EMENDATA_1971));
    }

    public function testGetSanctoraleFileNameRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        RomanMissal::getSanctoraleFileName('nope');
    }

    public function testGetSanctoraleI18nFilePath(): void
    {
        $path = RomanMissal::getSanctoraleI18nFilePath(RomanMissal::EDITIO_TYPICA_1970);
        self::assertIsString($path);
        self::assertStringContainsString('propriumdesanctis_1970/i18n/', $path);
        self::assertFalse(RomanMissal::getSanctoraleI18nFilePath(RomanMissal::ITALY_EDITION_2020));
    }

    public function testGetSanctoraleI18nFilePathRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        RomanMissal::getSanctoraleI18nFilePath('nope');
    }

    public function testGetLectionaryFilePath(): void
    {
        // USA 2011 has lectionary; 1970 does not.
        $path = RomanMissal::getLectionaryFilePath(RomanMissal::USA_EDITION_2011);
        self::assertIsString($path);
        self::assertStringContainsString('propriumdesanctis_US_2011/lectionary/', $path);
        self::assertFalse(RomanMissal::getLectionaryFilePath(RomanMissal::EDITIO_TYPICA_1970));
    }

    public function testGetLectionaryFilePathRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        RomanMissal::getLectionaryFilePath('nope');
    }

    public function testGetYearLimits(): void
    {
        $limits = RomanMissal::getYearLimits(RomanMissal::ITALY_EDITION_1983);
        self::assertSame(1983, $limits['since_year']);
        self::assertSame(2002, $limits['until_year']);
        $limits2 = RomanMissal::getYearLimits(RomanMissal::EDITIO_TYPICA_1970);
        self::assertSame(1970, $limits2['since_year']);
        self::assertArrayNotHasKey('until_year', $limits2);
    }

    public function testGetYearLimitsRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        RomanMissal::getYearLimits('nope');
    }

    public function testGetMissalIdsIncludesEverything(): void
    {
        $ids = RomanMissal::getMissalIds();
        self::assertContains(RomanMissal::EDITIO_TYPICA_1970, $ids);
        self::assertContains(RomanMissal::USA_EDITION_2011, $ids);
        self::assertContains(RomanMissal::CANADA_EDITION_2016, $ids);
    }

    public function testGetLatinMissalIdsOnlyContainsEditioTypica(): void
    {
        $latin = RomanMissal::getLatinMissalIds();
        foreach ($latin as $id) {
            self::assertStringStartsWith('EDITIO_TYPICA_', $id);
        }
        self::assertNotContains(RomanMissal::USA_EDITION_2011, $latin);
        self::assertNotContains(RomanMissal::ITALY_EDITION_1983, $latin);
    }
}
