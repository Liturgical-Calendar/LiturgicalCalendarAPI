<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;

/**
 * Repository for user-facing notification state and inbox queries.
 *
 * Backs the GET /auth/notifications and POST /auth/notifications/seen
 * endpoints. Reads from two sources — access_requests (filtered to reviewed
 * rows for the authenticated user) and sourcedata_change_requests (filtered
 * to settled batches submitted by the authenticated user) — merges them in
 * PHP, and reads/writes the user_notification_state bookmark table.
 *
 * The "no row yet = unseen since epoch" semantics are handled in the
 * read path via a NULL → '1970-01-01' fallback. Reads never insert.
 *
 * @phpstan-type AccessRequestItem array{
 *     type: 'access_request_reviewed',
 *     request_id: string,
 *     requested_role: string,
 *     status: string,
 *     review_notes: ?string,
 *     reviewed_at: string,
 *     permissions: list<array{object_type: string, object_id: string, relation: string}>,
 *     unread: bool
 * }
 * @phpstan-type ChangeRequestItem array{
 *     type: 'change_request_published',
 *     batch_id: string,
 *     resource_type: string,
 *     resource_id: string,
 *     publication_status: string,
 *     pr_number: ?int,
 *     settled_at: string,
 *     unread: bool
 * }
 * @phpstan-type InboxItem AccessRequestItem|ChangeRequestItem
 */
final class UserNotificationRepository
{
    private const EPOCH         = '1970-01-01 00:00:00';
    private const EPOCH_ISO8601 = '1970-01-01T00:00:00+00:00';

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Fetch the user's inbox + unread badge metadata.
     *
     * `items` is a DISCRIMINATED list: reviewed access requests
     * (`type: 'access_request_reviewed'`) and settled source-data change
     * requests (`type: 'change_request_published'`) are interleaved
     * newest-first. The two shapes share only `type` and `unread` — clients
     * MUST switch on `type` before reading any other key.
     *
     * # Why two queries and a PHP merge, rather than one UNION
     *
     * The two sources have genuinely different shapes, and `total` / `unread_count` are window
     * counts over the FULL filtered set rather than over the returned page — so a UNION would need
     * `COUNT(*) OVER ()` spanning both halves, over rows whose columns do not line up. That is one
     * query that is hard to read and harder to prove right. Two straightforward queries plus a
     * `usort` are verifiable by inspection, and each source is already capped at 50 rows, so the
     * merge is bounded.
     *
     * # Why `publication_settled_at` and not `updated_at`
     *
     * `updated_at` moves on every claim, release, reclaim and record, so it answers "when was this
     * row last touched", not "when did this become news". `publication_settled_at` is written once,
     * by the transition to `merged` or `closed`.
     *
     * # One item per batch
     *
     * An editor who changed a calendar and its fourteen i18n files proposed ONE thing. `DISTINCT ON
     * (batch_id)` collapses the rows the way the reviewer already sees them.
     *
     * # Sorting across two differently-typed timestamp columns
     *
     * `access_requests.reviewed_at` is TIMESTAMP (no time zone); `sourcedata_change_requests
     * .publication_settled_at` is TIMESTAMPTZ. String-comparing raw DB values would be wrong unless
     * both always render with the same UTC offset. They are never compared raw: both go through
     * `iso8601()` first, which parses each value (respecting an explicit offset when the string
     * carries one, and otherwise interpreting it as Europe/Vatican wall-clock — the session
     * time zone) and re-renders it via `->setTimezone(UTC)->format('Y-m-d\TH:i:sP')`. That format
     * call always yields a `+00:00` suffix, so by the time `settled_at` / `reviewed_at` reach the
     * merge sort, both are UTC-normalized RFC 3339 strings sharing one offset — `strcmp` on them is
     * chronologically correct. Verified empirically against this DB: a TIMESTAMPTZ value comes back
     * from pdo_pgsql as e.g. `2026-08-30 12:00:00+02` under the Europe/Vatican session zone, and
     * `iso8601()` turns that into `2026-08-30T10:00:00+00:00` — the same shape `iso8601()` produces
     * for a naive TIMESTAMP string interpreted in that same zone.
     *
     * @return array{
     *     items: list<InboxItem>,
     *     total: int,
     *     unread_count: int,
     *     last_seen_at: string
     * }
     */
    public function fetchInbox(string $userId, int $limit = 50): array
    {
        // Enforce the "up to 50" contract locally — defense in depth against
        // callers that ask for an unbounded or non-positive page size.
        $limit = max(1, min($limit, 50));

        // Bookmark (or epoch).
        $stmt = $this->db->prepare(
            'SELECT last_notification_seen_at FROM user_notification_state WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $lastSeenRaw = $stmt->fetchColumn();
        $hasBookmark = is_string($lastSeenRaw);
        $lastSeen    = $hasBookmark ? $lastSeenRaw : self::EPOCH;
        $lastSeenIso = $hasBookmark ? $this->iso8601($lastSeen) : self::EPOCH_ISO8601;

        $access = $this->accessRequestItems($userId, $lastSeen, $limit);
        $change = $this->changeRequestItems($userId, $lastSeen, $limit);

        /** @var list<InboxItem> $items */
        $items = array_merge($access['items'], $change['items']);
        usort(
            $items,
            static fn (array $a, array $b): int => strcmp(
                self::sortTimestamp($b),
                self::sortTimestamp($a)
            )
        );

        // total and unread_count span the FULL filtered set of both sources, not the merged page —
        // both accessRequestItems() and changeRequestItems() compute their own total/unread_count
        // via a window function over their full filtered set, so this is a plain sum of two
        // already-correct numbers, not a re-derivation from either returned page.
        $total       = $access['total'] + $change['total'];
        $unreadCount = $access['unread_count'] + $change['unread_count'];

        return [
            'items'        => array_slice($items, 0, $limit),
            'total'        => $total,
            'unread_count' => $unreadCount,
            'last_seen_at' => $lastSeenIso,
        ];
    }

    /**
     * Reviewed access requests for `$userId`, plus window-function counts over the full filtered
     * set (not just the returned page).
     *
     * @return array{items: list<AccessRequestItem>, total: int, unread_count: int}
     */
    private function accessRequestItems(string $userId, string $lastSeen, int $limit): array
    {
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
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':last_seen', $lastSeen);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [
                'items'        => [],
                'total'        => 0,
                'unread_count' => 0,
            ];
        }

        $total       = $this->toInt($rows[0]['total']);
        $unreadCount = $this->toInt($rows[0]['unread_count']);

        $items = array_map(
            function (array $row): array {
                $decoded     = AccessRequestRepository::decodePermissions($row);
                $rawPerms    = $decoded['permissions'] ?? [];
                $perms       = is_array($rawPerms) ? array_values($rawPerms) : [];
                $reviewNotes = $row['review_notes'] ?? null;
                /** @var list<array{object_type: string, object_id: string, relation: string}> $perms */
                return [
                    'type'           => 'access_request_reviewed',
                    'request_id'     => $this->toString($row['id']),
                    'requested_role' => $this->toString($row['requested_role']),
                    'status'         => $this->toString($row['status']),
                    'review_notes'   => $reviewNotes === null ? null : $this->toString($reviewNotes),
                    'reviewed_at'    => $this->iso8601($this->toString($row['reviewed_at'])),
                    'permissions'    => $perms,
                    'unread'         => (bool) ( $row['unread'] ?? false ),
                ];
            },
            $rows
        );

        return [
            'items'        => array_values($items),
            'total'        => $total,
            'unread_count' => $unreadCount,
        ];
    }

    /**
     * Settled source-data change-request batches submitted by `$userId` — one item per batch, not
     * per file — plus window-function counts over the FULL filtered set (every settled batch the
     * user has, not just the returned page). Mirrors accessRequestItems()'s COUNT(*) OVER()
     * pattern, but `DISTINCT ON (batch_id)` cannot sit in the same SELECT as that window function
     * and still count the collapsed (one-row-per-batch) rows the count is supposed to be over —
     * `COUNT(*) OVER ()` next to `DISTINCT ON` would count the PRE-collapse rows (one per file),
     * not batches. A CTE runs the `DISTINCT ON` to completion first; the outer SELECT then applies
     * `COUNT(*) OVER ()` and `LIMIT` against that already-collapsed, still-uncapped result, exactly
     * the way accessRequestItems() applies its window functions before its own LIMIT. Without the
     * outer LIMIT, this fetched every settled batch a user has ever had, unbounded — a latent
     * performance problem for prolific contributors independent of the counting bug it caused (see
     * fetchInbox()'s total/unread_count, which used to silently plateau at $limit).
     *
     * @return array{items: list<ChangeRequestItem>, total: int, unread_count: int}
     */
    private function changeRequestItems(string $userId, string $lastSeen, int $limit): array
    {
        $sql  = <<<'SQL'
            WITH batches AS (
                SELECT DISTINCT ON (batch_id)
                    batch_id,
                    resource_type,
                    resource_id,
                    publication_status,
                    pr_number,
                    publication_settled_at,
                    (publication_settled_at > :last_seen::timestamptz) AS unread
                FROM sourcedata_change_requests
                WHERE submitted_by_sub = :uid
                  AND publication_settled_at IS NOT NULL
                ORDER BY batch_id, publication_settled_at DESC
            )
            SELECT
                batch_id,
                resource_type,
                resource_id,
                publication_status,
                pr_number,
                publication_settled_at,
                unread,
                COUNT(*) OVER () AS total,
                COUNT(*) FILTER (WHERE unread) OVER () AS unread_count
            FROM batches
            ORDER BY publication_settled_at DESC
            LIMIT :limit
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':last_seen', $lastSeen);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [
                'items'        => [],
                'total'        => 0,
                'unread_count' => 0,
            ];
        }

        $total       = $this->toInt($rows[0]['total']);
        $unreadCount = $this->toInt($rows[0]['unread_count']);

        $items = array_map(
            function (array $row): array {
                return [
                    'type'               => 'change_request_published',
                    'batch_id'           => $this->toString($row['batch_id']),
                    'resource_type'      => $this->toString($row['resource_type']),
                    'resource_id'        => $this->toString($row['resource_id']),
                    'publication_status' => $this->toString($row['publication_status']),
                    'pr_number'          => $this->toNullableInt($row['pr_number'] ?? null),
                    'settled_at'         => $this->iso8601($this->toString($row['publication_settled_at'])),
                    'unread'             => in_array($row['unread'], [true, 't', 'true', '1', 1], true),
                ];
            },
            $rows
        );

        return [
            'items'        => array_values($items),
            'total'        => $total,
            'unread_count' => $unreadCount,
        ];
    }

    /**
     * The timestamp an InboxItem sorts by, regardless of which shape it is.
     *
     * `$item['settled_at'] ?? $item['reviewed_at']` would work at runtime, but PHPStan L10 flags it
     * (`offsetAccess.notFound`) because neither key exists on both halves of the InboxItem union — a
     * `??` access does not narrow which shape `$item` actually is. Matching on the `type`
     * discriminant does narrow it, so each arm's offset access is provably safe.
     *
     * Both values passed through iso8601() before reaching here, so both are UTC-normalized RFC
     * 3339 strings sharing one `+00:00` offset — see fetchInbox()'s docblock for why that makes
     * strcmp() on the result chronologically correct.
     *
     * @param InboxItem $item
     */
    private static function sortTimestamp(array $item): string
    {
        return match ($item['type']) {
            'change_request_published' => $item['settled_at'],
            'access_request_reviewed'  => $item['reviewed_at'],
        };
    }

    /**
     * Narrow a scalar DB column value to int without violating PHPStan L10's
     * cast-from-mixed rule. With PDO::ATTR_EMULATE_PREPARES=false (the project
     * default — see Connection.php), pdo_pgsql returns COUNT(*) OVER () as a
     * native PHP int, so a single is_int guard suffices.
     */
    private function toInt(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Expected int, got ' . gettype($value));
        }
        return $value;
    }

    /**
     * Narrow a scalar DB column value that may be SQL NULL to ?int without violating PHPStan L10's
     * cast-from-mixed rule. sourcedata_change_requests.pr_number is INTEGER NULL, and with
     * PDO::ATTR_EMULATE_PREPARES=false pdo_pgsql returns it as a native PHP int (or null).
     */
    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        return $this->toInt($value);
    }

    /**
     * Narrow a scalar DB column value to string without violating PHPStan L10's
     * cast-from-mixed rule. All columns we read here are TEXT, VARCHAR, UUID,
     * or TIMESTAMP — all of which pdo_pgsql returns as PHP strings.
     */
    private function toString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Expected string, got ' . gettype($value));
        }
        return $value;
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
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $seen = $stmt->fetchColumn();
        if (!is_string($seen)) {
            throw new \RuntimeException('markSeen: RETURNING did not yield a timestamp string');
        }
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
