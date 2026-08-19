<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * House conventions every file in `jsondata/schemas/` follows.
 *
 * These are already asserted, but only from `Routes/Readonly/SchemasTest`, which fetches each schema
 * over HTTP and therefore needs a running server. That makes a convention slip invisible locally and
 * discoverable only in CI — which is exactly how a schema declaring draft 2020-12 in a draft-07
 * corpus reached a pull request.
 *
 * Reading the files off disk costs nothing and moves the same failure to `composer test:quick`.
 */
#[CoversNothing]
final class SchemaConventionsTest extends TestCase
{
    /**
     * The folder is located from this file rather than through `JsonData`, because PHPUnit runs a data
     * provider before `setUpBeforeClass()`, and `JsonData::*->path()` needs `Router::$apiFilePath` to
     * have been initialised. Reaching for the enum here throws before a single case is produced.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function schemaFileProvider(): array
    {
        $folder = dirname(__DIR__, 2) . '/jsondata/schemas';
        $files  = glob($folder . '/*.json') ?: [];

        $cases = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            // The OpenAPI document lives in the same folder but is not a JSON Schema.
            if (false === is_array($decoded) || array_key_exists('openapi', $decoded)) {
                continue;
            }
            $cases[basename($file)] = [basename($file), $decoded];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    #[DataProvider('schemaFileProvider')]
    public function testEverySchemaDeclaresTheCorpusDraft(string $name, array $decoded): void
    {
        self::assertArrayHasKey('$schema', $decoded, "{$name} declares no \$schema");
        self::assertSame(
            'https://json-schema.org/draft-07/schema#',
            $decoded['$schema'],
            "{$name} declares a different JSON Schema draft from the rest of the corpus. "
                . 'Mixing drafts is what Routes/Readonly/SchemasTest rejects, and that test needs a live server, '
                . 'so the mismatch would otherwise only surface in CI.'
        );
    }

    /**
     * @param array<string, mixed> $decoded
     */
    #[DataProvider('schemaFileProvider')]
    public function testNoSchemaCarriesAnAbsoluteId(string $name, array $decoded): void
    {
        self::assertArrayNotHasKey(
            '$id',
            $decoded,
            "{$name} carries an \$id. The corpus deliberately omits it — cross-file \$refs here are relative "
                . '(./Foo.json), and an absolute $id pins the base URI to one deployment.'
        );
    }
}
