<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\SchemaRole;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * `SupportedLocales.json`, the schema `jsondata/supportedLocales.json` had gone without.
 *
 * It exists for a specific job: a curation change request stores a path, and
 * `SourceDataSchemaResolver` must be able to say what shape the bytes at that path should
 * have. A path no schema claims is read by `ChangeRequestSchemaValidator` as "not validated"
 * rather than "invalid", so without this a malformed promotion would be approved unchecked
 * (issue #926, gate #918).
 *
 * The constraint worth having a schema for at all is `official`'s `minItems`. An empty list is
 * NOT the same as no official locales: `SupportedLocales::official()` substitutes its built-in
 * FALLBACK for an empty or unreadable resource, so a write that emptied the list would
 * silently restore the historical five instead of doing what it said.
 */
#[CoversNothing]
final class SupportedLocalesSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() and JsonData::*->path() both read Router::$apiFilePath.
        Router::getApiPaths();
    }

    private static function schema(): Schema
    {
        $schema = Schema::import(LitSchema::SUPPORTED_LOCALES->path());
        self::assertInstanceOf(Schema::class, $schema);

        return $schema;
    }

    /**
     * SOURCE, not OUTPUT: these are bytes this repository stores and a change request
     * proposes, never a response the API emits.
     */
    public function testTheSchemaIsClassifiedAsSourceData(): void
    {
        self::assertSame(SchemaRole::SOURCE, LitSchema::SUPPORTED_LOCALES->role());
    }

    public function testTheCommittedResourceValidates(): void
    {
        self::schema()->in(json_decode((string) file_get_contents(JsonData::SUPPORTED_LOCALES_FILE->path())));

        $this->addToAssertionCount(1);
    }

    public function testAMinimalResourceValidates(): void
    {
        self::schema()->in(json_decode('{"general_roman_calendar":{"official":["la"]}}'));

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidResources(): array
    {
        return [
            'no official list'          => ['{"general_roman_calendar":{}}'],
            'empty official list'       => ['{"general_roman_calendar":{"official":[]}}'],
            'duplicated official entry' => ['{"general_roman_calendar":{"official":["la","la"]}}'],
            'unknown locale'            => ['{"general_roman_calendar":{"official":["zz"]}}'],
            'non-string official entry' => ['{"general_roman_calendar":{"official":[7]}}'],
            'candidate without a note'  => ['{"general_roman_calendar":{"official":["la"],"candidates":{"hr":""}}}'],
            'candidate note not prose'  => ['{"general_roman_calendar":{"official":["la"],"candidates":{"hr":true}}}'],
            'unknown candidate locale'  => ['{"general_roman_calendar":{"official":["la"],"candidates":{"zz":"why"}}}'],
            'stray key in the set'      => ['{"general_roman_calendar":{"official":["la"],"pending":["hr"]}}'],
            'unknown calendar'          => ['{"general_roman_calendar":{"official":["la"]},"ambrosian_calendar":{"official":["it"]}}'],
            'no calendar at all'        => ['{}'],
        ];
    }

    #[DataProvider('invalidResources')]
    public function testAMalformedResourceIsRejected(string $json): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);

        self::schema()->in(json_decode($json));
    }
}
