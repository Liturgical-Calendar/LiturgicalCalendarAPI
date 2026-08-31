<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\TestCase;

final class MissalsRitePathsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function decode(string $file): array
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/' . $file);
        self::assertIsString($raw, $file . ' must be readable');
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Literal rite segments, matching the precedent #948 set for /lectionary. A `{rite}` template
     * would collide with `{missal_id}` at the same position, which OpenAPI forbids outright.
     */
    public function testEveryMissalsShapeHasALiteralPerRiteSpelling(): void
    {
        /** @var array{paths: array<string,mixed>} $doc */
        $doc = self::decode('openapi.json');

        foreach (['roman', 'ambrosian'] as $rite) {
            foreach (
                [
                    "/missals/{$rite}",
                    "/missals/{$rite}/{missal_id}",
                    "/missals/{$rite}/{missal_id}/i18n",
                    "/missals/{$rite}/{missal_id}/{event_key}",
                ] as $path
            ) {
                self::assertArrayHasKey($path, $doc['paths'], $path . ' must be documented');
            }
        }
    }

    public function testNoRiteTemplateIsDeclared(): void
    {
        /** @var array{paths: array<string,mixed>} $doc */
        $doc = self::decode('openapi.json');

        // Scoped to /missals: an unrelated pre-existing `/tests/{rite}` path (#787) predates the
        // #948 literal-rite-segment precedent this task follows and is out of scope here.
        foreach (array_keys($doc['paths']) as $path) {
            if (!str_starts_with($path, '/missals')) {
                continue;
            }
            self::assertStringNotContainsString(
                '{rite}',
                $path,
                'a {rite} template collides with {missal_id}; enumerate rites literally (#948 precedent)'
            );
        }
    }

    public function testTheBareSpellingsAreRetained(): void
    {
        /** @var array{paths: array<string,mixed>} $doc */
        $doc = self::decode('openapi.json');

        foreach (['/missals', '/missals/{missal_id}', '/missals/{missal_id}/i18n', '/missals/{missal_id}/{event_key}'] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path . ' must keep working for existing clients');
        }
    }

    /**
     * The enum itself, not a substring of the serialised schema — which would pass on the word
     * appearing in any description.
     */
    public function testTheRegionEnumAdmitsAmbrosian(): void
    {
        /** @var array{definitions: array{Missal: array{properties: array{region: array{enum: list<string>}}}}} $doc */
        $doc  = self::decode('LitCalMissalsPath.json');
        $enum = $doc['definitions']['Missal']['properties']['region']['enum'];

        self::assertContains('AMBROSIAN', $enum, 'an Ambrosian response would fail schema validation without this');
        self::assertContains('VA', $enum, 'the existing regions must survive');
    }

    /**
     * `resolveSanctoraleTarget()` (MissalsHandler.php) resolves a `readings_tier` of `'rite'`,
     * `'missal'`, OR `'none'` — `'none'` for a rite with no lectionary corpus of its own and no
     * rite-wide corpus to fall back to (the Ambrosian rite today, #957). Every write response
     * enum that declares `readings_tier` must admit all three, or the API emits a value its own
     * published contract rejects — the #946 defect class this guards against recurring.
     *
     * Every `/missals/**\/{event_key}` PUT/PATCH/DELETE response (bare, roman, ambrosian) declares
     * its own `readings_tier` schema, so this walks the whole document rather than hardcoding each
     * path: a ninth write endpoint added later is covered automatically.
     */
    public function testEveryReadingsTierEnumAdmitsNone(): void
    {
        $doc = self::decode('openapi.json');

        $found = 0;
        self::walkForReadingsTierEnums($doc, $found);

        self::assertSame(9, $found, 'expected one readings_tier schema per PUT/PATCH/DELETE response, for bare/roman/ambrosian (3x3)');
    }

    /**
     * @param mixed $node
     */
    private static function walkForReadingsTierEnums(mixed $node, int &$found): void
    {
        if (!is_array($node)) {
            return;
        }

        if (array_key_exists('readings_tier', $node) && is_array($node['readings_tier']) && array_key_exists('enum', $node['readings_tier'])) {
            /** @var list<string> $enum */
            $enum = $node['readings_tier']['enum'];
            self::assertContains('rite', $enum, 'the editiones typicae tier must survive');
            self::assertContains('missal', $enum, 'the national-edition tier must survive');
            self::assertContains('none', $enum, 'a rite with no lectionary corpus at all (#957) must be a representable state');
            ++$found;
        }

        foreach ($node as $value) {
            self::walkForReadingsTierEnums($value, $found);
        }
    }
}
