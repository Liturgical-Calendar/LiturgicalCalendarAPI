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

    public function testARomanFixedSubResourcePairsWithItsBareLegacyObject(): void
    {
        self::assertSame(
            ['general_roman_calendar', 'decrees'],
            RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'roman/decrees')
        );
        self::assertSame(
            ['general_roman_calendar', 'temporale'],
            RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'roman/temporale')
        );
        self::assertSame(
            ['general_roman_calendar', 'supported_locales'],
            RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'roman/supported_locales')
        );
    }

    /**
     * The asymmetry the middleware relies on, asserted at its source. A fixed sub-resource names a
     * different resource in each rite, so pairing an Ambrosian one with the Roman legacy object
     * would carry a Roman grant across rites — exactly the un-qualification #955 removes.
     */
    public function testANonRomanFixedSubResourceHasNoLegacyCounterpart(): void
    {
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'ambrosian/temporale'));
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'ambrosian/decrees'));
    }

    /**
     * Typical editions go the other way: missal ids are unique across rites, so
     * `general_roman_calendar:EDITIO_TYPICA_2024` genuinely denoted the AMBROSIAN edition.
     */
    public function testATypicalEditionPairsInEveryRite(): void
    {
        self::assertSame(
            ['general_roman_calendar', 'EDITIO_TYPICA_2002'],
            RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'roman/EDITIO_TYPICA_2002')
        );
        self::assertSame(
            ['general_roman_calendar', 'EDITIO_TYPICA_2024'],
            RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'ambrosian/EDITIO_TYPICA_2024')
        );
    }

    /**
     * The edition has to belong to the rite the id names. Without this, revoking the bogus
     * `rite_calendar:roman/EDITIO_TYPICA_2024` would reach the AMBROSIAN edition's legacy tuple.
     */
    public function testATypicalEditionDoesNotPairUnderAnotherRitesPrefix(): void
    {
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'roman/EDITIO_TYPICA_2024'));
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'ambrosian/EDITIO_TYPICA_2002'));
    }

    public function testNothingOutsideTheTierHasALegacyCounterpart(): void
    {
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('national_calendar', 'roman/US'));
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'decrees'), 'an unqualified id names no rite');
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'byzantine/temporale'));
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', 'roman/not_a_resource'));
        self::assertNull(RiteCalendarObjectIds::legacyCounterpart('rite_calendar', ''));
    }

    public function testTheReverseDirectionRecoversTheRiteFromTheBareLegacyId(): void
    {
        self::assertSame(
            ['rite_calendar', 'roman/decrees'],
            RiteCalendarObjectIds::riteCounterpart('general_roman_calendar', 'decrees')
        );
        self::assertSame(
            ['rite_calendar', 'roman/EDITIO_TYPICA_2002'],
            RiteCalendarObjectIds::riteCounterpart('general_roman_calendar', 'EDITIO_TYPICA_2002')
        );
        self::assertSame(
            ['rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'],
            RiteCalendarObjectIds::riteCounterpart('general_roman_calendar', 'EDITIO_TYPICA_2024'),
            'the Ambrosian typical edition is recovered from its bare id, not assumed Roman'
        );
    }

    public function testNothingOutsideTheLegacyTypeHasARiteCounterpart(): void
    {
        self::assertNull(RiteCalendarObjectIds::riteCounterpart('rite_calendar', 'roman/decrees'));
        self::assertNull(RiteCalendarObjectIds::riteCounterpart('national_calendar', 'roman/US'));
        self::assertNull(RiteCalendarObjectIds::riteCounterpart('general_roman_calendar', 'not_a_resource'));
        self::assertNull(RiteCalendarObjectIds::riteCounterpart('general_roman_calendar', 'US_2011'), 'a national edition was never a rite-level resource');
        self::assertNull(RiteCalendarObjectIds::riteCounterpart('general_roman_calendar', ''));
    }

    /**
     * The two directions must be inverses, or a revoke aimed at one spelling would close a
     * different tuple than a revoke aimed at the other.
     */
    public function testTheTwoDirectionsAreInverses(): void
    {
        foreach (RiteCalendarObjectIds::allQualifiedIds() as $objectId) {
            $legacy = RiteCalendarObjectIds::legacyCounterpart('rite_calendar', $objectId);
            if ($legacy === null) {
                continue;
            }

            self::assertSame(
                ['rite_calendar', $objectId],
                RiteCalendarObjectIds::riteCounterpart($legacy[0], $legacy[1]),
                sprintf('%s must round-trip through its legacy counterpart', $objectId)
            );
        }
    }
}
