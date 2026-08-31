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
     * The compiled pattern table is a process-lifetime static, and `phpunit_tests/Handlers/`
     * sorts before `phpunit_tests/Services/`, so by the time this class runs in a full suite the
     * table has already been built by a handler test and every assertion below would exercise
     * only the lookup, never the construction it is meant to pin.
     */
    protected function setUp(): void
    {
        parent::setUp();
        SourceDataSchemaResolver::resetPatternCache();
    }

    /**
     * One path per family a write handler can stage — the union of every `stageFile()` call
     * site in `RegionalDataHandler`, `DecreesHandler`, `TestsHandler` and `MissalsHandler`.
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
            'supported locales resource'   => ['jsondata/supportedLocales.json', LitSchema::SUPPORTED_LOCALES],
            'roman test definition'        => ['jsondata/tests/roman/AllSaintsTest.json', LitSchema::TEST_SRC],
            'ambrosian test definition'    => ['jsondata/tests/ambrosian/AllSaintsTest.json', LitSchema::TEST_SRC],
            // Sanctorale entry writes (#943). The three families a MissalsHandler write stages,
            // plus the temporale — which is not staged by this handler but shares the missal
            // structure file's widened pattern and would otherwise be validated as a sanctorale.
            'missal sanctorale'            => ['jsondata/sourcedata/rite/roman/missals/propriumdesanctis_1970/propriumdesanctis_1970.json', LitSchema::PROPRIUMDESANCTIS],
            'missal sanctorale i18n'       => ['jsondata/sourcedata/rite/roman/missals/propriumdesanctis_1970/i18n/en.json', LitSchema::I18N],
            'missal lectionary sidecar'    => ['jsondata/sourcedata/rite/roman/missals/propriumdesanctis_US_2011/lectionary/en_US.json', LitSchema::LECTIONARY],
            'rite sanctorum lectionary'    => ['jsondata/sourcedata/rite/roman/lectionary/sanctorum/en.json', LitSchema::LECTIONARY],
            'temporale'                    => ['jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json', LitSchema::PROPRIUMDETEMPORE],
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
            // The missal families moved to stageablePaths() when sanctorale writes landed (#943).
            // The other lectionary sections have no write route, so nothing claims them.
            'lectionary sundays' => ['jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_A/en.json'],
            'world dioceses'     => ['jsondata/world_dioceses.json'],
            // A placeholder never crosses a `/`, so a deeper path is not swallowed by a
            // shallower family's pattern.
            'too deep'           => ['jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/regional/en.json'],
            'outside jsondata'   => ['src/Router.php'],
            'empty'              => [''],
        ];
    }

    #[DataProvider('unclaimedPaths')]
    public function testAPathNoFamilyClaimsResolvesToNull(string $path): void
    {
        self::assertNull(SourceDataSchemaResolver::forPath($path));
    }

    /**
     * The second lookup in a process takes the memo's early return rather than recompiling the
     * table. Asserted behaviourally — the table itself is private — so this pins that a warm
     * resolver keeps answering identically, which is the property the memo is there to preserve
     * and the one a future change to the caching would have to keep.
     */
    public function testASecondLookupAnswersIdenticallyFromTheCompiledTable(): void
    {
        $first = SourceDataSchemaResolver::forPath('jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en.json');

        // No reset between these two: setUp() has already cleared the table, so the first call
        // above built it and this one exercises the reuse path.
        $second = SourceDataSchemaResolver::forPath('jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en.json');

        self::assertSame($first, $second);
        self::assertNotNull($first);
    }
}
