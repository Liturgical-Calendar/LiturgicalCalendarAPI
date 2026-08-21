<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the `settings` property of the `LitCal.json` response schema (issue #844).
 *
 * `settings` is a `oneOf` over the calendar tiers `CalendarHandler` can actually emit:
 *
 * | # | definition                                        | `national_calendar` | `diocesan_calendar` | `rite`        | produced by                          |
 * |---|---------------------------------------------------|---------------------|---------------------|---------------|--------------------------------------|
 * | 0 | `NationalCalendarSettings`                        | `"IT"`              | absent              | any           | `/calendar/nation/{nation}`          |
 * | 1 | `DiocesanCalendarSettings`                        | `"IT"`              | diocesan id         | any           | `/calendar/diocese/{id}`             |
 * | 2 | `DiocesanCalendarSettingsWithoutNationalCalendar` | **absent**          | diocesan id         | **ambrosian** | `/calendar/ambrosian/diocese/{id}`   |
 * | 3 | `GeneralRomanCalendarSettings`                    | absent              | absent              | any           | `/calendar`, `/calendar/ambrosian`   |
 *
 * Branch 2 is the one issue #844 added. The Ambrosian rite has no national layer
 * ({@see \LiturgicalCalendar\Api\Params\CalendarParams::validateRiteCompatibility()} rejects a
 * `national_calendar` under the Ambrosian rite), so an Ambrosian diocesan response reports a
 * `diocesan_calendar` with no `national_calendar` at all — a shape none of the original three branches
 * admitted (branches 0 and 1 `require` `national_calendar`; branch 3 rejects `diocesan_calendar` as an
 * additional property).
 *
 * Note the deliberate spelling difference from the `/events` counterpart fixed in #817: `/events`
 * emits `national_calendar: null`, so `LitCalEventsPath.json` needed a branch admitting `null`, whereas
 * `/calendar` **omits the key entirely**, so this branch simply does not declare it — `additionalProperties:
 * false` then keeps it out. The response shapes were left as they are; only the schemas were taught the truth.
 *
 * Branch 2's `rite` is narrowed to `CommonDef.json#/definitions/RiteWithoutNationalCalendars`. Under the
 * Roman rite a diocese always inherits from a national calendar, so a Roman diocesan payload with no
 * `national_calendar` is invalid and must keep being rejected — widening the schema for the Ambrosian rite
 * must not quietly legalise it for the Roman one. That is what
 * {@see self::testRomanDiocesanWithoutNationalCalendarIsRejected()} pins down.
 *
 * Branch 3 stays open to both rites on purpose: `/calendar` and `/calendar/ambrosian` both emit a settings
 * object carrying no calendar keys, so pinning it to `roman` would break a live 200 response.
 *
 * Because this is a `oneOf` and not an `anyOf`, the tests below assert not only that each shape validates
 * but that it matches EXACTLY ONE branch: a fourth branch overlapping an existing one would turn a
 * previously-passing payload into a failure.
 */
final class CalendarSettingsSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function settingsSchema(): Schema
    {
        return Schema::import(LitSchema::LITCAL->path() . '#/properties/settings');
    }

    /**
     * The number of `oneOf` branches under `settings` that the given payload matches.
     *
     * Used to prove branch exclusivity: a valid payload must match exactly 1.
     */
    private static function matchingBranchCount(\stdClass $settings): int
    {
        $schemaFile  = LitSchema::LITCAL->path();
        $branchCount = count(self::settingsBranches());
        $matches     = 0;
        for ($idx = 0; $idx < $branchCount; ++$idx) {
            try {
                Schema::import($schemaFile . '#/properties/settings/oneOf/' . $idx)->in($settings);
                ++$matches;
            } catch (\Swaggest\JsonSchema\Exception) {
                // branch does not match; nothing to do
            }
        }

        return $matches;
    }

    /**
     * The raw (undecoded-by-the-validator) `oneOf` branches of `LitCal.json#/properties/settings`.
     *
     * @return list<array<string,mixed>>
     */
    private static function settingsBranches(): array
    {
        /** @var array{properties:array{settings:array{oneOf:list<array<string,mixed>>}}} $decoded */
        $decoded = json_decode((string) file_get_contents(LitSchema::LITCAL->path()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['properties']['settings']['oneOf'];
    }

    /**
     * The `definitions` block of `LitCal.json`.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function litCalDefinitions(): array
    {
        /** @var array{definitions:array<string,array<string,mixed>>} $decoded */
        $decoded = json_decode((string) file_get_contents(LitSchema::LITCAL->path()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['definitions'];
    }

    private static function decode(string $json): \stdClass
    {
        $obj = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $obj);
        return $obj;
    }

    /**
     * The settings keys common to every tier, as actually emitted by `CalendarHandler`.
     */
    private const COMMON = '"year":2026,"year_type":"LITURGICAL","epiphany":"JAN6","ascension":"THURSDAY",'
        . '"corpus_christi":"THURSDAY","return_type":"JSON","eternal_high_priest":false';

    /**
     * The `holydays_of_obligation` map of a real Roman response.
     */
    private const ROMAN_HOLYDAYS = '"holydays_of_obligation":{"Christmas":true,"Epiphany":true,"Ascension":true,'
        . '"CorpusChristi":true,"MaryMotherOfGod":true,"ImmaculateConception":true,"Assumption":true,'
        . '"StJoseph":true,"StsPeterPaulAp":true,"AllSaints":true}';

    /**
     * The `holydays_of_obligation` map of a real Ambrosian response (note the proper feasts).
     */
    private const AMBROSIAN_HOLYDAYS = '"holydays_of_obligation":{"Christmas":true,"Circoncisione":true,'
        . '"Epiphany":true,"Ascension":true,"Pentecost":true,"ImmaculateConception":true,"Assumption":true,'
        . '"AllSaints":true,"StAmbrose":true,"DedicationDuomo":true}';

    private static function romanSettings(string $locale, string $extra): string
    {
        return '{' . self::COMMON . ',' . self::ROMAN_HOLYDAYS . ',"locale":"' . $locale . '","rite":"roman"' . $extra . '}';
    }

    private static function ambrosianSettings(string $locale, string $extra): string
    {
        return '{' . self::COMMON . ',' . self::AMBROSIAN_HOLYDAYS . ',"locale":"' . $locale . '","rite":"ambrosian"' . $extra . '}';
    }

    /**
     * Every `settings` shape the `/calendar` endpoint can emit, keyed by the request that produces it.
     *
     * The Ambrosian diocesan rows are the eight that failed before #844: four dioceses x two locales.
     *
     * @return array<string,array{0:string}>
     */
    public static function validSettingsProvider(): array
    {
        $cases = [
            '/calendar'                   => [self::romanSettings('la', '')],
            '/calendar/nation/IT'         => [self::romanSettings('it_IT', ',"national_calendar":"IT"')],
            '/calendar/diocese/romamo_it' => [self::romanSettings('it_IT', ',"national_calendar":"IT","diocesan_calendar":"romamo_it"')],
            '/calendar/ambrosian'         => [self::ambrosianSettings('la', '')],
        ];

        foreach (['milano_it', 'bergam_it', 'novara_it', 'lugano_ch'] as $diocese) {
            foreach (['it_IT' => 'it', 'la' => 'la_VA'] as $locale => $acceptLanguage) {
                $cases["/calendar/ambrosian/diocese/{$diocese} ({$acceptLanguage})"] = [self::ambrosianSettings($locale, ',"diocesan_calendar":"' . $diocese . '"')];
            }
        }

        return $cases;
    }

    #[DataProvider('validSettingsProvider')]
    public function testEmittedSettingsShapeIsValid(string $json): void
    {
        $settings = self::decode($json);
        self::settingsSchema()->in($settings);
        $this->addToAssertionCount(1);
    }

    /**
     * `oneOf` exclusivity: every emitted shape must match one and only one branch.
     */
    #[DataProvider('validSettingsProvider')]
    public function testEmittedSettingsShapeMatchesExactlyOneBranch(string $json): void
    {
        $this->assertSame(1, self::matchingBranchCount(self::decode($json)));
    }

    /**
     * Shapes that must stay rejected after the fourth branch was added.
     *
     * @return array<string,array{0:string}>
     */
    public static function invalidSettingsProvider(): array
    {
        return [
            // The Roman paradigm: a Roman diocese always sits on top of a national calendar.
            'Roman diocese, no national_calendar' => [self::romanSettings('it_IT', ',"diocesan_calendar":"romamo_it"')],
            'Roman diocese, null national'        => [self::romanSettings('it_IT', ',"national_calendar":null,"diocesan_calendar":"romamo_it"')],
            'Roman general + diocesan_calendar'   => [self::romanSettings('la', ',"diocesan_calendar":"romamo_it"')],
            'Ambrosian diocese, null national'    => [self::ambrosianSettings('it_IT', ',"national_calendar":null,"diocesan_calendar":"milano_it"')],
            'unknown diocesan id'                 => [self::ambrosianSettings('it_IT', ',"diocesan_calendar":"nowhere_x"')],
            'unknown rite'                        => ['{' . self::COMMON . ',' . self::AMBROSIAN_HOLYDAYS . ',"locale":"it_IT","rite":"mozarabic","diocesan_calendar":"milano_it"}'],
            'missing rite'                        => ['{' . self::COMMON . ',' . self::AMBROSIAN_HOLYDAYS . ',"locale":"it_IT","diocesan_calendar":"milano_it"}'],
            'missing year'                        => [str_replace('"year":2026,', '', self::ambrosianSettings('it_IT', ',"diocesan_calendar":"milano_it"'))],
            'additional property'                 => [self::ambrosianSettings('it_IT', ',"diocesan_calendar":"milano_it","totally_new":true')],
            'lowercase national_calendar'         => [self::romanSettings('it_IT', ',"national_calendar":"it","diocesan_calendar":"romamo_it"')],
            'diocesan id as null-ish ""'          => [self::ambrosianSettings('it_IT', ',"diocesan_calendar":""')],
        ];
    }

    #[DataProvider('invalidSettingsProvider')]
    public function testInvalidSettingsShapeIsRejected(string $json): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::settingsSchema()->in(self::decode($json));
    }

    #[DataProvider('invalidSettingsProvider')]
    public function testInvalidSettingsShapeMatchesNoBranchAtAll(string $json): void
    {
        $this->assertSame(0, self::matchingBranchCount(self::decode($json)));
    }

    /**
     * The single assertion that proves the Roman paradigm survived issue #844.
     *
     * The new branch legalises `{national_calendar absent, diocesan_calendar: <id>}` for the Ambrosian
     * rite ONLY. The very same shape under `rite: "roman"` describes a Roman diocese with no national
     * calendar, which the Roman calendar hierarchy cannot produce, and must therefore still match no
     * branch at all.
     */
    public function testRomanDiocesanWithoutNationalCalendarIsRejected(): void
    {
        $settings = self::decode(self::romanSettings('it_IT', ',"diocesan_calendar":"romamo_it"'));

        $this->assertSame(0, self::matchingBranchCount($settings), 'no branch may admit a Roman diocese without a national calendar');

        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::settingsSchema()->in($settings);
    }

    /**
     * Draft-07 trap guard.
     *
     * In draft-07 a `$ref` REPLACES its containing schema object: sibling validation keywords are
     * silently ignored. Verified against swaggest/json-schema in #817 — both
     * `{"$ref": ".../Rite", "not": {"const": "roman"}}` and `{"$ref": ".../Rite", "enum": ["ambrosian"]}`
     * accept `"roman"` without complaint. The narrowing on the new branch therefore has to be a `$ref`
     * to a definition that is already narrow (`RiteWithoutNationalCalendars`), never a `$ref` plus a
     * sibling constraint. This test fails if anyone later "tightens" a branch that way, because the
     * tightening would be a no-op.
     *
     * Unlike the `/events` schema, `LitCal.json` spells each branch as a `$ref` to a local definition, so
     * the guard follows that indirection before inspecting the per-property sub-schemas.
     *
     * `description` and `title` are exempt: they are annotations, not validation keywords.
     */
    public function testNoRefInTheSettingsBranchesCarriesSiblingValidationKeywords(): void
    {
        $definitions = self::litCalDefinitions();

        foreach (self::settingsBranches() as $idx => $branch) {
            $label = "oneOf[{$idx}]";

            if (array_key_exists('$ref', $branch)) {
                $siblings = array_diff(array_keys($branch), ['$ref', 'description', 'title']);
                $this->assertSame(
                    [],
                    array_values($siblings),
                    "{$label} pairs a \$ref with " . implode(', ', $siblings)
                        . ' — under draft-07 those siblings are ignored, so the constraint would silently do nothing.'
                );

                $ref = $branch['$ref'];
                self::assertIsString($ref);
                $this->assertStringStartsWith('#/definitions/', $ref, "{$label} must reference a local definition");
                $name = substr($ref, strlen('#/definitions/'));
                $this->assertArrayHasKey($name, $definitions, "{$label} references an undefined definition");
                $label  = "definitions.{$name}";
                $branch = $definitions[$name];
            }

            $this->assertArrayHasKey('properties', $branch, "{$label} declares no properties");
            self::assertIsArray($branch['properties']);
            foreach ($branch['properties'] as $property => $subSchema) {
                self::assertIsArray($subSchema);
                if (false === array_key_exists('$ref', $subSchema)) {
                    continue;
                }
                $siblings = array_diff(array_keys($subSchema), ['$ref', 'description', 'title']);
                $this->assertSame(
                    [],
                    array_values($siblings),
                    "{$label}.properties.{$property} pairs a \$ref with " . implode(', ', $siblings)
                        . ' — under draft-07 those siblings are ignored, so the constraint would silently do nothing.'
                );
            }
        }
    }

    /**
     * The branch added by #844 must stay the mirror image of `DiocesanCalendarSettings`: same properties
     * and same `required` list, minus `national_calendar`, with `rite` narrowed. Spelled out separately in
     * the schema, the two definitions would otherwise drift the first time a settings key is added.
     */
    public function testTierlessDiocesanBranchMirrorsTheDiocesanBranch(): void
    {
        $definitions = self::litCalDefinitions();

        $this->assertArrayHasKey('DiocesanCalendarSettingsWithoutNationalCalendar', $definitions);
        $diocesan = $definitions['DiocesanCalendarSettings'];
        $tierless = $definitions['DiocesanCalendarSettingsWithoutNationalCalendar'];

        self::assertIsArray($diocesan['properties']);
        self::assertIsArray($tierless['properties']);
        $this->assertSame(
            array_values(array_diff(array_keys($diocesan['properties']), ['national_calendar'])),
            array_keys($tierless['properties']),
            'the two diocesan settings definitions have drifted apart'
        );

        self::assertIsArray($diocesan['required']);
        self::assertIsArray($tierless['required']);
        $this->assertSame(
            array_values(array_diff($diocesan['required'], ['national_calendar'])),
            array_values($tierless['required']),
            'the two diocesan settings definitions require different keys'
        );

        $this->assertArrayNotHasKey(
            'national_calendar',
            $tierless['properties'],
            'declaring national_calendar would let additionalProperties:false stop rejecting it'
        );
        $this->assertFalse($tierless['additionalProperties'], 'additionalProperties:false is what keeps national_calendar out');
        $this->assertSame(
            ['$ref' => './CommonDef.json#/definitions/RiteWithoutNationalCalendars'],
            $tierless['properties']['rite'],
            'the branch must be discriminated on a rite that has no national tier'
        );
    }
}
