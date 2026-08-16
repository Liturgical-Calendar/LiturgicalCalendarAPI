# Rite-aware `/tests` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/tests` rite-aware — a rite path segment, a rite-partitioned test corpus, and the resolved FGA scope exposed on every test.

**Architecture:** The test corpus moves from a flat `jsondata/tests/` to `jsondata/tests/{rite}/`, so test names become unique within a rite
rather than globally. `/tests/{rite}/{name}` becomes the only way to address a test (bare `/tests/{name}` is a 400), while bare `/tests` is
retained as the corpus-wide index across all rites. Every consumer that resolves a test by name — the handler, `TestScopeResolver`, the FGA
middleware, `LitTestRunner` — takes a `Rite` alongside the name.

**Tech Stack:** PHP 8.4+, PSR-7/15 (Nyholm PSR-7), PHPUnit 12, Swaggest JSON-Schema, OpenFGA.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-16-tests-rite-awareness-design.md`. Read it before starting.
- PHP >= 8.4. PSR-12 via `composer lint`; PHPStan level 10 via `composer analyse` (scans `src` only).
- Short array syntax `[]`, 4-space indent, single quotes unless interpolating. Line length is not enforced.
- Never use `git commit --no-verify`. CaptainHook pre-commit runs `composer lint` and `composer lint:md`; fix failures, do not bypass.
- Work in the worktree `../LCAPI-tests-rite` on branch `feature/tests-rite-awareness`. **Never** commit in the main checkout, which is shared
  with other agents.
- PHPStan ignores use the modern identifier form: `@phpstan-ignore <identifier>`, never a bare `@phpstan-ignore-line`.
- Use the `#[Group('slow')]` **attribute**, never a `@group slow` docblock.
- Run the suite with `composer test:quick`. Do **not** pass a bare `--exclude-group` on the CLI: it un-fences the golden-master-generate test,
  which then silently rewrites the fixtures it is checked against.
- One pre-existing failure is the green baseline when the API server is not reachable on `localhost:8000`
  (`ExecuteValidationTest`). Exactly one failure means pass.
- `Rite` has exactly two cases: `Rite::ROMAN` (`'roman'`, the default) and `Rite::AMBROSIAN` (`'ambrosian'`).

---

### Task 1: `JsonData` rite resolver for the tests folder

Pure addition — nothing reads these yet. Mirrors the `diocesanCalendarFileFor()` family added by #786.

**Files:**

- Modify: `src/Enum/JsonDataConstants.php:23` (after `TESTS_FOLDER`)
- Modify: `src/Enum/JsonData.php:25` (case list) and `:508` (end of class, after `diocesanCalendarI18nFileFor()`)
- Test: `phpunit_tests/Enum/JsonDataRiteResolversTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces: `JsonData::testsFolderFor(Rite $rite): JsonData`, plus cases `JsonData::ROMAN_TESTS_FOLDER` and `JsonData::AMBROSIAN_TESTS_FOLDER`.
  Every later task resolves a test directory through `JsonData::testsFolderFor($rite)->path()`.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Enum/JsonDataRiteResolversTest.php`, inside the existing class:

```php
    public function testTestsFolderForRomanIsTheRomanPartition(): void
    {
        self::assertSame(JsonData::ROMAN_TESTS_FOLDER, JsonData::testsFolderFor(Rite::ROMAN));
        self::assertStringEndsWith('jsondata/tests/roman', JsonData::ROMAN_TESTS_FOLDER->value);
    }

    public function testTestsFolderForAmbrosianIsTheAmbrosianPartition(): void
    {
        self::assertSame(JsonData::AMBROSIAN_TESTS_FOLDER, JsonData::testsFolderFor(Rite::AMBROSIAN));
        self::assertStringEndsWith('jsondata/tests/ambrosian', JsonData::AMBROSIAN_TESTS_FOLDER->value);
    }

    public function testTestPartitionsAreDistinctAndSitUnderTheTestsFolder(): void
    {
        self::assertNotSame(JsonData::ROMAN_TESTS_FOLDER, JsonData::AMBROSIAN_TESTS_FOLDER);
        foreach ([JsonData::ROMAN_TESTS_FOLDER, JsonData::AMBROSIAN_TESTS_FOLDER] as $partition) {
            self::assertStringStartsWith(JsonData::TESTS_FOLDER->value . '/', $partition->value);
        }
    }
```

If the file's `use` block lacks them, add `use LiturgicalCalendar\Api\Enum\JsonData;` and `use LiturgicalCalendar\Api\Enum\Rite;`.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Enum/JsonDataRiteResolversTest.php
```

Expected: FAIL — `Error: Undefined constant LiturgicalCalendar\Api\Enum\JsonData::ROMAN_TESTS_FOLDER`.

- [ ] **Step 3: Add the constants**

In `src/Enum/JsonDataConstants.php`, immediately after the `TESTS_FOLDER` constant (line 23):

```php
    /**
     * The test corpus is partitioned by rite, so that a test name is unique within a
     * rite rather than globally: a Roman `StIgnatiusOfLoyolaTest` can coexist with the
     * Ambrosian one. Unlike the sourcedata partitions this lives under `jsondata/tests`
     * rather than `sourcedata/rite`, because tests are not source data.
     *
     * Evaluates to 'jsondata/tests/roman'.
     */
    public const ROMAN_TESTS_FOLDER = JsonDataConstants::TESTS_FOLDER . '/roman';

    /** Evaluates to 'jsondata/tests/ambrosian'. */
    public const AMBROSIAN_TESTS_FOLDER = JsonDataConstants::TESTS_FOLDER . '/ambrosian';
```

- [ ] **Step 4: Add the enum cases and the resolver**

In `src/Enum/JsonData.php`, after the `TESTS_FOLDER` case (line 25):

```php
    case ROMAN_TESTS_FOLDER     = JsonDataConstants::ROMAN_TESTS_FOLDER;
    case AMBROSIAN_TESTS_FOLDER = JsonDataConstants::AMBROSIAN_TESTS_FOLDER;
```

At the end of the class, after `diocesanCalendarI18nFileFor()`:

```php
    /**
     * The test-corpus folder for the given rite.
     *
     * Unlike the diocesan resolvers, both partitions always exist: every rite has a test
     * folder, because a rite-level test (`applies_to: {"rite": "ambrosian"}`) needs
     * somewhere to live even where that rite has no national or diocesan tier.
     */
    public static function testsFolderFor(Rite $rite): self
    {
        return $rite === Rite::AMBROSIAN
            ? self::AMBROSIAN_TESTS_FOLDER
            : self::ROMAN_TESTS_FOLDER;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Enum/JsonDataRiteResolversTest.php
```

Expected: PASS.

- [ ] **Step 6: Static analysis and lint**

```bash
cd ../LCAPI-tests-rite && composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
cd ../LCAPI-tests-rite
git add src/Enum/JsonDataConstants.php src/Enum/JsonData.php phpunit_tests/Enum/JsonDataRiteResolversTest.php
git commit -m "feat(tests): add rite-partitioned test folder constants and resolver (#787)"
```

---

### Task 2: Router tri-state rite resolution for `/tests`

The hard break lands here. Storage is untouched: after this task `/tests/ambrosian/StIgnatiusOfLoyolaTest` still reads the flat file,
which is correct behaviour over the old layout. Task 3 moves the files.

`tests` deliberately does **not** join `extractRiteSegment()` — that helper defaults an absent segment to Roman, and bare `/tests` must
mean *all rites*.

**Files:**

- Modify: `src/Router.php` — new static method near `extractRiteSegment()` (`:132`); `case 'tests':` block (`:580`); auth block (`:833-840`)
- Modify: `src/Handlers/TestsHandler.php` — constructor takes the rite and rejects a null rite with path params
- Test: `phpunit_tests/RouterTestsRiteSegmentTest.php` (create)

**Interfaces:**

- Consumes: nothing from Task 1.
- Produces: `Router::extractTestsRite(array &$requestPathParts): ?Rite` — returns `null` when no leading rite segment is present, and strips
  the segment when it is. The request attribute `test_rite` (string rite value, or absent when null). `TestsHandler::__construct(array
  $requestPathParams = [], ?Rite $rite = null)`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/RouterTestsRiteSegmentTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * `/tests` resolves its own tri-state rite rather than joining extractRiteSegment():
 * that helper resolves an absent segment to Roman, whereas bare `/tests` must mean
 * *every* rite (the corpus-wide index). null therefore means "all rites", not "Roman".
 */
#[CoversMethod(Router::class, 'extractTestsRite')]
final class RouterTestsRiteSegmentTest extends TestCase
{
    public function testAmbrosianSegmentIsStrippedAndSelectsAmbrosian(): void
    {
        $parts = ['ambrosian', 'StIgnatiusOfLoyolaTest'];
        self::assertSame(Rite::AMBROSIAN, Router::extractTestsRite($parts));
        self::assertSame(['StIgnatiusOfLoyolaTest'], $parts);
    }

    public function testRomanSegmentIsStrippedAndSelectsRoman(): void
    {
        $parts = ['roman', 'MaryMotherChurchTest'];
        self::assertSame(Rite::ROMAN, Router::extractTestsRite($parts));
        self::assertSame(['MaryMotherChurchTest'], $parts);
    }

    public function testBareRiteSegmentIsThePerRiteCollection(): void
    {
        $parts = ['ambrosian'];
        self::assertSame(Rite::AMBROSIAN, Router::extractTestsRite($parts));
        self::assertSame([], $parts);
    }

    public function testNoSegmentAtAllMeansAllRites(): void
    {
        $parts = [];
        self::assertNull(Router::extractTestsRite($parts));
        self::assertSame([], $parts);
    }

    public function testBareTestNameIsNotARiteAndIsLeftIntact(): void
    {
        // The hard break: this shape reaches the handler with a null rite and one
        // path param, which TestsHandler rejects with a 400.
        $parts = ['MaryMotherChurchTest'];
        self::assertNull(Router::extractTestsRite($parts));
        self::assertSame(['MaryMotherChurchTest'], $parts);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/RouterTestsRiteSegmentTest.php
```

Expected: FAIL — `Call to undefined method LiturgicalCalendar\Api\Router::extractTestsRite()`.

- [ ] **Step 3: Add `extractTestsRite()` to the Router**

In `src/Router.php`, immediately after `extractRiteSegment()` (which ends at line 143):

```php
    /**
     * Resolve the optional leading rite segment on the `/tests` route.
     *
     * `/tests` deliberately does not go through {@see self::extractRiteSegment()}. That
     * helper resolves an absent segment to the default rite, which is right for `/calendar`,
     * `/events` and `/data` — every one of those addresses a single calendar, so "no rite
     * stated" can only sensibly mean "the default one". `/tests` has a *collection* whose
     * historical meaning is "every test regardless of rite", so it needs a third state:
     * null means all rites, and is distinct from an explicit `roman`.
     *
     * A test name can never be mistaken for a rite: `LitCalTest.json` requires names to end
     * in `Test` and the collection globs `*Test.json`, so neither `roman` nor `ambrosian`
     * can name a test.
     *
     * @param list<string> $requestPathParts the path segments following the route; the rite segment is removed in place when present
     * @return Rite|null the rite named by the leading segment, or null when none is present
     */
    public static function extractTestsRite(array &$requestPathParts): ?Rite
    {
        $maybeRite = Rite::tryFrom((string) ( $requestPathParts[0] ?? '' ));
        if ($maybeRite !== null) {
            array_shift($requestPathParts);
            return $maybeRite;
        }

        return null;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/RouterTestsRiteSegmentTest.php
```

Expected: PASS.

- [ ] **Step 5: Wire it into the `tests` route**

In `src/Router.php`, replace the opening of `case 'tests':` (line 580, `$testsHandler = new TestsHandler($requestPathParts);`) with:

```php
            case 'tests':
                // Strip the rite segment BEFORE the count-based method wiring below, so
                // /tests/ambrosian behaves exactly like /tests (collection) and
                // /tests/ambrosian/{name} exactly like a one-part item route.
                $testsRite    = self::extractTestsRite($requestPathParts);
                $testsHandler = new TestsHandler($requestPathParts, $testsRite);
```

Declare `$testsRite` before the `switch` so the authorization block can see it. Immediately above the `switch ($route)` statement add:

```php
        $testsRite = null;
```

- [ ] **Step 6: Pass the rite to the FGA middleware**

In `src/Router.php`, in the `elseif ($route === 'tests')` authorization block (line 833), replace the `withAttribute('test_id', ...)` line with:

```php
            if ($oidcAvailable && $fgaClient !== null && count($requestPathParts) >= 1) {
                $this->request = $this->request
                    ->withAttribute('test_id', $requestPathParts[0])
                    ->withAttribute('test_rite', $testsRite?->value);
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forTestScopes($fgaClient, new TestScopeResolver()));
            }
```

- [ ] **Step 7: Make the handler reject a rite-less item path**

In `src/Handlers/TestsHandler.php`, add the import `use LiturgicalCalendar\Api\Enum\Rite;` and change the constructor (line 49):

```php
    private ?Rite $rite;

    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [], ?Rite $rite = null)
    {
        parent::__construct($requestPathParams);
        $this->rite = $rite;
        // ... existing $this->allowCredentials = true; and its comment stay unchanged
    }
```

Then, at the very top of `handle()` — immediately after `$this->validateRequestMethod($request);` (line 378) — add the hard-break guard:

```php
        // A test is addressed as /tests/{rite}/{name}. The bare /tests/{name} form is
        // gone: names are only unique within a rite now, so a bare name does not identify
        // a test. Bare /tests (no path params) remains the corpus-wide index.
        if ($this->rite === null && count($this->requestPathParams) > 0) {
            $description = 'A Unit Test is addressed as /tests/{rite}/{name}, where {rite} is one of: '
                . implode(', ', array_column(Rite::cases(), 'value'))
                . '. Received /tests/' . implode('/', $this->requestPathParams) . ' with no rite segment.';
            throw new ValidationException($description);
        }
```

- [ ] **Step 8: Add the handler-level guard test**

Append to `phpunit_tests/Handlers/TestsHandlerTest.php`:

```php
    public function testBareTestNameWithoutRiteSegmentIsRejected(): void
    {
        // The #787 hard break: /tests/MaryMotherChurchTest no longer addresses a test.
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['MaryMotherChurchTest'], null) )
            ->handle($this->requestFor('GET', '/tests/MaryMotherChurchTest'));
    }

    public function testCollectionWithoutRiteSegmentIsStillAllowed(): void
    {
        $response = ( new TestsHandler([], null) )->handle($this->requestFor('GET', '/tests'));
        self::assertSame(200, $response->getStatusCode());
    }
```

- [ ] **Step 9: Update the existing handler tests to pass a rite**

Every existing `new TestsHandler([...])` call in `phpunit_tests/Handlers/TestsHandlerTest.php` that passes **one or more** path params must
now pass a rite as the second argument. The shipped corpus is Roman except `StIgnatiusOfLoyolaTest`, so these all take `Rite::ROMAN`:

```php
        // e.g. testGetSingleTestByNameReturnsThatTest
        $handler  = new TestsHandler(['MaryMotherChurchTest'], Rite::ROMAN);
        $response = $handler->handle($this->requestFor('GET', '/tests/roman/MaryMotherChurchTest'));
```

Add `use LiturgicalCalendar\Api\Enum\Rite;` to the file's import block. Leave `testTooManyPathParamsIsValidationError` passing
`['a', 'b']` with `Rite::ROMAN` — two params after the rite is still a 400.

- [ ] **Step 10: Run the affected suites**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/RouterTestsRiteSegmentTest.php phpunit_tests/Handlers/TestsHandlerTest.php phpunit_tests/Http/RouterPipelineTest.php
```

Expected: PASS. If `RouterPipelineTest` asserts a `/tests/{name}` shape, update those paths to `/tests/roman/{name}`.

- [ ] **Step 11: Static analysis, lint, full suite**

```bash
cd ../LCAPI-tests-rite && composer analyse && composer lint && composer test:quick
```

Expected: no analyse/lint errors; at most the one baseline `ExecuteValidationTest` failure.

- [ ] **Step 12: Commit**

```bash
cd ../LCAPI-tests-rite
git add src/Router.php src/Handlers/TestsHandler.php phpunit_tests/RouterTestsRiteSegmentTest.php phpunit_tests/Handlers/TestsHandlerTest.php phpunit_tests/Http/RouterPipelineTest.php
git commit -m "feat(tests): require a rite segment to address a test (#787)"
```

---

### Task 3: Flip the corpus to the rite-partitioned layout

The atomic task: the files move and every reader moves with them. This cannot be split — a reviewer cannot approve "files moved"
without "the handler reads the new location".

**Files:**

- Move: `jsondata/tests/*.json` → `jsondata/tests/roman/` (10 files) and `jsondata/tests/ambrosian/` (`StIgnatiusOfLoyolaTest.json`)
- Modify: `src/Services/TestScopeResolver.php:44-47`, `:145-168`
- Modify: `src/Handlers/TestsHandler.php` — `handleGetRequest()`, `handleDeleteRequest()`, `handlePutRequest()`, `handlePatchRequest()`
- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php:265-289`
- Modify: `src/Test/LitTestRunner.php:87-96`
- Modify: `src/Health.php:1348-1372`
- Modify: `phpunit_tests/Schemas/SchemaValidationTest.php:659`
- Test: `phpunit_tests/Services/TestScopeResolverRiteTest.php` (create)

**Interfaces:**

- Consumes: `JsonData::testsFolderFor(Rite)` (Task 1); `TestsHandler::__construct(array, ?Rite)` and the `test_rite` attribute (Task 2).
- Produces: `TestScopeResolver::resolve(Rite $rite, string $testName): ?array{0:string,1:string}` (the rite is now the **first** parameter);
  `TestScopeResolver::__construct(?string $testsRootDir = null)` where the argument is the corpus **root**, not a partition;
  `LitTestRunner::__construct(string $Test, \stdClass $testData, Rite $rite)`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Services/TestScopeResolverRiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The collision this whole change exists to make possible: the same test name under two
 * rites is two different tests, resolving to two different FGA scopes.
 */
#[CoversClass(TestScopeResolver::class)]
final class TestScopeResolverRiteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/litcal-scope-' . bin2hex(random_bytes(6));
        foreach ([Rite::ROMAN, Rite::AMBROSIAN] as $rite) {
            mkdir($this->root . '/' . $rite->value, 0777, true);
            file_put_contents(
                $this->root . '/' . $rite->value . '/StIgnatiusOfLoyolaTest.json',
                json_encode(['name' => 'StIgnatiusOfLoyolaTest', 'applies_to' => ['rite' => $rite->value]])
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ([Rite::ROMAN, Rite::AMBROSIAN] as $rite) {
            @unlink($this->root . '/' . $rite->value . '/StIgnatiusOfLoyolaTest.json');
            @rmdir($this->root . '/' . $rite->value);
        }
        @rmdir($this->root);
    }

    public function testSameNameUnderTwoRitesResolvesToTwoScopes(): void
    {
        $resolver = new TestScopeResolver($this->root);

        self::assertSame(
            ['rite_calendar_test', 'roman'],
            $resolver->resolve(Rite::ROMAN, 'StIgnatiusOfLoyolaTest')
        );
        self::assertSame(
            ['rite_calendar_test', 'ambrosian'],
            $resolver->resolve(Rite::AMBROSIAN, 'StIgnatiusOfLoyolaTest')
        );
    }

    public function testMissingTestInThatPartitionResolvesToNull(): void
    {
        $resolver = new TestScopeResolver($this->root);
        self::assertNull($resolver->resolve(Rite::AMBROSIAN, 'NotARealTest'));
    }

    public function testUnsafeNameNeverTouchesTheFilesystem(): void
    {
        $resolver = new TestScopeResolver($this->root);
        self::assertNull($resolver->resolve(Rite::ROMAN, '../../etc/passwd'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Services/TestScopeResolverRiteTest.php
```

Expected: FAIL — `ArgumentCountError` / `TypeError`, because `resolve()` currently takes only a string.

- [ ] **Step 3: Move the corpus**

```bash
cd ../LCAPI-tests-rite
mkdir -p jsondata/tests/roman jsondata/tests/ambrosian
git mv jsondata/tests/StIgnatiusOfLoyolaTest.json jsondata/tests/ambrosian/
for f in jsondata/tests/*Test.json; do git mv "$f" jsondata/tests/roman/; done
git status --short jsondata/tests
```

Expected: 11 renames, no content changes. Verify the split matches `applies_to.rite` in every file:

```bash
cd ../LCAPI-tests-rite && for f in jsondata/tests/*/*.json; do
  printf '%-70s %s\n' "$f" "$(python3 -c "import json,sys;print(json.load(open('$f'))['applies_to']['rite'])")"
done
```

Expected: every path under `roman/` reports `roman`, and the one under `ambrosian/` reports `ambrosian`.

- [ ] **Step 4: Make `TestScopeResolver` rite-aware**

In `src/Services/TestScopeResolver.php`, replace the constructor (lines 42-47):

```php
    /** Absolute path to the corpus ROOT (`jsondata/tests`), not to a rite partition. */
    private string $testsRootDir;

    public function __construct(?string $testsRootDir = null)
    {
        $this->testsRootDir = $testsRootDir ?? JsonData::TESTS_FOLDER->path();
    }
```

Replace `resolve()` (lines 142-168):

```php
    /**
     * Resolve the FGA scope pair for a stored test.
     *
     * The corpus is partitioned by rite (#787), so a name alone does not identify a test:
     * `StIgnatiusOfLoyolaTest` exists — or may come to exist — under both rites, as two
     * different tests with two different scopes.
     *
     * @return array{0: string, 1: string}|null null when the name is unsafe, or no such test exists under that rite
     */
    public function resolve(Rite $rite, string $testName): ?array
    {
        // Reject any name that could enable path traversal or filesystem injection.
        // Only allow characters that are safe for use as a bare file-stem: letters,
        // digits, hyphens, and underscores. This rejects '..', '/', '\', null bytes,
        // spaces, and every other special character before touching the filesystem.
        if (!self::isSafeName($testName)) {
            return null;
        }

        $filePath = $this->testsRootDir . DIRECTORY_SEPARATOR . $rite->value . DIRECTORY_SEPARATOR . $testName . '.json';

        $raw = @file_get_contents($filePath);
        if (false === $raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return self::mapAppliesTo($data['applies_to'] ?? null);
    }
```

Update the class docblock's first line so that the path it documents reads `{testsRoot}/{rite}/{testName}.json` rather than
`{testsDir}/{testName}.json`.

- [ ] **Step 5: Run the resolver test to verify it passes**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Services/TestScopeResolverRiteTest.php
```

Expected: PASS.

- [ ] **Step 6: Make the FGA middleware pass the rite**

In `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`, add `use LiturgicalCalendar\Api\Enum\Rite;` and replace the body of the
`$objectResolver` closure inside `forTestScopes()` (lines 266-288):

```php
        $objectResolver = static function (ServerRequestInterface $request) use ($resolver): ?array {
            $testId = $request->getAttribute('test_id');
            if (!is_string($testId) || trim($testId) === '') {
                return null;
            }

            // The rite is part of a test's identity (#787). Without it there is no single
            // test to authorize against, so fail closed rather than guessing a partition.
            $riteValue = $request->getAttribute('test_rite');
            $rite      = is_string($riteValue) ? Rite::tryFrom($riteValue) : null;
            if ($rite === null) {
                return null;
            }

            $resolved = $resolver->resolve($rite, $testId);
            if (
                $resolved === null
                && strtoupper($request->getMethod()) === 'PUT'
                && TestScopeResolver::isSafeName($testId)
            ) {
                // Create flow: the test file does not exist yet, so derive the scope
                // from the payload's `applies_to` — the same value the handler will
                // persist, so the scope that authorizes the create is the scope the
                // created resource will carry. The payload comes from getParsedBody()
                // (populated by JsonBodyParserMiddleware earlier in the pipeline)
                // rather than the raw stream, so the body is never consumed here;
                // a missing/unparseable body yields null and fails closed.
                $resolved = $resolver->resolveFromPayload($request->getParsedBody());
            }
            return $resolved;
        };
```

Update the class docblock line 34 to read:

```text
 *   /tests/{rite}/{id}      → {national,diocesan}_calendar_test:{rite}/{id} | rite_calendar_test:{rite} (via TestScopeResolver)
```

- [ ] **Step 7: Make the handler read and write the partition**

In `src/Handlers/TestsHandler.php`, add a private helper just above `handleGetRequest()`:

```php
    /**
     * Absolute path to the file backing a test, in the partition for this request's rite.
     *
     * Only ever called once the null-rite guard in handle() has passed, so $this->rite is
     * non-null on every write and single-test read path.
     */
    private function testFilePath(string $testName): string
    {
        return JsonData::testsFolderFor($this->rite ?? Rite::default())->path() . '/' . $testName . '.json';
    }

    /**
     * Every test in the requested rite, or across every rite when no rite segment was given.
     *
     * @return list<array<string,mixed>>
     */
    private function collectTests(): array
    {
        $rites = $this->rite === null ? Rite::cases() : [$this->rite];
        $suite = [];
        foreach ($rites as $rite) {
            $folder = JsonData::testsFolderFor($rite)->path();
            $files  = glob($folder . '/*Test.json');
            if ($files === false) {
                throw new ServiceUnavailableException("Tests folder {$folder} cannot be opened");
            }
            foreach ($files as $filePath) {
                $testContents = file_get_contents($filePath);
                if ($testContents === false) {
                    throw new ServiceUnavailableException('Test ' . basename($filePath) . ' was not readable');
                }
                /** @var array<string,mixed> $decoded */
                $decoded = json_decode($testContents, true, 512, JSON_THROW_ON_ERROR);
                $suite[] = $decoded;
            }
        }
        return $suite;
    }
```

Replace the collection branch of `handleGetRequest()` (lines 139-160) with:

```php
        if (count($this->requestPathParams) === 0) {
            $responseBody               = new \stdClass();
            $responseBody->litcal_tests = $this->collectTests();
            return $this->encodeResponseBody($response, $responseBody);
        } elseif (count($this->requestPathParams) > 1) {
```

In the single-test branch of `handleGetRequest()`, replace both occurrences of
`JsonData::TESTS_FOLDER->path() . "/{$testFile}.json"` with `$this->testFilePath($testFile)` (assign it to a local `$testFilePath` first
and reuse it, so the path is built once).

In `handleDeleteRequest()` replace line 213 and line 218:

```php
        $scope = ( new TestScopeResolver() )->resolve($this->rite ?? Rite::default(), $testName);
        if ($scope === null) {
            throw new NotFoundException("Test {$testName} not found, cannot DELETE.");
        }

        if (false === unlink($this->testFilePath($testName))) {
```

In `handlePutRequest()` replace line 282, and in `handlePatchRequest()` replace line 338, both with:

```php
        $testFilePath = $this->testFilePath($testName);
```

- [ ] **Step 8: Give `LitTestRunner` the rite**

In `src/Test/LitTestRunner.php`, add `use LiturgicalCalendar\Api\Enum\Rite;` and change the constructor signature and path/cache lines:

```php
    public function __construct(string $Test, \stdClass $testData, Rite $rite)
    {
        $this->Test       = $Test;
        $this->dataToTest = $testData;
        if (self::$testCache === null) {
            self::$testCache = new TestsMap();
        }
        // The cache key carries the rite: the corpus is partitioned, so the same name
        // under two rites is two different tests (#787).
        $cacheKey = $rite->value . '/' . $Test;
        if (false === self::$testCache->has($cacheKey)) {
            $testPath = rtrim(JsonData::testsFolderFor($rite)->path(), '/\\') . DIRECTORY_SEPARATOR . basename($Test) . '.json';
```

Replace the single `self::$testCache->add($Test, $testInstructions);` with `self::$testCache->add($cacheKey, $testInstructions);`, and
every other `self::$testCache` lookup keyed on `$Test` with `$cacheKey`. Search the file for `$testCache` to catch them all:

```bash
cd ../LCAPI-tests-rite && grep -n 'testCache' src/Test/LitTestRunner.php
```

- [ ] **Step 9: Pass the rite through `Health`**

In `src/Health.php`, in `executeUnitTest()` (line 1348), hoist the resolved rite so the closure can capture it:

```php
        $rite    = $this->resolveRite($calendar, $category, $riteHint);
        $req     = $this->buildCalendarRequestPath($calendar, $year, $category, $rite);
        $promise = $this->cachedGet(Route::CALENDAR->path() . $req, $opts, 300, $to);
        $promise->then(
            function (array $result) use ($to, $test, $year, $runToken, $rite) {
```

and change the runner construction inside that closure:

```php
                    $UnitTest = new LitTestRunner($test, $jsonData, $rite);
```

- [ ] **Step 10: Make corpus schema validation recursive**

In `phpunit_tests/Schemas/SchemaValidationTest.php:659`:

```php
        $files = glob(dirname(__DIR__, 2) . '/jsondata/tests/*/*.json');
```

Update the comment above it to say the corpus is partitioned by rite.

- [ ] **Step 11: Fix the remaining test fixtures**

`phpunit_tests/Handlers/TestsHandlerTest.php` reads and writes fixtures via `JsonData::TESTS_FOLDER->path()`. Point each at the Roman
partition:

```php
        $payload = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzPutCreatedTest.json';
```

Apply the same substitution in `phpunit_tests/Test/LitTestRunnerTest.php` (which also needs the new third constructor argument,
`Rite::ROMAN`) and in `phpunit_tests/Http/RouterPipelineTest.php` if it references the folder.

Also add to `phpunit_tests/Test/LitTestRunnerTest.php` a case proving the runner reads the right partition, since its static cache is
now keyed by rite and a leak between partitions would be silent:

```php
    public function testRunnerResolvesTestsFromTheRitePartition(): void
    {
        // StIgnatiusOfLoyolaTest exists only under ambrosian/.
        $ambrosian = new LitTestRunner('StIgnatiusOfLoyolaTest', new \stdClass(), Rite::AMBROSIAN);
        self::assertTrue($ambrosian->isReady());

        // The same name under roman/ does not exist, so the runner is not ready.
        $roman = new LitTestRunner('StIgnatiusOfLoyolaTest', new \stdClass(), Rite::ROMAN);
        self::assertFalse($roman->isReady());
    }
```

Add `use LiturgicalCalendar\Api\Enum\Rite;` to that file if it is not already imported.

- [ ] **Step 12: Run the full suite**

```bash
cd ../LCAPI-tests-rite && composer test:quick
```

Expected: at most the one baseline `ExecuteValidationTest` failure. Any other failure names a reader of the corpus that step 7-11 missed —
find it with `grep -rn 'TESTS_FOLDER' src phpunit_tests`.

- [ ] **Step 13: Static analysis and lint**

```bash
cd ../LCAPI-tests-rite && composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 14: Commit**

```bash
cd ../LCAPI-tests-rite
git add -A jsondata/tests src phpunit_tests
git commit -m "feat(tests): partition the test corpus by rite (#787)"
```

---

### Task 4: Reject a path segment that contradicts `applies_to.rite`

The directory is the address and `applies_to.rite` is the content; the handler refuses to let them diverge, mirroring
`/data/roman/diocese/lugano_ch`.

**Files:**

- Modify: `src/Handlers/TestsHandler.php` — new guard called from `handlePutRequest()` and `handlePatchRequest()`
- Test: `phpunit_tests/Handlers/TestsHandlerTest.php`

**Interfaces:**

- Consumes: `TestsHandler::$rite` (Task 2), the partitioned write paths (Task 3).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Handlers/TestsHandlerTest.php`:

```php
    public function testPutRejectsPayloadWhoseRiteContradictsThePath(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']       = 'ZzzRiteMismatchTest';
        $payload['applies_to'] = ['rite' => 'roman'];

        // Addressed under /tests/ambrosian/... but the body says roman.
        $this->expectException(UnprocessableContentException::class);
        ( new TestsHandler(['ZzzRiteMismatchTest'], Rite::AMBROSIAN) )->handle(
            $this->requestFor('PUT', '/tests/ambrosian/ZzzRiteMismatchTest', [], $payload)
        );
    }

    public function testPutAcceptsPayloadWhoseRiteMatchesThePath(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']       = 'ZzzRiteMatchTest';
        $payload['applies_to'] = ['rite' => 'roman'];

        $this->testFixturePath = JsonData::testsFolderFor(Rite::ROMAN)->path() . '/ZzzRiteMatchTest.json';

        $response = ( new TestsHandler(['ZzzRiteMatchTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzRiteMatchTest', [], $payload)
        );
        self::assertSame(201, $response->getStatusCode());
    }
```

Add the PATCH half of the same guard — the spec requires the check on both write verbs, and PATCH takes a different code path
(it derives the name from the payload rather than the path):

```php
    public function testPatchRejectsPayloadWhoseRiteContradictsThePath(): void
    {
        /** @var array<string,mixed> $payload */
        $payload               = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['applies_to'] = ['rite' => 'roman'];

        // MaryMotherChurchTest exists under roman/, but is addressed here under ambrosian/.
        $this->expectException(UnprocessableContentException::class);
        ( new TestsHandler(['MaryMotherChurchTest'], Rite::AMBROSIAN) )->handle(
            $this->requestFor('PATCH', '/tests/ambrosian/MaryMotherChurchTest', [], $payload)
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php --filter 'RiteMismatch|RiteContradicts'
```

Expected: FAIL — no exception is thrown; the mismatched test is written into the Ambrosian partition.

- [ ] **Step 3: Add the guard**

In `src/Handlers/TestsHandler.php`, add above `writeTestToDisk()`:

```php
    /**
     * The path segment and `applies_to.rite` must name the same rite.
     *
     * The directory is the address and `applies_to.rite` is the content. Letting them
     * diverge would file a test under a rite it does not claim, and `TestScopeResolver`
     * reads the content while the route reads the address — so they would authorize and
     * store against different rites.
     */
    private function assertPayloadRiteMatchesPath(): void
    {
        $pathRite = $this->rite ?? Rite::default();

        $payloadRite = null;
        if (
            property_exists($this->payload, 'applies_to')
            && $this->payload->applies_to instanceof \stdClass
            && property_exists($this->payload->applies_to, 'rite')
            && is_string($this->payload->applies_to->rite)
        ) {
            $payloadRite = Rite::tryFrom($this->payload->applies_to->rite);
        }

        if ($payloadRite !== $pathRite) {
            $described   = $payloadRite?->value ?? 'none';
            $description = 'You are attempting to write a Unit Test at /tests/' . $pathRite->value
                . '/ whose applies_to.rite is ' . $described . '. The rite in the path and the rite in the body must match.';
            throw new UnprocessableContentException($description);
        }
    }
```

Call it in `handlePutRequest()` immediately after `self::sanitizeObjectValues($this->payload);` (line 269), and in `handlePatchRequest()`
immediately after the same call (line 329).

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php
```

Expected: PASS.

- [ ] **Step 5: Static analysis, lint, full suite**

```bash
cd ../LCAPI-tests-rite && composer analyse && composer lint && composer test:quick
```

Expected: no analyse/lint errors; at most the one baseline failure.

- [ ] **Step 6: Commit**

```bash
cd ../LCAPI-tests-rite
git add src/Handlers/TestsHandler.php phpunit_tests/Handlers/TestsHandlerTest.php
git commit -m "feat(tests): reject a rite segment that contradicts applies_to.rite (#787)"
```

---

### Task 5: Expose the resolved FGA scope on every test

Deletes the duplication that made `admin-tests`' `deriveScope()` drift twice in one release.

**Files:**

- Modify: `jsondata/schemas/LitCalTest.json` — `scope` on all three correspondence types (they are `additionalProperties: false`)
- Modify: `src/Handlers/TestsHandler.php` — inject on read, reject on write
- Test: `phpunit_tests/Handlers/TestsHandlerTest.php`

**Interfaces:**

- Consumes: `TestScopeResolver::resolve(Rite, string)` (Task 3).
- Produces: a `scope` object `{"object_type": string, "object_id": string}` on every test in both the collection and the single-test response.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Handlers/TestsHandlerTest.php`:

```php
    public function testCollectionCarriesTheResolvedScope(): void
    {
        $response = ( new TestsHandler([], Rite::AMBROSIAN) )->handle($this->requestFor('GET', '/tests/ambrosian'));
        $body     = $this->decodeJsonBody($response);

        self::assertNotEmpty($body['litcal_tests']);
        foreach ($body['litcal_tests'] as $test) {
            self::assertArrayHasKey('scope', $test);
            self::assertSame('rite_calendar_test', $test['scope']['object_type']);
            self::assertSame('ambrosian', $test['scope']['object_id']);
        }
    }

    public function testSingleTestCarriesTheResolvedScope(): void
    {
        $response = ( new TestsHandler(['PrayerUnbornTest'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/tests/roman/PrayerUnbornTest'));

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('national_calendar_test', $body['scope']['object_type']);
        self::assertSame('roman/US', $body['scope']['object_id']);
    }

    public function testWritePayloadCarryingScopeIsRejected(): void
    {
        /** @var array<string,mixed> $payload */
        $payload          = json_decode(
            (string) file_get_contents(JsonData::testsFolderFor(Rite::ROMAN)->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name']  = 'ZzzScopeRejectedTest';
        $payload['scope'] = ['object_type' => 'rite_calendar_test', 'object_id' => 'roman'];

        $this->expectException(UnprocessableContentException::class);
        ( new TestsHandler(['ZzzScopeRejectedTest'], Rite::ROMAN) )->handle(
            $this->requestFor('PUT', '/tests/roman/ZzzScopeRejectedTest', [], $payload)
        );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php --filter Scope
```

Expected: FAIL — `Failed asserting that an array has the key 'scope'`.

- [ ] **Step 3: Add `scope` to the schema**

In `jsondata/schemas/LitCalTest.json`, add to `definitions` a shared block:

```json
        "ResolvedScope": {
            "type": "object",
            "title": "ResolvedScope",
            "description": "the OpenFGA (object type, object id) pair that scopes this test in the authorization model, computed by the server from `applies_to`. Read-only: it never appears in a source file, and a write payload carrying it is rejected with a 422. Exposed so clients do not re-implement TestScopeResolver — see issue #787.",
            "properties": {
                "object_type": {
                    "type": "string",
                    "enum": ["rite_calendar_test", "national_calendar_test", "diocesan_calendar_test"]
                },
                "object_id": {
                    "type": "string",
                    "minLength": 1
                }
            },
            "required": ["object_type", "object_id"],
            "additionalProperties": false
        },
```

Then add `"scope": { "$ref": "#/definitions/ResolvedScope" }` to the `properties` of **all three** of `ExactCorrespondenceType`,
`ExactCorrespondenceSinceType` and `ExactCorrespondenceUntilType`. Do **not** add it to any `required` array — source files never carry it.

- [ ] **Step 4: Inject on read and reject on write**

In `src/Handlers/TestsHandler.php`, add a helper next to `collectTests()`:

```php
    /**
     * The FGA scope pair for a test, as a response-only object.
     *
     * @return (\stdClass&object{object_type:string,object_id:string})|null null when no such test exists under that rite
     */
    private function scopeObjectFor(Rite $rite, string $testName): ?\stdClass
    {
        $scope = ( new TestScopeResolver() )->resolve($rite, $testName);
        if ($scope === null) {
            return null;
        }
        /** @var \stdClass&object{object_type:string,object_id:string} $obj */
        $obj = (object) ['object_type' => $scope[0], 'object_id' => $scope[1]];
        return $obj;
    }
```

In `collectTests()`, inside the inner loop, after `$decoded = json_decode(...)`:

```php
                $name = is_string($decoded['name'] ?? null) ? $decoded['name'] : basename($filePath, '.json');
                $scope = ( new TestScopeResolver() )->resolve($rite, $name);
                if ($scope !== null) {
                    $decoded['scope'] = ['object_type' => $scope[0], 'object_id' => $scope[1]];
                }
                $suite[] = $decoded;
```

(remove the now-duplicated `$suite[] = $decoded;` that preceded it).

In the single-test branch of `handleGetRequest()`, the raw-passthrough shortcut must go — the body is no longer the file verbatim. Replace
that branch's body with:

```php
            $decodedContents = json_decode($testContents, false, 512, JSON_THROW_ON_ERROR);
            if (false === ( $decodedContents instanceof \stdClass )) {
                throw new ServiceUnavailableException("Failed to decode test {$testFile} as JSON");
            }
            $scopeObject = $this->scopeObjectFor($this->rite ?? Rite::default(), $testFile);
            if ($scopeObject !== null) {
                $decodedContents->scope = $scopeObject;
            }
            return $this->encodeResponseBody($response, $decodedContents);
```

For the write rejection, add to `assertPayloadRiteMatchesPath()`'s caller path — a separate guard, called from the same two places,
immediately before `assertPayloadRiteMatchesPath()`:

```php
    /**
     * `scope` is server-computed and read-only. Rejecting it loudly, rather than silently
     * dropping it, is deliberate: a field that looks writable but is ignored is exactly how
     * the client-side copy of this logic drifted in the first place (#787).
     */
    private function assertPayloadCarriesNoScope(): void
    {
        if (property_exists($this->payload, 'scope')) {
            $description = 'The `scope` property is computed by the server from `applies_to` and is read-only. Remove it from the request body.';
            throw new UnprocessableContentException($description);
        }
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php
```

Expected: PASS.

- [ ] **Step 6: Confirm the corpus still validates**

The schema gained an optional property, so every source file must still validate:

```bash
cd ../LCAPI-tests-rite && vendor/bin/phpunit phpunit_tests/Schemas/SchemaValidationTest.php
```

Expected: PASS.

- [ ] **Step 7: Static analysis, lint, full suite**

```bash
cd ../LCAPI-tests-rite && composer analyse && composer lint && composer test:quick
```

Expected: no analyse/lint errors; at most the one baseline failure.

- [ ] **Step 8: Commit**

```bash
cd ../LCAPI-tests-rite
git add jsondata/schemas/LitCalTest.json src/Handlers/TestsHandler.php phpunit_tests/Handlers/TestsHandlerTest.php
git commit -m "feat(tests): expose the resolved FGA scope on every test (#787)"
```

---

### Task 6: OpenAPI and documentation

**Files:**

- Modify: `jsondata/schemas/openapi.json` — the `/tests` paths
- Modify: `README.md` if it documents `/tests` URLs (check with grep before editing)

**Interfaces:**

- Consumes: the final route shapes and response shape from Tasks 2-5.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Find the current `/tests` documentation**

```bash
cd ../LCAPI-tests-rite && python3 -c "
import json
d = json.load(open('jsondata/schemas/openapi.json'))
print([p for p in d['paths'] if 'test' in p.lower()])
"
grep -rn '/tests/' README.md docs/*.md 2>/dev/null | head -20
```

- [ ] **Step 2: Rewrite the paths**

Replace the `/tests/{test_name}` path object with `/tests/{rite}/{test_name}`, adding a `rite` path parameter:

```json
{
  "name": "rite",
  "in": "path",
  "required": true,
  "description": "The liturgical rite the test is defined under. Part of a test's identity: names are unique within a rite, not globally.",
  "schema": { "type": "string", "enum": ["roman", "ambrosian"] }
}
```

Add a `/tests/{rite}` path with the collection `get` operation (the same response shape as `/tests`, restricted to one rite), and keep
`/tests` documented as the corpus-wide index across all rites.

- [ ] **Step 3: Lint the OpenAPI schema**

```bash
cd ../LCAPI-tests-rite && composer lint:openapi
```

Expected: no errors.

- [ ] **Step 4: Lint markdown if any docs changed**

```bash
cd ../LCAPI-tests-rite && composer lint:md
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
cd ../LCAPI-tests-rite
git add jsondata/schemas/openapi.json README.md
git commit -m "docs(tests): document the rite-aware /tests routes (#787)"
```

---

## Verification before opening the PR

- [ ] `composer test:quick` — at most the one baseline `ExecuteValidationTest` failure
- [ ] `composer analyse` — clean
- [ ] `composer lint` — clean
- [ ] `composer lint:md` — clean
- [ ] `composer lint:openapi` — clean
- [ ] Live probes against a server running this branch, mirroring the #786 commit message's evidence table:

```text
GET  /tests                                 200  all rites
GET  /tests/roman                           200  10 tests
GET  /tests/ambrosian                       200  1 test, scope rite_calendar_test:ambrosian
GET  /tests/roman/MaryMotherChurchTest      200  carries scope
GET  /tests/ambrosian/StIgnatiusOfLoyolaTest 200 carries scope
GET  /tests/MaryMotherChurchTest            400  rite segment required (the hard break)
GET  /tests/roman/StIgnatiusOfLoyolaTest    404  Ambrosian test, not in the Roman partition
PUT  /tests/ambrosian/X  (body rite: roman) 422  rite disagreement
PUT  /tests/roman/X      (body has scope)   422  scope is read-only
```

- [ ] PR targets `development`, never `stable`
- [ ] PR body notes that UnitTestInterface#39 and LiturgicalCalendarFrontend#459 must ship alongside this, because the bare
      `/tests/{name}` form is gone
