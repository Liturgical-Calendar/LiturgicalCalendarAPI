# Calendar-scoped tests — API foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat `test_definition` OpenFGA type with three
calendar-scoped test types and resolve `/tests` authorization via each test's
`applies_to`, with an idempotent tuple migration.

**Architecture:** Additive OpenFGA model version adds `national_calendar_test`,
`diocesan_calendar_test`, `general_roman_calendar_test` (each flat, 4 relations)
alongside the existing `test_definition` during transition. The `/tests` authz
middleware gains a per-request _object resolver_ that loads the target test,
reads `applies_to`, and checks the scoped object. API validation/role-maps swap
to the new types. A standalone PHP migration remaps existing `test_definition`
tuples. `test_definition` is removed in a later model version after migration.

**Tech Stack:** PHP 8.4, PSR-7/15, OpenFGA (preshared-key auth via
`OpenFgaClient`), PHPUnit, phpcs, PHPStan L10. Companion repo (frontend) is a
separate plan.

## Global Constraints

- PHP >= 8.4; PSR-12 + repo `phpcs.xml`; PHPStan level 10 must stay green
  (`composer analyse`).
- Branch off `development`, PR to `development`; never commit to
  `development`/`stable` directly. Work on branch `feat/calendar-scoped-tests`
  (already created; the design spec lives there).
- Never use `--no-verify`; pre-commit runs phpcs + markdownlint.
- New object types (exact strings): `national_calendar_test`,
  `diocesan_calendar_test`, `general_roman_calendar_test`. GRC-test fixed
  object_id: `general_roman_calendar`.
- `applies_to` resolution: `diocesan_calendar`→`diocesan_calendar_test:{id}`;
  `national_calendar`→`national_calendar_test:{id}`;
  absent/empty→`general_roman_calendar_test:general_roman_calendar`.
  Unknown/missing test → fail closed.
- Keep `test_definition` in the model until the migration has run in every
  environment (additive rollout).

## File Structure

| File                                                         | Responsibility                                          | Action                              |
| ------------------------------------------------------------ | ------------------------------------------------------- | ----------------------------------- |
| `scripts/openfga-model.json`                                 | Authorization model                                     | Modify — add 3 type_definitions     |
| `phpunit_tests/Services/OpenFgaModelTest.php`                | Model shape assertions                                  | Modify — assert new types/relations |
| `src/Repositories/AccessRequestRepository.php`               | Valid/role object types + object_id validation          | Modify                              |
| `phpunit_tests/Repositories/AccessRequestRepositoryTest.php` | Repo validation tests                                   | Modify/extend                       |
| `src/Handlers/Auth/AccessRequestHandler.php`                 | Role↔permission consistency                             | Modify                              |
| `phpunit_tests/Handlers/Auth/AccessRequestHandlerTest.php`   | Handler validation tests                                | Modify/extend                       |
| `src/Services/TestScopeResolver.php`                         | Resolve a test name → scoped (type,id) via `applies_to` | **Create**                          |
| `phpunit_tests/Services/TestScopeResolverTest.php`           | Resolver unit tests                                     | **Create**                          |
| `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`     | Authz check; add optional object resolver               | Modify                              |
| `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`  | Middleware tests                                        | Modify/extend                       |
| `src/Router.php`                                             | `/tests` wiring                                         | Modify (lines ~699–716)             |
| `jsondata/schemas/openapi.json`                              | object_type enums                                       | Modify (4 enum sites)               |
| `scripts/migrate-test-tuples.php`                            | One-off idempotent tuple remap                          | **Create**                          |
| `phpunit_tests/Services/TestTupleMigrationTest.php`          | Migration mapping tests                                 | **Create**                          |
| `docs/ops/test-scope-migration-runbook.md`                   | Rollout/verify steps                                    | **Create**                          |

---

### Task 1: Add the three scoped test types to the OpenFGA model

**Files:**

- Modify: `scripts/openfga-model.json`
- Test: `phpunit_tests/Services/OpenFgaModelTest.php`

**Interfaces:**

- Produces: model contains `type_definitions[*].type` ∈
  {`national_calendar_test`, `diocesan_calendar_test`,
  `general_roman_calendar_test`}, each with relations
  `admin/viewer/editor/deleter` (direct,
  `directly_related_user_types: [{type:user}]`).

- [ ] **Step 1: Write the failing test** — in `OpenFgaModelTest.php`, add:

```php
public function testScopedTestTypesPresentWithFourRelations(): void
{
    $model = json_decode((string) file_get_contents(__DIR__ . '/../../scripts/openfga-model.json'), true);
    $types = array_column($model['type_definitions'], 'relations', 'type');
    foreach (['national_calendar_test', 'diocesan_calendar_test', 'general_roman_calendar_test'] as $t) {
        $this->assertArrayHasKey($t, $types, "missing type $t");
        $this->assertSame(['admin', 'viewer', 'editor', 'deleter'], array_keys($types[$t]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails** —
      `vendor/bin/phpunit --filter testScopedTestTypesPresentWithFourRelations`
      → FAIL (missing type).
- [ ] **Step 3: Add the three type_definitions** to `scripts/openfga-model.json`
      (after the `test_definition` block), each identical in shape to
      `national_calendar` but with the new `type` name:

```json
{
  "type": "national_calendar_test",
  "relations": {
    "admin": { "this": {} },
    "viewer": { "this": {} },
    "editor": { "this": {} },
    "deleter": { "this": {} }
  },
  "metadata": {
    "relations": {
      "admin": { "directly_related_user_types": [{ "type": "user" }] },
      "viewer": { "directly_related_user_types": [{ "type": "user" }] },
      "editor": { "directly_related_user_types": [{ "type": "user" }] },
      "deleter": { "directly_related_user_types": [{ "type": "user" }] }
    }
  }
}
```

Repeat for `diocesan_calendar_test` and `general_roman_calendar_test`. Keep
`test_definition`.

- [ ] **Step 4: Run tests** —
      `vendor/bin/phpunit phpunit_tests/Services/OpenFgaModelTest.php` → PASS.
- [ ] **Step 5: Commit** —
      `git add scripts/openfga-model.json phpunit_tests/Services/OpenFgaModelTest.php && git commit -m "feat(fga): add calendar-scoped test object types to model"`

---

### Task 2: Swap object-type constants in AccessRequestRepository

**Files:**

- Modify: `src/Repositories/AccessRequestRepository.php` (`VALID_OBJECT_TYPES`,
  `ROLE_OBJECT_TYPES`, `validateObjectId`)
- Test: `phpunit_tests/Repositories/AccessRequestRepositoryTest.php`

**Interfaces:**

- Produces: `VALID_OBJECT_TYPES` includes the 3 new types and **no longer**
  `test_definition`; `ROLE_OBJECT_TYPES['test_editor']` = the 3 new types;
  `ROLE_OBJECT_TYPES['developer']` swaps `test_definition` → the 3 new types.
  `validateObjectId(type,id)` accepts: `general_roman_calendar_test` only when
  `id === 'general_roman_calendar'`;
  `national_calendar_test`/`diocesan_calendar_test` use the same id validation
  as `national_calendar`/`diocesan_calendar` respectively.

- [ ] **Step 1: Write failing tests** — add cases:

```php
public function testNewTestTypesAreValid(): void
{
    foreach (['national_calendar_test','diocesan_calendar_test','general_roman_calendar_test'] as $t) {
        $this->assertContains($t, AccessRequestRepository::VALID_OBJECT_TYPES);
    }
    $this->assertNotContains('test_definition', AccessRequestRepository::VALID_OBJECT_TYPES);
    $this->assertSame(
        ['national_calendar_test','diocesan_calendar_test','general_roman_calendar_test'],
        AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor']
    );
}

public function testGrcTestObjectIdMustBeFixed(): void
{
    $repo = new AccessRequestRepository($this->pdo());
    $this->assertTrue($repo->isValidObjectId('general_roman_calendar_test', 'general_roman_calendar'));
    $this->assertFalse($repo->isValidObjectId('general_roman_calendar_test', 'temporale'));
}
```

(Match the existing test's method for obtaining a repo / the existing object-id
validation entry point — read `validateObjectId`/`isValidObjectId` and mirror
its test style.)

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** — update the two constants; in the object-id
      validation switch add: `national_calendar_test` → reuse the
      `national_calendar` branch; `diocesan_calendar_test` → reuse the
      `diocesan_calendar` branch; `general_roman_calendar_test` → valid iff
      `$objectId === 'general_roman_calendar'`.
- [ ] **Step 4: Run → PASS**, plus
      `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php`.
- [ ] **Step 5: Commit** —
      `feat(rbac): scoped test object types in AccessRequestRepository`

---

### Task 3: Update role↔permission consistency in AccessRequestHandler

**Files:**

- Modify: `src/Handlers/Auth/AccessRequestHandler.php`
  (`validateRolePermissionConsistency`, ~line 578)
- Test: `phpunit_tests/Handlers/Auth/AccessRequestHandlerTest.php`

**Interfaces:**

- Produces: `test_editor` requests pass iff every `object_type` ∈ the 3 new test
  types; the error message lists them.

- [ ] **Step 1: Write failing tests** — a `test_editor` request with
      `object_type='national_calendar_test'` passes; with
      `object_type='national_calendar'` throws `ValidationException` whose
      message names the three test types. (Follow the existing handler-test
      harness for building a request.)
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** — replace the `$objectType !== 'test_definition'`
      check with
      `!in_array($objectType, AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor'], true)`
      and update the message to
      `implode(', ', AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor'])`.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** —
      `feat(rbac): validate scoped test types for test_editor role`

---

### Task 4: TestScopeResolver — map a test name to scoped (type, id)

**Files:**

- Create: `src/Services/TestScopeResolver.php`
- Test: `phpunit_tests/Services/TestScopeResolverTest.php`

**Interfaces:**

- Produces:
  `TestScopeResolver::resolve(string $testName): ?array{0:string,1:string}`
  returning `[objectType, objectId]` or `null` when the test file is
  missing/unreadable. Reads
  `JsonData::TESTS_FOLDER->path()."/{$testName}.json"`, decodes `applies_to`:
  - `['diocesan_calendar' => $id]` → `['diocesan_calendar_test', $id]`
  - `['national_calendar' => $id]` → `['national_calendar_test', $id]`
  - absent/empty/other →
    `['general_roman_calendar_test', 'general_roman_calendar']`

- [ ] **Step 1: Write failing tests** — use a temp tests dir or fixtures: a
      diocesan `applies_to` → `['diocesan_calendar_test','rotter_nl']`; national
      → `['national_calendar_test','US']`; missing `applies_to` →
      `['general_roman_calendar_test','general_roman_calendar']`; nonexistent
      name → `null`.

```php
public function testResolvesDiocesan(): void
{
    $r = new TestScopeResolver($this->fixturesDir); // dir containing FooTest.json
    $this->assertSame(['diocesan_calendar_test','rotter_nl'], $r->resolve('FooTest'));
}
```

- [ ] **Step 2: Run → FAIL** (class missing).
- [ ] **Step 3: Implement** `TestScopeResolver` (constructor takes the tests
      dir, defaulting to `JsonData::TESTS_FOLDER->path()`); guard
      `file_get_contents`/`json_decode` failures → `null`.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** —
      `feat(api): TestScopeResolver maps tests to scoped FGA objects via applies_to`

---

### Task 5: Object-resolver mode in OpenFgaAuthorizationMiddleware + /tests factory

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`
- Test: `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`

**Interfaces:**

- Consumes: `TestScopeResolver` (Task 4), `OpenFgaClient::check` (existing).
- Produces: new constructor-injectable optional `?callable $objectResolver` with
  signature `fn(ServerRequestInterface): ?array{0:string,1:string}`. When set,
  `process()` derives `[$objectType,$resourceId]` from it (instead of
  `$this->objectType` + `extractResourceId`); a `null` result →
  `ForbiddenException` (fail closed). New static factory
  `forTestScopes(OpenFgaClient $client, TestScopeResolver $resolver): self` that
  wires a resolver reading the `test_id` request attribute →
  `$resolver->resolve($testId)`.

- [ ] **Step 1: Write failing tests** — with a stub `OpenFgaClient` (extend the
      existing test double) and a resolver returning
      `['national_calendar_test','US']`: a `PATCH` by a user lacking the tuple →
      `ForbiddenException` mentioning `national_calendar_test:US`; with the
      tuple present → passes through; resolver returns `null` →
      `ForbiddenException`. Admin role still bypasses.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** — add the `objectResolver` property + constructor
      param (default `null`); in `process()`, after the admin bypass and
      relation lookup, if `$this->objectResolver !== null` use it to get
      `[$type,$id]` (fail closed on null) and skip `extractResourceId`; build
      `$fgaObject = "{$type}:{$id}"`. Add `forTestScopes()`.
- [ ] **Step 4: Run → PASS**, plus the full middleware test file.
- [ ] **Step 5: Commit** —
      `feat(api): object-resolver authz mode for calendar-scoped tests`

---

### Task 6: Wire /tests routing to the resolver

**Files:**

- Modify: `src/Router.php` (~line 705: replace `forTestDefinition($fgaClient)`)
- Test: covered by an integration/route test if present; otherwise assert via
  the middleware factory.

**Interfaces:**

- Consumes: `OpenFgaAuthorizationMiddleware::forTestScopes`,
  `TestScopeResolver`.

- [ ] **Step 1:** At Router.php:705 replace
      `OpenFgaAuthorizationMiddleware::forTestDefinition($fgaClient)` with:

```php
$pipeline->pipe(OpenFgaAuthorizationMiddleware::forTestScopes($fgaClient, new TestScopeResolver()));
```

Add the `use` import for `TestScopeResolver`. Leave the GRC temporale/decrees
sub-route pipes (710/715) unchanged.

- [ ] **Step 2:** Run `composer analyse` and `composer test:quick` → green (no
      `forTestDefinition` usages remain; grep to confirm, then remove the
      now-dead `forTestDefinition()` factory **only after** confirming no other
      callers).
- [ ] **Step 3: Commit** —
      `feat(api): route /tests writes through calendar-scoped authz`

---

### Task 7: openapi.json object_type enums

**Files:**

- Modify: `jsondata/schemas/openapi.json` (4 enum sites: ~2090, 2455,
  7403, 7855)

- [ ] **Step 1:** In each `object_type` enum array, replace `"test_definition"`
      with `"national_calendar_test"`, `"diocesan_calendar_test"`,
      `"general_roman_calendar_test"`. Update the example at ~1666 to a scoped
      value.
- [ ] **Step 2:** `composer lint:openapi` (Redocly) → green.
- [ ] **Step 3: Commit** —
      `docs(openapi): scoped test object types in access-request schemas`

---

### Task 8: Tuple migration script (idempotent auto-remap)

**Files:**

- Create: `scripts/migrate-test-tuples.php`
- Test: `phpunit_tests/Services/TestTupleMigrationTest.php`

**Interfaces:**

- Consumes: `OpenFgaClient::readTuples` (enumerate `test_definition` tuples —
  confirm its filter signature when implementing), `TestScopeResolver::resolve`,
  `OpenFgaClient::writeTuple`, `OpenFgaClient::deleteTuple`.
- Produces: a
  `mapTuple(array $tuple, TestScopeResolver $r): ?array{user:string,relation:string,object:string}`
  pure function (unit-tested) returning the new scoped tuple, or `null` for
  unresolved tests. The script wires it to the live client with `--dry-run`
  (default) and `--apply` flags, logging counts + unresolved test ids.

- [ ] **Step 1: Write failing test** for the pure mapper: given
      `{user:'user:1', relation:'editor', object:'test_definition:FooTest'}` and
      a resolver returning `['diocesan_calendar_test','rotter_nl']`, `mapTuple`
      →
      `{user:'user:1', relation:'editor', object:'diocesan_calendar_test:rotter_nl'}`;
      resolver `null` → `null`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the mapper (in the script or a small
      `TestTupleMigration` class it includes) + the CLI wrapper: read all
      `test_definition` tuples, `mapTuple` each, on `--apply` `writeTuple` the
      new then `deleteTuple` the old; idempotent (writing an existing tuple is a
      no-op per `writeTuple`); collect+print a summary; never delete when the
      remap is `null` (report it).
- [ ] **Step 4: Run → PASS.** Manually
      `php scripts/migrate-test-tuples.php --dry-run` against the dev store and
      review the summary.
- [ ] **Step 5: Commit** —
      `feat(ops): idempotent migration of test_definition tuples to scoped types`

---

### Task 9: Rollout runbook + verification

**Files:**

- Create: `docs/ops/test-scope-migration-runbook.md`

- [ ] **Step 1:** Document the ordered rollout (matches the spec): (1) apply the
      new model version via `scripts/setup-openfga.sh` / `load_model` in each
      env (dev → staging → prod); (2) deploy this API; (3) run
      `migrate-test-tuples.php --dry-run` then `--apply`; (4) verify `/tests`
      PATCH authz for a scoped user (expect 403 out-of-scope, 2xx in-scope); (5)
      after all envs migrated, a follow-up model version drops `test_definition`
      and a follow-up removes it from any remaining constants. Include the exact
      curl/CLI verification commands.
- [ ] **Step 2:** `composer lint:md` → green.
- [ ] **Step 3: Commit** — `docs(ops): test-scope migration runbook`

---

## Self-Review

- **Spec coverage:** model change (T1), `/tests` resolver via `applies_to`
  (T4–T6), API validation/role maps (T2–T3), openapi (T7), idempotent migration
  (T8), ordered rollout incl. deferred `test_definition` removal (T9). ✓
- **Placeholder scan:** object-id validation in T2 and the handler harness in T3
  say "mirror the existing style" — acceptable because the exact helper names
  must be read from current code at execution; all behavior is specified. No
  TODO/TBD.
- **Type consistency:**
  `TestScopeResolver::resolve(): ?array{0:string,1:string}` is consumed
  identically in T5/T6/T8; object type strings are constant throughout; GRC-test
  id `general_roman_calendar` consistent (T1/T2/T4).

## Follow-ups (out of scope for this plan)

- **Frontend plan** (`permission-requests.php`/`.js`, `admin-permissions.js`) —
  separate plan, after this lands and deploys.
- **`test_definition` removal** — a later model version + constant cleanup once
  every environment is migrated.
