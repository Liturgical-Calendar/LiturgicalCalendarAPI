<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\RiteCalendarObjectIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiteCalendarObjectIds::class)]
final class RiteCalendarObjectIdsTest extends TestCase
{
    /**
     * `getSanctoraleFileName()` resolves through `JsonData::path()`, which reads
     * `Router::$apiFilePath`. The bootstrap does not set it — see
     * phpunit_tests/LectionaryCorpusTest.php:28 for the same pattern.
     */
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public function testTheRomanSetReproducesTheLegacyGrcIds(): void
    {
        self::assertSame(
            ['temporale', 'decrees', 'supported_locales', 'EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008'],
            RiteCalendarObjectIds::forRite(Rite::ROMAN)
        );
    }

    public function testTheAmbrosianSetIsItsTemporaleAndItsTypicalEdition(): void
    {
        self::assertSame(
            ['temporale', 'EDITIO_TYPICA_2024'],
            RiteCalendarObjectIds::forRite(Rite::AMBROSIAN)
        );
    }

    /**
     * The derivation is `isEditioTypica() AND getSanctoraleFileName() !== false`, not
     * `isEditioTypica()` alone. Roman 1971/1975 and Ambrosian 1976 are typical editions
     * that ship no sanctorale; admitting them would make a permission grantable over a
     * resource with nothing to edit.
     */
    public function testTypicalEditionsWithoutSanctoraleDataAreExcluded(): void
    {
        $roman = RiteCalendarObjectIds::forRite(Rite::ROMAN);

        self::assertNotContains('EDITIO_TYPICA_1971', $roman);
        self::assertNotContains('EDITIO_TYPICA_1975', $roman);
        self::assertNotContains('EDITIO_TYPICA_1976', RiteCalendarObjectIds::forRite(Rite::AMBROSIAN));
    }

    public function testNationalEditionsAreNotRiteLevelResources(): void
    {
        $roman = RiteCalendarObjectIds::forRite(Rite::ROMAN);

        self::assertNotContains('US_2011', $roman);
        self::assertNotContains('IT_1983', $roman);
    }

    public function testDecreesAndSupportedLocalesAreRomanOnly(): void
    {
        $ambrosian = RiteCalendarObjectIds::forRite(Rite::AMBROSIAN);

        self::assertNotContains('decrees', $ambrosian);
        self::assertNotContains('supported_locales', $ambrosian);
    }

    public function testQualifiedIdsCarryTheirRite(): void
    {
        self::assertContains('roman/temporale', RiteCalendarObjectIds::qualifiedIdsForRite(Rite::ROMAN));
        self::assertContains('ambrosian/EDITIO_TYPICA_2024', RiteCalendarObjectIds::qualifiedIdsForRite(Rite::AMBROSIAN));
    }

    public function testValidationAcceptsOnlyQualifiedIdsOfTheOwningRite(): void
    {
        self::assertTrue(RiteCalendarObjectIds::isValid('roman/decrees'));
        self::assertTrue(RiteCalendarObjectIds::isValid('ambrosian/EDITIO_TYPICA_2024'));

        // Right sub-resource, wrong rite.
        self::assertFalse(RiteCalendarObjectIds::isValid('ambrosian/decrees'));
        self::assertFalse(RiteCalendarObjectIds::isValid('roman/EDITIO_TYPICA_2024'));

        // Bare (legacy) ids are not valid for the NEW type.
        self::assertFalse(RiteCalendarObjectIds::isValid('decrees'));
        self::assertFalse(RiteCalendarObjectIds::isValid('temporale'));

        // Unknown rite, unknown sub-resource, empty.
        self::assertFalse(RiteCalendarObjectIds::isValid('byzantine/temporale'));
        self::assertFalse(RiteCalendarObjectIds::isValid('roman/not_a_resource'));
        self::assertFalse(RiteCalendarObjectIds::isValid(''));
    }

    public function testAllQualifiedIdsCoversEveryRite(): void
    {
        $all = RiteCalendarObjectIds::allQualifiedIds();

        self::assertContains('roman/temporale', $all);
        self::assertContains('ambrosian/EDITIO_TYPICA_2024', $all);
        self::assertSame(count(array_unique($all)), count($all), 'ids must not repeat across rites');
    }

    public function testTheLabelNamesEveryValidId(): void
    {
        $label = RiteCalendarObjectIds::label();

        self::assertStringContainsString('roman/temporale', $label);
        self::assertStringContainsString('ambrosian/EDITIO_TYPICA_2024', $label);
    }

    // -----------------------------------------------------------------
    // The single definition of the legacy/successor pairing (#955)
    // -----------------------------------------------------------------
}
