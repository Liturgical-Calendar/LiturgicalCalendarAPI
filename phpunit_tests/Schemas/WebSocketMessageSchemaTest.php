<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\Structure\ObjectItemContract;

/**
 * `WebSocketMessage.json` — the published contract for an inbound Health message.
 *
 * The load-bearing test here is compatibility, not correctness in the abstract. The shipped
 * UnitTestInterface sends properties the server neither declares nor reads: `sendMessage()` injects
 * `runToken` into every message, and the source-data checks are built by spreading a config object
 * that carries `rite` onto an `executeValidation` that never looks at it. A schema that refused
 * those would take the test interface down on the day it shipped.
 *
 * The fixtures are therefore transcribed from the client's own source, not imagined.
 */
final class WebSocketMessageSchemaTest extends TestCase
{
    private static function schema(): Schema
    {
        $schema = Schema::import(LitSchema::WEBSOCKET_MESSAGE->path());
        self::assertInstanceOf(Schema::class, $schema);

        return $schema;
    }

    /**
     * Messages the shipped client actually sends. Every one must validate.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function clientMessageProvider(): array
    {
        return [
            // resources.js:1221 and index.js:647 — `{ action, ...check }` where check carries `rite`.
            'source-data check with the spread rite' => [
                [
                    'action'     => 'executeValidation',
                    'rite'       => 'roman',
                    'validate'   => 'PropriumDeTempore',
                    'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
                    'category'   => 'universalcalendar',
                    'runToken'   => 'abc123'
                ]
            ],
            'i18n folder check with the spread rite' => [
                [
                    'action'       => 'executeValidation',
                    'rite'         => 'roman',
                    'validate'     => 'proprium-de-tempore-i18n',
                    'sourceFolder' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n',
                    'category'     => 'sourceDataCheck',
                    'runToken'     => 'abc123'
                ]
            ],
            // resources.js:1184 — the resource arm adds responsetype.
            'resource check with responsetype'       => [
                [
                    'action'       => 'executeValidation',
                    'responsetype' => 'JSON',
                    'rite'         => 'roman',
                    'validate'     => 'calendars',
                    'sourceFile'   => 'http://localhost:8000/calendars',
                    'category'     => 'resourceDataCheck',
                    'runToken'     => 'abc123'
                ]
            ],
            // index.js:686-693 — note `year` is a number, which is why the schema may require integer.
            'legacy validateCalendar'                => [
                [
                    'action'       => 'validateCalendar',
                    'year'         => 2024,
                    'calendar'     => 'IT',
                    'category'     => 'nationalcalendar',
                    'rite'         => 'roman',
                    'responsetype' => 'JSON',
                    'runToken'     => 'abc123'
                ]
            ],
            'legacy executeUnitTest'                 => [
                [
                    'action'   => 'executeUnitTest',
                    'test'     => 'AllSaintsTest',
                    'calendar' => 'IT',
                    'year'     => 2024,
                    'category' => 'nationalcalendar',
                    'runToken' => 'abc123'
                ]
            ],
            // wsProtocol.js:31
            'cancelRun'                              => [
                [
                    'action'   => 'cancelRun',
                    'runToken' => 'abc123'
                ]
            ],
            'v2 validateSource'                      => [
                [
                    'action'    => 'validateSource',
                    'target'    => ['id' => 'temporale:roman'],
                    'runToken'  => 'abc123',
                    'requestId' => 'req-1'
                ]
            ],
            'v2 typed validateCalendar'              => [
                [
                    'action'         => 'validateCalendar',
                    'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                    'year'           => 2026,
                    'responseFormat' => 'JSON',
                    'requestId'      => 'req-2'
                ]
            ],
            'v2 runTest'                             => [
                [
                    'action'    => 'runTest',
                    'test'      => 'AllSaintsTest',
                    'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'      => 2024,
                    'requestId' => 'req-3'
                ]
            ],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('clientMessageProvider')]
    public function testAMessageTheShippedClientSendsValidates(array $message): void
    {
        $decoded = json_decode((string) json_encode($message));

        // in() throws on failure and returns the processed value on success, so the assertions
        // below are on that value rather than on having survived the call. `assertTrue(true)`
        // would pass on a build where in() silently became a no-op.
        //
        // NOTE: swaggest/json-schema 0.12.43's in() builds an ObjectItem (not stdClass) for any
        // object-typed schema that doesn't set a custom objectItemClass — see
        // Schema::processObject(), which does this unconditionally for every plain object result.
        // The instanceof check below targets that actual contract rather than stdClass so it
        // asserts "in() really processed this" without failing on a hardcoded, incorrect return type.
        $processed = self::schema()->in($decoded);

        self::assertInstanceOf(ObjectItemContract::class, $processed);
        self::assertSame($message['action'], $processed->action, 'the validated message is not the one that went in');
    }

    /**
     * Exactly one arm may match, or the shape discrimination is ambiguous and which handler runs
     * becomes a matter of arm order.
     */
    #[DataProvider('clientMessageProvider')]
    public function testEachMessageMatchesExactlyOneArm(array $message): void
    {
        $raw     = json_decode((string) file_get_contents(LitSchema::WEBSOCKET_MESSAGE->path()));
        $decoded = json_decode((string) json_encode($message));
        $matches = 0;
        foreach ($raw->oneOf as $arm) {
            // The arm is wrapped rather than extracted: several definitions `$ref` their siblings
            // (`calendarIdentity`, `correlationId`), and a definition lifted out on its own takes
            // its unresolvable pointers with it. Keeping `definitions` alongside preserves them.
            $armDocument = (object) [
                '$schema'     => 'https://json-schema.org/draft-07/schema#',
                'allOf'       => [$arm],
                'definitions' => $raw->definitions
            ];
            try {
                Schema::import($armDocument)->in($decoded);
                $matches++;
            } catch (\Throwable) {
                // not this arm
            }
        }
        self::assertSame(1, $matches, 'a message must match exactly one shape');
    }

    /**
     * The definition names Task 3's unknown-property check looks up. Renaming one without updating
     * that lookup would silently disable the gate, so the names are pinned here.
     */
    public function testTheDefinitionNamesAreTheOnesTheValidatorLooksUp(): void
    {
        $raw = json_decode((string) file_get_contents(LitSchema::WEBSOCKET_MESSAGE->path()));
        self::assertEqualsCanonicalizing(
            [
                'correlationId',
                'calendarIdentity',
                'executeValidation',
                'validateCalendarLegacy',
                'validateCalendarTyped',
                'executeUnitTest',
                'runTest',
                'cancelRun', 'validateSource'
            ],
            array_keys((array) $raw->definitions)
        );
    }

    /**
     * The crash vectors, at the schema level. Task 3 asserts what the *server* does with them; this
     * asserts the schema is what refuses them, so a later loosening of the schema fails here first.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedMessageProvider(): array
    {
        return [
            'year as a non-numeric string'            => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 'not-a-year', 'category' => 'nationalcalendar', 'responsetype' => 'JSON']],
            'category as an array'                    => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => [], 'responsetype' => 'JSON']],
            'unknown response format'                 => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar', 'responsetype' => 'NOT_A_FORMAT']],
            'test as an object'                       => [['action' => 'executeUnitTest', 'test' => ['a' => 1], 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar']],
            'executeValidation category as an object' => [['action' => 'executeValidation', 'category' => ['k' => 'v'], 'validate' => 'x', 'sourceFile' => 'jsondata/x.json']],
            'misspelled category'                     => [['action' => 'executeValidation', 'category' => 'sourceDataChek', 'validate' => 'x', 'sourceFile' => 'jsondata/x.json']],
            'neither sourceFile nor sourceFolder'     => [['action' => 'executeValidation', 'category' => 'sourceDataCheck', 'validate' => 'x']],
            'sourceFolder under universalcalendar'    => [['action' => 'executeValidation', 'category' => 'universalcalendar', 'validate' => 'x', 'sourceFolder' => 'jsondata/i18n']],
            'sourceFolder under resourceDataCheck'    => [['action' => 'executeValidation', 'category' => 'resourceDataCheck', 'validate' => 'x', 'sourceFolder' => 'jsondata/i18n']],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('malformedMessageProvider')]
    public function testAMalformedMessageIsRefusedByTheSchema(array $message): void
    {
        $this->expectException(\Throwable::class);
        self::schema()->in(json_decode((string) json_encode($message)));
    }
}
