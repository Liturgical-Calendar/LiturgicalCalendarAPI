<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the Ambrosian comune sanctorale allowances in PropriumDeSanctis.json (Plan 5 / Task 2).
 *
 * The comune ambrosiano sanctorale data (authored in later tasks) needs three schema allowances:
 * 1. an OPTIONAL `is_dominical` boolean property (marks feasts/solemnities of the Lord for the
 *    Ambrosian precedence classifier);
 * 2. `"AMBROSIAN"` as an allowed `calendar` value (Roman rows use e.g. `"GENERAL ROMAN"`);
 * 3. an OPTIONAL `is_bvm` boolean property (marks celebrations of the Blessed Virgin Mary, the
 *    within-rank tiebreak in the same classifier).
 *
 * All three must be additive: existing Roman data (which omits both flags and uses the
 * pre-existing `calendar` values) must keep validating, and `additionalProperties: false` must
 * still reject genuinely unknown properties.
 */
final class AmbrosianSanctoraleSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function schema(): Schema
    {
        return Schema::import(LitSchema::PROPRIUMDESANCTIS->path());
    }

    private function assertValidRow(\stdClass $row): void
    {
        self::schema()->in([$row]);
        $this->addToAssertionCount(1);
    }

    private function assertInvalidRow(\stdClass $row): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in([$row]);
    }

    private static function decode(string $json): \stdClass
    {
        $obj = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    private static function minimalAmbrosianRow(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "month": 12,
            "day": 7,
            "event_key": "StAmbrose",
            "grade": 6,
            "common": ["Pastors:For a Bishop"],
            "calendar": "AMBROSIAN",
            "color": ["white"],
            "is_dominical": false
        }
        JSON);
    }

    private static function minimalRomanRow(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "month": 1,
            "day": 1,
            "event_key": "MaryMotherOfGod",
            "grade": 7,
            "common": ["Proper"],
            "calendar": "GENERAL ROMAN",
            "color": ["white"]
        }
        JSON);
    }

    public function testMinimalAmbrosianRowWithIsDominicalIsValid(): void
    {
        $this->assertValidRow(self::minimalAmbrosianRow());
    }

    public function testRomanRowWithoutIsDominicalIsStillValid(): void
    {
        $row = self::minimalRomanRow();
        $this->assertObjectNotHasProperty('is_dominical', $row);
        $this->assertValidRow($row);
    }

    public function testIsDominicalTrueOnRomanRowIsValid(): void
    {
        // `is_dominical` is calendar-agnostic: it's an optional marker, not restricted to AMBROSIAN rows.
        $row               = self::minimalRomanRow();
        $row->is_dominical = true;
        $this->assertValidRow($row);
    }

    public function testUnknownPropertyIsStillRejected(): void
    {
        // additionalProperties: false must still be enforced after adding is_dominical.
        $row              = self::minimalAmbrosianRow();
        $row->totally_new = 'unexpected';
        $this->assertInvalidRow($row);
    }

    public function testUnknownCalendarValueIsRejected(): void
    {
        $row           = self::minimalAmbrosianRow();
        $row->calendar = 'NOT_A_REAL_CALENDAR';
        $this->assertInvalidRow($row);
    }

    public function testIsDominicalMustBeBoolean(): void
    {
        $row               = self::minimalAmbrosianRow();
        $row->is_dominical = 'false';
        $this->assertInvalidRow($row);
    }

    public function testAmbrosianRowWithIsBvmIsValid(): void
    {
        $row         = self::minimalAmbrosianRow();
        $row->is_bvm = true;
        $this->assertValidRow($row);
    }

    public function testRomanRowWithoutIsBvmIsStillValid(): void
    {
        $row = self::minimalRomanRow();
        $this->assertObjectNotHasProperty('is_bvm', $row);
        $this->assertValidRow($row);
    }

    public function testIsBvmFalseIsValid(): void
    {
        $row         = self::minimalAmbrosianRow();
        $row->is_bvm = false;
        $this->assertValidRow($row);
    }

    /**
     * The schema is the ONLY line of defence against a non-boolean `is_bvm`:
     * `PropriumDeSanctisEvent::fromObjectInternal()` guards with
     * `property_exists(...) && is_bool(...)`, which SILENTLY DROPS a non-boolean rather than
     * throwing. A row carrying `"is_bvm": "true"` would therefore load with `is_bvm === null`
     * and be ranked as a saint's memorial, with nothing anywhere reporting the mistake.
     */
    public function testIsBvmMustBeBoolean(): void
    {
        $row         = self::minimalAmbrosianRow();
        $row->is_bvm = 'true';
        $this->assertInvalidRow($row);
    }

    public function testIsBvmNullIsRejected(): void
    {
        $row         = self::minimalAmbrosianRow();
        $row->is_bvm = null;
        $this->assertInvalidRow($row);
    }

    /**
     * `morello` and `black` are Ambrosian-specific liturgical colors (Plan 3 added them to the
     * PHP `LitColor` enum + i18n, but CommonDef.json's `LitColor` enum lagged behind). This
     * additive schema fix (Plan 5 / Task 6) makes the temporale's `morello` rows, and any
     * sanctorale row that legitimately needs `morello`/`black`, schema-valid.
     */
    public function testMorelloColorIsAccepted(): void
    {
        $row        = self::minimalAmbrosianRow();
        $row->color = ['morello'];
        $this->assertValidRow($row);
    }

    public function testBlackColorIsAccepted(): void
    {
        $row        = self::minimalAmbrosianRow();
        $row->color = ['black'];
        $this->assertValidRow($row);
    }
}
