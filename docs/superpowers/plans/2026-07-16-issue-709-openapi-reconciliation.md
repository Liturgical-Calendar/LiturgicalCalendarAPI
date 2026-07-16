# Issue #709 — OpenAPI Response Schema Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repoint the `/calendars`, `/easter`, and `/tests` OpenAPI response schemas at their authoritative
standalone schema files, document the missing `/schemas` path, remove the two orphaned inline components, and
lock the reconciliation in CI with in-process response-validation tests.

**Architecture:** `jsondata/schemas/openapi.json` is the documentation layer; the standalone files in
`jsondata/schemas/` are the validation layer (Health checks map every route to its file via `LitSchema`). All
five files already validate live responses, so this plan changes ONLY openapi.json plus one new test class —
no schema file content changes. Approved spec:
`docs/superpowers/specs/2026-07-16-issue-709-openapi-reconciliation-design.md`.

**Tech Stack:** OpenAPI 3.1 (`openapi.json`), JSON Schema draft-07, PHP 8.4, PHPUnit, swaggest/json-schema,
Redocly CLI (`composer lint:openapi`).

## Global Constraints

- **Working directory:** the git worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-wt-709`
  on branch `feature/709-openapi-schema-reconciliation`. Every command below runs from there. Verify with
  `git rev-parse --show-toplevel` before each task's first command — it MUST print the worktree path, never
  `.../LiturgicalCalendarAPI` (the main checkout is shared and off-limits).
- **Never bypass git hooks.** No `--no-verify`. Commits are GPG-signed; on `gpg: signing failed: Timeout`,
  STOP and report BLOCKED (never disable signing).
- **Do NOT change any standalone schema file** (`LitCalMetadata.json`, `LitCalEasterPath.json`,
  `LitCalTestsPath.json`, `LitCalSchemasPath.json`, `LitCalDataPath.json`) — they are authoritative as-is.
- openapi.json is hand-formatted with 2-space indentation — make surgical text edits only; NEVER round-trip
  the whole file through a JSON serializer (it would reformat every line).
- The worktree has `.env`/`.env.local`, so JWT-gated handler tests genuinely run. A phpunit run that reports
  "Skipped" is NOT a pass — report exact tallies.
- Test code must pass phpcs (`vendor/bin/phpcs <file>`); commit messages end with
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Reconcile openapi.json

**Files:**

- Modify: `jsondata/schemas/openapi.json` (line numbers below are pre-edit positions; they shift as you edit —
  work top-to-bottom is NOT required, but re-locate each target by its quoted content, not by stale line numbers)

**Interfaces:**

- Consumes: nothing from other tasks.
- Produces: `paths./schemas` documented; `/calendars`, `/easter`, `/tests` 200 schemas as file `$ref`s;
  components `LitCalMetadata` and `UnitTestArray` deleted. Task 2's tests are independent of these edits but
  Task 3 verifies the whole file.

- [ ] **Step 1: Repoint `/calendars` GET and POST (2 identical edits, lines ~3476 and ~3507)**

Replace BOTH occurrences of:

```json
                  "$ref": "#/components/schemas/LitCalMetadata"
```

with:

```json
                  "$ref": "./LitCalMetadata.json"
```

(They are the only two occurrences in the file; verify with
`grep -c '#/components/schemas/LitCalMetadata' jsondata/schemas/openapi.json` → expect `0` afterwards.)

- [ ] **Step 2: Replace the `/easter` inline response schema (lines ~4678–4731)**

In the `/easter` GET 200 response, replace the entire inline `"schema"` object — it starts at
`"schema": {` followed by `"type": "object"`, contains the `"EasterDates"`, `"lastCoincidenceString"`
(const `"Sunday, April 24th, 2698"`), and `"lastCoincidence"` (const `22983264000`) properties, and ends with
the closing brace immediately before `"example": {` — with:

```json
                "schema": {
                  "$ref": "./LitCalEasterPath.json"
                },
```

Do not touch the 200 `description` line above it.

- [ ] **Step 3: Fix the `/easter` example property name (line ~4733)**

In the `"example"` object immediately following, replace the key:

```json
                  "EasterDates": [
```

with:

```json
                  "litcal_easter": [
```

(The API returns `litcal_easter`; the example must match the schema it now refs.)

- [ ] **Step 4: Repoint `/tests` GET (line ~5122)**

Replace:

```json
                "schema": {
                  "$ref": "#/components/schemas/UnitTestArray"
                },
```

with:

```json
                "schema": {
                  "$ref": "./LitCalTestsPath.json"
                },
```

- [ ] **Step 5: Wrap the `/tests` example in the `litcal_tests` envelope**

The `"example"` immediately after Step 4's edit is a bare array (starts `"example": [` at line ~5124, ends
with the matching `]` before the closing of the `application/json` object). The response is actually
`{"litcal_tests": [...]}`. Wrap it: the opening becomes

```json
                "example": {
                  "litcal_tests": [
```

the closing `]` gains a wrapping `}` at the matching indent, and every line of the array body is re-indented
by 2 extra spaces. Do this mechanically (e.g. a small Python text-manipulation over the exact line range) —
then verify:

```bash
python3 -c "
import json
spec = json.load(open('jsondata/schemas/openapi.json'))
ex = spec['paths']['/tests']['get']['responses']['200']['content']['application/json']['example']
assert list(ex.keys()) == ['litcal_tests'], ex.keys()
assert isinstance(ex['litcal_tests'], list) and len(ex['litcal_tests']) >= 2
print('tests example OK')
"
```

- [ ] **Step 6: Add the `/schemas` path**

Insert this block into `"paths"`, after the closing of the `"/tests/{test_name}"` entry and before
`"/temporale": {` (matching the file's 4-space path-level indentation):

```json
    "/schemas": {
      "get": {
        "tags": [
          "Schemas"
        ],
        "security": [
          {}
        ],
        "summary": "Retrieve an index of the JSON schema resources that define API request and response shapes and source data files",
        "operationId": "schemasIndexGET",
        "responses": {
          "200": {
            "description": "OK: an index of the JSON schema resources served by the API",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "./LitCalSchemasPath.json"
                }
              }
            }
          }
        }
      }
    },
```

- [ ] **Step 7: Add the `Schemas` tag**

In the top-level `"tags"` array, insert after the `"Unit Tests"` tag entry (mirroring path order):

```json
    {
      "name": "Schemas",
      "description": "Retrieve the JSON schemas that define API request and response shapes and source data files"
    },
```

- [ ] **Step 8: Delete the two orphaned components**

In `"components" → "schemas"`, delete the entire `"LitCalMetadata": { ... },` entry and the entire
`"UnitTestArray": { ... },` entry (each is a complete key-value pair; read the file to find each entry's full
extent, and keep the surrounding entries' comma structure valid). Do NOT touch
`ExactCorrespondenceUnitTest`, `ExactCorrespondenceSinceUnitTest`, `ExactCorrespondenceUntilUnitTest` — they
retain 3 references each elsewhere in the file.

- [ ] **Step 9: Verify the whole edit set**

```bash
python3 -c "
import json
spec = json.load(open('jsondata/schemas/openapi.json'))
text = json.dumps(spec)
assert '#/components/schemas/LitCalMetadata\"' not in text
assert '#/components/schemas/UnitTestArray\"' not in text
assert 'LitCalMetadata' not in spec['components']['schemas']
assert 'UnitTestArray' not in spec['components']['schemas']
for name in ('ExactCorrespondenceUnitTest','ExactCorrespondenceSinceUnitTest','ExactCorrespondenceUntilUnitTest'):
    assert name in spec['components']['schemas'], name
    assert text.count('#/components/schemas/' + name + '\"') == 3, name
assert spec['paths']['/calendars']['get']['responses']['200']['content']['application/json']['schema'] == {'\$ref': './LitCalMetadata.json'}
assert spec['paths']['/calendars']['post']['responses']['200']['content']['application/json']['schema'] == {'\$ref': './LitCalMetadata.json'}
assert spec['paths']['/easter']['get']['responses']['200']['content']['application/json']['schema'] == {'\$ref': './LitCalEasterPath.json'}
assert list(spec['paths']['/easter']['get']['responses']['200']['content']['application/json']['example'].keys()) == ['litcal_easter']
assert spec['paths']['/tests']['get']['responses']['200']['content']['application/json']['schema'] == {'\$ref': './LitCalTestsPath.json'}
assert spec['paths']['/schemas']['get']['responses']['200']['content']['application/json']['schema'] == {'\$ref': './LitCalSchemasPath.json'}
assert any(t['name'] == 'Schemas' for t in spec['tags'])
print('openapi reconciliation OK')
"
```

Expected: `openapi reconciliation OK`.

- [ ] **Step 10: Lint**

Run: `composer lint:openapi`
Expected: "Your API description is valid" (exit 0, no new warnings vs the development branch).

- [ ] **Step 11: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "fix(openapi): ref authoritative response schema files for /calendars, /easter, /tests, /schemas (#709)

The inline copies had drifted: /easter documented an EasterDates
property (the API returns litcal_easter), /tests documented a bare
array (the API returns a litcal_tests wrapper), and the LitCalMetadata
component was missing locales/timezone/api_path among others. The
standalone schema files are validated against live responses by Health
checks and are authoritative; openapi.json now refs them. The /schemas
index path is documented for the first time, and the two orphaned
components (LitCalMetadata, UnitTestArray) are removed.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: In-process response-validation tests

**Files:**

- Create: `phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php`

**Interfaces:**

- Consumes (existing code): `AbstractHandlerTestCase` (namespace `LiturgicalCalendar\Tests\Handlers`;
  provides `requestFor(string $method, string $uri, array $headers = [], array|string|null $body = null)`
  and saves/restores `Router::$apiPath` around the class); `LitSchema::METADATA|EASTER|TESTS|SCHEMAS->path()`;
  handlers `MetadataHandler`, `EasterHandler`, `TestsHandler`, `SchemasHandler` (all no-arg constructors for
  index GETs, verified against their existing tests).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the test class**

Create `phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php` with exactly:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Handlers\EasterHandler;
use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use LiturgicalCalendar\Api\Handlers\SchemasHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Router;
use Psr\Http\Server\RequestHandlerInterface;
use Swaggest\JsonSchema\Schema;

/**
 * Validates real handler output for the read-only index routes against the
 * standalone response schema files that openapi.json refs (issue #709).
 * Health checks perform the same validation, but only via the external
 * WebSocket test interface; these tests put it in CI.
 */
final class ReadonlyPathsResponseSchemaTest extends AbstractHandlerTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The base class pins Router::$apiPath to '' for stable self-links;
        // LitCalMetadata.json and LitCalSchemasPath.json constrain URL
        // properties with strict absolute-URL patterns, so emit a
        // production-like base URL instead. The parent's tearDownAfterClass()
        // restores the saved value.
        Router::$apiPath = 'http://localhost:8000';
    }

    private function validateHandlerResponse(RequestHandlerInterface $handler, string $route, LitSchema $schema): void
    {
        $resp = $handler->handle($this->requestFor('GET', $route, ['Accept-Language' => 'en']));
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody());
        assert($body instanceof \stdClass);
        Schema::import($schema->path())->in($body);
        $this->addToAssertionCount(1);
    }

    public function testCalendarsResponseValidatesAgainstMetadataSchema(): void
    {
        $this->validateHandlerResponse(new MetadataHandler(), '/calendars', LitSchema::METADATA);
    }

    public function testEasterResponseValidatesAgainstEasterPathSchema(): void
    {
        $this->validateHandlerResponse(new EasterHandler(), '/easter', LitSchema::EASTER);
    }

    public function testTestsResponseValidatesAgainstTestsPathSchema(): void
    {
        $this->validateHandlerResponse(new TestsHandler(), '/tests', LitSchema::TESTS);
    }

    public function testSchemasResponseValidatesAgainstSchemasPathSchema(): void
    {
        $this->validateHandlerResponse(new SchemasHandler(), '/schemas', LitSchema::SCHEMAS);
    }
}
```

Notes for the implementer:

- If any handler does not implement `Psr\Http\Server\RequestHandlerInterface` (they all extend
  `AbstractHandler`, which does), or a constructor requires arguments, mirror the invocation used in that
  handler's existing test class (`MetadataHandlerTest`, `EasterHandlerTest`, `TestsHandlerTest`,
  `SchemasHandlerTest`) and note the deviation in your report.
- These tests are expected to PASS immediately (the files already validate live responses) — this task is
  regression armor, not TDD. If one FAILS, do NOT edit the schema file or the handler: report the exact
  validator message as a blocker (it would mean the in-process response differs from the live one, which is
  itself a finding the controller must see).

- [ ] **Step 2: Run the new tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php`
Expected: OK, 4 tests, with real assertions (NOT skipped). Total runtime should be a few seconds at most
(the easter computation for years 1970–9999 measured ~1.7s cold); do NOT add `@group slow` unless the run
measurably exceeds that scale.

- [ ] **Step 3: phpcs**

Run: `vendor/bin/phpcs phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php
git commit -m "test(handlers): validate read-only index responses against their schema files (#709)

Health checks validate /calendars, /easter, /tests and /schemas
responses against the standalone schema files only via the external
WebSocket interface; these in-process tests put the same validation in
CI so the files (now ref'd by openapi.json) cannot drift from handler
output unnoticed.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Full verification

**Files:** none modified (verification only; fix-forward only for defects introduced by Tasks 1–2).

**Interfaces:** consumes everything above.

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: 0 failures, 0 errors. ~1632 tests. Allowed skips ONLY: `Routes/*` server-dependent skips,
DB-gated skips, and environment-gated skips (Zitadel/OpenFGA/opcache/APCu). Any failure in
`Schemas/*` or `Handlers/*` must be reported as BLOCKED with the failing output — do not fix production
code in this task. (Note: one prior session saw 2 transient failures from `Routes/*` tests sharing the
`localhost:8000` server with other consumers; a failure that vanishes on a targeted re-run of just that
test file and lies outside this branch's changed files may be reported as environmental, with both runs'
tallies.)

- [ ] **Step 2: Static analysis and style**

Run: `composer analyse && composer lint`
Expected: PHPStan clean (no `src/` changes on this branch, so any error is pre-existing — report, don't fix);
phpcs clean.

- [ ] **Step 3: OpenAPI and markdown lint**

Run: `composer lint:openapi && composer lint:md`
Expected: both exit 0.

- [ ] **Step 4: Report**

No commit expected in this task (unless a Task 1/2 defect was fixed forward — then commit the fix with a
`fix:` message and re-run the relevant suites). Report: every command with exact tallies, skip accounting,
and confirmation the branch is ready for PR against `development`.
