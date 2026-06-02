# Issue #572 — Offset pagination for `GET /auth/access-requests` and `GET /admin/access-requests`

**Status:** Design approved, ready for implementation plan.
**Date:** 2026-06-01
**Author:** John R. D'Orazio (with Claude Code)
**Issue:** [#572](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/572)
**Sibling:** [#565 / PR #623](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/623) — cursor pagination for `/admin/permissions`.

## 1. Goal

`GET /auth/access-requests` and `GET /admin/access-requests` currently return the full unpaginated set
of `access_requests` rows. The admin endpoint accumulates `approved`/`rejected`/`revoked` rows
indefinitely, so its response grows without bound. The user endpoint's per-user load is small in
practice but is paginated for symmetry — same envelope, same client contract.

This spec adds **offset-based** pagination (different from the cursor model used in #565 because the
backing store is Postgres, where `COUNT(*)` and `LIMIT/OFFSET` are cheap and natural). The change
spans three layers (repository → handler → OpenAPI) and keeps `AccessRequestListResponse` as a single
shared schema between both endpoints, extended with pagination metadata.

## 2. Endpoint contracts

### Request

Both endpoints accept the same two new query parameters:

| Parameter | Type    | Default | Constraints     | Notes                                                          |
| --------- | ------- | ------- | --------------- | -------------------------------------------------------------- |
| `limit`   | integer | 100     | 1 ≤ limit ≤ 500 | **New** — max items per page.                                  |
| `offset`  | integer | 0       | offset ≥ 0      | **New** — zero-based item index where this page starts.        |

`GET /admin/access-requests` additionally retains its existing `status` filter (unchanged).

### Response (200)

```json
{
  "requests": [
    { "id": "0e7…", "zitadel_user_id": "…", "user_email": "…", "requested_role": "calendar_editor",
      "permissions": [{ "object_type": "national_calendar", "object_id": "IT", "relation": "editor" }],
      "status": "pending", "created_at": "2026-05-30T12:00:00Z" }
  ],
  "count": 1,
  "total": 137,
  "limit": 100,
  "offset": 0,
  "has_more": true
}
```

Final page has `has_more: false` and the last `count` items.

| Field      | Semantics                                                                                                             |
| ---------- | --------------------------------------------------------------------------------------------------------------------- |
| `requests` | Items in this page, up to `limit`. Shape unchanged — array of `AccessRequest`.                                        |
| `count`    | Number of items in this page (`requests.length`). May be **less than `limit`** on the last page or after admin-filter.|
| `total`    | Total rows matching the same filter, ignoring `limit`/`offset`. Single source of truth for "how many altogether".     |
| `limit`    | Echo of the effective `limit` (default 100 if omitted).                                                               |
| `offset`   | Echo of the effective `offset` (default 0 if omitted).                                                                |
| `has_more` | `(offset + count) < total`. False on the final page.                                                                  |

### Error matrix

| Condition                                           | Status | Source                                                                 |
| --------------------------------------------------- | ------ | ---------------------------------------------------------------------- |
| `limit` not a positive integer (non-digit, signed)  | 400    | Handler `ValidationException('limit must be a positive integer')`      |
| `limit < 1` or `limit > 500`                        | 400    | Handler `ValidationException('limit must be between 1 and 500')`       |
| `offset` not a non-negative integer (non-digit, -1) | 400    | Handler `ValidationException('offset must be a non-negative integer')` |
| `status` not in enum (admin only)                   | 400    | Existing `ValidationException`                                         |
| Auth missing / invalid                              | 401    | Existing OIDC / `UnauthorizedException`                                |
| Caller not admin (admin endpoint)                   | 403    | Existing `ForbiddenException`                                          |

## 3. Architecture

```text
Client request
  GET /admin/access-requests?status=pending&limit=50&offset=100
  ↓
Router  (OIDC-gated, role-checked — unchanged)
  ↓
AccessRequestAdminHandler::listRequests()    ← src/Handlers/Admin/AccessRequestAdminHandler.php
  ├─ parse limit (1..500, default 100)
  ├─ parse offset (≥0, default 0)
  ├─ existing status enum validation
  ├─ global-admin path:    page = repo->getAll($status, $limit, $offset)
  │                        total = repo->countAll($status)
  └─ resource-admin path:  page = repo->getAll($status, $limit, $offset)
                           total = repo->countAll($status)
                           page = filterByAdminAccess(page, adminId)   ← OpenFGA check loop
  ↓
AccessRequestRepository                       ← src/Repositories/AccessRequestRepository.php
  ├─ getAll(?string $status, ?int $limit=null, ?int $offset=null)
  ├─ getByUser(string $userId, ?int $limit=null, ?int $offset=null)
  ├─ countAll(?string $status = null)
  └─ countByUser(string $userId)
  ↓
Postgres `access_requests`
```

```text
Client request
  GET /auth/access-requests?limit=20&offset=0
  ↓
Router  (OIDC-gated — unchanged)
  ↓
AccessRequestHandler::listOwnRequests()      ← src/Handlers/Auth/AccessRequestHandler.php
  ├─ parse limit, offset (same helper)
  ├─ page = repo->getByUser($userId, $limit, $offset)
  └─ total = repo->countByUser($userId)
```

**Why offset, not cursor.** Postgres exposes `COUNT(*)` cheaply, so we can return an honest `total`
and let clients render "page X of Y" UIs without a heuristic. Offset's downside (`OFFSET` walks rows
on each page) is irrelevant at this volume: the access_requests table is bounded by user count and
admin activity, not external traffic. If volume ever pushes `COUNT(*)` or `OFFSET` over budget,
cursor migration is backward-compatible by adding `page_token` as an alternative to `offset` and
making `total`/`has_more` honest while `offset` becomes a one-way ratchet — out of scope today
(explicitly per the issue).

**Why not split the response schema per endpoint.** `/auth/access-requests` and
`/admin/access-requests` already share `AccessRequestListResponse`. The pagination envelope is
identical on both — same fields, same semantics. Splitting would duplicate the schema for zero gain.

**Resource-admin caveat (same as PR #623).** When `filterByAdminAccess()` post-filters a page on
`/admin/access-requests`, the returned `count` may be smaller than `limit` and smaller than `total`.
`total` reflects the SQL-level pre-filter count; `has_more` reflects the SQL paginator. Clients
should keep paging until `has_more === false` even when individual pages come back short. Documented
in the OpenAPI schema description — clients see the contract at the same layer they see the shape.

## 4. `AccessRequestRepository` changes

**File:** `src/Repositories/AccessRequestRepository.php`

### `getAll()` — add optional `$limit, $offset`

```php
/**
 * @param string|null $status Filter by status (pending, approved, rejected, revoked).
 * @param int|null $limit Max rows to return; null = no LIMIT clause.
 * @param int|null $offset Zero-based row offset; null = no OFFSET clause.
 * @return array<int, array<string, mixed>>
 * @throws \InvalidArgumentException If status is not a valid value.
 */
public function getAll(?string $status = null, ?int $limit = null, ?int $offset = null): array
```

Body sketch: same WHERE/ORDER BY as today, append `LIMIT :limit OFFSET :offset` when both are
non-null. We pass them through `PDO::bindValue($key, $value, PDO::PARAM_INT)` rather than the
`execute([...])` array form because PDO's prepared-statement array form binds everything as a
string, which can confuse `LIMIT`/`OFFSET` parsing in Postgres.

### `getByUser()` — same shape

```php
/**
 * @param string $userId
 * @param int|null $limit
 * @param int|null $offset
 * @return array<int, array<string, mixed>>
 */
public function getByUser(string $userId, ?int $limit = null, ?int $offset = null): array
```

### `countAll()` — new

```php
/**
 * Count access requests matching an optional status filter.
 *
 * @param string|null $status
 * @return int
 * @throws \InvalidArgumentException If status is not a valid value.
 */
public function countAll(?string $status = null): int
```

Single `SELECT COUNT(*) FROM access_requests` with the same conditional WHERE clause as `getAll()`.

### `countByUser()` — new

```php
/**
 * Count access requests for a given user.
 *
 * @param string $userId
 * @return int
 */
public function countByUser(string $userId): int
```

`SELECT COUNT(*) FROM access_requests WHERE zitadel_user_id = :user_id`.

### Decision notes

| Choice                                                      | Rationale                                                                                                              |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Optional `?int $limit/$offset`, default `null`              | Callers that don't paginate (e.g., `getStatus()`'s call to `getByUser()` for counting) keep their existing semantics.  |
| `bindValue(..., PDO::PARAM_INT)` for LIMIT/OFFSET           | `execute([...])` binds as strings; Postgres rejects `LIMIT '100' OFFSET '0'` parameter forms in some configurations.   |
| Separate `count*` methods (not a tuple return)              | `total` is independent of `limit`/`offset`. Two queries is honest. Caching is the handler's call if it ever matters.   |
| Repository raises `InvalidArgumentException` on bad status  | Already does on `getAll()`; keep consistent in `countAll()`. Handler validates before calling, so this is defense.     |

## 5. Handler changes

### Shared: limit/offset parsing helpers

Both handlers need the same `parseLimit()` and `parseOffset()` logic. PR #623 added `parseLimit()`
to `PermissionAdminHandler`. To avoid copy-pasting three times (and the eventual drift), we'll
introduce a small trait `Pagination/OffsetPaginationTrait` exposing `parseLimit()` and
`parseOffset()` with shared constants:

```php
namespace LiturgicalCalendar\Api\Handlers\Pagination;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;

trait OffsetPaginationTrait
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;

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
            throw new ValidationException(sprintf('limit must be between 1 and %d', self::MAX_LIMIT));
        }
        return $limit;
    }

    private function parseOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new ValidationException('offset must be a non-negative integer');
        }
        return (int) $raw;
    }
}
```

`ctype_digit` rejects signs, decimals, and non-ASCII digits — the same strict policy as PR #623.
Constants are private to the trait (a trait is a code-injection mechanism, not a class), so
constants land on the using class with the same visibility.

**Not migrating `PermissionAdminHandler::parseLimit()` to the trait in this PR.** That handler
shares the same `DEFAULT_LIMIT`/`MAX_LIMIT`/parsing logic, but its `parseLimit` is private and
already shipped. Migrating it would be a noise-only refactor; the trait stands ready for adoption
in a follow-up if a third pagination site appears. The DRY win waits for a third instance per the
"rule of three".

### `AccessRequestHandler::listOwnRequests()`

```php
private function listOwnRequests(
    ServerRequestInterface $request,
    ResponseInterface $response,
    string $userId
): ResponseInterface {
    $params = $request->getQueryParams();
    $limit  = $this->parseLimit($params['limit'] ?? null);
    $offset = $this->parseOffset($params['offset'] ?? null);

    $repo     = $this->getRepository();
    $requests = $repo->getByUser($userId, $limit, $offset);
    $total    = $repo->countByUser($userId);

    return $this->encodeResponseBody($response, [
        'requests' => $requests,
        'count'    => count($requests),
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
        'has_more' => ($offset + count($requests)) < $total,
    ]);
}
```

Signature changes — the method now needs the request to read query params. The single caller
(`handle()`) passes it through.

### `AccessRequestAdminHandler::listRequests()`

```php
private function listRequests(
    ServerRequestInterface $request,
    ResponseInterface $response,
    string $adminId,
    bool $isGlobalAdmin
): ResponseInterface {
    $repo        = $this->getRepository();
    $queryParams = $request->getQueryParams();

    $statusFilter = isset($queryParams['status']) && is_string($queryParams['status'])
        ? $queryParams['status']
        : null;

    if ($statusFilter !== null && !in_array($statusFilter, AccessRequestRepository::VALID_STATUSES, true)) {
        throw new ValidationException(
            sprintf('Invalid status. Valid values are: %s', implode(', ', AccessRequestRepository::VALID_STATUSES))
        );
    }

    $limit  = $this->parseLimit($queryParams['limit']  ?? null);
    $offset = $this->parseOffset($queryParams['offset'] ?? null);

    $page  = $repo->getAll($statusFilter, $limit, $offset);
    $total = $repo->countAll($statusFilter);

    if (!$isGlobalAdmin) {
        $page = $this->filterByAdminAccess($page, $adminId);
    }

    return $this->encodeResponseBody($response, [
        'requests' => $page,
        'count'    => count($page),
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
        'has_more' => ($offset + count($page)) < $total,
    ]);
}
```

Note: for resource admins, `count` ≤ raw page size, but `total` and `has_more` reflect the SQL-level
paginator. Documented in OpenAPI.

## 6. OpenAPI updates

**File:** `jsondata/schemas/openapi.json`

### Edit 1 — Add two query parameters to `paths["/auth/access-requests"].get.parameters`

The path currently has no `parameters` block. Add one with `limit` and `offset`.

### Edit 2 — Add two query parameters to `paths["/admin/access-requests"].get.parameters`

Append `limit` and `offset` to the existing `parameters` list (which currently only has `status`).

Reusable parameter components (`LimitQueryParam`, `OffsetQueryParam`) so both endpoints share the
same definition — change defaults/bounds once, both endpoints update:

```json
"LimitQueryParam": {
  "name": "limit",
  "in": "query",
  "required": false,
  "description": "Maximum number of items to return in this page. Default 100, max 500.",
  "schema": { "type": "integer", "minimum": 1, "maximum": 500, "default": 100 }
},
"OffsetQueryParam": {
  "name": "offset",
  "in": "query",
  "required": false,
  "description": "Zero-based offset of the first item to return. Default 0.",
  "schema": { "type": "integer", "minimum": 0, "default": 0 }
}
```

Both paths `$ref` these components.

### Edit 3 — Extend `AccessRequestListResponse` with pagination fields

```json
"AccessRequestListResponse": {
  "type": "object",
  "description": "Offset-paginated page of access requests returned by GET /auth/access-requests and GET /admin/access-requests. When a resource admin (non-global) calls /admin/access-requests, `filterByAdminAccess` is applied to each page after fetching: `count` may be smaller than `limit`, and `total` reflects the pre-filter SQL count. Clients should page until `has_more` is false even if individual pages come back short.",
  "properties": {
    "requests": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/AccessRequest" }
    },
    "count": {
      "type": "integer",
      "minimum": 0,
      "description": "Number of items in this page. May be less than `limit` on the last page, or smaller still for resource admins after post-filtering."
    },
    "total": {
      "type": "integer",
      "minimum": 0,
      "description": "Total matching rows ignoring limit/offset. For resource admins on /admin/access-requests, this is the pre-filter SQL count."
    },
    "limit": {
      "type": "integer",
      "minimum": 1,
      "maximum": 500,
      "description": "Effective limit applied to this page (default 100 if not supplied)."
    },
    "offset": {
      "type": "integer",
      "minimum": 0,
      "description": "Effective offset of the first item in this page (default 0 if not supplied)."
    },
    "has_more": {
      "type": "boolean",
      "description": "True iff (offset + count) < total. False on the final page."
    }
  },
  "required": ["requests", "count", "total", "limit", "offset", "has_more"],
  "additionalProperties": false
}
```

Old `required: [requests, count]` becomes `required: [requests, count, total, limit, offset, has_more]`.

### Edit 4 — Add `firstPage` + `lastPage` examples to both endpoints

Same pattern as PR #623: each 200 response carries two examples illustrating mid-paginated and
final-page envelopes. Helps human readers and tooling.

### Lint

`composer lint:openapi` must pass with zero errors. No 3.1-only features needed (no nullable union
strings); the schema stays OpenAPI 3.0-clean.

## 7. Tests

Three layers. Targets follow PR #623's discipline: repository covered for new SQL paths, handlers
covered for validation + envelope, no integration tests against a live DB beyond what already exists.

### Layer A — `AccessRequestRepositoryTest`

**5 new tests:**

| #   | Test                                              | Verifies                                                                                |
| --- | ------------------------------------------------- | --------------------------------------------------------------------------------------- |
| R1  | `testGetAllWithLimitAndOffsetReturnsSlice`        | Insert N>2 rows, call `getAll(null, 1, 1)`, assert correct row by created_at DESC.      |
| R2  | `testGetByUserWithLimitAndOffsetReturnsSlice`     | Same pattern, user-scoped.                                                              |
| R3  | `testCountAllMatchesGetAllSize`                   | Insert N rows, assert `countAll()` and `countAll('pending')` match SQL truth.           |
| R4  | `testCountByUserMatchesGetByUserSize`             | `countByUser(userId)` matches the row count for that user.                              |
| R5  | `testCountAllRejectsInvalidStatus`                | `countAll('bogus')` throws `InvalidArgumentException` (defense-in-depth).               |

Existing tests for `getAll()` / `getByUser()` keep passing — default-args back-compat assertion.

### Layer B — `AccessRequestHandlerTest` (user-facing endpoint)

**5 new tests:**

| #   | Test                                                 | Assertion                                                                              |
| --- | ---------------------------------------------------- | -------------------------------------------------------------------------------------- |
| H1  | `testListOwnRequestsWithoutParamsReturnsFirstPage`   | Defaults: `limit=100, offset=0, total = total inserted, has_more=false`.               |
| H2  | `testListOwnRequestsWithLimitAndOffset`              | `limit=1&offset=1` returns 1 item, correct envelope (`count=1, has_more=(2 < total)`). |
| H3  | `testListOwnRequestsInvalidLimitIsValidationError`   | `limit=0`, `limit=501`, `limit=abc`, `limit=-1`, all → 400.                            |
| H4  | `testListOwnRequestsInvalidOffsetIsValidationError`  | `offset=-1`, `offset=abc` → 400.                                                       |
| H5  | `testListOwnRequestsHasMoreFalseOnLastPage`          | Page-3 of a 25-row, limit-10 dataset has `count=5, has_more=false`.                    |

### Layer C — `AccessRequestAdminHandlerTest` (admin endpoint)

**5 new tests:**

| #   | Test                                                 | Assertion                                                            |
| --- | ---------------------------------------------------- | -------------------------------------------------------------------- |
| A1  | `testListRequestsWithoutPaginationDefaults`          | Defaults applied; envelope includes new fields.                      |
| A2  | `testListRequestsWithLimitAndOffset`                 | Sliced page; envelope honest.                                        |
| A3  | `testListRequestsInvalidLimitIsValidationError`      | `limit=0`, `limit=501`, `limit=abc`, `limit=-1` → 400.               |
| A4  | `testListRequestsInvalidOffsetIsValidationError`     | `offset=-1`, `offset=abc` → 400.                                     |
| A5  | `testListRequestsCombinesStatusFilterAndPagination`  | `status=pending&limit=2&offset=0` returns only pending, paginated.   |

### Verification commands

```bash
vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php
vendor/bin/phpunit phpunit_tests/Handlers/Auth/AccessRequestHandlerTest.php
vendor/bin/phpunit phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
composer test                  # full unit suite
composer analyse               # PHPStan L10
composer lint                  # phpcs PSR-12
composer lint:openapi          # Redocly
composer parallel-lint         # php -l
```

## 8. Out of scope

Per the issue:

- **Cursor migration.** Offset is sufficient for this volume; cursor is a future option if `OFFSET`
  ever becomes a hotspot. The envelope is forward-compatible because adding `page_token` later
  doesn't conflict with `limit`/`offset`.
- **Pagination of `/admin/permissions`** — shipped in PR #623 (cursor model).
- **Pagination of `/admin/applications`** — separate decision, separate handler.
- **Frontend UI changes** beyond basic compatibility (clients that ignore the new envelope fields
  still get `requests` and `count` at the same paths).

## 9. Files touched (summary)

**Modified:**

- `src/Repositories/AccessRequestRepository.php` — `getAll()` + `getByUser()` gain optional
  `$limit, $offset`; new `countAll()` and `countByUser()` methods.
- `src/Handlers/Auth/AccessRequestHandler.php` — `listOwnRequests()` reads query params, builds new
  envelope; uses the trait.
- `src/Handlers/Admin/AccessRequestAdminHandler.php` — `listRequests()` reads `limit`/`offset`,
  builds new envelope; uses the trait.
- `jsondata/schemas/openapi.json` — two reusable parameter components, two endpoints reference them,
  `AccessRequestListResponse` gains pagination fields and updated `required`, examples on both
  endpoints.
- `phpunit_tests/Repositories/AccessRequestRepositoryTest.php` — 5 new tests.
- `phpunit_tests/Handlers/Auth/AccessRequestHandlerTest.php` — 5 new tests.
- `phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php` — 5 new tests.

**Created:**

- `src/Handlers/Pagination/OffsetPaginationTrait.php` — shared `parseLimit()` + `parseOffset()`.

**Unchanged:**

- `AccessRequest` component schema in OpenAPI (row shape is identical).
- Router wiring, OIDC middleware, OpenFGA client.
- `PermissionAdminHandler::parseLimit()` — left as-is; migrating it to the trait is a noise-only
  refactor and the rule of three says wait for a third instance.
