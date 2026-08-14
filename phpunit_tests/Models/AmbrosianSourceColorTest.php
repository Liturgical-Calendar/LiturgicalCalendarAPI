<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\LitColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #772 (part 2): the Ambrosian source tree must not carry Roman-only
 * liturgical colors.
 *
 * `purple` and `rose` belong to the Roman rite; the Ambrosian violet-family
 * color is `morello`, which is a proper denomination of the rite and is kept
 * distinct from `purple` in {@see LitColor} even though the vestments are in
 * practice interchangeable.
 *
 * The concrete defect this guards was the Commemoration of All the Faithful
 * Departed (`AllSouls`, 2 November), authored as `purple` while all fifteen
 * other violet-family Ambrosian rows already used `morello`.
 *
 * Note this asserts on the *shipped source data*, not on the JSON Schema: the
 * schema's color enumeration is deliberately the union across rites (XML Schema
 * and JSON Schema alike have no clean way to key a color facet off the rite of
 * the containing file). Rite-scoping color validity in the validators themselves
 * is tracked in issue #771; until that lands, this test is what keeps the
 * Ambrosian tree honest.
 */
final class AmbrosianSourceColorTest extends TestCase
{
    /**
     * Colors admissible in the Ambrosian rite. `black` is admitted by the
     * Ordinamento Generale del Messale Ambrosiano n. 320 as an optional
     * alternative to `morello` in offices and Masses for the dead (and on
     * Lenten ferias), so it belongs in the admissible set even though no row
     * currently uses it.
     */
    private const AMBROSIAN_COLORS = ['green', 'red', 'white', 'morello', 'black'];

    private static function ambrosianRoot(): string
    {
        return dirname(__DIR__, 2) . '/jsondata/sourcedata/rite/ambrosian';
    }

    /**
     * Every JSON file under the Ambrosian source tree, excluding the `i18n/`
     * translation files (which carry no `color` keys).
     *
     * @return array<string,array{0:string}>
     */
    public static function ambrosianSourceFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ambrosianRoot(), \FilesystemIterator::SKIP_DOTS)
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
            $cases[substr($path, strlen(self::ambrosianRoot()) + 1)] = [$path];
        }

        ksort($cases);
        self::assertNotEmpty($cases, 'No Ambrosian source files discovered under ' . self::ambrosianRoot());

        return $cases;
    }

    #[DataProvider('ambrosianSourceFiles')]
    public function testNoRomanOnlyColorsInAmbrosianSourceData(string $path): void
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $offenders = [];
        self::collectColors($decoded, $offenders);

        self::assertSame(
            [],
            $offenders,
            'Ambrosian source data must not use Roman-only colors (use "morello" for the violet family): '
            . implode(', ', array_unique($offenders))
        );
    }

    /**
     * Walk an arbitrarily nested decoded structure and record any `color`
     * entry whose value is not admissible in the Ambrosian rite.
     *
     * @param mixed             $node
     * @param array<int,string> $offenders
     */
    private static function collectColors(mixed $node, array &$offenders): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'color' && is_array($value)) {
                foreach ($value as $color) {
                    if (is_string($color) && !in_array($color, self::AMBROSIAN_COLORS, true)) {
                        $offenders[] = $color;
                    }
                }
                continue;
            }
            self::collectColors($value, $offenders);
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
        $path = self::ambrosianRoot() . '/missals/propriumdesanctis_2024/propriumdesanctis.json';
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
