<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Targeted coverage for `Health::getPathToSchemaFile()`, the private lookup table the WebSocket
 * test-interface ("Health-wired" validation) uses to map a known source-data file path to the
 * schema it must validate against.
 *
 * Plan 5 / Task 6 registers the Ambrosian comune sanctorale data file here so that the
 * UnitTestInterface schema-validation flow covers it exactly like the pre-existing Roman
 * proprium de sanctis editions.
 */
#[CoversClass(Health::class)]
final class HealthSchemaMappingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path()/LitSchema::path() both depend on Router::$apiFilePath.
        Router::getApiPaths();
    }

    private static function getPathToSchemaFile(string $dataFile): ?string
    {
        $method = new \ReflectionMethod(Health::class, 'getPathToSchemaFile');
        /** @var string|null $result */
        $result = $method->invoke(null, $dataFile);

        return $result;
    }

    public function testAmbrosianSanctoraleFileMapsToPropriumDeSanctisSchema(): void
    {
        $result = self::getPathToSchemaFile(JsonData::AMBROSIAN_SANCTORALE_FILE->value);

        $this->assertSame(LitSchema::PROPRIUMDESANCTIS->path(), $result);
    }

    public function testRoman1970SanctoraleFileStillMapsToPropriumDeSanctisSchema(): void
    {
        // Guards against the new Ambrosian match arm accidentally shadowing/breaking the
        // pre-existing Roman entries in the same match expression.
        $result = self::getPathToSchemaFile(
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_1970/propriumdesanctis_1970.json'
        );

        $this->assertSame(LitSchema::PROPRIUMDESANCTIS->path(), $result);
    }

    public function testAmbrosianTemporaleFileMapsToPropriumDeTemporeSchema(): void
    {
        // The sanctorale arm was registered on its own, leaving the Ambrosian temporale as the
        // one shipped missal file no client could schema-validate (issue #800). Every Roman
        // missal registers both halves; the Ambrosian rite must too.
        $result = self::getPathToSchemaFile(JsonData::AMBROSIAN_TEMPORALE_FILE->value);

        $this->assertSame(LitSchema::PROPRIUMDETEMPORE->path(), $result);
    }

    public function testRomanTemporaleFileStillMapsToPropriumDeTemporeSchema(): void
    {
        // Guards against the new Ambrosian temporale arm shadowing the pre-existing Roman one,
        // which lives in the same match expression.
        $result = self::getPathToSchemaFile(
            JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json'
        );

        $this->assertSame(LitSchema::PROPRIUMDETEMPORE->path(), $result);
    }

    public function testAmbrosianTemporaleAndSanctoraleMapToDistinctSchemas(): void
    {
        // The two Ambrosian files sit next to each other under the same missals folder and differ
        // only by leaf name, so a copy-paste slip would silently point both at one schema.
        $temporale  = self::getPathToSchemaFile(JsonData::AMBROSIAN_TEMPORALE_FILE->value);
        $sanctorale = self::getPathToSchemaFile(JsonData::AMBROSIAN_SANCTORALE_FILE->value);

        $this->assertNotSame($temporale, $sanctorale);
    }

    public function testUnknownFileMapsToNull(): void
    {
        $result = self::getPathToSchemaFile('/some/unregistered/path.json');

        $this->assertNull($result);
    }
}
