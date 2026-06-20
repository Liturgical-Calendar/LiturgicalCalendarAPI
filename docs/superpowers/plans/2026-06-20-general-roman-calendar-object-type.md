# General Roman Calendar Object Type — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended)
> or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax
> for tracking.

**Goal:** Add a `general_roman_calendar` OpenFGA object type (five enumerated object IDs) so Calendar
Editors can be granted edit rights on the Temporale, the Editio Typica Sanctorale editions, and the
Decrees — and enforce it on the `temporale`, `missals`, and `decrees` write routes.

**Architecture:** The API (`LiturgicalCalendarAPI`) is authoritative: it owns the FGA model, the
object-type/ID validation, and the per-route enforcement. The frontend (`LiturgicalCalendarFrontend`)
mirrors the FGA model byte-for-byte and exposes the new type in its grant and access-request UIs.
Phase A (API) lands first; Phase B (frontend) lands after.

**Tech Stack:** PHP 8.4 (PSR-7/15), OpenFGA, PHPUnit (`phpunit_tests/`), vanilla ES6 JS + Bootstrap (frontend), gettext i18n.

**Spec:** `docs/superpowers/specs/2026-06-20-general-roman-calendar-object-type-design.md`

## Global Constraints

- New object type string: `general_roman_calendar` (exact).
- The five valid object IDs (exact): `temporale`, `EDITIO_TYPICA_1970`, `EDITIO_TYPICA_2002`, `EDITIO_TYPICA_2008`, `decrees`.
- Relations unchanged: `admin`, `viewer`, `editor`, `deleter`. Write→relation map: `PUT`/`PATCH`→`editor`, `DELETE`→`deleter`.
- Roles allowed to hold the new type: `calendar_editor` and `developer` only.
- The two `scripts/openfga-model.json` files (API + frontend) MUST remain byte-identical.
- Admin role bypasses all FGA checks (existing behavior — do not change).
- Only `PUT`/`PATCH`/`DELETE` are gated (matches existing `data`/`tests` behavior); `POST` is out of scope.
- PHP: PSR-12, short arrays, 4-space indent. Run `composer lint:fix` before committing; never use `--no-verify`.
- API branch: `feat/general-roman-calendar-object-type` (off `development`, already created). Frontend branch: create `feat/general-roman-calendar-object-type` off `development`.

---

## Phase A — API (`LiturgicalCalendarAPI`)

Work on branch `feat/general-roman-calendar-object-type`. All paths in Phase A are relative to the
`LiturgicalCalendarAPI` repo root. Run a single test with `vendor/bin/phpunit phpunit_tests/Path/To/Test.php`.

### Task A1: Add `general_roman_calendar` to the FGA model

**Files:**

- Modify: `scripts/openfga-model.json`
- Test: `phpunit_tests/Services/OpenFgaModelTest.php` (create)

**Interfaces:**

- Produces: a `general_roman_calendar` type definition with relations `admin/viewer/editor/deleter`, each `directly_related_user_types: [{ "type": "user" }]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Services;

use PHPUnit\Framework\TestCase;

final class OpenFgaModelTest extends TestCase
{
    /** @return array<string, mixed> */
    private function loadModel(): array
    {
        $json = file_get_contents(__DIR__ . '/../../scripts/openfga-model.json');
        self::assertIsString($json);
        $model = json_decode($json, true);
        self::assertIsArray($model);
        return $model;
    }

    public function testGeneralRomanCalendarTypeExistsWithStandardRelations(): void
    {
        $model = $this->loadModel();
        $types = [];
        foreach ($model['type_definitions'] as $def) {
            $types[$def['type']] = $def;
        }

        self::assertArrayHasKey('general_roman_calendar', $types);
        $grc = $types['general_roman_calendar'];
        foreach (['admin', 'viewer', 'editor', 'deleter'] as $relation) {
            self::assertArrayHasKey($relation, $grc['relations']);
            self::assertSame(
                [['type' => 'user']],
                $grc['metadata']['relations'][$relation]['directly_related_user_types']
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/OpenFgaModelTest.php`
Expected: FAIL — `Failed asserting that an array has the key 'general_roman_calendar'`.

- [ ] **Step 3: Add the type to the model**

In `scripts/openfga-model.json`, add this object to the `type_definitions` array (after `test_definition`):

```json
    {
      "type": "general_roman_calendar",
      "relations": {
        "admin": { "this": {} },
        "viewer": { "this": {} },
        "editor": { "this": {} },
        "deleter": { "this": {} }
      },
      "metadata": {
        "relations": {
          "admin":   { "directly_related_user_types": [{ "type": "user" }] },
          "viewer":  { "directly_related_user_types": [{ "type": "user" }] },
          "editor":  { "directly_related_user_types": [{ "type": "user" }] },
          "deleter": { "directly_related_user_types": [{ "type": "user" }] }
        }
      }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/OpenFgaModelTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/openfga-model.json phpunit_tests/Services/OpenFgaModelTest.php
git commit -m "feat(fga): add general_roman_calendar object type to model"
```

---

### Task A2: Repository — object type, role map, and object-ID validation

**Files:**

- Modify: `src/Repositories/AccessRequestRepository.php:47` (VALID_OBJECT_TYPES), `:56` (ROLE_OBJECT_TYPES)
- Test: `phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php` (create)

**Interfaces:**

- Produces:
  - `AccessRequestRepository::VALID_OBJECT_TYPES` now contains `general_roman_calendar`.
  - `AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor']` and `['developer']` contain `general_roman_calendar`.
  - `AccessRequestRepository::GRC_OBJECT_IDS` — `array<int,string>` of the five valid IDs.
  - `AccessRequestRepository::isValidObjectIdForType(string $objectType, string $objectId): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use PHPUnit\Framework\TestCase;

final class AccessRequestRepositoryConstantsTest extends TestCase
{
    public function testGeneralRomanCalendarIsAValidObjectType(): void
    {
        self::assertContains('general_roman_calendar', AccessRequestRepository::VALID_OBJECT_TYPES);
    }

    public function testCalendarEditorAndDeveloperCanHoldGeneralRomanCalendar(): void
    {
        self::assertContains('general_roman_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor']);
        self::assertContains('general_roman_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['developer']);
        self::assertNotContains('general_roman_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor']);
    }

    public function testGrcObjectIdValidation(): void
    {
        foreach (['temporale', 'EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'decrees'] as $id) {
            self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', $id));
        }
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', 'EDITIO_TYPICA_1971'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', ''));
        // Other types keep free-form ids (non-empty)
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('national_calendar', 'IT'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('national_calendar', ''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`
Expected: FAIL — `general_roman_calendar` not found / `isValidObjectIdForType` undefined.

- [ ] **Step 3: Update constants and add the helper**

In `src/Repositories/AccessRequestRepository.php`, change line 47 to:

```php
    public const VALID_OBJECT_TYPES = ['national_calendar', 'diocesan_calendar', 'wider_region', 'test_definition', 'general_roman_calendar'];
```

Change the `ROLE_OBJECT_TYPES` constant (line ~56) to:

```php
    public const ROLE_OBJECT_TYPES = [
        'developer'       => ['national_calendar', 'diocesan_calendar', 'wider_region', 'test_definition', 'general_roman_calendar'],
        'calendar_editor' => ['national_calendar', 'diocesan_calendar', 'wider_region', 'general_roman_calendar'],
        'test_editor'     => ['test_definition'],
    ];
```

Immediately after the `VALID_RELATIONS` constant (line ~42) add:

```php
    /**
     * The fixed, enumerated set of object IDs valid for the general_roman_calendar type.
     *
     * @var array<int, string>
     */
    public const GRC_OBJECT_IDS = ['temporale', 'EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'decrees'];
```

Add this static method to the class (e.g. just below the constructor):

```php
    /**
     * Validate an object_id for a given object_type.
     *
     * general_roman_calendar uses a fixed enumerated id set; all other types accept any
     * non-empty id (the resource itself is validated downstream by the handler).
     */
    public static function isValidObjectIdForType(string $objectType, string $objectId): bool
    {
        if ($objectType === 'general_roman_calendar') {
            return in_array($objectId, self::GRC_OBJECT_IDS, true);
        }

        return $objectId !== '';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/AccessRequestRepository.php phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php
git commit -m "feat(fga): allow general_roman_calendar tuples in repository constants"
```

---

### Task A3: Middleware — fixed object IDs + GRC/missals factories

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`
- Test: `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php` (extend existing)

**Interfaces:**

- Consumes: `RomanMissal::isLatinMissal(string): bool` (`src/Enum/RomanMissal.php`).
- Produces:
  - constructor 4th param `?string $fixedObjectId = null`; when set, it is used as the object id instead of a request attribute.
  - `OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar(OpenFgaClient $client, string $objectId): self`
  - `OpenFgaAuthorizationMiddleware::forMissals(OpenFgaClient $client, string $missalId): self`

- [ ] **Step 1: Write the failing test**

Add to `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`. These verify the FGA object string
each factory checks. Use the existing test's helpers for building a request/handler and a fake/mock
`OpenFgaClient`; the assertions below capture the `check()` arguments.

```php
public function testForGeneralRomanCalendarChecksFixedObjectId(): void
{
    $client = $this->createMock(\LiturgicalCalendar\Api\Services\OpenFgaClient::class);
    $client->expects($this->once())
        ->method('check')
        ->with('user:abc', 'editor', 'general_roman_calendar:temporale')
        ->willReturn(true);

    $mw      = \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar($client, 'temporale');
    $request = $this->makeRequest('PUT', ['sub' => 'abc', 'roles' => ['calendar_editor']]);
    $mw->process($request, $this->passthroughHandler());
}

public function testForMissalsEditioTypicaChecksGeneralRomanCalendar(): void
{
    $client = $this->createMock(\LiturgicalCalendar\Api\Services\OpenFgaClient::class);
    $client->expects($this->once())
        ->method('check')
        ->with('user:abc', 'editor', 'general_roman_calendar:EDITIO_TYPICA_2002')
        ->willReturn(true);

    $mw      = \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forMissals($client, 'EDITIO_TYPICA_2002');
    $request = $this->makeRequest('PATCH', ['sub' => 'abc', 'roles' => ['calendar_editor']]);
    $mw->process($request, $this->passthroughHandler());
}

public function testForMissalsNationalChecksNationalCalendarByPrefix(): void
{
    $client = $this->createMock(\LiturgicalCalendar\Api\Services\OpenFgaClient::class);
    $client->expects($this->once())
        ->method('check')
        ->with('user:abc', 'editor', 'national_calendar:IT')
        ->willReturn(true);

    $mw      = \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forMissals($client, 'IT_1983');
    $request = $this->makeRequest('PUT', ['sub' => 'abc', 'roles' => ['calendar_editor']]);
    $mw->process($request, $this->passthroughHandler());
}
```

> Note: `makeRequest()` and `passthroughHandler()` are helper names — reuse the equivalents already
> present in this test file (it already builds requests with the `oidc_user` attribute and a
> pass-through handler). If they have different names, call the existing ones.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`
Expected: FAIL — `forGeneralRomanCalendar`/`forMissals` undefined.

- [ ] **Step 3: Implement the middleware changes**

Add the import near the top of `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`:

```php
use LiturgicalCalendar\Api\Enum\RomanMissal;
```

Add a property alongside the other private properties:

```php
    /**
     * When non-null, this fixed object id is used instead of a request attribute.
     */
    private ?string $fixedObjectId;
```

Replace the constructor with:

```php
    public function __construct(
        OpenFgaClient $client,
        string $objectType,
        string $resourceIdAttribute = 'calendar_id',
        ?string $fixedObjectId = null
    ) {
        $this->client              = $client;
        $this->objectType          = $objectType;
        $this->resourceIdAttribute = $resourceIdAttribute;
        $this->fixedObjectId       = $fixedObjectId;
    }
```

Replace `extractResourceId()` with:

```php
    private function extractResourceId(ServerRequestInterface $request): ?string
    {
        if ($this->fixedObjectId !== null) {
            return $this->fixedObjectId;
        }

        $value = $request->getAttribute($this->resourceIdAttribute);
        if ($value !== null && ( is_string($value) || is_int($value) )) {
            return (string) $value;
        }

        return null;
    }
```

Add the two factories next to `forTestDefinition()`:

```php
    /**
     * Create middleware for a General Roman Calendar sub-resource with a fixed object id
     * (e.g. "temporale" or "decrees").
     */
    public static function forGeneralRomanCalendar(OpenFgaClient $client, string $objectId): self
    {
        return new self($client, 'general_roman_calendar', 'calendar_id', $objectId);
    }

    /**
     * Create middleware for a missal write.
     *
     * Editio Typica (Latin) missals are General Roman Calendar Sanctorale sub-resources;
     * national/regional missals follow the owning national calendar's grants (id prefix).
     */
    public static function forMissals(OpenFgaClient $client, string $missalId): self
    {
        if (RomanMissal::isLatinMissal($missalId)) {
            return new self($client, 'general_roman_calendar', 'calendar_id', $missalId);
        }

        $nation = explode('_', $missalId)[0];
        return new self($client, 'national_calendar', 'calendar_id', $nation);
    }
```

Update the class docblock object-type list to mention `temporale`/`missals`/`decrees` → `general_roman_calendar` / `national_calendar` (documentation only).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`
Expected: PASS (all, including pre-existing).

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware/OpenFgaAuthorizationMiddleware.php phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php
git commit -m "feat(fga): GRC + missals authorization middleware factories"
```

---

### Task A4: Router — guard temporale, missals, decrees

**Files:**

- Modify: `src/Router.php:631` (protected-route list), `src/Router.php:676-705` (`configureAuthorizationPipeline`)
- Test: `phpunit_tests/Http/RouterAuthorizationTest.php` (create) — or extend an existing Router test if present.

**Interfaces:**

- Consumes: `OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar`, `::forMissals` (Task A3); `AuthorizationMiddleware::forCalendarEditor()` (existing).

- [ ] **Step 1: Write the failing test**

Verify the protected-route gate now includes `missals` and `decrees`. The simplest robust assertion is
behavioral: a `PATCH /decrees/...` with no auth token returns 401 (auth middleware now applied) instead
of reaching the handler. If the repo already has a Router test harness, add a case there; otherwise create:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Http;

use PHPUnit\Framework\TestCase;

final class RouterAuthorizationTest extends TestCase
{
    /**
     * The set of routes that require auth for write methods must include the
     * General Roman Calendar write routes.
     */
    public function testProtectedWriteRoutesIncludeGrcRoutes(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Router.php');
        self::assertIsString($src);
        // Guard list literal must contain all five route names.
        self::assertMatchesRegularExpression(
            "/in_array\\(\\s*\\\$route,\\s*\\['data',\\s*'tests',\\s*'temporale',\\s*'missals',\\s*'decrees'\\]/",
            $src
        );
        // temporale must no longer be admin-only.
        self::assertStringNotContainsString("// Temporale requires admin role", $src);
    }
}
```

> This is a guard-rail (static) test because Router wiring is hard to exercise in isolation. The real
> authorization behavior is covered by Task A3's middleware tests; an end-to-end check is added in
> Phase A verification (Task A7).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Http/RouterAuthorizationTest.php`
Expected: FAIL — guard list does not yet contain `missals`/`decrees`.

- [ ] **Step 3: Update the Router**

Change the protected-route condition (line ~631) to:

```php
            in_array($route, ['data', 'tests', 'temporale', 'missals', 'decrees'], true)
```

In `configureAuthorizationPipeline()`, replace the `temporale` branch (lines ~702-705) with the following three branches:

```php
        } elseif ($route === 'temporale') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar($fgaClient, 'temporale'));
            }
        } elseif ($route === 'decrees') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar($fgaClient, 'decrees'));
            }
        } elseif ($route === 'missals') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null && count($requestPathParts) >= 1) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forMissals($fgaClient, $requestPathParts[0]));
            }
        }
```

- [ ] **Step 4: Run test + full suite to verify**

Run: `vendor/bin/phpunit phpunit_tests/Http/RouterAuthorizationTest.php`
Expected: PASS.
Run: `composer test:quick`
Expected: PASS (no regressions).

- [ ] **Step 5: Commit**

```bash
git add src/Router.php phpunit_tests/Http/RouterAuthorizationTest.php
git commit -m "feat(fga): enforce GRC editor on temporale, missals, decrees routes"
```

---

### Task A5: AccessRequestHandler — accept GRC in request submission/approval

**Files:**

- Modify: `src/Handlers/Auth/AccessRequestHandler.php:41` (VALID_OBJECT_TYPES), `:208-233` (submit validation), `:371-395` (approve validation)
- Test: `phpunit_tests/Handlers/Auth/AccessRequestHandlerValidationTest.php` (create), or extend an existing AccessRequestHandler test.

**Interfaces:**

- Consumes: `AccessRequestRepository::isValidObjectIdForType()` (Task A2).

- [ ] **Step 1: Write the failing test**

Add focused tests that a `general_roman_calendar` permission with a valid id is accepted and with an
invalid id is rejected. Mirror the existing AccessRequestHandler test setup (request body → handler →
response). Example assertions on the validation path:

```php
public function testGrcPermissionWithValidObjectIdIsAccepted(): void
{
    $perms = [['object_type' => 'general_roman_calendar', 'object_id' => 'temporale', 'relation' => 'editor']];
    self::assertTrue($this->submitIsAccepted('calendar_editor', $perms));
}

public function testGrcPermissionWithInvalidObjectIdIsRejected(): void
{
    $perms = [['object_type' => 'general_roman_calendar', 'object_id' => 'EDITIO_TYPICA_1971', 'relation' => 'editor']];
    self::assertFalse($this->submitIsAccepted('calendar_editor', $perms));
}
```

> `submitIsAccepted()` is a helper to write against the handler's existing entry point (it returns true
> on 2xx, false on a `ValidationException`/4xx). Use the existing test harness's request builder.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/AccessRequestHandlerValidationTest.php`
Expected: FAIL — GRC type rejected as invalid object_type.

- [ ] **Step 3: Implement (consolidate object-type list onto the repository constant)**

Per the consolidation decision, do NOT add a fifth entry to a private copy. Instead, remove this
handler's private `VALID_OBJECT_TYPES` constant (lines ~41-46) entirely and point every usage at the
single authoritative source `AccessRequestRepository::VALID_OBJECT_TYPES` (updated in Task A2).

- Delete the `private const VALID_OBJECT_TYPES = [ ... ];` declaration.
- Replace each `self::VALID_OBJECT_TYPES` reference in this file (the submit loop ~line 218 and the
  approve loop ~line 379) with `AccessRequestRepository::VALID_OBJECT_TYPES`.
- Leave the private `VALID_RELATIONS` constant unchanged (relations are not part of this consolidation).
- Ensure `use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;` is imported.

In the submit-validation loop, after the `object_id === ''` check (line ~233), add:

```php
            if (!AccessRequestRepository::isValidObjectIdForType($objectType, $objectId)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_id "%s" is invalid for object_type "%s". Valid ids: %s',
                        $index,
                        $objectId,
                        $objectType,
                        implode(', ', AccessRequestRepository::GRC_OBJECT_IDS)
                    )
                );
            }
```

In the approve-validation loop, after the `in_array($objType, ...)` check (line ~385), add the same guard using the local names `$objType` / `$objId`:

```php
            if (!AccessRequestRepository::isValidObjectIdForType($objType, $objId)) {
                throw new ValidationException(sprintf(
                    'permissions[%d].object_id "%s" is invalid for object_type "%s". Valid ids: %s',
                    $index,
                    $objId,
                    $objType,
                    implode(', ', AccessRequestRepository::GRC_OBJECT_IDS)
                ));
            }
```

(`AccessRequestRepository` is already imported per the consolidation step above.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/AccessRequestHandlerValidationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/Auth/AccessRequestHandler.php phpunit_tests/Handlers/Auth/AccessRequestHandlerValidationTest.php
git commit -m "feat(fga): validate GRC object ids in access request handler"
```

---

### Task A6: PermissionAdminHandler — accept GRC in admin grants

**Files:**

- Modify: `src/Handlers/Admin/PermissionAdminHandler.php:54` (VALID_OBJECT_TYPES), `:816-834` (`validateTupleParams`)
- Test: `phpunit_tests/Handlers/Admin/PermissionAdminHandlerValidationTest.php` (create) or extend the existing admin handler test.

**Interfaces:**

- Consumes: `AccessRequestRepository::isValidObjectIdForType()` (Task A2).

- [ ] **Step 1: Write the failing test**

```php
public function testGrantGrcTupleWithValidIdPasses(): void
{
    self::assertTrue($this->tupleParamsValid('user:abc', 'general_roman_calendar', 'decrees', 'editor'));
}

public function testGrantGrcTupleWithInvalidIdFails(): void
{
    self::assertFalse($this->tupleParamsValid('user:abc', 'general_roman_calendar', 'nonsense', 'editor'));
}
```

> `tupleParamsValid()` is a helper that calls the handler's grant validation (or `validateTupleParams`
> via the public grant entry point) and returns true unless a `ValidationException` is thrown.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerValidationTest.php`
Expected: FAIL — GRC type invalid.

- [ ] **Step 3: Implement (consolidate object-type list onto the repository constant)**

Per the consolidation decision, remove this handler's private `VALID_OBJECT_TYPES` constant
(lines ~54-59) entirely and point every usage at `AccessRequestRepository::VALID_OBJECT_TYPES`
(updated in Task A2).

- Delete the `private const VALID_OBJECT_TYPES = [ ... ];` declaration.
- Replace each `self::VALID_OBJECT_TYPES` reference in this file (the list-filter validation ~line 330
  and `validateTupleParams` ~line 816) with `AccessRequestRepository::VALID_OBJECT_TYPES`.
- Leave the private `VALID_RELATIONS` constant unchanged.
- Ensure `use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;` is imported.

In `validateTupleParams()`, after the `$objectId === ''` check (line ~824), add:

```php
        if (!AccessRequestRepository::isValidObjectIdForType($objectType, $objectId)) {
            throw new ValidationException(
                sprintf(
                    'Invalid object_id "%s" for object_type "%s". Valid ids: %s',
                    $objectId,
                    $objectType,
                    implode(', ', AccessRequestRepository::GRC_OBJECT_IDS)
                )
            );
        }
```

(`AccessRequestRepository` is already imported per the consolidation step above.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerValidationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/Admin/PermissionAdminHandler.php phpunit_tests/Handlers/Admin/PermissionAdminHandlerValidationTest.php
git commit -m "feat(fga): validate GRC object ids in permission admin handler"
```

---

### Task A7: Phase A verification + static analysis

**Files:** none (verification only).

- [ ] **Step 1: Run full quality gates**

```bash
composer parallel-lint
composer lint
composer analyse
composer test
```

Expected: all pass. Fix any phpcs issues with `composer lint:fix`.

- [ ] **Step 2: Apply the model to a running OpenFGA store (manual/integration)**

In an environment with OpenFGA running, run the model-update script and confirm the new type is present:

```bash
./scripts/setup-openfga.sh
```

Expected: the script writes the updated authorization model containing `general_roman_calendar`.

- [ ] **Step 3: Manual end-to-end smoke (documented, optional in CI)**

Grant `editor` on `general_roman_calendar:temporale` to a test user, obtain their token, and confirm
`PATCH /temporale/...` succeeds while a user without the grant gets 403. Record the result in the PR
description.

- [ ] **Step 4: Open the API PR**

```bash
git push -u origin feat/general-roman-calendar-object-type
gh pr create --base development --title "feat(fga): general_roman_calendar object type + enforcement" --body "Implements docs/superpowers/specs/2026-06-20-general-roman-calendar-object-type-design.md (API side)."
```

---

## Phase B — Frontend (`LiturgicalCalendarFrontend`)

Start only after the API PR is merged (the model must exist server-side). All Phase B paths are relative
to the `LiturgicalCalendarFrontend` repo root. Create branch `feat/general-roman-calendar-object-type`
off `development`. Verify JS with `node --check <file>` and PHP with `composer lint`.

### Task B1: Mirror the FGA model (byte-identical)

**Files:**

- Modify: `scripts/openfga-model.json`

- [ ] **Step 1: Copy the model from the API repo**

```bash
cp ../LiturgicalCalendarAPI/scripts/openfga-model.json scripts/openfga-model.json
```

- [ ] **Step 2: Verify byte-identical**

Run: `diff -q ../LiturgicalCalendarAPI/scripts/openfga-model.json scripts/openfga-model.json`
Expected: no output (identical).

- [ ] **Step 3: Commit**

```bash
git add scripts/openfga-model.json
git commit -m "feat(fga): mirror general_roman_calendar in frontend openfga model"
```

---

### Task B2: Admin grant UI — option, display name, object-id dropdown

**Files:**

- Modify: `admin-permissions.php:287` (object-type `<select>`), `:371` (i18n display-name map)
- Modify: `assets/js/admin-permissions.js:53-57` (`objectTypeNames`), object-id field handling (~lines 32-33, 258-274)

**Interfaces:**

- Consumes: `config.i18n.generalRomanCalendar` (added in this task).

- [ ] **Step 1: Add the option + i18n in `admin-permissions.php`**

After the `test_definition` `<option>` (line ~287) add:

```php
                            <option value="general_roman_calendar"><?php echo htmlspecialchars(_('General Roman Calendar'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
```

In the JS config `i18n` block, after `testDefinition:` (line ~371) add:

```php
                generalRomanCalendar: <?php echo json_encode(_('General Roman Calendar')); ?>,
```

- [ ] **Step 2: Update `assets/js/admin-permissions.js`**

Add to the `objectTypeNames` map (after `test_definition`, line ~57):

```javascript
        'general_roman_calendar': config.i18n.generalRomanCalendar,
```

Add a module-level constant near the top of the file (after the element refs at lines ~32-33):

```javascript
    // Fixed object-id choices for the singleton-ish General Roman Calendar type.
    const GRC_OBJECT_IDS = [
        { id: 'temporale',          label: config.i18n.grcTemporale },
        { id: 'EDITIO_TYPICA_1970', label: config.i18n.grcSanctorale1970 },
        { id: 'EDITIO_TYPICA_2002', label: config.i18n.grcSanctorale2002 },
        { id: 'EDITIO_TYPICA_2008', label: config.i18n.grcSanctorale2008 },
        { id: 'decrees',            label: config.i18n.grcDecrees },
    ];

    // Swap the free-text Object ID input for a <select> when GRC is selected, and back otherwise.
    function syncObjectIdField(objectType) {
        const current = document.getElementById('grantObjectId');
        if (objectType === 'general_roman_calendar') {
            if (current.tagName === 'SELECT') {
                return;
            }
            const select = document.createElement('select');
            select.className = 'form-select';
            select.id = 'grantObjectId';
            select.required = true;
            for (const opt of GRC_OBJECT_IDS) {
                const o = document.createElement('option');
                o.value = opt.id;
                o.textContent = opt.label;
                select.appendChild(o);
            }
            current.replaceWith(select);
        } else if (current.tagName === 'SELECT') {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.id = 'grantObjectId';
            input.required = true;
            input.placeholder = config.i18n.enterObjectId || '';
            current.replaceWith(input);
        }
    }

    grantObjectType.addEventListener('change', (e) => syncObjectIdField(e.target.value));
```

> The grant submit handler (line ~270) reads `document.getElementById('grantObjectId').value` — because
> the swap keeps the same `id`, no change is needed there. Confirm the reset logic (line ~258-259) still
> works: after reset, call `syncObjectIdField('')` to restore the text input.

In the reset block (line ~258), add after `grantObjectType.value = '';`:

```javascript
        syncObjectIdField('');
```

- [ ] **Step 3: Add the new i18n strings to `admin-permissions.php` config**

In the same `i18n` block add:

```php
                grcTemporale: <?php echo json_encode(_('Temporale')); ?>,
                grcSanctorale1970: <?php echo json_encode(_('Sanctorale — Editio Typica 1970')); ?>,
                grcSanctorale2002: <?php echo json_encode(_('Sanctorale — Editio Typica 2002')); ?>,
                grcSanctorale2008: <?php echo json_encode(_('Sanctorale — Editio Typica 2008')); ?>,
                grcDecrees: <?php echo json_encode(_('Decrees of the Dicastery for Divine Worship')); ?>,
                enterObjectId: <?php echo json_encode(_('Enter object ID...')); ?>,
```

- [ ] **Step 4: Verify**

Run: `node --check assets/js/admin-permissions.js`
Expected: no syntax errors.
Run: `composer lint`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add admin-permissions.php assets/js/admin-permissions.js
git commit -m "feat(ui): grant General Roman Calendar permissions in admin UI"
```

---

### Task B3: Access-request UI — role→type map, display name, object-id dropdown

**Files:**

- Modify: `assets/js/permission-requests.js:50-53` (`objectTypeNames`), `:82-84` (`roleObjectTypes`), object-id field handling
- Modify: `permission-requests.php` and `request-access.php` (i18n config strings for the new labels)

**Interfaces:**

- Consumes: the same `config.i18n.generalRomanCalendar` + GRC id labels, provided by `permission-requests.php` / `request-access.php`.

- [ ] **Step 1: Update `assets/js/permission-requests.js`**

Add to `objectTypeNames` (after `test_definition`, line ~53):

```javascript
        'general_roman_calendar': config.i18n.generalRomanCalendar,
```

Update `roleObjectTypes` (lines ~82-84) to:

```javascript
        'calendar_editor': ['national_calendar', 'diocesan_calendar', 'wider_region', 'general_roman_calendar'],
        'test_editor': ['test_definition'],
        'developer': ['national_calendar', 'diocesan_calendar', 'wider_region', 'test_definition', 'general_roman_calendar']
```

Apply the same `GRC_OBJECT_IDS` + object-id-field-swap approach as Task B2, scoped to the per-row
object-id control used in this form (match the existing element ids/classes this file already uses when
it builds a permission row). When the row's object type is `general_roman_calendar`, render a `<select>`
of the five ids; otherwise the existing input.

- [ ] **Step 2: Add i18n strings in `permission-requests.php` and `request-access.php`**

In each file's JS i18n config object, add the same six keys as Task B2 Step 3 (`generalRomanCalendar`,
`grcTemporale`, `grcSanctorale1970`, `grcSanctorale2002`, `grcSanctorale2008`, `grcDecrees`). Use the
existing `_()` + `json_encode` pattern already in those files.

- [ ] **Step 3: Verify**

Run: `node --check assets/js/permission-requests.js`
Expected: no syntax errors.
Run: `composer lint`
Expected: pass.

- [ ] **Step 4: Commit**

```bash
git add assets/js/permission-requests.js permission-requests.php request-access.php
git commit -m "feat(ui): request General Roman Calendar access in request flows"
```

---

### Task B4: i18n extraction + Phase B verification

**Files:**

- Modify: `i18n/` gettext catalogs (regenerated)

- [ ] **Step 1: Extract/update translation strings**

Run the project's gettext extraction (matching how this repo updates `.pot`/`.po`; commonly):

```bash
composer lint:md:fix >/dev/null 2>&1 || true
# Extract translatable strings (use the repo's documented command if different):
find . -path ./vendor -prune -o -name '*.php' -print | xargs xgettext --from-code=UTF-8 -k_ -o i18n/messages.pot 2>/dev/null || true
```

Confirm the new msgids appear in the catalog: `General Roman Calendar`, `Temporale`,
`Sanctorale — Editio Typica 1970/2002/2008`, and `Decrees of the Dicastery for Divine Worship`.
If the project uses Weblate, leave `.po` value updates to Weblate and only commit the `.pot`/source
string additions.

- [ ] **Step 2: Run quality gates**

```bash
composer parallel-lint
composer lint
yarn typecheck
```

Expected: all pass.

- [ ] **Step 3: Manual UI smoke**

With the API (Phase A) deployed: open `admin-permissions.php` and select **General Roman Calendar** as
Object Type. Confirm the Object ID field becomes a dropdown of the five labels, grant `editor` on
`Temporale` to a test user, and confirm the tuple appears in the list. Repeat a request in
`request-access.php` as a calendar_editor.

- [ ] **Step 4: Commit + open PR**

```bash
git add i18n/
git commit -m "chore(i18n): add General Roman Calendar permission strings"
git push -u origin feat/general-roman-calendar-object-type
gh pr create --base development --title "feat(ui): general_roman_calendar object type (frontend)" --body "Mirror of the API GRC object type; implements the frontend portion of docs/superpowers/specs/2026-06-20-general-roman-calendar-object-type-design.md (in the API repo)."
```

---

## Self-Review notes (for the implementer)

- **Object-type list consolidation:** the API previously had three duplicated `VALID_OBJECT_TYPES`
  lists (`AccessRequestRepository`, `AccessRequestHandler`, `PermissionAdminHandler`). Per the
  consolidation decision, Task A2 keeps the authoritative copy on `AccessRequestRepository`, and
  Tasks A5/A6 delete the two handler-private copies and reference the repository constant instead.
- **Cascade** (`RoleCascadeService`) needs no change: it discovers object ids via OpenFGA `listObjects`
  over `ROLE_OBJECT_TYPES`, so adding GRC to that map (Task A2) is sufficient.
- **Naming consistency:** the factory `forGeneralRomanCalendar`, helper `isValidObjectIdForType`,
  constant `GRC_OBJECT_IDS`, and i18n keys (`generalRomanCalendar`, `grcTemporale`,
  `grcSanctorale1970/2002/2008`, `grcDecrees`) are used identically across all tasks referencing them.
- **POST** writes to `missals`/`decrees` remain ungated (matches existing `data`/`tests` behavior —
  only PUT/PATCH/DELETE are gated). If POST creation must be gated, that is a separate follow-up.
