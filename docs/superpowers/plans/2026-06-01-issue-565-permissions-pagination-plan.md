# Issue #565 — Cursor pagination on /admin/permissions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add cursor-based pagination to `GET /admin/permissions` (limit + page_token in,
`{permissions, count, has_more, next_page_token}` out), removing `OpenFgaClient::readTuples()`'s
internal auto-loop in favor of per-page returns the handler drives.

**Architecture:** Three layers change in lock-step. `OpenFgaClient::readTuples()` becomes a
single-page primitive returning `{tuples, next_continuation_token}`.
`PermissionAdminHandler::listPermissions()` parses `limit`/`page_token`, calls the client once,
builds the new envelope (preserving the existing `filterByAdminAccess` post-processing for
non-global admins without `object_id`). OpenAPI gains two query params, a new
`AdminListPermissionsResponse` schema, and two examples. A small constructor-injection refactor on
the handler enables a focused mock-driven integration test.

**Tech Stack:** PHP 8.4+, PSR-7/15/17, PHPUnit, PHPStan L10, phpcs PSR-12, Redocly (OpenAPI), markdownlint, Guzzle MockHandler, CaptainHook.

**Reference spec:** `docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md`

**Pre-flight (one-time, run once before Task 1):**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI
git checkout development
git pull --ff-only origin development
git checkout -b feature/issue-565-permissions-pagination
docker compose up -d --build      # bring up Postgres + Zitadel + OpenFGA + migrations
composer install                  # ensure vendor/ is fresh
```

Expected: docker compose reports `liturgicalcalendarapi-{db,zitadel,openfga,mailpit,adminer}-1` all
healthy and `liturgicalcalendarapi-litcal-migrate-1` exited 0. `composer install` shows "Nothing to
install".

Baseline sanity check (these should pass on `development` before any changes):

```bash
vendor/bin/phpunit phpunit_tests/Services/OpenFgaClientTest.php
vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
composer analyse
composer lint
```

Expected: all green. (If any are red on the baseline, stop and investigate before touching the new work.)

---

## Task 1 — `OpenFgaClient::readTuples()` cursor pagination

**Files:**

- Modify: `src/Services/OpenFgaClient.php` lines 225-307 (the `readTuples` method body and docblock)
- Modify: `phpunit_tests/Services/OpenFgaClientTest.php` (update 2 existing tests for new return shape; add 5 new tests)

**Ordering rationale:** Client first because the handler depends on its new return shape; if we did handler-first, both sides would be momentarily inconsistent.

### Step 1.1: Update the two existing `readTuples` tests for the new return shape

In `phpunit_tests/Services/OpenFgaClientTest.php`, find `testReadTuplesReturnsParsedTuples` and `testReadTuplesReturnsEmptyArrayWhenNoTuples`.

The current tests assert against a flat `array<int, array{user, relation, object}>`. After the
refactor, `readTuples()` returns `array{tuples: list<...>, next_continuation_token: string}`. Update
the assertions.

For `testReadTuplesReturnsParsedTuples`:

```php
public function testReadTuplesReturnsParsedTuples(): void
{
    $mock   = new MockHandler([
        new Response(200, [], (string) json_encode([
            'tuples' => [
                ['key' => ['user' => 'user:alice',  'relation' => 'editor', 'object' => 'national_calendar:IT']],
                ['key' => ['user' => 'user:bob',    'relation' => 'viewer', 'object' => 'national_calendar:IT']],
            ],
            'continuation_token' => '',
        ])),
    ]);
    $client = $this->createClientWithMock($mock);

    $result = $client->readTuples('', 'national_calendar:IT');

    self::assertSame('', $result['next_continuation_token']);
    self::assertCount(2, $result['tuples']);
    self::assertSame(['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT'], $result['tuples'][0]);
    self::assertSame(['user' => 'user:bob',   'relation' => 'viewer', 'object' => 'national_calendar:IT'], $result['tuples'][1]);
}
```

For `testReadTuplesReturnsEmptyArrayWhenNoTuples`:

```php
public function testReadTuplesReturnsEmptyArrayWhenNoTuples(): void
{
    $mock   = new MockHandler([
        new Response(200, [], (string) json_encode([
            'tuples'             => [],
            'continuation_token' => '',
        ])),
    ]);
    $client = $this->createClientWithMock($mock);

    $result = $client->readTuples('', 'national_calendar:IT');

    self::assertSame([], $result['tuples']);
    self::assertSame('', $result['next_continuation_token']);
}
```

### Step 1.2: Add 5 new tests covering the new behavior

Append to the same class (before the closing `}`). These tests need access to the Guzzle request
history; the existing `createClientWithMock()` helper doesn't capture it, so add a sibling helper at
the top of the class (next to the existing helper):

```php
/**
 * @var list<array<string, mixed>>
 */
private array $requestHistory = [];

private function createClientCapturingRequests(MockHandler $mock): OpenFgaClient
{
    $this->requestHistory = [];
    $handlerStack         = HandlerStack::create($mock);
    $handlerStack->push(\GuzzleHttp\Middleware::history($this->requestHistory));
    $httpClient = new Client(['handler' => $handlerStack]);
    $psr17      = new \Nyholm\Psr7\Factory\Psr17Factory();

    return new OpenFgaClient(
        'http://localhost:8083',
        'store-123',
        'model-456',
        $httpClient,
        $psr17,
        $psr17
    );
}

/**
 * Pull the JSON-decoded payload of the Nth recorded request.
 *
 * @return array<string, mixed>
 */
private function decodedRequestPayload(int $index): array
{
    self::assertArrayHasKey($index, $this->requestHistory, "no request at index {$index}");
    $tx = $this->requestHistory[$index];
    self::assertArrayHasKey('request', $tx);
    $body = (string) $tx['request']->getBody();
    $decoded = json_decode($body, true);
    self::assertIsArray($decoded);
    return $decoded;
}
```

(Imports needed at the top of the file: `use GuzzleHttp\Middleware;` if not already present.)

Now the 5 new test methods:

```php
public function testReadTuplesPassesLimitAndContinuationToken(): void
{
    $mock = new MockHandler([
        new Response(200, [], (string) json_encode([
            'tuples'             => [['key' => ['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT']]],
            'continuation_token' => 'xyz',
        ])),
    ]);
    $client = $this->createClientCapturingRequests($mock);

    $result = $client->readTuples('user:alice', 'national_calendar:IT', null, 50, 'abc');

    self::assertSame('xyz', $result['next_continuation_token']);
    self::assertCount(1, $result['tuples']);

    $payload = $this->decodedRequestPayload(0);
    self::assertSame(50, $payload['page_size']);
    self::assertSame('abc', $payload['continuation_token']);
    self::assertSame(['user' => 'user:alice', 'object' => 'national_calendar:IT'], $payload['tuple_key']);
}

public function testReadTuplesOmitsLimitWhenNull(): void
{
    $mock   = new MockHandler([
        new Response(200, [], (string) json_encode([
            'tuples'             => [],
            'continuation_token' => '',
        ])),
    ]);
    $client = $this->createClientCapturingRequests($mock);

    $client->readTuples('', 'national_calendar:IT');

    $payload = $this->decodedRequestPayload(0);
    self::assertArrayNotHasKey('page_size', $payload);
    self::assertArrayNotHasKey('continuation_token', $payload);
}

public function testReadTuplesOmitsContinuationTokenWhenNullOrEmpty(): void
{
    $mock   = new MockHandler([
        new Response(200, [], (string) json_encode(['tuples' => [], 'continuation_token' => ''])),
        new Response(200, [], (string) json_encode(['tuples' => [], 'continuation_token' => ''])),
    ]);
    $client = $this->createClientCapturingRequests($mock);

    // null continuation token
    $client->readTuples('', 'national_calendar:IT', null, 10, null);
    self::assertArrayNotHasKey('continuation_token', $this->decodedRequestPayload(0));

    // empty-string continuation token
    $client->readTuples('', 'national_calendar:IT', null, 10, '');
    self::assertArrayNotHasKey('continuation_token', $this->decodedRequestPayload(1));
}

public function testReadTuplesReturnsEmptyTokenWhenServerOmits(): void
{
    $mock   = new MockHandler([
        new Response(200, [], (string) json_encode(['tuples' => []])),  // no continuation_token field at all
    ]);
    $client = $this->createClientWithMock($mock);

    $result = $client->readTuples('', 'national_calendar:IT');

    self::assertSame('', $result['next_continuation_token']);
}

public function testReadTuplesNoLongerAutoPaginates(): void
{
    // First response carries a continuation_token. The old auto-loop would
    // have fetched a second page; the new contract returns immediately and
    // hands the token back to the caller.
    $mock = new MockHandler([
        new Response(200, [], (string) json_encode([
            'tuples'             => [['key' => ['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT']]],
            'continuation_token' => 'tok2',
        ])),
        // A second response is queued but should NEVER be consumed.
        new Response(500, [], 'should not be reached'),
    ]);
    $client = $this->createClientCapturingRequests($mock);

    $result = $client->readTuples('', 'national_calendar:IT');

    self::assertSame('tok2', $result['next_continuation_token']);
    self::assertCount(1, $this->requestHistory);  // exactly ONE HTTP call
}
```

- [ ] **Step 1.3: Run the updated/new tests and confirm they fail**

```bash
vendor/bin/phpunit phpunit_tests/Services/OpenFgaClientTest.php
```

Expected: 2 existing-test failures (`testReadTuplesReturnsParsedTuples`,
`testReadTuplesReturnsEmptyArrayWhenNoTuples`) due to assertion shape mismatch, plus 5 errors on the
new tests (mostly "wrong number of arguments to readTuples" — old signature has 3 params, new tests
call with 5).

- [ ] **Step 1.4: Replace the `readTuples()` body**

In `src/Services/OpenFgaClient.php`, replace the entire method body (currently lines 225-307) with:

```php
/**
 * Read a single page of OpenFGA tuples matching the given filter.
 *
 * Pagination is caller-driven — pass `$continuationToken` from the previous
 * call's `next_continuation_token` to fetch the next page. The empty string
 * means "no more pages".
 *
 * @param string      $user              User identifier (e.g., "user:zitadel-user-id"); '' for no user filter
 * @param string      $object            Object type or full object (e.g., "national_calendar:" or "national_calendar:IT"); '' for no object filter
 * @param string|null $relation          Optional relation filter
 * @param int|null    $limit             Optional max items in this page; null lets OpenFGA apply its server-side default
 * @param string|null $continuationToken Opaque cursor from a previous response; null/'' means "first page"
 * @return array{
 *     tuples: list<array{user: string, relation: string, object: string}>,
 *     next_continuation_token: string
 * }
 * @throws RuntimeException If the API request fails
 */
public function readTuples(
    string $user,
    string $object,
    ?string $relation = null,
    ?int $limit = null,
    ?string $continuationToken = null,
): array {
    $tupleKey = [];
    if ($user !== '') {
        $tupleKey['user'] = $user;
    }
    if ($object !== '') {
        $tupleKey['object'] = $object;
    }
    if ($relation !== null && $relation !== '') {
        $tupleKey['relation'] = $relation;
    }

    $payload = count($tupleKey) > 0 ? ['tuple_key' => $tupleKey] : [];
    if ($limit !== null) {
        $payload['page_size'] = $limit;
    }
    if ($continuationToken !== null && $continuationToken !== '') {
        $payload['continuation_token'] = $continuationToken;
    }

    $response = $this->post("/stores/{$this->storeId}/read", $payload);

    $tuples         = [];
    $responseTuples = $response['tuples'] ?? [];
    if (is_array($responseTuples)) {
        foreach ($responseTuples as $tuple) {
            if (!is_array($tuple)) {
                continue;
            }
            $key           = is_array($tuple['key'] ?? null) ? $tuple['key'] : [];
            $tupleUser     = is_string($key['user'] ?? null) ? $key['user'] : '';
            $tupleRelation = is_string($key['relation'] ?? null) ? $key['relation'] : '';
            $tupleObject   = is_string($key['object'] ?? null) ? $key['object'] : '';
            if ($tupleUser === '' || $tupleRelation === '' || $tupleObject === '') {
                continue;
            }
            $tuples[] = [
                'user'     => $tupleUser,
                'relation' => $tupleRelation,
                'object'   => $tupleObject,
            ];
        }
    }

    $nextToken = is_string($response['continuation_token'] ?? null)
        ? $response['continuation_token']
        : '';

    return [
        'tuples'                  => $tuples,
        'next_continuation_token' => $nextToken,
    ];
}
```

Removed: the `do { ... } while ($continuationToken !== '' && $page < $maxPages);` loop, the 100-page
cap, the `throw new \RuntimeException(...)` for hitting the cap, the page counter, the accumulating
`$tuples` across iterations, the in-loop `$payload['continuation_token']` mutation.

Renamed local var inside the loop: the old code shadowed the outer `$user` parameter inside the
foreach (`$user = is_string($key['user']...)`) — the new code uses `$tupleUser` to avoid that.

- [ ] **Step 1.5: Run tests — all pass**

```bash
vendor/bin/phpunit phpunit_tests/Services/OpenFgaClientTest.php
```

Expected: 9/9 pass (2 updated + 5 new + 2 unrelated existing tests still passing).

If any fail:

- "Argument 4 must be of type int" → step 1.4 not applied (signature still old).
- "Undefined index: tuples" in the updated tests → the OLD test body wasn't replaced.
- "Failed asserting that count matches" on `testReadTuplesNoLongerAutoPaginates` → the auto-loop wasn't removed (still consuming the second mock response).

- [ ] **Step 1.6: Static analysis**

```bash
composer analyse
```

Expected: `[OK] No errors`. PHPStan L10 must stay clean. The new `@return array{tuples: list<...>, next_continuation_token: string}` should narrow well.

- [ ] **Step 1.7: Lint**

```bash
composer lint
```

Expected: 0 violations. If any: `composer lint:fix` and re-run.

- [ ] **Step 1.8: Commit**

```bash
git add src/Services/OpenFgaClient.php phpunit_tests/Services/OpenFgaClientTest.php
git commit -m "$(cat <<'EOF'
refactor: OpenFgaClient::readTuples returns a single page (issue #565)

The auto-pagination loop (with its 100-page hard cap) is removed. The
caller now drives pagination via $limit + $continuationToken params and
inspects the returned next_continuation_token to decide whether to
fetch another page. Single-page primitive aligns with "handler owns
pagination policy" — the only caller (PermissionAdminHandler) wants
exactly this behavior.

Signature:
- BEFORE: readTuples(string, string, ?string): array<int, {user,relation,object}>
- AFTER:  readTuples(string, string, ?string, ?int, ?string): {
              tuples: list<{user,relation,object}>,
              next_continuation_token: string
          }

Tests:
- Updated 2 existing tests for the new return shape.
- Added 5: limit+token forwarding, omission semantics, server-omitted
  token defaults to '', and a regression test that locks in "no more
  silent auto-pagination" (a second mock response is queued and asserted
  unused).

Refs: docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md §4

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Expected: CaptainHook pre-commit passes (phpcs, parallel-lint).

---

## Task 2 — `PermissionAdminHandler` constructor injection

**Files:**

- Modify: `src/Handlers/Admin/PermissionAdminHandler.php` (constructor only)

**Goal:** Make the handler accept an optional `?OpenFgaClient $client` so tests can inject a mock.
Router-side instantiation (`new PermissionAdminHandler()`) keeps working unchanged because the new
param defaults to `null`. No behavior change — Task 3 builds the test that exercises injection.

- [ ] **Step 2.1: Modify the constructor**

In `src/Handlers/Admin/PermissionAdminHandler.php`, replace lines 64-73:

```php
public function __construct()
{
    parent::__construct();

    $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST, RequestMethod::DELETE];
    $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
    $this->allowedRequestContentTypes = [RequestContentType::JSON];
    $this->allowCredentials           = true;
    $this->logger                     = LoggerFactory::create('admin', null, 30, false, true, false);
}
```

With:

```php
public function __construct(?OpenFgaClient $client = null)
{
    parent::__construct();

    // Pre-seed the lazy client slot so tests can inject a mock.
    // When null, getClient() falls back to OpenFgaClient::fromEnv()
    // on first use (existing behavior, unchanged).
    $this->fgaClient = $client;

    $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST, RequestMethod::DELETE];
    $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
    $this->allowedRequestContentTypes = [RequestContentType::JSON];
    $this->allowCredentials           = true;
    $this->logger                     = LoggerFactory::create('admin', null, 30, false, true, false);
}
```

`getClient()` (lines 75-81) needs no change — its existing `if ($this->fgaClient === null)` branch handles both cases.

- [ ] **Step 2.2: Verify nothing regressed**

```bash
vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
```

Expected: all currently-passing tests still pass. The injection point isn't exercised yet (added in Task 3).

```bash
composer analyse
```

Expected: `[OK] No errors`. The `?OpenFgaClient` import already exists in the file (it's used by `getClient()`), so no `use` line needs adding.

```bash
composer lint
```

Expected: clean.

- [ ] **Step 2.3: Commit**

```bash
git add src/Handlers/Admin/PermissionAdminHandler.php
git commit -m "$(cat <<'EOF'
refactor: PermissionAdminHandler constructor takes optional OpenFgaClient (issue #565)

Pre-seeds the lazy \$this->fgaClient slot. When the new param is null
(Router path: \`new PermissionAdminHandler()\`), getClient() still
falls back to OpenFgaClient::fromEnv() on first use — zero behavior
change for production.

Tests in the next commit can now inject a mock client to verify
wire-level forwarding (the back-compat acceptance criterion in #565
needs this seam).

Mirrors the repository-injection convention from issue #573
(UserNotificationRepository::__construct(?\PDO \$db = null)).

Refs: docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md §5

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — `PermissionAdminHandler::listPermissions()` cursor pagination

**Files:**

- Modify: `src/Handlers/Admin/PermissionAdminHandler.php` — add 2 constants near the top of the
class, add private `parseLimit()` helper, replace `listPermissions()` body lines 170-252.
- Modify: `phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php` — add 5 validation tests + 1 integration test.

**TDD ordering:** Validation tests first (against still-old handler) → all fail with "no such field"
or "wrong status" → modify handler → tests pass. Then the integration test follows the same pattern.

### Step 3.1: Write the 5 validation tests

In `phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php`, add (inside the class, before the closing `}`):

```php
public function testListWithLimitZeroIsValidationError(): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('limit must be between 1 and 500');

    ( new PermissionAdminHandler() )->handle(
        $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=0'))
    );
}

public function testListWithLimitTooLargeIsValidationError(): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('limit must be between 1 and 500');

    ( new PermissionAdminHandler() )->handle(
        $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=501'))
    );
}

public function testListWithNonNumericLimitIsValidationError(): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('limit must be a positive integer');

    ( new PermissionAdminHandler() )->handle(
        $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=abc'))
    );
}

public function testListWithNegativeLimitIsValidationError(): void
{
    // ctype_digit('-1') is false, so this hits the "positive integer" branch.
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('limit must be a positive integer');

    ( new PermissionAdminHandler() )->handle(
        $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=-1'))
    );
}

public function testListWithLimitAtUpperBoundPassesValidation(): void
{
    // At limit=500 the parseLimit() helper accepts the value and the handler
    // proceeds past validation. With OpenFGA not configured in tests, the
    // call to OpenFgaClient::fromEnv() fails — caught here as a
    // RuntimeException. The point of the test is that we DON'T see a
    // ValidationException about limit before that.
    $this->expectException(\RuntimeException::class);
    // (no exceptionMessage assertion — we only care that it's NOT a
    // ValidationException about limit)

    ( new PermissionAdminHandler() )->handle(
        $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=500'))
    );
}
```

If the existing test file's `use` block doesn't already import `ValidationException`, add at the top:

```php
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
```

(Per the explore reports it's likely already imported — there are existing validation tests like `testNonAdminWithoutObjectTypeIsValidationError`.)

### Step 3.2: Run them — expect 5 failures

```bash
vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
```

Expected: 5 new failures. The first 4 expect `ValidationException` with limit-related messages that
the current handler doesn't emit (it never reads `limit`). They'll likely throw
`RuntimeException('OPENFGA_API_URL is not configured')` (or similar) from `OpenFgaClient::fromEnv()`
instead. `testListWithLimitAtUpperBoundPassesValidation` is the only one that might already pass (it
expects the RuntimeException path) — that's OK.

### Step 3.3: Add constants + parseLimit helper

In `src/Handlers/Admin/PermissionAdminHandler.php`, add near the top of the class (after the existing class declaration / property block):

```php
private const DEFAULT_LIMIT = 100;
private const MAX_LIMIT     = 500;
```

Then add a new private method (place near other private helpers in the class):

```php
/**
 * Parse the `limit` query param: returns DEFAULT_LIMIT when absent/empty,
 * throws ValidationException when present but invalid or out of range.
 */
private function parseLimit(mixed $raw): int
{
    if ($raw === null || $raw === '') {
        return self::DEFAULT_LIMIT;
    }
    if (!is_string($raw) || !ctype_digit($raw)) {
        throw new ValidationException('limit must be a positive integer');
    }
    $limit = (int) $raw;
    if ($limit < 1 || $limit > self::MAX_LIMIT) {
        throw new ValidationException(sprintf(
            'limit must be between 1 and %d',
            self::MAX_LIMIT
        ));
    }
    return $limit;
}
```

### Step 3.4: Replace `listPermissions()` body

Replace the entire method body (lines 170-252) with:

```php
/**
 * GET /admin/permissions — List relationship tuples with cursor pagination.
 *
 * Global admins see all tuples. Resource admins see only tuples
 * for resources they administer (filterByAdminAccess is applied
 * post-fetch when object_id is unset).
 *
 * Query parameters:
 *   - user, object_type, object_id, relation: filters (existing)
 *   - limit: max items in this page (1..500, default 100)
 *   - page_token: opaque cursor from a previous response's
 *     `next_page_token`; empty/omitted means first page
 *
 * Note: when filterByAdminAccess is applied, the page returned may be
 * smaller than `limit` (some OpenFGA tuples in the page are filtered
 * out). `has_more` continues to reflect OpenFGA's pagination state, so
 * clients should keep paging until `has_more` is false even if a page
 * comes back smaller than expected.
 */
private function listPermissions(
    ServerRequestInterface $request,
    ResponseInterface $response,
    string $userId,
    bool $isGlobalAdmin
): ResponseInterface {
    $params     = $request->getQueryParams();
    $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
    $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
    $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
    $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';
    $limit      = $this->parseLimit($params['limit'] ?? null);
    $pageToken  = is_string($params['page_token'] ?? null) ? $params['page_token'] : '';

    if (!$isGlobalAdmin && $objectType === '') {
        throw new ValidationException('Resource admins must specify object_type filter');
    }

    if ($objectType !== '' && !in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
        throw new ValidationException(
            sprintf('Invalid object_type. Valid types: %s', implode(', ', self::VALID_OBJECT_TYPES))
        );
    }

    if ($relation !== '' && !in_array($relation, self::VALID_RELATIONS, true)) {
        throw new ValidationException(
            sprintf('Invalid relation. Valid relations: %s', implode(', ', self::VALID_RELATIONS))
        );
    }

    if (!$isGlobalAdmin && $objectId !== '') {
        $this->requireResourceAdmin($userId, false, $objectType, $objectId);
    }

    $normalizedUser = $user !== '' ? $this->normalizeUser($user) : '';
    $relationFilter = $relation !== '' ? $relation : null;
    $objectFilter   = $objectType !== ''
        ? ( $objectId !== '' ? "{$objectType}:{$objectId}" : "{$objectType}:" )
        : '';

    $page = $this->getClient()->readTuples(
        $normalizedUser,
        $objectFilter,
        $relationFilter,
        $limit,
        $pageToken === '' ? null : $pageToken
    );

    /** @var list<array{user: string, relation: string, object: string}> $tuples */
    $tuples    = $page['tuples'];
    $nextToken = $page['next_continuation_token'];

    // Preserve the existing post-filter for resource admins listing without
    // a specific object_id. May reduce this page's item count below `limit`.
    if (!$isGlobalAdmin && $objectType !== '' && $objectId === '') {
        $tuples = $this->filterByAdminAccess($tuples, $userId, $objectType);
    }

    $hasMore = $nextToken !== '';

    return $this->encodeResponseBody($response, [
        'permissions'     => $tuples,
        'count'           => count($tuples),
        'has_more'        => $hasMore,
        'next_page_token' => $hasMore ? $nextToken : null,
    ]);
}
```

Key differences from the previous body:

- New `$limit` and `$pageToken` extraction via `parseLimit()` + direct string check.
- The `if ($objectType === '')` branch (global admin no-filter) and the `else` branch (filtered) collapse to a single `readTuples()` call — `$objectFilter` is constructed once.
- `filterByAdminAccess` is preserved but only fires for the same condition as before (non-global admin + object_type set + no object_id).
- Response envelope adds `has_more` and `next_page_token`; `count` keeps post-filter semantics (matches existing code).

### Step 3.5: Run validation tests — expect all pass

```bash
vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
```

Expected: 5 new validation tests pass, all previously-passing tests still pass.

If `testListWithLimitAtUpperBoundPassesValidation` fails with `ValidationException` (not
`RuntimeException`), the issue is the helper's `MAX_LIMIT` boundary check — verify `$limit >
self::MAX_LIMIT` (strict greater-than) not `>=`.

### Step 3.6: Add the integration test

In the same test file, add (before the closing `}`):

```php
public function testListDefaultsToLimit100AndNoToken(): void
{
    // Stub OpenFGA's /read endpoint to capture the request and return an
    // empty result. Verifies (a) the handler sends page_size=100 when no
    // limit is provided, (b) it omits continuation_token entirely when no
    // page_token is provided, and (c) the response envelope has the new
    // shape with has_more=false and next_page_token=null.
    $requestHistory = [];
    $mock           = new MockHandler([
        new Response(200, [], (string) json_encode([
            'tuples'             => [],
            'continuation_token' => '',
        ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
    $httpClient = new Client(['handler' => $handlerStack]);
    $psr17      = new \Nyholm\Psr7\Factory\Psr17Factory();
    $fgaClient  = new OpenFgaClient(
        'http://localhost:8083',
        'store-123',
        'model-456',
        $httpClient,
        $psr17,
        $psr17
    );

    $response = ( new PermissionAdminHandler($fgaClient) )->handle(
        $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar'))
    );

    self::assertSame(200, $response->getStatusCode());
    $body = $this->decodeJsonBody($response);
    self::assertSame([], $body['permissions']);
    self::assertSame(0, $body['count']);
    self::assertFalse($body['has_more']);
    self::assertNull($body['next_page_token']);

    self::assertCount(1, $requestHistory);
    $payload = json_decode((string) $requestHistory[0]['request']->getBody(), true);
    self::assertIsArray($payload);
    self::assertSame(100, $payload['page_size']);
    self::assertArrayNotHasKey('continuation_token', $payload);
}
```

Imports needed at the top of the test file (only add the ones not already present — most will be):

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
```

### Step 3.7: Run integration test — expect pass

```bash
vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
```

Expected: 12-13 tests pass (existing 7 + 5 new validation + 1 new integration). All green.

If the integration test fails:

- 500 status from the handler → the mocked OpenFgaClient is being bypassed because `getClient()` is
still calling `fromEnv()`. Verify Task 2's constructor change is in place (`$this->fgaClient =
$client;`).
- `Undefined index: has_more` → the listPermissions body wasn't replaced. Re-check Step 3.4.

### Step 3.8: Static analysis + lint

```bash
composer analyse
composer lint
```

Both must be clean. If PHPStan reports issues on the new shape, the most likely culprit is the docblock `@return` array shape — adjust the type narrowing.

### Step 3.9: Commit

```bash
git add src/Handlers/Admin/PermissionAdminHandler.php \
        phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(api): cursor pagination on GET /admin/permissions (issue #565)

PermissionAdminHandler::listPermissions() gains limit + page_token
query params, validates limit (1..500, default 100), forwards to
the new readTuples signature, and builds the cursor-paginated
envelope { permissions, count, has_more, next_page_token }.

- Two-branch readTuples calls collapse to one — \$objectFilter is
  constructed once, the rest of the handler logic is identical.
- filterByAdminAccess is preserved for non-global admins without
  object_id; documented in the method docblock that a filtered page
  may be smaller than \`limit\` (clients keep paging until
  has_more is false).
- parseLimit helper: ctype_digit strictness rejects negatives,
  decimals, scientific notation, leading +.

Tests (6 new):
- 4 validation cases (limit=0, 501, abc, -1)
- 1 boundary case (limit=500 passes validation)
- 1 integration test using constructor-injected OpenFgaClient with
  a Guzzle MockHandler, verifies the back-compat acceptance
  criterion (default limit=100, no token) end-to-end.

Refs: docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md §5, §7

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 — OpenAPI updates

**Files:**

- Modify: `jsondata/schemas/openapi.json` — `paths["/admin/permissions"].get`, `components.schemas.AdminListPermissionsResponse` (new).

- [ ] **Step 4.1: Add the two new query parameters**

In `jsondata/schemas/openapi.json`, find the `parameters` array of `paths["/admin/permissions"].get`. Append (keep the existing four filter params; insert these after them):

```json
{
  "name": "limit",
  "in": "query",
  "required": false,
  "description": "Maximum number of permission tuples to return in this page. Default 100, max 500.",
  "schema": {
    "type": "integer",
    "minimum": 1,
    "maximum": 500,
    "default": 100
  }
},
{
  "name": "page_token",
  "in": "query",
  "required": false,
  "description": "Opaque pagination cursor returned in the previous response's `next_page_token`. Omit (or pass empty) to request the first page.",
  "schema": {
    "type": "string"
  }
}
```

- [ ] **Step 4.2: Add the new component schema**

Under `components.schemas` (alphabetical placement is fine; near `AdminUsersResponse`), add:

```json
"AdminListPermissionsResponse": {
  "type": "object",
  "description": "Cursor-paginated page of OpenFGA permission tuples. Use `next_page_token` to fetch the next page; `null` means this was the last page. Note: when the handler applies a server-side admin-access filter, the returned `permissions` array may be shorter than `limit` — clients should keep paging until `has_more` is false.",
  "properties": {
    "permissions": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/PermissionTuple" },
      "description": "Tuples in this page, up to the requested `limit` (may be fewer after server-side filtering)."
    },
    "count": {
      "type": "integer",
      "minimum": 0,
      "description": "Number of tuples in this page (`permissions.length`). NOT a total count across all pages — OpenFGA does not expose an O(1) total."
    },
    "has_more": {
      "type": "boolean",
      "description": "True iff the OpenFGA store has more tuples matching the filter beyond this page."
    },
    "next_page_token": {
      "type": ["string", "null"],
      "description": "Opaque continuation token to pass back as `page_token` for the next page. `null` when `has_more` is false."
    }
  },
  "required": ["permissions", "count", "has_more", "next_page_token"],
  "additionalProperties": false
}
```

- [ ] **Step 4.3: Update the 200 response to `$ref` the new component, add examples**

In `paths["/admin/permissions"].get.responses.200`, replace the existing inline schema with:

```json
"200": {
  "description": "Paginated list of permission tuples.",
  "content": {
    "application/json": {
      "schema": { "$ref": "#/components/schemas/AdminListPermissionsResponse" },
      "examples": {
        "firstPage": {
          "summary": "First page of results — more remain",
          "value": {
            "permissions": [
              { "user": "user:zitadel-123", "relation": "editor", "object": "national_calendar:IT" },
              { "user": "user:zitadel-456", "relation": "viewer", "object": "national_calendar:IT" }
            ],
            "count": 2,
            "has_more": true,
            "next_page_token": "eyJwayI6IjAxIDM2MmIzZWM..."
          }
        },
        "lastPage": {
          "summary": "Final page — no more results",
          "value": {
            "permissions": [
              { "user": "user:zitadel-789", "relation": "deleter", "object": "national_calendar:IT" }
            ],
            "count": 1,
            "has_more": false,
            "next_page_token": null
          }
        }
      }
    }
  }
}
```

(Leave 400/401/403 responses on this path unchanged.)

- [ ] **Step 4.4: Lint OpenAPI**

```bash
composer lint:openapi
```

Expected: 0 errors. Common pitfalls:

- `"type": ["string", "null"]` is OpenAPI 3.1 only; this project uses 3.1, so it parses cleanly.
- If Redocly complains about the `$ref` resolution, double-check the schema name spelling matches `AdminListPermissionsResponse` exactly.

- [ ] **Step 4.5: Verify JSON validity**

```bash
jq . jsondata/schemas/openapi.json > /dev/null && echo OK
```

Expected: `OK`.

- [ ] **Step 4.6: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "$(cat <<'EOF'
docs(openapi): cursor pagination on /admin/permissions (issue #565)

- Add limit (1..500, default 100) and page_token query params.
- New component schema AdminListPermissionsResponse with
  permissions, count, has_more, next_page_token.
- 200 response now \$refs the new schema; adds firstPage and
  lastPage examples.

Not reusing the existing PaginationMetadata component (offset-based,
used by /admin/users) because that has different pagination semantics.

Refs: docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md §6

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 — Final verification + PR

- [ ] **Step 5.1: Full unit test suite**

```bash
composer test:quick
```

Expected: all currently-passing tests still pass. The 3 known pre-existing failures (#619/#620
territory) were already fixed in PR #622; the suite should be fully green now apart from the route
tests that need a running API server.

- [ ] **Step 5.2: Targeted suite (the changes)**

```bash
vendor/bin/phpunit phpunit_tests/Services/OpenFgaClientTest.php phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
```

Expected: all green (9 OpenFgaClient + 13 PermissionAdminHandler tests, ≈22 total).

- [ ] **Step 5.3: PHPStan L10**

```bash
composer analyse
```

Expected: `[OK] No errors`.

- [ ] **Step 5.4: phpcs**

```bash
composer lint
```

Expected: clean.

- [ ] **Step 5.5: Redocly**

```bash
composer lint:openapi
```

Expected: 0 errors.

- [ ] **Step 5.6: markdownlint (only docs we touched)**

```bash
npx --yes markdownlint-cli \
  docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md \
  docs/superpowers/plans/2026-06-01-issue-565-permissions-pagination-plan.md
```

Expected: 0 errors.

- [ ] **Step 5.7: parallel-lint**

```bash
composer parallel-lint
```

Expected: 0 syntax errors across all PHP files.

- [ ] **Step 5.8: Optional smoke test against running API**

If the docker stack is up and you want a manual sanity check:

```bash
composer start                          # starts the host PHP server on :8000
# (in another shell or background)
TOKEN=$(curl -fsS -X POST http://localhost:8000/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"password"}' | jq -r '.access_token')

curl -i "http://localhost:8000/admin/permissions?object_type=national_calendar&limit=10" \
  -H "Authorization: Bearer $TOKEN"
# Verify response is { permissions:[...], count:N, has_more:bool, next_page_token: string|null }

curl -i "http://localhost:8000/admin/permissions?object_type=national_calendar&limit=0" \
  -H "Authorization: Bearer $TOKEN"
# Verify 400 with "limit must be between 1 and 500"

composer stop
```

Pure smoke — failure would re-open Task 3 for debugging.

- [ ] **Step 5.9: Push branch**

```bash
git push -u origin feature/issue-565-permissions-pagination
```

- [ ] **Step 5.10: Open PR**

`````bash
gh pr create --base development \
  --title 'feat(api): cursor pagination on GET /admin/permissions (#565)' \
  --body "$(cat <<'EOF'
Closes #565.

Adds cursor-based pagination to `GET /admin/permissions`.
`OpenFgaClient::readTuples()` becomes a single-page primitive (no more silent
auto-loop with 100-page cap); the handler drives pagination via the new
`limit` and `page_token` query params and returns the new
`{permissions, count, has_more, next_page_token}` envelope.

## Design

Cursor passthrough — `next_page_token` on the wire IS OpenFGA's `continuation_token`. Clients treat it as opaque. Default limit 100, max 500.

See `docs/superpowers/specs/2026-06-01-issue-565-permissions-pagination-design.md` for the full design and `docs/superpowers/plans/2026-06-01-issue-565-permissions-pagination-plan.md` for the implementation plan.

## Endpoints

`GET /admin/permissions?object_type=national_calendar&limit=50&page_token=<opaque>` →

```json
{
  "permissions": [{ "user": "user:zitadel-123", "relation": "editor", "object": "national_calendar:IT" }, ...],
  "count": 50,
  "has_more": true,
  "next_page_token": "eyJ..."
}
```

Final page: `has_more: false`, `next_page_token: null`.

## Changes

| Layer   | File                                                          | Notes                                                                                                                                                                                                                                               |
| ------- | ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Client  | `src/Services/OpenFgaClient.php`                              | `readTuples()` gains `?int $limit, ?string $continuationToken`; returns `{tuples, next_continuation_token}`. Auto-loop removed.                                                                                                                     |
| Handler | `src/Handlers/Admin/PermissionAdminHandler.php`               | Constructor takes `?OpenFgaClient $client = null` for testability. `listPermissions()` parses + validates `limit` (helper), forwards to client, builds the new envelope. `filterByAdminAccess` preserved for non-global admins without `object_id`. |
| Docs    | `jsondata/schemas/openapi.json`                               | Two new query params, new `AdminListPermissionsResponse` component, `firstPage` + `lastPage` examples.                                                                                                                                              |
| Tests   | `phpunit_tests/Services/OpenFgaClientTest.php`                | 2 existing tests updated, 5 added (limit/token forwarding, omission semantics, no-auto-pagination regression).                                                                                                                                      |
| Tests   | `phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php` | 5 validation tests + 1 integration test (mock-injected client, verifies back-compat default-limit behavior).                                                                                                                                        |

## Tests

- 9 OpenFgaClient unit tests (was 4), all green.
- 13 PermissionAdminHandler tests (was 7), all green.
- `composer analyse` (PHPStan L10): clean.
- `composer lint` (phpcs PSR-12): clean.
- `composer lint:openapi` (Redocly): clean.

## Notable design decision

When `filterByAdminAccess` post-filters a page (non-global admin, no `object_id`), the returned page
may be shorter than `limit`. `has_more` still reflects OpenFGA's pagination state, so clients should
keep paging until `has_more === false` even if a page is short. Documented in the OpenAPI
description.

## Out of scope (deferred)

- Pagination for the other admin endpoints (#572 covers `/auth/access-requests` and `/admin/access-requests`).
- Token wrapping/signing (current `next_page_token` is raw OpenFGA continuation token).
- Server-side total count exposure (OpenFGA has no O(1) total).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"

`````

Expected: PR created against `development`. CI runs the linters + tests; codecov reports patch
coverage. Once green, follow the merge dance from the previous PRs (`gh pr merge <N> --merge
--delete-branch`, `git checkout development && git pull --ff-only && git fetch --prune origin`).

---

## Done

Five tasks. All checks green, PR open for review.

## Self-review against the spec

- ✅ §2 endpoint contract (limit/page_token in, response envelope out) — Task 3 implements + Task 4 documents.
- ✅ §3 architecture (handler drives pagination, client is single-page primitive) — Task 1 + Task 3.
- ✅ §4 `OpenFgaClient::readTuples()` change (new params, auto-loop gone) — Task 1.
- ✅ §5 `PermissionAdminHandler::listPermissions()` change (parseLimit, single readTuples call, new envelope) — Task 3.
- ✅ §5 constructor injection refactor — Task 2.
- ✅ §6 OpenAPI updates (params + schema + examples) — Task 4.
- ✅ §7 Layer A client tests (2 updated, 5 added) — Task 1.
- ✅ §7 Layer B handler validation tests (5) — Task 3.
- ✅ §7 Layer C integration test (1, depends on Task 2's injection) — Task 3.
- ✅ §8 out-of-scope items not implemented — confirmed in PR body.
- ✅ §9 files-touched inventory — every file in the spec's inventory is modified.
- ✅ `filterByAdminAccess` preservation (gap I caught while reading the actual `listPermissions()`
body) — explicitly preserved in Task 3 with a docblock note about the "smaller-than-limit page"
semantic.
- ✅ No placeholders (`TODO`, `TBD`, `Add appropriate error handling`).
- ✅ Type/name consistency: `readTuples()` signature in Task 1 matches the signature called from Task
3; `parseLimit()` constants match the validation messages; `AdminListPermissionsResponse` name
matches across Task 3 (response keys) and Task 4 (schema name).
