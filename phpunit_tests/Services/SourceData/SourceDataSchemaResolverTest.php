<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataSchemaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceDataSchemaResolver::class)]
final class SourceDataSchemaResolverTest extends TestCase
{
    /**
     * One path per family a write handler can stage — the union of every `stageFile()` call
     * site in `RegionalDataHandler`, `DecreesHandler` and `TestsHandler`.
     *
     * A `null` here would mean a change request whose content the #918 approval gate cannot
     * check, so this list is the gate's actual coverage written down.
     *
     * @return array<string, array{string, LitSchema}>
     */
    public static function stageablePaths(): array
    {
        return [
            'national calendar'            => ['jsondata/sourcedata/rite/roman/calendars/nations/US/US.json', LitSchema::NATIONAL],
            'national calendar i18n'       => ['jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en.json', LitSchema::I18N],
            'national calendar lectionary' => ['jsondata/sourcedata/rite/roman/calendars/nations/US/lectionary/en.json', LitSchema::LECTIONARY],
            'diocesan calendar'            => ['jsondata/sourcedata/rite/roman/calendars/dioceses/US/boston_us/Archdiocese of Boston.json', LitSchema::DIOCESAN],
            'diocesan calendar i18n'       => ['jsondata/sourcedata/rite/roman/calendars/dioceses/US/boston_us/i18n/en.json', LitSchema::I18N],
            'diocesan calendar lectionary' => ['jsondata/sourcedata/rite/roman/calendars/dioceses/US/boston_us/lectionary/en.json', LitSchema::LECTIONARY],
            'ambrosian diocesan calendar'  => ['jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it/Milano.json', LitSchema::DIOCESAN],
            'ambrosian diocesan i18n'      => ['jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it/i18n/it.json', LitSchema::I18N],
            'wider region'                 => ['jsondata/sourcedata/rite/roman/calendars/wider_regions/Americas/Americas.json', LitSchema::WIDERREGION],
            'wider region i18n'            => ['jsondata/sourcedata/rite/roman/calendars/wider_regions/Americas/i18n/en.json', LitSchema::I18N],
            'wider region lectionary'      => ['jsondata/sourcedata/rite/roman/calendars/wider_regions/Americas/lectionary/en.json', LitSchema::LECTIONARY],
            'decrees corpus'               => ['jsondata/sourcedata/rite/roman/decrees/decrees.json', LitSchema::DECREES_SRC],
            'decrees i18n sidecar'         => ['jsondata/sourcedata/rite/roman/decrees/i18n/en.json', LitSchema::I18N],
            'decrees lectionary sidecar'   => ['jsondata/sourcedata/rite/roman/decrees/lectionary/en.json', LitSchema::LECTIONARY],
            'roman test definition'        => ['jsondata/tests/roman/AllSaintsTest.json', LitSchema::TEST_SRC],
            'ambrosian test definition'    => ['jsondata/tests/ambrosian/AllSaintsTest.json', LitSchema::TEST_SRC],
        ];
    }

    #[DataProvider('stageablePaths')]
    public function testEveryStageablePathFamilyResolvesToItsSchema(string $path, LitSchema $expected): void
    {
        self::assertSame($expected, SourceDataSchemaResolver::forPath($path));
    }

    /**
     * The `path` column stores the unprefixed form, but a caller holding the leading-slash
     * spelling must not silently get "no schema" — that would read as "not validated".
     */
    public function testALeadingSlashIsTolerated(): void
    {
        self::assertSame(
            LitSchema::NATIONAL,
            SourceDataSchemaResolver::forPath('/jsondata/sourcedata/rite/roman/calendars/nations/US/US.json')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unclaimedPaths(): array
    {
        return [
            // No write handler stages a missal, a temporale or the lectionary corpus, so no
            // pattern claims them. If one ever does, this expectation is the thing that has
            // to change with it.
            'missal sanctorale' => ['jsondata/sourcedata/rite/roman/missals/propriumdesanctis_1970/propriumdesanctis_1970.json'],
            'temporale'         => ['jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json'],
            'lectionary corpus' => ['jsondata/sourcedata/rite/roman/lectionary/sanctorum/en.json'],
            'world dioceses'    => ['jsondata/world_dioceses.json'],
            // A placeholder never crosses a `/`, so a deeper path is not swallowed by a
            // shallower family's pattern.
            'too deep'          => ['jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/regional/en.json'],
            'outside jsondata'  => ['src/Router.php'],
            'empty'             => [''],
        ];
    }

    #[DataProvider('unclaimedPaths')]
    public function testAPathNoFamilyClaimsResolvesToNull(string $path): void
    {
        self::assertNull(SourceDataSchemaResolver::forPath($path));
    }
}
