<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory;
use PHPUnit\Framework\TestCase;

/**
 * Issue #772: the liturgical color vocabulary is spelled out in four independent
 * places, and they had silently drifted apart.
 *
 * When `morello` and `black` were added to the PHP {@see LitColor} enum and to
 * `CommonDef.json`, the XML Schema (`LiturgicalCalendar.xsd`) and the OpenAPI
 * description of the XML response body were left behind — with the result that
 * *every* Ambrosian calendar requested as XML failed schema validation, and
 * nothing anywhere noticed because no test had ever validated Ambrosian XML.
 *
 * This test is the guard against a repeat: all four lists must carry the same
 * set of colors. It deliberately compares sets, not order — the four files order
 * their entries differently and that is not a defect.
 *
 * Note the four enumerations are deliberately the *union* across rites:
 * `purple`/`rose` are Roman, `morello`/`black` are Ambrosian. Neither JSON Schema
 * nor XML Schema can key a color facet off the rite of the containing document, so
 * rite-scoped color validation lives in the validators rather than in these enums
 * (issue #771). Nothing about the union may change.
 *
 * Issue #771 added two further spellings that must agree with it from the other
 * direction: the per-rite palettes on
 * {@see \LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfile::colors()} and their
 * `CommonDef.json` counterparts, `RomanLitColor` and `AmbrosianLitColor`. Those must
 * partition the union exactly — so a color added to only one palette, or to the enum
 * but to neither palette, is caught here rather than by whichever validator happens
 * to run first.
 */
final class ColorEnumParityTest extends TestCase
{
    private static function schemasDir(): string
    {
        return dirname(__DIR__, 2) . '/jsondata/schemas';
    }

    /**
     * @return array<int,string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);
        return $values;
    }

    /**
     * @return array<int,string>
     */
    private static function phpEnumColors(): array
    {
        return self::sorted(array_map(static fn (LitColor $c): string => $c->value, LitColor::cases()));
    }

    /**
     * The `items.enum` array of one of CommonDef.json's color definitions.
     *
     * @return array<int,mixed>
     */
    private static function commonDefColorEnum(string $definition): array
    {
        $path    = self::schemasDir() . '/CommonDef.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $enum = $decoded['definitions'][$definition]['items']['enum'] ?? null;
        self::assertIsArray($enum, "CommonDef.json: could not locate definitions.$definition.items.enum");

        return $enum;
    }

    /**
     * The palette a rite profile declares, as sorted string values.
     *
     * @return array<int,string>
     */
    private static function riteProfileColors(Rite $rite): array
    {
        return self::sorted(array_map(
            static fn (LitColor $c): string => $c->value,
            RiteProfileFactory::forRite($rite)->colors()
        ));
    }

    public function testCommonDefJsonMatchesPhpEnum(): void
    {
        self::assertSame(
            self::phpEnumColors(),
            self::sorted(self::commonDefColorEnum('LitColor')),
            'CommonDef.json LitColor enum has drifted from the PHP LitColor enum'
        );
    }

    public function testXsdColorEnumTypeMatchesPhpEnum(): void
    {
        $path = self::schemasDir() . '/LiturgicalCalendar.xsd';
        $dom  = new \DOMDocument();
        self::assertTrue($dom->load($path), 'Could not load ' . $path);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $nodes = $xpath->query('//xs:simpleType[@name="ColorEnumType"]/xs:restriction/xs:enumeration/@value');
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length, 'LiturgicalCalendar.xsd: ColorEnumType not found');

        $values = [];
        foreach ($nodes as $node) {
            $values[] = $node->nodeValue ?? '';
        }

        self::assertSame(
            self::phpEnumColors(),
            self::sorted($values),
            'LiturgicalCalendar.xsd ColorEnumType has drifted from the PHP LitColor enum'
        );
    }

    /**
     * The OpenAPI description of the XML `Color/option` element mirrors the XSD
     * facet; it drifted in lockstep with it.
     */
    public function testOpenApiColorEnumMatchesPhpEnum(): void
    {
        $path    = self::schemasDir() . '/openapi.json';
        $raw     = (string) file_get_contents($path);
        $decoded = json_decode($raw, true, 1024, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $enums = [];
        self::collectColorOptionEnums($decoded, $enums);
        self::assertNotEmpty($enums, 'openapi.json: no Color.properties.option.enum found');

        foreach ($enums as $enum) {
            self::assertSame(
                self::phpEnumColors(),
                self::sorted($enum),
                'openapi.json Color option enum has drifted from the PHP LitColor enum'
            );
        }
    }

    /**
     * The two rite palettes must partition the union exactly: every color in
     * {@see LitColor} is licit in at least one rite, and neither palette invents a
     * color the enum does not carry.
     *
     * This is the assertion that catches a colour added to only one palette (it
     * would leave the union short, or overshoot it), and equally a colour added to
     * `LitColor` and to neither palette — which would be a value no validator could
     * ever admit.
     */
    public function testRiteProfilePalettesUnionToThePhpEnum(): void
    {
        $roman     = self::riteProfileColors(Rite::ROMAN);
        $ambrosian = self::riteProfileColors(Rite::AMBROSIAN);

        self::assertNotEmpty($roman, 'RomanRiteProfile::colors() must not be empty');
        self::assertNotEmpty($ambrosian, 'AmbrosianRiteProfile::colors() must not be empty');

        self::assertSame(
            self::phpEnumColors(),
            self::sorted(array_merge($roman, $ambrosian)),
            'The union of the Roman and Ambrosian rite palettes must be exactly LitColor::cases()'
        );
    }

    /**
     * `green`, `red` and `white` are common to both Missals; the violet families and
     * extremes are what separate them. Pinning the intersection keeps a future edit
     * from quietly making, say, `rose` Ambrosian by adding it to both palettes —
     * which the union assertion above would not notice.
     */
    public function testRiteProfilePalettesShareOnlyTheCommonColors(): void
    {
        self::assertSame(
            ['green', 'red', 'white'],
            self::sorted(array_intersect(
                self::riteProfileColors(Rite::ROMAN),
                self::riteProfileColors(Rite::AMBROSIAN)
            )),
            'Only green, red and white are common to the Roman and Ambrosian palettes'
        );
    }

    /**
     * The JSON Schema subsets used to validate rite-partitioned source data must
     * restate the rite profiles exactly — the profiles are authoritative, the schema
     * definitions are their source-data counterpart.
     */
    public function testCommonDefRiteSubsetsMatchTheRiteProfiles(): void
    {
        self::assertSame(
            self::riteProfileColors(Rite::ROMAN),
            self::sorted(self::commonDefColorEnum('RomanLitColor')),
            'CommonDef.json RomanLitColor has drifted from RomanRiteProfile::colors()'
        );

        self::assertSame(
            self::riteProfileColors(Rite::AMBROSIAN),
            self::sorted(self::commonDefColorEnum('AmbrosianLitColor')),
            'CommonDef.json AmbrosianLitColor has drifted from AmbrosianRiteProfile::colors()'
        );
    }

    /**
     * And, closing the loop, the two schema subsets must union to the schema union —
     * so `LitColor` in CommonDef.json cannot drift away from its own subsets even if
     * both of them still match the PHP profiles.
     */
    public function testCommonDefRiteSubsetsUnionToCommonDefLitColor(): void
    {
        self::assertSame(
            self::sorted(self::commonDefColorEnum('LitColor')),
            self::sorted(array_merge(
                self::commonDefColorEnum('RomanLitColor'),
                self::commonDefColorEnum('AmbrosianLitColor')
            )),
            'CommonDef.json: RomanLitColor ∪ AmbrosianLitColor must equal LitColor'
        );
    }

    /**
     * Recursively collect every `Color.properties.option.enum` array, so the test
     * keeps working if the OpenAPI document grows further copies of the schema.
     *
     * @param array<int|string,mixed> $node
     * @param array<int,array<int,mixed>> $found
     */
    private static function collectColorOptionEnums(array $node, array &$found): void
    {
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'Color' && isset($value['properties']['option']['enum']) && is_array($value['properties']['option']['enum'])) {
                $found[] = $value['properties']['option']['enum'];
            }
            self::collectColorOptionEnums($value, $found);
        }
    }
}
