<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Neither rite-partitioned source tree may carry a liturgical color that the
 * other rite's Missal alone admits.
 *
 * `purple` and `rose` belong to the Roman rite; the Ambrosian violet-family
 * color is `morello`, a proper denomination of the rite kept distinct from
 * `purple` in {@see LitColor} even though the vestments are in practice
 * interchangeable. `morello` and `black` are in turn Ambrosian-only.
 *
 * The concrete defect this started life guarding (issue #772) was the
 * Commemoration of All the Faithful Departed (`AllSouls`, 2 November), authored
 * as `purple` while all sixteen other violet-family Ambrosian rows already used
 * `morello`. Issue #771 generalised it: the palettes are no longer restated here
 * but read from {@see \LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfile::colors()},
 * which is their single authoritative statement, and the Roman tree is now
 * covered symmetrically.
 *
 * Note this asserts on the *shipped source data*, not on the response schemas:
 * their color enumeration is deliberately the union across rites (XML Schema and
 * JSON Schema alike have no clean way to key a color facet off the rite of the
 * containing file). The rite-scoped JSON Schema subsets that do exist —
 * `CommonDef.json#/definitions/{Roman,Ambrosian}LitColor` — are exercised from
 * {@see \LiturgicalCalendar\Tests\Schemas\SchemaValidationTest}, and the write
 * path enforces the same rule against `metadata.rite` in
 * {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler}.
 */
final class RiteSourceColorTest extends TestCase
{
    private static function riteRoot(Rite $rite): string
    {
        return dirname(__DIR__, 2) . '/jsondata/sourcedata/rite/' . $rite->value;
    }

    /**
     * The licit palette for a rite, as string values.
     *
     * Sourced from the rite profile rather than restated, so a colour added to a
     * Missal's palette is admitted here by the same edit that declares it.
     *
     * @return string[]
     */
    private static function licitColors(Rite $rite): array
    {
        return array_map(
            static fn (LitColor $color): string => $color->value,
            RiteProfileFactory::forRite($rite)->colors()
        );
    }

    /**
     * Every JSON file under a rite's source tree, excluding the `i18n/`
     * translation files (which carry no `color` keys).
     *
     * @return array<string,array{0:string}>
     */
    private static function sourceFilesForRite(Rite $rite): array
    {
        $root     = self::riteRoot($rite);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $cases = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $cases[substr($path, strlen($root) + 1)] = [$path];
        }

        ksort($cases);
        self::assertNotEmpty($cases, 'No source files discovered under ' . $root);

        return $cases;
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function ambrosianSourceFiles(): array
    {
        return self::sourceFilesForRite(Rite::AMBROSIAN);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function romanSourceFiles(): array
    {
        return self::sourceFilesForRite(Rite::ROMAN);
    }

    #[DataProvider('ambrosianSourceFiles')]
    public function testNoRomanOnlyColorsInAmbrosianSourceData(string $path): void
    {
        $this->assertNoIllicitColors($path, Rite::AMBROSIAN);
    }

    /**
     * The symmetric guard on the Roman tree: `morello` and `black` are Ambrosian
     * and must not leak the other way. This tree also carries the decrees and the
     * lectionary, both of which have `color` keys.
     */
    #[DataProvider('romanSourceFiles')]
    public function testNoAmbrosianOnlyColorsInRomanSourceData(string $path): void
    {
        $this->assertNoIllicitColors($path, Rite::ROMAN);
    }

    private function assertNoIllicitColors(string $path, Rite $rite): void
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $licit   = self::licitColors($rite);

        $offenders = [];
        self::collectColors($decoded, $licit, $offenders);

        self::assertSame(
            [],
            $offenders,
            $rite->value . ' source data must not use colors licit only in the other rite (found: '
            . implode(', ', array_unique($offenders)) . '); licit here are: ' . implode(', ', $licit)
        );
    }

    /**
     * Walk an arbitrarily nested decoded structure and record any `color` entry
     * whose value is not admissible in the given rite.
     *
     * @param mixed             $node
     * @param string[]          $licit
     * @param array<int,string> $offenders
     */
    private static function collectColors(mixed $node, array $licit, array &$offenders): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'color') {
                // `color` is an array on an event, but a bare string inside a
                // `color_ad_libitum` entry (issue #781) — an ad libitum colour is just as
                // rite-scoped as an unconditional one, so both shapes are checked.
                foreach (is_array($value) ? $value : [$value] as $color) {
                    if (is_string($color) && !in_array($color, $licit, true)) {
                        $offenders[] = $color;
                    }
                }
                if (is_array($value)) {
                    continue;
                }
            }
            self::collectColors($value, $licit, $offenders);
        }
    }

    /**
     * The specific row from issue #772: per OGMA n. 320 the proper color of the
     * Ambrosian Commemoration of All the Faithful Departed is `morello`. (Black
     * is admitted only as an optional alternative and is excluded on Sundays,
     * which the scalar-per-event color field cannot yet express — see the
     * follow-up tracked separately.)
     */
    public function testAllSoulsIsMorello(): void
    {
        $path = self::riteRoot(Rite::AMBROSIAN) . '/missals/propriumdesanctis_2024/propriumdesanctis_2024.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $allSouls = array_find(
            $data,
            static fn (mixed $row): bool => is_array($row) && ( $row['event_key'] ?? null ) === 'AllSouls'
        );

        self::assertIsArray($allSouls, 'AllSouls row not found in the Ambrosian sanctorale');
        self::assertSame([LitColor::MORELLO->value], $allSouls['color']);
    }
}
