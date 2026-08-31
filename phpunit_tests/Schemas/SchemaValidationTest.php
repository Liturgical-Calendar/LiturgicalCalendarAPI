<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;

/**
 * Test suite for JSON Schema validation using swaggest/json-schema.
 *
 * These tests verify that:
 * 1. All schemas can be imported successfully
 * 2. Cross-file $ref references resolve correctly
 * 3. Real source data files validate against their schemas
 *
 * This is particularly important to ensure that removing $id from schema files
 * (if done) does not break schema validation functionality.
 */
class SchemaValidationTest extends TestCase
{
    private static string $schemasPath;
    private static bool $routerInitialized = false;

    public static function setUpBeforeClass(): void
    {
        // Initialize the Router paths (required for JsonData::path() to work)
        Router::getApiPaths();

        // Get the paths
        self::$schemasPath = JsonData::SCHEMAS_FOLDER->path();
    }

    /**
     * Assert that every liturgical color in a decoded source document is licit in the
     * rite whose tree the document belongs to.
     *
     * The source-data schemas cannot express this themselves: `PropriumDeSanctis.json`
     * (and `PropriumDeTempore.json`, and `DiocesanCalendar.json`) are shared by both
     * rite-partitioned trees, and JSON Schema cannot key a `color` facet off the rite of
     * the containing file — for the sanctorale and temporale files, the rite is not even
     * recorded *in* the file, only in its path. Since this suite already has separate
     * Roman and Ambrosian entry points per schema, the rite is known at the call site,
     * and the rite-scoped subsets `CommonDef.json#/definitions/{Roman,Ambrosian}LitColor`
     * can be applied there. Issue #771.
     *
     * @param mixed  $data    The decoded (stdClass/array) document.
     * @param Rite   $rite    The rite whose tree the document was loaded from.
     * @param string $context A human-readable identifier for the assertion message.
     */
    private function assertColorsLicitForRite(mixed $data, Rite $rite, string $context): void
    {
        $definition = match ($rite) {
            Rite::ROMAN     => 'RomanLitColor',
            Rite::AMBROSIAN => 'AmbrosianLitColor',
        };

        $commonDef = json_decode((string) file_get_contents(self::$schemasPath . '/CommonDef.json'));
        $this->assertInstanceOf(\stdClass::class, $commonDef);
        $this->assertObjectHasProperty($definition, $commonDef->definitions);

        $subset = Schema::import($commonDef->definitions->{$definition});

        $colorArrays = [];
        self::collectColorArrays($data, $colorArrays);
        $this->assertNotEmpty($colorArrays, "No color arrays found in $context — the assertion would be vacuous");

        foreach ($colorArrays as $colors) {
            // Throws InvalidValue if any entry is outside the rite's palette.
            $subset->in($colors);
        }

        $this->assertTrue(true, "All colors in $context are licit in the {$rite->value} rite");
    }

    /**
     * Collect every `color` array in an arbitrarily nested decoded document.
     *
     * @param mixed                $node
     * @param array<int,list<mixed>> $found
     */
    private static function collectColorArrays(mixed $node, array &$found): void
    {
        if ($node instanceof \stdClass) {
            $node = get_object_vars($node);
        }

        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'color') {
                // An event's `color` is an array; a `color_ad_libitum` entry's `color` is a
                // bare string (issue #781). Normalise both so the rite-scoped subset applies
                // to conditional colours too.
                if (is_array($value)) {
                    $found[] = array_values($value);
                    continue;
                }
                if (is_string($value)) {
                    $found[] = [$value];
                    continue;
                }
            }
            self::collectColorArrays($value, $found);
        }
    }

    /**
     * Data provider for all schema files that should be importable.
     *
     * @return array<string, array{0: LitSchema}>
     */
    public static function schemaProvider(): array
    {
        return [
            'DiocesanCalendar'         => [LitSchema::DIOCESAN],
            'NationalCalendar'         => [LitSchema::NATIONAL],
            'PropriumDeSanctis'        => [LitSchema::PROPRIUMDESANCTIS],
            'PropriumDeTempore'        => [LitSchema::PROPRIUMDETEMPORE],
            'WiderRegionCalendar'      => [LitSchema::WIDERREGION],
            'LitCalDecreesPath'        => [LitSchema::DECREES],
            'LitCalDecreesSource'      => [LitSchema::DECREES_SRC],
            'LitCalDecreeWritePayload' => [LitSchema::DECREE_WRITE],
            'LitCalMissalWritePayload' => [LitSchema::MISSAL_WRITE],
            'LitCalTranslation'        => [LitSchema::I18N],
            'LitCalMetadata'           => [LitSchema::METADATA],
            'LitCal'                   => [LitSchema::LITCAL],
            'LitCalEventsPath'         => [LitSchema::EVENTS],
            'LitCalTestsPath'          => [LitSchema::TESTS],
            'LitCalTest'               => [LitSchema::TEST_SRC],
            'LitCalMissalsPath'        => [LitSchema::MISSALS],
            'LitCalEasterPath'         => [LitSchema::EASTER],
            'LitCalDataPath'           => [LitSchema::DATA],
            'LitCalSchemasPath'        => [LitSchema::SCHEMAS],
        ];
    }

    /**
     * Test that all schemas can be imported successfully.
     */
    #[DataProvider('schemaProvider')]
    public function testSchemaCanBeImported(LitSchema $litSchema): void
    {
        // Initialize Router paths once (data provider runs before setUpBeforeClass)
        if (!self::$routerInitialized) {
            Router::getApiPaths();
            self::$routerInitialized = true;
        }

        $schemaPath = $litSchema->path();
        $this->assertFileExists($schemaPath, "Schema file should exist: $schemaPath");

        // This should not throw an exception
        $schema = Schema::import($schemaPath);
        $this->assertInstanceOf(Schema::class, $schema);
    }

    /**
     * Test that CommonDef.json can be imported (it contains shared definitions).
     */
    public function testCommonDefCanBeImported(): void
    {
        $commonDefPath = self::$schemasPath . '/CommonDef.json';
        $this->assertFileExists($commonDefPath);

        $schema = Schema::import($commonDefPath);
        $this->assertInstanceOf(Schema::class, $schema);
    }

    /**
     * Test that translation/i18n schema validates correctly.
     *
     * This schema references definitions and should validate simple key-value objects.
     */
    public function testI18nSchemaValidatesKeyValuePairs(): void
    {
        $schemaPath = LitSchema::I18N->path();
        $schema     = Schema::import($schemaPath);

        // Valid i18n data - simple key-value pairs
        $validData = (object) [
            'TestEvent'    => 'Test Event Name',
            'AnotherEvent' => 'Another Event Name',
        ];

        // This should not throw
        $schema->in($validData);
        $this->assertTrue(true, 'Valid i18n data should pass validation');
    }

    /**
     * Test that PropriumDeTempore schema validates correctly.
     */
    public function testPropriumDeTemporeSchemaValidatesMinimalData(): void
    {
        $schemaPath = LitSchema::PROPRIUMDETEMPORE->path();
        $schema     = Schema::import($schemaPath);

        // Valid proprium de tempore data - minimal array of events with event_key
        $validData = [
            (object) ['event_key' => 'Advent1'],
            (object) ['event_key' => 'Christmas'],
        ];

        // This should not throw
        $schema->in($validData);
        $this->assertTrue(true, 'Valid proprium de tempore data should pass validation');
    }

    /**
     * Test that invalid event_key pattern is rejected.
     *
     * This tests that cross-file $ref pattern validation works correctly.
     */
    public function testInvalidEventKeyPatternIsRejected(): void
    {
        $schemaPath = LitSchema::PROPRIUMDETEMPORE->path();
        $schema     = Schema::import($schemaPath);

        // Invalid data - event_key doesn't match the pattern from CommonDef.json
        $invalidData = [
            (object) ['event_key' => 'invalid-event-key-with-dashes'],
        ];

        $this->expectException(\Throwable::class);
        $schema->in($invalidData);
    }

    /**
     * `is_bvm` is optional and boolean in PropriumDeTempore.json.
     */
    public function testPropriumDeTemporeAcceptsOptionalIsBvmBoolean(): void
    {
        $schema = Schema::import(LitSchema::PROPRIUMDETEMPORE->path());

        $schema->in([
            (object) ['event_key' => 'ImmaculateHeart', 'is_bvm' => true],
            (object) ['event_key' => 'MaryMotherChurch', 'is_bvm' => false],
            (object) ['event_key' => 'Christmas'],
        ]);
        $this->assertTrue(true, 'is_bvm must be optional and accept booleans');
    }

    /**
     * The schema is the ONLY line of defence against a non-boolean `is_bvm`:
     * `PropriumDeTemporeEvent::fromObjectInternal()` guards with
     * `property_exists(...) && is_bool(...)`, which SILENTLY DROPS a non-boolean rather than
     * throwing, so a row carrying `"is_bvm": "true"` would load with `is_bvm === null` and be
     * ordered as a saint's memorial with nothing reporting the mistake.
     */
    public function testPropriumDeTemporeRejectsNonBooleanIsBvm(): void
    {
        $schema = Schema::import(LitSchema::PROPRIUMDETEMPORE->path());

        $this->expectException(\Throwable::class);
        $schema->in([(object) ['event_key' => 'ImmaculateHeart', 'is_bvm' => 'true']]);
    }

    /**
     * Test loading a real national calendar source file against its schema.
     *
     * @group slow
     */
    public function testRealNationalCalendarValidation(): void
    {
        $schemaPath = LitSchema::NATIONAL->path();
        $schema     = Schema::import($schemaPath);

        // Load a real national calendar file (structure: nations/{NATION}/{NATION}.json)
        $usaCalendarPath = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'US']);

        if (!file_exists($usaCalendarPath)) {
            $this->markTestSkipped('USA national calendar file not found');
        }

        $content = file_get_contents($usaCalendarPath);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed');

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, 'Real USA national calendar should pass validation');

        // National calendars have no `rite` field and exist only under the Roman tree.
        $this->assertColorsLicitForRite($data, Rite::ROMAN, 'the USA national calendar');
    }

    /**
     * Test loading a real diocesan calendar source file against its schema.
     *
     * @group slow
     */
    public function testRealDiocesanCalendarValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Try to find any diocesan calendar file
        // Structure: dioceses/{NATION}/{diocese_id}/*.json
        $dioceseBasePath = JsonData::DIOCESAN_CALENDARS_FOLDER->path();
        $nationDirs      = glob($dioceseBasePath . '/*', GLOB_ONLYDIR);

        if (empty($nationDirs) || $nationDirs === false) {
            $this->markTestSkipped('No diocesan calendar directories found');
        }

        // Find the first diocesan calendar file (nested in diocese_id folders)
        $diocesanFile = null;
        foreach ($nationDirs as $nationDir) {
            $dioceseDirs = glob($nationDir . '/*', GLOB_ONLYDIR);
            if (!empty($dioceseDirs) && $dioceseDirs !== false) {
                foreach ($dioceseDirs as $dioceseDir) {
                    $files = glob($dioceseDir . '/*.json');
                    if (!empty($files) && $files !== false) {
                        $diocesanFile = $files[0];
                        break 2;
                    }
                }
            }
        }

        if ($diocesanFile === null) {
            $this->markTestSkipped('No diocesan calendar files found');
        }

        $content = file_get_contents($diocesanFile);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed for: ' . $diocesanFile);

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, "Real diocesan calendar should pass validation: $diocesanFile");

        // DIOCESAN_CALENDARS_FOLDER is the Roman tree; the Ambrosian dioceses have their
        // own folder enum and their own entry point below.
        $this->assertColorsLicitForRite($data, Rite::ROMAN, $diocesanFile);
    }

    /**
     * Test loading a real wider region calendar source file against its schema.
     *
     * @group slow
     */
    public function testRealWiderRegionCalendarValidation(): void
    {
        $schemaPath = LitSchema::WIDERREGION->path();
        $schema     = Schema::import($schemaPath);

        // Try to find any wider region calendar file
        // Structure: wider_regions/{REGION}/{REGION}.json
        $widerRegionPath = JsonData::WIDER_REGIONS_FOLDER->path();
        $regionDirs      = glob($widerRegionPath . '/*', GLOB_ONLYDIR);

        if (empty($regionDirs) || $regionDirs === false) {
            $this->markTestSkipped('No wider region calendar directories found');
        }

        // Find the first wider region calendar JSON file
        $widerRegionFile = null;
        foreach ($regionDirs as $regionDir) {
            $files = glob($regionDir . '/*.json');
            if (!empty($files) && $files !== false) {
                $widerRegionFile = $files[0];
                break;
            }
        }

        if ($widerRegionFile === null) {
            $this->markTestSkipped('No wider region calendar files found');
        }
        $content = file_get_contents($widerRegionFile);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed for: ' . $widerRegionFile);

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, "Real wider region calendar should pass validation: $widerRegionFile");

        // No rite-scoped color assertion here: wider-region calendars are a layer above
        // the national calendars (Roman-only), and the shipped ones carry no `color` keys
        // at all — their actions patch existing events rather than create new ones. The
        // full-tree sweep in RiteSourceColorTest covers these files regardless.
    }

    /**
     * Test loading a real proprium de sanctis source file against its schema.
     *
     * @group slow
     */
    public function testRealPropriumDeSanctisValidation(): void
    {
        $schemaPath = LitSchema::PROPRIUMDESANCTIS->path();
        $schema     = Schema::import($schemaPath);

        // Try to find the 1970 proprium de sanctis file
        $sanctisPath = strtr(JsonData::MISSAL_FILE->path(), ['{missal_folder}' => 'propriumdesanctis_1970']);

        if (!file_exists($sanctisPath)) {
            $this->markTestSkipped('Proprium de Sanctis 1970 file not found');
        }

        $content = file_get_contents($sanctisPath);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed');

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, 'Real proprium de sanctis 1970 should pass validation');

        // PropriumDeSanctis.json is shared by both rite trees, so the rite-scoped
        // subset is asserted here rather than in the schema (see the Ambrosian
        // counterpart below).
        $this->assertColorsLicitForRite($data, Rite::ROMAN, 'the 1970 proprium de sanctis');
    }

    /**
     * Test loading the real Ambrosian comune sanctorale source file (2024 edition,
     * Plan 5) against the PropriumDeSanctis schema.
     *
     * @group slow
     */
    public function testRealAmbrosianPropriumDeSanctisValidation(): void
    {
        $schemaPath = LitSchema::PROPRIUMDESANCTIS->path();
        $schema     = Schema::import($schemaPath);

        $ambrosianSanctisPath = JsonData::AMBROSIAN_SANCTORALE_FILE->path();

        if (!file_exists($ambrosianSanctisPath)) {
            $this->markTestSkipped('Ambrosian comune sanctorale file not found');
        }

        $content = file_get_contents($ambrosianSanctisPath);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed');

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, 'Real Ambrosian comune sanctorale should pass validation');

        // The stray `purple` AllSouls row of issue #772 lived in this very file and
        // passed the shared schema; the Ambrosian subset is what rejects it.
        $this->assertColorsLicitForRite($data, Rite::AMBROSIAN, 'the Ambrosian comune sanctorale');
    }

    /**
     * Test loading all 4 real Ambrosian diocesan calendar source files (Tasks 4/5: milano_it,
     * bergam_it, novara_it, lugano_ch) against the DiocesanCalendar schema. This exercises the
     * optional `metadata.rite` field added in Task 1.
     *
     * @group slow
     */
    public function testRealAmbrosianDiocesanCalendarValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Structure: dioceses/{NATION}/{diocese_id}/*.json
        $dioceseBasePath = JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->path();
        $nationDirs      = glob($dioceseBasePath . '/*', GLOB_ONLYDIR);

        if (empty($nationDirs) || $nationDirs === false) {
            $this->fail('No Ambrosian diocesan calendar directories found at: ' . $dioceseBasePath);
        }

        // Collect every diocesan calendar file (nested in diocese_id folders)
        $diocesanFiles = [];
        foreach ($nationDirs as $nationDir) {
            $dioceseDirs = glob($nationDir . '/*', GLOB_ONLYDIR);
            if (empty($dioceseDirs) || $dioceseDirs === false) {
                continue;
            }
            foreach ($dioceseDirs as $dioceseDir) {
                $files = glob($dioceseDir . '/*.json');
                if (!empty($files) && $files !== false) {
                    array_push($diocesanFiles, ...$files);
                }
            }
        }

        // Fail loudly (don't silently pass) if the glob came up empty or short of the expected 4 dioceses
        $this->assertCount(
            4,
            $diocesanFiles,
            'Expected exactly 4 Ambrosian diocesan calendar files (milano_it, bergam_it, novara_it, lugano_ch), found: '
                . implode(', ', $diocesanFiles)
        );

        foreach ($diocesanFiles as $diocesanFile) {
            $content = file_get_contents($diocesanFile);
            $this->assertIsString($content);

            $data = json_decode($content);
            $this->assertNotNull($data, 'JSON decode should succeed for: ' . $diocesanFile);

            // This should not throw
            $schema->in($data);

            $this->assertColorsLicitForRite($data, Rite::AMBROSIAN, $diocesanFile);
        }

        $this->assertTrue(true, 'All 4 real Ambrosian diocesan calendars should pass validation');
    }

    /**
     * Test loading a real proprium de tempore source file against its schema.
     *
     * @group slow
     */
    public function testRealPropriumDeTemporeValidation(): void
    {
        $schemaPath = LitSchema::PROPRIUMDETEMPORE->path();
        $schema     = Schema::import($schemaPath);

        // Try to find the proprium de tempore file
        $temporePath = JsonData::TEMPORALE_FILE->path();

        if (!file_exists($temporePath)) {
            $this->markTestSkipped('Proprium de Tempore file not found');
        }

        $content = file_get_contents($temporePath);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed');

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, 'Real proprium de tempore should pass validation');

        $this->assertColorsLicitForRite($data, Rite::ROMAN, 'the Roman proprium de tempore');
    }

    /**
     * Test loading the real Ambrosian proprium de tempore source file against its schema.
     *
     * Uses the #[Group('slow')] attribute rather than a `@group slow` docblock: PHPUnit 12 in this
     * repo only honours the attribute form for `--group` filtering, so a docblock annotation here
     * would silently never be selected by `--group slow` (see the sibling Roman-temporale test above,
     * which still uses the docblock form and is therefore also not selectable that way).
     *
     * This exercises the file that now carries the five Pentecost-anchored celebrations added for the
     * Ambrosian rite (MaryMotherChurch, Trinity, CorpusChristi, SacredHeart, ImmaculateHeart), including
     * MaryMotherChurch's `since_year` field, the first real use of that schema property.
     */
    #[Group('slow')]
    public function testRealAmbrosianPropriumDeTemporeValidation(): void
    {
        $schemaPath = LitSchema::PROPRIUMDETEMPORE->path();
        $schema     = Schema::import($schemaPath);

        // Try to find the Ambrosian proprium de tempore file
        $temporePath = JsonData::AMBROSIAN_TEMPORALE_FILE->path();

        if (!file_exists($temporePath)) {
            $this->markTestSkipped('Ambrosian Proprium de Tempore file not found');
        }

        $content = file_get_contents($temporePath);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed');

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, 'Real Ambrosian proprium de tempore should pass validation');

        $this->assertColorsLicitForRite($data, Rite::AMBROSIAN, 'the Ambrosian proprium de tempore');
    }

    /**
     * Test loading a real decrees source file against its schema.
     *
     * @group slow
     */
    public function testRealDecreesSourceValidation(): void
    {
        $schemaPath = LitSchema::DECREES_SRC->path();
        $schema     = Schema::import($schemaPath);

        // Try to find the decrees source file
        $decreesPath = JsonData::DECREES_FILE->path();

        if (!file_exists($decreesPath)) {
            $this->markTestSkipped('Decrees source file not found');
        }

        $content = file_get_contents($decreesPath);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed');

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, 'Real decrees source should pass validation');
    }

    /**
     * Every real test source file must validate against its schema.
     *
     * Deliberately data-driven over the whole `jsondata/tests/` corpus rather
     * than a single `glob()[0]`: a requirement newly added to LitCalTest.json
     * (e.g. `applies_to.rite`, issue #767) has to be satisfied by *all* the
     * test files, and checking only the alphabetically-first one lets the rest
     * drift unnoticed.
     *
     * Uses the #[Group('slow')] attribute rather than a `@group slow` docblock,
     * which PHPUnit 12 does not honour — see the sibling Ambrosian temporale test
     * above. CI runs the whole suite (`composer test:coverage`, no exclusions), so
     * the corpus is still checked on every run.
     */
    #[Group('slow')]
    #[DataProvider('realTestSourceFileProvider')]
    public function testRealTestSourceValidation(string $testFile): void
    {
        $schemaPath = LitSchema::TEST_SRC->path();
        $schema     = Schema::import($schemaPath);

        $content = file_get_contents($testFile);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed for: ' . $testFile);

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, "Real test source file should pass validation: $testFile");
    }

    /**
     * @return array<string,array{string}>
     */
    public static function realTestSourceFileProvider(): array
    {
        // Test source files live in jsondata/tests/, partitioned by rite (#787:
        // jsondata/tests/{rite}/*.json), not sourcedata. Resolved from the repo
        // root rather than via JsonData::TESTS_FOLDER, because a data provider
        // runs before any test can initialise Router::$apiFilePath.
        $files = glob(dirname(__DIR__, 2) . '/jsondata/tests/*/*.json');
        if (empty($files) || $files === false) {
            return [];
        }

        $cases = [];
        foreach ($files as $file) {
            // Key on {rite}/{name}.json rather than the bare basename: the corpus is
            // partitioned by rite, so the same test name could exist under two rites,
            // and a bare-basename key would silently drop one of them.
            $key         = basename(dirname($file)) . '/' . basename($file);
            $cases[$key] = [$file];
        }
        return $cases;
    }
}
