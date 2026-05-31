# Issue #573 — User-facing notifications endpoint pair

**Status:** Design approved, ready for implementation plan.
**Date:** 2026-05-30
**Author:** John R. D'Orazio (with Claude Code, via superpowers:brainstorming)
**Issue:** [#573](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/573)
**Blocks:** Corresponding LiturgicalCalendarFrontend issue.

## 1. Goal

When an admin approves, rejects, or revokes an end user's access request via
`/admin/access-requests/{id}/{action}`, the requesting user currently has no
in-app feedback. They must navigate to the access-requests page and inspect
each row's status to discover what changed.

This spec adds a complementary user-facing endpoint pair so the frontend can
render a notification bell with an unread badge and an inbox view.

## 2. Endpoint contract

### `GET /auth/notifications` — inbox + badge

Returns the authenticated user's last 50 reviewed access-requests, ordered by
`reviewed_at DESC`, plus the unread badge metadata.

**Auth:** `OidcAuthMiddleware::fromEnv()` (same gate as `/auth/access-requests`).
In OpenAPI parlance: `BearerAuth | CookieAuth`.

**Response (200):**

```json
{
  "items": [
    {
      "type": "access_request_reviewed",
      "request_id": "1234abcd-...",
      "requested_role": "calendar_editor",
      "status": "approved",
      "review_notes": "Welcome to the team.",
      "reviewed_at": "2026-05-09T12:34:56+00:00",
      "permissions": [
        {
          "object_type": "national_calendar",
          "object_id": "IT",
          "relation": "editor"
        }
      ],
      "unread": true
    }
  ],
  "total": 1,
  "unread_count": 1,
  "last_seen_at": "1970-01-01T00:00:00+00:00"
}
```

**Field semantics:**

| Field            | Meaning                                                                                                                                      |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `items[].unread` | True iff `reviewed_at > last_seen_at`.                                                                                                       |
| `total`          | Count of _all_ reviewed access-requests for this user (may exceed `items.length`). Window function `COUNT(*) OVER ()` over the filtered set. |
| `unread_count`   | Count of `reviewed_at > last_seen_at` across the full filtered set, not just the LIMIT-50 page.                                              |
| `last_seen_at`   | The user's bookmark. RFC 3339 UTC. Defaults to `1970-01-01T00:00:00+00:00` if the user has never called `/seen`.                             |

**Status values:** `approved | rejected | revoked`. Pending requests (where `reviewed_at IS NULL`) are excluded.

**Headers:** `Cache-Control: no-store`.

### `POST /auth/notifications/seen` — mark inbox seen

Upserts the user's bookmark to `NOW()` using the database clock as single
source of truth. Inbox items remain visible after this call — only the
`unread` flags and `unread_count` change on subsequent GETs.

**Auth:** Same as GET.

**Request body:** `{}` (no payload; user identity comes from the auth context).
Must send `Content-Type: application/json`.

**Response (200):**

```json
{ "success": true, "seen_at": "2026-05-09T12:35:01+00:00" }
```

**Headers:** `Cache-Control: no-store`.

### Error matrix (both endpoints)

| Condition                                           | Status                                    |
| --------------------------------------------------- | ----------------------------------------- |
| No `oidc_user` attribute (auth failed)              | 401                                       |
| Method not in `[GET, POST]`                         | 405 with `Allow: GET, POST`               |
| Unknown sub-path (e.g. `/auth/notifications/bogus`) | 404                                       |
| `Accept` header excludes JSON                       | 406                                       |
| `Content-Type` not JSON on POST                     | 415                                       |
| PDO error                                           | 500 (caught by `ErrorHandlingMiddleware`) |

## 3. Architecture

```text
Client (cookie or Bearer Authorization header)
  ↓
Router (src/Router.php)
  ↓
OidcAuthMiddleware::fromEnv()           ← same gate as /auth/access-requests
  ↓  sets $request->getAttribute('oidc_user') = ['sub' => zitadelUserId, 'roles' => [...]]
  ↓
NotificationsHandler::handle()          ← src/Handlers/Auth/NotificationsHandler.php
  ├─ extracts $userId = $oidcUser['sub']
  ├─ method dispatch:
  │    GET  /auth/notifications        → fetchInbox()
  │    POST /auth/notifications/seen   → markSeen()
  │    anything else                   → 405 / 404
  ↓
UserNotificationRepository              ← src/Repositories/UserNotificationRepository.php
  ├─ two SELECTs on GET (bookmark, then items + counts via window functions)
  └─ INSERT ... ON CONFLICT (user_id) DO UPDATE ... RETURNING on POST
  ↓
PostgreSQL
  ├─ access_requests        (existing)
  └─ user_notification_state (new, one row per user)
```

**Read consistency:** `markSeen()` uses `NOW()` in SQL and `RETURNING
last_notification_seen_at`, so the response timestamp comes from Postgres —
no PHP/DB clock skew. `fetchInbox()` reads `last_notification_seen_at` from
the same table on every call, so a GET→POST /seen→GET sequence is read-
consistent.

**Round-trips per request:** GET is two SQL statements (bookmark + items).
POST is one SQL statement (upsert).

## 4. Database migration

**File:** `src/Migrations/Version20260530140000.php`

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #573: user-facing notification bookmark.
 *
 * One row per Zitadel user, holding the last time they marked
 * their notifications inbox as seen. Absence of a row is treated
 * as "unseen since epoch" via LEFT JOIN + COALESCE — we never
 * insert a placeholder row on read.
 */
final class Version20260530140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_notification_state table for issue #573';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_notification_state (
                user_id                     VARCHAR(255) PRIMARY KEY,
                last_notification_seen_at   TIMESTAMP NOT NULL DEFAULT TIMESTAMP 'epoch'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_notification_state');
    }
}
```

**Decision notes:**

| Choice                          | Rationale                                                                                                                                                                                                                                      |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `user_id VARCHAR(255)`          | Matches `access_requests.zitadel_user_id` and the `oidc_user['sub']` claim. No FK because there's no local `users` table — Zitadel owns user identity.                                                                                         |
| `TIMESTAMP` (not `TIMESTAMPTZ`) | Matches `access_requests.reviewed_at` (also `TIMESTAMP`). Cross-type comparisons would do implicit, session-TZ-dependent conversions — avoidable trap. Issue spec said `TIMESTAMPTZ`, but the existing schema convention wins for consistency. |
| `DEFAULT TIMESTAMP 'epoch'`     | Safety net. The read path uses LEFT JOIN + COALESCE so the default is rarely consulted, but if some future code path INSERTs without specifying the column we still get correct semantics.                                                     |
| No `created_at`/`updated_at`    | YAGNI. Row's only meaningful column is the timestamp itself. First POST /seen creates the row via UPSERT; subsequent POSTs update it.                                                                                                          |
| PK = `user_id`                  | All queries are point-lookups by user. No secondary index needed.                                                                                                                                                                              |

**Test-fixture impact:** `phpunit_tests/Handlers/AbstractHandlerTestCase::setUp()`
currently truncates `api_keys, applications, access_requests, audit_log`. Add
`user_notification_state` to that list so per-test isolation holds.

## 5. `UserNotificationRepository`

**File:** `src/Repositories/UserNotificationRepository.php`

Constructor: `public function __construct(\PDO $pdo)`. Matches `AccessRequestRepository`.

### Method 1: `fetchInbox(string $userId, int $limit = 50): array`

Two SQL statements (chosen over one CTE-with-window for readability — the
second statement returns zero rows when the user has no reviewed requests, at
which point we still need the bookmark from the first for `last_seen_at`).

**Statement A** — fetch bookmark (or epoch):

```sql
SELECT last_notification_seen_at
FROM user_notification_state
WHERE user_id = :uid
```

PHP fallback when no row exists: `$lastSeen = '1970-01-01 00:00:00';`

**Statement B** — items + aggregate counts via window functions:

```sql
SELECT
    id,
    requested_role,
    status,
    review_notes,
    reviewed_at,
    permissions,
    (reviewed_at > :last_seen::timestamp) AS unread,
    COUNT(*) OVER () AS total,
    COUNT(*) FILTER (
        WHERE reviewed_at > :last_seen::timestamp
    ) OVER () AS unread_count
FROM access_requests
WHERE zitadel_user_id = :uid
  AND reviewed_at IS NOT NULL
ORDER BY reviewed_at DESC
LIMIT :limit
```

If the result set is empty, PHP returns `{items:[], total:0, unread_count:0,
last_seen_at: <from statement A>}`.

### Method 2: `markSeen(string $userId): string`

Single upsert with `RETURNING`:

```sql
INSERT INTO user_notification_state (user_id, last_notification_seen_at)
VALUES (:uid, NOW())
ON CONFLICT (user_id) DO UPDATE
SET last_notification_seen_at = EXCLUDED.last_notification_seen_at
RETURNING last_notification_seen_at
```

Returns the RFC 3339 string formatted from the DB's returned timestamp.

### Permission decoding — reuse via visibility change

`AccessRequestRepository::decodePermissions(string $json): array` is currently
private. Promote to `public static`. It's a pure function (input string →
output array, no state). One-line visibility change in
`src/Repositories/AccessRequestRepository.php` (~line 617); the new repo calls
`AccessRequestRepository::decodePermissions($row['permissions'])`.

Alternative considered: extract to a `src/Utils/PermissionsCodec.php`. Rejected
as overkill for one tiny pure function used in two places.

### Timestamp normalization

`access_requests.reviewed_at` and `user_notification_state.last_notification_seen_at`
are both `TIMESTAMP` (no TZ). The codebase convention (per `CLAUDE.md`) is
`Europe/Vatican` internally, RFC 3339 UTC on the wire. Private helper:

```php
private function iso8601(string $dbTimestamp): string
{
    return ( new \DateTimeImmutable($dbTimestamp, new \DateTimeZone('Europe/Vatican')) )
        ->setTimezone(new \DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:sP');
}
```

## 6. `NotificationsHandler`

**File:** `src/Handlers/Auth/NotificationsHandler.php`

Extends `AbstractHandler`, dispatches on method + URI tail. Pattern mirrors
`AccessRequestHandler`.

### Constructor

```php
public function __construct()
{
    parent::__construct();
    $this->setAllowedRequestMethods([RequestMethod::GET, RequestMethod::POST]);
    $this->setAllowedAcceptHeaders([AcceptHeader::JSON]);
    $this->setAllowedRequestContentTypes([RequestContentType::JSON]);
}
```

No `return_type` query-param support — per `CLAUDE.md`, that param is reserved
for `/calendar`. Auth/admin endpoints use `Accept` only.

### Dispatch matrix

| Method | URI tail      | Action                                              |
| ------ | ------------- | --------------------------------------------------- |
| `GET`  | `""`          | `fetchInbox` → 200 with full inbox shape            |
| `POST` | `"seen"`      | `markSeen` → 200 with `{success, seen_at}`          |
| `GET`  | anything else | 404                                                 |
| `POST` | anything else | 404                                                 |
| other  | —             | 405 (set by `AbstractHandler` allowed-method check) |

### Skeleton

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $oidcUser = $request->getAttribute('oidc_user');
    if ($oidcUser === null || !isset($oidcUser['sub']) || !is_string($oidcUser['sub'])) {
        throw new UnauthorizedException('Authentication required');
    }
    $userId = $oidcUser['sub'];

    $method = $request->getMethod();
    $tail   = $this->extractSubPath($request->getUri()->getPath());

    $response = $this->initResponse($request);
    $repo     = new UserNotificationRepository($this->getPdo());

    if ($method === 'GET' && $tail === '') {
        $body = $repo->fetchInbox($userId, limit: 50);
        return $this->encodeResponseBody(
            $response->withHeader('Cache-Control', 'no-store'),
            $body
        );
    }

    if ($method === 'POST' && $tail === 'seen') {
        $seenAt = $repo->markSeen($userId);
        return $this->encodeResponseBody(
            $response->withHeader('Cache-Control', 'no-store'),
            ['success' => true, 'seen_at' => $seenAt]
        );
    }

    return new Response(
        StatusCode::NOT_FOUND->value, [], null,
        $request->getProtocolVersion(),
        StatusCode::NOT_FOUND->reason()
    );
}

private function extractSubPath(string $path): string
{
    $prefix = '/auth/notifications';
    $base   = $_ENV['API_BASE_PATH'] ?? '';
    $needle = rtrim($base, '/') . $prefix;
    $tail   = substr($path, strlen($needle));
    return trim($tail, '/');
}
```

### PDO acquisition

`AbstractHandler` doesn't currently expose `getPdo()`. The plan must read
`AccessRequestHandler.php` to confirm the exact mechanism (likely inline
construction from env vars). Implementation will mirror that pattern.

## 7. Router wiring + middleware activation

Four small edits to `src/Router.php`.

### Edit 1 — Import block

Add:

```php
use LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler as AdminNotificationsHandler;
use LiturgicalCalendar\Api\Handlers\Auth\NotificationsHandler;
```

The first line renames the existing `Admin\NotificationsHandler` import. This
frees the bare `NotificationsHandler` name for the new `Auth\` class without
breaking the project's "unprefixed handler classes per namespace" convention.

### Edit 2 — `auth` dispatch case (~line 345)

Insert as a new `elseif`:

```php
} elseif ($authRoute === 'notifications') {
    // User notifications routes
    // GET  /auth/notifications        - Inbox + unread badge
    // POST /auth/notifications/seen   - Mark inbox seen
    $notificationsHandler = new NotificationsHandler();
    $this->handler        = $notificationsHandler;
```

### Edit 3 — `admin` dispatch case (~line 381)

Update the existing usage to the alias:

```php
} elseif ($adminRoute === 'notifications') {
    // Admin notifications route — GET /admin/notifications
    $notificationsHandler = new AdminNotificationsHandler();
    $this->handler        = $notificationsHandler;
```

### Edit 4 — OIDC middleware activation (~line 585)

Current:

```php
( $route === 'auth' && count($requestPathParts) >= 1
    && in_array($requestPathParts[0], ['access-requests', 'email-verification'], true) )
```

Becomes:

```php
( $route === 'auth' && count($requestPathParts) >= 1
    && in_array($requestPathParts[0], ['access-requests', 'email-verification', 'notifications'], true) )
```

`OidcAuthMiddleware::fromEnv()` now runs for `/auth/notifications/*`. No JWT
fallback — data is keyed by Zitadel user IDs, which only OIDC resolves.

## 8. OpenAPI additions

Edits to `jsondata/schemas/openapi.json`.

### Edit 1 — New tag

```json
{
  "name": "Notifications",
  "description": "User-facing in-app notifications. Currently scoped to access-request review events; may expand to other event types."
}
```

### Edit 2 — `UserNotification` schema

```json
{
  "type": "object",
  "description": "A single notification item in the authenticated user's inbox.",
  "properties": {
    "type": { "type": "string", "enum": ["access_request_reviewed"] },
    "request_id": { "type": "string", "format": "uuid" },
    "requested_role": { "type": "string" },
    "status": { "type": "string", "enum": ["approved", "rejected", "revoked"] },
    "review_notes": { "type": ["string", "null"] },
    "reviewed_at": { "type": "string", "format": "date-time" },
    "permissions": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/Permission" }
    },
    "unread": { "type": "boolean" }
  },
  "required": [
    "type",
    "request_id",
    "requested_role",
    "status",
    "review_notes",
    "reviewed_at",
    "permissions",
    "unread"
  ],
  "additionalProperties": false
}
```

Reuses existing `Permission` component (object_type + object_id + relation).

### Edit 3 — `UserNotificationsResponse` schema

```json
{
  "type": "object",
  "properties": {
    "items": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/UserNotification" }
    },
    "total": { "type": "integer", "minimum": 0 },
    "unread_count": { "type": "integer", "minimum": 0 },
    "last_seen_at": { "type": "string", "format": "date-time" }
  },
  "required": ["items", "total", "unread_count", "last_seen_at"],
  "additionalProperties": false
}
```

### Edit 4 — `NotificationsSeenResponse` schema

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean", "enum": [true] },
    "seen_at": { "type": "string", "format": "date-time" }
  },
  "required": ["success", "seen_at"],
  "additionalProperties": false
}
```

### Edit 5 — `/auth/notifications` (GET) path

```json
{
  "/auth/notifications": {
    "get": {
      "tags": ["Notifications"],
      "summary": "Get the authenticated user's notification inbox",
      "security": [{ "BearerAuth": [] }, { "CookieAuth": [] }],
      "responses": {
        "200": {
          "description": "Inbox payload.",
          "content": {
            "application/json": {
              "schema": {
                "$ref": "#/components/schemas/UserNotificationsResponse"
              }
            }
          },
          "headers": {
            "Cache-Control": {
              "schema": { "type": "string", "enum": ["no-store"] }
            }
          }
        },
        "401": { "$ref": "#/components/responses/Unauthorized" },
        "406": { "$ref": "#/components/responses/NotAcceptable" }
      }
    }
  }
}
```

### Edit 6 — `/auth/notifications/seen` (POST) path

```json
{
  "/auth/notifications/seen": {
    "post": {
      "tags": ["Notifications"],
      "summary": "Mark the user's notification inbox as seen",
      "security": [{ "BearerAuth": [] }, { "CookieAuth": [] }],
      "responses": {
        "200": {
          "description": "Bookmark updated.",
          "content": {
            "application/json": {
              "schema": {
                "$ref": "#/components/schemas/NotificationsSeenResponse"
              }
            }
          },
          "headers": {
            "Cache-Control": {
              "schema": { "type": "string", "enum": ["no-store"] }
            }
          }
        },
        "401": { "$ref": "#/components/responses/Unauthorized" },
        "406": { "$ref": "#/components/responses/NotAcceptable" },
        "415": { "$ref": "#/components/responses/UnsupportedMediaType" }
      }
    }
  }
}
```

The `Unauthorized`, `NotAcceptable`, and `UnsupportedMediaType` `$ref`s
already exist in `components.responses` per the project's convention. If any
is missing, add it as part of this PR.

Run `composer lint:openapi` after edits.

## 9. Tests

Two layers: unit (handler-direct) and integration (HTTP round-trip).

### Layer A — Unit tests

**File:** `phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`

Extends `AbstractHandlerTestCase` with `$requiresDatabase = true`. Uses
existing `withOidcUser()`, `requestFor()`, `decodeJsonBody()` helpers. Seeds
rows via `AccessRequestRepository::create()` then `::approve()` /
`::reject()` / `::revoke()`.

**Pre-req edit:** Add `user_notification_state` to the truncation list in
`AbstractHandlerTestCase::setUp()` (~lines 111-124).

**GET matrix:**

| #   | Scenario                                            | Assertion                                                                            |
| --- | --------------------------------------------------- | ------------------------------------------------------------------------------------ |
| G1  | No `oidc_user` attribute                            | 401, `UnauthorizedException`                                                         |
| G2  | Valid user, zero reviewed requests                  | 200; `{items:[], total:0, unread_count:0, last_seen_at:"1970-01-01T00:00:00+00:00"}` |
| G3  | 3 reviewed (approved/rejected/revoked), no bookmark | `total=3`, `unread_count=3`, every `items[].unread === true`                         |
| G4  | 3 reviewed + bookmark between item #2 and #1        | `unread_count=1`; only newest `unread:true`                                          |
| G5  | A's 3 + B's 2 in same table                         | A's GET → `total=3`, no B leak                                                       |
| G6  | Mix of reviewed and pending for same user           | Pending excluded from items/total/unread_count                                       |
| G7  | 55 reviewed                                         | `items.length === 50`, `total === 55`, items ordered DESC                            |
| G8  | Permissions JSONB                                   | `items[0].permissions[0]` is decoded object, not string                              |
| G9  | `Cache-Control: no-store`                           | Header assertion                                                                     |
| G10 | `GET /auth/notifications/bogus`                     | 404                                                                                  |
| G11 | `PUT /auth/notifications`                           | 405 with `Allow: GET, POST`                                                          |
| G12 | `review_notes` is NULL                              | `items[i].review_notes === null`                                                     |

**POST /seen matrix:**

| #   | Scenario                             | Assertion                                                         |
| --- | ------------------------------------ | ----------------------------------------------------------------- |
| S1  | No `oidc_user`                       | 401                                                               |
| S2  | First POST                           | 200; row exists in `user_notification_state`; `seen_at` is recent |
| S3  | Second POST                          | `seen_at` strictly greater than first                             |
| S4  | Wrong Content-Type                   | 415                                                               |
| S5  | `Cache-Control: no-store`            | Header assertion                                                  |
| S6  | `POST /auth/notifications` (no tail) | 404                                                               |
| S7  | `POST /auth/notifications/bogus`     | 404                                                               |

**Round-trip:**

| #   | Scenario                                   | Assertion                                                              |
| --- | ------------------------------------------ | ---------------------------------------------------------------------- |
| R1  | GET → POST /seen → GET                     | Second GET: same items/total, `unread_count === 0`, all `unread:false` |
| R2  | GET → POST /seen → seed new reviewed → GET | Third GET: `unread_count === 1`, newest `unread:true`                  |
| R3  | Two users                                  | A's POST /seen doesn't affect B's `unread_count`                       |

### Layer B — Integration tests

**File:** `phpunit_tests/Routes/Auth/NotificationsRoutesTest.php`

Extends `ApiTestCase`. Gated by `if (!self::isDatabaseConfigured()) self::markTestSkipped(...)` and `getJwtToken()`.

| #   | Test                                                              | Assertion                                         |
| --- | ----------------------------------------------------------------- | ------------------------------------------------- |
| I1  | `GET /auth/notifications` without `Authorization`                 | 401                                               |
| I2  | `GET /auth/notifications` with Bearer                             | 200, body conforms to `UserNotificationsResponse` |
| I3  | `POST /auth/notifications/seen` with Bearer + JSON `Content-Type` | 200, `{success:true, seen_at}`                    |
| I4  | GET → POST → GET end-to-end                                       | `unread_count` drops to 0                         |

### Verification commands

```bash
docker compose up -d --build           # apply the new migration
composer test phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php
composer test phpunit_tests/Routes/Auth/NotificationsRoutesTest.php
composer test                          # full suite — no regressions
composer analyse                       # PHPStan level 10
composer lint                          # phpcs
composer lint:openapi                  # Redocly
```

## 10. Out of scope

Per the issue and the brainstorming session:

- Per-item notification deletion (`DELETE /auth/notifications/{id}`) and bulk
  clear (`POST /auth/notifications/clear`). Inbox + unread-badge UX is sufficient
  for v1; if usage data later shows users accumulate noise, the cheapest add is
  a `last_notification_cleared_at` column + one new route.
- Push notifications (WebSocket, SSE), email, mobile push. REST polling on the
  frontend is sufficient.
- Notification types beyond access-request review events. The
  `type: "access_request_reviewed"` discriminator is in place so new types can be
  added without breaking the schema.
- Pagination for `items[]`. Hard LIMIT 50; the access-requests page covers
  historical browsing.

## 11. Files touched (summary)

**New:**

- `src/Migrations/Version20260530140000.php`
- `src/Handlers/Auth/NotificationsHandler.php`
- `src/Repositories/UserNotificationRepository.php`
- `phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`
- `phpunit_tests/Routes/Auth/NotificationsRoutesTest.php`

**Modified:**

- `src/Router.php` (imports + dispatch branches + middleware gate)
- `src/Repositories/AccessRequestRepository.php` (`decodePermissions` → `public static`)
- `phpunit_tests/Handlers/AbstractHandlerTestCase.php` (truncation list)
- `jsondata/schemas/openapi.json` (tag + 3 schemas + 2 paths)

**Unchanged:**

- `scripts/init-db.sql` — schema is authoritative in `src/Migrations/`, not here.
