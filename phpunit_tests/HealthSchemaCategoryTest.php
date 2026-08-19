<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for `Health::retrieveSchemaForCategory()` — the `executeValidation` schema-resolution
 * strategy selector, which had none before issue #805.
 *
 * The three supported categories are NOT interchangeable: each resolves from a different input.
 * That is easy to miss, because the property is a bare string and choosing wrong does not fail
 * loudly — it yields a null schema, which the client renders as "Unable to detect schema for
 * dataPath …" long after the mistake. An automated reviewer proposed swapping two of them on
 * UnitTestInterface#41 for exactly this reason. These tests pin the distinction down.
 *
 * Note the argument is the caller's `$pathForSchema`, not the raw `sourceFile`: for
 * `sourceDataCheck` that is the `validate` slug; for the other two it is the `sourceFile`.
 */
#[CoversClass(Health::class)]
final class HealthSchemaCategoryTest extends TestCase
{
    private static bool $routerInitialized = false;

    /**
     * `LitSchema::path()` and `Route::path()` both need `Router`'s static paths resolved.
     * Data providers run *before* `setUpBeforeClass()`, so any provider that builds a
     * `Route::path()` must initialise them itself; the flag keeps repeat calls cheap.
     */
    private static function initRouter(): void
    {
        if (false === self::$routerInitialized) {
            Router::getApiPaths();
            self::$routerInitialized = true;
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::initRouter();
    }

    private static function retrieveSchemaForCategory(string $category, string $dataPath): ?string
    {
        $method = new \ReflectionMethod(Health::class, 'retrieveSchemaForCategory');
        /** @var string|null $result */
        $result = $method->invoke(null, $category, $dataPath);

        return $result;
    }

    // ---------------------------------------------------------------- universalcalendar

    /**
     * `universalcalendar` resolves from the source-data PATH.
     *
     * @return array<string, array{string, LitSchema}>
     */
    public static function universalCalendarPathProvider(): array
    {
        return [
            'roman temporale'      => [JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json', LitSchema::PROPRIUMDETEMPORE],
            'roman sanctorale'     => [JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2002/propriumdesanctis_2002.json', LitSchema::PROPRIUMDESANCTIS],
            'ambrosian temporale'  => [JsonData::AMBROSIAN_TEMPORALE_FILE->value, LitSchema::PROPRIUMDETEMPORE],
            'ambrosian sanctorale' => [JsonData::AMBROSIAN_SANCTORALE_FILE->value, LitSchema::PROPRIUMDESANCTIS],
        ];
    }

    #[DataProvider('universalCalendarPathProvider')]
    public function testUniversalCalendarResolvesFromTheSourceFilePath(string $path, LitSchema $expected): void
    {
        self::assertSame($expected->path(), self::retrieveSchemaForCategory('universalcalendar', $path));
    }

    // ---------------------------------------------------------------- sourceDataCheck

    /**
     * `sourceDataCheck` resolves from the `validate` SLUG.
     *
     * @return array<string, array{string, LitSchema}>
     */
    public static function sourceDataCheckSlugProvider(): array
    {
        return [
            'temporale'            => ['proprium-de-tempore', LitSchema::PROPRIUMDETEMPORE],
            'editio typica missal' => ['proprium-de-sanctis-2002', LitSchema::PROPRIUMDESANCTIS],
            'regional missal'      => ['proprium-de-sanctis-IT-1983', LitSchema::PROPRIUMDESANCTIS],
            'wider region'         => ['wider-region-Europe', LitSchema::WIDERREGION],
            'national calendar'    => ['national-calendar-US', LitSchema::NATIONAL],
            'diocesan calendar'    => ['diocesan-calendar-romamo_it', LitSchema::DIOCESAN],
            'decrees'              => ['memorials-from-decrees', LitSchema::DECREES_SRC],
            'test definition'      => ['tests-StIgnatiusOfLoyolaTest', LitSchema::TEST_SRC],
            'i18n folder suffix'   => ['national-calendar-US-i18n', LitSchema::I18N],
        ];
    }

    #[DataProvider('sourceDataCheckSlugProvider')]
    public function testSourceDataCheckResolvesFromTheValidateSlug(string $slug, LitSchema $expected): void
    {
        self::assertSame($expected->path(), self::retrieveSchemaForCategory('sourceDataCheck', $slug));
    }

    public function testSourceDataCheckRejectsAnUnrecognisedSlug(): void
    {
        self::assertNull(self::retrieveSchemaForCategory('sourceDataCheck', 'not-a-known-slug'));
    }

    // ---------------------------------------------------------------- resourceDataCheck

    /**
     * `resourceDataCheck` resolves from the API endpoint URL, either by matching a route shape
     * or — for the bare routes — by falling through to {@see Health::getPathToSchemaFile()}.
     *
     * @return array<string, array{string, LitSchema}>
     */
    public static function resourceDataCheckUrlProvider(): array
    {
        self::initRouter();

        return [
            'missal by id'      => [Route::MISSALS->path() . '/EDITIO_TYPICA_1970', LitSchema::PROPRIUMDESANCTIS],
            'events for nation' => [Route::EVENTS->path() . '/nation/US', LitSchema::EVENTS],
            'data for nation'   => [Route::DATA->path() . '/nation/US', LitSchema::NATIONAL],
            'data for diocese'  => [Route::DATA->path() . '/diocese/romamo_it', LitSchema::DIOCESAN],
            'data for region'   => [Route::DATA->path() . '/widerregion/Europe', LitSchema::WIDERREGION],
            'bare route'        => [Route::CALENDARS->path(), LitSchema::METADATA],
        ];
    }

    #[DataProvider('resourceDataCheckUrlProvider')]
    public function testResourceDataCheckResolvesFromTheEndpointUrl(string $url, LitSchema $expected): void
    {
        self::assertSame($expected->path(), self::retrieveSchemaForCategory('resourceDataCheck', $url));
    }

    public function testResourceDataCheckRejectsAnUnrecognisedUrl(): void
    {
        self::assertNull(self::retrieveSchemaForCategory('resourceDataCheck', 'https://example.test/not/a/route'));
    }

    public function testResourceDataCheckDoesNotResolveASourceDataCheckSlug(): void
    {
        // Completes the mismatch matrix: each category rejects the others' input.
        self::assertNull(self::retrieveSchemaForCategory('resourceDataCheck', 'national-calendar-US'));
    }

    // ---------------------------------------------------------------- the two are not interchangeable

    /**
     * The heart of #805 / UnitTestInterface#41: feeding one category the other's input
     * silently yields no schema. Both directions are asserted so neither can regress.
     */
    public function testSourceDataCheckDoesNotResolveAUniversalCalendarPath(): void
    {
        $path = JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json';

        // Sanity: the value really does resolve under its own category.
        self::assertSame(LitSchema::PROPRIUMDETEMPORE->path(), self::retrieveSchemaForCategory('universalcalendar', $path));

        self::assertNull(self::retrieveSchemaForCategory('sourceDataCheck', $path));
    }

    public function testUniversalCalendarDoesNotResolveASourceDataCheckSlug(): void
    {
        // Sanity: the slug really does resolve under its own category.
        self::assertSame(LitSchema::PROPRIUMDETEMPORE->path(), self::retrieveSchemaForCategory('sourceDataCheck', 'proprium-de-tempore'));

        self::assertNull(self::retrieveSchemaForCategory('universalcalendar', 'proprium-de-tempore'));
    }

    /**
     * The PascalCase labels the universal checks actually send. These are display/CSS labels,
     * never schema keys — which is precisely what the rejected UnitTestInterface#41 suggestion
     * would have fed to the slug matcher.
     *
     * @return array<string, array{string}>
     */
    public static function universalValidateLabelProvider(): array
    {
        return [
            'PropriumDeTempore'     => ['PropriumDeTempore'],
            'PropriumDeSanctis2002' => ['PropriumDeSanctis2002'],
            'PropriumDeSanctis2024' => ['PropriumDeSanctis2024'],
            'LitCalMetadata'        => ['LitCalMetadata'],
            'MemorialsFromDecrees'  => ['MemorialsFromDecrees'],
        ];
    }

    #[DataProvider('universalValidateLabelProvider')]
    public function testUniversalValidateLabelsAreNotSourceDataCheckSlugs(string $label): void
    {
        self::assertNull(self::retrieveSchemaForCategory('sourceDataCheck', $label));
    }

    // ---------------------------------------------------------------- removed legacy categories

    /**
     * Categories removed by #805. They were never sent by any client and had no coverage, but
     * they did resolve a schema — after which the caller left the raw `sourceFile` as the data
     * path, so the read failed anyway. They now return null, which reports the real problem.
     *
     * @return array<string, array{string}>
     */
    public static function removedCategoryProvider(): array
    {
        return [
            'nationalcalendar'    => ['nationalcalendar'],
            'diocesancalendar'    => ['diocesancalendar'],
            'widerregioncalendar' => ['widerregioncalendar'],
            'propriumdesanctis'   => ['propriumdesanctis'],
        ];
    }

    #[DataProvider('removedCategoryProvider')]
    public function testRemovedLegacyCategoriesResolveToNull(string $category): void
    {
        self::assertNull(self::retrieveSchemaForCategory($category, 'US'));
    }

    public function testUnknownCategoryResolvesToNull(): void
    {
        self::assertNull(self::retrieveSchemaForCategory('definitelyNotACategory', 'US'));
    }
}
