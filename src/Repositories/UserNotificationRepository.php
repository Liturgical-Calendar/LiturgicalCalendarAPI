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
     * @return array{
     *     items: list<array{
     *         type: string,
     *         request_id: string,
     *         requested_role: string,
     *         status: string,
     *         review_notes: ?string,
     *         reviewed_at: string,
     *         permissions: list<array{object_type: string, object_id: string, relation: string}>,
     *         unread: bool
     *     }>,
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

        // Statement A: bookmark (or epoch).
        $stmt = $this->db->prepare(
            'SELECT last_notification_seen_at FROM user_notification_state WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $lastSeenRaw = $stmt->fetchColumn();
        $hasBookmark = is_string($lastSeenRaw);
        $lastSeen    = $hasBookmark ? $lastSeenRaw : self::EPOCH;
        $lastSeenIso = $hasBookmark ? $this->iso8601($lastSeen) : self::EPOCH_ISO8601;

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
                'last_seen_at' => $lastSeenIso,
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
            'last_seen_at' => $lastSeenIso,
        ];
    }

    /**
     * Narrow a scalar DB column value to int without violating PHPStan L10's
     * cast-from-mixed rule. Postgres returns COUNT(*) OVER () as a numeric
     * string by default; cast-after-validate is the canonical L10 pattern.
     */
    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Expected int-compatible scalar, got ' . gettype($value));
    }

    /**
     * Narrow a scalar DB column value to string without violating PHPStan L10's
     * cast-from-mixed rule. All columns we read here are either text-typed or
     * UUID/timestamp (which the driver returns as strings).
     */
    private function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new \UnexpectedValueException('Expected string-compatible scalar, got ' . gettype($value));
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
