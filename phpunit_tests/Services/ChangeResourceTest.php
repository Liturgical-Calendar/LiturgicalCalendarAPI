<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeResource::class)]
final class ChangeResourceTest extends TestCase
{
    public function testNationalCalendarUsesARiteQualifiedCalendarId(): void
    {
        // 'US', not 'USA': national_calendar object ids validate as ISO 3166-1
        // alpha-2 nation codes (AccessRequestRepository::isValidNationCode()), and
        // that is also the actual on-disk folder name (jsondata/sourcedata/rite/
        // roman/calendars/nations/US).
        $resource = ChangeResource::nationalCalendar(Rite::ROMAN, 'US');

        self::assertSame('national_calendar', $resource->type);
        self::assertSame('roman/US', $resource->id);
    }

    public function testDiocesanCalendarUsesARiteQualifiedCalendarId(): void
    {
        $resource = ChangeResource::diocesanCalendar(Rite::AMBROSIAN, 'lugano_ch');

        self::assertSame('diocesan_calendar', $resource->type);
        self::assertSame('ambrosian/lugano_ch', $resource->id);
    }

    public function testDecreesIsTheGeneralRomanCalendarDecreesObject(): void
    {
        $resource = ChangeResource::decrees();

        self::assertSame('general_roman_calendar', $resource->type);
        self::assertSame('decrees', $resource->id);
    }

    /**
     * A supported-locale set is not a calendar and is Roman by construction, so like the
     * decrees corpus it takes a bare, non-rite-qualified id on the general_roman_calendar
     * type. Reusing the existing type is what keeps this off the OpenFGA model: ids are not
     * part of the model, only types and relations are (issue #926).
     */
    public function testSupportedLocalesIsTheGeneralRomanCalendarSupportedLocalesObject(): void
    {
        $resource = ChangeResource::supportedLocales();

        self::assertSame('general_roman_calendar', $resource->type);
        self::assertSame('supported_locales', $resource->id);
        self::assertNotSame(ChangeResource::decrees()->branch(), $resource->branch());
    }

    public function testTestScopeIdsAreRiteQualified(): void
    {
        $resource = ChangeResource::test(Rite::AMBROSIAN, 'diocesan_calendar_test', 'lugano_ch');

        self::assertSame('diocesan_calendar_test', $resource->type);
        self::assertSame('ambrosian/lugano_ch', $resource->id);
    }

    public function testGeneralRomanCalendarTestIdStaysBare(): void
    {
        // general_roman_calendar_test is NOT in RITE_QUALIFIED_TEST_TYPES: it accepts
        // only the literal id 'general_roman_calendar' (isValidObjectIdForType()), so
        // rite-qualifying it would break validation rather than fix an ambiguity.
        $resource = ChangeResource::test(Rite::AMBROSIAN, 'general_roman_calendar_test', 'general_roman_calendar');

        self::assertSame('general_roman_calendar_test', $resource->type);
        self::assertSame('general_roman_calendar', $resource->id);
    }

    public function testRiteCalendarTestIdStaysBare(): void
    {
        // rite_calendar_test is NOT in RITE_QUALIFIED_TEST_TYPES: its id IS the rite
        // value itself (isValidObjectIdForType() requires Rite::tryFrom($objectId) to
        // succeed), so qualifying it would produce e.g. 'ambrosian/ambrosian'.
        $resource = ChangeResource::test(Rite::AMBROSIAN, 'rite_calendar_test', Rite::AMBROSIAN->value);

        self::assertSame('rite_calendar_test', $resource->type);
        self::assertSame('ambrosian', $resource->id);
    }

    public function testFgaPermissionAsksForTheAdminRelation(): void
    {
        $resource = ChangeResource::nationalCalendar(Rite::ROMAN, 'IT');

        self::assertSame(
            ['object_type' => 'national_calendar', 'object_id' => 'roman/IT', 'relation' => 'admin'],
            $resource->fgaPermission()
        );
    }

    public function testNationalCalendarWithAThreeLetterCodeFailsTheValidityInvariant(): void
    {
        // Documents why 'US' (not 'USA') is used above: national_calendar ids are
        // validated as ISO 3166-1 alpha-2 codes, and 'USA' is not one.
        $resource = ChangeResource::nationalCalendar(Rite::ROMAN, 'USA');

        self::assertFalse(AccessRequestRepository::isValidObjectIdForType($resource->type, $resource->id));
    }

    public function testBranchNameIsStablePerResource(): void
    {
        $resource = ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it');

        self::assertSame('litcal-data/diocesan_calendar/roman/romamo_it', $resource->branch());
        self::assertSame($resource->branch(), ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it')->branch());
    }

    public function testWiderRegionRejectsAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChangeResource::widerRegion('');
    }

    public function testWiderRegionIsQualifiedWithTheRomanRite(): void
    {
        $resource = ChangeResource::widerRegion('Americas');

        self::assertSame('wider_region', $resource->type);
        self::assertSame('roman/Americas', $resource->id);
    }

    /**
     * Pins the invariant that broke in the original draft of this class: every id
     * ChangeResource produces must satisfy AccessRequestRepository's own validation,
     * because Task 6 hands fgaPermission() straight to ResourceAdminService without
     * translation. A bare (non rite-qualified) calendar id passes ChangeResource's
     * own checks but is silently rejected by isValidObjectIdForType(), which would
     * have made every national/diocesan/wider-region admin's queue empty.
     *
     * Covers every object type ChangeResource can emit — both calendar tiers, wider
     * region, decrees, and all FOUR test() branches, including the two that must
     * stay unqualified (general_roman_calendar_test, rite_calendar_test). Those two
     * are otherwise unexercised anywhere else in this file, so without them here a
     * future change that wrongly added them to RITE_QUALIFIED_TEST_TYPES would pass
     * every other test in this suite.
     */
    public function testProducedIdsAreValidForTheirObjectType(): void
    {
        $resources = [
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            ChangeResource::diocesanCalendar(Rite::AMBROSIAN, 'lugano_ch'),
            ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it'),
            ChangeResource::widerRegion('Americas'),
            ChangeResource::decrees(),
            ChangeResource::supportedLocales(),
            ChangeResource::test(Rite::ROMAN, 'national_calendar_test', 'US'),
            ChangeResource::test(Rite::AMBROSIAN, 'diocesan_calendar_test', 'lugano_ch'),
            ChangeResource::test(Rite::AMBROSIAN, 'general_roman_calendar_test', 'general_roman_calendar'),
            ChangeResource::test(Rite::AMBROSIAN, 'rite_calendar_test', Rite::AMBROSIAN->value),
        ];

        foreach ($resources as $resource) {
            self::assertTrue(
                AccessRequestRepository::isValidObjectIdForType($resource->type, $resource->id),
                sprintf('%s:%s should be a valid object id', $resource->type, $resource->id)
            );
        }
    }
}
