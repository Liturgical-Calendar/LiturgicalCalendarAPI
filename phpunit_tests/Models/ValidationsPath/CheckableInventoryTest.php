<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The inventory of source data the API can validate (#806 step A).
 *
 * Its reason for existing is that the layout was written down in several places that had to be
 * edited in lockstep — the client's path constants, `Health`'s schema table, the `.vscode` globs —
 * and nothing failed loudly when they diverged. These tests pin the two properties that make one
 * list safe to rely on: it resolves everything the old table resolved, and its `region` mapping is
 * exactly what the client's scope predicate assumes.
 */
#[CoversClass(CheckableInventory::class)]
#[CoversClass(CheckableItem::class)]
final class CheckableInventoryTest extends TestCase
{
    private static string $savedApiPath = '';

    public static function setUpBeforeClass(): void
    {
        // JsonData cases build filesystem paths from this prefix.
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        // CalendarMetadataProvider::create() (pulled in via CheckableInventory::nationalCalendarItems()
        // and ::widerRegionItems()) reads Router::$apiPath while building each wider region's api_path.
        // isset() is false for typed-uninitialised properties.
        self::$savedApiPath = isset(Router::$apiPath) ? Router::$apiPath : '';
        Router::$apiPath    = '';
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
    }

    /**
     * The arms of `Health::getPathToSchemaFile()` that name source-data FILES, pasted here as the
     * oracle. The refactor in a later task is proved equivalent against this rather than assumed.
     * The route arms of that table are not source data and stay where they are.
     *
     * @return array<string, LitSchema>
     */
    private static function legacyFileTable(): array
    {
        return [
            JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json'                 => LitSchema::PROPRIUMDETEMPORE,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_1970/propriumdesanctis_1970.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2002/propriumdesanctis_2002.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2008/propriumdesanctis_2008.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_IT_1983/propriumdesanctis_IT_1983.json' => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_US_2011/propriumdesanctis_US_2011.json' => LitSchema::PROPRIUMDESANCTIS,
            JsonData::AMBROSIAN_TEMPORALE_FILE->value                                                     => LitSchema::PROPRIUMDETEMPORE,
            JsonData::AMBROSIAN_SANCTORALE_FILE->value                                                    => LitSchema::PROPRIUMDESANCTIS,
        ];
    }

    public function testItResolvesEverythingTheOldFileTableResolved(): void
    {
        foreach (self::legacyFileTable() as $path => $schema) {
            $item = CheckableInventory::byPath($path);
            self::assertNotNull($item, "no inventory entry for {$path}");
            self::assertSame($schema, $item->schema, "wrong schema for {$path}");
        }
    }

    public function testEveryItemIsFullyPopulatedAndIdsAreUnique(): void
    {
        $ids = [];
        foreach (CheckableInventory::all() as $item) {
            self::assertNotSame('', $item->id);
            self::assertContains($item->kind, ['file', 'folder']);
            self::assertNotSame('', $item->label);
            self::assertNotSame('', $item->path);
            // Three steps, or those three plus `covers` for an item that also declares which locales it
            // should hold. The two are not free to disagree: `covers` is advertised exactly when
            // `expectedLocales` is non-null, which is what the assertion below pins.
            self::assertContains(
                $item->steps,
                [['exists', 'parses', 'validates'], ['exists', 'parses', 'validates', 'covers']],
                "unexpected step list on {$item->id}"
            );
            self::assertSame(
                in_array('covers', $item->steps, true),
                null !== $item->expectedLocales,
                "{$item->id}: the covers step and expectedLocales disagree"
            );
            $ids[] = $item->id;
        }
        self::assertSame(array_unique($ids), $ids, 'inventory ids must be unique');
    }

    /**
     * Folders were i18n folders and nothing else until the lectionary corpus joined the inventory, so this
     * pinned `:i18n` directly. What it was really pinning is that a folder item is a folder of per-locale
     * JSON files validated one schema at a time — which the lectionary folders are too, under their own
     * schema. The `:i18n` / `:lectionary` split is now what tells them apart.
     */
    public function testEveryItemIsEitherAFileOrAFolderOfPerLocaleFiles(): void
    {
        $files   = 0;
        $folders = 0;
        foreach (CheckableInventory::all() as $item) {
            if ('folder' === $item->kind) {
                ++$folders;
                $isI18n       = str_ends_with($item->id, ':i18n');
                $isLectionary = str_ends_with($item->id, ':lectionary') || str_starts_with($item->id, 'lectionary:');
                self::assertTrue($isI18n || $isLectionary, "folder item {$item->id} is neither an i18n nor a lectionary folder");
                self::assertSame(
                    $isI18n ? LitSchema::I18N : LitSchema::LECTIONARY,
                    $item->schema,
                    "folder item {$item->id} validates against the wrong schema"
                );
            } else {
                ++$files;
                self::assertStringEndsNotWith(':i18n', $item->id, "file item {$item->id} looks like an i18n folder");
                self::assertStringEndsNotWith(':lectionary', $item->id, "file item {$item->id} looks like a lectionary folder");
            }
        }

        // The static half alone contributes nine of each; enumeration only adds.
        self::assertGreaterThanOrEqual(9, $files);
        self::assertGreaterThanOrEqual(9, $folders);
    }

    public function testTheFiveMissalsWithASanctoraleArePresentAndTheOthersAbsent(): void
    {
        $ids = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());

        foreach (['EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'US_2011', 'IT_1983'] as $missalId) {
            self::assertContains("sanctorale:roman:{$missalId}", $ids);
        }
        foreach (['EDITIO_TYPICA_1971', 'EDITIO_TYPICA_1975', 'IT_2020', 'NL_1978', 'CA_2011', 'CA_2016'] as $missalId) {
            self::assertNotContains("sanctorale:roman:{$missalId}", $ids);
        }
    }

    /**
     * The Ambrosian sanctorale is derived one item per edition from the missal registry, exactly as the
     * Roman one is. It used to be two singletons hard-wired to `propriumdesanctis_2024`, so a second
     * edition's sanctorale and translations would silently never have been validated (#963).
     *
     * Driven off `MissalCatalog::for(Rite::AMBROSIAN)` rather than a list written down here, because that
     * is the only thing that can tell derivation apart from a lucky singleton: the rite ships exactly one
     * edition with data today, so both approaches yield one item. Declaring a data-bearing edition on
     * `AmbrosianMissal` fails this test until the inventory carries it.
     *
     * The interface, not the enum — a third rite must need no edit in `CheckableInventory` or here.
     */
    public function testTheAmbrosianSanctoraleIsDerivedPerEditionAndSkipsDatalessEditions(): void
    {
        $source   = MissalCatalog::for(Rite::AMBROSIAN);
        $declared = $source->getMissalIds();
        $ids      = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());

        self::assertGreaterThan(
            1,
            count($declared),
            'the rite must declare more than one edition, or this test cannot distinguish a derivation from a singleton'
        );

        $expected = [];
        foreach ($declared as $missalId) {
            if (false === $source->getSanctoraleFileName($missalId)) {
                self::assertNotContains("sanctorale:ambrosian:{$missalId}", $ids, "{$missalId} ships no sanctorale data and must contribute no item");
                self::assertNotContains("sanctorale:ambrosian:{$missalId}:i18n", $ids);
                continue;
            }

            $expected[] = "sanctorale:ambrosian:{$missalId}";
            $expected[] = "sanctorale:ambrosian:{$missalId}:i18n";
            self::assertContains("sanctorale:ambrosian:{$missalId}", $ids);
            self::assertContains("sanctorale:ambrosian:{$missalId}:i18n", $ids);

            $item = CheckableInventory::byId("sanctorale:ambrosian:{$missalId}");
            self::assertNotNull($item);
            self::assertSame(Rite::AMBROSIAN, $item->rite);
            self::assertSame(LitSchema::PROPRIUMDESANCTIS, $item->schema);
            self::assertNull($item->region, 'the Ambrosian rite has no national tier, so region is always null');
            self::assertFileExists($item->path, "{$item->id} points at a file that does not exist");
            // The registry's own answer for THIS id, not the edition-pinned JsonData constant: that is the
            // difference the hard-wired version could not express.
            self::assertSame(
                $source->getSanctoraleFileName($missalId),
                $item->path,
                "{$item->id} must point at the file the registry declares for its own edition"
            );
        }

        self::assertNotEmpty($expected, 'at least one Ambrosian edition ships sanctorale data');

        // The unqualified singleton is gone, and nothing else claims to be an Ambrosian sanctorale.
        self::assertNotContains('sanctorale:ambrosian', $ids);
        self::assertNotContains('sanctorale:ambrosian:i18n', $ids);

        // The exact SET, not a count. `derivedAmbrosianSanctorale()`'s docblock plans for a
        // `sanctorale:ambrosian:{id}:lectionary` sibling once `getLectionaryFilePath()` returns a path; a
        // count assertion would fold that arrival into a bare `3 !== 2` that names nothing and sends the
        // next reader looking in the wrong place. As a set, the surprising id names itself in the diff and
        // adopting it is a deliberate one-line edit here.
        $actual = array_values(array_filter(
            $ids,
            static fn (string $id): bool => str_starts_with($id, 'sanctorale:ambrosian')
        ));
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'the inventory holds exactly the derived Ambrosian sanctorale items and nothing else');
    }

    /**
     * The mapping the client's scope predicate depends on: `null` means "applies to the whole rite",
     * a nation code means "only that nation's calendar". Deliberately NOT produceMetadata()'s 'VA',
     * which is a nation code and would be simply false on the Ambrosian items.
     */
    public function testRegionIsNullForUniversalItemsAndANationCodeForNationalEditions(): void
    {
        self::assertNull(CheckableInventory::byId('temporale:roman')?->region);
        self::assertNull(CheckableInventory::byId('sanctorale:roman:EDITIO_TYPICA_1970')?->region);
        self::assertNull(CheckableInventory::byId('temporale:ambrosian')?->region);
        self::assertNull(CheckableInventory::byId('sanctorale:ambrosian:EDITIO_TYPICA_2024')?->region);

        self::assertSame('US', CheckableInventory::byId('sanctorale:roman:US_2011')?->region);
        self::assertSame('IT', CheckableInventory::byId('sanctorale:roman:IT_1983')?->region);
    }

    /**
     * The client's predicate, applied here so the data is proved fit for it:
     *   item.rite === rite && (item.region === null || item.region === nation)
     */
    public function testTheClientScopePredicateSelectsTheRightItems(): void
    {
        $inScope = static fn (CheckableItem $i, Rite $rite, ?string $nation): bool
            => $i->rite === $rite && ( $i->region === null || $i->region === $nation );

        $usa = array_map(
            static fn (CheckableItem $i): string => $i->id,
            array_values(array_filter(
                CheckableInventory::all(),
                static fn (CheckableItem $i): bool => $inScope($i, Rite::ROMAN, 'US')
            ))
        );
        self::assertContains('sanctorale:roman:US_2011', $usa);
        self::assertNotContains('sanctorale:roman:IT_1983', $usa);
        self::assertContains('temporale:roman', $usa);

        $ambrosian = array_map(
            static fn (CheckableItem $i): string => $i->id,
            array_values(array_filter(
                CheckableInventory::all(),
                static fn (CheckableItem $i): bool => $inScope($i, Rite::AMBROSIAN, null)
            ))
        );
        sort($ambrosian);
        self::assertSame(
            [
                // Per edition, not one hard-wired pair: EDITIO_TYPICA_1976 is declared but ships no
                // sanctorale data, so it contributes nothing here (#963).
                'sanctorale:ambrosian:EDITIO_TYPICA_2024',
                'sanctorale:ambrosian:EDITIO_TYPICA_2024:i18n',
                'temporale:ambrosian',
                'temporale:ambrosian:i18n',
                // Test definitions are rite-scoped, not nation-scoped (region is always null), so the
                // repository's one Ambrosian test fixture is legitimately in scope here too.
                'test:ambrosian:StIgnatiusOfLoyolaTest',
            ],
            $ambrosian
        );
    }

    public function testTheSerializedFormNeverCarriesAPath(): void
    {
        foreach (CheckableInventory::all() as $item) {
            $encoded = json_encode($item, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('jsondata', $encoded, "item {$item->id} leaked a path");
            self::assertArrayNotHasKey('path', (array) json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testNationalCalendarsAreEnumeratedFromTheMetadataProvider(): void
    {
        $ids = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());

        // Italy is a national calendar the repository ships; if it ever stops being one, this test
        // should be updated deliberately rather than the assertion loosened.
        self::assertContains('nation:roman:IT', $ids);
        self::assertContains('nation:roman:IT:i18n', $ids);

        $italy = CheckableInventory::byId('nation:roman:IT');
        self::assertNotNull($italy);
        self::assertSame('file', $italy->kind);
        self::assertSame(Rite::ROMAN, $italy->rite);
        self::assertSame('IT', $italy->region, 'a national calendar is specific to its own nation');
        self::assertSame(LitSchema::NATIONAL, $italy->schema);
        self::assertStringContainsString('/calendars/nations/IT/IT.json', $italy->path);

        $italyI18n = CheckableInventory::byId('nation:roman:IT:i18n');
        self::assertNotNull($italyI18n);
        self::assertSame('folder', $italyI18n->kind);
        self::assertSame(LitSchema::I18N, $italyI18n->schema);
    }

    public function testVaticanIsExcludedBecauseItHasNoSourceDataOfItsOwn(): void
    {
        // The Vatican is announced as a national calendar (CalendarMetadataProvider hardcodes it
        // alongside the real per-nation entries) but is served by the General Roman Calendar and
        // has no nations/VA/ source folder — nothing here is checkable, so it must not be listed.
        self::assertNull(CheckableInventory::byId('nation:roman:' . ResourceExistenceChecker::VATICAN_NATIONAL_CALENDAR_ID));

        $vaticanPrefix = 'nation:roman:' . ResourceExistenceChecker::VATICAN_NATIONAL_CALENDAR_ID;
        foreach (CheckableInventory::all() as $item) {
            self::assertStringStartsNotWith($vaticanPrefix, $item->id, "unexpected Vatican item {$item->id}");
        }
    }

    public function testWiderRegionsAreEnumeratedAndAreNotNationScoped(): void
    {
        $europe = CheckableInventory::byId('widerregion:roman:Europe');
        self::assertNotNull($europe);
        self::assertSame('file', $europe->kind);
        self::assertSame(LitSchema::WIDERREGION, $europe->schema);
        self::assertNull(
            $europe->region,
            'a wider region spans several nations, which the scalar region cannot express; '
                . 'clients scope it via the wider_region field on /calendars instead'
        );

        self::assertNotNull(CheckableInventory::byId('widerregion:roman:Europe:i18n'));
    }

    public function testEveryEnumeratedItemStillHidesItsPath(): void
    {
        foreach (CheckableInventory::all() as $item) {
            self::assertStringNotContainsString('jsondata', json_encode($item, JSON_THROW_ON_ERROR));
        }
    }

    public function testDiocesanCalendarsAreEnumeratedUnderTheirOwnRite(): void
    {
        // The Diocese of Rome's registered calendar_id is `romamo_it` (the source folder basename
        // under jsondata/sourcedata/rite/roman/calendars/dioceses/IT/), not the id sketched in the
        // implementation plan this test is drawn from.
        $roman = CheckableInventory::byId('diocese:roman:romamo_it');
        self::assertNotNull($roman, 'the Diocese of Rome should be a checkable target');
        self::assertSame(Rite::ROMAN, $roman->rite);
        self::assertSame('IT', $roman->region, 'a diocesan calendar is scoped to its nation');
        self::assertSame(LitSchema::DIOCESAN, $roman->schema);
        self::assertNotNull(CheckableInventory::byId('diocese:roman:romamo_it:i18n'));
    }

    /**
     * The rite is not cosmetic here: an Ambrosian diocese lives under a different path template
     * entirely, so getting it wrong produces an item pointing at a file that does not exist — which
     * the exists step would report as a failure of the data rather than of this class.
     */
    public function testAnAmbrosianDioceseResolvesToTheAmbrosianTree(): void
    {
        $ambrosian = array_values(array_filter(
            CheckableInventory::all(),
            static fn (CheckableItem $i): bool => str_starts_with($i->id, 'diocese:ambrosian:')
                && false === str_ends_with($i->id, ':i18n')
        ));

        self::assertNotEmpty($ambrosian, 'the repository ships Ambrosian dioceses; none were enumerated');

        foreach ($ambrosian as $item) {
            self::assertSame(Rite::AMBROSIAN, $item->rite);
            self::assertStringContainsString('/rite/ambrosian/calendars/dioceses/', $item->path);
            self::assertFileExists($item->path, "{$item->id} points at a file that does not exist");
        }
    }

    /**
     * A test *definition* is a source artifact: does the JSON match LitCalTest.json. That is a different
     * thing from running the test against a computed calendar, which stays a separate action.
     */
    public function testTestDefinitionsAreEnumeratedPerRite(): void
    {
        $ids     = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());
        $testIds = array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'test:')));

        self::assertNotEmpty($testIds, 'the repository ships test definitions; none were enumerated');

        foreach ($testIds as $id) {
            self::assertMatchesRegularExpression('/^test:(roman|ambrosian):[A-Za-z0-9_]+$/', $id);
            self::assertStringEndsNotWith(':i18n', $id, 'test definitions have no translations folder');
        }

        foreach (CheckableInventory::all() as $item) {
            if (str_starts_with($item->id, 'test:')) {
                self::assertSame('file', $item->kind);
                self::assertSame(LitSchema::TEST_SRC, $item->schema);
                self::assertNull($item->region, 'a test definition is not nation-scoped');
                self::assertFileExists($item->path, "{$item->id} points at a file that does not exist");
            }
        }
    }

    /**
     * A failed `glob()` raises; it does not quietly produce an empty set of test definitions.
     *
     * `glob()` returns an empty array — never `false` — for a readable directory with no matches, so
     * `false` only ever means a filesystem error. Swallowing it would make all 11 test definitions
     * vanish from `/validations` with nothing reported anywhere: #800's silent absence, in the one
     * producer that reads the filesystem directly rather than going through the calendar index.
     *
     * The error is induced through `glob()`'s real 4096-character pattern limit rather than by
     * mocking, so what is under test is the production call itself. PHP raises a warning before
     * returning the sentinel; it is swallowed here so the assertion is about the exception and not
     * about PHPUnit's warning handling.
     */
    public function testAFailedGlobRaisesInsteadOfDroppingEveryTestDefinition(): void
    {
        $savedApiFilePath = Router::$apiFilePath;

        // Long enough that JsonData::testsFolderFor()'s pattern exceeds glob()'s maximum length.
        Router::$apiFilePath = DIRECTORY_SEPARATOR . str_repeat('a', 5000) . DIRECTORY_SEPARATOR;

        $producer = new \ReflectionMethod(CheckableInventory::class, 'testDefinitionItems');
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/glob failed/');

            $producer->invoke(null);
        } finally {
            restore_error_handler();
            Router::$apiFilePath = $savedApiFilePath;
        }
    }
}
