<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `<rite>/<calendarId>` OpenFGA object id format shared by the test scopes
 * (issue #767) and the data resource types (#786).
 */
#[CoversClass(RiteScopedObjectId::class)]
final class RiteScopedObjectIdTest extends TestCase
{
    public function testQualifyComposesRiteAndCalendarId(): void
    {
        self::assertSame('ambrosian/lugano_ch', RiteScopedObjectId::qualify(Rite::AMBROSIAN, 'lugano_ch'));
        self::assertSame('roman/US', RiteScopedObjectId::qualify(Rite::ROMAN, 'US'));
    }

    public function testParseIsTheInverseOfQualify(): void
    {
        foreach (Rite::cases() as $rite) {
            $qualified = RiteScopedObjectId::qualify($rite, 'some_calendar');
            self::assertSame([$rite, 'some_calendar'], RiteScopedObjectId::parse($qualified));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unparseableIdProvider(): array
    {
        return [
            'unqualified legacy id' => ['rotter_nl'],
            'unknown rite'          => ['byzantine/foo'],
            'empty calendar id'     => ['roman/'],
            'empty string'          => [''],
            'separator only'        => ['/'],
        ];
    }

    #[DataProvider('unparseableIdProvider')]
    public function testParseReturnsNullForAnythingNotRiteQualified(string $objectId): void
    {
        self::assertNull(RiteScopedObjectId::parse($objectId));
    }

    public function testTheSameCalendarIdUnderTwoRitesYieldsDistinctObjectIds(): void
    {
        // The whole point: a bare `lugano_ch` grant would be ambiguous.
        self::assertNotSame(
            RiteScopedObjectId::qualify(Rite::ROMAN, 'lugano_ch'),
            RiteScopedObjectId::qualify(Rite::AMBROSIAN, 'lugano_ch')
        );
    }

    public function testCalendarIdStripsTheQualifierWhenPresent(): void
    {
        self::assertSame('lugano_ch', RiteScopedObjectId::calendarId('ambrosian/lugano_ch'));
        self::assertSame('US', RiteScopedObjectId::calendarId('roman/US'));
    }

    public function testCalendarIdPassesThroughAnUnqualifiedId(): void
    {
        // Legacy ids are still in the store for the whole migration window, so the
        // filesystem lookups that use this must keep working on both forms.
        self::assertSame('rotter_nl', RiteScopedObjectId::calendarId('rotter_nl'));
        self::assertSame('', RiteScopedObjectId::calendarId(''));
        self::assertSame('byzantine/foo', RiteScopedObjectId::calendarId('byzantine/foo'));
    }
}
