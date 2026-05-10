<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\InvalidValue;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;

/**
 * Test suite for validating frontend payloads against JSON schemas.
 *
 * NOTE: This test intentionally extends TestCase rather than ApiTestCase.
 * Unlike integration tests in phpunit_tests/Routes/ that make HTTP requests
 * to a running API, this is a unit test that validates JSON payloads against
 * schemas locally using Swaggest\JsonSchema. It does not require the API
 * server to be running and should execute quickly without network overhead.
 *
 * These tests ensure that:
 * 1. Sample payloads (representing what the frontend should produce) validate against schemas
 * 2. Invalid payloads (like the broken serialization format) are correctly rejected
 * 3. Frontend-backend contract is maintained
 *
 * The fixtures in phpunit_tests/fixtures/payloads/ represent:
 * - valid_*.json: Payloads that should pass schema validation (frontend contract)
 * - invalid_*.json: Payloads that should fail schema validation (e.g., broken serialization)
 */
#[Group('Schemas')]
class PayloadValidationTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../fixtures/payloads';

    private static bool $routerInitialized = false;

    /**
     * Ensure Router paths are initialized.
     *
     * LitSchema::path() requires Router paths to be initialized.
     * Called from setUp() before each test method.
     *
     * Router::getApiPaths() is idempotent, but we use a flag to avoid
     * unnecessary repeated calls.
     */
    private static function ensureRouterInitialized(): void
    {
        if (!self::$routerInitialized) {
            Router::getApiPaths();
            self::$routerInitialized = true;
        }
    }

    protected function setUp(): void
    {
        self::ensureRouterInitialized();
    }

    /**
     * Load a JSON fixture file.
     *
     * @param string $filename The fixture filename (relative to fixtures/payloads/)
     * @return \stdClass The parsed JSON data
     */
    private static function loadFixture(string $filename): \stdClass
    {
        $path = self::FIXTURES_PATH . '/' . $filename;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read fixture file: $path");
        }

        $data = json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse JSON in fixture: $path - " . json_last_error_msg());
        }

        if (!$data instanceof \stdClass) {
            throw new \RuntimeException('Fixture must be a JSON object, not ' . gettype($data) . ': ' . $path);
        }

        return $data;
    }

    /**
     * Data provider for valid diocesan calendar payloads.
     *
     * @return array<string, array{0: string}>
     */
    public static function validDiocesanPayloadProvider(): array
    {
        return [
            'valid diocesan calendar'     => ['valid_diocesan_calendar.json'],
            'valid diocesan multi-locale' => ['valid_diocesan_multi_locale.json'],
        ];
    }

    /**
     * Data provider for valid national calendar payloads.
     *
     * @return array<string, array{0: string}>
     */
    public static function validNationalPayloadProvider(): array
    {
        return [
            'valid national calendar' => ['valid_national_calendar.json'],
        ];
    }

    /**
     * Data provider for valid wider region calendar payloads.
     *
     * @return array<string, array{0: string}>
     */
    public static function validWiderRegionPayloadProvider(): array
    {
        return [
            'valid wider region calendar' => ['valid_wider_region_calendar.json'],
        ];
    }

    /**
     * Data provider for invalid payloads that should be rejected.
     *
     * Tests invalid payloads across all regional schema types (diocesan, national, wider region)
     * to guard against regressions of litcal wrapping and metadata issues.
     *
     * @return array<string, array{0: string, 1: LitSchema}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            // Diocesan invalid payloads
            'diocesan: wrapped litcal (broken serialization)'    => ['invalid_litcal_wrapped.json', LitSchema::DIOCESAN],
            'diocesan: missing metadata'                         => ['invalid_missing_metadata.json', LitSchema::DIOCESAN],
            'diocesan: empty litcal array'                       => ['invalid_diocesan_empty_litcal.json', LitSchema::DIOCESAN],
            // National invalid payloads
            'national: wrapped litcal (broken serialization)'    => ['invalid_national_litcal_wrapped.json', LitSchema::NATIONAL],
            'national: missing metadata'                         => ['invalid_national_missing_metadata.json', LitSchema::NATIONAL],
            'national: empty litcal array'                       => ['invalid_national_empty_litcal.json', LitSchema::NATIONAL],
            // Wider region invalid payloads
            'widerregion: wrapped litcal (broken serialization)' => ['invalid_widerregion_litcal_wrapped.json', LitSchema::WIDERREGION],
            'widerregion: missing metadata'                      => ['invalid_widerregion_missing_metadata.json', LitSchema::WIDERREGION],
            'widerregion: empty litcal array'                    => ['invalid_widerregion_empty_litcal.json', LitSchema::WIDERREGION],
        ];
    }

    /**
     * Test that valid diocesan calendar payloads pass schema validation.
     *
     * This verifies the frontend-backend contract for diocesan calendar creation.
     */
    #[DataProvider('validDiocesanPayloadProvider')]
    public function testValidDiocesanPayloadPassesSchemaValidation(string $fixtureFile): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        // Implicit pass if no exception thrown
        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    /**
     * Test that valid national calendar payloads pass schema validation.
     *
     * This verifies the frontend-backend contract for national calendar creation.
     */
    #[DataProvider('validNationalPayloadProvider')]
    public function testValidNationalPayloadPassesSchemaValidation(string $fixtureFile): void
    {
        $schemaPath = LitSchema::NATIONAL->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        // Implicit pass if no exception thrown
        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    /**
     * Test that invalid payloads are correctly rejected by schema validation.
     *
     * This ensures that broken serialization formats (like wrapped litcal) are detected.
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidPayloadFailsSchemaValidation(string $fixtureFile, LitSchema $litSchema): void
    {
        $schemaPath = $litSchema->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        $this->expectException(InvalidValue::class);
        $schema->in($payload);
    }

    /**
     * Test that the litcal property must be an array, not an object with litcalItems.
     *
     * This specifically tests the serialization bug where LitCalItemCollection
     * serializes as {"litcalItems": [...]} instead of [...].
     */
    public function testLitcalMustBeArrayNotObject(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Simulate the broken serialization output
        $brokenPayload = (object) [
            'litcal'   => (object) [
                'litcalItems' => [
                    (object) [
                        'liturgical_event' => (object) [
                            'event_key' => 'TestEvent',
                            'day'       => 1,
                            'month'     => 1,
                            'color'     => ['white'],
                            'grade'     => 3,
                            'common'    => [],
                        ],
                        'metadata'         => (object) [
                            'form_rownum' => 0,
                            'since_year'  => 2020,
                        ],
                    ],
                ],
            ],
            'metadata' => (object) [
                'diocese_id'   => 'newyor_us',
                'diocese_name' => 'Archdiocese of New York (New York)',
                'nation'       => 'US',
                'locales'      => ['en_US'],
                'timezone'     => 'America/New_York',
            ],
        ];

        $this->expectException(InvalidValue::class);
        $schema->in($brokenPayload);
    }

    /**
     * Test that correct litcal array format passes validation.
     *
     * This verifies the expected format after fixing the serialization bug.
     */
    public function testLitcalAsArrayPassesValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Correct format - litcal is an array directly
        $correctPayload = (object) [
            'litcal'   => [
                (object) [
                    'liturgical_event' => (object) [
                        'event_key' => 'TestEvent',
                        'day'       => 1,
                        'month'     => 1,
                        'color'     => ['white'],
                        'grade'     => 3,
                        'common'    => [],
                    ],
                    'metadata'         => (object) [
                        'form_rownum' => 0,
                        'since_year'  => 2020,
                    ],
                ],
            ],
            'metadata' => (object) [
                'diocese_id'   => 'newyor_us',
                'diocese_name' => 'Archdiocese of New York (New York)',
                'nation'       => 'US',
                'locales'      => ['en_US'],
                'timezone'     => 'America/New_York',
            ],
        ];

        // Implicit pass if no exception thrown
        $schema->in($correctPayload);
        $this->addToAssertionCount(1);
    }

    /**
     * Test i18n structure validation.
     *
     * Verifies that the i18n property (when present) has the correct structure:
     * { "locale": { "event_key": "translation" } }
     */
    public function testI18nStructureValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Payload with correct i18n structure
        $payload = (object) [
            'litcal'   => [
                (object) [
                    'liturgical_event' => (object) [
                        'event_key' => 'TestEvent',
                        'day'       => 1,
                        'month'     => 1,
                        'color'     => ['white'],
                        'grade'     => 3,
                        'common'    => [],
                    ],
                    'metadata'         => (object) [
                        'form_rownum' => 0,
                        'since_year'  => 2020,
                    ],
                ],
            ],
            'metadata' => (object) [
                'diocese_id'   => 'newyor_us',
                'diocese_name' => 'Archdiocese of New York (New York)',
                'nation'       => 'US',
                'locales'      => ['en_US'],
                'timezone'     => 'America/New_York',
            ],
            'i18n'     => (object) [
                'en_US' => (object) ['TestEvent' => 'Test Event Translation'],
            ],
        ];

        // Implicit pass if no exception thrown
        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    /**
     * Test that multi-locale i18n structure passes schema validation.
     *
     * Verifies that schemas correctly allow multiple locales in the i18n property,
     * mirroring real-world usage where dioceses may support multiple languages.
     */
    public function testMultiLocaleI18nPassesSchemaValidation(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture('valid_diocesan_multi_locale.json');

        // Verify multi-locale structure
        $this->assertObjectHasProperty('i18n', $payload);
        $this->assertObjectHasProperty('en_US', $payload->i18n);
        $this->assertObjectHasProperty('es_US', $payload->i18n);

        // Verify metadata.locales matches i18n keys
        $this->assertContains('en_US', $payload->metadata->locales);
        $this->assertContains('es_US', $payload->metadata->locales);

        // Schema validation should pass
        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    /**
     * Test that i18n locale mismatch is rejected at the DTO level.
     *
     * The JSON schema allows any locale keys in i18n, but the DTO's
     * validateTranslations() method enforces that i18n keys must match
     * metadata.locales exactly. This test verifies that validation behavior.
     */
    public function testI18nLocaleMismatchRejectedByDto(): void
    {
        // First verify the payload passes schema validation
        // (schema doesn't enforce locale matching)
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture('invalid_diocesan_i18n_mismatch.json');
        $schema->in($payload);

        // But DTO validation should reject it because i18n keys (en_US, it_IT)
        // don't match metadata.locales (en_US, es_US)
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('keys of i18n parameter must be the same as the values of metadata.locales');

        DiocesanData::fromObject($payload);
    }

    /**
     * Test that a payload matching exactly what the frontend should produce validates.
     *
     * This is a comprehensive test using a complete payload structure.
     */
    public function testCompleteFrontendPayloadValidates(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture('valid_diocesan_calendar.json');

        // Verify all expected properties are present
        $this->assertObjectHasProperty('litcal', $payload);
        $this->assertIsArray($payload->litcal);
        $this->assertObjectHasProperty('metadata', $payload);
        $this->assertObjectHasProperty('diocese_id', $payload->metadata);
        $this->assertObjectHasProperty('diocese_name', $payload->metadata);
        $this->assertObjectHasProperty('nation', $payload->metadata);
        $this->assertObjectHasProperty('locales', $payload->metadata);
        $this->assertObjectHasProperty('timezone', $payload->metadata);

        // Verify litcal items have correct structure
        foreach ($payload->litcal as $item) {
            $this->assertObjectHasProperty('liturgical_event', $item);
            $this->assertObjectHasProperty('metadata', $item);
            $this->assertObjectHasProperty('event_key', $item->liturgical_event);
        }

        // Implicit pass if no exception thrown
        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    /**
     * Test that valid wider region calendar payloads pass schema validation.
     *
     * This verifies the frontend-backend contract for wider region calendar creation.
     */
    #[DataProvider('validWiderRegionPayloadProvider')]
    public function testValidWiderRegionPayloadPassesSchemaValidation(string $fixtureFile): void
    {
        $schemaPath = LitSchema::WIDERREGION->path();
        $schema     = Schema::import($schemaPath);

        $payload = self::loadFixture($fixtureFile);

        // Implicit pass if no exception thrown
        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // SERIALIZATION ROUND-TRIP TESTS
    // =========================================================================
    //
    // These tests verify that the raw payload approach for serialization works
    // correctly. The flow is:
    // 1. Parse JSON to stdClass (simulates receiving request body)
    // 2. Validate against schema
    // 3. Create DTO from stdClass (for typed property access)
    // 4. Re-encode the original stdClass (simulates writing to disk)
    // 5. Verify the re-encoded output passes schema validation
    //
    // This verifies the fix for the serialization bug where DTOs (which don't
    // implement JsonSerializable) produced invalid JSON structure when encoded.
    // =========================================================================

    /**
     * Test round-trip serialization for diocesan calendar payloads.
     *
     * Verifies that:
     * 1. Valid payload passes initial schema validation
     * 2. DTO can be constructed from the payload
     * 3. Re-encoding the raw payload produces valid JSON
     */
    #[DataProvider('validDiocesanPayloadProvider')]
    public function testDiocesanPayloadRoundTripSerialization(string $fixtureFile): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        // Step 1: Load and parse the fixture (simulates request body parsing)
        $rawPayload = self::loadFixture($fixtureFile);

        // Step 2: Validate against schema
        $schema->in($rawPayload);

        // Step 3: Create DTO from the payload (for typed property access)
        $dto = DiocesanData::fromObject($rawPayload);

        // Verify DTO has expected properties
        $this->assertNotNull($dto->metadata);
        $this->assertEquals('newyor_us', $dto->metadata->diocese_id);
        $this->assertEquals('US', $dto->metadata->nation);

        // Step 4: Re-encode the raw payload (simulates writing to disk)
        // Remove i18n since it's written separately in the actual handler
        $payloadForDisk = clone $rawPayload;
        unset($payloadForDisk->i18n);

        $encoded = json_encode($payloadForDisk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertNotFalse($encoded);

        // Step 5: Parse the re-encoded JSON and validate against schema
        $reDecoded = json_decode($encoded);
        $this->assertInstanceOf(\stdClass::class, $reDecoded);

        // The re-encoded payload (without i18n) should still have valid structure
        $this->assertObjectHasProperty('litcal', $reDecoded);
        $this->assertIsArray($reDecoded->litcal);
        $this->assertObjectHasProperty('metadata', $reDecoded);

        // Final check: round-tripped payload (without i18n) still conforms to schema
        // For diocesan, i18n is optional, so this should pass without it
        $schema->in($reDecoded);
    }

    /**
     * Test round-trip serialization for national calendar payloads.
     *
     * Verifies that:
     * 1. Valid payload passes initial schema validation
     * 2. DTO can be constructed from the payload
     * 3. Re-encoding the raw payload produces valid JSON
     */
    #[DataProvider('validNationalPayloadProvider')]
    public function testNationalPayloadRoundTripSerialization(string $fixtureFile): void
    {
        $schemaPath = LitSchema::NATIONAL->path();
        $schema     = Schema::import($schemaPath);

        // Step 1: Load and parse the fixture
        $rawPayload = self::loadFixture($fixtureFile);

        // Step 2: Validate against schema
        $schema->in($rawPayload);

        // Step 3: Create DTO from the payload
        $dto = NationalData::fromObject($rawPayload);

        // Verify DTO has expected properties
        $this->assertNotNull($dto->metadata);
        $this->assertEquals('IT', $dto->metadata->nation);

        // Step 4: Re-encode the raw payload
        $payloadForDisk = clone $rawPayload;
        unset($payloadForDisk->i18n);

        $encoded = json_encode($payloadForDisk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertNotFalse($encoded);

        // Step 5: Verify structure of re-encoded payload
        $reDecoded = json_decode($encoded);
        $this->assertInstanceOf(\stdClass::class, $reDecoded);
        $this->assertObjectHasProperty('litcal', $reDecoded);
        $this->assertIsArray($reDecoded->litcal);
        $this->assertObjectHasProperty('metadata', $reDecoded);
        $this->assertObjectHasProperty('settings', $reDecoded);

        // Final check: round-tripped payload (without i18n) still conforms to schema
        $schema->in($reDecoded);
    }

    /**
     * Test round-trip serialization for wider region calendar payloads.
     *
     * Verifies that:
     * 1. Valid payload passes initial schema validation
     * 2. DTO can be constructed from the payload
     * 3. Re-encoding the raw payload produces valid JSON
     */
    #[DataProvider('validWiderRegionPayloadProvider')]
    public function testWiderRegionPayloadRoundTripSerialization(string $fixtureFile): void
    {
        $schemaPath = LitSchema::WIDERREGION->path();
        $schema     = Schema::import($schemaPath);

        // Step 1: Load and parse the fixture
        $rawPayload = self::loadFixture($fixtureFile);

        // Step 2: Validate against schema
        $schema->in($rawPayload);

        // Step 3: Create DTO from the payload
        $dto = WiderRegionData::fromObject($rawPayload);

        // Verify DTO has expected properties
        $this->assertNotNull($dto->metadata);
        $this->assertEquals('Europe', $dto->metadata->wider_region);
        $this->assertNotEmpty($dto->national_calendars);

        // Step 4: Re-encode the raw payload
        $payloadForDisk = clone $rawPayload;
        unset($payloadForDisk->i18n);

        $encoded = json_encode($payloadForDisk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertNotFalse($encoded);

        // Step 5: Verify structure of re-encoded payload
        $reDecoded = json_decode($encoded);
        $this->assertInstanceOf(\stdClass::class, $reDecoded);
        $this->assertObjectHasProperty('litcal', $reDecoded);
        $this->assertIsArray($reDecoded->litcal);
        $this->assertObjectHasProperty('metadata', $reDecoded);
        $this->assertObjectHasProperty('national_calendars', $reDecoded);

        // Final check: round-tripped payload (without i18n) still conforms to schema
        $schema->in($reDecoded);
    }

    /**
     * Test that litcal array structure is preserved during round-trip.
     *
     * This is a critical test that verifies the fix for the serialization bug.
     * The bug was: LitCalItemCollection serialized as {"litcalItems": [...]}
     * The fix was: Use raw stdClass payload for json_encode instead of DTO
     */
    public function testLitcalArrayStructurePreservedDuringRoundTrip(): void
    {
        $schemaPath = LitSchema::DIOCESAN->path();
        $schema     = Schema::import($schemaPath);

        $rawPayload = self::loadFixture('valid_diocesan_calendar.json');

        // Verify initial structure
        $this->assertObjectHasProperty('litcal', $rawPayload);
        $this->assertIsArray($rawPayload->litcal);
        $this->assertCount(2, $rawPayload->litcal);

        // Create DTO (this would break serialization if we encoded the DTO)
        $dto = DiocesanData::fromObject($rawPayload);
        $this->assertNotNull($dto->litcal);

        // Re-encode raw payload (the fix)
        $payloadForDisk = clone $rawPayload;
        unset($payloadForDisk->i18n);

        $encoded   = json_encode($payloadForDisk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $reDecoded = json_decode($encoded);

        // Verify litcal is still an array, not an object with litcalItems
        $this->assertObjectHasProperty('litcal', $reDecoded);
        $this->assertIsArray($reDecoded->litcal);
        $this->assertCount(2, $reDecoded->litcal);

        // Verify each item in litcal has the expected structure
        foreach ($reDecoded->litcal as $item) {
            $this->assertObjectHasProperty('liturgical_event', $item);
            $this->assertObjectHasProperty('metadata', $item);
            $this->assertObjectHasProperty('event_key', $item->liturgical_event);
        }

        // Validate round-trip output against schema (i18n is optional)
        $schema->in($reDecoded);
    }

    /**
     * Test i18n extraction and separate serialization.
     *
     * Verifies that i18n data can be extracted and serialized separately
     * (as done in the actual handler implementation).
     */
    public function testI18nExtractionAndSeparateSerialization(): void
    {
        $rawPayload = self::loadFixture('valid_diocesan_calendar.json');

        // Verify i18n exists
        $this->assertObjectHasProperty('i18n', $rawPayload);

        /** @var array<string, \stdClass> $rawI18n */
        $rawI18n = (array) $rawPayload->i18n;

        // Verify i18n structure
        $this->assertArrayHasKey('en_US', $rawI18n);

        // Serialize each locale's translations separately
        foreach ($rawI18n as $locale => $translations) {
            $encoded = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->assertIsString($encoded, "Failed to encode i18n for locale: $locale");
            $this->assertNotFalse($encoded, "json_encode returned false for locale: $locale");

            // Verify it can be decoded back
            $reDecoded = json_decode($encoded);
            $this->assertInstanceOf(\stdClass::class, $reDecoded, "Failed to decode i18n for locale: $locale");
        }

        // Remove i18n from main payload
        unset($rawPayload->i18n);
        $this->assertObjectNotHasProperty('i18n', $rawPayload);

        // Main payload should still be valid structure
        $encoded   = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $reDecoded = json_decode($encoded);
        $this->assertObjectHasProperty('litcal', $reDecoded);
        $this->assertObjectHasProperty('metadata', $reDecoded);
    }
}
