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
}
