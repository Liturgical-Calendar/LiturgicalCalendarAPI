# Issue #572 — Implementation plan

**Spec:** [2026-06-01-issue-572-access-requests-pagination-design.md](../specs/2026-06-01-issue-572-access-requests-pagination-design.md)
**Branch:** `feature/issue-572-access-requests-pagination`
**Date:** 2026-06-01

Five tasks, each TDD: write failing tests → minimal change to pass → verify. Repository first
because the handlers depend on its new shape; trait second because both handlers depend on it.

## Task 1 — Repository: `getAll`/`getByUser` accept `$limit, $offset`; add `countAll`, `countByUser`

### Step 1.1: Write 5 failing tests

`phpunit_tests/Repositories/AccessRequestRepositoryTest.php` — add R1–R5 from spec §7 Layer A.

Per the existing test file's patterns: each test inserts known rows, calls the method, asserts on
the result. Use the same DB-fixture setup the file already has.

### Step 1.2: Run — expect 5 failures

```bash
vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php
```

Failures expected: `getAll`/`getByUser` ignore extra args (no failure on call signature in PHP since
extra args are silently dropped — but our test asserts on sliced result count, which will fail);
`countAll`/`countByUser` don't exist → fatal.

### Step 1.3: Modify `getAll()` — add `$limit, $offset`

Append `LIMIT :limit OFFSET :offset` when both non-null. Bind with `PARAM_INT`. PDO's `execute([...])`
binds everything as a string; for LIMIT/OFFSET we want integer-typed binds, so split prepare/bind/execute
when paginating, keep the array-execute path when not.

### Step 1.4: Modify `getByUser()` — same shape

### Step 1.5: Add `countAll(?string $status = null): int`

Same WHERE clause as `getAll`. Validate status if provided (mirror `getAll()`'s
`InvalidArgumentException`).

### Step 1.6: Add `countByUser(string $userId): int`

`SELECT COUNT(*) FROM access_requests WHERE zitadel_user_id = :user_id`.

### Step 1.7: Run — expect all pass

```bash
vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php
```

### Step 1.8: Verify existing tests still pass

The default-arg back-compat is the existing behavior. Run the file fully.

### Step 1.9: Commit

`feat(repo): paginated getAll/getByUser + countAll/countByUser on AccessRequestRepository (#572)`

## Task 2 — `OffsetPaginationTrait`

### Step 2.1: Create `src/Handlers/Pagination/OffsetPaginationTrait.php`

Body from spec §5. No tests — the trait is exercised via the two handlers' validation tests in
Task 3 / Task 4. (A trait without a using class has no behavior to test in isolation; PHPStan/phpcs
catches the structural issues.)

### Step 2.2: Verify it compiles

```bash
composer parallel-lint  # catches syntax errors
composer analyse        # catches type/PSR issues
```

### Step 2.3: Commit

`feat(handlers): OffsetPaginationTrait for limit/offset parsing (#572)`

## Task 3 — `AccessRequestHandler::listOwnRequests()` pagination

### Step 3.1: Write 5 failing tests (H1–H5 from spec §7 Layer B)

`phpunit_tests/Handlers/Auth/AccessRequestHandlerTest.php`. Use the same handler-test patterns
already in the file (request mock, repository stub or real DB-backed depending on what existing
tests do).

### Step 3.2: Run — expect 5 failures

### Step 3.3: Use the trait, modify `listOwnRequests()` body

Add `use OffsetPaginationTrait;` to the class. Modify `listOwnRequests()` per spec §5. Update the
single call site in `handle()` to pass `$request`.

### Step 3.4: Run — expect all pass

### Step 3.5: Run the full file — back-compat check

Existing tests should keep passing. The envelope-shape assertions are the risk; if existing tests
only check `requests` and `count` they'll pass; if they assert `additionalProperties: false`-style
exact-match they'll need updating to include the new fields.

### Step 3.6: Commit

`feat(api): pagination on GET /auth/access-requests (#572)`

## Task 4 — `AccessRequestAdminHandler::listRequests()` pagination

### Step 4.1: Write 5 failing tests (A1–A5 from spec §7 Layer C)

`phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php`.

### Step 4.2: Run — expect 5 failures

### Step 4.3: Use the trait, modify `listRequests()` body

Add `use OffsetPaginationTrait;`. Modify per spec §5. Resource-admin path still applies
`filterByAdminAccess` post-fetch; document the caveat in code comments matching OpenAPI.

### Step 4.4: Run — expect all pass

### Step 4.5: Run the full file — back-compat check

### Step 4.6: Commit

`feat(api): pagination on GET /admin/access-requests (#572)`

## Task 5 — OpenAPI updates

### Step 5.1: Add `LimitQueryParam` + `OffsetQueryParam` reusable parameter components

Under `components.parameters`. If that key doesn't exist yet, create it.

### Step 5.2: Reference them from both paths

`/auth/access-requests` GET: add `parameters` array with two `$ref`s. `/admin/access-requests` GET:
extend the existing `parameters` array.

### Step 5.3: Extend `AccessRequestListResponse`

Add `total`, `limit`, `offset`, `has_more` properties; update `required`; rewrite `description`.

### Step 5.4: Add `firstPage` + `lastPage` examples to both 200 responses

### Step 5.5: Lint

```bash
composer lint:openapi
```

Fix any Redocly warnings/errors.

### Step 5.6: Commit

`docs(openapi): document pagination on access-requests endpoints (#572)`

## Task 6 — Final verification + PR

### Step 6.1: Full test suite

```bash
composer test
```

### Step 6.2: Static analysis

```bash
composer analyse
```

### Step 6.3: Lint

```bash
composer lint
composer lint:openapi
composer parallel-lint
```

### Step 6.4: Self-review

Walk the spec section by section; for each "the implementation does X" claim, find the line in the
diff. Anything that's drifted from the spec gets a follow-up commit or an update to the spec.

### Step 6.5: Push + open PR

Branch: `feature/issue-572-access-requests-pagination`. Title:
`feat(api): offset pagination on access-requests endpoints (#572)`. Body uses the same template as
PR #623 (Design / Endpoint contract table / Changes table / Tests summary).

PR targets `development`, not `stable`.

## Done

All five tasks green. Spec is honest about everything the PR ships. CodeRabbit gets the diff, we
respond to its review feedback the same way #623 did.
