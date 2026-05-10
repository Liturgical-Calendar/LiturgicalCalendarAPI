<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\JsonData;
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
    private static string $sourceDataPath;
    private static bool $routerInitialized = false;

    public static function setUpBeforeClass(): void
    {
        // Initialize the Router paths (required for JsonData::path() to work)
        Router::getApiPaths();

        // Get the paths
        self::$schemasPath    = JsonData::SCHEMAS_FOLDER->path();
        self::$sourceDataPath = JsonData::SOURCEDATA_FOLDER->path();
    }

    /**
     * Data provider for all schema files that should be importable.
     *
     * @return array<string, array{0: LitSchema}>
     */
    public static function schemaProvider(): array
    {
        return [
            'DiocesanCalendar'    => [LitSchema::DIOCESAN],
            'NationalCalendar'    => [LitSchema::NATIONAL],
            'PropriumDeSanctis'   => [LitSchema::PROPRIUMDESANCTIS],
            'PropriumDeTempore'   => [LitSchema::PROPRIUMDETEMPORE],
            'WiderRegionCalendar' => [LitSchema::WIDERREGION],
            'LitCalDecreesPath'   => [LitSchema::DECREES],
            'LitCalDecreesSource' => [LitSchema::DECREES_SRC],
            'LitCalTranslation'   => [LitSchema::I18N],
            'LitCalMetadata'      => [LitSchema::METADATA],
            'LitCal'              => [LitSchema::LITCAL],
            'LitCalEventsPath'    => [LitSchema::EVENTS],
            'LitCalTestsPath'     => [LitSchema::TESTS],
            'LitCalTest'          => [LitSchema::TEST_SRC],
            'LitCalMissalsPath'   => [LitSchema::MISSALS],
            'LitCalEasterPath'    => [LitSchema::EASTER],
            'LitCalDataPath'      => [LitSchema::DATA],
            'LitCalSchemasPath'   => [LitSchema::SCHEMAS],
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
     * Test loading a real national calendar source file against its schema.
     *
     * @group slow
     */
    public function testRealNationalCalendarValidation(): void
    {
        $schemaPath = LitSchema::NATIONAL->path();
        $schema     = Schema::import($schemaPath);

        // Load a real national calendar file (structure: nations/{NATION}/{NATION}.json)
        $usaCalendarPath = self::$sourceDataPath . '/calendars/nations/US/US.json';

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
        $dioceseBasePath = self::$sourceDataPath . '/calendars/dioceses';
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
        $widerRegionPath = self::$sourceDataPath . '/calendars/wider_regions';
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
        $sanctisPath = self::$sourceDataPath . '/missals/propriumdesanctis_1970/propriumdesanctis_1970.json';

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
        $temporePath = self::$sourceDataPath . '/missals/propriumdetempore/propriumdetempore.json';

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
        $decreesPath = self::$sourceDataPath . '/decrees/decrees.json';

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
     * Test loading a real test source file against its schema.
     *
     * @group slow
     */
    public function testRealTestSourceValidation(): void
    {
        $schemaPath = LitSchema::TEST_SRC->path();
        $schema     = Schema::import($schemaPath);

        // Try to find any test source file (in jsondata/tests/, not sourcedata)
        $testsPath = JsonData::TESTS_FOLDER->path();
        $files     = glob($testsPath . '/*.json');

        if (empty($files) || $files === false) {
            $this->markTestSkipped('No test source files found');
        }

        $testFile = $files[0];
        $content  = file_get_contents($testFile);
        $this->assertIsString($content);

        $data = json_decode($content);
        $this->assertNotNull($data, 'JSON decode should succeed for: ' . $testFile);

        // This should not throw
        $schema->in($data);
        $this->assertTrue(true, "Real test source file should pass validation: $testFile");
    }
}
