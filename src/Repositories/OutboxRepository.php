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
        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxRow
    {
        $metadataJson = is_string($row['metadata']) ? $row['metadata'] : '{}';
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);

        return new OutboxRow(
            id: (int) $row['id'],
            operation: OutboxOperation::from((string) $row['operation']),
            fgaUser: (string) $row['fga_user'],
            fgaRelation: (string) $row['fga_relation'],
            fgaObject: (string) $row['fga_object'],
            status: OutboxStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            nextAttemptAt: new \DateTimeImmutable((string) $row['next_attempt_at']),
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            lastErrorCode: $row['last_error_code'] !== null ? (string) $row['last_error_code'] : null,
            metadata: $metadata,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            completedAt: $row['completed_at'] !== null ? new \DateTimeImmutable((string) $row['completed_at']) : null,
        );
    }
}
