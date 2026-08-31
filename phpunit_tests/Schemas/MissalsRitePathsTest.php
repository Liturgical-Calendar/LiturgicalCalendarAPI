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
}
