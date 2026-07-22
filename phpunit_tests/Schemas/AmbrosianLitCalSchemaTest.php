<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the Ambrosian allowances in the LitCal.json response schema (Plan 7 / Task 1).
 *
 * The Ambrosian rite's calendar calculation (implemented in later tasks) needs the response
 * schema's `LiturgicalEvent` and `LiturgicalEventVigil` definitions to accept:
 * 1. `"AFTER_EPIPHANY"` and `"AFTER_PENTECOST"` as `liturgical_season` enum values (Ambrosian
 *    seasons that have no Roman equivalent);
 * 2. two OPTIONAL boolean properties, `is_dominical` and `is_aliturgical`, used by the Ambrosian
 *    precedence classifier and by the aliturgical Fridays of Ambrosian Lent respectively.
 *
 * Both changes must be additive: existing Roman events (which use the pre-existing
 * `liturgical_season` values and omit `is_dominical`/`is_aliturgical`) must keep validating, and
 * `additionalProperties: false` must still reject genuinely unknown properties.
 */
final class AmbrosianLitCalSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function liturgicalEventSchema(): Schema
    {
        return Schema::import(LitSchema::LITCAL->path() . '#/definitions/LiturgicalEvent');
    }

    private function assertValid(\stdClass $event): void
    {
        self::liturgicalEventSchema()->in($event);
        $this->addToAssertionCount(1);
    }

    private function assertInvalid(\stdClass $event): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::liturgicalEventSchema()->in($event);
    }

    private static function decode(string $json): \stdClass
    {
        $obj = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    /**
     * A minimal, otherwise schema-valid `LiturgicalEvent` object shaped like an Ambrosian event:
     * `liturgical_season` set to one of the new Ambrosian-only values, plus the two new optional
     * booleans populated.
     */
    private static function minimalAmbrosianEvent(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "event_idx": 0,
            "event_key": "SundayAfterPentecost1",
            "name": "First Sunday after Pentecost",
            "date": "2026-06-07T00:00:00+00:00",
            "color": ["green"],
            "color_lcl": ["Green"],
            "type": "mobile",
            "grade": 4,
            "grade_lcl": "Feast",
            "grade_abbr": "F",
            "grade_display": null,
            "common": [],
            "common_lcl": "",
            "day_of_the_week_iso8601": 7,
            "month": 6,
            "day": 7,
            "year": 2026,
            "month_short": "Jun",
            "month_long": "June",
            "day_of_the_week_short": "Sun",
            "day_of_the_week_long": "Sunday",
            "liturgical_season": "AFTER_PENTECOST",
            "liturgical_season_lcl": "After Pentecost",
            "psalter_week": 1,
            "readings": "Proper",
            "is_dominical": true,
            "is_aliturgical": true
        }
        JSON);
    }

    public function testMinimalAmbrosianEventIsValid(): void
    {
        $this->assertValid(self::minimalAmbrosianEvent());
    }

    public function testAfterEpiphanySeasonIsValid(): void
    {
        $event                    = self::minimalAmbrosianEvent();
        $event->liturgical_season = 'AFTER_EPIPHANY';
        $this->assertValid($event);
    }

    public function testUnknownLiturgicalSeasonIsRejected(): void
    {
        $event                    = self::minimalAmbrosianEvent();
        $event->liturgical_season = 'NONSENSE';
        $this->assertInvalid($event);
    }

    public function testRomanLiturgicalSeasonsStillValidateWithoutAmbrosianProperties(): void
    {
        // Existing Roman events omit is_dominical/is_aliturgical entirely and use the
        // pre-existing liturgical_season values; both must keep validating unchanged.
        $event = self::minimalAmbrosianEvent();
        unset($event->is_dominical, $event->is_aliturgical);
        $event->liturgical_season = 'ORDINARY_TIME';
        $this->assertValid($event);
    }

    public function testIsDominicalMustBeBoolean(): void
    {
        $event               = self::minimalAmbrosianEvent();
        $event->is_dominical = 'true';
        $this->assertInvalid($event);
    }

    public function testIsAliturgicalMustBeBoolean(): void
    {
        $event                 = self::minimalAmbrosianEvent();
        $event->is_aliturgical = 'true';
        $this->assertInvalid($event);
    }

    public function testUnknownPropertyIsStillRejected(): void
    {
        // additionalProperties: false must still be enforced after adding the new properties.
        $event              = self::minimalAmbrosianEvent();
        $event->totally_new = 'unexpected';
        $this->assertInvalid($event);
    }
}
