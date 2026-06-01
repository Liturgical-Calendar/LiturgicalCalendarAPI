# Issue #565 — Cursor pagination for `GET /admin/permissions`

**Status:** Design approved, ready for implementation plan.
**Date:** 2026-06-01
**Author:** John R. D'Orazio (with Claude Code, via superpowers:brainstorming)
**Issue:** [#565](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/565)
**Spawned by:** [PR #555 review comment](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/555#discussion_r3198313895)

## 1. Goal

`GET /admin/permissions` currently returns an unpaginated list of OpenFGA tuples. The handler
relies on `OpenFgaClient::readTuples()` auto-paginating internally with a hard 100-page cap; for any
non-trivial store this either buffers a large set into memory or trips the cap and 500s. Clients
cannot traverse the store reliably.

This spec adds cursor-based pagination, exposing OpenFGA's native continuation tokens on the wire
so clients can page through the store one bounded chunk at a time. The change spans three layers
(client → handler → OpenAPI) and replaces — does not extend — the existing auto-paginate behavior,
because no caller benefits from auto-aggregation and "handler owns pagination policy" is the
correct architectural boundary.

## 2. Endpoint contract

### Request

`GET /admin/permissions` accepts the four existing filter parameters plus two new pagination parameters:

| Parameter     | Type    | Default | Constraints                                                                    | Notes                                                                                                |
| ------------- | ------- | ------- | ------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------- |
| `user`        | string  | `""`    | —                                                                              | Existing                                                                                             |
| `object_type` | string  | `""`    | enum: `national_calendar`/`diocesan_calendar`/`wider_region`/`test_definition` | Existing; **required for non-global admins**                                                         |
| `object_id`   | string  | `""`    | —                                                                              | Existing                                                                                             |
| `relation`    | string  | `""`    | enum: `admin`/`viewer`/`editor`/`deleter`                                      | Existing                                                                                             |
| `limit`       | integer | 100     | 1 ≤ limit ≤ 500                                                                | **New** — max page size                                                                              |
| `page_token`  | string  | `""`    | —                                                                              | **New** — opaque cursor from previous response's `next_page_token`; empty/omitted means "first page" |

### Response (200)

```json
{
  "permissions": [
    {
      "user": "user:zitadel-123",
      "relation": "editor",
      "object": "national_calendar:IT"
    },
    {
      "user": "user:zitadel-456",
      "relation": "viewer",
      "object": "national_calendar:IT"
    }
  ],
  "count": 2,
  "has_more": true,
  "next_page_token": "eyJwayI6IjAxIDM2MmIzZWM..."
}
```

Final page has `has_more: false` and `next_page_token: null`.

| Field             | Semantics                                                                                                                  |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `permissions`     | The tuples in this page, up to the requested `limit`. Shape unchanged from current — `list<{user, relation, object}>`.     |
| `count`           | Number of tuples in this page (i.e., `permissions.length`). **NOT** a total — OpenFGA does not expose an O(1) total count. |
| `has_more`        | `true` iff OpenFGA's response carried a non-empty `continuation_token` for this filter.                                    |
| `next_page_token` | The opaque continuation token to pass back as `page_token` for the next page; `null` when `has_more` is false.             |

### Error matrix

| Condition                                  | Status     | Source                                                                        |
| ------------------------------------------ | ---------- | ----------------------------------------------------------------------------- |
| `limit` not parseable as positive int      | 400        | Handler `ValidationException('limit must be a positive integer')`             |
| `limit < 1` or `limit > 500`               | 400        | Handler `ValidationException('limit must be between 1 and 500')`              |
| `page_token` invalid (rejected by OpenFGA) | 400 or 500 | Whatever the OpenFGA response carries up the existing `RuntimeException` path |
| Non-global admin without `object_type`     | 400        | Existing check, unchanged                                                     |
| Auth missing/invalid                       | 401 / 403  | Existing OIDC middleware, unchanged                                           |
| Other transport failure                    | 500        | Existing                                                                      |

## 3. Architecture

```text
Client request
  GET /admin/permissions?object_type=national_calendar&limit=50&page_token=<opaque>
  ↓
Router  (OIDC-gated, role-checked — unchanged)
  ↓
PermissionAdminHandler::listPermissions()      ← src/Handlers/Admin/PermissionAdminHandler.php
  ├─ parse limit (validate 1..500, default 100)
  ├─ parse page_token (default empty)
  ├─ existing authz: non-global admin must filter by object_type
  └─ ONE call to OpenFgaClient::readTuples(..., $limit, $pageToken)
  ↓
OpenFgaClient::readTuples()                    ← src/Services/OpenFgaClient.php
  ├─ + ?int $limit
  ├─ + ?string $continuationToken
  ├─ auto-loop REMOVED
  └─ returns { tuples, next_continuation_token }
  ↓
OpenFGA HTTP API
```

**Token strategy — passthrough, not wrapped.** `next_page_token` on the wire IS OpenFGA's
`continuation_token`. Clients treat it as opaque (they already would, even if we wrapped it). The
vendor-swap risk is low — replacing OpenFGA would be a major migration regardless — and adding a
wrapping layer later is backward-compatible because clients can't introspect the token. YAGNI wins.

**Why the auto-loop disappears entirely.** Today `readTuples()` calls the OpenFGA `/read` endpoint
in a loop until no token remains, capped at 100 iterations. There are exactly two callers (both in
`PermissionAdminHandler::listPermissions()`), and after this change neither wants the auto-behavior
— the handler now drives pagination. The auto-loop wrapper would be speculative API surface with no
consumer; remove it.

## 4. `OpenFgaClient::readTuples()` change

**File:** `src/Services/OpenFgaClient.php`

### Signature

```php
/**
 * Read a single page of OpenFGA tuples matching the given filter.
 *
 * @return array{
 *     tuples: list<array{user: string, relation: string, object: string}>,
 *     next_continuation_token: string
 * } The empty string for next_continuation_token means "no more pages".
 *
 * @throws \RuntimeException on transport/parse failure
 */
public function readTuples(
    string $user,
    string $object,
    ?string $relation = null,
    ?int $limit = null,
    ?string $continuationToken = null,
): array
```

### Body sketch

```php
$tupleKey = [];
if ($user !== '')   { $tupleKey['user']   = $user; }
if ($object !== '') { $tupleKey['object'] = $object; }
if ($relation !== null && $relation !== '') { $tupleKey['relation'] = $relation; }

$payload = $tupleKey !== [] ? ['tuple_key' => $tupleKey] : [];
if ($limit !== null) {
    $payload['page_size'] = $limit;
}
if ($continuationToken !== null && $continuationToken !== '') {
    $payload['continuation_token'] = $continuationToken;
}

$response = $this->post("/stores/{$this->storeId}/read", $payload);

$rawTuples = is_array($response['tuples'] ?? null) ? $response['tuples'] : [];
$tuples    = [];
foreach ($rawTuples as $row) {
    $key = is_array($row) ? ($row['key'] ?? null) : null;
    if (!is_array($key)) {
        continue;
    }
    $tupleUser     = $key['user']     ?? null;
    $tupleRelation = $key['relation'] ?? null;
    $tupleObject   = $key['object']   ?? null;
    if (!is_string($tupleUser) || !is_string($tupleRelation) || !is_string($tupleObject)) {
        continue;
    }
    $tuples[] = [
        'user'     => $tupleUser,
        'relation' => $tupleRelation,
        'object'   => $tupleObject,
    ];
}

$nextToken = is_string($response['continuation_token'] ?? null)
    ? $response['continuation_token']
    : '';

return [
    'tuples'                  => $tuples,
    'next_continuation_token' => $nextToken,
];
```

### Decision notes

| Choice                                                          | Rationale                                                                                                                                                    |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `?int $limit = null` (nullable, optional)                       | When null, omit `page_size` — OpenFGA applies its server-side default. Keeps the magic-number policy (100 default) at the handler boundary where it belongs. |
| `?string $continuationToken = null`                             | Null or empty means "first page"; payload omits `continuation_token` entirely.                                                                               |
| `next_continuation_token` returned as empty string when no more | Matches OpenFGA's wire format. Handler interprets empty → `has_more: false`.                                                                                 |
| 100-page cap removed                                            | Unreachable now — caller controls iteration. Defensive code with no caller is dead code.                                                                     |
| Single statement to `$this->post()`                             | No more `do/while`. Straight-line code.                                                                                                                      |

## 5. `PermissionAdminHandler::listPermissions()` change

**File:** `src/Handlers/Admin/PermissionAdminHandler.php`

### Constants

```php
private const DEFAULT_LIMIT = 100;
private const MAX_LIMIT     = 500;
```

### New private helper

```php
/**
 * Parse the `limit` query param; return DEFAULT_LIMIT when absent or empty,
 * throw ValidationException when present-but-invalid or out of range.
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

`ctype_digit` rejects `-1`, `1.5`, `+1`, `1e2`, and any non-ASCII-digit characters — strict and explicit. Query params arrive as strings; leniency hides bugs.

### Modified `listPermissions()` body

Replace the existing param-parsing block and the two `readTuples()` call sites:

```php
$user        = is_string($params['user']        ?? null) ? $params['user']        : '';
$objectType  = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
$objectId    = is_string($params['object_id']   ?? null) ? $params['object_id']   : '';
$relation    = is_string($params['relation']    ?? null) ? $params['relation']    : '';
$limit       = $this->parseLimit($params['limit'] ?? null);
$pageToken   = is_string($params['page_token'] ?? null) ? $params['page_token'] : '';

// Existing authz + enum-value checks: UNCHANGED.

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

$tuples    = $page['tuples'];
$nextToken = $page['next_continuation_token'];
$hasMore   = $nextToken !== '';

return $this->encodeResponseBody($response, [
    'permissions'     => $tuples,
    'count'           => count($tuples),
    'has_more'        => $hasMore,
    'next_page_token' => $hasMore ? $nextToken : null,
]);
```

The dual-call branching (one for global admin no-filter, one for filtered) collapses to a single
call because the object-filter construction was the only difference between the two paths.

### Constructor injection (small refactor for testability)

```php
private ?OpenFgaClient $injectedClient = null;

public function __construct(?OpenFgaClient $client = null)
{
    parent::__construct();
    $this->injectedClient = $client;
}

private function getClient(): OpenFgaClient
{
    return $this->injectedClient ??= OpenFgaClient::fromEnv();
}
```

Mirrors the repository-injection convention introduced in #573
(`UserNotificationRepository::__construct(?\PDO $db = null)`). Router-side instantiation
(`new PermissionAdminHandler()`) keeps working — zero-arg constructor still valid. Tests pass a
stubbed `OpenFgaClient` to verify wire-level forwarding without env juggling.

## 6. OpenAPI updates

**File:** `jsondata/schemas/openapi.json`

### Edit 1 — Two new query parameters on `paths["/admin/permissions"].get.parameters`

```json
{
  "name": "limit",
  "in": "query",
  "required": false,
  "description": "Maximum number of permission tuples to return in this page. Default 100, max 500.",
  "schema": { "type": "integer", "minimum": 1, "maximum": 500, "default": 100 }
},
{
  "name": "page_token",
  "in": "query",
  "required": false,
  "description": "Opaque pagination cursor from the previous response's `next_page_token`. Omit (or pass empty) for the first page.",
  "schema": { "type": "string" }
}
```

The four existing filter params stay untouched.

### Edit 2 — New component `AdminListPermissionsResponse`

```json
"AdminListPermissionsResponse": {
  "type": "object",
  "description": "Cursor-paginated page of OpenFGA permission tuples. Use `next_page_token` to fetch the next page; null means this was the last page.",
  "properties": {
    "permissions": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/PermissionTuple" },
      "description": "Tuples in this page, up to the requested `limit`."
    },
    "count": {
      "type": "integer",
      "minimum": 0,
      "description": "Number of tuples in this page. NOT a total count across all pages — OpenFGA does not expose an O(1) total."
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

Naming convention follows existing `AdminUsersResponse`. **Not** sharing with `PaginationMetadata`
(which exists for `/admin/users`) because that component is offset-based
(`{limit, offset, hasMore}`) — different pagination semantics deserve different envelopes.

### Edit 3 — 200 response: `$ref` the new component + add two examples

Replace the existing inline 200 response schema with `$ref` to `AdminListPermissionsResponse` and
add `firstPage` + `lastPage` examples (illustrating mid-paginated and final-page states). Full JSON
in plan.

### Lint

`composer lint:openapi` must pass with 0 errors. `"type": ["string", "null"]` is OpenAPI 3.1 — confirmed compatible with this project's `openapi.json` during issue #573 work.

## 7. Tests

Two layers — wire-level and validation-level — plus one focused integration test.

### Layer A — `OpenFgaClientTest` (wire-level, MockHandler-driven)

**Existing 2 tests need return-shape updates** (`testReadTuplesReturnsParsedTuples`,
`testReadTuplesReturnsEmptyArrayWhenNoTuples`): they now read `$result['tuples']` and assert
`$result['next_continuation_token'] === ''`.

**5 new tests:**

| #   | Test                                                  | Verifies                                                                                                                      |
| --- | ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| C1  | `testReadTuplesPassesLimitAndContinuationToken`       | Captured request body has `page_size: 50` and `continuation_token: 'abc'`; response carries `next_continuation_token: 'xyz'`. |
| C2  | `testReadTuplesOmitsLimitWhenNull`                    | Request body has no `page_size` key when limit is null.                                                                       |
| C3  | `testReadTuplesOmitsContinuationTokenWhenNullOrEmpty` | Empty string and null both produce a request body without `continuation_token`.                                               |
| C4  | `testReadTuplesReturnsEmptyTokenWhenServerOmits`      | Server response missing `continuation_token` → `next_continuation_token === ''`.                                              |
| C5  | `testReadTuplesNoLongerAutoPaginates`                 | Even when first response carries a `continuation_token`, only ONE HTTP request is made. Locks in the architectural change.    |

### Layer B — `PermissionAdminHandlerTest` (validation-level)

**5 new validation tests:**

| #   | Test                                            | Assertion                                                                                                           |
| --- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| H1  | `testListWithLimitZeroIsValidationError`        | `ValidationException('between 1 and 500')`                                                                          |
| H2  | `testListWithLimitTooLargeIsValidationError`    | Same, with `limit=501`                                                                                              |
| H3  | `testListWithNonNumericLimitIsValidationError`  | `ValidationException('positive integer')` with `limit=abc`                                                          |
| H4  | `testListWithNegativeLimitIsValidationError`    | Same as H3 (caught by `ctype_digit`)                                                                                |
| H5  | `testListWithLimitAtUpperBoundPassesValidation` | `limit=500` passes the validation gate (downstream failure when OpenFGA env not configured is the existing pattern) |

### Layer C — Handler/client integration (1 test, the back-compat assertion)

| #   | Test                                   | Setup                                                                                          | Assertion                                                                                                                                             |
| --- | -------------------------------------- | ---------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| I1  | `testListDefaultsToLimit100AndNoToken` | MockHandler returns `{tuples:[], continuation_token:''}`; handler constructed with that client | Captured OpenFGA POST body has `page_size: 100`, no `continuation_token`; response: `{permissions:[], count:0, has_more:false, next_page_token:null}` |

This is the test the issue's "back-compat" acceptance criterion ("existing clients without
pagination params still receive a bounded first page using the server default limit") asks for.
Requires the small constructor-injection refactor described in §5.

### Verification commands

```bash
composer test phpunit_tests/Services/OpenFgaClientTest.php
composer test phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
composer test                  # full unit suite
composer analyse               # PHPStan L10
composer lint                  # phpcs PSR-12
composer lint:openapi          # Redocly
```

## 8. Out of scope

Per the issue:

- Frontend UI changes beyond basic compatibility.
- Server-side sorting customizations beyond current behavior.
- Total-count exposure (OpenFGA does not provide it cheaply; would require a separate counter or full enumeration).
- Token wrapping / signing — `next_page_token` is the raw OpenFGA continuation token. Can be wrapped backward-compatibly later if needed.
- Pagination for the other admin endpoints (`/admin/access-requests`, `/admin/users`, etc.) — separately tracked as #572 for access-requests; out of scope here.

## 9. Files touched (summary)

**Modified:**

- `src/Services/OpenFgaClient.php` — `readTuples()` signature + body (auto-loop removed).
- `src/Handlers/Admin/PermissionAdminHandler.php` — `listPermissions()` body, new `parseLimit()` helper, constants, constructor.
- `phpunit_tests/Services/OpenFgaClientTest.php` — update 2 existing tests, add 5.
- `phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php` — add 5 validation tests + 1 integration test.
- `jsondata/schemas/openapi.json` — 2 new query params on path, new `AdminListPermissionsResponse` schema, 200 response `$ref` updated, examples added.

**Unchanged:**

- `PermissionTuple` component schema in OpenAPI.
- `OpenFgaClient::writeTuple()`, `deleteTuple()`, `check()`, all approve/revoke admin handlers.
- Router wiring and OIDC middleware.
- Other admin handler endpoints.
