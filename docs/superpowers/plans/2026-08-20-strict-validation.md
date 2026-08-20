# Strict Validation and Typed Protocol Errors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.
Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a malformed WebSocket message impossible to crash the `Health` process, and answer it with a typed, machine-readable protocol error.

**Architecture:** A published draft-07 schema, `jsondata/schemas/WebSocketMessage.json`, becomes the authority for what an inbound message may look like. A new
`WebSocketMessageValidator` service validates every message against it before dispatch and — only for messages carrying a `requestId` — refuses properties the schema does not
declare, reading the allowed names out of the schema rather than from a list of its own. Rejections become `type: "protocolError"` frames carrying a `ProtocolErrorCode`. A
`\Throwable` backstop around the dispatch `switch` guarantees the daemon survives anything a handler does.

**Tech Stack:** PHP 8.4, `swaggest/json-schema` v0.12.43 (draft-07), Ratchet/ReactPHP, PHPUnit 12, PHPStan level 10, PSR-12 via phpcs.

**Spec:** `docs/superpowers/specs/2026-08-20-strict-validation-design.md` — read it before Task 1. Every "why" below is short because the spec carries the reasoning.

## Global Constraints

- **No v1 message that works today may stop working**, except ones that currently kill the process. This is the single most important constraint; Task 1's compatibility test
is what enforces it.
- Unknown-property rejection applies **only** when the message carries a `requestId`. Type and enum validation applies to **all** messages.
- The schema declares `"$schema": "https://json-schema.org/draft-07/schema#"` and puts shapes under `definitions`, matching the other 21 schemas in `jsondata/schemas/`.
- `swaggest/json-schema` v0.12.43 implements draft-07 only. **Do not use `unevaluatedProperties`, `$defs`, `$dynamicRef`, or any 2019-09+ keyword** — see spec §4 and issue #826.
- The new error frame uses **`text`**, never `message`. `errorCode` is the new field.
- Retired-property checks stay in `Health` and keep running **before** schema validation.
- Tests drive `Health::onMessage()` with a real JSON message. Do not test the private validator by invoking it directly except where a step explicitly says to.
- Every test class that builds a `Health` must `use HealthQueueIsolationTrait` and build via `$this->newHealth()`. A hand-written `new Health()` leaks queued HTTP requests
that fire for real at PHPUnit shutdown.
- Commits are GPG-signed and pre-commit hooks run `composer lint` / `lint:md`. Never pass `--no-verify`.
- Run `composer test:quick`, never a bare `phpunit --exclude-group`.

## File Structure

| File                                                         | Responsibility                                                   |
|--------------------------------------------------------------|------------------------------------------------------------------|
| `jsondata/schemas/WebSocketMessage.json` (new)               | The authority: every inbound message shape, types, enums         |
| `src/Enum/LitSchema.php` (modify)                            | One new case so the schema resolves by path like every other     |
| `src/Enum/ProtocolErrorCode.php` (new)                       | The error-code vocabulary                                        |
| `src/Services/WebSocketMessageValidator.php` (new)           | Schema validation + the `requestId`-gated unknown-property check |
| `src/Health.php` (modify)                                    | Wire the validator in, emit typed errors, add the backstop       |
| `phpunit_tests/Schemas/WebSocketMessageSchemaTest.php` (new) | The schema is correct and accepts what the shipped client sends  |
| `phpunit_tests/HealthProtocolValidationTest.php` (new)       | The crash vectors are refused; codes are right; gate works       |

`WebSocketMessageValidator` is a separate class rather than more methods on `Health` because `src/Health.php` is already 4108 lines, and validating an inbound message is a
distinct responsibility with a clean interface. `src/Services/` is where `JwtService` lives.

---

### Task 1: The published schema and its compatibility test

**Files:**

- Create: `jsondata/schemas/WebSocketMessage.json`
- Modify: `src/Enum/LitSchema.php` (add a case; extend `error()` and `fromURL()`)
- Test: `phpunit_tests/Schemas/WebSocketMessageSchemaTest.php`

**Interfaces:**

- Consumes: nothing from earlier tasks.
- Produces: `LitSchema::WEBSOCKET_MESSAGE` (path `/WebSocketMessage.json`), and a schema whose `definitions` keys are exactly `executeValidation`, `validateCalendarLegacy`,
`validateCalendarTyped`, `executeUnitTest`, `runTest`, `cancelRun`, `validateSource`, `calendarIdentity`. Task 3 reads `definitions.<shape>.properties` by those names.

**Background you need:** the shapes are already specified as `@phpstan-type` annotations in the `Health` class docblock (`src/Health.php:55-65`). PHPStan level 10 enforces
them against the implementation, so they are the closest thing to a written contract that exists. Transcribe them; do not invent constraints.

Two deliberate decisions, both of which you must not "improve":

1. **`year` is `"type": "integer"`.** PHP's coercive typing means a client sending `"2024"` works today. Requiring an integer is a deliberate, narrow tightening; the shipped
client sends numbers (`assets/js/index.js:686-693`), which Step 3's test proves.
2. **`category` enums are closed.** A misspelled category currently survives to "Unable to detect schema" much later; closing the enum is the point of #806 §7. It is still an
error either way — just earlier and legible.

- [ ] **Step 1: Write the schema file**

Create `jsondata/schemas/WebSocketMessage.json`:

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "$id": "https://litcal.johnromanodorazio.com/api/dev/jsondata/schemas/WebSocketMessage.json",
    "title": "Health WebSocket inbound message",
    "description": "Every message the Health WebSocket endpoint accepts. Shapes are discriminated by `action`, and for `validateCalendar` additionally by whether `calendar` is a string (v1) or an object (v2). Properties a shape does not declare are refused only for messages carrying a `requestId`; see API issue #806 section G.",
    "oneOf": [
        { "$ref": "#/definitions/executeValidation" },
        { "$ref": "#/definitions/validateCalendarLegacy" },
        { "$ref": "#/definitions/validateCalendarTyped" },
        { "$ref": "#/definitions/executeUnitTest" },
        { "$ref": "#/definitions/runTest" },
        { "$ref": "#/definitions/cancelRun" },
        { "$ref": "#/definitions/validateSource" }
    ],
    "definitions": {
        "correlationId": {
            "type": "string",
            "pattern": "^[A-Za-z0-9_-]{1,64}$",
            "description": "An opaque client-minted handle the server echoes back. One alphabet for runToken and requestId alike."
        },
        "calendarIdentity": {
            "type": "object",
            "required": ["kind", "rite"],
            "properties": {
                "kind": { "enum": ["general", "national", "diocesan", "rite"] },
                "id": { "type": "string" },
                "rite": { "type": "string" }
            }
        },
        "executeValidation": {
            "type": "object",
            "required": ["action", "category", "validate"],
            "oneOf": [
                { "required": ["sourceFile"] },
                { "required": ["sourceFolder"] }
            ],
            "properties": {
                "action": { "const": "executeValidation" },
                "category": { "enum": ["universalcalendar", "sourceDataCheck", "resourceDataCheck"] },
                "validate": { "type": "string" },
                "sourceFile": { "type": "string" },
                "sourceFolder": { "type": "string" },
                "responsetype": { "type": "string" },
                "rite": { "type": "string" },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        },
        "validateCalendarLegacy": {
            "type": "object",
            "required": ["action", "calendar", "year", "category", "responsetype"],
            "properties": {
                "action": { "const": "validateCalendar" },
                "calendar": { "type": "string" },
                "year": { "type": "integer" },
                "category": { "enum": ["nationalcalendar", "diocesancalendar", "ritecalendar"] },
                "responsetype": { "enum": ["JSON", "XML", "ICS", "YML"] },
                "rite": { "type": "string" },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        },
        "validateCalendarTyped": {
            "type": "object",
            "required": ["action", "calendar", "year", "responseFormat"],
            "properties": {
                "action": { "const": "validateCalendar" },
                "calendar": { "$ref": "#/definitions/calendarIdentity" },
                "year": { "type": "integer" },
                "responseFormat": { "enum": ["JSON", "XML", "ICS", "YML"] },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        },
        "executeUnitTest": {
            "type": "object",
            "required": ["action", "calendar", "year", "category", "test"],
            "properties": {
                "action": { "const": "executeUnitTest" },
                "calendar": { "type": "string" },
                "year": { "type": "integer" },
                "category": { "enum": ["nationalcalendar", "diocesancalendar", "ritecalendar"] },
                "test": { "type": "string" },
                "rite": { "type": "string" },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        },
        "runTest": {
            "type": "object",
            "required": ["action", "test", "calendar", "year"],
            "properties": {
                "action": { "const": "runTest" },
                "test": { "type": "string" },
                "calendar": { "$ref": "#/definitions/calendarIdentity" },
                "year": { "type": "integer" },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        },
        "cancelRun": {
            "type": "object",
            "required": ["action", "runToken"],
            "properties": {
                "action": { "const": "cancelRun" },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        },
        "validateSource": {
            "type": "object",
            "required": ["action", "target"],
            "properties": {
                "action": { "const": "validateSource" },
                "target": {
                    "type": "object",
                    "required": ["id"],
                    "properties": { "id": { "type": "string" } }
                },
                "runToken": { "$ref": "#/definitions/correlationId" },
                "requestId": { "$ref": "#/definitions/correlationId" }
            }
        }
    }
}
```

- [ ] **Step 2: Add the `LitSchema` case**

In `src/Enum/LitSchema.php`, add the case after `VALIDATIONS`:

```php
    case WEBSOCKET_MESSAGE = '/WebSocketMessage.json';
```

Add an arm to `error()` (the `match` is exhaustive — omitting it is a PHPStan error):

```php
            LitSchema::WEBSOCKET_MESSAGE => $ERRMSG . 'WebSocket message not valid',
```

And an arm to `fromURL()`, in the same style as its neighbours:

```php
            LitSchema::WEBSOCKET_MESSAGE->path() => LitSchema::WEBSOCKET_MESSAGE,
```

- [ ] **Step 3: Write the failing compatibility test**

Create `phpunit_tests/Schemas/WebSocketMessageSchemaTest.php`. The shapes in `clientMessageProvider()` are transcribed from `UnitTestInterface/assets/js/wsProtocol.js`
(`UNIVERSAL_CHECKS`), `assets/js/index.js:531` (`sendMessage()` injects `runToken` into every message) and `assets/js/index.js:686-693`.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

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
            'source-data check with the spread rite' => [[
                'action'     => 'executeValidation',
                'rite'       => 'roman',
                'validate'   => 'PropriumDeTempore',
                'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
                'category'   => 'universalcalendar',
                'runToken'   => 'abc123'
            ]],
            'i18n folder check with the spread rite' => [[
                'action'       => 'executeValidation',
                'rite'         => 'roman',
                'validate'     => 'proprium-de-tempore-i18n',
                'sourceFolder' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n',
                'category'     => 'sourceDataCheck',
                'runToken'     => 'abc123'
            ]],
            // resources.js:1184 — the resource arm adds responsetype.
            'resource check with responsetype' => [[
                'action'       => 'executeValidation',
                'responsetype' => 'JSON',
                'rite'         => 'roman',
                'validate'     => 'calendars',
                'sourceFile'   => 'http://localhost:8000/calendars',
                'category'     => 'resourceDataCheck',
                'runToken'     => 'abc123'
            ]],
            // index.js:686-693 — note `year` is a number, which is why the schema may require integer.
            'legacy validateCalendar' => [[
                'action'       => 'validateCalendar',
                'year'         => 2024,
                'calendar'     => 'IT',
                'category'     => 'nationalcalendar',
                'rite'         => 'roman',
                'responsetype' => 'JSON',
                'runToken'     => 'abc123'
            ]],
            'legacy executeUnitTest' => [[
                'action'   => 'executeUnitTest',
                'test'     => 'AllSaintsTest',
                'calendar' => 'IT',
                'year'     => 2024,
                'category' => 'nationalcalendar',
                'runToken' => 'abc123'
            ]],
            // wsProtocol.js:31
            'cancelRun' => [[
                'action'   => 'cancelRun',
                'runToken' => 'abc123'
            ]],
            'v2 validateSource' => [[
                'action'    => 'validateSource',
                'target'    => ['id' => 'temporale:roman'],
                'runToken'  => 'abc123',
                'requestId' => 'req-1'
            ]],
            'v2 typed validateCalendar' => [[
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                'year'           => 2026,
                'responseFormat' => 'JSON',
                'requestId'      => 'req-2'
            ]],
            'v2 runTest' => [[
                'action'    => 'runTest',
                'test'      => 'AllSaintsTest',
                'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                'year'      => 2024,
                'requestId' => 'req-3'
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('clientMessageProvider')]
    public function testAMessageTheShippedClientSendsValidates(array $message): void
    {
        $decoded = json_decode((string) json_encode($message));
        self::schema()->in($decoded);
        // in() throws on failure; reaching here is the assertion.
        self::assertTrue(true);
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
            $name = substr((string) $arm->{'$ref'}, strlen('#/definitions/'));
            try {
                Schema::import((object) ['$schema' => 'https://json-schema.org/draft-07/schema#'] + (array) $raw->definitions->{$name})
                    ->in($decoded);
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
                'correlationId', 'calendarIdentity', 'executeValidation', 'validateCalendarLegacy',
                'validateCalendarTyped', 'executeUnitTest', 'runTest', 'cancelRun', 'validateSource'
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
            'year as a non-numeric string' => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 'not-a-year', 'category' => 'nationalcalendar', 'responsetype' => 'JSON']],
            'category as an array'         => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => [], 'responsetype' => 'JSON']],
            'unknown response format'      => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar', 'responsetype' => 'NOT_A_FORMAT']],
            'test as an object'            => [['action' => 'executeUnitTest', 'test' => ['a' => 1], 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar']],
            'executeValidation category as an object' => [['action' => 'executeValidation', 'category' => ['k' => 'v'], 'validate' => 'x', 'sourceFile' => 'jsondata/x.json']],
            'misspelled category'          => [['action' => 'executeValidation', 'category' => 'sourceDataChek', 'validate' => 'x', 'sourceFile' => 'jsondata/x.json']],
            'neither sourceFile nor sourceFolder' => [['action' => 'executeValidation', 'category' => 'sourceDataCheck', 'validate' => 'x']],
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
```

- [ ] **Step 4: Run the test and watch it fail for the right reason**

```bash
vendor/bin/phpunit --no-coverage --filter WebSocketMessageSchemaTest
```

Expected before Steps 1-2 are in place: errors about `LitSchema::WEBSOCKET_MESSAGE` not existing. After them: all green. If any *client* fixture fails, **the schema is wrong,
not the fixture** — the fixtures describe shipped behaviour.

- [ ] **Step 5: Verify the schema is served and self-consistent**

```bash
vendor/bin/phpunit --no-coverage --filter 'SchemaValidationTest|WebSocketMessageSchemaTest'
composer analyse
composer lint
```

`SchemaValidationTest::testSchemaCanBeImported` is data-provided from `LitSchema` cases, so the new case is exercised automatically. `SchemasHandler` globs the schema folder,
so no handler change is needed for `/schemas/WebSocketMessage.json` to be served.

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/WebSocketMessage.json src/Enum/LitSchema.php phpunit_tests/Schemas/WebSocketMessageSchemaTest.php
git commit -m "feat(health): publish WebSocketMessage.json as the inbound message contract

Transcribed from the @phpstan-type annotations in the Health class docblock,
which PHPStan level 10 already enforces against the implementation, so the
schema, the annotation and the code now agree by construction.

The load-bearing test is compatibility: the shipped UnitTestInterface injects
runToken into every message and spreads rite onto an executeValidation that
never reads it, so the fixtures are transcribed from the client source rather
than imagined. Nothing is wired to this yet.

Refs #806"
```

---

### Task 2: Typed protocol errors

**Files:**

- Create: `src/Enum/ProtocolErrorCode.php`
- Modify: `src/Health.php` (`rejectMessage()` and its call sites)
- Modify: `phpunit_tests/HealthValidateSourceTest.php`, `phpunit_tests/HealthTypedCalendarTest.php`, `phpunit_tests/HealthCancelRunTest.php` (existing assertions on `type ===
'echobot'`)

**Interfaces:**

- Consumes: nothing from Task 1.
- Produces: `ProtocolErrorCode` (a string-backed enum) and the new signature
  `private function rejectMessage(ConnectionInterface $to, ProtocolErrorCode $code, string $text, ?string $requestId = null): void`.
  Task 3 calls it with `ProtocolErrorCode::INVALID_MESSAGE`; Task 4 with `ProtocolErrorCode::INTERNAL_ERROR`.

**Background:** `rejectMessage()` currently sends `type: 'echobot'`. Changing it to `protocolError` is safe **ungated**: `UnitTestInterface/assets/js/index.js:932-937` treats
*any* unrecognised type — `echobot` included — as a visible failure via `countUnattributableFailure()`. Its docblock says a dedicated type "would make every rejection look
like a failing test"; that is now equally true of `echobot`, so the comment must be corrected rather than preserved.

- [ ] **Step 1: Write the enum**

Create `src/Enum/ProtocolErrorCode.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Why a message was refused, in a form a client can branch on.
 *
 * A code exists where a client would *act* differently; where it would only display the reason, the
 * reason is prose in the frame's `text`. That is why `INVALID_MESSAGE` covers every schema
 * violation — a wrong type, an unknown enum value and an undeclared property all mean "fix the
 * message", and the text says which — while `RETIRED_PROPERTY` (you are half-migrated) and
 * `UNKNOWN_TARGET_ID` (refetch /validations) are separate.
 */
enum ProtocolErrorCode: string
{
    case INVALID_JSON       = 'invalid_json';
    case NOT_AN_OBJECT      = 'not_an_object';
    case MISSING_ACTION     = 'missing_action';
    case UNKNOWN_ACTION     = 'unknown_action';
    case INVALID_REQUEST_ID = 'invalid_request_id';
    case RETIRED_PROPERTY   = 'retired_property';
    case UNKNOWN_TARGET_ID  = 'unknown_target_id';
    case INVALID_MESSAGE    = 'invalid_message';
    case INTERNAL_ERROR     = 'internal_error';
}
```

- [ ] **Step 2: Write the failing test**

Create `phpunit_tests/HealthProtocolValidationTest.php` with the stub connection and this first test. (Task 3 adds more tests to this same class.)

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * What `Health` does with a message it cannot act on.
 *
 * Every test here drives `onMessage()` with a real JSON string. The private validator is never
 * invoked directly: #825's lesson was that an emitter can be correct and tested while nothing routes
 * to it, and a test that calls the right function directly passes against exactly that bug.
 */
#[CoversClass(Health::class)]
final class HealthProtocolValidationTest extends TestCase
{
    use HealthQueueIsolationTrait;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    private static function createStubConnection(int $resourceId = 1)
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * Send one raw message and return the frames it produced.
     *
     * @return list<\stdClass>
     */
    private function frames(string $raw): array
    {
        $conn = self::createStubConnection();
        ob_start();
        $this->newHealth()->onMessage($conn, $raw);
        ob_end_clean();

        return array_map(static fn (string $f): \stdClass => json_decode($f), $conn->sent);
    }

    public function testARejectionIsATypedProtocolErrorRatherThanAnEchobot(): void
    {
        $frames = $this->frames((string) json_encode(['action' => 'validateSource', 'target' => ['id' => 'nation:roman:ZZ']]));

        self::assertCount(1, $frames);
        self::assertSame('protocolError', $frames[0]->type, 'rejections are typed now, not echoes');
        self::assertSame(ProtocolErrorCode::UNKNOWN_TARGET_ID->value, $frames[0]->errorCode);
        self::assertSame('Unknown validation target: nation:roman:ZZ', $frames[0]->text, 'the prose is unchanged; only the envelope is typed');
    }
}
```

- [ ] **Step 3: Run it and confirm it fails**

```bash
vendor/bin/phpunit --no-coverage --filter HealthProtocolValidationTest
```

Expected: fails asserting `'protocolError'` against the actual `'echobot'`.

- [ ] **Step 4: Change `rejectMessage()` and its call sites**

In `src/Health.php`, replace the body and docblock of `rejectMessage()`:

```php
    /**
     * Reject a message that cannot be acted on, saying why in a form a client can branch on.
     *
     * Ungated, unlike the terminal `complete` frame, and the difference is real rather than an
     * inconsistency: a new frame changes the stream a v1 client counts, while a new *type* on a
     * frame it was already going to receive changes nothing for it. Since UnitTestInterface#46 an
     * unrecognised type is painted as a visible failed check — which is what `echobot` already
     * became — so `protocolError` reads to a v1 client exactly as its predecessor did.
     *
     * `text` carries the prose, as every other frame in this protocol does. #806's sketch spells it
     * `message`; a second name for an existing field is the duplication that issue exists to remove.
     */
    private function rejectMessage(ConnectionInterface $to, ProtocolErrorCode $code, string $text, ?string $requestId = null): void
    {
        $message            = new \stdClass();
        $message->type      = 'protocolError';
        $message->errorCode = $code->value;
        $message->text      = $text;
        $this->sendMessage($to, $message, requestId: $requestId);
    }
```

Add `use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;` to the imports.

Then update every existing `rejectMessage(` call to pass a code. Find them with:

```bash
grep -n 'rejectMessage(' src/Health.php
```

Use `ProtocolErrorCode::INVALID_REQUEST_ID` for the correlation-id refusal in `onMessage()`, `ProtocolErrorCode::RETIRED_PROPERTY` inside `rejectRetiredProperties()`,
`ProtocolErrorCode::UNKNOWN_TARGET_ID` for an unresolvable `validateSource` target, and `ProtocolErrorCode::INVALID_MESSAGE` for every remaining shape complaint (a malformed
`calendar` identity, an unusable response format, and similar).

Also replace the two inline `echobot` frames in `onMessage()`'s `default:` arm and its `else` branch with `rejectMessage()` calls carrying `ProtocolErrorCode::UNKNOWN_ACTION`
and, respectively, the code matching `$errorMsg`: `INVALID_JSON`, `NOT_AN_OBJECT`, `MISSING_ACTION`, or `INVALID_MESSAGE`.

- [ ] **Step 5: Update the existing assertions on `echobot`**

```bash
grep -rn "echobot" phpunit_tests/ src/
```

Every remaining occurrence is either an assertion to update to `'protocolError'` or a comment describing the old behaviour, which must be corrected rather than left. Add the
matching `errorCode` assertion wherever you update a `type` assertion — that is what stops two codes silently collapsing into one later.

- [ ] **Step 6: Run the Health suite**

```bash
vendor/bin/phpunit --no-coverage --filter 'Health'
composer analyse
composer lint
```

Expected: green.

- [ ] **Step 7: Commit**

```bash
git add src/Enum/ProtocolErrorCode.php src/Health.php phpunit_tests/
git commit -m "feat(health): answer refusals with a typed protocolError frame

type becomes protocolError and carries an errorCode a client can branch on.
Ungated: since UnitTestInterface#46 an unrecognised type is painted as a visible
failed check, which is what echobot already became, so this reads to a v1 client
exactly as its predecessor did. The docblock claiming otherwise is corrected.

Codes exist where a client would act differently. INVALID_MESSAGE deliberately
covers every schema violation: wrong type, unknown enum value and undeclared
property all mean fix the message, and text says which.

Refs #806"
```

---

### Task 3: The validator, wired in

**Files:**

- Create: `src/Services/WebSocketMessageValidator.php`
- Modify: `src/Health.php` (`onMessage()`; delete `validateMessageProperties()`, `ACTION_PROPERTIES`, `TYPED_CALENDAR_PROPERTIES`)
- Test: `phpunit_tests/HealthProtocolValidationTest.php` (extend)

**Interfaces:**

- Consumes: `LitSchema::WEBSOCKET_MESSAGE` (Task 1), `ProtocolErrorCode` and the new `rejectMessage()` signature (Task 2).
- Produces:

```php
final class WebSocketMessageValidator
{
    public function __construct(?string $schemaPath = null);
    /**
     * @param list<string> $deferToHandler property names to leave for a handler to diagnose
     * @return string|null null when the message is acceptable, else the reason for the client
     */
    public function validate(\stdClass $message, array $deferToHandler = []): ?string;
    public function warm(): void;
    public static function shapeOf(\stdClass $message): ?string;
    public static function reset(): void;
}
```

**Background:** `Health::isTypedCalendarMessage()` stays — it is the discriminator for `validateCalendar`'s two shapes and is used by the dispatch too. `RETIRED_PROPERTIES`
and `rejectRetiredProperties()` stay and keep running **before** this validator. `ACTION_PROPERTIES`, `TYPED_CALENDAR_PROPERTIES` and `validateMessageProperties()` are used
only for required-property checking and are replaced by the schema.

- [ ] **Step 1: Write the validator**

Create `src/Services/WebSocketMessageValidator.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\LitSchema;
use Swaggest\JsonSchema\Schema;

/**
 * Validates an inbound Health WebSocket message against the published contract.
 *
 * Two rules, deliberately different in reach:
 *
 *  - **Types, enums and required properties** are checked for every message. A message that fails
 *    these is one that would otherwise reach a typed parameter and throw a `TypeError` — an
 *    `\Error`, which Ratchet's `IoServer::handleData` does not catch, so it would kill the process
 *    rather than fail the request. Refusing it is better for a v1 client too.
 *  - **Undeclared properties** are refused only for messages carrying a `requestId`, the same v2
 *    opt-in the terminal `complete` frame is gated on. The shipped client sends `runToken` on every
 *    message and spreads `rite` onto an `executeValidation` that never reads it; a uniform rule
 *    would take the test interface down.
 *
 * The second rule is applied here rather than in the schema because `swaggest/json-schema` v0.12.43
 * implements draft-07, where `additionalProperties` sees only the properties declared in the same
 * schema object — so the natural `if`/`then`/`unevaluatedProperties` spelling needs 2019-09. The
 * allowed *names* still come from the schema; only the gate is here. See issue #826.
 */
final class WebSocketMessageValidator
{
    private static ?Schema $schema = null;

    /** @var array<string, list<string>>|null */
    private static ?array $propertyNames = null;

    private string $schemaPath;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? LitSchema::WEBSOCKET_MESSAGE->path();
    }

    /**
     * @param list<string> $deferToHandler Property names this must not report, because a handler says
     *        something better about them. See the note on retired properties below.
     * @return string|null null when the message is acceptable, otherwise the reason, phrased for the
     *         client that sent it.
     */
    public function validate(\stdClass $message, array $deferToHandler = []): ?string
    {
        try {
            $this->schema()->in($message);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if (false === property_exists($message, 'requestId')) {
            return null;
        }

        $shape = self::shapeOf($message);
        if (null === $shape) {
            return null;
        }

        $allowed = $this->propertyNamesFor($shape);
        foreach (array_keys((array) $message) as $property) {
            if (in_array((string) $property, $deferToHandler, true)) {
                continue;
            }
            if (false === in_array((string) $property, $allowed, true)) {
                return sprintf(
                    '%s is not a property of a %s message. A message carrying a requestId may only use the properties the contract declares: %s.',
                    (string) $property,
                    $shape,
                    implode(', ', $allowed)
                );
            }
        }

        return null;
    }

    /**
     * Which published shape a message claims to be. Mirrors the dispatch: the action name, plus the
     * type of `calendar` for the one action that carries two shapes.
     */
    public static function shapeOf(\stdClass $message): ?string
    {
        if (false === property_exists($message, 'action') || false === is_string($message->action)) {
            return null;
        }

        if ('validateCalendar' === $message->action) {
            return property_exists($message, 'calendar') && $message->calendar instanceof \stdClass
                ? 'validateCalendarTyped'
                : 'validateCalendarLegacy';
        }

        return in_array($message->action, ['executeValidation', 'executeUnitTest', 'runTest', 'cancelRun', 'validateSource'], true)
            ? $message->action
            : null;
    }

    /**
     * The property names the contract declares for a shape, read from the schema itself so that this
     * class carries no list of its own.
     *
     * @return list<string>
     */
    private function propertyNamesFor(string $shape): array
    {
        if (null === self::$propertyNames) {
            $raw = json_decode((string) file_get_contents($this->schemaPath));
            if (false === $raw instanceof \stdClass || false === isset($raw->definitions)) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} has no definitions.");
            }
            $names = [];
            foreach ((array) $raw->definitions as $name => $definition) {
                if ($definition instanceof \stdClass && isset($definition->properties)) {
                    $names[(string) $name] = array_map('strval', array_keys((array) $definition->properties));
                }
            }
            self::$propertyNames = $names;
        }

        return self::$propertyNames[$shape] ?? [];
    }

    private function schema(): Schema
    {
        if (null === self::$schema) {
            $imported = Schema::import($this->schemaPath);
            if (false === $imported instanceof Schema) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} could not be imported.");
            }
            self::$schema = $imported;
        }

        return self::$schema;
    }

    /**
     * Drop the memoized schema. Tests that point the validator at a different file need this; nothing
     * in production does.
     */
    public static function reset(): void
    {
        self::$schema        = null;
        self::$propertyNames = null;
    }
}
```

- [ ] **Step 2: Write the failing tests**

Append to `phpunit_tests/HealthProtocolValidationTest.php`:

```php
    /**
     * The crash vectors. Each of these terminated the WebSocket process before this work: Ratchet's
     * `IoServer::handleData` catches `\Exception`, and `TypeError`, `ValueError` and `Error` are not
     * `\Exception`. The assertion is therefore two-part — a typed refusal came back, *and* nothing
     * escaped — because a test that only checked the frame would pass on a process that had already
     * died in a real server.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function crashVectorProvider(): array
    {
        return [
            'year as a non-numeric string'  => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 'not-a-year', 'category' => 'nationalcalendar', 'responsetype' => 'JSON']],
            'category as an array'          => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => [], 'responsetype' => 'JSON']],
            'unknown response format'       => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar', 'responsetype' => 'NOT_A_FORMAT']],
            'test as an object'             => [['action' => 'executeUnitTest', 'test' => ['a' => 1], 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar']],
            'executeValidation category as an object' => [['action' => 'executeValidation', 'category' => ['k' => 'v'], 'validate' => 'x', 'sourceFile' => 'jsondata/x.json']],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('crashVectorProvider')]
    public function testAMessageThatUsedToKillTheProcessIsRefused(array $message): void
    {
        $frames = $this->frames((string) json_encode($message));

        self::assertCount(1, $frames, 'a refused message must not also start work');
        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frames[0]->errorCode);
    }

    /**
     * The gate, both directions, in one place: the same message is accepted without a `requestId`
     * and refused with one. Asserting only the refusal would pass on a build that refused everything.
     */
    public function testAnUndeclaredPropertyIsRefusedOnlyWhenTheMessageOptedIn(): void
    {
        $lenient = [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            'notAThing'  => 'whatever'
        ];

        $acceptedFrames = $this->frames((string) json_encode($lenient));
        self::assertNotEmpty($acceptedFrames, 'a v1 message with a stray property must still be served');
        self::assertNotSame('protocolError', $acceptedFrames[0]->type, "a v1 message was refused: {$acceptedFrames[0]->text}");

        $refusedFrames = $this->frames((string) json_encode($lenient + ['requestId' => 'req-1']));
        self::assertSame('protocolError', $refusedFrames[0]->type, 'a message carrying a requestId opted into strictness');
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $refusedFrames[0]->errorCode);
        self::assertStringContainsString('notAThing', (string) $refusedFrames[0]->text, 'the refusal must name the offending property');
    }

    /**
     * `rite` on an `executeValidation` is the concrete case: the shipped client spreads it onto every
     * source-data check and the server never reads it. It is declared, so it survives even under the
     * strict gate — which is what stops a future tidy-up from removing it from the schema.
     */
    public function testTheSpreadRiteSurvivesEvenUnderTheStrictGate(): void
    {
        $frames = $this->frames((string) json_encode([
            'action'     => 'executeValidation',
            'rite'       => 'roman',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            'requestId'  => 'req-2'
        ]));

        self::assertNotSame('protocolError', $frames[0]->type, "rite was refused: {$frames[0]->text}");
    }

    /**
     * Every action the schema declares has a dispatch arm and vice versa. Adding an action without a
     * schema entry would otherwise fall through to `unknown_action` in production rather than in CI.
     */
    public function testEverySchemaShapeHasADispatchArmAndEveryArmHasAShape(): void
    {
        $raw    = json_decode((string) file_get_contents(\LiturgicalCalendar\Api\Enum\LitSchema::WEBSOCKET_MESSAGE->path()));
        $actions = [];
        foreach ((array) $raw->definitions as $definition) {
            if ($definition instanceof \stdClass && isset($definition->properties->action->const)) {
                $actions[(string) $definition->properties->action->const] = true;
            }
        }

        $source = (string) file_get_contents(__DIR__ . '/../src/Health.php');
        preg_match_all("/case '([a-zA-Z]+)':/", $source, $matches);
        $dispatched = array_unique($matches[1]);

        self::assertEqualsCanonicalizing(
            array_keys($actions),
            array_values(array_intersect($dispatched, array_keys($actions))),
            'schema shapes and dispatch arms have drifted apart'
        );
        foreach ($dispatched as $arm) {
            self::assertArrayHasKey($arm, $actions, "the dispatch handles {$arm} but the schema does not declare it");
        }
    }
```

Add `use PHPUnit\Framework\Attributes\DataProvider;` to the class imports.

- [ ] **Step 3: Run and confirm they fail**

```bash
vendor/bin/phpunit --no-coverage --filter HealthProtocolValidationTest
```

Expected: the crash-vector tests fail with an uncaught `TypeError` / `ValueError` — which is the bug, reproduced.

- [ ] **Step 4: Wire the validator into `onMessage()`**

In `src/Health.php`, add the import `use LiturgicalCalendar\Api\Services\WebSocketMessageValidator;` and a property:

```php
    private WebSocketMessageValidator $messageValidator;
```

initialised in the constructor with `$this->messageValidator = new WebSocketMessageValidator();`.

In `onMessage()`, replace the `&& self::validateMessageProperties($messageReceived)` condition in the big `if` with a preceding explicit check, so the reason is available.
After the existing `requestId` block and before the `if`, insert:

```php
        if (
            json_last_error() === JSON_ERROR_NONE
            && $messageReceived instanceof \stdClass
            && property_exists($messageReceived, 'action')
        ) {
            $invalid = $this->messageValidator->validate($messageReceived);
            if (null !== $invalid) {
                echo sprintf('Invalid message from connection %1$d: %2$s (%3$s)', $resourceId, $invalid, $msg);
                $this->rejectMessage($from, ProtocolErrorCode::INVALID_MESSAGE, $invalid, requestId: $requestId);
                return;
            }
        }
```

Then change the big `if`'s condition to drop `self::validateMessageProperties($messageReceived)`, leaving the JSON, object and `action` checks.

- [ ] **Step 5: Keep the retired-property diagnosis ahead of the schema's**

The spec (§3) requires a half-migrated message to be answered for what is actually wrong with it. `rejectRetiredProperties()`
runs inside each v2 handler — that is, *after* the validator — so without this step a `validateSource` carrying both a
`requestId` and a retired `category` would be refused as "category is not a property of a validateSource message" instead of
"category is not part of a validateSource message: target.id replaces it." The second sentence is the whole value of that
check, and the client that needs it is exactly the half-migrated one.

Rather than hoisting the handler check into `onMessage()`, pass the retired names for the shape so the validator steps aside
and lets the handler speak. In `Health::onMessage()`, build the list from the existing constant and hand it over:

```php
            $retiredForAction = array_keys(self::RETIRED_PROPERTIES[$messageReceived->action]['retired'] ?? []);
            $invalid          = $this->messageValidator->validate($messageReceived, $retiredForAction);
```

Note `RETIRED_PROPERTIES` is keyed by action (`validateSource`, `validateCalendar`, `runTest`), not by shape, which is why
this indexes by `$messageReceived->action` and not by `WebSocketMessageValidator::shapeOf()`.

Add the test to `phpunit_tests/HealthProtocolValidationTest.php`:

```php
    /**
     * A half-migrated message hears the sentence that helps it, not the generic one.
     *
     * `rejectRetiredProperties()` runs inside the handler, so it is reached only if the validator
     * declines to report the property first. Without the deferral this comes back as
     * INVALID_MESSAGE and the client is told the property is unknown rather than what replaced it.
     */
    public function testARetiredPropertyIsDiagnosedByTheHandlerNotTheSchema(): void
    {
        $frames = $this->frames((string) json_encode([
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'category'  => 'sourceDataCheck',
            'requestId' => 'req-4'
        ]));

        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frames[0]->errorCode, 'the generic schema complaint won the race');
        self::assertSame('category is not part of a validateSource message: target.id replaces it.', $frames[0]->text);
    }
```

- [ ] **Step 6: Import the schema at startup, not on the first message**

Spec §8: a WebSocket server that cannot validate is misconfigured and should say so before a client connects. In `Health`'s
constructor, after building the validator, force the import so an unreadable or malformed schema throws there:

```php
        $this->messageValidator = new WebSocketMessageValidator();
        $this->messageValidator->warm();
```

Add the method to `WebSocketMessageValidator`:

```php
    /**
     * Import the schema now rather than on the first message. A server that cannot validate is
     * misconfigured, and should fail where an operator sees it rather than answering every message
     * with an internal error.
     */
    public function warm(): void
    {
        $this->schema();
        $this->propertyNamesFor('validateSource');
    }
```

- [ ] **Step 7: Delete what the schema replaced**

Remove `validateMessageProperties()`, the `ACTION_PROPERTIES` constant and the `TYPED_CALENDAR_PROPERTIES` constant, along with the `else` branch's now-unreachable `'Invalid
message properties'` arm. Keep `isTypedCalendarMessage()`, `RETIRED_PROPERTIES` and `rejectRetiredProperties()`.

Fix the class-docblock references to the deleted constants: search for `ACTION_PROPERTIES` and rewrite each mention to name the schema instead. Several are long explanations
of why the retired set is not derivable from it — those stay true and valuable; they just need to point at `WebSocketMessage.json`.

- [ ] **Step 8: Run everything**

```bash
vendor/bin/phpunit --no-coverage --filter 'Health|WebSocketMessage'
composer test:quick
composer analyse
composer lint
```

Expected: green. If a pre-existing Health test now fails, read it before changing it — it may be describing behaviour this task deliberately changed, in which case update the
test *and* its docblock, or it may be a real regression.

- [ ] **Step 9: Commit**

```bash
git add src/Services/WebSocketMessageValidator.php src/Health.php phpunit_tests/HealthProtocolValidationTest.php
git commit -m "feat(health): validate every inbound message against the published contract

Five message shapes could terminate the WebSocket process for every connected
client: validateMessageProperties() checked that required keys existed and never
what they were, and the v1 dispatch arms unpack straight into typed parameters.
Ratchet catches \\Exception; TypeError, ValueError and Error are not \\Exception.

Types and enums are now checked for every message. Undeclared properties are
refused only for messages carrying a requestId, because the shipped client sends
runToken on every message and spreads rite onto an executeValidation that never
reads it. The allowed names are read from the schema, so this class carries no
list of its own.

ACTION_PROPERTIES and validateMessageProperties() are deleted rather than kept
beside the schema. A second copy is the disease.

Refs #806"
```

---

### Task 4: The backstop

**Files:**

- Modify: `src/Health.php` (`onMessage()` dispatch `switch`)
- Test: `phpunit_tests/HealthProtocolValidationTest.php` (extend)

**Interfaces:**

- Consumes: `ProtocolErrorCode::INTERNAL_ERROR` (Task 2), `WebSocketMessageValidator::reset()` and its `__construct(?string $schemaPath)` (Task 3).
- Produces: nothing later tasks depend on.

**Background:** the schema is the primary gate. The backstop exists because a crash that kills the process for every connected client must not depend on a schema file being
correct. The test therefore has to isolate the backstop *from* the schema — otherwise it only proves the schema works, which Task 3 already proved.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/HealthProtocolValidationTest.php`:

```php
    /**
     * The backstop, isolated from the gate that normally stands in front of it.
     *
     * Pointing the validator at a permissive schema lets a crash vector through to the dispatch, so
     * this asserts what happens when the schema is *wrong* — which is the only circumstance in which
     * the backstop matters, and the reason it is not redundant with Task 3's tests. Without it the
     * process dies; with it, one request fails and the daemon keeps serving.
     */
    public function testAHandlerThatThrowsIsContainedRatherThanKillingTheProcess(): void
    {
        $permissive = sys_get_temp_dir() . '/permissive-ws-schema-' . getmypid() . '.json';
        file_put_contents($permissive, (string) json_encode([
            '$schema' => 'https://json-schema.org/draft-07/schema#',
            'type'    => 'object'
        ]));

        $health    = $this->newHealth();
        $validator = new \ReflectionProperty(Health::class, 'messageValidator');
        $validator->setValue($health, new \LiturgicalCalendar\Api\Services\WebSocketMessageValidator($permissive));
        \LiturgicalCalendar\Api\Services\WebSocketMessageValidator::reset();

        $conn = self::createStubConnection();
        ob_start();
        try {
            $health->onMessage($conn, (string) json_encode([
                'action'       => 'validateCalendar',
                'calendar'     => 'IT',
                'year'         => 'not-a-year',
                'category'     => 'nationalcalendar',
                'responsetype' => 'JSON'
            ]));
        } finally {
            ob_end_clean();
            @unlink($permissive);
            \LiturgicalCalendar\Api\Services\WebSocketMessageValidator::reset();
        }

        $frames = array_map(static fn (string $f): \stdClass => json_decode($f), $conn->sent);
        self::assertNotEmpty($frames, 'the connection was told nothing at all');
        $last = $frames[count($frames) - 1];
        self::assertSame('protocolError', $last->type);
        self::assertSame(ProtocolErrorCode::INTERNAL_ERROR->value, $last->errorCode);
    }
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
vendor/bin/phpunit --no-coverage --filter testAHandlerThatThrowsIsContainedRatherThanKillingTheProcess
```

Expected: an uncaught `TypeError` escapes the test — the crash, reproduced with the gate removed.

- [ ] **Step 3: Add the backstop**

In `src/Health.php`, wrap the dispatch `switch` in `onMessage()`:

```php
            try {
                switch ($messageReceived->action) {
                    // … existing arms, unchanged …
                }
            } catch (\Throwable $e) {
                // Ratchet's IoServer::handleData catches \Exception, and TypeError, ValueError and
                // Error are not \Exception — so without this, anything a handler throws terminates
                // the process for every connected client rather than failing one request.
                //
                // Schema validation stands in front of this and is the real gate. The backstop is
                // here because a process-wide crash must not depend on a schema file being correct.
                // A catch-all can mask a bug, which is why this logs before it answers: the log line
                // is what keeps the masked bug findable.
                //
                // It does NOT cover #823. Those throws happen inside promise callbacks, after this
                // method has returned, where no try around the dispatch can see them.
                echo sprintf(
                    "Uncaught %s handling %s from connection %d: %s\n",
                    get_class($e),
                    (string) $messageReceived->action,
                    $resourceId,
                    $e->getMessage()
                );
                $this->rejectMessage(
                    $from,
                    ProtocolErrorCode::INTERNAL_ERROR,
                    'The server failed while handling this message. This is a bug; the run may be incomplete.',
                    requestId: $requestId
                );
            }
```

- [ ] **Step 4: Run the suites**

```bash
vendor/bin/phpunit --no-coverage --filter 'Health|WebSocketMessage'
composer test:quick
composer analyse
composer lint
composer lint:md
```

Expected: green.

- [ ] **Step 5: Mutation-check every guard this branch added**

For each of the three, remove it, confirm the named tests fail, then restore it. A guard whose removal breaks nothing is not a guard.

1. Delete the `catch (\Throwable)` block → `testAHandlerThatThrowsIsContainedRatherThanKillingTheProcess` must fail.
2. Make `WebSocketMessageValidator::validate()` return `null` immediately → all five `crashVectorProvider` cases must fail.
3. Delete the `property_exists($message, 'requestId')` early return in `validate()` → `testAnUndeclaredPropertyIsRefusedOnlyWhenTheMessageOptedIn` must fail on its *accept*
half, and the `WebSocketMessageSchemaTest` client fixtures must still pass (they carry no `requestId` except the v2 ones).

Record the result of each in the task report.

- [ ] **Step 6: Commit**

```bash
git add src/Health.php phpunit_tests/HealthProtocolValidationTest.php
git commit -m "feat(health): contain a throwing handler instead of losing the process

Ratchet's IoServer::handleData catches \\Exception; TypeError, ValueError and
Error are not \\Exception. Schema validation is the real gate, but a crash that
kills the process for every connected client must not depend on a schema file
being correct, so the dispatch is wrapped and answers INTERNAL_ERROR.

The test points the validator at a permissive schema so a crash vector reaches
the dispatch: the backstop only matters when the schema is wrong, and a test
that did not isolate it would merely re-prove the gate.

This does not cover #823 — those throws happen inside promise callbacks, after
onMessage() has returned — and the comment says so.

Refs #806"
```

---

## Notes for the reviewer of the final branch

- The one intentional behaviour change for a *working* v1 client is that `year` must now be a JSON number rather than a numeric string, and `category` must be a known value.
Both are argued in the spec; the shipped client satisfies both, which `WebSocketMessageSchemaTest::clientMessageProvider` proves.
- `#823` is untouched and stays open.
- Issue #826 tracks the validator upgrade that would let §4's gate move into the schema.
