<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use PDO;

/**
 * PDO repository for the openfga_outbox table.
 *
 * Sole writer of outbox rows. Hot path is insertBatch (called inside the
 * handler's tx with the business write) and pickupPending (the consumer
 * and backstop both call this).
 */
final class OutboxRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert a batch of outbox rows, idempotent on idempotency_key.
     *
     * Returns the row IDs in the same order as the input payload.
     *
     * @param list<array{
     *     operation: OutboxOperation,
     *     fga_user: string,
     *     fga_relation: string,
     *     fga_object: string,
     *     idempotency_key: string,
     *     metadata: array<string, mixed>
     * }> $rows
     * @return list<int>
     */
    public function insertBatch(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $ids = [];

        $insert = $this->db->prepare(<<<'SQL'
            INSERT INTO openfga_outbox (operation, fga_user, fga_relation, fga_object, metadata)
            VALUES (:operation, :fga_user, :fga_relation, :fga_object, :metadata::jsonb)
            ON CONFLICT ((metadata->>'idempotency_key')) DO NOTHING
            RETURNING id
        SQL);

        $select = $this->db->prepare(<<<'SQL'
            SELECT id FROM openfga_outbox WHERE metadata->>'idempotency_key' = :key
        SQL);

        foreach ($rows as $row) {
            // The idempotency_key must live inside the metadata JSONB so the
            // expression UNIQUE index catches conflicts.
            $metadata                    = $row['metadata'];
            $metadata['idempotency_key'] = $row['idempotency_key'];

            $insert->execute([
                ':operation'    => $row['operation']->value,
                ':fga_user'     => $row['fga_user'],
                ':fga_relation' => $row['fga_relation'],
                ':fga_object'   => $row['fga_object'],
                ':metadata'     => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);

            $insertedId = $insert->fetchColumn();
            if ($insertedId !== false) {
                $ids[] = (int) $insertedId;
                continue;
            }

            // Conflict path — the row already exists. Look up its ID.
            $select->execute([':key' => $row['idempotency_key']]);
            $existingId = $select->fetchColumn();
            if ($existingId === false) {
                throw new \RuntimeException(sprintf(
                    'OutboxRepository::insertBatch: conflict on key %s but row not found',
                    $row['idempotency_key']
                ));
            }
            $ids[] = (int) $existingId;
        }

        return $ids;
    }

    public function getById(int $id): ?OutboxRow
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, operation, fga_user, fga_relation, fga_object,
                   status, attempts, next_attempt_at, last_error, last_error_code,
                   metadata, created_at, completed_at
            FROM openfga_outbox
            WHERE id = :id
        SQL);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        /** @var array<string, mixed> $row */
        return $this->hydrate($row);
    }

    public function markSucceeded(int $id): void
    {
        // Guard against terminal-status downgrades: only mark succeeded if currently
        // in a non-terminal state. Re-applying succeeded is a no-op (completed_at preserved).
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'succeeded',
                completed_at = COALESCE(completed_at, NOW())
            WHERE id = :id AND status IN ('pending', 'retrying', 'succeeded')
        SQL);
        $stmt->execute([':id' => $id]);
    }

    public function markRetryable(
        int $id,
        int $attempts,
        \DateTimeImmutable $nextAttemptAt,
        string $lastError,
        ?string $lastErrorCode,
    ): void {
        // Only transition out of pending/retrying. A failed_terminal row must
        // stay terminal — admin retry has its own path (resetForRetry).
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'retrying',
                attempts = :attempts,
                next_attempt_at = :next_attempt_at,
                last_error = :last_error,
                last_error_code = :last_error_code
            WHERE id = :id AND status IN ('pending', 'retrying')
        SQL);
        $stmt->execute([
            ':id'              => $id,
            ':attempts'        => $attempts,
            ':next_attempt_at' => $nextAttemptAt->format('Y-m-d H:i:s.uP'),
            ':last_error'      => $lastError,
            ':last_error_code' => $lastErrorCode,
        ]);
    }

    public function markFailedTerminal(int $id, string $lastError, ?string $lastErrorCode): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'failed_terminal',
                last_error = :last_error,
                last_error_code = :last_error_code,
                completed_at = NOW()
            WHERE id = :id AND status IN ('pending', 'retrying')
        SQL);
        $stmt->execute([
            ':id'              => $id,
            ':last_error'      => $lastError,
            ':last_error_code' => $lastErrorCode,
        ]);
    }

    /**
     * Pick up rows ready for the consumer / backstop to process.
     *
     * Uses FOR UPDATE SKIP LOCKED so concurrent runners don't collide:
     * each runner gets a distinct slice of the eligible rows. Caller is
     * responsible for COMMIT/ROLLBACK of the surrounding transaction
     * (the lock is held until the runner finishes processing or rolls
     * back).
     *
     * @return list<OutboxRow>
     */
    public function pickupPending(int $limit, \DateTimeImmutable $now): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, operation, fga_user, fga_relation, fga_object,
                   status, attempts, next_attempt_at, last_error, last_error_code,
                   metadata, created_at, completed_at
            FROM openfga_outbox
            WHERE status IN ('pending', 'retrying')
              AND next_attempt_at <= :now
            ORDER BY next_attempt_at ASC, id ASC
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        SQL);
        $stmt->bindValue(':now', $now->format('Y-m-d H:i:s.uP'), PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        while (( $r = $stmt->fetch(PDO::FETCH_ASSOC) ) !== false) {
            /** @var array<string, mixed> $r */
            $rows[] = $this->hydrate($r);
        }
        return $rows;
    }

    /**
     * Reset a failed_terminal row back to pending so the consumer/backstop
     * will retry it. Only failed_terminal rows are eligible — admin retry on
     * a pending/retrying row is a 409 from the handler.
     *
     * Returns true if a row was reset; false if the row was not in
     * failed_terminal state.
     */
    public function resetForRetry(int $id): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'pending',
                attempts = 0,
                last_error = NULL,
                last_error_code = NULL,
                completed_at = NULL,
                next_attempt_at = NOW()
            WHERE id = :id AND status = 'failed_terminal'
        SQL);
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * List outbox rows with optional filters and offset pagination.
     *
     * @return array{rows: list<OutboxRow>, total: int}
     */
    public function list(
        ?string $status,
        ?string $accessRequestId,
        int $limit,
        int $offset,
    ): array {
        // Count query.
        // We use two parameters for the status filter to avoid PostgreSQL's
        // "could not determine data type of parameter" ambiguity when the same
        // placeholder appears in both `IS NULL` and a cast expression.
        $countStmt = $this->db->prepare(<<<'SQL'
            SELECT COUNT(*)::int AS total
            FROM openfga_outbox
            WHERE (:status_null OR status = :status_val::outbox_status)
              AND (:access_request_id_null OR metadata->>'access_request_id' = :access_request_id_val)
        SQL);
        $countStmt->bindValue(':status_null', $status === null, PDO::PARAM_BOOL);
        $countStmt->bindValue(':status_val', $status ?? '', PDO::PARAM_STR);
        $countStmt->bindValue(':access_request_id_null', $accessRequestId === null, PDO::PARAM_BOOL);
        $countStmt->bindValue(':access_request_id_val', $accessRequestId ?? '', PDO::PARAM_STR);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // Data query — same split-param trick.
        $dataStmt = $this->db->prepare(<<<'SQL'
            SELECT id, operation, fga_user, fga_relation, fga_object,
                   status, attempts, next_attempt_at, last_error, last_error_code,
                   metadata, created_at, completed_at
            FROM openfga_outbox
            WHERE (:status_null OR status = :status_val::outbox_status)
              AND (:access_request_id_null OR metadata->>'access_request_id' = :access_request_id_val)
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        SQL);
        $dataStmt->bindValue(':status_null', $status === null, PDO::PARAM_BOOL);
        $dataStmt->bindValue(':status_val', $status ?? '', PDO::PARAM_STR);
        $dataStmt->bindValue(':access_request_id_null', $accessRequestId === null, PDO::PARAM_BOOL);
        $dataStmt->bindValue(':access_request_id_val', $accessRequestId ?? '', PDO::PARAM_STR);
        $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        $rows = [];
        while (( $r = $dataStmt->fetch(PDO::FETCH_ASSOC) ) !== false) {
            /** @var array<string, mixed> $r */
            $rows[] = $this->hydrate($r);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Return the age in seconds of the oldest pending/retrying row, or 0 when none.
     */
    public function oldestPendingAgeSeconds(): int
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT COALESCE(EXTRACT(EPOCH FROM (NOW() - MIN(created_at)))::int, 0)
            FROM openfga_outbox
            WHERE status IN ('pending', 'retrying')
        SQL);
        if ($stmt === false) {
            return 0;
        }
        $val = $stmt->fetchColumn();
        return is_numeric($val) ? (int) $val : 0;
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT status::text AS status, COUNT(*)::int AS n
            FROM openfga_outbox
            GROUP BY status
        SQL);
        $out  = [];
        if ($stmt !== false) {
            /** @var array{status: string, n: int} $r */
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['status']] = $r['n'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxRow
    {
        $metadataJson = is_string($row['metadata']) ? $row['metadata'] : '{}';
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);

        /** @var int|string $id */
        $id = $row['id'];
        /** @var string $operation */
        $operation = $row['operation'];
        /** @var string $fgaUser */
        $fgaUser = $row['fga_user'];
        /** @var string $fgaRelation */
        $fgaRelation = $row['fga_relation'];
        /** @var string $fgaObject */
        $fgaObject = $row['fga_object'];
        /** @var string $status */
        $status = $row['status'];
        /** @var int|string $attempts */
        $attempts = $row['attempts'];
        /** @var string $nextAttemptAt */
        $nextAttemptAt = $row['next_attempt_at'];
        /** @var string|null $lastError */
        $lastError = $row['last_error'];
        /** @var string|null $lastErrorCode */
        $lastErrorCode = $row['last_error_code'];
        /** @var string $createdAt */
        $createdAt = $row['created_at'];
        /** @var string|null $completedAt */
        $completedAt = $row['completed_at'];

        return new OutboxRow(
            id: (int) $id,
            operation: OutboxOperation::from($operation),
            fgaUser: $fgaUser,
            fgaRelation: $fgaRelation,
            fgaObject: $fgaObject,
            status: OutboxStatus::from($status),
            attempts: (int) $attempts,
            nextAttemptAt: new \DateTimeImmutable($nextAttemptAt),
            lastError: $lastError,
            lastErrorCode: $lastErrorCode,
            metadata: $metadata,
            createdAt: new \DateTimeImmutable($createdAt),
            completedAt: $completedAt !== null ? new \DateTimeImmutable($completedAt) : null,
        );
    }
}
