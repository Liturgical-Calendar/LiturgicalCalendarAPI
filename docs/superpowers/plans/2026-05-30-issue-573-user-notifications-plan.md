# Issue #573 — User-facing notifications endpoint pair — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /auth/notifications` (inbox + unread badge) and `POST /auth/notifications/seen` (mark-seen bookmark)
endpoints so frontend can render a notification bell for access-request review events.

**Architecture:** New `user_notification_state` table holds one-row-per-user last-seen timestamp.
`NotificationsHandler` (under `src/Handlers/Auth/`) dispatches on method + URI tail and delegates to
`UserNotificationRepository`. OIDC-gated via the existing `OidcAuthMiddleware::fromEnv()`. Inbox returns the user's
last 50 reviewed access-requests with an `unread` boolean per item; `unread_count` and `total` use Postgres window
functions over the full filtered set. `POST /seen` upserts via
`INSERT ... ON CONFLICT (user_id) DO UPDATE ... RETURNING NOW()` so timestamps come from the DB clock (single source
of truth).

**Tech Stack:** PHP 8.4+, PostgreSQL, Doctrine migrations, PSR-7/15/17, PHPUnit, PHPStan level 10, phpcs (PSR-12), Redocly (OpenAPI), markdownlint, CaptainHook.

**Reference spec:** `docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md`

**Pre-flight (one-time, run once before Task 1):**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI
git checkout development
git pull --ff-only origin development
git checkout -b feature/issue-573-user-notifications
docker compose up -d --build      # bring up Postgres + Zitadel + run any pending migrations
composer install                  # ensure vendor/ is fresh
```

Expected: containers `litcal-db`, `litcal-zitadel`, `litcal-openfga`, `litcal-migrate` (exited 0), `litcal-mailpit`,
`litcal-adminer` all healthy. `composer install` shows "Nothing to install".

---

## Task 1 — Database migration

**Files:**

- Create: `src/Migrations/Version20260530140000.php`

- [ ] **Step 1.1: Create the migration file**

Write `src/Migrations/Version20260530140000.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #573: user-facing notification bookmark.
 *
 * One row per Zitadel user, holding the last time they marked
 * their notifications inbox as seen. Absence of a row is treated
 * as "unseen since epoch" via the read path (no placeholder row
 * is ever inserted on read).
 */
final class Version20260530140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_notification_state table for issue #573';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

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

- [ ] **Step 1.2: Apply the migration locally**

Run: `composer db:migrate`

Expected: Migrations output lists `LiturgicalCalendar\Api\Migrations\Version20260530140000` as applied. No errors.

- [ ] **Step 1.3: Verify the table exists**

Run:

```bash
docker compose exec -T db psql -U litcal -d litcal -c "\d user_notification_state"
```

Expected output includes the two columns and `PRIMARY KEY, btree (user_id)`:

```text
                Table "public.user_notification_state"
          Column           |            Type             | ... | Default
---------------------------+-----------------------------+-----+--------------------
 user_id                   | character varying(255)      | ... |
 last_notification_seen_at | timestamp without time zone | ... | 'epoch'::timestamp
Indexes:
    "user_notification_state_pkey" PRIMARY KEY, btree (user_id)
```

- [ ] **Step 1.4: Confirm migration status**

Run: `composer db:migrations:status`

Expected: status line shows "Available Migrations: 0", "Executed Migrations: 2" (the baseline + ours).

- [ ] **Step 1.5: Commit**

```bash
git add src/Migrations/Version20260530140000.php
git commit -m "$(cat <<'EOF'
feat(db): add user_notification_state table for issue #573

One row per Zitadel user holding last_notification_seen_at as a
TIMESTAMP. Absence of a row = unseen-since-epoch via the read path
(LEFT JOIN + COALESCE in the upcoming UserNotificationRepository).

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Expected: CaptainHook pre-commit passes (phpcs, parallel-lint, markdownlint).

---

## Task 2 — Promote `AccessRequestRepository::decodePermissions` to `public static`

**Files:**

- Modify: `src/Repositories/AccessRequestRepository.php:617-637`

**Rationale:** `UserNotificationRepository` (Task 3) needs to decode the same `permissions` JSONB column. The existing
method is already pure (no `$this` references), so promoting to `public static` is a safe one-keyword change. We must
also update the internal callable in `decodePermissionsList` since `[$this, 'decodePermissions']` no longer matches a
static method.

- [ ] **Step 2.1: Change the method signature**

In `src/Repositories/AccessRequestRepository.php`, replace lines 617-626:

```php
    private function decodePermissions(array $row): array
    {
        if (isset($row['permissions']) && is_string($row['permissions'])) {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}>|null $decoded */
            $decoded            = json_decode($row['permissions'], true);
            $row['permissions'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
```

With:

```php
    public static function decodePermissions(array $row): array
    {
        if (isset($row['permissions']) && is_string($row['permissions'])) {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}>|null $decoded */
            $decoded            = json_decode($row['permissions'], true);
            $row['permissions'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
```

- [ ] **Step 2.2: Update the internal callable**

In the same file, replace line 636:

```php
        return array_map([$this, 'decodePermissions'], $rows);
```

With:

```php
        return array_map([self::class, 'decodePermissions'], $rows);
```

- [ ] **Step 2.3: Static analysis**

Run: `composer analyse`

Expected: `[OK] No errors`. PHPStan must remain at level 10 clean.

- [ ] **Step 2.4: Run the existing repository tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryTest.php`

Expected: all tests pass (no regression from the visibility change).

If that test file doesn't exist, run the broader handler tests that exercise the repo instead:

```bash
vendor/bin/phpunit phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
```

Expected: all tests pass.

- [ ] **Step 2.5: Commit**

```bash
git add src/Repositories/AccessRequestRepository.php
git commit -m "$(cat <<'EOF'
refactor: promote AccessRequestRepository::decodePermissions to public static

The method is already pure (no \$this references). Promoting it lets
the upcoming UserNotificationRepository reuse the same JSONB → array
decoder without duplication.

Internal callable in decodePermissionsList updated from \$this to
self::class.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — `UserNotificationRepository` (TDD)

**Files:**

- Create: `src/Repositories/UserNotificationRepository.php`
- Test: `phpunit_tests/Repositories/UserNotificationRepositoryTest.php`

**Pre-req confirmation:** Inspect `src/Repositories/AccessRequestRepository.php` constructor (lines 60-75 or
thereabouts) to confirm the exact PDO-acquisition pattern. The constructor signature should be
`public function __construct(?\PDO $pdo = null)`. Mirror this exactly. If the existing constructor uses
`Connection::getInstance()->getPdo()` (or `Connection::pdo()`), use the same call.

- [ ] **Step 3.1: Write the failing test file**

Create `phpunit_tests/Repositories/UserNotificationRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\UserNotificationRepository;
use LiturgicalCalendar\Api\Tests\Handlers\AbstractHandlerTestCase;

final class UserNotificationRepositoryTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    private UserNotificationRepository $repo;
    private AccessRequestRepository $accessReqRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo          = new UserNotificationRepository(self::$pdo);
        $this->accessReqRepo = new AccessRequestRepository(self::$pdo);
    }

    public function testFetchInboxReturnsEmptyShapeWhenUserHasNoRequests(): void
    {
        $result = $this->repo->fetchInbox('zitadel-user-empty');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['unread_count']);
        self::assertSame('1970-01-01T00:00:00+00:00', $result['last_seen_at']);
    }

    public function testFetchInboxExcludesPendingRequests(): void
    {
        $this->accessReqRepo->create(
            'zitadel-user-a',
            'a@example.test',
            'User A',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        // Pending — no review yet.

        $result = $this->repo->fetchInbox('zitadel-user-a');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['unread_count']);
    }

    public function testFetchInboxReturnsReviewedItemsAllUnreadWhenNoBookmark(): void
    {
        $id1 = $this->accessReqRepo->create(
            'zitadel-user-b',
            'b@example.test',
            'User B',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $id2 = $this->accessReqRepo->create(
            'zitadel-user-b',
            'b@example.test',
            'User B',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 'foo', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id1, 'admin-x', 'welcome');
        $this->accessReqRepo->reject($id2, 'admin-x', 'denied');

        $result = $this->repo->fetchInbox('zitadel-user-b');

        self::assertCount(2, $result['items']);
        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['unread_count']);
        foreach ($result['items'] as $item) {
            self::assertTrue($item['unread']);
            self::assertSame('access_request_reviewed', $item['type']);
            self::assertIsArray($item['permissions']);
        }
    }

    public function testFetchInboxIsolatesUsers(): void
    {
        $idA = $this->accessReqRepo->create(
            'zitadel-user-c',
            'c@example.test',
            'C',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $idB = $this->accessReqRepo->create(
            'zitadel-user-d',
            'd@example.test',
            'D',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'FR', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($idA, 'admin-x', null);
        $this->accessReqRepo->approve($idB, 'admin-x', null);

        $resultC = $this->repo->fetchInbox('zitadel-user-c');
        self::assertCount(1, $resultC['items']);
        self::assertSame(1, $resultC['total']);
        self::assertSame('zitadel-user-c', 'zitadel-user-c'); // sanity
    }

    public function testFetchInboxRespectsLimit(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $id = $this->accessReqRepo->create(
                'zitadel-user-e',
                "e{$i}@example.test",
                "E{$i}",
                'developer',
                [['object_type' => 'test_definition', 'object_id' => "obj-{$i}", 'relation' => 'editor']]
            );
            $this->accessReqRepo->approve($id, 'admin-x', null);
        }

        $result = $this->repo->fetchInbox('zitadel-user-e', limit: 50);

        self::assertCount(50, $result['items']);
        self::assertSame(55, $result['total']);
        self::assertSame(55, $result['unread_count']);
    }

    public function testFetchInboxOrdersByReviewedAtDesc(): void
    {
        $id1 = $this->accessReqRepo->create(
            'zitadel-user-f',
            'f@example.test',
            'F',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id1, 'admin-x', null);

        usleep(1_100_000); // 1.1s — coarser than Postgres TIMESTAMP precision

        $id2 = $this->accessReqRepo->create(
            'zitadel-user-f',
            'f@example.test',
            'F',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 't', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id2, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-f');

        self::assertSame($id2, $result['items'][0]['request_id']);
        self::assertSame($id1, $result['items'][1]['request_id']);
    }

    public function testMarkSeenInsertsRowOnFirstCall(): void
    {
        $seenAt = $this->repo->markSeen('zitadel-user-g');

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $seenAt
        );

        // Row exists in DB.
        $stmt = self::$pdo->prepare(
            'SELECT user_id FROM user_notification_state WHERE user_id = :u'
        );
        $stmt->execute(['u' => 'zitadel-user-g']);
        self::assertSame('zitadel-user-g', $stmt->fetchColumn());
    }

    public function testMarkSeenAdvancesOnSecondCall(): void
    {
        $first = $this->repo->markSeen('zitadel-user-h');
        usleep(1_100_000);
        $second = $this->repo->markSeen('zitadel-user-h');

        self::assertGreaterThan($first, $second);
    }

    public function testMarkSeenThenFetchInboxMarksItemsRead(): void
    {
        $id = $this->accessReqRepo->create(
            'zitadel-user-i',
            'i@example.test',
            'I',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id, 'admin-x', null);

        usleep(1_100_000);
        $this->repo->markSeen('zitadel-user-i');

        $result = $this->repo->fetchInbox('zitadel-user-i');
        self::assertCount(1, $result['items']);
        self::assertFalse($result['items'][0]['unread']);
        self::assertSame(0, $result['unread_count']);
        self::assertSame(1, $result['total']);
    }

    public function testFetchInboxDecodesPermissionsJsonb(): void
    {
        $id = $this->accessReqRepo->create(
            'zitadel-user-j',
            'j@example.test',
            'J',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-j');

        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']],
            $result['items'][0]['permissions']
        );
    }
}
```

- [ ] **Step 3.2: Run the test — confirm it fails (class doesn't exist yet)**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/UserNotificationRepositoryTest.php`

Expected: Errors like "Class 'LiturgicalCalendar\Api\Repositories\UserNotificationRepository' not found" — confirms test is wired up but implementation is missing.

- [ ] **Step 3.3: Write the repository**

Create `src/Repositories/UserNotificationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;

/**
 * Repository for user-facing notification state and inbox queries.
 *
 * Backs the GET /auth/notifications and POST /auth/notifications/seen
 * endpoints. Reads from access_requests (filtered to reviewed rows for
 * the authenticated user) and reads/writes the user_notification_state
 * bookmark table.
 *
 * The "no row yet = unseen since epoch" semantics are handled in the
 * read path via a NULL → '1970-01-01' fallback. Reads never insert.
 */
final class UserNotificationRepository
{
    private const EPOCH = '1970-01-01 00:00:00';

    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance()->getPdo();
    }

    /**
     * Fetch the user's inbox + unread badge metadata.
     *
     * @return array{
     *     items: array<int, array{
     *         type: string,
     *         request_id: string,
     *         requested_role: string,
     *         status: string,
     *         review_notes: ?string,
     *         reviewed_at: string,
     *         permissions: array<int, array{object_type: string, object_id: string, relation: string}>,
     *         unread: bool
     *     }>,
     *     total: int,
     *     unread_count: int,
     *     last_seen_at: string
     * }
     */
    public function fetchInbox(string $userId, int $limit = 50): array
    {
        // Statement A: bookmark (or epoch).
        $stmt = $this->pdo->prepare(
            'SELECT last_notification_seen_at FROM user_notification_state WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $lastSeenRaw = $stmt->fetchColumn();
        $lastSeen    = is_string($lastSeenRaw) ? $lastSeenRaw : self::EPOCH;

        // Statement B: items + window-function counts over the full filtered set.
        $sql  = <<<'SQL'
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
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':last_seen', $lastSeen);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [
                'items'        => [],
                'total'        => 0,
                'unread_count' => 0,
                'last_seen_at' => $this->iso8601($lastSeen),
            ];
        }

        $total       = (int) $rows[0]['total'];
        $unreadCount = (int) $rows[0]['unread_count'];

        $items = array_map(
            function (array $row): array {
                $decoded = AccessRequestRepository::decodePermissions($row);
                /** @var array<int, array{object_type: string, object_id: string, relation: string}> $perms */
                $perms = is_array($decoded['permissions']) ? $decoded['permissions'] : [];
                return [
                    'type'           => 'access_request_reviewed',
                    'request_id'     => (string) $row['id'],
                    'requested_role' => (string) $row['requested_role'],
                    'status'         => (string) $row['status'],
                    'review_notes'   => $row['review_notes'] === null ? null : (string) $row['review_notes'],
                    'reviewed_at'    => $this->iso8601((string) $row['reviewed_at']),
                    'permissions'    => $perms,
                    'unread'         => (bool) $row['unread'],
                ];
            },
            $rows
        );

        return [
            'items'        => $items,
            'total'        => $total,
            'unread_count' => $unreadCount,
            'last_seen_at' => $this->iso8601($lastSeen),
        ];
    }

    /**
     * Mark the user's inbox as seen. Single source of truth for the
     * timestamp is the database clock (NOW() in SQL, returned via
     * RETURNING).
     *
     * @return string RFC 3339 UTC timestamp of the new bookmark.
     */
    public function markSeen(string $userId): string
    {
        $sql  = <<<'SQL'
            INSERT INTO user_notification_state (user_id, last_notification_seen_at)
            VALUES (:uid, NOW())
            ON CONFLICT (user_id) DO UPDATE
            SET last_notification_seen_at = EXCLUDED.last_notification_seen_at
            RETURNING last_notification_seen_at
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $seen = (string) $stmt->fetchColumn();
        return $this->iso8601($seen);
    }

    /**
     * Convert a Postgres TIMESTAMP (no TZ) string to RFC 3339 UTC.
     *
     * The DB stores wall-clock time in Europe/Vatican per the project
     * convention; the wire format is always UTC.
     */
    private function iso8601(string $dbTimestamp): string
    {
        return ( new \DateTimeImmutable($dbTimestamp, new \DateTimeZone('Europe/Vatican')) )
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:sP');
    }
}
```

- [ ] **Step 3.4: Update `AbstractHandlerTestCase` truncation list (prereq for tests)**

In `phpunit_tests/Handlers/AbstractHandlerTestCase.php`, locate the `setUp()` truncation block (around lines 111-124). The current list looks like:

```php
self::$pdo->exec('TRUNCATE TABLE api_keys, applications, access_requests, audit_log RESTART IDENTITY CASCADE');
```

Update to:

```php
self::$pdo->exec('TRUNCATE TABLE api_keys, applications, access_requests, audit_log, user_notification_state RESTART IDENTITY CASCADE');
```

(The exact original line may differ; the change is adding `user_notification_state` to the comma-separated list.)

- [ ] **Step 3.5: Run the repository tests — expect all pass**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/UserNotificationRepositoryTest.php`

Expected: 10/10 tests pass.

If any fail:

- "Cannot truncate" → Step 3.4 not done.
- "PDO error: relation user_notification_state does not exist" → migration from Task 1 not applied. Run `composer db:migrate`.
- Ordering test fails sporadically → increase the `usleep` to `2_100_000` (some CI Postgres builds have coarser timestamp precision than expected).

- [ ] **Step 3.6: Static analysis**

Run: `composer analyse`

Expected: `[OK] No errors`. PHPStan L10 must remain clean.

- [ ] **Step 3.7: Lint**

Run: `composer lint`

Expected: phpcs reports no PSR-12 violations on the new file.

If violations: `composer lint:fix` (auto-fix), then re-run `composer lint`.

- [ ] **Step 3.8: Commit**

```bash
git add src/Repositories/UserNotificationRepository.php \
        phpunit_tests/Repositories/UserNotificationRepositoryTest.php \
        phpunit_tests/Handlers/AbstractHandlerTestCase.php
git commit -m "$(cat <<'EOF'
feat(repo): UserNotificationRepository for issue #573

Two methods:
- fetchInbox(userId, limit): two SQL statements (bookmark fetch +
  items/counts via window functions). Returns the inbox shape with
  total, unread_count, last_seen_at, and per-item unread bool.
- markSeen(userId): single upsert with RETURNING; DB clock is the
  single source of truth for the timestamp.

Reuses AccessRequestRepository::decodePermissions (now public static).
Adds user_notification_state to AbstractHandlerTestCase truncate list
for per-test isolation.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 — `NotificationsHandler` GET path (TDD)

**Files:**

- Create: `src/Handlers/Auth/NotificationsHandler.php`
- Test: `phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`

- [ ] **Step 4.1: Write the failing test file (GET cases only)**

Create `phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\NotificationsHandler;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Tests\Handlers\AbstractHandlerTestCase;

final class NotificationsHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    public function testGetInboxRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        ( new NotificationsHandler() )->handle(
            $this->requestFor('GET', '/auth/notifications')
        );
    }

    public function testGetInboxReturnsEmptyShapeForNewUser(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-x'
            )
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = $this->decodeJsonBody($response);
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
        self::assertSame(0, $body['unread_count']);
        self::assertSame('1970-01-01T00:00:00+00:00', $body['last_seen_at']);
    }

    public function testGetInboxReturnsReviewedItemsWithUnreadFlag(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create(
            'zitadel-user-y',
            'y@example.test',
            'Y',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $repo->approve($id, 'admin-z', 'welcome');

        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-y'
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['items']);
        self::assertSame($id, $body['items'][0]['request_id']);
        self::assertSame('access_request_reviewed', $body['items'][0]['type']);
        self::assertSame('approved', $body['items'][0]['status']);
        self::assertSame('welcome', $body['items'][0]['review_notes']);
        self::assertSame('calendar_editor', $body['items'][0]['requested_role']);
        self::assertTrue($body['items'][0]['unread']);
        self::assertSame(1, $body['total']);
        self::assertSame(1, $body['unread_count']);
    }

    public function testGetInboxUnknownSubPathReturns404(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications/bogus'),
                'zitadel-user-x'
            )
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
```

- [ ] **Step 4.2: Run the tests — confirm they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`

Expected: errors like "Class 'NotificationsHandler' not found".

- [ ] **Step 4.3: Create the handler with GET path support**

Create `src/Handlers/Auth/NotificationsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\UserNotificationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * User notifications handler — issue #573.
 *
 * - GET  /auth/notifications        — Inbox + unread badge.
 * - POST /auth/notifications/seen   — Mark inbox seen (bookmark).
 *
 * OIDC-gated via OidcAuthMiddleware (wired in Router.php). The user
 * identifier is the Zitadel sub from oidc_user, which keys both the
 * access_requests query and the user_notification_state row.
 */
final class NotificationsHandler extends AbstractHandler
{
    private const INBOX_LIMIT = 50;

    private ?UserNotificationRepository $repository = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): UserNotificationRepository
    {
        if ($this->repository === null) {
            $this->repository = new UserNotificationRepository();
        }
        return $this->repository;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::tryFrom($request->getMethod());

        if ($method === null) {
            $this->validateRequestMethod($request);
        }

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);
        $response = $response->withHeader('Cache-Control', 'no-store');

        /** @var array{sub?: string, email?: string, name?: string, preferred_username?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');
        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }
        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        $tail = $this->extractSubPath($request->getUri()->getPath());

        if ($method === RequestMethod::GET && $tail === '') {
            return $this->getInbox($response, $userId);
        }

        if ($method === RequestMethod::POST && $tail === 'seen') {
            return $this->markSeen($response, $userId);
        }

        return $response->withStatus(StatusCode::NOT_FOUND->value, StatusCode::NOT_FOUND->reason());
    }

    private function getInbox(ResponseInterface $response, string $userId): ResponseInterface
    {
        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }
        $body = $this->getRepository()->fetchInbox($userId, self::INBOX_LIMIT);
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));
        return $response->withStatus(StatusCode::OK->value);
    }

    private function markSeen(ResponseInterface $response, string $userId): ResponseInterface
    {
        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }
        $seenAt = $this->getRepository()->markSeen($userId);
        $response->getBody()->write((string) json_encode(
            ['success' => true, 'seen_at' => $seenAt],
            JSON_THROW_ON_ERROR
        ));
        return $response->withStatus(StatusCode::OK->value);
    }

    private function extractSubPath(string $path): string
    {
        $prefix = '/auth/notifications';
        $base   = isset($_ENV['API_BASE_PATH']) && is_string($_ENV['API_BASE_PATH'])
            ? rtrim($_ENV['API_BASE_PATH'], '/')
            : '';
        $needle = $base . $prefix;
        $tail   = substr($path, strlen($needle));
        return trim($tail, '/');
    }
}
```

- [ ] **Step 4.4: Run the tests — confirm GET tests pass**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`

Expected: 4/4 tests pass.

- [ ] **Step 4.5: Static analysis**

Run: `composer analyse`

Expected: `[OK] No errors`.

- [ ] **Step 4.6: Lint**

Run: `composer lint`

Expected: no violations. If any, run `composer lint:fix`.

- [ ] **Step 4.7: Commit**

```bash
git add src/Handlers/Auth/NotificationsHandler.php \
        phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(api): NotificationsHandler GET inbox path for issue #573

GET /auth/notifications returns up to 50 recent reviewed access-
requests for the authenticated user, with unread_count, total, and
last_seen_at. Inbox items remain visible after marking-as-seen; only
the unread flag flips.

Auth via OidcAuthMiddleware (wired in next task). Cache-Control:
no-store. Unknown sub-paths return 404.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 — `NotificationsHandler` POST /seen + additional unit tests

**Files:**

- Modify: `phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php` (add cases)

The handler already supports POST /seen (Task 4 code includes it). This task expands test coverage.

- [ ] **Step 5.1: Add POST /seen and round-trip tests**

Append these methods to `phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php` inside the class:

```php
    public function testPostSeenRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        ( new NotificationsHandler() )->handle(
            $this->requestFor('POST', '/auth/notifications/seen', [], [])
        );
    }

    public function testPostSeenInsertsBookmarkAndReturnsTimestamp(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-seen-1'
            )
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $body['seen_at']
        );

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM user_notification_state WHERE user_id = :u'
        );
        $stmt->execute(['u' => 'zitadel-user-seen-1']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testPostSeenTwiceAdvancesTimestamp(): void
    {
        $h = new NotificationsHandler();

        $resp1 = $h->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-seen-2'
            )
        );
        $body1 = $this->decodeJsonBody($resp1);

        usleep(1_100_000);

        $resp2 = $h->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-seen-2'
            )
        );
        $body2 = $this->decodeJsonBody($resp2);

        self::assertGreaterThan($body1['seen_at'], $body2['seen_at']);
    }

    public function testPostSeenUnknownSubPathReturns404(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/bogus', [], []),
                'zitadel-user-seen-3'
            )
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPostSeenAtBaseUrlReturns404(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications', [], []),
                'zitadel-user-seen-4'
            )
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testGetThenSeenThenGetFlipsUnreadFlag(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create(
            'zitadel-user-rt-1',
            'rt@example.test',
            'RT',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $repo->approve($id, 'admin-x', null);

        $h = new NotificationsHandler();

        $body1 = $this->decodeJsonBody($h->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-rt-1'
            )
        ));
        self::assertSame(1, $body1['unread_count']);
        self::assertTrue($body1['items'][0]['unread']);

        usleep(1_100_000);

        $h->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/auth/notifications/seen', [], []),
                'zitadel-user-rt-1'
            )
        );

        $body2 = $this->decodeJsonBody($h->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/auth/notifications'),
                'zitadel-user-rt-1'
            )
        ));
        self::assertSame(0, $body2['unread_count']);
        self::assertFalse($body2['items'][0]['unread']);
        self::assertSame(1, $body2['total']);
    }
```

- [ ] **Step 5.2: Run the expanded tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php`

Expected: 10/10 tests pass (4 from Task 4 + 6 added here).

- [ ] **Step 5.3: Static analysis + lint**

Run:

```bash
composer analyse
composer lint
```

Expected: both clean.

- [ ] **Step 5.4: Commit**

```bash
git add phpunit_tests/Handlers/Auth/NotificationsHandlerTest.php
git commit -m "$(cat <<'EOF'
test(api): expand NotificationsHandler tests with POST /seen + round-trip

Adds: auth check, first/second POST timestamps advance, 404 for
unknown sub-paths (including the bare URL), GET→POST→GET flips
unread flag and unread_count.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6 — Wire `NotificationsHandler` into the Router (+ OIDC gate)

**Files:**

- Modify: `src/Router.php` (imports, auth dispatch branch, admin dispatch branch, OIDC middleware gate)

- [ ] **Step 6.1: Add the new import; alias the existing admin one**

In `src/Router.php`, find the `use` block containing `LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler;`.
Replace that single line with both of these (keeping alphabetical order with surrounding imports):

```php
use LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler as AdminNotificationsHandler;
use LiturgicalCalendar\Api\Handlers\Auth\NotificationsHandler;
```

- [ ] **Step 6.2: Update the admin dispatch to use the alias**

In `src/Router.php`, locate the `elseif ($adminRoute === 'notifications')` branch (around line 378-382). The current body is:

```php
                        } elseif ($adminRoute === 'notifications') {
                            // Admin notifications route
                            // GET /admin/notifications - Get counts of pending items
                            $notificationsHandler = new NotificationsHandler();
                            $this->handler        = $notificationsHandler;
```

Change `new NotificationsHandler()` to `new AdminNotificationsHandler()`:

```php
                        } elseif ($adminRoute === 'notifications') {
                            // Admin notifications route
                            // GET /admin/notifications - Get counts of pending items
                            $notificationsHandler = new AdminNotificationsHandler();
                            $this->handler        = $notificationsHandler;
```

- [ ] **Step 6.3: Add the auth dispatch branch**

In `src/Router.php`, locate the `auth` case (around line 329-365). Find the last `elseif` inside it (currently `email-verification`, around line 357-362):

```php
                        } elseif ($authRoute === 'email-verification') {
                            // Email verification routes for authenticated users
                            // POST /auth/email-verification/resend - Resend verification email
                            $emailVerificationHandler = new EmailVerificationHandler();
                            $this->handler            = $emailVerificationHandler;
                        } else {
```

Insert a new `elseif` immediately before the `} else {` (keeping the dispatch alphabetically organized):

```php
                        } elseif ($authRoute === 'email-verification') {
                            // Email verification routes for authenticated users
                            // POST /auth/email-verification/resend - Resend verification email
                            $emailVerificationHandler = new EmailVerificationHandler();
                            $this->handler            = $emailVerificationHandler;
                        } elseif ($authRoute === 'notifications') {
                            // User notifications routes (issue #573)
                            // GET  /auth/notifications        - Inbox + unread badge
                            // POST /auth/notifications/seen   - Mark inbox seen
                            $notificationsHandler = new NotificationsHandler();
                            $this->handler        = $notificationsHandler;
                        } else {
```

- [ ] **Step 6.4: Add `'notifications'` to the OIDC middleware gate**

In `src/Router.php`, locate the OIDC pipe condition (around line 585). The current check is:

```php
            ( $route === 'auth' && count($requestPathParts) >= 1 && in_array($requestPathParts[0], ['access-requests', 'email-verification'], true) )
```

Add `'notifications'`:

```php
            ( $route === 'auth' && count($requestPathParts) >= 1 && in_array($requestPathParts[0], ['access-requests', 'email-verification', 'notifications'], true) )
```

- [ ] **Step 6.5: Static analysis**

Run: `composer analyse`

Expected: `[OK] No errors`. PHPStan must remain L10 clean — confirms the alias rename didn't break any callsite.

- [ ] **Step 6.6: Lint**

Run: `composer lint`

Expected: no violations.

- [ ] **Step 6.7: Run full unit suite — no regressions in adjacent routes**

Run: `composer test`

Expected: all tests pass. Pay particular attention to any admin-notifications tests that depended on the old import name.

- [ ] **Step 6.8: Commit**

```bash
git add src/Router.php
git commit -m "$(cat <<'EOF'
feat(router): wire NotificationsHandler under /auth/notifications

- Rename existing Admin\NotificationsHandler import to
  AdminNotificationsHandler to free the bare name for the new
  Auth\NotificationsHandler.
- Add auth dispatch branch for /auth/notifications (handler dispatches
  GET inbox vs POST /seen on URI tail).
- Add 'notifications' to the OidcAuthMiddleware activation list — same
  gate as /auth/access-requests.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7 — Integration tests (HTTP round-trip)

**Files:**

- Create: `phpunit_tests/Routes/Auth/NotificationsRoutesTest.php`

- [ ] **Step 7.1: Inspect an existing routes test for the bootstrap pattern**

Examine `phpunit_tests/Routes/Auth/AccessRequestsRoutesTest.php` (or the closest equivalent in `phpunit_tests/Routes/Auth/`). Note:

- the `extends ApiTestCase` declaration,
- whether it uses `getJwtToken()` or `getZitadelToken()`,
- the `markTestSkipped` guard pattern,
- how it issues HTTP requests (cURL or Guzzle?).

Mirror that pattern in the new file.

- [ ] **Step 7.2: Create the routes test**

Create `phpunit_tests/Routes/Auth/NotificationsRoutesTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Routes\Auth;

use LiturgicalCalendar\Api\Tests\ApiTestCase;

/**
 * @group slow
 * Integration tests for /auth/notifications. Requires a running
 * API server, a configured database, and either a Zitadel OIDC
 * config or admin login credentials.
 */
final class NotificationsRoutesTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!self::isDatabaseConfigured()) {
            self::markTestSkipped('Database not configured.');
        }
    }

    public function testInboxRequiresAuth(): void
    {
        $response = $this->httpGet('/auth/notifications', []);
        self::assertSame(401, $response['status']);
    }

    public function testInboxReturnsExpectedShapeWithBearer(): void
    {
        $token = self::getJwtToken();
        if ($token === null) {
            self::markTestSkipped('No JWT token obtainable.');
        }

        $response = $this->httpGet('/auth/notifications', self::authHeaders($token));
        self::assertSame(200, $response['status']);

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertArrayHasKey('items', $body);
        self::assertArrayHasKey('total', $body);
        self::assertArrayHasKey('unread_count', $body);
        self::assertArrayHasKey('last_seen_at', $body);
        self::assertIsArray($body['items']);
        self::assertIsInt($body['total']);
        self::assertIsInt($body['unread_count']);
        self::assertSame('no-store', $response['headers']['cache-control'] ?? '');
    }

    public function testPostSeenWithBearer(): void
    {
        $token = self::getJwtToken();
        if ($token === null) {
            self::markTestSkipped('No JWT token obtainable.');
        }

        $headers = array_merge(self::authHeaders($token), ['Content-Type' => 'application/json']);
        $response = $this->httpPost('/auth/notifications/seen', $headers, '{}');
        self::assertSame(200, $response['status']);

        $body = json_decode($response['body'], true);
        self::assertTrue($body['success']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $body['seen_at']
        );
    }

    public function testGetSeenGetEndToEnd(): void
    {
        $token = self::getJwtToken();
        if ($token === null) {
            self::markTestSkipped('No JWT token obtainable.');
        }

        $headers     = self::authHeaders($token);
        $postHeaders = array_merge($headers, ['Content-Type' => 'application/json']);

        $this->httpPost('/auth/notifications/seen', $postHeaders, '{}');
        sleep(1); // ensure NOW() advances past any seeded reviewed_at
        $second = $this->httpGet('/auth/notifications', $headers);
        $body   = json_decode($second['body'], true);
        self::assertSame(0, $body['unread_count']);
        foreach ($body['items'] as $item) {
            self::assertFalse($item['unread']);
        }
    }
}
```

Note on `httpGet` / `httpPost` helpers: if `ApiTestCase` doesn't already expose them, replace with whatever HTTP
helper the existing routes tests use (likely cURL via a small wrapper or Guzzle). Check the existing routes tests
first; do not invent a new wrapper.

- [ ] **Step 7.3: Start the API server**

Run: `composer start`

Expected: server listening on `localhost:8000` with 6 workers. Verify with `curl http://localhost:8000/calendar?year=2026 | head -c 100`.

- [ ] **Step 7.4: Run the integration tests**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Routes/Auth/NotificationsRoutesTest.php --group slow
```

Expected: 4/4 pass. If Zitadel is not configured locally, tests that need a token will skip rather than fail.

- [ ] **Step 7.5: Stop the API server**

Run: `composer stop`

- [ ] **Step 7.6: Commit**

```bash
git add phpunit_tests/Routes/Auth/NotificationsRoutesTest.php
git commit -m "$(cat <<'EOF'
test(api): integration tests for /auth/notifications

HTTP round-trip coverage: 401 without auth, 200 with expected
response shape, POST /seen success, GET→POST→GET end-to-end with
unread_count flipping to 0.

@group slow — requires running API server + configured database.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8 — OpenAPI: tag + schemas

**Files:**

- Modify: `jsondata/schemas/openapi.json`

- [ ] **Step 8.1: Add the new tag**

In `jsondata/schemas/openapi.json`, locate the top-level `"tags"` array. Append a new entry (keep JSON syntactically valid — add the comma to the previous entry):

```json
{
  "name": "Notifications",
  "description": "User-facing in-app notifications. Currently scoped to access-request review events; may expand to other event types."
}
```

- [ ] **Step 8.2: Add the three new schemas under `components.schemas`**

Add to `components.schemas` (any order; keep alphabetical if the file is sorted):

```json
    "UserNotification": {
      "type": "object",
      "description": "A single notification item in the authenticated user's inbox.",
      "properties": {
        "type":           { "type": "string", "enum": ["access_request_reviewed"], "description": "Discriminator for the notification kind." },
        "request_id":     { "type": "string", "format": "uuid", "description": "UUID of the underlying access_requests row." },
        "requested_role": { "type": "string", "description": "The role that was requested (e.g., 'calendar_editor')." },
        "status":         { "type": "string", "enum": ["approved", "rejected", "revoked"], "description": "Resolution status of the request." },
        "review_notes":   { "type": ["string", "null"], "description": "Free-text notes from the reviewing admin, if any." },
        "reviewed_at":    { "type": "string", "format": "date-time", "description": "RFC 3339 UTC timestamp of when the request was reviewed." },
        "permissions": {
          "type": "array",
          "items": { "$ref": "#/components/schemas/Permission" },
          "description": "The permissions associated with the request."
        },
        "unread":         { "type": "boolean", "description": "True if reviewed_at is more recent than the user's last_seen_at bookmark." }
      },
      "required": ["type", "request_id", "requested_role", "status", "review_notes", "reviewed_at", "permissions", "unread"],
      "additionalProperties": false
    },
    "UserNotificationsResponse": {
      "type": "object",
      "description": "Response from GET /auth/notifications — the inbox with unread badge metadata.",
      "properties": {
        "items": {
          "type": "array",
          "items": { "$ref": "#/components/schemas/UserNotification" }
        },
        "total":        { "type": "integer", "minimum": 0 },
        "unread_count": { "type": "integer", "minimum": 0 },
        "last_seen_at": { "type": "string", "format": "date-time" }
      },
      "required": ["items", "total", "unread_count", "last_seen_at"],
      "additionalProperties": false
    },
    "NotificationsSeenResponse": {
      "type": "object",
      "description": "Response from POST /auth/notifications/seen.",
      "properties": {
        "success": { "type": "boolean", "enum": [true] },
        "seen_at": { "type": "string", "format": "date-time" }
      },
      "required": ["success", "seen_at"],
      "additionalProperties": false
    }
```

- [ ] **Step 8.3: Lint OpenAPI**

Run: `composer lint:openapi`

Expected: Redocly passes (0 errors). If `Unauthorized`, `NotAcceptable`, or `UnsupportedMediaType` `$ref` targets are
missing under `components.responses`, Redocly will flag them when paths are added in Task 9. (Don't pre-add — Task 9
will surface that need.)

- [ ] **Step 8.4: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "$(cat <<'EOF'
docs(openapi): add Notifications tag and 3 schemas for issue #573

- Tag: "Notifications" — user-facing notification inbox.
- UserNotification — single inbox item, reuses existing Permission.
- UserNotificationsResponse — GET /auth/notifications response shape.
- NotificationsSeenResponse — POST /auth/notifications/seen response.

Path entries added in the next commit.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9 — OpenAPI: paths

**Files:**

- Modify: `jsondata/schemas/openapi.json`

- [ ] **Step 9.1: Confirm shared error responses exist**

Search `jsondata/schemas/openapi.json` for `"Unauthorized":`, `"NotAcceptable":`, `"UnsupportedMediaType":` under `components.responses`:

```bash
jq '.components.responses | keys' jsondata/schemas/openapi.json
```

Expected: array containing `"Unauthorized"`, `"NotAcceptable"`, `"UnsupportedMediaType"`. If any is missing, add a
minimal one mirroring the pattern of the others (e.g., look at how `/auth/login` references its errors).

- [ ] **Step 9.2: Add the two new path entries**

Under top-level `"paths"`, add (alphabetical placement is fine):

```json
    "/auth/notifications": {
      "get": {
        "tags": ["Notifications"],
        "summary": "Get the authenticated user's notification inbox",
        "description": "Returns up to 50 most recent reviewed access-requests for the current user, with unread badge count and last-seen bookmark. Inbox items remain visible after marking-as-seen; only the unread flag and unread_count change.",
        "security": [{ "BearerAuth": [] }, { "CookieAuth": [] }],
        "responses": {
          "200": {
            "description": "Inbox payload.",
            "content": {
              "application/json": {
                "schema": { "$ref": "#/components/schemas/UserNotificationsResponse" }
              }
            },
            "headers": {
              "Cache-Control": { "schema": { "type": "string", "enum": ["no-store"] } }
            }
          },
          "401": { "$ref": "#/components/responses/Unauthorized" },
          "406": { "$ref": "#/components/responses/NotAcceptable" }
        }
      }
    },
    "/auth/notifications/seen": {
      "post": {
        "tags": ["Notifications"],
        "summary": "Mark the user's notification inbox as seen",
        "description": "Upserts the user's bookmark so that last_notification_seen_at = NOW() (DB clock). Inbox itself is unchanged; only unread flags and unread_count flip on subsequent GETs.",
        "security": [{ "BearerAuth": [] }, { "CookieAuth": [] }],
        "requestBody": {
          "required": false,
          "content": {
            "application/json": {
              "schema": { "type": "object", "additionalProperties": false }
            }
          }
        },
        "responses": {
          "200": {
            "description": "Bookmark updated.",
            "content": {
              "application/json": {
                "schema": { "$ref": "#/components/schemas/NotificationsSeenResponse" }
              }
            },
            "headers": {
              "Cache-Control": { "schema": { "type": "string", "enum": ["no-store"] } }
            }
          },
          "401": { "$ref": "#/components/responses/Unauthorized" },
          "406": { "$ref": "#/components/responses/NotAcceptable" },
          "415": { "$ref": "#/components/responses/UnsupportedMediaType" }
        }
      }
    }
```

- [ ] **Step 9.3: Lint OpenAPI**

Run: `composer lint:openapi`

Expected: 0 errors. If any `$ref` to `components/responses/...` is unresolved, add the missing shared response definition.

- [ ] **Step 9.4: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "$(cat <<'EOF'
docs(openapi): add /auth/notifications paths for issue #573

- GET  /auth/notifications      — inbox + badge (200 / 401 / 406)
- POST /auth/notifications/seen — mark-as-seen (200 / 401 / 406 / 415)

Both gated by BearerAuth | CookieAuth (matches /auth/access-requests).
Cache-Control: no-store on both 200 responses.

Refs: docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10 — Final verification gate

**Files:** none (verification only)

- [ ] **Step 10.1: Full unit test suite**

Run: `composer test`

Expected: 100% pass. Pay attention to:

- existing AdminNotificationsHandler tests (alias rename didn't break them),
- AccessRequestRepository tests (decodePermissions promotion didn't break them),
- all new NotificationsHandler / UserNotificationRepository tests.

- [ ] **Step 10.2: Quick subset confirmation**

Run: `composer test:quick`

Expected: pass; ensures non-slow regressions are clean.

- [ ] **Step 10.3: Static analysis L10**

Run: `composer analyse`

Expected: `[OK] No errors`.

- [ ] **Step 10.4: phpcs**

Run: `composer lint`

Expected: 0 violations.

- [ ] **Step 10.5: OpenAPI lint**

Run: `composer lint:openapi`

Expected: 0 errors.

- [ ] **Step 10.6: Markdown lint**

Run: `composer lint:md`

Expected: 0 errors (the spec + plan must remain clean).

- [ ] **Step 10.7: Parallel syntax check**

Run: `composer parallel-lint`

Expected: 0 errors.

- [ ] **Step 10.8: Schema verification — sanity-check the migration is still in the database**

Run:

```bash
docker compose exec -T db psql -U litcal -d litcal -c "\d user_notification_state"
docker compose exec -T db psql -U litcal -d litcal -c "SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 3"
```

Expected: table exists; migrations list shows `Version20260530140000` as applied.

- [ ] **Step 10.9: Manual smoke test (optional but recommended)**

Start the server, get a token, exercise both endpoints:

```bash
composer start
# In another terminal:
TOKEN=$(curl -s -X POST http://localhost:8000/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"password"}' | jq -r '.access_token')
curl -i http://localhost:8000/auth/notifications -H "Authorization: Bearer $TOKEN"
curl -i -X POST http://localhost:8000/auth/notifications/seen \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{}'
curl -i http://localhost:8000/auth/notifications -H "Authorization: Bearer $TOKEN"
composer stop
```

Expected:

- First GET: 200 with `unread_count: 0, total: 0, items: []` for the admin user (no access-requests for them).
- POST /seen: 200 with `success: true, seen_at` timestamp.
- Second GET: same shape; `last_seen_at` updated to match the POST's `seen_at`.

(Note: if the local DB has no access-requests for the `admin` user, both GETs show empty inbox. To get a non-trivial
result, seed an access-request as the admin user first via the existing admin tooling — out of scope for this
verification.)

- [ ] **Step 10.10: Push the branch**

```bash
git push -u origin feature/issue-573-user-notifications
```

- [ ] **Step 10.11: Open PR against `development`**

```bash
gh pr create --base development \
  --title 'feat(api): user-facing notifications endpoint pair (#573)' \
  --body "$(cat <<'EOF'
Closes #573.

Adds `GET /auth/notifications` (inbox + unread badge) and
`POST /auth/notifications/seen` (mark-as-seen bookmark) for end users
to receive in-app feedback on access-request review events.

## Design

Inbox + unread badge UX. The bookmark is the only mutable state
(one row per Zitadel user in `user_notification_state`). All
timestamps derive from `NOW()` in SQL — single source of truth.

See `docs/superpowers/specs/2026-05-30-issue-573-user-notifications-design.md`
for the full design and `docs/superpowers/plans/2026-05-30-issue-573-user-notifications-plan.md`
for the implementation plan.

## Changes

- DB: new `user_notification_state` table (migration Version20260530140000).
- New: `UserNotificationRepository`, `NotificationsHandler`.
- Modified: `Router.php` (dispatch + OIDC gate), `AccessRequestRepository::decodePermissions` (public static), `AbstractHandlerTestCase` (truncation list), OpenAPI schema (1 tag + 3 schemas + 2 paths).

## Tests

- 10 unit tests for the repository.
- 10 unit tests for the handler (GET, POST /seen, 404, round-trip).
- 4 integration tests for the routes (@group slow).
- Full suite: composer test, analyse, lint, lint:openapi, lint:md all clean.

## Out of scope (deferred)

- Per-item delete / bulk clear UX.
- Push / WebSocket / email notification channels.
- Notification types beyond access-request reviews (discriminator in place for future expansion).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR created against `development`. CI runs all linters + tests.

---

## Done

All 10 tasks complete. The endpoint pair is live on the feature branch, all checks green, PR open for review.

## Self-review against the spec

- ✅ §2 contract (response shapes, error matrix) — Tasks 4–5 unit tests + Task 7 integration tests assert each field.
- ✅ §3 architecture (OIDC gate, single-handler dispatch, two-statement GET, upsert-with-RETURNING POST) — Tasks 3, 4, 6.
- ✅ §4 migration (TIMESTAMP not TIMESTAMPTZ, VARCHAR(255) PK, default `epoch`) — Task 1.
- ✅ §5 UserNotificationRepository (window functions, two-statement GET, ISO 8601 normalization) — Task 3.
- ✅ §6 NotificationsHandler (method + URI-tail dispatch, sub-path extractor, OIDC user check) — Tasks 4–5.
- ✅ §7 Router wiring (4 edits) — Task 6.
- ✅ §8 OpenAPI (1 tag + 3 schemas + 2 paths) — Tasks 8–9.
- ✅ §9 tests (unit + integration matrices, truncation list update) — Tasks 3, 4, 5, 7.
- ✅ §10 out-of-scope items NOT implemented — confirmed in PR body.
- ✅ §11 files-touched inventory — every file in the spec's inventory is created/modified.
- ✅ Verification commands run after each commit and as the final gate (Task 10).
- ✅ No placeholders (`TODO`, `TBD`, `Add appropriate error handling`).
- ✅ Type/name consistency: `UserNotificationRepository::fetchInbox` / `markSeen` / `iso8601` used consistently across
  Tasks 3–5; `AccessRequestRepository::decodePermissions` (now `public static`) called the same way in repo + tests.
