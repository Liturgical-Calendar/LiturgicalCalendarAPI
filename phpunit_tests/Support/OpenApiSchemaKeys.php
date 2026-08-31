<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * Key-for-key comparison of a response body against the `openapi.json` schema that declares it.
 *
 * The admin schemas are `additionalProperties: false` and list every property as required, and
 * `openapi.json` is used to generate client code. A key a handler emits that the schema does not
 * know about — or the reverse — is therefore a broken client rather than cosmetic drift, and it is
 * invisible to assertions that only read the keys they care about: `AdminNotificationsResponse`
 * forbade `pending_applications` for as long as the handler had been returning it (#946).
 *
 * Extracted from `ChangeRequestAdminHandlerTest`, which introduced the idiom.
 */
trait OpenApiSchemaKeys
{
    /**
     * Decoded once per process: `openapi.json` is large and every assertion needs the whole of it.
     *
     * @var array{components: array{schemas: array<string, array{properties: array<string, mixed>, required: list<string>, additionalProperties?: bool}>}}|null
     */
    private static ?array $openApiDocument = null;

    /**
     * Assert that $value carries exactly the keys $schemaName declares, no more and no fewer.
     *
     * @param array<string, mixed> $value
     */
    private static function assertSchemaKeysMatch(string $schemaName, array $value): void
    {
        $schemas = self::openApiSchemas();
        self::assertArrayHasKey($schemaName, $schemas, 'openapi.json declares no schema named ' . $schemaName);
        $schema = $schemas[$schemaName];

        self::assertFalse($schema['additionalProperties'] ?? true, $schemaName . ' is expected to forbid extra properties');

        $declared = array_keys($schema['properties']);
        $actual   = array_keys($value);
        sort($declared);
        sort($actual);

        self::assertSame($declared, $actual, $schemaName . ' and the response body disagree about which keys exist');

        $required = $schema['required'];
        sort($required);
        self::assertSame($declared, $required, $schemaName . ' declares a property it does not require');
    }

    /**
     * @return array<string, array{properties: array<string, mixed>, required: list<string>, additionalProperties?: bool}>
     */
    private static function openApiSchemas(): array
    {
        if (null === self::$openApiDocument) {
            $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');
            self::assertIsString($raw, 'Could not read openapi.json');

            /** @var array{components: array{schemas: array<string, array{properties: array<string, mixed>, required: list<string>, additionalProperties?: bool}>}} $decoded */
            $decoded               = json_decode($raw, true);
            self::$openApiDocument = $decoded;
        }

        return self::$openApiDocument['components']['schemas'];
    }
}
