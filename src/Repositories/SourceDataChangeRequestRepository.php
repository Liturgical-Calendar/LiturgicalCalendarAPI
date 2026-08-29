<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PDO;

/**
 * Persistence for proposed edits to source data.
 *
 * A single API write request produces one BATCH: creating a diocesan calendar
 * writes the calendar and its i18n files, and those must be reviewed together.
 * Approval and rejection therefore act on a batch id, never on a row id.
 *
 * Save-equals-submit is implemented as replace: submitting deletes the
 * submitter's existing `submitted` rows for the same resource in the same
 * transaction. Other submitters' pending rows are untouched.
 */
class SourceDataChangeRequestRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Submit a batch of proposed file changes, superseding the submitter's own
     * pending proposal for this resource.
     *
     * @param list<array{path: string, operation: ChangeOperation, content: ?string}> $files
     * @param array<string, mixed> $metadata
     * @return string The batch id.
     */
    public function submitBatch(
        ChangeResource $resource,
        array $files,
        string $submittedBySub,
        ?string $submittedByName,
        ?string $submittedByEmail,
        bool $submittedByEmailVerified,
        array $metadata = []
    ): string {
        if ($files === []) {
            throw new \InvalidArgumentException('A change request batch must contain at least one file');
        }

        $batchId = $this->newBatchId();

        $this->db->beginTransaction();
        try {
            $supersede = $this->db->prepare(
                'DELETE FROM sourcedata_change_requests
                  WHERE resource_type = :resource_type
                    AND resource_id = :resource_id
                    AND submitted_by_sub = :sub
                    AND review_status = :submitted'
            );
            $supersede->execute([
                'resource_type' => $resource->type,
                'resource_id'   => $resource->id,
                'sub'           => $submittedBySub,
                'submitted'     => ChangeReviewStatus::SUBMITTED->value,
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO sourcedata_change_requests
                    (batch_id, resource_type, resource_id, path, operation, content,
                     submitted_by_sub, submitted_by_name, submitted_by_email, submitted_by_email_verified,
                     review_status, publication_status, metadata)
                 VALUES
                    (:batch_id, :resource_type, :resource_id, :path, :operation, :content,
                     :sub, :name, :email, :email_verified,
                     :review_status, :publication_status, :metadata)'
            );

            foreach ($files as $file) {
                $insert->execute([
                    'batch_id'           => $batchId,
                    'resource_type'      => $resource->type,
                    'resource_id'        => $resource->id,
                    'path'               => $file['path'],
                    'operation'          => $file['operation']->value,
                    'content'            => $file['content'],
                    'sub'                => $submittedBySub,
                    'name'               => $submittedByName,
                    'email'              => $submittedByEmail,
                    'email_verified'     => $submittedByEmailVerified ? 'true' : 'false',
                    'review_status'      => ChangeReviewStatus::SUBMITTED->value,
                    'publication_status' => ChangePublicationStatus::NONE->value,
                    'metadata'           => json_encode($metadata) ?: '{}',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $batchId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sourcedata_change_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every row in a batch, ordered by path.
     *
     * The ordering is lexicographic under the database's collation, which is
     * linguistic rather than byte order (e.g. `en_US.utf8`). Whether a resource's
     * top-level file sorts before its `i18n/` files therefore depends on how the
     * filename compares to the literal string "i18n" under that collation, not on
     * directory depth — do not assume the top-level file is always first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBatch(string $batchId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sourcedata_change_requests WHERE batch_id = :batch_id ORDER BY path ASC'
        );
        $stmt->execute(['batch_id' => $batchId]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $row): array => $this->hydrate($row),
            $rows
        );
    }

    /**
     * Approve every still-submitted row in the batch.
     *
     * @return int Rows transitioned. Zero means the batch was already decided.
     */
    public function approveBatch(string $batchId, string $approvedBySub): int
    {
        return $this->decideBatch($batchId, ChangeReviewStatus::APPROVED, $approvedBySub, null);
    }

    /**
     * @return int Rows transitioned. Zero means the batch was already decided.
     */
    public function rejectBatch(string $batchId, string $rejectedBySub, ?string $reason = null): int
    {
        return $this->decideBatch($batchId, ChangeReviewStatus::REJECTED, $rejectedBySub, $reason);
    }

    /**
     * Withdraw a batch. Only its own submitter may do this, which is enforced in
     * SQL rather than by the caller so a handler bug cannot widen it.
     *
     * @return int Rows transitioned. Zero means it was not theirs, or already decided.
     */
    public function withdrawBatch(string $batchId, string $submittedBySub): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET review_status = :withdrawn,
                    updated_at = NOW()
              WHERE batch_id = :batch_id
                AND submitted_by_sub = :sub
                AND review_status = :submitted'
        );
        $stmt->execute([
            'withdrawn' => ChangeReviewStatus::WITHDRAWN->value,
            'batch_id'  => $batchId,
            'sub'       => $submittedBySub,
            'submitted' => ChangeReviewStatus::SUBMITTED->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * The `review_status = 'submitted'` predicate is what makes a decision
     * single-shot: re-deciding an approved batch matches no rows.
     */
    private function decideBatch(
        string $batchId,
        ChangeReviewStatus $status,
        string $deciderSub,
        ?string $reason
    ): int {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET review_status = :status,
                    approved_by_sub = :decider,
                    approved_at = NOW(),
                    rejected_reason = :reason,
                    updated_at = NOW()
              WHERE batch_id = :batch_id
                AND review_status = :submitted'
        );
        $stmt->execute([
            'status'    => $status->value,
            'decider'   => $deciderSub,
            'reason'    => $reason,
            'batch_id'  => $batchId,
            'submitted' => ChangeReviewStatus::SUBMITTED->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Decode the JSONB and boolean columns, and attach the synthetic `permissions`
     * key that ResourceAdminService::filterByAdminAccess() reads.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function hydrate(array $row): array
    {
        $metadata = is_string($row['metadata'] ?? null)
            ? json_decode($row['metadata'], true)
            : [];

        $row['metadata']                    = is_array($metadata) ? $metadata : [];
        $row['submitted_by_email_verified'] = self::toBool($row['submitted_by_email_verified'] ?? false);
        $row['permissions']                 = [
            [
                'object_type' => self::requireString($row['resource_type'] ?? null, 'resource_type'),
                'object_id'   => self::requireString($row['resource_id'] ?? null, 'resource_id'),
                'relation'    => 'admin',
            ],
        ];

        return $row;
    }

    /**
     * PDO's pgsql driver returns booleans as the strings 't'/'f' (or '1'/'0'
     * depending on the build), so normalise rather than casting.
     */
    private static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 't', 'true', '1', 1], true);
    }

    /**
     * Narrow a NOT NULL VARCHAR column read back through PDO to string, rather than
     * casting mixed and hoping — a genuinely non-string value here means the schema
     * or the query changed underneath this class.
     */
    private static function requireString(mixed $value, string $column): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Expected column %s to be a string, got %s', $column, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * Narrow a COUNT(*) result read back through PDO to int, rather than casting
     * mixed and hoping — mirrors {@see requireString} for the same reason.
     */
    private static function requireInt(mixed $value, string $column): int
    {
        if (!is_int($value) && !is_numeric($value)) {
            throw new \RuntimeException(sprintf('Expected column %s to be numeric, got %s', $column, get_debug_type($value)));
        }

        return (int) $value;
    }

    private function newBatchId(): string
    {
        $stmt = $this->db->query('SELECT gen_random_uuid()');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to generate a change request batch id');
        }

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>> One entry per batch, newest first.
     */
    public function listBySubmitter(
        string $sub,
        ?ChangeReviewStatus $status = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        return $this->listBatches('submitted_by_sub = :sub', ['sub' => $sub], $status, $limit, $offset);
    }

    /**
     * @return array<int, array<string, mixed>> One entry per batch, newest first.
     */
    public function listAll(?ChangeReviewStatus $status = null, int $limit = 50, int $offset = 0): array
    {
        return $this->listBatches('TRUE', [], $status, $limit, $offset);
    }

    public function countBySubmitter(string $sub, ?ChangeReviewStatus $status = null): int
    {
        return $this->countBatches('submitted_by_sub = :sub', ['sub' => $sub], $status);
    }

    public function countAll(?ChangeReviewStatus $status = null): int
    {
        return $this->countBatches('TRUE', [], $status);
    }

    /**
     * Collapse rows into batches.
     *
     * Every row in a batch shares its resource, submitter and statuses — they are
     * written together and transitioned together — so MIN() over those columns is
     * exact, not an approximation.
     *
     * @param array<string, string> $params
     * @return array<int, array<string, mixed>>
     */
    private function listBatches(
        string $predicate,
        array $params,
        ?ChangeReviewStatus $status,
        int $limit,
        int $offset
    ): array {
        if ($status !== null) {
            $predicate              .= ' AND review_status = :review_status';
            $params['review_status'] = $status->value;
        }

        $sql = 'SELECT batch_id,
                       MIN(resource_type)       AS resource_type,
                       MIN(resource_id)         AS resource_id,
                       MIN(review_status)       AS review_status,
                       MIN(publication_status)  AS publication_status,
                       MIN(submitted_by_sub)    AS submitted_by_sub,
                       MIN(submitted_by_name)   AS submitted_by_name,
                       MIN(submitted_by_email)  AS submitted_by_email,
                       MIN(approved_by_sub)     AS approved_by_sub,
                       MIN(created_at)          AS created_at,
                       MAX(updated_at)          AS updated_at,
                       COUNT(*)                 AS file_count,
                       ARRAY_AGG(path ORDER BY path) AS paths
                  FROM sourcedata_change_requests
                 WHERE ' . $predicate . '
              GROUP BY batch_id
              ORDER BY MIN(created_at) DESC
                 LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $row): array => $this->hydrateBatch($row),
            $rows
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function countBatches(string $predicate, array $params, ?ChangeReviewStatus $status): int
    {
        if ($status !== null) {
            $predicate              .= ' AND review_status = :review_status';
            $params['review_status'] = $status->value;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT batch_id) FROM sourcedata_change_requests WHERE ' . $predicate
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateBatch(array $row): array
    {
        $row['file_count']  = self::requireInt($row['file_count'] ?? null, 'file_count');
        $row['paths']       = self::parsePgArray(self::requireString($row['paths'] ?? null, 'paths'));
        $row['permissions'] = [
            [
                'object_type' => self::requireString($row['resource_type'] ?? null, 'resource_type'),
                'object_id'   => self::requireString($row['resource_id'] ?? null, 'resource_id'),
                'relation'    => 'admin',
            ],
        ];

        return $row;
    }

    /**
     * PDO's pgsql driver hands back ARRAY_AGG as the literal `{a,b}` text form,
     * with double quotes around any element containing a comma or brace. Paths
     * contain neither, but parse defensively rather than assuming.
     *
     * @return list<string>
     */
    private static function parsePgArray(string $literal): array
    {
        $inner = trim($literal, '{}');
        if ($inner === '') {
            return [];
        }

        return array_map(
            static fn (?string $element): string => trim($element ?? '', '"'),
            str_getcsv($inner, ',', '"', '\\')
        );
    }
}
