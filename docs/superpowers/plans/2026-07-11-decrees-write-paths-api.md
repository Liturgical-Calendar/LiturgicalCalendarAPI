# Decrees Write Paths (API) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement authenticated, FGA-gated `PUT/PATCH/DELETE /decrees/{decree_id}` plus `readings` enrichment of GET, per the approved spec
`docs/superpowers/specs/2026-07-11-decrees-write-paths-design.md`.

**Architecture:** Per-item write paths on `DecreesHandler` following the `TemporaleHandler` write-path pattern: JSON-schema validation + DTO invariants + a per-action sidecar
guard, mutations to the single `jsondata/sourcedata/decrees/decrees.json` database with i18n/lectionary distribution, audit logging. Authorization is the existing middleware
pipeline with a decrees-specific relation map (`PUT/PATCH→editor`, `DELETE→admin`) on FGA object `general_roman_calendar:decrees`.

**Tech Stack:** PHP 8.4, PSR-7/15, Swaggest JSON Schema, PHPUnit, OpenFGA (existing `OpenFgaClient`).

## Global Constraints

- PHP 8.4+, PHPStan level 10 (`composer analyse` must stay green), PSR-12 (`composer lint`).
- Never bypass git hooks; run `composer test:quick` before each commit of PHP code.
- `GET /decrees` stays public; `decree_id` in URL is authoritative (body `decree_id` must match → 400).
- Relation map: PUT `editor`, PATCH `editor`, DELETE `admin` on `general_roman_calendar:decrees`.
- Sidecar matrix (spec §Payload): i18n required for `createNew`/`makeDoctor`/`setProperty:name` (must include Accept-Language base locale), rejected for `setProperty:grade`;
  readings required on PUT only for `createNew`, rejected on PUT otherwise, optional on PATCH for every action.
- All decree file writes: `JsonFormatter::encode($data) . PHP_EOL` with `LOCK_EX`.
- Work happens in the existing worktree `~/development/LiturgicalCalendar/wt-api-decrees-write` on branch `feature/decrees-write-paths`.

---

### Task 1: Write-payload JSON schema

**Files:**

- Create: `jsondata/schemas/LitCalDecreeWritePayload.json`
- Modify: `src/Enum/LitSchema.php` (add case + the two match arms)
- Test: `phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php`

**Interfaces:**

- Produces: `LitSchema::DECREE_WRITE` (value `/LitCalDecreeWritePayload.json`), used by Task 4/5 via `LitSchema::DECREE_WRITE->path()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

final class DecreeWritePayloadSchemaTest extends TestCase
{
    private static function schema(): Schema
    {
        return Schema::import(LitSchema::DECREE_WRITE->path());
    }

    private static function validCreateNewPayload(): \stdClass
    {
        $json = <<<'JSON'
        {
            "decree_id": "StTest_Create",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 1/25",
            "description": "Test decree creating a new liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "day": 14,
                "month": 2,
                "color": ["white"],
                "grade": 2,
                "common": ["Pastors"],
                "type": "fixed",
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2025,
                "url": "https://www.vatican.va/test.html"
            },
            "i18n": { "en": "Saint Test" },
            "readings": {
                "en": {
                    "first_reading": "Genesis 1:1",
                    "responsorial_psalm": "Psalm 1",
                    "gospel_acclamation": "John 1:1",
                    "gospel": "John 1:1-14"
                }
            }
        }
        JSON;
        $obj  = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    public function testValidCreateNewPayloadPasses(): void
    {
        self::schema()->in(self::validCreateNewPayload());
        $this->addToAssertionCount(1);
    }

    public function testUnknownTopLevelPropertyFails(): void
    {
        $payload         = self::validCreateNewPayload();
        $payload->bogus  = true;
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testEmptyI18nObjectFails(): void
    {
        $payload       = self::validCreateNewPayload();
        $payload->i18n = new \stdClass();
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testRegionalLocaleKeyInI18nFails(): void
    {
        $payload       = self::validCreateNewPayload();
        $payload->i18n = (object) ['en_US' => 'Saint Test'];
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php`
Expected: ERROR — `LitSchema::DECREE_WRITE` undefined / schema file missing.

- [ ] **Step 3: Create the schema**

`jsondata/schemas/LitCalDecreeWritePayload.json` — single decree plus write-only sidecars. Reuse
`LitCalDecreesSource.json` definitions for nested structures and `CommonDef.json#/definitions/Readings`
for readings:

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "title": "LitCalDecreeWritePayload",
    "description": "Single-decree write payload for PUT/PATCH /decrees/{decree_id}. Method- and action-dependent sidecar requirements (i18n, readings) are enforced by the API handler, not by this schema.",
    "type": "object",
    "additionalProperties": false,
    "properties": {
        "decree_id": { "$ref": "./LitCalDecreesSource.json#/definitions/LitCalDecree/properties/decree_id" },
        "decree_date": { "$ref": "./LitCalDecreesSource.json#/definitions/LitCalDecree/properties/decree_date" },
        "decree_protocol": { "$ref": "./LitCalDecreesSource.json#/definitions/LitCalDecree/properties/decree_protocol" },
        "description": { "$ref": "./LitCalDecreesSource.json#/definitions/LitCalDecree/properties/description" },
        "liturgical_event": { "$ref": "./LitCalDecreesSource.json#/definitions/LiturgicalEvent" },
        "metadata": { "$ref": "./LitCalDecreesSource.json#/definitions/Metadata" },
        "i18n": {
            "type": "object",
            "minProperties": 1,
            "propertyNames": { "pattern": "^[a-z]{2,3}$" },
            "additionalProperties": { "type": "string", "minLength": 1 }
        },
        "readings": {
            "type": "object",
            "minProperties": 1,
            "propertyNames": { "pattern": "^[a-z]{2,3}$" },
            "additionalProperties": { "$ref": "./CommonDef.json#/definitions/Readings" }
        }
    },
    "required": ["decree_id", "decree_date", "decree_protocol", "description", "liturgical_event", "metadata"]
}
```

Before committing, verify the two `$ref` targets exist with exactly these names
(`definitions/LitCalDecree/properties/decree_id` etc. in `LitCalDecreesSource.json`;
`definitions/Readings` in `CommonDef.json`); adjust ref paths to the actual property names if they
differ — the test suite will catch mismatches.

- [ ] **Step 4: Register in LitSchema enum**

In `src/Enum/LitSchema.php` add (alongside the existing `DECREES_SRC` case, line ~16):

```php
case DECREE_WRITE = '/LitCalDecreeWritePayload.json';
```

and in the two `match` expressions (error-message map ~line 42, path map ~line 65) add:

```php
LitSchema::DECREE_WRITE => $ERRMSG . 'Decree write payload not valid',
```

```php
LitSchema::DECREE_WRITE->path() => LitSchema::DECREE_WRITE,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/`
Expected: PASS (new test + existing `SchemaValidationTest` corpus still green).

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/LitCalDecreeWritePayload.json src/Enum/LitSchema.php phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php
git commit -m "feat: JSON schema for single-decree write payload"
```

---

### Task 2: Per-action sidecar guard (pure logic)

**Files:**

- Create: `src/Models/Decrees/DecreeWritePayloadGuard.php`
- Test: `phpunit_tests/Models/Decrees/DecreeWritePayloadGuardTest.php`

**Interfaces:**

- Consumes: nothing (pure).
- Produces: `DecreeWritePayloadGuard::assertSidecars(\stdClass $payload, string $baseLocale, bool $isCreate): void` — throws
  `\LiturgicalCalendar\Api\Http\Exception\ValidationException` on matrix violations. Used by Tasks 4 and 5.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Decrees;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Decrees\DecreeWritePayloadGuard;
use PHPUnit\Framework\TestCase;

final class DecreeWritePayloadGuardTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private static function payload(string $action, ?string $property = null, array $overrides = []): \stdClass
    {
        $p                   = new \stdClass();
        $p->metadata         = new \stdClass();
        $p->metadata->action = $action;
        if ($property !== null) {
            $p->metadata->property = $property;
        }
        foreach ($overrides as $k => $v) {
            $p->{$k} = $v;
        }
        return $p;
    }

    public function testCreateNewOnPutRequiresI18nAndReadings(): void
    {
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars(self::payload('createNew'), 'en', true);
    }

    public function testCreateNewOnPutWithSidecarsIncludingAcceptLocalePasses(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['en' => 'Saint Test'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
        $this->addToAssertionCount(1);
    }

    public function testI18nMissingAcceptLanguageBaseLocaleFails(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['it' => 'San Test'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    public function testSetPropertyGradeRejectsI18n(): void
    {
        $p = self::payload('setProperty', 'grade', ['i18n' => (object) ['en' => 'X']]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
    }

    public function testSetPropertyGradeWithoutSidecarsPasses(): void
    {
        DecreeWritePayloadGuard::assertSidecars(self::payload('setProperty', 'grade'), 'en', false);
        $this->addToAssertionCount(1);
    }

    public function testReadingsRejectedOnPutForMakeDoctor(): void
    {
        $p = self::payload('makeDoctor', null, [
            'i18n'     => (object) ['en' => 'Saint Test, Doctor'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    public function testReadingsOptionalOnPatchForMakeDoctor(): void
    {
        $p = self::payload('makeDoctor', null, [
            'i18n'     => (object) ['en' => 'Saint Test, Doctor'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
        $this->addToAssertionCount(1);
    }

    public function testSetPropertyNameRequiresI18nOnPatchToo(): void
    {
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars(self::payload('setProperty', 'name'), 'en', false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Models/Decrees/DecreeWritePayloadGuardTest.php`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement the guard**

```php
<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;

/**
 * Enforces the per-action sidecar matrix for decree write payloads
 * (spec: docs/superpowers/specs/2026-07-11-decrees-write-paths-design.md).
 *
 * | action              | i18n                | readings                              |
 * |---------------------|---------------------|---------------------------------------|
 * | createNew           | required            | required on PUT, optional on PATCH    |
 * | makeDoctor          | required            | rejected on PUT, optional on PATCH    |
 * | setProperty (name)  | required            | rejected on PUT, optional on PATCH    |
 * | setProperty (grade) | rejected            | rejected on PUT, optional on PATCH    |
 */
final class DecreeWritePayloadGuard
{
    /**
     * @throws ValidationException when the payload violates the sidecar matrix
     */
    public static function assertSidecars(\stdClass $payload, string $baseLocale, bool $isCreate): void
    {
        $action   = $payload->metadata->action ?? null;
        $property = $payload->metadata->property ?? null;
        $hasI18n  = property_exists($payload, 'i18n') && $payload->i18n instanceof \stdClass;
        $hasRead  = property_exists($payload, 'readings') && $payload->readings instanceof \stdClass;

        $nameBearing = in_array($action, ['createNew', 'makeDoctor'], true)
            || ( $action === 'setProperty' && $property === 'name' );

        if ($nameBearing) {
            if (!$hasI18n || count(get_object_vars($payload->i18n)) === 0) {
                throw new ValidationException(
                    "Decrees with metadata.action `{$action}`" . ( $property !== null ? " (property `{$property}`)" : '' )
                    . ' require an `i18n` object with at least one entry'
                );
            }
            if (!property_exists($payload->i18n, $baseLocale)) {
                throw new ValidationException(
                    "The `i18n` object must contain an entry for the Accept-Language base locale `{$baseLocale}`"
                );
            }
        } elseif ($hasI18n) {
            throw new ValidationException(
                'Decrees with metadata.action `setProperty` and property `grade` do not affect the event name: the `i18n` object is not allowed'
            );
        }

        if ($isCreate) {
            if ($action === 'createNew' && !$hasRead) {
                throw new ValidationException(
                    'Decrees with metadata.action `createNew` require a `readings` object when creating: a new liturgical event must define its lectionary readings'
                );
            }
            if ($action !== 'createNew' && $hasRead) {
                throw new ValidationException(
                    "Decrees with metadata.action `{$action}` do not accept a `readings` object on creation; readings may only be corrected via PATCH"
                );
            }
        }
        // On PATCH (isCreate === false) readings are optional for every action.
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Decrees/DecreeWritePayloadGuardTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Static analysis + commit**

```bash
composer analyse
git add src/Models/Decrees/DecreeWritePayloadGuard.php phpunit_tests/Models/Decrees/DecreeWritePayloadGuardTest.php
git commit -m "feat: per-action sidecar guard for decree write payloads"
```

---

### Task 3: Router + middleware relation map

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php:265-277` (`forGeneralRomanCalendar`)
- Modify: `src/Router.php:234-267` (decrees case, allowed methods) and `src/Router.php:713-718` (FGA wiring)
- Test: `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php` (add cases)

**Interfaces:**

- Produces: `OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar(OpenFgaClient $client, string $objectId, ?array $relationMap = null): self`. Existing callers (`temporale`,
  line ~712) pass no map → default behavior unchanged.
- Produces route shape consumed by Tasks 4-6: writes only reach `DecreesHandler` with exactly one path param.

- [ ] **Step 1: Write the failing middleware test**

Add to `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`, following the existing test style in that file (mocked `OpenFgaClient` asserting the relation passed to
`check()`):

```php
public function testForGeneralRomanCalendarAcceptsCustomRelationMap(): void
{
    $client = $this->createMock(OpenFgaClient::class);
    $client->expects($this->once())
        ->method('check')
        ->with('user:someone', 'editor', 'general_roman_calendar:decrees')
        ->willReturn(true);

    $middleware = OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar(
        $client,
        'decrees',
        ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
    );

    $request = $this->requestWithOidcUser('PUT', ['sub' => 'someone', 'roles' => []]);
    $middleware->process($request, $this->passthroughHandler());
}
```

Adapt the two helper calls (`requestWithOidcUser`, `passthroughHandler`) to the helper names actually present in that test file — read it first; if it builds requests inline,
inline the same construction here.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`
Expected: FAIL — `forGeneralRomanCalendar()` takes 2 arguments / relation checked is `admin` not `editor`.

- [ ] **Step 3: Extend the factory**

```php
    /**
     * Create middleware for a General Roman Calendar sub-resource with a fixed object id
     * (e.g. "temporale" or "decrees").
     *
     * @param OpenFgaClient              $client      The OpenFGA client
     * @param string                     $objectId    Fixed object id (e.g. "temporale")
     * @param array<string,string>|null  $relationMap Optional method→relation override
     *                                                (default: PUT/DELETE→admin, PATCH→editor)
     * @return self Configured middleware
     */
    public static function forGeneralRomanCalendar(OpenFgaClient $client, string $objectId, ?array $relationMap = null): self
    {
        return new self($client, 'general_roman_calendar', 'calendar_id', $objectId, null, $relationMap);
    }
```

Check the constructor's parameter order first (`$client, $objectType, $idAttribute, $fixedObjectId, $objectResolver, $relationMap` — see the `forTestScopes` factory at line ~255
which already passes a map as the 6th argument) and match it exactly.

- [ ] **Step 4: Update the Router decrees case**

In `src/Router.php` (~line 236) change the allowed-methods branches so writes are per-item only:

```php
            case 'decrees':
                $decreesHandler = new DecreesHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $decreesHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $decreesHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $decreesHandler->setAllowedRequestMethods([]);
                }
```

(everything after — content types, accept headers, allowed origins — stays as is). Then at the FGA wiring (~line 713):

```php
        } elseif ($route === 'decrees') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar(
                    $fgaClient,
                    'decrees',
                    ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
                ));
            }
        }
```

- [ ] **Step 5: Run tests, analyse, commit**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php && composer analyse`
Expected: PASS / no errors.

```bash
git add src/Http/Middleware/OpenFgaAuthorizationMiddleware.php src/Router.php phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php
git commit -m "feat: per-item decrees write routes with editor-writes/admin-deletes relation map"
```

---

### Task 4: DecreesHandler PUT (create)

**Files:**

- Modify: `src/Handlers/DecreesHandler.php` (replace the `handlePutRequest` stub at lines 226-231; add private helpers used by Tasks 4-6)
- Test: `phpunit_tests/Handlers/DecreesHandlerWriteTest.php` (new; includes data-file snapshot/restore)

**Interfaces:**

- Consumes: `LitSchema::DECREE_WRITE` (Task 1), `DecreeWritePayloadGuard::assertSidecars()` (Task 2).
- Produces (private helpers reused by Tasks 5-6, keep these exact names):
  - `loadDecreesDatabase(): array` — decoded `\stdClass[]` from `JsonData::DECREES_FILE`
  - `saveDecreesDatabase(array $decrees): void` — encode + `LOCK_EX` write, throws `ServiceUnavailableException` on failure
  - `stripSidecars(\stdClass $payload): \stdClass` — clone without `i18n`/`readings`
  - `distributeI18n(string $eventKey, \stdClass $i18n): void` — update every existing `jsondata/sourcedata/decrees/i18n/*.json` (empty-string placeholder for locales not provided)
  - `distributeReadings(string $eventKey, \stdClass $readings): void` — same against `jsondata/sourcedata/decrees/lectionary/*.json`, only for locales provided (no placeholders:
    a readings placeholder would be an invalid readings object)
  - `auditLog(string $operation, string $decreeId): void` — Monolog audit channel, pattern copied from `RegionalDataHandler` (operation, decree_id, user sub from request
    attribute `oidc_user`, client IP)
- Produces: `PUT /decrees/{decree_id}` → 201 with `{"success": "...", "decree": {...}}`.

- [ ] **Step 1: Write the failing tests**

`phpunit_tests/Handlers/DecreesHandlerWriteTest.php`. The class snapshots the three data locations in `setUp()` and restores them in `tearDown()` so write tests are hermetic:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DecreesHandler::class)]
final class DecreesHandlerWriteTest extends AbstractHandlerTestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/decrees-backup-' . uniqid();
        mkdir($this->backupDir, 0755, true);
        $src = dirname(JsonData::DECREES_FILE->path());
        exec(sprintf('cp -r %s %s', escapeshellarg($src), escapeshellarg($this->backupDir)));
    }

    protected function tearDown(): void
    {
        $src = dirname(JsonData::DECREES_FILE->path());
        exec(sprintf('rm -rf %s && cp -r %s %s', escapeshellarg($src), escapeshellarg($this->backupDir . '/decrees'), escapeshellarg($src)));
        exec(sprintf('rm -rf %s', escapeshellarg($this->backupDir)));
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private static function createNewPayload(string $decreeId = 'StTest_Create'): array
    {
        return [
            'decree_id'        => $decreeId,
            'decree_date'      => '2025-01-01',
            'decree_protocol'  => 'Prot. N. 1/25',
            'description'      => 'Test decree creating a new liturgical event.',
            'liturgical_event' => [
                'event_key' => 'StTest',
                'day'       => 14,
                'month'     => 2,
                'color'     => ['white'],
                'grade'     => 2,
                'common'    => ['Pastors'],
                'type'      => 'fixed',
                'calendar'  => 'GENERAL ROMAN',
            ],
            'metadata'         => [
                'action'     => 'createNew',
                'since_year' => 2025,
                'url'        => 'https://www.vatican.va/test.html',
            ],
            'i18n'             => ['en' => 'Saint Test'],
            'readings'         => [
                'en' => [
                    'first_reading'      => 'Genesis 1:1',
                    'responsorial_psalm' => 'Psalm 1',
                    'gospel_acclamation' => 'John 1:1',
                    'gospel'             => 'John 1:1-14',
                ],
            ],
        ];
    }

    public function testPutCreatesDecreeAndDistributesSidecars(): void
    {
        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
        self::assertSame(201, $resp->getStatusCode());

        $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertContains('StTest_Create', array_column($db, 'decree_id'));

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Saint Test', $en['StTest']);

        $it = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'it'])), true);
        self::assertSame('', $it['StTest']); // placeholder for un-provided locale

        $lectEn = json_decode((string) file_get_contents(strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Genesis 1:1', $lectEn['StTest']['first_reading']);
    }

    public function testPutExistingDecreeIdConflicts(): void
    {
        $payload              = self::createNewPayload('StMaryMagdalene_Upgrade');
        $this->expectException(ConflictException::class);
        ( new DecreesHandler(['StMaryMagdalene_Upgrade']) )->handle(
            $this->requestFor('PUT', '/decrees/StMaryMagdalene_Upgrade', ['Accept-Language' => 'en'], $payload)
        );
    }

    public function testPutBodyIdMismatchIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new DecreesHandler(['SomethingElse_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/SomethingElse_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
    }

    public function testPutWithoutReadingsForCreateNewIsRejected(): void
    {
        $payload = self::createNewPayload();
        unset($payload['readings']);
        $this->expectException(ValidationException::class);
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $payload)
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php`
Expected: first test FAILS with 405 status; others fail for missing exceptions.

- [ ] **Step 3: Implement handlePutRequest + helpers**

Replace the stub in `src/Handlers/DecreesHandler.php`:

```php
    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        $decreeId = $this->requireSinglePathParam();
        $payload  = $this->requireValidatedPayload($decreeId, isCreate: true);

        $decrees = $this->loadDecreesDatabase();
        if (null !== array_find($decrees, fn ($d) => $d->decree_id === $decreeId)) {
            throw new ConflictException("Decree `{$decreeId}` already exists. Use PATCH to update it.");
        }

        $decrees[] = $this->stripSidecars($payload);
        $this->saveDecreesDatabase($decrees);
        $this->applySidecars($payload);
        $this->auditLog('CREATE', $decreeId);

        $result          = new \stdClass();
        $result->success = "Decree `{$decreeId}` created";
        $result->decree  = $this->stripSidecars($payload);
        return $this->encodeResponseBody($response, $result)
            ->withStatus(StatusCode::CREATED->value, StatusCode::CREATED->reason());
    }
```

with these private helpers (also used by Tasks 5-6):

```php
    private function requireSinglePathParam(): string
    {
        if (count($this->requestPathParams) !== 1) {
            throw new ValidationException('Write operations on the `/decrees` path require exactly one path parameter: /decrees/{decree_id}');
        }
        return $this->requestPathParams[0];
    }

    private function requireValidatedPayload(string $decreeId, bool $isCreate): \stdClass
    {
        $payload = $this->params->Payload;
        if (false === ( $payload instanceof \stdClass )) {
            throw new ValidationException('Expected a JSON (or YAML) object payload in the request body');
        }
        try {
            $schema = Schema::import(LitSchema::DECREE_WRITE->path());
            $schema->in($payload);
        } catch (\Swaggest\JsonSchema\Exception $e) {
            throw new ValidationException('Decree write payload failed schema validation: ' . $e->getMessage());
        }
        if ($payload->decree_id !== $decreeId) {
            throw new ValidationException("The `decree_id` in the request body (`{$payload->decree_id}`) must match the decree_id in the URL (`{$decreeId}`)");
        }
        $baseLocale = explode('_', $this->params->Locale)[0];
        DecreeWritePayloadGuard::assertSidecars($payload, $baseLocale, $isCreate);
        // DTO invariants (fixed vs mobile, setProperty->property, etc.)
        DecreeItemCollection::fromObject([$this->stripSidecarsAsArray($payload)]);
        return $payload;
    }

    private function applySidecars(\stdClass $payload): void
    {
        $eventKey = $payload->liturgical_event->event_key;
        if (property_exists($payload, 'i18n') && $payload->i18n instanceof \stdClass) {
            $this->distributeI18n($eventKey, $payload->i18n);
        }
        if (property_exists($payload, 'readings') && $payload->readings instanceof \stdClass) {
            $this->distributeReadings($eventKey, $payload->readings);
        }
    }
```

Implementation notes for the remaining helpers (write them in this task, exact behavior):

- `loadDecreesDatabase()`: `Utilities::jsonFileToObjectArray(JsonData::DECREES_FILE->path())`.
- `saveDecreesDatabase(array $decrees)`: `file_put_contents($path, JsonFormatter::encode(array_values($decrees)) . PHP_EOL, LOCK_EX)`; `false` result → `throw new
  ServiceUnavailableException('Could not write decrees database')`.
- `stripSidecars(\stdClass $payload): \stdClass`: `$clone = clone $payload; unset($clone->i18n, $clone->readings); return $clone;`
- `stripSidecarsAsArray(\stdClass $payload): array`: `json_decode(json_encode($this->stripSidecars($payload)), true)` — `DecreeItemCollection::fromObject()` expects the same
  associative shape it reads from disk; check its signature and adapt (it may accept objects directly, in which case pass `[$this->stripSidecars($payload)]` and delete this
  helper).
- `distributeI18n(string $eventKey, \stdClass $i18n)`: `glob(JsonData::DECREES_I18N_FOLDER->path() . '/*.json')`; for each file, decode to assoc array, set `$arr[$eventKey] =
  $i18n->{$locale} ?? ''`(locale = basename without extension), `ksort($arr)`, write back with `JsonFormatter::encode` + `LOCK_EX`.
- `distributeReadings(string $eventKey, \stdClass $readings)`: same loop over `JsonData::LECTIONARY_DECREES_FOLDER`, but ONLY for locales present in `$readings` (no placeholders).
- `auditLog(string $operation, string $decreeId)`: `LoggerFactory::create('audit')`-style — copy the exact audit pattern from `RegionalDataHandler` (~lines 384-395), logging
  `['operation' => $operation, 'resource' => 'decrees', 'decree_id' => $decreeId, 'user' => ..., 'ip' => ...]`. The user sub comes from
  `$request->getAttribute('oidc_user')['sub'] ?? 'anonymous'` — store the request in a property in `handle()` (`$this->request = $request;`) so helpers can read it, matching how
  other handlers do it (check `TemporaleHandler` for the exact idiom).

Add the imports the new code needs (`ConflictException`, `ServiceUnavailableException`, `LitSchema`, `Schema`, `JsonFormatter`, `DecreeWritePayloadGuard`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php && vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerTest.php`
Expected: write tests PASS; in the pre-existing `DecreesHandlerTest`, `testPutIsNotImplemented` now FAILS — replace it with:

```php
    public function testPutOnCollectionRootIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new DecreesHandler() )->handle(
            $this->requestFor('PUT', '/decrees', ['Accept-Language' => 'en'], ['decree_id' => 'fake'])
        );
    }
```

(the handler-level guard `requireSinglePathParam()` fires even though the Router also blocks this).

- [ ] **Step 5: Full quick suite + commit**

```bash
composer analyse && composer test:quick
git add src/Handlers/DecreesHandler.php phpunit_tests/Handlers/DecreesHandlerWriteTest.php phpunit_tests/Handlers/DecreesHandlerTest.php
git commit -m "feat: implement PUT /decrees/{decree_id} with sidecar distribution and audit logging"
```

---

### Task 5: DecreesHandler PATCH (update)

**Files:**

- Modify: `src/Handlers/DecreesHandler.php` (replace `handlePatchRequest` stub)
- Test: `phpunit_tests/Handlers/DecreesHandlerWriteTest.php` (add cases)

**Interfaces:**

- Consumes: all Task 4 helpers unchanged.
- Produces: `PATCH /decrees/{decree_id}` → 200 `{"success": "...", "decree": {...}}`; 404 for unknown id.

- [ ] **Step 1: Write the failing tests** (append to `DecreesHandlerWriteTest`)

```php
    public function testPatchUpdatesExistingDecree(): void
    {
        // First create, then patch the description and the i18n entry.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
        $patch                = self::createNewPayload();
        $patch['description'] = 'Amended description.';
        $patch['i18n']        = ['en' => 'Saint Test, Amended'];
        unset($patch['readings']); // optional on PATCH

        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $patch)
        );
        self::assertSame(200, $resp->getStatusCode());

        $db    = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        $entry = array_values(array_filter($db, fn ($d) => $d['decree_id'] === 'StTest_Create'))[0];
        self::assertSame('Amended description.', $entry['description']);
        self::assertArrayNotHasKey('i18n', $entry); // sidecars never stored in the database

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Saint Test, Amended', $en['StTest']);
    }

    public function testPatchUnknownDecreeIs404(): void
    {
        $this->expectException(NotFoundException::class);
        ( new DecreesHandler(['Nonexistent_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/Nonexistent_Create', ['Accept-Language' => 'en'], self::createNewPayload('Nonexistent_Create'))
        );
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php`
Expected: PATCH tests fail with 405 status.

- [ ] **Step 3: Implement**

```php
    private function handlePatchRequest(ResponseInterface $response): ResponseInterface
    {
        $decreeId = $this->requireSinglePathParam();
        $payload  = $this->requireValidatedPayload($decreeId, isCreate: false);

        $decrees = $this->loadDecreesDatabase();
        $idx     = null;
        foreach ($decrees as $i => $decree) {
            if ($decree->decree_id === $decreeId) {
                $idx = $i;
                break;
            }
        }
        if (null === $idx) {
            throw new NotFoundException("No decree found with decree_id `{$decreeId}`; use PUT to create it.");
        }

        $decrees[$idx] = $this->stripSidecars($payload);
        $this->saveDecreesDatabase($decrees);
        $this->applySidecars($payload);
        $this->auditLog('UPDATE', $decreeId);

        $result          = new \stdClass();
        $result->success = "Decree `{$decreeId}` updated";
        $result->decree  = $this->stripSidecars($payload);
        return $this->encodeResponseBody($response, $result);
    }
```

- [ ] **Step 4: Run tests to verify they pass, commit**

```bash
vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php && composer analyse
git add src/Handlers/DecreesHandler.php phpunit_tests/Handlers/DecreesHandlerWriteTest.php
git commit -m "feat: implement PATCH /decrees/{decree_id}"
```

---

### Task 6: DecreesHandler DELETE with shared-key garbage collection

**Files:**

- Modify: `src/Handlers/DecreesHandler.php` (replace `handleDeleteRequest` stub)
- Test: `phpunit_tests/Handlers/DecreesHandlerWriteTest.php` (add cases)

**Interfaces:**

- Consumes: Task 4 helpers.
- Produces: `DELETE /decrees/{decree_id}` → 200 with success body; removes `event_key` from `i18n/*.json` and `lectionary/*.json` only when no surviving decree references it.

- [ ] **Step 1: Write the failing tests**

```php
    public function testDeleteRemovesDecreeAndOrphanedSidecarKeys(): void
    {
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('DELETE', '/decrees/StTest_Create', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());

        $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertNotContains('StTest_Create', array_column($db, 'decree_id'));

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertArrayNotHasKey('StTest', $en);

        $lectEn = json_decode((string) file_get_contents(strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertArrayNotHasKey('StTest', $lectEn);
    }

    public function testDeletePreservesSidecarKeysSharedWithSurvivingDecrees(): void
    {
        // StFaustinaKowalska has both a _Create and a _Doctor decree in the shipped
        // database sharing event_key StFaustinaKowalska. Deleting one must keep the
        // i18n key for the other. Verify the fixture assumption first.
        $db     = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        $shared = array_values(array_filter($db, fn ($d) => $d['liturgical_event']['event_key'] === 'StFaustinaKowalska'));
        self::assertGreaterThan(1, count($shared), 'fixture assumption: StFaustinaKowalska appears in more than one decree');

        ( new DecreesHandler([$shared[0]['decree_id']]) )->handle(
            $this->requestFor('DELETE', '/decrees/' . $shared[0]['decree_id'], ['Accept-Language' => 'en'])
        );

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertArrayHasKey('StFaustinaKowalska', $en);
    }

    public function testDeleteUnknownDecreeIs404(): void
    {
        $this->expectException(NotFoundException::class);
        ( new DecreesHandler(['Nonexistent_Create']) )->handle(
            $this->requestFor('DELETE', '/decrees/Nonexistent_Create', ['Accept-Language' => 'en'])
        );
    }
```

If the fixture assumption in the second test does not hold (check `decrees.json`: are there two decrees sharing an `event_key`? `StFaustinaKowalska_Create` +
`StFaustinaKowalska_Doctor` are expected), create the second decree in the test via PUT (a `makeDoctor` payload with the same `event_key`) instead of relying on shipped data.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php`
Expected: DELETE tests fail with 405.

- [ ] **Step 3: Implement**

```php
    private function handleDeleteRequest(ResponseInterface $response): ResponseInterface
    {
        $decreeId = $this->requireSinglePathParam();

        $decrees = $this->loadDecreesDatabase();
        $target  = array_find($decrees, fn ($d) => $d->decree_id === $decreeId);
        if (null === $target) {
            throw new NotFoundException("No decree found with decree_id `{$decreeId}`");
        }

        $surviving = array_values(array_filter($decrees, fn ($d) => $d->decree_id !== $decreeId));
        $this->saveDecreesDatabase($surviving);

        $eventKey       = $target->liturgical_event->event_key;
        $stillReferenced = null !== array_find($surviving, fn ($d) => $d->liturgical_event->event_key === $eventKey);
        if (false === $stillReferenced) {
            $this->removeKeyFromLocaleFiles($eventKey, JsonData::DECREES_I18N_FOLDER->path());
            $this->removeKeyFromLocaleFiles($eventKey, JsonData::LECTIONARY_DECREES_FOLDER->path());
        }
        $this->auditLog('DELETE', $decreeId);

        $result          = new \stdClass();
        $result->success = "Decree `{$decreeId}` deleted";
        return $this->encodeResponseBody($response, $result);
    }

    private function removeKeyFromLocaleFiles(string $eventKey, string $folder): void
    {
        $files = glob(rtrim($folder, '/') . '/*.json');
        if (false === $files) {
            return;
        }
        foreach ($files as $file) {
            $arr = Utilities::jsonFileToArray($file);
            if (array_key_exists($eventKey, $arr)) {
                unset($arr[$eventKey]);
                file_put_contents($file, JsonFormatter::encode($arr) . PHP_EOL, LOCK_EX);
            }
        }
    }
```

- [ ] **Step 4: Run tests, full suite, commit**

```bash
vendor/bin/phpunit phpunit_tests/Handlers/ && composer analyse && composer test:quick
git add src/Handlers/DecreesHandler.php phpunit_tests/Handlers/DecreesHandlerWriteTest.php
git commit -m "feat: implement DELETE /decrees/{decree_id} with shared-key garbage collection"
```

---

### Task 7: GET readings enrichment

**Files:**

- Modify: `src/Handlers/DecreesHandler.php:176-224` (`handleGetRequest`)
- Test: `phpunit_tests/Handlers/DecreesHandlerTest.php` (add case)

**Interfaces:**

- Produces: every decree in GET responses carries `liturgical_event->readings` when the decrees lectionary has an entry for its `event_key` in the request locale (base-locale
  fallback; property absent when no readings exist).

- [ ] **Step 1: Write the failing test** (append to `DecreesHandlerTest`)

```php
    public function testGetDecreeIncludesReadingsFromDecreesLectionary(): void
    {
        $resp = ( new DecreesHandler(['MaryMotherChurch_Create']) )->handle(
            $this->requestFor('GET', '/decrees/MaryMotherChurch_Create', ['Accept-Language' => 'en'])
        );
        $body = $this->decodeJsonBody($resp);
        self::assertArrayHasKey('readings', $body['liturgical_event']);
        self::assertNotEmpty($body['liturgical_event']['readings']['first_reading']);
    }
```

If the shipped decree id differs (check with `grep MaryMotherChurch jsondata/sourcedata/decrees/decrees.json` — the id follows pattern `MaryMotherChurch_Create`), use the actual
id.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerTest.php`
Expected: FAIL — no `readings` key.

- [ ] **Step 3: Implement**

In `handleGetRequest()` after `DecreeItemCollection::setNames($decrees, $names);` (line ~201), load the decrees lectionary for the request locale (base-locale fallback, mirroring
`TemporaleHandler::getLectionaryLocale()` at line ~202 of that file) and attach readings:

```php
        $lectionaryFile = strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => $this->params->Locale]);
        if (!file_exists($lectionaryFile)) {
            $baseLocale     = explode('_', $this->params->Locale)[0];
            $lectionaryFile = strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => $baseLocale]);
        }
        if (file_exists($lectionaryFile)) {
            $readings = Utilities::jsonFileToObject($lectionaryFile);
            foreach ($decrees as $decree) {
                $eventKey = $decree->liturgical_event->event_key;
                if (property_exists($readings, $eventKey)) {
                    $decree->liturgical_event->readings = $readings->{$eventKey};
                }
            }
        }
```

Place this BEFORE `DecreeItemCollection::fromObject($decrees)` only if the DTO tolerates the extra
property; if `DecreeItemCollection`/`LiturgicalEvent` DTOs reject unknown properties, instead attach
readings to the serialized output after `fromObject` (check how `liturgical_event` is serialized —
`DecreeEventData` — and add an optional `readings` property to that DTO with a
`?\stdClass $readings = null` constructor default, serialized only when non-null).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerTest.php && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/DecreesHandler.php src/Models/Decrees/ phpunit_tests/Handlers/DecreesHandlerTest.php
git commit -m "feat: enrich GET /decrees responses with lectionary readings"
```

---

### Task 8: Route-level integration tests

**Files:**

- Create: `phpunit_tests/Routes/ReadWrite/DecreesTest.php`

**Interfaces:**

- Consumes: the running dev API on `localhost:8000` (`ApiTestCase` auto-skips when unreachable); auth helpers from `TemporaleTest` (JWT login flow — copy its exact login/token
  pattern).

- [ ] **Step 1: Write the tests**

Model directly on `phpunit_tests/Routes/ReadWrite/TemporaleTest.php` (read it first; reuse its
authentication helper verbatim). Cases:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;

final class DecreesTest extends ApiTestCase
{
    public function testPutWithoutAuthReturns401(): void
    {
        $response = self::$http->request('PUT', '/decrees/StTest_Create', [
            'json'        => ['decree_id' => 'StTest_Create'],
            'http_errors' => false,
        ]);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDeleteWithoutAuthReturns401(): void
    {
        $response = self::$http->request('DELETE', '/decrees/StTest_Create', ['http_errors' => false]);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAuthenticatedPutOnExistingDecreeReturns409(): void
    {
        // build full valid payload for an id that ships with the API (StMaryMagdalene_Upgrade)
        // and expect 409; copy the payload builder from DecreesHandlerWriteTest::createNewPayload().
    }

    public function testAuthenticatedPutWithInvalidPayloadReturns400(): void
    {
        // authenticated PUT /decrees/StTest_Create with body {"decree_id": "StTest_Create"} only
        // (fails schema validation) -> 400
    }

    public function testAuthenticatedDeleteUnknownDecreeReturns404(): void
    {
        // authenticated DELETE /decrees/Nonexistent_Create -> 404
    }

    public function testFullLifecycleCreatePatchDelete(): void
    {
        // 1. PUT valid createNew payload for StZzTest_Create -> 201
        // 2. PATCH same id with amended description -> 200
        // 3. DELETE same id -> 200
        // wrap 2-3 in try/finally { DELETE } so the data files return to their
        // original state even when an assertion fails mid-flight.
    }
}
```

Flesh out the commented bodies with the payload array from Task 4's `createNewPayload()` and the
authentication idiom from `TemporaleTest` (do not invent a new one). Note: if the API on
`localhost:8000` runs from the Docker image rather than this worktree (see project memory), these
tests are skipped or run against old code locally — they will exercise the new code in CI once the
branch is pushed; do not chase local 405s from a stale server.

- [ ] **Step 2: Run**

Run: `vendor/bin/phpunit phpunit_tests/Routes/ReadWrite/DecreesTest.php`
Expected: PASS against a server running this branch; SKIPPED when no server is reachable.

- [ ] **Step 3: Commit**

```bash
git add phpunit_tests/Routes/ReadWrite/DecreesTest.php
git commit -m "test: route-level integration coverage for decrees write paths"
```

---

### Task 9: OpenAPI documentation

**Files:**

- Modify: `jsondata/schemas/openapi.json` (extend `/decrees/{decree_id}` path item; add `DecreeWritePayload` component)

**Interfaces:**

- Consumes: the write-payload shape from Task 1.

- [ ] **Step 1: Extend the path item**

In `jsondata/schemas/openapi.json` find the existing `/decrees/{decree_id}` (or `/decrees/{decree_ID}` — match the existing parameter name) path item and add `put`, `patch`,
`delete` operations. Follow the structure of the existing protected operations under the `/data` paths for `security`, 401/403 responses, and requestBody. Summary lines:

- `put`: "Create a new decree (requires editor permission on general_roman_calendar:decrees)"
- `patch`: "Update an existing decree (requires editor permission on general_roman_calendar:decrees)"
- `delete`: "Delete a decree (requires admin permission on general_roman_calendar:decrees)"

Add component `DecreeWritePayload` mirroring `LitCalDecreeWritePayload.json` (openapi.json is self-contained — inline the sidecar property definitions; reference the existing
decree component for the base properties if one exists, otherwise inline). Responses: 201 (put) / 200 (patch, delete), 400, 401, 403, 404 (patch/delete), 409 (put).

- [ ] **Step 2: Validate**

Run: `composer lint:openapi`
Expected: "Your API description is valid".

- [ ] **Step 3: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs: OpenAPI coverage for decrees write paths"
```

---

### Task 10: Final verification

- [ ] **Step 1: Full suite**

Run: `composer test:quick && composer analyse && composer lint && composer lint:openapi`
Expected: all green.

- [ ] **Step 2: Push and open PR**

```bash
git push -u origin feature/decrees-write-paths
gh pr create --base development --title "feat: PUT/PATCH/DELETE /decrees/{decree_id} with FGA gating" \
  --body "Implements docs/superpowers/specs/2026-07-11-decrees-write-paths-design.md. Refs #706."
```

Expected: PR opened against `development`; CI (which runs against the branch code) exercises the Route tests.
