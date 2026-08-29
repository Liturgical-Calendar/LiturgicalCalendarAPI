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
 * # The invariant
 *
 * **At most one SUBMITTED proposal exists per `(path, submitted_by_sub)`.** That is what
 * `idx_scr_unique_pending_path_submitter` enforces — and only that. It says nothing about
 * approved, rejected or withdrawn rows, which are not covered by the partial index and of
 * which any number may share a `(path, submitter)`. The supersede rules below follow from
 * it; the accumulation rules deliberately do not.
 *
 * Save-equals-submit is implemented as replace: submitting deletes the submitter's
 * own still-submitted batches that collide on PATH with an incoming file, in the same
 * transaction. The DELETE keys on path, not on resource, because a `ChangeResource`
 * is not 1:1 with a file — `ChangeResource::decrees()` is a single resource covering
 * the entire decree corpus, and `rite_calendar_test:<rite>` is the scope shared by
 * every rite-level test. Keying on resource would delete a submitted file the incoming
 * request never touched, and would also leave a stale row behind that then collides
 * with the unique index (a PATCH that re-scopes a test changes its `ChangeResource`
 * while its file path stays the same). Whole batches are deleted, never just the
 * colliding row: a batch is reviewed as a unit, so a partial batch must never be left
 * behind.
 *
 * # Accumulation, not replacement
 *
 * Path-keying alone is NOT enough to keep an edit from being lost. Some resources are
 * stored as a single AGGREGATE file: every decree lives in one `decrees.json`, and
 * every decree translation for a locale lives in one `decrees/i18n/<locale>.json`. Two
 * successive decree edits by one submitter therefore always collide on those paths, and
 * the second correctly supersedes the first. What made that lossy was one layer up: a
 * handler rebuilding the aggregate read it from DISK, and in queue mode disk never
 * received the first edit — so decree A vanished when decree B was submitted, silently,
 * behind a `201` and `disposition: submitted`.
 *
 * {@see findUnpublishedContent()} closes that. A handler rebuilding an aggregate starts
 * from the submitter's OWN unpublished content for that path when there is one, and falls
 * back to disk otherwise, so superseding is an accumulation: what that submitter has in
 * flight is always their cumulative proposal.
 *
 * # The accumulation base is "not yet on disk", NOT "submitted"
 *
 * The accumulation base is deliberately WIDER than the supersede DELETE's, and this is the
 * whole point of the two being separate predicates:
 *
 * - the supersede DELETE removes only still-`submitted` batches, because those are the only
 *   ones nobody has ruled on yet. An approved batch is a decision and must survive;
 * - the accumulation base is every row whose content is not yet in the repository —
 *   `review_status IN ('submitted', 'approved') AND publication_status <> 'merged'`.
 *
 * Approval in phase 1 is a single `UPDATE` ({@see decideBatch()}); it writes no files, and
 * there is no publisher until phase 2. An approved batch is therefore exactly as absent
 * from disk as a submitted one. Filtering the base on `submitted` alone meant that the
 * instant a batch was approved it left the base, the next rebuild fell back to still-stale
 * disk, and everything approved-but-unpublished was silently dropped — with the approved
 * rows still sitting in the table holding the contradictory older version (the partial
 * unique index covers only `submitted`, so they do not collide) and `superseded_batch_ids`
 * empty, so nothing warned. That is a first-class path, not a corner: `Router` gates decree
 * DELETE at the `admin` relation and `ChangeRequestReview::administers()` auto-approves on
 * exactly that relation, so every decree DELETE in queue mode is auto-approved.
 *
 * `rejected` and `withdrawn` are excluded for the mirror-image reason: that is work the
 * queue threw away, and accumulating it would resurrect content a reviewer refused.
 * `publication_status = 'merged'` is excluded because merged content IS the repository —
 * a later deploy brings it to disk, and accumulating it on top of disk would be a
 * double-count. (Phase 1 only ever writes `none`; the predicate is written for the phases
 * that will not.)
 *
 * # Ordering is load-bearing
 *
 * Because `idx_scr_unique_pending_path_submitter` covers only `submitted`, several rows can
 * legitimately share a `(path, submitted_by_sub)` once approved rows are in scope — one per
 * approved-but-unpublished batch, plus at most one submitted. Uniqueness can no longer
 * disambiguate, so {@see findUnpublishedContent()} orders explicitly and takes exactly one:
 *
 * - `created_at DESC` — NOT `updated_at`. `created_at` is immutable and is the only column
 *   that records when the CONTENT was written; `updated_at` moves on every later transition
 *   (approval today, `publication_status` in phase 2), which would let an old batch's
 *   publication bump float it above a newer submission;
 * - `review_status = 'submitted'` first, as the tie-break that matters. A submitted row,
 *   when one exists, is always the newest for its `(path, submitter)`: creating it ran the
 *   supersede DELETE, which removed every then-submitted row for that path, so any row that
 *   survives alongside it was decided — and therefore created — before it. Under an exact
 *   `created_at` tie (two transactions starting in the same microsecond) plain
 *   `created_at DESC` picks an arbitrary row, verified against live Postgres;
 * - `id DESC` last, so the result is deterministic even when two decided rows tie exactly.
 *
 * # Scoping
 *
 * The supersede DELETE is scoped to `(path, submitted_by_sub, review_status = 'submitted')`
 * and {@see findUnpublishedContent()} to `(path, submitted_by_sub, not yet on disk)`. Another
 * submitter's work is never deleted and never read, in either. The `submitted_by_sub`
 * predicate is repeated on the outer DELETE as well as the inner SELECT so that the
 * cross-submitter guarantee is structural, rather than a consequence of `batch_id` happening
 * to be unique per submitter.
 *
 * Because a supersede removes a whole batch, it can also remove a submitted path the
 * incoming request never mentioned (a batch that staged both `decrees.json` and
 * `decrees/i18n/de.json`, superseded by a request that stages only the former). That is
 * intentional — batches are indivisible — but it must never be invisible, so
 * {@see submitBatch()} returns the ids of every batch it superseded and the write
 * response carries them.
 */
class SourceDataChangeRequestRepository
{
    /**
     * SQL for "this row's content is not yet in the repository".
     *
     * Both accumulation reads — {@see findUnpublishedContent()} and
     * {@see findUnpublishedPathsUnder()} — share it verbatim so they cannot drift apart:
     * widening one without the other regresses either the content half or the enumeration
     * half of the same defect. See the class docblock for why this is deliberately wider
     * than the supersede DELETE's `review_status = 'submitted'`.
     */
    private const UNPUBLISHED_PREDICATE = 'review_status IN (:submitted, :approved)
                AND publication_status <> :merged';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Submit a batch of proposed file changes, superseding any of the submitter's
     * own still-SUBMITTED batches that collide on path with one of these files.
     *
     * Deliberately narrower than the accumulation base {@see findUnpublishedContent()}
     * reads: an approved batch is a decision, so it is never deleted here — it is carried
     * forward as content instead. See the class docblock.
     *
     * @param list<array{path: string, operation: ChangeOperation, content: ?string}> $files
     * @param array<string, mixed> $metadata
     * @return array{batch_id: string, superseded_batch_ids: list<string>} The new batch id, plus
     *         the ids of the submitter's own still-submitted batches this submission replaced. A
     *         superseded batch may have contained paths this submission never mentions, so the
     *         caller must surface these rather than let the replacement happen invisibly.
     */
    public function submitBatch(
        ChangeResource $resource,
        array $files,
        string $submittedBySub,
        ?string $submittedByName,
        ?string $submittedByEmail,
        bool $submittedByEmailVerified,
        array $metadata = []
    ): array {
        if ($files === []) {
            throw new \InvalidArgumentException('A change request batch must contain at least one file');
        }

        $batchId = $this->newBatchId();

        $this->db->beginTransaction();
        try {
            // Supersede whole batches, not individual rows: a batch is approved and
            // rejected as a unit, so leaving a partial batch behind after this DELETE
            // would make it incoherent. Keyed on path (matching
            // idx_scr_unique_pending_path_submitter) rather than on resource — see the
            // class docblock for why a resource match alone is not equivalent.
            $pathParams       = [];
            $pathPlaceholders = [];
            foreach (array_values($files) as $i => $file) {
                $key                = "path_{$i}";
                $pathPlaceholders[] = ":{$key}";
                $pathParams[$key]   = $file['path'];
            }

            // `submitted_by_sub` is repeated on the OUTER delete, not only on the inner
            // SELECT: batch ids are unique per submitter today, so the inner predicate
            // alone happens to be sufficient, but the cross-submitter guarantee should be
            // structural rather than a consequence of that. `review_status` is deliberately
            // NOT repeated out here — restricting the outer delete to still-submitted rows
            // would leave a partial batch behind, which whole-batch deletion exists to
            // prevent. RETURNING makes the supersede visible to the caller: a superseded
            // batch may have held paths this submission never mentions.
            $supersede = $this->db->prepare(
                'DELETE FROM sourcedata_change_requests
                  WHERE submitted_by_sub = :sub
                    AND batch_id IN (
                      SELECT batch_id FROM sourcedata_change_requests
                       WHERE submitted_by_sub = :sub
                         AND review_status = :submitted
                         AND path IN (' . implode(', ', $pathPlaceholders) . ')
                    )
              RETURNING batch_id'
            );
            $supersede->execute([
                'sub'       => $submittedBySub,
                'submitted' => ChangeReviewStatus::SUBMITTED->value,
                ...$pathParams,
            ]);

            $supersededBatchIds = [];
            foreach ($supersede->fetchAll(PDO::FETCH_COLUMN) as $supersededId) {
                $supersededBatchIds[] = self::requireString($supersededId, 'batch_id');
            }
            $supersededBatchIds = array_values(array_unique($supersededBatchIds));

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

        return [
            'batch_id'             => $batchId,
            'superseded_batch_ids' => $supersededBatchIds,
        ];
    }

    /**
     * The bound values {@see self::UNPUBLISHED_PREDICATE} expects.
     *
     * @return array{submitted: string, approved: string, merged: string}
     */
    private static function unpublishedParams(): array
    {
        return [
            'submitted' => ChangeReviewStatus::SUBMITTED->value,
            'approved'  => ChangeReviewStatus::APPROVED->value,
            'merged'    => ChangePublicationStatus::MERGED->value,
        ];
    }

    /**
     * The submitter's own not-yet-published content for one path, or null when they have none.
     *
     * This is what makes supersede-by-path an accumulation rather than a replacement:
     * a handler rebuilding an aggregate file (`decrees.json`, a `decrees/i18n/<locale>.json`)
     * must start from what it already has in flight for that path, because in queue mode
     * neither a submitted nor an approved proposal has reached disk. See the class docblock.
     *
     * Scoped to `$sub`: another submitter's work is never visible here. Scoped to
     * {@see self::UNPUBLISHED_PREDICATE}: a rejected, withdrawn or already-merged row is
     * never visible either.
     *
     * Several rows can legitimately match — the partial unique index covers only
     * `submitted` — so this orders explicitly and takes exactly one. The ordering is
     * load-bearing and is justified in full in the class docblock.
     *
     * A DELETE row carries no content and therefore reads back as null, the same as no row
     * at all — which would send a caller to disk for a file that is proposed for deletion.
     * No aggregate file is ever staged for deletion (they are rewritten in place, never
     * removed), so this is not reachable today; a future caller that stages aggregate
     * deletions would need to distinguish the two cases explicitly.
     */
    public function findUnpublishedContent(string $path, string $sub): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT content
               FROM sourcedata_change_requests
              WHERE path = :path
                AND submitted_by_sub = :sub
                AND ' . self::UNPUBLISHED_PREDICATE . '
              ORDER BY ( review_status = :submitted ) DESC, created_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'path' => $path,
            'sub'  => $sub,
            ...self::unpublishedParams(),
        ]);

        $content = $stmt->fetchColumn();

        return is_string($content) ? $content : null;
    }

    /**
     * Every path the submitter has unpublished beneath `$pathPrefix`.
     *
     * The companion to {@see findUnpublishedContent()} for the case where a handler must
     * first work out WHICH aggregate files exist before rebuilding them — decree i18n
     * sidecars are enumerated by listing the locale folder, and a locale file that exists
     * only in this submitter's queued work would otherwise be invisible to that listing and
     * dropped on the next submission. It has to be widened in step with
     * {@see findUnpublishedContent()}, or a sidecar created by an approved-but-unpublished
     * batch becomes invisible again and is swept away.
     *
     * DISTINCT because, unlike the submitted-only case, one path can now have several
     * matching rows (one approved-but-unpublished batch each, plus at most one submitted).
     * Callers get each path once and then ask {@see findUnpublishedContent()} for its
     * newest content.
     *
     * Matches on a literal prefix rather than LIKE so that `_` and `%`, both of which occur
     * in real paths, need no escaping.
     *
     * @return list<string> Repository-relative paths, ascending.
     */
    public function findUnpublishedPathsUnder(string $pathPrefix, string $sub): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT path
               FROM sourcedata_change_requests
              WHERE submitted_by_sub = :sub
                AND ' . self::UNPUBLISHED_PREDICATE . '
                AND LEFT(path, :prefix_length) = :prefix
              ORDER BY path ASC'
        );
        $stmt->bindValue('sub', $sub);
        foreach (self::unpublishedParams() as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('prefix_length', strlen($pathPrefix), PDO::PARAM_INT);
        $stmt->bindValue('prefix', $pathPrefix);
        $stmt->execute();

        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $paths[] = self::requireString($path, 'path');
        }

        return $paths;
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
     *
     * Note what this does NOT do: it writes no files and leaves `publication_status` at
     * `none`. In phase 1 there is no publisher, so an approved batch is exactly as absent
     * from disk as a submitted one — which is why {@see findUnpublishedContent()} must keep
     * reading it. `updated_at` moves here, and will move again when phase 2 sets
     * `publication_status`, which is why that column is NOT what the accumulation base
     * orders by.
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
     * Ordering contract: newest first by `created_at`, with `batch_id` DESC as a
     * deterministic tie-breaker. `created_at` is the transaction timestamp, so two
     * batches submitted close together (or genuinely concurrently) can share the same
     * value; without a tie-breaker, Postgres is free to order tied rows differently
     * between calls, which would let a batch appear twice or vanish across a paginated
     * `LIMIT`/`OFFSET` sequence. `batch_id` is unique per batch, so appending it fully
     * resolves any tie and makes the page sequence stable.
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
              ORDER BY MIN(created_at) DESC, batch_id DESC
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
