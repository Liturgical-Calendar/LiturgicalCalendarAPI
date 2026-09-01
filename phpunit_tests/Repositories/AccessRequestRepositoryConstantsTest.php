<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;

final class AccessRequestRepositoryConstantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public function testGeneralRomanCalendarIsAValidObjectType(): void
    {
        self::assertContains('general_roman_calendar', AccessRequestRepository::VALID_OBJECT_TYPES);
    }

    public function testCalendarEditorAndDeveloperCanHoldGeneralRomanCalendar(): void
    {
        self::assertContains('general_roman_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor']);
        self::assertContains('general_roman_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['developer']);
        self::assertNotContains('general_roman_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor']);
    }

    public function testGrcObjectIdValidation(): void
    {
        // Independently pin the exact set of valid ids, so this test fails if the
        // production constant gains, loses, or reorders an entry.
        self::assertSame(
            ['temporale', 'EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'EDITIO_TYPICA_2024', 'decrees', 'supported_locales'],
            AccessRequestRepository::GRC_OBJECT_IDS
        );

        foreach (AccessRequestRepository::GRC_OBJECT_IDS as $id) {
            self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', $id));
        }
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', 'EDITIO_TYPICA_1971'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', ''));
        // Calendar-naming types require a rite-qualified id (issue #786).
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('national_calendar', 'roman/IT'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('national_calendar', 'IT'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('national_calendar', ''));
    }

    public function testNewTestTypesAreValid(): void
    {
        foreach (['national_calendar_test', 'diocesan_calendar_test', 'general_roman_calendar_test', 'rite_calendar_test'] as $t) {
            self::assertContains($t, AccessRequestRepository::VALID_OBJECT_TYPES);
        }
        self::assertNotContains('test_definition', AccessRequestRepository::VALID_OBJECT_TYPES);
        self::assertSame(
            ['national_calendar_test', 'diocesan_calendar_test', 'general_roman_calendar_test', 'rite_calendar_test'],
            AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor']
        );
    }

    public function testRiteCalendarTestObjectIdMustBeAKnownRite(): void
    {
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('rite_calendar_test', 'roman'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('rite_calendar_test', 'ambrosian'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar_test', 'byzantine'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar_test', 'general_roman_calendar'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar_test', ''));
    }

    public function testGrcTestObjectIdMustBeFixed(): void
    {
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar_test', 'general_roman_calendar'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar_test', 'temporale'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar_test', ''));
    }

    public function testScopedCalendarTestTypesRequireRiteQualifiedIds(): void
    {
        // A bare calendar id does not identify a calendar — `lugano_ch` could be
        // Ambrosian or Roman — so a scoped test grant must name the rite.
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('national_calendar_test', 'roman/IT'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('diocesan_calendar_test', 'roman/romamo_it'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('diocesan_calendar_test', 'ambrosian/lugano_ch'));

        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('national_calendar_test', 'IT'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('diocesan_calendar_test', 'romamo_it'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('national_calendar_test', ''));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('diocesan_calendar_test', ''));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('diocesan_calendar_test', 'byzantine/foo'));
    }

    public function testOnlyTheRomanRiteHasANationalTier(): void
    {
        // /calendar/ambrosian/nation/{id} is a 400 — there is no Ambrosian
        // national calendar to grant against.
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('national_calendar_test', 'ambrosian/IT'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('diocesan_calendar_test', 'ambrosian/lugano_ch'));
    }

    /**
     * Every arm of the label map, because the label is the only thing a rejected
     * caller sees: an arm that silently falls through to the General Roman
     * Calendar's ids tells them nothing useful.
     */
    public function testValidIdsLabelIsTypeAware(): void
    {
        self::assertStringContainsString('roman/US', AccessRequestRepository::validIdsLabelForType('national_calendar_test'));
        self::assertStringContainsString('ambrosian/lugano_ch', AccessRequestRepository::validIdsLabelForType('diocesan_calendar_test'));
        self::assertSame('roman, ambrosian', AccessRequestRepository::validIdsLabelForType('rite_calendar_test'));
        self::assertSame('general_roman_calendar', AccessRequestRepository::validIdsLabelForType('general_roman_calendar_test'));

        self::assertSame(
            implode(', ', AccessRequestRepository::GRC_OBJECT_IDS),
            AccessRequestRepository::validIdsLabelForType('general_roman_calendar')
        );
        self::assertStringContainsString('roman/US', AccessRequestRepository::validIdsLabelForType('national_calendar'));
        self::assertStringContainsString('ambrosian/lugano_ch', AccessRequestRepository::validIdsLabelForType('diocesan_calendar'));
        self::assertStringContainsString('roman/Europe', AccessRequestRepository::validIdsLabelForType('wider_region'));
        self::assertSame('any non-empty id', AccessRequestRepository::validIdsLabelForType('something_unknown'));
    }

    public function testValidRelationsHasNoDeleter(): void
    {
        self::assertSame(['admin', 'viewer', 'editor'], AccessRequestRepository::VALID_RELATIONS);
    }

    public function testOperationalRelationsExcludeAdmin(): void
    {
        self::assertSame(['viewer', 'editor'], AccessRequestRepository::OPERATIONAL_RELATIONS);
        self::assertNotContains('admin', AccessRequestRepository::OPERATIONAL_RELATIONS);
    }

    public function testRiteCalendarIsAValidObjectType(): void
    {
        self::assertContains('rite_calendar', AccessRequestRepository::VALID_OBJECT_TYPES);
    }

    public function testRiteCalendarIsHeldByTheCalendarEditingRoles(): void
    {
        self::assertContains('rite_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor']);
        self::assertContains('rite_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['developer']);
        self::assertNotContains('rite_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor']);
    }

    public function testRiteCalendarIdsMustBeRiteQualified(): void
    {
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'roman/decrees'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'decrees'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'ambrosian/decrees'));
    }

    /**
     * The legacy type keeps validating for the whole migration window. Dropping it here
     * would refuse to re-grant a permission that is still live in the store.
     */
    public function testTheLegacyGeneralRomanCalendarTypeStillValidates(): void
    {
        self::assertContains('general_roman_calendar', AccessRequestRepository::VALID_OBJECT_TYPES);
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', 'decrees'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar_test', 'general_roman_calendar'));
    }

    public function testTheRiteCalendarErrorLabelNamesQualifiedIds(): void
    {
        $label = AccessRequestRepository::validIdsLabelForType('rite_calendar');

        self::assertStringContainsString('roman/decrees', $label);
        self::assertStringContainsString('ambrosian/EDITIO_TYPICA_2024', $label);
    }
}
