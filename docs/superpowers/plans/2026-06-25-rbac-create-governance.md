# RBAC create-governance + admin-superset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `admin` a proper superset (drop `deleter`, `DELETE → admin`, union-rewrite hierarchy), add resource-type-aware create
authz with national create-governance, cascade-revoke operational tuples on resource deletion, and grant `wider_region` admin to
member-nation admins via a tuple-to-userset rewrite.

**Architecture:** OpenFGA authorization model + PSR-15 middleware. Tuple side effects flow through the existing transactional outbox
(`DELETE_TUPLE`/`WRITE_TUPLE` rows processed by `OutboxProcessor`). No new outbox operation: the purge enumerates an object's
operational tuples and enqueues concrete `DELETE_TUPLE` rows. Spec:
`docs/superpowers/specs/2026-06-25-issues-668-669-rbac-create-governance-design.md`.

**Tech Stack:** PHP 8.4, PHPUnit, PHPStan level 10, PSR-12, OpenFGA, Postgres outbox.

## Global Constraints

- PHP >= 8.4; PSR-12; PHPStan level 10 (`composer analyse` scans `src` only). Use modern `@phpstan-ignore <identifier>` if ever needed (not `@phpstan-ignore-line`).
- Short array syntax `[]`; single quotes unless interpolating; 4-space indent; line length NOT enforced in PHP.
- Timezone always `Europe/Vatican`. Accept-Language always via `Negotiator::pickLanguage($request, [], LitLocale::LATIN)` (not relevant here but a standing rule).
- Pre-commit (CaptainHook) runs `composer lint` + `composer lint:md`. Never `--no-verify`. Markdown: `markdownlint-cli2`, `MD060: aligned` tables, `MD013` 180 cols (code/tables exempt).
- Branch `feat/rbac-create-governance` (already created off `development`). PRs target `development`, never `stable`.
- **Operational relations** = `['viewer','editor']`. `admin` is governance and is NEVER purged on data deletion.
- Run a single test file: `vendor/bin/phpunit phpunit_tests/Path/SomeTest.php`. Full suite: `composer test`. Style: `composer lint` / `composer lint:fix`.

## File Structure

**New files:**

- `src/Services/ResourceTuplePurgeService.php` — enumerate an object's operational tuples; enqueue one `DELETE_TUPLE` outbox row each; process sync. `admin` never touched.
- `src/Services/ResourceExistenceChecker.php` — map `{objectType, objectId}` → backing file path → exists?
- `src/Services/Outbox/ResourceTuplePurgeReconciler.php` — sweep all tuples; for deleted resources with operational tuples, call the purge service.
- `src/Services/WiderRegionMembershipSeeder.php` — derive `wider_region:<R>#member_nation@national_calendar:<N>` tuples from nation files; write them.
- `src/Services/DeleterTupleMapper.php` — pure mapper `deleter` tuple → `admin` tuple.
- `scripts/openfga-model.additive.json` — transient rollout-only model (union rewrites + member_nation, **keeps** `deleter`).
- `scripts/migrate-deleter-tuples.php` — CLI, `--dry-run`/`--apply`.
- `scripts/seed-wider-region-membership.php` — CLI, `--dry-run`/`--apply`.
- `scripts/reconcile-resource-tuples.php` — CLI, `--dry-run`/`--apply`.
- `docs/ops/rbac-create-governance-runbook.md` — rollout runbook (Task 14).
- Tests: `phpunit_tests/Services/ResourceTuplePurgeServiceTest.php`, `.../ResourceExistenceCheckerTest.php`,
  `.../Outbox/ResourceTuplePurgeReconcilerTest.php`, `.../WiderRegionMembershipSeederTest.php`, `.../DeleterTupleMapperTest.php`.

**Modified files:**

- `scripts/openfga-model.json` — union rewrites all types; `wider_region.member_nation` TTU; drop `deleter`.
- `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` — per-instance relation map; `DELETE → admin`; default create→admin; test create→editor.
- `src/Repositories/AccessRequestRepository.php` — `VALID_RELATIONS` drop `deleter`; add `OPERATIONAL_RELATIONS`; `isValidObjectIdForType` ISO-nation rule.
- `src/Handlers/Auth/AccessRequestHandler.php`, `src/Handlers/Admin/PermissionAdminHandler.php` — `VALID_RELATIONS` drop `deleter`; docblocks.
- `src/Handlers/RegionalDataHandler.php` — delete → purge; national create → enqueue `member_nation`.
- `src/Handlers/TestsHandler.php` — delete → purge.
- `jsondata/schemas/openapi.json` — drop `deleter` enums + example; document create semantics.
- Existing tests: `phpunit_tests/Services/OpenFgaModelTest.php`, `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`, `phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`.

---

## Task 1: OpenFGA model — union rewrites + member_nation TTU, drop deleter

**Files:**

- Modify: `scripts/openfga-model.json`
- Create: `scripts/openfga-model.additive.json`
- Test: `phpunit_tests/Services/OpenFgaModelTest.php`

**Interfaces:**

- Produces: a model where, for every type, `editor = this ∪ admin`, `viewer = this ∪ editor ∪ admin`, `admin` direct; `deleter`
  removed; `wider_region` additionally has `member_nation: [national_calendar]` and `admin = this ∪ (admin from member_nation)`.

- [ ] **Step 1: Update the model test to assert the new shape (failing test)**

Replace the two existing methods in `phpunit_tests/Services/OpenFgaModelTest.php` and add new ones:

```php
    public function testNoTypeDefinesDeleter(): void
    {
        $model = $this->loadModel();
        foreach ($model['type_definitions'] as $def) {
            if (!isset($def['relations'])) {
                continue;
            }
            self::assertArrayNotHasKey('deleter', $def['relations'], "{$def['type']} still defines deleter");
            self::assertArrayNotHasKey(
                'deleter',
                $def['metadata']['relations'] ?? [],
                "{$def['type']} still has deleter metadata"
            );
        }
    }

    public function testEditorAndViewerAreUnionsOfAdmin(): void
    {
        $model = $this->loadModel();
        $types = array_column($model['type_definitions'], 'relations', 'type');
        foreach (['national_calendar', 'diocesan_calendar', 'wider_region', 'general_roman_calendar'] as $t) {
            $editorChildren = $types[$t]['editor']['union']['child'];
            self::assertContains(['this' => (object) []], $editorChildren, "$t editor missing this", false);
            self::assertContains(['computedUserset' => ['relation' => 'admin']], $editorChildren, "$t editor missing admin");

            $viewerChildren = $types[$t]['viewer']['union']['child'];
            self::assertContains(['computedUserset' => ['relation' => 'editor']], $viewerChildren, "$t viewer missing editor");
            self::assertContains(['computedUserset' => ['relation' => 'admin']], $viewerChildren, "$t viewer missing admin");
        }
    }

    public function testWiderRegionHasMemberNationTtu(): void
    {
        $model = $this->loadModel();
        $types = array_column($model['type_definitions'], 'relations', 'type');
        $meta  = array_column($model['type_definitions'], 'metadata', 'type');

        self::assertArrayHasKey('member_nation', $types['wider_region']);
        self::assertSame(
            [['type' => 'national_calendar']],
            $meta['wider_region']['relations']['member_nation']['directly_related_user_types']
        );

        $adminChildren = $types['wider_region']['admin']['union']['child'];
        self::assertContains([
            'tupleToUserset' => [
                'tupleset'        => ['relation' => 'member_nation'],
                'computedUserset' => ['relation' => 'admin'],
            ],
        ], $adminChildren, 'wider_region admin missing member_nation TTU');
    }
```

Delete the old `testGeneralRomanCalendarTypeExistsWithStandardRelations` and `testScopedTestTypesPresentWithFourRelations` (they assert the `deleter` shape we are removing).

Note: `assertContains` with associative-array needles compares by `==`. `['this' => (object) []]` matches the JSON `{ "this": {} }`
only if decoded as object. The test decodes with `json_decode($json, true)` (assoc), so `{ "this": {} }` becomes `['this' => []]`.
Use `['this' => []]` as the needle instead:

```php
            self::assertContains(['this' => []], $editorChildren, "$t editor missing this");
```

(Apply the `['this' => []]` form; drop the stray third arg shown above.)

- [ ] **Step 2: Run the test — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/OpenFgaModelTest.php`
Expected: FAIL (model still flat with `deleter`).

- [ ] **Step 3: Rewrite `scripts/openfga-model.json`**

For **every** type currently shaped like:

```json
"relations": { "admin": { "this": {} }, "viewer": { "this": {} }, "editor": { "this": {} }, "deleter": { "this": {} } },
"metadata": { "relations": {
  "admin":   { "directly_related_user_types": [{ "type": "user" }] },
  "viewer":  { "directly_related_user_types": [{ "type": "user" }] },
  "editor":  { "directly_related_user_types": [{ "type": "user" }] },
  "deleter": { "directly_related_user_types": [{ "type": "user" }] }
} }
```

replace with (drop `deleter`; add union rewrites):

```json
"relations": {
  "admin":  { "this": {} },
  "editor": { "union": { "child": [ { "this": {} }, { "computedUserset": { "relation": "admin" } } ] } },
  "viewer": { "union": { "child": [ { "this": {} }, { "computedUserset": { "relation": "editor" } }, { "computedUserset": { "relation": "admin" } } ] } }
},
"metadata": { "relations": {
  "admin":  { "directly_related_user_types": [{ "type": "user" }] },
  "editor": { "directly_related_user_types": [{ "type": "user" }] },
  "viewer": { "directly_related_user_types": [{ "type": "user" }] }
} }
```

Apply to all 8 types: `wider_region`, `national_calendar`, `diocesan_calendar`, `general_roman_calendar`, `test_definition`, `national_calendar_test`, `diocesan_calendar_test`, `general_roman_calendar_test`.

Then for `wider_region` ONLY, additionally add the `member_nation` tupleset and the TTU on `admin`:

```json
{
  "type": "wider_region",
  "relations": {
    "member_nation": { "this": {} },
    "admin": { "union": { "child": [
      { "this": {} },
      { "tupleToUserset": { "tupleset": { "relation": "member_nation" }, "computedUserset": { "relation": "admin" } } }
    ] } },
    "editor": { "union": { "child": [ { "this": {} }, { "computedUserset": { "relation": "admin" } } ] } },
    "viewer": { "union": { "child": [ { "this": {} }, { "computedUserset": { "relation": "editor" } }, { "computedUserset": { "relation": "admin" } } ] } }
  },
  "metadata": { "relations": {
    "member_nation": { "directly_related_user_types": [{ "type": "national_calendar" }] },
    "admin":  { "directly_related_user_types": [{ "type": "user" }] },
    "editor": { "directly_related_user_types": [{ "type": "user" }] },
    "viewer": { "directly_related_user_types": [{ "type": "user" }] }
  } }
}
```

- [ ] **Step 4: Validate JSON + run the test**

Run: `php -r 'json_decode(file_get_contents("scripts/openfga-model.json"), true, 512, JSON_THROW_ON_ERROR); echo "valid\n";'`
Expected: `valid`
Run: `vendor/bin/phpunit phpunit_tests/Services/OpenFgaModelTest.php`
Expected: PASS

- [ ] **Step 5: Create the transient additive model**

Copy the final model to `scripts/openfga-model.additive.json`, then add `deleter` back to every type (the same
`"deleter": { "this": {} }` relation + its metadata entry) WITHOUT removing the new union rewrites or `member_nation`. This file is
applied to the live store first during rollout (Task 14 runbook). A `"_comment"` key is not valid in the model, so document the
file's transient purpose in the runbook instead.

Run: `php -r 'json_decode(file_get_contents("scripts/openfga-model.additive.json"), true, 512, JSON_THROW_ON_ERROR); echo "valid\n";'`
Expected: `valid`

- [ ] **Step 6: Commit**

```bash
git add scripts/openfga-model.json scripts/openfga-model.additive.json phpunit_tests/Services/OpenFgaModelTest.php
git commit -m "feat(rbac): admin-superset model (union rewrites, member_nation TTU, drop deleter)"
```

---

## Task 2: Middleware — per-instance method→relation map

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`
- Test: `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`

**Interfaces:**

- Consumes: nothing new.
- Produces: constructor gains a 6th param `?array $relationMap = null`; `forTestScopes` passes the test map; all other factories use
  the default. Default map = `['PUT'=>'admin','PATCH'=>'editor','DELETE'=>'admin']`; test map =
  `['PUT'=>'editor','PATCH'=>'editor','DELETE'=>'admin']`.

- [ ] **Step 1: Update existing middleware tests (failing)**

In `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`:

- `testAllowsWhenOpenFgaReturnsTrue`: change `->with('user:user-123', 'editor', 'national_calendar:IT')` → `'admin'`.
- `testDeniesWhenOpenFgaReturnsFalse`: change relation to `'admin'` and message to `'No admin permission for national_calendar:IT'`.
- Rename `testDeleteMapsToDeleterRelation` → `testDeleteMapsToAdminRelation`; change `->with('user:user-123', 'deleter', 'national_calendar:IT')` → `'admin'`.
- `testForGeneralRomanCalendarChecksFixedObjectId`: the request is `PUT` → change expected relation `'editor'` → `'admin'`.
- `testForMissalsNationalChecksNationalCalendarByPrefix`: request is `PUT` → change `'editor'` → `'admin'`.
- Leave PATCH-based tests (`testPatchMapsToEditorRelation`, `testForMissalsEditioTypicaChecksGeneralRomanCalendar`, object-resolver
  PATCH tests, `testForTestScopesFactory`) unchanged (PATCH→editor in both maps).

Add a new test asserting test-scope create maps to editor:

```php
    public function testForTestScopesPutMapsToEditor(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:US')
            ->willReturn(true);

        $resolver   = static fn () => ['national_calendar_test', 'US'];
        $middleware = new OpenFgaAuthorizationMiddleware(
            $client,
            'test_definition',
            'test_id',
            null,
            $resolver,
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        $request = ( new ServerRequest('PUT', '/tests/some-test') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'some-test');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }
```

- [ ] **Step 2: Run tests — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`
Expected: FAIL (still maps PUT→editor, DELETE→deleter).

- [ ] **Step 3: Implement per-instance map**

In `OpenFgaAuthorizationMiddleware.php`:

Replace the `RELATION_MAP` const block (lines 50–59) with:

```php
    /**
     * Default method→relation map. Create (`PUT`) is a governance act → admin.
     * Edit (`PATCH`) → editor. Delete → admin (admin is a superset; #668).
     *
     * @var array<string, string>
     */
    private const DEFAULT_RELATION_MAP = [
        'PUT'    => 'admin',
        'PATCH'  => 'editor',
        'DELETE' => 'admin',
    ];

    /** @var array<string, string> */
    private array $relationMap;
```

Update the class docblock mapping lines 24–26 to:

```php
 * Mapping (default; per-instance override via constructor):
 *   PUT (create) → "admin"   PATCH (edit) → "editor"   DELETE → "admin"
 *   Calendar tests override PUT → "editor" (creating a test is an editing act).
```

Add the 6th constructor param and assignment:

```php
    /**
     * @phpstan-param (\Closure(\Psr\Http\Message\ServerRequestInterface): (array{0: string, 1: string}|null))|null $objectResolver
     * @param array<string, string>|null $relationMap
     */
    public function __construct(
        OpenFgaClient $client,
        string $objectType,
        string $resourceIdAttribute = 'calendar_id',
        ?string $fixedObjectId = null,
        ?\Closure $objectResolver = null,
        ?array $relationMap = null
    ) {
        $this->client              = $client;
        $this->objectType          = $objectType;
        $this->resourceIdAttribute = $resourceIdAttribute;
        $this->fixedObjectId       = $fixedObjectId;
        $this->objectResolver      = $objectResolver;
        $this->relationMap         = $relationMap ?? self::DEFAULT_RELATION_MAP;
    }
```

In `process()` change line 140 from `self::RELATION_MAP[$method]` to:

```php
        $relation = $this->relationMap[$method] ?? null;
```

In `forTestScopes()` change the return (line 248) to pass the test map:

```php
        return new self(
            $client,
            '',
            '',
            null,
            $objectResolver,
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );
```

`forCalendarData`, `forGeneralRomanCalendar`, `forMissals` need no change (they use the default map).

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`
Expected: PASS

- [ ] **Step 5: Static analysis + style**

Run: `composer analyse -- src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` (or `composer analyse`)
Run: `composer lint`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Middleware/OpenFgaAuthorizationMiddleware.php phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php
git commit -m "feat(rbac): per-instance method->relation map (create->admin, test create->editor, DELETE->admin)"
```

---

## Task 3: Constants — drop `deleter`, add `OPERATIONAL_RELATIONS`

**Files:**

- Modify: `src/Repositories/AccessRequestRepository.php:42`
- Modify: `src/Handlers/Auth/AccessRequestHandler.php:41`
- Modify: `src/Handlers/Admin/PermissionAdminHandler.php:54` (+ docblock ~:375)
- Test: `phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`

**Interfaces:**

- Produces: `AccessRequestRepository::VALID_RELATIONS = ['admin','viewer','editor']`; new `AccessRequestRepository::OPERATIONAL_RELATIONS = ['viewer','editor']`.

- [ ] **Step 1: Update the constants test (failing)**

In `phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php` add/adjust:

```php
    public function testValidRelationsHasNoDeleter(): void
    {
        self::assertSame(['admin', 'viewer', 'editor'], AccessRequestRepository::VALID_RELATIONS);
    }

    public function testOperationalRelationsExcludeAdmin(): void
    {
        self::assertSame(['viewer', 'editor'], AccessRequestRepository::OPERATIONAL_RELATIONS);
        self::assertNotContains('admin', AccessRequestRepository::OPERATIONAL_RELATIONS);
    }
```

If a pre-existing test asserts `deleter` is present, update it to the new value.

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit the constants**

`AccessRequestRepository.php` line 42:

```php
    public const VALID_RELATIONS = ['admin', 'viewer', 'editor'];

    /**
     * Operational relations (everything except governance `admin`). Purged when a
     * resource's data is deleted; `admin` survives (it authorizes recreation).
     *
     * @var list<string>
     */
    public const OPERATIONAL_RELATIONS = ['viewer', 'editor'];
```

`AccessRequestHandler.php` line 41:

```php
    private const VALID_RELATIONS = ['admin', 'viewer', 'editor'];
```

`PermissionAdminHandler.php` line 54:

```php
    private const VALID_RELATIONS = ['admin', 'viewer', 'editor'];
```

Update the `PermissionAdminHandler` docblock near line 375 that lists `deleter` as a valid relation — remove `deleter` from the prose.

- [ ] **Step 4: Run targeted tests — expect pass**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`
Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/AccessRequestHandlerValidationTest.php`
Run: `vendor/bin/phpunit phpunit_tests/Services/RoleCascadeServiceTest.php` (it iterates `VALID_RELATIONS`)
Expected: PASS. If any test hardcodes `deleter` as valid, update it to expect rejection.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/AccessRequestRepository.php src/Handlers/Auth/AccessRequestHandler.php src/Handlers/Admin/PermissionAdminHandler.php phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php
git commit -m "feat(rbac): drop deleter from VALID_RELATIONS; add OPERATIONAL_RELATIONS"
```

---

## Task 4: Validation — prospective ISO national ids (+ VA)

**Files:**

- Modify: `src/Repositories/AccessRequestRepository.php` (`isValidObjectIdForType`, lines 97–108)
- Test: `phpunit_tests/Repositories/AccessRequestRepositoryTest.php`

**Interfaces:**

- Produces: `isValidObjectIdForType('national_calendar', $code)` is true iff `$code` is a valid ISO 3166-1 alpha-2 region (ICU) —
  including `VA` — even when no calendar exists yet; false for `XX`, `ZZ`, lowercase, arbitrary strings.

- [ ] **Step 1: Write failing tests**

Add to `phpunit_tests/Repositories/AccessRequestRepositoryTest.php`:

```php
    /** @dataProvider provideNationCodes */
    public function testNationalCalendarObjectIdValidation(string $code, bool $expected): void
    {
        self::assertSame($expected, AccessRequestRepository::isValidObjectIdForType('national_calendar', $code));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function provideNationCodes(): array
    {
        return [
            'IT existing'      => ['IT', true],
            'US existing'      => ['US', true],
            'NZ prospective'   => ['NZ', true],   // valid ISO, may have no calendar yet
            'VA vatican'       => ['VA', true],
            'ZZ unknown'       => ['ZZ', false],
            'XX private-use'   => ['XX', false],
            'lowercase it'     => ['it', false],
            'too long'         => ['ITA', false],
            'empty'            => ['', false],
            'arbitrary'        => ['FOO', false],
        ];
    }
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php --filter testNationalCalendarObjectIdValidation`
Expected: FAIL (`XX`/`ZZ`/`it`/`FOO` currently accepted as "any non-empty string").

- [ ] **Step 3: Implement the rule**

In `AccessRequestRepository::isValidObjectIdForType` add a `national_calendar` branch before the final `return $objectId !== '';`:

```php
        if ($objectType === 'national_calendar') {
            return self::isValidNationCode($objectId);
        }
```

Add the helper to the class:

```php
    /**
     * Valid ISO 3166-1 alpha-2 region code (per ICU), including the special VA.
     *
     * Accepts prospective nations (a valid code whose calendar does not exist
     * yet) so a national liturgy office can request `admin` to create it (#669);
     * rejects unknown/private-use codes (ZZ, XX), lowercase, and arbitrary strings.
     */
    private static function isValidNationCode(string $code): bool
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return false;
        }
        $display = \Locale::getDisplayRegion('und-' . $code, 'en');
        return $display !== $code && stripos($display, 'Unknown Region') === false;
    }
```

- [ ] **Step 4: Run — expect pass**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php --filter testNationalCalendarObjectIdValidation`
Expected: PASS.

- [ ] **Step 5: analyse + commit**

Run: `composer analyse` then:

```bash
git add src/Repositories/AccessRequestRepository.php phpunit_tests/Repositories/AccessRequestRepositoryTest.php
git commit -m "feat(rbac): accept prospective ISO national_calendar ids (+VA) in access-request validation"
```

---

## Task 5: `ResourceTuplePurgeService`

**Files:**

- Create: `src/Services/ResourceTuplePurgeService.php`
- Test: `phpunit_tests/Services/ResourceTuplePurgeServiceTest.php`

**Interfaces:**

- Consumes:
  - `OpenFgaClient::readTuples($user, $object, $relation, $limit, $token)` → `array{tuples: list<{user,relation,object}>,
    next_continuation_token: string}` (full signature in `src/Services/OpenFgaClient.php:348`)
  - `OutboxRepository::insertBatch(list<array{operation:OutboxOperation,fga_user:string,fga_relation:string,fga_object:string,idempotency_key:string,metadata:array}>): list<int>`
  - `OutboxProcessor::processSync(int): OutboxDisposition`
  - `AccessRequestRepository::OPERATIONAL_RELATIONS`
- Produces: `purgeForObject(string $fgaObject): int` — number of operational tuples enqueued. `admin` tuples are never enqueued.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use PDO;
use PHPUnit\Framework\TestCase;

class ResourceTuplePurgeServiceTest extends TestCase
{
    public function testPurgesOperationalTuplesAndRetainsAdmin(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples' => [
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'national_calendar:IT'],
                ['user' => 'user:b', 'relation' => 'viewer', 'object' => 'national_calendar:IT'],
                ['user' => 'user:c', 'relation' => 'admin',  'object' => 'national_calendar:IT'],
            ],
            'next_continuation_token' => '',
        ]);

        $repo = $this->createMock(OutboxRepository::class);
        $repo->expects($this->once())
            ->method('insertBatch')
            ->with($this->callback(function (array $rows): bool {
                // exactly the two operational tuples, never admin
                $relations = array_column($rows, 'fga_relation');
                sort($relations);
                return $relations === ['editor', 'viewer']
                    && array_unique(array_column($rows, 'operation')) === [OutboxOperation::DELETE_TUPLE];
            }))
            ->willReturn([10, 11]);

        $processor = $this->createMock(OutboxProcessor::class);
        $processor->expects($this->exactly(2))->method('processSync');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $service = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        $count   = $service->purgeForObject('national_calendar:IT');

        $this->assertSame(2, $count);
    }

    public function testNoOperationalTuplesIsNoOp(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples' => [['user' => 'user:c', 'relation' => 'admin', 'object' => 'national_calendar:IT']],
            'next_continuation_token' => '',
        ]);
        $repo = $this->createMock(OutboxRepository::class);
        $repo->expects($this->never())->method('insertBatch');
        $processor = $this->createMock(OutboxProcessor::class);
        $pdo       = $this->createMock(PDO::class);

        $service = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        $this->assertSame(0, $service->purgeForObject('national_calendar:IT'));
    }
}
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceTuplePurgeServiceTest.php`
Expected: FAIL ("Class ResourceTuplePurgeService not found").

- [ ] **Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use PDO;

/**
 * Purges OPERATIONAL OpenFGA tuples (editor/viewer) for a deleted resource by
 * enqueuing one DELETE_TUPLE outbox row per tuple, then processing them.
 *
 * Governance (`admin`) tuples are NEVER enumerated, so admin survives data
 * deletion (it authorizes recreating the resource — see #669). Shared by the
 * resource delete handlers and the reconciler sweep.
 */
final class ResourceTuplePurgeService
{
    public function __construct(
        private readonly OpenFgaClient $client,
        private readonly OutboxRepository $repo,
        private readonly OutboxProcessor $processor,
        private readonly PDO $db,
    ) {
    }

    /**
     * Enqueue + process DELETE_TUPLE rows for every operational tuple on $fgaObject.
     *
     * @param string $fgaObject Full FGA object, e.g. "national_calendar:IT".
     * @return int Number of operational tuples enqueued.
     */
    public function purgeForObject(string $fgaObject): int
    {
        /** @var list<array{user: string, relation: string, object: string}> $tuples */
        $tuples = [];
        $token  = null;
        do {
            $page   = $this->client->readTuples('', $fgaObject, null, null, $token);
            $tuples = array_merge($tuples, $page['tuples']);
            $token  = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        $rows = [];
        foreach ($tuples as $t) {
            if (!in_array($t['relation'], AccessRequestRepository::OPERATIONAL_RELATIONS, true)) {
                continue; // skip admin (governance) and anything non-operational
            }
            $rows[] = [
                'operation'       => OutboxOperation::DELETE_TUPLE,
                'fga_user'        => $t['user'],
                'fga_relation'    => $t['relation'],
                'fga_object'      => $t['object'],
                'idempotency_key' => "resource_purge:{$t['object']}:{$t['user']}:{$t['relation']}",
                'metadata'        => ['resource_purge' => true],
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $ids = $this->repo->insertBatch($rows);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        foreach ($ids as $id) {
            $this->processor->processSync($id);
        }

        return count($ids);
    }
}
```

- [ ] **Step 4: Run — expect pass; analyse**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceTuplePurgeServiceTest.php`
Expected: PASS.
Run: `composer analyse`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Services/ResourceTuplePurgeService.php phpunit_tests/Services/ResourceTuplePurgeServiceTest.php
git commit -m "feat(rbac): ResourceTuplePurgeService — enqueue DELETE_TUPLE per operational tuple, retain admin"
```

---

## Task 6: Wire purge into `RegionalDataHandler` delete

**Files:**

- Modify: `src/Handlers/RegionalDataHandler.php` (`deleteCalendar`, ~989–1072; constructor/helpers)
- Test: `phpunit_tests/Handlers/RegionalDataHandlerTest.php`

**Interfaces:**

- Consumes: `ResourceTuplePurgeService::purgeForObject()`; `OpenFgaClient::isConfigured()`; `PathCategory`.
- Produces: a test seam `setPurgeService(ResourceTuplePurgeService $s): void` and a lazy `getPurgeService(): ?ResourceTuplePurgeService` (null when OpenFGA unconfigured).

- [ ] **Step 1: Add the failing test**

Follow the existing `RegionalDataHandlerTest` setup (it builds a handler and a DELETE request and writes fixture files). Add:

```php
    public function testDeleteCalendarPurgesOperationalTuples(): void
    {
        // Arrange: create the national calendar fixture files the delete path expects
        // (mirror the existing delete test's fixture creation in this file).
        $handler = $this->makeHandlerForNationDelete('IT'); // existing helper or inline per this file's pattern

        $purge = $this->createMock(\LiturgicalCalendar\Api\Services\ResourceTuplePurgeService::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar:IT');
        $handler->setPurgeService($purge);

        $request = $this->makeAuthedDeleteRequest('/data/nation/IT'); // admin oidc_user per this file's pattern
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }
```

Note for implementer: reuse this test class's existing fixture/request helpers (see its current delete test). The assertion that
matters is `purgeForObject('national_calendar:IT')` is called exactly once after a successful delete.

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php --filter testDeleteCalendarPurgesOperationalTuples`
Expected: FAIL (`setPurgeService` undefined).

- [ ] **Step 3: Implement the seam + call**

Add imports and members to `RegionalDataHandler`:

```php
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Models\Connection; // confirm the Connection FQCN used elsewhere in this handler
```

```php
    private ?ResourceTuplePurgeService $purgeService = null;

    /** Test seam. */
    public function setPurgeService(ResourceTuplePurgeService $service): void
    {
        $this->purgeService = $service;
    }

    private function getPurgeService(): ?ResourceTuplePurgeService
    {
        if ($this->purgeService !== null) {
            return $this->purgeService;
        }
        if (!OpenFgaClient::isConfigured()) {
            return null;
        }
        $pdo                 = Connection::getInstance();
        $client              = OpenFgaClient::fromEnv();
        $repo                = new OutboxRepository($pdo);
        $processor           = new OutboxProcessor($repo, $client);
        $this->purgeService  = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        return $this->purgeService;
    }

    /** Map the delete path category to its FGA object type. */
    private function fgaObjectTypeForCategory(): ?string
    {
        return match ($this->params->category) {
            PathCategory::NATION      => 'national_calendar',
            PathCategory::DIOCESE     => 'diocesan_calendar',
            PathCategory::WIDERREGION => 'wider_region',
            default                   => null,
        };
    }
```

In `deleteCalendar`, AFTER the successful file/i18n deletion block and BEFORE building the success response (just before the `auditLogger->info('Calendar deleted', …)` call), insert:

```php
        $objectType = $this->fgaObjectTypeForCategory();
        $purge      = $this->getPurgeService();
        if ($objectType !== null && $purge !== null) {
            // Operational tuples (editor/viewer) are orphaned by the data deletion;
            // admin (governance) is intentionally retained so the resource can be recreated.
            $purge->purgeForObject("{$objectType}:{$this->params->key}");
        }
```

Confirm the exact `Connection` FQCN by grepping how `PermissionAdminHandler` imports it (`grep -n "use .*Connection" src/Handlers/Admin/PermissionAdminHandler.php`) and match it.

- [ ] **Step 4: Run — expect pass**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php`
Expected: PASS. Run `composer analyse` and `composer lint`.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/RegionalDataHandler.php phpunit_tests/Handlers/RegionalDataHandlerTest.php
git commit -m "feat(rbac): purge operational tuples on calendar delete (retain admin)"
```

---

## Task 7: Wire purge into `TestsHandler` delete

**Files:**

- Modify: `src/Handlers/TestsHandler.php` (`handleDeleteRequest`, 186–205)
- Test: `phpunit_tests/Handlers/TestsHandlerTest.php`

**Interfaces:**

- Consumes: `ResourceTuplePurgeService`, `TestScopeResolver::resolve(string): ?array{0:string,1:string}`.
- Produces: `setPurgeService()` seam on `TestsHandler`.

- [ ] **Step 1: Failing test**

Mirror the existing `TestsHandlerTest` delete fixture pattern. Add:

```php
    public function testDeletePurgesScopedTestOperationalTuples(): void
    {
        // Arrange a test fixture file "<name>.json" with applies_to national_calendar US
        // (mirror this file's existing delete test fixture creation).
        $handler = $this->makeTestsHandlerForDelete('some-test');

        $purge = $this->createMock(\LiturgicalCalendar\Api\Services\ResourceTuplePurgeService::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar_test:US');
        $handler->setPurgeService($purge);

        $request  = $this->makeAuthedDeleteRequest('/tests/some-test');
        $response = $handler->handle($request);
        $this->assertSame(204, $response->getStatusCode());
    }
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php --filter testDeletePurgesScopedTestOperationalTuples`
Expected: FAIL.

- [ ] **Step 3: Implement**

Add the same `$purgeService` property, `setPurgeService()`, and `getPurgeService()` helper as Task 6 (identical bodies — repeat them
in `TestsHandler`). Add `use LiturgicalCalendar\Api\Services\TestScopeResolver;` if not present.

In `handleDeleteRequest`, after a successful `unlink(...)` and before returning `204`, insert:

```php
            $scope = ( new TestScopeResolver() )->resolve($testName);
            $purge = $this->getPurgeService();
            if ($scope !== null && $purge !== null) {
                [$scopeType, $scopeId] = $scope;
                $purge->purgeForObject("{$scopeType}:{$scopeId}");
            }
```

(`$testName` is the variable already in scope at line 188.)

- [ ] **Step 4: Run — expect pass; analyse; lint**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php`
Run: `composer analyse && composer lint`
Expected: PASS / no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/TestsHandler.php phpunit_tests/Handlers/TestsHandler*.php
git commit -m "feat(rbac): purge scoped-test operational tuples on test delete (retain admin)"
```

---

## Task 8: `WiderRegionMembershipSeeder` + seed CLI

**Files:**

- Create: `src/Services/WiderRegionMembershipSeeder.php`
- Create: `scripts/seed-wider-region-membership.php`
- Test: `phpunit_tests/Services/WiderRegionMembershipSeederTest.php`

**Interfaces:**

- Consumes: nation JSON files (each has `metadata.wider_region`); `OpenFgaClient::writeTuple(string $user, string $relation, string $object): void`; `TupleAlreadyExistsException`.
- Produces: `computeTuples(string $nationsDir): list<array{user:string,relation:string,object:string}>` (pure; testable without
  FGA); `seed(OpenFgaClient $client, string $nationsDir, bool $apply): array{planned:int, written:int}`.

- [ ] **Step 1: Failing test (pure mapper)**

```php
<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;
use PHPUnit\Framework\TestCase;

class WiderRegionMembershipSeederTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wr_seed_' . uniqid();
        mkdir($this->dir . '/IT', 0777, true);
        mkdir($this->dir . '/US', 0777, true);
        mkdir($this->dir . '/XX', 0777, true); // no wider_region -> skipped
        file_put_contents($this->dir . '/IT/IT.json', json_encode(['metadata' => ['nation' => 'IT', 'wider_region' => 'Europe']]));
        file_put_contents($this->dir . '/US/US.json', json_encode(['metadata' => ['nation' => 'US', 'wider_region' => 'Americas']]));
        file_put_contents($this->dir . '/XX/XX.json', json_encode(['metadata' => ['nation' => 'XX']]));
    }

    protected function tearDown(): void
    {
        foreach (['IT', 'US', 'XX'] as $n) {
            @unlink("{$this->dir}/{$n}/{$n}.json");
            @rmdir("{$this->dir}/{$n}");
        }
        @rmdir($this->dir);
    }

    public function testComputeTuplesMapsNationsToRegions(): void
    {
        $tuples = ( new WiderRegionMembershipSeeder() )->computeTuples($this->dir);
        $this->assertContains(
            ['user' => 'national_calendar:IT', 'relation' => 'member_nation', 'object' => 'wider_region:Europe'],
            $tuples
        );
        $this->assertContains(
            ['user' => 'national_calendar:US', 'relation' => 'member_nation', 'object' => 'wider_region:Americas'],
            $tuples
        );
        $this->assertCount(2, $tuples); // XX skipped (no wider_region)
    }
}
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionMembershipSeederTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the seeder**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;

/**
 * Derives wider-region membership tuples from the national calendar source files
 * (each nation's `metadata.wider_region`) and writes them to OpenFGA:
 *   wider_region:<Region>#member_nation@national_calendar:<Nation>
 *
 * Membership powers the wider_region admin TTU (`admin from member_nation`), so a
 * national admin inherits admin on their wider region (#669).
 */
final class WiderRegionMembershipSeeder
{
    /**
     * @return list<array{user: string, relation: string, object: string}>
     */
    public function computeTuples(string $nationsDir): array
    {
        $tuples = [];
        $dirs   = glob($nationsDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $dir) {
            $nation = basename($dir);
            $file   = "{$dir}/{$nation}.json";
            if (!is_file($file)) {
                continue;
            }
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            /** @var array<string, mixed> $data */
            $data   = json_decode($raw, true) ?: [];
            $meta   = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $region = is_string($meta['wider_region'] ?? null) ? $meta['wider_region'] : '';
            if ($region === '') {
                continue;
            }
            $tuples[] = [
                'user'     => "national_calendar:{$nation}",
                'relation' => 'member_nation',
                'object'   => "wider_region:{$region}",
            ];
        }
        return $tuples;
    }

    /**
     * @return array{planned: int, written: int}
     */
    public function seed(OpenFgaClient $client, string $nationsDir, bool $apply): array
    {
        $tuples  = $this->computeTuples($nationsDir);
        $written = 0;
        if ($apply) {
            foreach ($tuples as $t) {
                try {
                    $client->writeTuple($t['user'], $t['relation'], $t['object']);
                    ++$written;
                } catch (TupleAlreadyExistsException) {
                    // benign — already seeded
                }
            }
        }
        return ['planned' => count($tuples), 'written' => $written];
    }
}
```

- [ ] **Step 4: Run — expect pass; analyse**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionMembershipSeederTest.php`
Run: `composer analyse`
Expected: PASS / no errors.

- [ ] **Step 5: Create the CLI**

`scripts/seed-wider-region-membership.php` — mirror `scripts/migrate-test-tuples.php` bootstrap (Dotenv, `OpenFgaClient::isConfigured()`/`fromEnv()`, `--apply` flag). Body:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;

$projectRoot = dirname(__DIR__);
Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
)->safeLoad();

$apply = in_array('--apply', $argv, true);
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN (pass --apply to write)') . PHP_EOL . PHP_EOL;

if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured.\n");
    exit(1);
}

$client     = OpenFgaClient::fromEnv();
$seeder     = new WiderRegionMembershipSeeder();
$nationsDir = JsonData::NATIONAL_CALENDARS_FOLDER->path();

foreach ($seeder->computeTuples($nationsDir) as $t) {
    echo "{$t['object']}#member_nation@{$t['user']}" . PHP_EOL;
}
$result = $seeder->seed($client, $nationsDir, $apply);
echo PHP_EOL . sprintf("Planned: %d  Written: %d\n", $result['planned'], $result['written']);
exit(0);
```

Confirm `JsonData::NATIONAL_CALENDARS_FOLDER->path()` is the correct enum case (grep `NATIONAL_CALENDARS_FOLDER` in `src/Enum/JsonData.php`).

- [ ] **Step 6: Smoke-run dry mode + commit**

Run: `php scripts/seed-wider-region-membership.php` (dry run; prints planned tuples). Note the CLI exits 1 if OpenFGA is
unconfigured, so skip this smoke run when FGA isn't configured locally.

```bash
git add src/Services/WiderRegionMembershipSeeder.php scripts/seed-wider-region-membership.php phpunit_tests/Services/WiderRegionMembershipSeederTest.php
git commit -m "feat(rbac): wider-region membership seeder + CLI (member_nation tuples)"
```

---

## Task 9: National create → enqueue `member_nation` sync

**Files:**

- Modify: `src/Handlers/RegionalDataHandler.php` (`createNationalCalendar`, 414+)
- Test: `phpunit_tests/Handlers/RegionalDataHandlerTest.php`

**Interfaces:**

- Consumes: the lazy outbox accessors from Task 6; the new national calendar's `metadata.wider_region`.
- Produces: on successful national create with a wider_region, a `WRITE_TUPLE` outbox row with
  `fga_user="national_calendar:<N>"`, `fga_relation="member_nation"`, `fga_object="wider_region:<R>"`.

- [ ] **Step 1: Failing test**

Add an injectable outbox-repo seam to the handler so the test can observe the enqueue. Add:

```php
    private ?OutboxRepository $outboxRepoOverride = null;
    public function setOutboxRepository(OutboxRepository $repo): void { $this->outboxRepoOverride = $repo; }
```

and have a private `getOutboxRepository()` prefer the override (else `new OutboxRepository(Connection::getInstance())`). Test:

```php
    public function testCreateNationalCalendarEnqueuesMemberNationTuple(): void
    {
        $handler = $this->makeHandlerForNationCreate('IT', 'Europe'); // build PUT request + NationalData payload per this file's pattern

        $repo = $this->createMock(\LiturgicalCalendar\Api\Repositories\OutboxRepository::class);
        $repo->expects($this->atLeastOnce())
            ->method('insertBatch')
            ->with($this->callback(function (array $rows): bool {
                foreach ($rows as $r) {
                    if (
                        $r['fga_user'] === 'national_calendar:IT'
                        && $r['fga_relation'] === 'member_nation'
                        && $r['fga_object'] === 'wider_region:Europe'
                    ) {
                        return true;
                    }
                }
                return false;
            }))
            ->willReturn([99]);
        $handler->setOutboxRepository($repo);

        $response = $handler->handle($this->makeAuthedPutRequest('/data/nation/IT'));
        $this->assertContains($response->getStatusCode(), [200, 201]);
    }
```

If FGA-conditional wiring would skip enqueue when OpenFGA is unconfigured in tests, gate the enqueue so it also runs when an outbox
override is present. Simplest: guard with `OpenFgaClient::isConfigured() || $this->outboxRepoOverride !== null`.

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php --filter testCreateNationalCalendarEnqueuesMemberNationTuple`
Expected: FAIL.

- [ ] **Step 3: Implement enqueue**

In `createNationalCalendar`, after the national calendar file is written successfully and before the audit log, read the wider_region from the validated payload metadata and enqueue:

```php
        $widerRegion = is_string($payload->metadata->wider_region ?? null) ? $payload->metadata->wider_region : '';
        if ($widerRegion !== '' && ( OpenFgaClient::isConfigured() || $this->outboxRepoOverride !== null )) {
            $pdo  = Connection::getInstance();
            $repo = $this->getOutboxRepository();
            $row  = [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => "national_calendar:{$nation}",
                'fga_relation'    => 'member_nation',
                'fga_object'      => "wider_region:{$widerRegion}",
                'idempotency_key' => "member_nation:wider_region:{$widerRegion}:national_calendar:{$nation}",
                'metadata'        => ['member_nation_seed' => true],
            ];
            $pdo->beginTransaction();
            try {
                $ids = $repo->insertBatch([$row]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            if (OpenFgaClient::isConfigured()) {
                $processor = new OutboxProcessor($repo, OpenFgaClient::fromEnv());
                foreach ($ids as $id) {
                    $processor->processSync($id);
                }
            }
        }
```

Confirm `$payload->metadata->wider_region` is the correct accessor on the `NationalData` model (grep `wider_region` in
`src/Models/RegionalData/` / `NationalData`). Adjust the path if the property differs (e.g. `metadata->settings`).

- [ ] **Step 4: Run — expect pass; analyse; lint**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php`
Run: `composer analyse && composer lint`
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/RegionalDataHandler.php phpunit_tests/Handlers/RegionalDataHandlerTest.php
git commit -m "feat(rbac): enqueue member_nation tuple when a national calendar is created"
```

---

## Task 10: `ResourceExistenceChecker`

**Files:**

- Create: `src/Services/ResourceExistenceChecker.php`
- Test: `phpunit_tests/Services/ResourceExistenceCheckerTest.php`

**Interfaces:**

- Produces: `exists(string $objectType, string $objectId): bool` and `isResourceType(string $objectType): bool`. GRC types always
  "exist"; calendar/test types resolve to a backing file path.

- [ ] **Step 1: Failing test**

```php
<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use PHPUnit\Framework\TestCase;

class ResourceExistenceCheckerTest extends TestCase
{
    public function testGrcAlwaysExists(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertTrue($checker->exists('general_roman_calendar', 'temporale'));
    }

    public function testNonResourceTypeIsNotAResourceType(): void
    {
        $checker = new ResourceExistenceChecker();
        $this->assertFalse($checker->isResourceType('user'));
        $this->assertTrue($checker->isResourceType('national_calendar'));
    }

    public function testMissingNationalCalendarDoesNotExist(): void
    {
        $checker = new ResourceExistenceChecker();
        // 'ZZ' has no folder under jsondata/sourcedata/calendars/nations
        $this->assertFalse($checker->exists('national_calendar', 'ZZ'));
    }
}
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceExistenceCheckerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Decides whether the backing data for an OpenFGA object still exists on disk.
 * Used by the reconciler sweep to distinguish orphaned operational tuples from
 * live ones. GRC fixed objects always exist; unknown types are not resources.
 */
final class ResourceExistenceChecker
{
    private const RESOURCE_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'general_roman_calendar',
        'national_calendar_test',
        'diocesan_calendar_test',
        'general_roman_calendar_test',
    ];

    public function isResourceType(string $objectType): bool
    {
        return in_array($objectType, self::RESOURCE_TYPES, true);
    }

    public function exists(string $objectType, string $objectId): bool
    {
        switch ($objectType) {
            case 'general_roman_calendar':
            case 'general_roman_calendar_test':
                return true; // fixed catalog ids — always present
            case 'national_calendar':
                return is_file(JsonData::NATIONAL_CALENDARS_FOLDER->path() . "/{$objectId}/{$objectId}.json");
            case 'wider_region':
                return is_dir(JsonData::WIDER_REGIONS_FOLDER->path() . "/{$objectId}");
            case 'diocesan_calendar':
                // diocesan files live under nations/<NATION>/<dioceseId>.json; existence by glob.
                $matches = glob(JsonData::NATIONAL_CALENDARS_FOLDER->path() . "/*/{$objectId}.json");
                return $matches !== false && $matches !== [];
            case 'national_calendar_test':
            case 'diocesan_calendar_test':
                // scoped test objects are governance scopes, not files — treat as existing
                // unless you wire a registry; default to true to avoid false purges.
                return true;
            default:
                return false;
        }
    }
}
```

Confirm the `JsonData` enum cases (`NATIONAL_CALENDARS_FOLDER`, `WIDER_REGIONS_FOLDER`) by grepping `src/Enum/JsonData.php`; adjust
names to match. If `WIDER_REGIONS_FOLDER` differs, use the actual case.

- [ ] **Step 4: Run — expect pass; analyse**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceExistenceCheckerTest.php`
Run: `composer analyse`
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add src/Services/ResourceExistenceChecker.php phpunit_tests/Services/ResourceExistenceCheckerTest.php
git commit -m "feat(rbac): ResourceExistenceChecker for reconciler sweep"
```

---

## Task 11: `ResourceTuplePurgeReconciler` + reconcile CLI

**Files:**

- Create: `src/Services/Outbox/ResourceTuplePurgeReconciler.php`
- Create: `scripts/reconcile-resource-tuples.php`
- Test: `phpunit_tests/Services/Outbox/ResourceTuplePurgeReconcilerTest.php`

**Interfaces:**

- Consumes: `OpenFgaClient::readTuples` (full scan), `ResourceExistenceChecker`, `ResourceTuplePurgeService::purgeForObject`, `AccessRequestRepository::OPERATIONAL_RELATIONS`.
- Produces: `sweep(): array{scanned:int, purgedObjects:int, enqueued:int}` — for each distinct object of a resource type whose
  resource is gone and which has ≥1 operational tuple, calls `purgeForObject` once; ignores `admin` tuples.

- [ ] **Step 1: Failing test**

```php
<?php

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use LiturgicalCalendar\Api\Services\Outbox\ResourceTuplePurgeReconciler;
use PHPUnit\Framework\TestCase;

class ResourceTuplePurgeReconcilerTest extends TestCase
{
    public function testPurgesOnlyDeletedResourcesWithOperationalTuplesIgnoringAdmin(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples' => [
                // deleted resource (ZZ) with operational tuple -> should purge
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'national_calendar:ZZ'],
                // deleted resource (ZZ) admin tuple -> must be ignored (no purge trigger by itself)
                ['user' => 'user:b', 'relation' => 'admin', 'object' => 'national_calendar:ZZ'],
                // GRC always exists -> never purge
                ['user' => 'user:c', 'relation' => 'editor', 'object' => 'general_roman_calendar:temporale'],
            ],
            'next_continuation_token' => '',
        ]);

        $checker = $this->createMock(ResourceExistenceChecker::class);
        $checker->method('isResourceType')->willReturn(true);
        $checker->method('exists')->willReturnCallback(
            fn (string $t, string $id): bool => !($t === 'national_calendar' && $id === 'ZZ')
        );

        $purge = $this->createMock(ResourceTuplePurgeService::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar:ZZ')
            ->willReturn(1);

        $reconciler = new ResourceTuplePurgeReconciler($client, $checker, $purge);
        $result     = $reconciler->sweep();

        $this->assertSame(1, $result['purgedObjects']);
    }
}
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/ResourceTuplePurgeReconcilerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;

/**
 * Defense-in-depth sweep: finds OPERATIONAL tuples whose backing resource no
 * longer exists and purges them via ResourceTuplePurgeService. `admin` tuples
 * on deleted resources are intentional governance and are never purged.
 *
 * Cron-able; intentionally off the hot ConsumerLoop/Backstop path (full scan).
 */
final class ResourceTuplePurgeReconciler
{
    public function __construct(
        private readonly OpenFgaClient $client,
        private readonly ResourceExistenceChecker $checker,
        private readonly ResourceTuplePurgeService $purge,
    ) {
    }

    /**
     * @return array{scanned: int, purgedObjects: int, enqueued: int}
     */
    public function sweep(): array
    {
        /** @var list<array{user: string, relation: string, object: string}> $tuples */
        $tuples = [];
        $token  = null;
        do {
            $page   = $this->client->readTuples('', '', null, null, $token);
            $tuples = array_merge($tuples, $page['tuples']);
            $token  = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        // Collect objects that have at least one operational tuple.
        $objectsWithOperational = [];
        foreach ($tuples as $t) {
            if (in_array($t['relation'], AccessRequestRepository::OPERATIONAL_RELATIONS, true)) {
                $objectsWithOperational[$t['object']] = true;
            }
        }

        $purgedObjects = 0;
        $enqueued      = 0;
        foreach (array_keys($objectsWithOperational) as $object) {
            $colon = strpos($object, ':');
            if ($colon === false) {
                continue;
            }
            $type = substr($object, 0, $colon);
            $id   = substr($object, $colon + 1);
            if (!$this->checker->isResourceType($type)) {
                continue;
            }
            if ($this->checker->exists($type, $id)) {
                continue; // resource still present — operational tuples are live
            }
            $enqueued += $this->purge->purgeForObject($object);
            ++$purgedObjects;
        }

        return ['scanned' => count($tuples), 'purgedObjects' => $purgedObjects, 'enqueued' => $enqueued];
    }
}
```

- [ ] **Step 4: Run — expect pass; analyse**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/ResourceTuplePurgeReconcilerTest.php`
Run: `composer analyse`
Expected: PASS / clean.

- [ ] **Step 5: Create the reconcile CLI**

`scripts/reconcile-resource-tuples.php` — same bootstrap as the seed CLI. Wire (one statement per line):

```php
$client     = OpenFgaClient::fromEnv();
$pdo        = Connection::getInstance();
$repo       = new OutboxRepository($pdo);
$processor  = new OutboxProcessor($repo, $client);
$purge      = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
$reconciler = new ResourceTuplePurgeReconciler($client, new ResourceExistenceChecker(), $purge);
```

Then, when `--apply`, `$result = $reconciler->sweep();` and print the summary. In dry-run mode, print "pass --apply to enqueue
purges" and exit without calling `sweep()` (the sweep enqueues; keep dry-run side-effect-free for v1). Confirm the `Connection` FQCN
by matching how `PermissionAdminHandler` imports it.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/ResourceTuplePurgeReconciler.php scripts/reconcile-resource-tuples.php phpunit_tests/Services/Outbox/ResourceTuplePurgeReconcilerTest.php
git commit -m "feat(rbac): reconciler sweep for orphaned operational tuples (+CLI)"
```

---

## Task 12: `DeleterTupleMapper` + migrate-deleter-tuples CLI

**Files:**

- Create: `src/Services/DeleterTupleMapper.php`
- Create: `scripts/migrate-deleter-tuples.php`
- Test: `phpunit_tests/Services/DeleterTupleMapperTest.php`

**Interfaces:**

- Produces: `DeleterTupleMapper::mapTuple(array{user,relation,object}): ?array{user,relation,object}` — maps a `deleter` tuple to
  the same user/object with relation `admin`; returns null for non-`deleter` tuples.

- [ ] **Step 1: Failing test**

```php
<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\DeleterTupleMapper;
use PHPUnit\Framework\TestCase;

class DeleterTupleMapperTest extends TestCase
{
    public function testMapsDeleterToAdmin(): void
    {
        $mapped = ( new DeleterTupleMapper() )->mapTuple(
            ['user' => 'user:x', 'relation' => 'deleter', 'object' => 'national_calendar:IT']
        );
        $this->assertSame(['user' => 'user:x', 'relation' => 'admin', 'object' => 'national_calendar:IT'], $mapped);
    }

    public function testIgnoresNonDeleterTuples(): void
    {
        $this->assertNull(
            ( new DeleterTupleMapper() )->mapTuple(['user' => 'user:x', 'relation' => 'editor', 'object' => 'national_calendar:IT'])
        );
    }
}
```

- [ ] **Step 2: Run — expect failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/DeleterTupleMapperTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Pure mapper: a `deleter` tuple → the equivalent `admin` tuple (#668 folds
 * delete into admin). Non-`deleter` tuples map to null and are skipped.
 */
final class DeleterTupleMapper
{
    /**
     * @param array{user: string, relation: string, object: string} $tuple
     * @return array{user: string, relation: string, object: string}|null
     */
    public function mapTuple(array $tuple): ?array
    {
        if ($tuple['relation'] !== 'deleter') {
            return null;
        }
        return ['user' => $tuple['user'], 'relation' => 'admin', 'object' => $tuple['object']];
    }
}
```

- [ ] **Step 4: Run — expect pass; analyse**

Run: `vendor/bin/phpunit phpunit_tests/Services/DeleterTupleMapperTest.php`
Run: `composer analyse`
Expected: PASS / clean.

- [ ] **Step 5: Create the migration CLI**

`scripts/migrate-deleter-tuples.php` — copy `scripts/migrate-test-tuples.php` and adapt: enumerate all tuples (full scan, paginated),
for each call `DeleterTupleMapper::mapTuple`; on `--apply` write the mapped `admin` tuple (`TupleAlreadyExistsException` benign) then
delete the original `deleter` tuple (`TupleNotFoundException` benign); dry-run prints `[DRY RUN] {object}#deleter → #admin`. Summary
counts. No "unresolved" concept (mapping is total for deleter tuples). Use the same Dotenv bootstrap and `OpenFgaClient::isConfigured()`
guard.

- [ ] **Step 6: Commit**

```bash
git add src/Services/DeleterTupleMapper.php scripts/migrate-deleter-tuples.php phpunit_tests/Services/DeleterTupleMapperTest.php
git commit -m "feat(rbac): migrate deleter tuples to admin (mapper + CLI)"
```

---

## Task 13: OpenAPI — drop `deleter`, document create semantics

**Files:**

- Modify: `jsondata/schemas/openapi.json`

**Interfaces:** none (schema only).

- [ ] **Step 1: Remove `deleter` from the six locations**

Edit `jsondata/schemas/openapi.json`, removing the `"deleter"` enum entry at (approx) lines 2110–2115, 2473–2483, 7417–7426,
7826–7833, 7870–7877, and fix the response example at ~2169–2177 (change the example tuple `"relation": "deleter"` to
`"relation": "admin"`). Search to confirm none remain:

Run: `grep -n '"deleter"' jsondata/schemas/openapi.json`
Expected: no matches.

- [ ] **Step 2: Document create semantics**

In the `/data/{category}/{calendar}` and `/missals` PUT/PATCH/DELETE operation descriptions, add a sentence: "Create (`PUT`) requires
the `admin` relation (calendars/missals); edit (`PATCH`) requires `editor`; `DELETE` requires `admin`. `admin` implies `editor` and
`viewer`." For `/tests` PUT, note create requires `editor`. (Edit the existing `description` strings; do not restructure paths.)

- [ ] **Step 3: Lint the schema**

Run: `composer lint:openapi`
Expected: passes (no new errors vs. baseline; pre-existing external-$ref oasdiff noise is unrelated).

- [ ] **Step 4: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs(openapi): drop deleter relation enums; document create semantics + admin superset"
```

---

## Task 14: Runbook + full verification sweep

**Files:**

- Create: `docs/ops/rbac-create-governance-runbook.md`

- [ ] **Step 1: Write the runbook**

Create `docs/ops/rbac-create-governance-runbook.md` documenting the no-downtime rollout (markdown must pass `markdownlint-cli2`; aligned tables, ≤180-col prose):

1. Apply the additive model: push `scripts/openfga-model.additive.json` to the OpenFGA store (union rewrites + `member_nation`, `deleter` retained).
2. Deploy the API (this branch).
3. Run `php scripts/seed-wider-region-membership.php --apply`.
4. Run `php scripts/migrate-deleter-tuples.php` (dry run), inspect, then `--apply`.
5. Deploy the coordinated frontend PR (removes the `deleter` option).
6. Apply the final model: push `scripts/openfga-model.json` (drops `deleter`).
7. Schedule `php scripts/reconcile-resource-tuples.php --apply` daily (cron).

Note that admins bypass OpenFGA throughout, so the window is non-breaking. Note the governance chain: a system admin approves the
first `admin` grant on a (possibly non-existent) national calendar; thereafter the national admin self-governs scoped editor/viewer
requests, and inherits `wider_region` admin via membership.

- [ ] **Step 2: Lint markdown**

Run: `npx --yes markdownlint-cli2 "docs/ops/rbac-create-governance-runbook.md"`
Expected: `0 error(s)`.

- [ ] **Step 3: Full verification**

Run: `composer lint`
Run: `composer analyse`
Run: `composer test`
Expected: all green. Fix any regressions before committing. If `composer test` requires a running API / DB and some suites skip
(per the layered base classes), that is expected; ensure no failures (skips are acceptable).

- [ ] **Step 4: Commit + open PR**

```bash
git add docs/ops/rbac-create-governance-runbook.md
git commit -m "docs(ops): RBAC create-governance rollout runbook"
git push -u origin feat/rbac-create-governance
gh pr create --base development --title "RBAC: create-governance + admin-superset (#668, #669)" --body "Implements #668 and #669 per docs/superpowers/specs/2026-06-25-issues-668-669-rbac-create-governance-design.md. Frontend deleter-removal is a coordinated follow-up. Diocesan create-governance deferred."
```

---

## Self-review notes (addressed)

- **Spec coverage:** model rewrites + drop deleter (T1), per-resource create map (T2), constants (T3), prospective national
  validation (T4), purge service (T5), delete-path purge (T6/T7), wider_region membership seed + create-sync (T8/T9), reconciler
  sweep (T10/T11), deleter→admin migration (T12), OpenAPI (T13), runbook + additive model (T1/T14). National-missal coupling is the
  existing `forMissals` mapping — no task needed (documented in T13). Approval routing needs no code (system admin is already the
  only approver) — documented in T14.
- **Deferred (no tasks, by design):** diocesan create-governance; frontend `deleter` removal.
- **Type consistency:** `purgeForObject(string): int`, `computeTuples(string): list<tuple>`, `mapTuple(array): ?array`,
  `exists(string,string): bool`, `sweep(): array{...}` are used identically across producing and consuming tasks.
- **Known confirmations for the implementer (grep before editing):** the `Connection` FQCN used by handlers; `JsonData` enum case
  names (`NATIONAL_CALENDARS_FOLDER`, `WIDER_REGIONS_FOLDER`); the `NationalData` payload accessor for `wider_region`; and the
  existing `RegionalDataHandlerTest`/`TestsHandlerTest` fixture/request helpers to mirror.
