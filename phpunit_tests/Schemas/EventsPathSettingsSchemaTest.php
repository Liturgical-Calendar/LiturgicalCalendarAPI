<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the `settings` property of the `LitCalEventsPath.json` response schema (issue #817).
 *
 * `settings` is a `oneOf` over the four combinations of `national_calendar`/`diocesan_calendar` that
 * `EventsHandler` can actually emit:
 *
 * | # | `national_calendar` | `diocesan_calendar` | `rite`         | produced by                        |
 * |---|---------------------|---------------------|----------------|------------------------------------|
 * | 0 | `"IT"` (2 letters)  | diocesan id         | any            | `/events/diocese/{id}` (Roman)     |
 * | 1 | `"IT"` (2 letters)  | `null`              | any            | `/events/nation/{nation}`          |
 * | 2 | `null`              | `null`              | any            | `/events`, `/events/ambrosian`     |
 * | 3 | `null`              | diocesan id         | **ambrosian**  | `/events/ambrosian/diocese/{id}`   |
 *
 * Branch 3 is the one issue #817 added. The Ambrosian rite has no national layer
 * ({@see \LiturgicalCalendar\Api\Params\EventsParams::validateRiteCompatibility()} rejects a
 * `national_calendar` under the Ambrosian rite, and
 * {@see \LiturgicalCalendar\Api\Handlers\EventsHandler::loadAmbrosianDiocesanData()} deliberately
 * leaves `NationalCalendar` null), so an Ambrosian diocesan request reports a diocesan calendar with
 * no national calendar — a shape the original three branches did not admit, producing 8 red
 * `schema-valid` cards (2 locales x 4 Ambrosian dioceses) in the UnitTestInterface resources runner.
 *
 * Branch 3 is deliberately NOT open to every rite: its `rite` is narrowed to
 * `CommonDef.json#/definitions/RiteWithoutNationalCalendars`. Under the Roman rite a diocese always
 * inherits from a national calendar, so a Roman `{national_calendar: null, diocesan_calendar: <id>}`
 * payload is invalid and must keep being rejected — widening the schema for the Ambrosian rite must
 * not quietly legalise it for the Roman one. That is what
 * {@see self::testRomanDiocesanWithNullNationalCalendarIsRejected()} pins down.
 *
 * Branch 2 stays open to both rites on purpose: `/events` and `/events/ambrosian` both emit
 * `{null, null}`, so pinning it to `roman` would break a live 200 response.
 *
 * Because this is a `oneOf` and not an `anyOf`, the tests below assert not only that each shape
 * validates but that it matches EXACTLY ONE branch: a fourth branch that overlapped an existing one
 * would turn a previously-passing payload into a failure.
 */
final class EventsPathSettingsSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function settingsSchema(): Schema
    {
        return Schema::import(LitSchema::EVENTS->path() . '#/properties/settings');
    }

    /**
     * The number of `oneOf` branches under `settings` that the given payload matches.
     *
     * Used to prove branch exclusivity: a valid payload must match exactly 1.
     */
    private static function matchingBranchCount(\stdClass $settings): int
    {
        $schemaFile = LitSchema::EVENTS->path();
        /** @var array{properties:array{settings:array{oneOf:list<array<string,mixed>>}}} $raw */
        $raw         = json_decode(file_get_contents($schemaFile) ?: '', true, 512, JSON_THROW_ON_ERROR);
        $branchCount = count($raw['properties']['settings']['oneOf']);
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

    private static function decode(string $json): \stdClass
    {
        $obj = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $obj);
        return $obj;
    }

    /**
     * Every `settings` shape the `/events` endpoint can emit, keyed by the request that produces it.
     *
     * @return array<string,array{0:string}>
     */
    public static function validSettingsProvider(): array
    {
        return [
            '/events'                             => ['{"locale":"en","national_calendar":null,"diocesan_calendar":null,"rite":"roman"}'],
            '/events/nation/IT'                   => ['{"locale":"it_IT","national_calendar":"IT","diocesan_calendar":null,"rite":"roman"}'],
            '/events/diocese/romamo_it'           => ['{"locale":"it_IT","national_calendar":"IT","diocesan_calendar":"romamo_it","rite":"roman"}'],
            '/events/ambrosian'                   => ['{"locale":"it","national_calendar":null,"diocesan_calendar":null,"rite":"ambrosian"}'],
            '/events/ambrosian/diocese/milano_it' => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"milano_it","rite":"ambrosian"}'],
            '/events/ambrosian/diocese/bergam_it' => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"bergam_it","rite":"ambrosian"}'],
            '/events/ambrosian/diocese/novara_it' => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"novara_it","rite":"ambrosian"}'],
            '/events/ambrosian/diocese/lugano_ch' => ['{"locale":"la_VA","national_calendar":null,"diocesan_calendar":"lugano_ch","rite":"ambrosian"}'],
        ];
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
            'Roman diocese, null nation'  => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"romamo_it","rite":"roman"}'],
            'unknown diocesan id'         => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"nowhere_xx","rite":"ambrosian"}'],
            'unknown rite'                => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"milano_it","rite":"mozarabic"}'],
            'missing rite'                => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"milano_it"}'],
            'missing national_calendar'   => ['{"locale":"it_IT","diocesan_calendar":"milano_it","rite":"ambrosian"}'],
            'additional property'         => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"milano_it","rite":"ambrosian","totally_new":true}'],
            'lowercase national_calendar' => ['{"locale":"it_IT","national_calendar":"it","diocesan_calendar":"milano_it","rite":"roman"}'],
            'diocesan id as null-ish ""'  => ['{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"","rite":"ambrosian"}'],
        ];
    }

    #[DataProvider('invalidSettingsProvider')]
    public function testInvalidSettingsShapeIsRejected(string $json): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::settingsSchema()->in(self::decode($json));
    }

    /**
     * The single assertion that proves the Roman paradigm survived issue #817.
     *
     * The fourth `oneOf` branch legalises `{national_calendar: null, diocesan_calendar: <id>}` for the
     * Ambrosian rite ONLY. The very same shape under `rite: "roman"` describes a Roman diocese with no
     * national calendar, which the Roman calendar hierarchy cannot produce, and must therefore still
     * match no branch at all.
     */
    public function testRomanDiocesanWithNullNationalCalendarIsRejected(): void
    {
        $settings = self::decode('{"locale":"it_IT","national_calendar":null,"diocesan_calendar":"romamo_it","rite":"roman"}');

        $this->assertSame(0, self::matchingBranchCount($settings), 'no branch may admit a Roman diocese without a national calendar');

        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::settingsSchema()->in($settings);
    }

    /**
     * `RiteWithoutNationalCalendars` must stay a non-empty proper subset of `Rite` that excludes
     * `roman`. Mirrors the `RomanLitColor`/`AmbrosianLitColor` parity guard in
     * {@see ColorEnumParityTest}: the two lists are spelled out separately in `CommonDef.json` and
     * would otherwise drift the first time a rite is added.
     */
    public function testRiteWithoutNationalCalendarsIsAProperSubsetOfRite(): void
    {
        $commonDef = dirname(__DIR__, 2) . '/jsondata/schemas/CommonDef.json';
        /** @var array{definitions:array<string,array{enum?:list<string>}>} $decoded */
        $decoded = json_decode((string) file_get_contents($commonDef), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('RiteWithoutNationalCalendars', $decoded['definitions']);

        $allRites = $decoded['definitions']['Rite']['enum'] ?? [];
        $tierless = $decoded['definitions']['RiteWithoutNationalCalendars']['enum'] ?? [];

        $this->assertNotEmpty($tierless);
        $this->assertNotContains('roman', $tierless, 'the Roman rite has national calendars');
        $this->assertSame([], array_diff($tierless, $allRites), 'RiteWithoutNationalCalendars has drifted from Rite');
        $this->assertNotSame([], array_diff($allRites, $tierless), 'RiteWithoutNationalCalendars must stay a *proper* subset');
    }

    /**
     * Draft-07 trap guard.
     *
     * In draft-07 a `$ref` REPLACES its containing schema object: sibling validation keywords are
     * silently ignored. Verified against swaggest/json-schema — both
     * `{"$ref": ".../Rite", "not": {"const": "roman"}}` and `{"$ref": ".../Rite", "enum": ["ambrosian"]}`
     * accept `"roman"` without complaint. The narrowing on branch 3 therefore has to be a `$ref` to a
     * definition that is already narrow (`RiteWithoutNationalCalendars`), never a `$ref` plus a
     * sibling constraint. This test fails if anyone later "tightens" a branch that way, because the
     * tightening would be a no-op.
     *
     * `description` is exempt: it is an annotation, not a validation keyword.
     */
    public function testNoRefInTheSettingsBranchesCarriesSiblingValidationKeywords(): void
    {
        /** @var array{properties:array{settings:array{oneOf:list<array<string,mixed>>}}} $decoded */
        $decoded = json_decode((string) file_get_contents(LitSchema::EVENTS->path()), true, 512, JSON_THROW_ON_ERROR);

        foreach ($decoded['properties']['settings']['oneOf'] as $idx => $branch) {
            self::assertIsArray($branch['properties']);
            foreach ($branch['properties'] as $property => $subSchema) {
                self::assertIsArray($subSchema);
                if (false === array_key_exists('$ref', $subSchema)) {
                    continue;
                }
                $siblings = array_diff(array_keys($subSchema), ['$ref', 'description']);
                $this->assertSame(
                    [],
                    array_values($siblings),
                    "oneOf[{$idx}].properties.{$property} pairs a \$ref with " . implode(', ', $siblings)
                        . ' — under draft-07 those siblings are ignored, so the constraint would silently do nothing.'
                );
            }
        }
    }
}
