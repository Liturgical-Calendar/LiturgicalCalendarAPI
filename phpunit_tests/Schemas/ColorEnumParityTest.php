<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitColor;
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
 * Note the enumerations are the *union* across rites: `purple`/`rose` are Roman,
 * `morello`/`black` are Ambrosian. Neither JSON Schema nor XML Schema can key a
 * color facet off the rite of the containing document, so rite-scoped color
 * validation belongs in the validators rather than in these enums — tracked in
 * issue #771.
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

    public function testCommonDefJsonMatchesPhpEnum(): void
    {
        $path    = self::schemasDir() . '/CommonDef.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $enum = $decoded['definitions']['LitColor']['items']['enum'] ?? null;
        self::assertIsArray($enum, 'CommonDef.json: could not locate definitions.LitColor.items.enum');

        self::assertSame(
            self::phpEnumColors(),
            self::sorted($enum),
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
