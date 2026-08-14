<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use PHPUnit\Framework\TestCase;

final class AccessRequestRepositoryConstantsTest extends TestCase
{
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
            ['temporale', 'EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'decrees'],
            AccessRequestRepository::GRC_OBJECT_IDS
        );

        foreach (AccessRequestRepository::GRC_OBJECT_IDS as $id) {
            self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', $id));
        }
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', 'EDITIO_TYPICA_1971'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', ''));
        // Other types keep free-form ids (non-empty)
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('national_calendar', 'IT'));
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

    public function testValidIdsLabelIsTypeAware(): void
    {
        self::assertStringContainsString('roman/US', AccessRequestRepository::validIdsLabelForType('national_calendar_test'));
        self::assertStringContainsString('ambrosian/lugano_ch', AccessRequestRepository::validIdsLabelForType('diocesan_calendar_test'));
        self::assertSame('roman, ambrosian', AccessRequestRepository::validIdsLabelForType('rite_calendar_test'));
        self::assertSame('general_roman_calendar', AccessRequestRepository::validIdsLabelForType('general_roman_calendar_test'));
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
}
