<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Enum\ClaimReleaseOutcome;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\PublishClaim;
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
 * Save-equals-submit is implemented as replace: submitting clears the submitter's
 * own still-submitted batches that collide on PATH with an incoming file, in the same
 * transaction. The supersede keys on path, not on resource, because a `ChangeResource`
 * is not 1:1 with a file — `ChangeResource::decrees()` is a single resource covering
 * the entire decree corpus, and `rite_calendar_test:<rite>` is the scope shared by
 * every rite-level test. Keying on resource would delete a submitted file the incoming
 * request never touched, and would also leave a stale row behind that then collides
 * with the unique index (a PATCH that re-scopes a test changes its `ChangeResource`
 * while its file path stays the same). No colliding batch is left partially behind: a
 * batch is reviewed as a unit, so every one of its rows either goes or moves.
 *
 * # A supersede carries forward what it does not replace
 *
 * A colliding batch is cleared, but it is not simply discarded. Only the rows for paths
 * the incoming request RESTAGES are superseded — those it genuinely replaces. Every other
 * row that batch held is re-parented onto the new batch id, keeping its content, its path
 * and its `created_at`.
 *
 * Deleting them along with the rest of the batch was silent data loss, and reachable
 * through the ordinary API: {@see \LiturgicalCalendar\Api\Models\Decrees\DecreeWritePayloadGuard}
 * makes `readings` optional on PATCH, so correcting a decree's translation stages
 * `decrees.json` and the i18n sidecars but not `decrees/lectionary/<locale>.json` — and
 * the readings of the decree being corrected went with the batch that held them. A
 * `setProperty`/`grade` write is worse: the guard FORBIDS both `i18n` and `readings`
 * there, so it stages the aggregate alone and swept both sidecars, leaving a reviewer
 * looking at a `decrees.json` entry for an event with no name and no readings — internally
 * inconsistent, and approving it published exactly that. The accumulation below cannot
 * help, because a handler only ever rebuilds the paths it restages. The identical sequence
 * in DISK mode keeps both sidecars, so this was a queue-mode-only divergence, and one that
 * only touched `submitted` work — the non-admin editor, the primary user of the queue.
 *
 * Re-parenting keeps the unit reviewable: there is still exactly one batch, approved,
 * rejected or withdrawn as a whole, and it is now genuinely the submitter's cumulative
 * proposal rather than only the part of it they last touched. It cannot violate
 * `idx_scr_unique_pending_path_submitter` either — the moved rows keep their path and
 * their `submitted` status, and the index already guarantees that no two of them, and
 * none of them and the incoming files, share a path.
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
 * # A merged row's own ancestors must also drop out
 *
 * `publication_status <> 'merged'` alone is not enough once a batch actually reaches
 * `merged`: accumulation means batch B's content already contains the older batch A's, so
 * publishing B also publishes A's content — but A itself stays `approved`/`none` forever,
 * because nothing ever marks an ancestor merged (see {@see NOT_SUPERSEDED_BY_PUBLISHED} for
 * why). Without a further check, A would still match {@see UNPUBLISHED_PREDICATE} and, being
 * older than nothing, would become the newest surviving row for its `(path, submitter)` the
 * instant B is excluded by its own `merged` status — silently reverting everything B added on
 * the next accumulation. {@see NOT_SUPERSEDED_BY_PUBLISHED} closes this by excluding any row
 * older than the newest `merged` row for the same `(path, submitter)`, on top of
 * `UNPUBLISHED_PREDICATE` rather than folded into it, so the two ideas — "not yet published"
 * and "not superseded by something that was" — stay independently readable.
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
 * - `review_status = 'submitted'` first, and this is the key that does the real work now
 *   that a row can be carried forward. A carried-forward row keeps its original
 *   `created_at`, so a `submitted` row for a path can be OLDER than the batch that holds
 *   it — `created_at DESC` alone would no longer be enough. It is still always the newest
 *   unpublished row for its `(path, submitter)`, though, and for a reason the carry-forward
 *   does not disturb: a review decision is one-way, so a row that is `submitted` now has
 *   been `submitted` since it was created, and `idx_scr_unique_pending_path_submitter`
 *   therefore forbids any other row for that `(path, submitter)` from having been created
 *   during its whole lifetime. Every approved-but-unpublished sibling is strictly older.
 *   The one thing that could have created a newer row for that path — an incoming batch
 *   restaging it — supersedes this row rather than joining it. Under an exact `created_at`
 *   tie (two transactions starting in the same microsecond) plain `created_at DESC` picks
 *   an arbitrary row, verified against live Postgres;
 * - `id DESC` last, so the result is deterministic even when two decided rows tie exactly.
 *
 * # Scoping
 *
 * The supersede is scoped to `(path, submitted_by_sub, review_status = 'submitted')` and
 * {@see findUnpublishedContent()} to `(path, submitted_by_sub, not yet on disk)`. Another
 * submitter's work is never deleted, never re-parented and never read, in any of them. The
 * `submitted_by_sub` predicate is repeated on the carry-forward UPDATE and the DELETE as
 * well as on the SELECT that finds the colliding batches, so that the cross-submitter
 * guarantee is structural rather than a consequence of `batch_id` happening to be unique
 * per submitter.
 *
 * A superseded batch id stops existing — its rows are either replaced or re-parented onto
 * the new batch — so a client tracking it would find it gone from `GET /auth/change-requests`
 * with no explanation. {@see submitBatch()} therefore returns the ids of every batch it
 * superseded and the write response carries them. They name a batch that was folded into
 * this one, NOT work that was thrown away: anything the incoming request did not restage
 * is in the new batch.
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
     *
     * # Why `closed` is admitted here, and what actually excludes it
     *
     * `chk_scr_publication_status` also allows `closed`, which phase 3 writes when a pull request
     * is closed unmerged. This predicate excludes only `merged`, so a `closed` row is ADMITTED by
     * the publication half — and that is correct, not an oversight. The publication axis answers
     * "is this row's content in the repository?", and for a pull request closed unmerged the
     * answer is no.
     *
     * What excludes it is the review half: phase 3 writes `review_status = 'rejected'` alongside
     * `closed`, and a rejected batch is no longer a proposal whatever became of its pull request.
     * Two axes answering two different questions — the parent design's own reason for keeping them
     * as two columns rather than one flattened enum.
     *
     * Decided in `docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md` and
     * pinned by `SourceDataChangeRequestRepositoryTest::testClosedAndRejectedRowIsExcludedFromTheAccumulationBase()`
     * and its deliberate mirror image `…ClosedButStillApprovedRowRemainsInTheAccumulationBase()`.
     * Do NOT "fix" this by adding `closed` to the exclusion: that would drop an editor's un-merged
     * work from their next submission on the strength of content that never reached the repository.
     */
    private const UNPUBLISHED_PREDICATE = 'review_status IN (:submitted, :approved)
                AND publication_status <> :merged';

    /**
     * Rows superseded by published content are excluded by AGE, not by rewriting their status.
     *
     * Accumulation makes each batch the submitter's cumulative proposal, so publishing batch B also
     * publishes the content of the older batch A that B accumulated onto. A is then stale. Marking A
     * `merged` would say it was published, which is false: the publisher selects approved rows that are
     * not yet merged, so a broken containment assumption would make it skip A and lose its content
     * silently. Excluding by age asserts nothing — A stays approved, visible and publishable — and the
     * worst case degrades from lost data to a suboptimal rebuild base.
     *
     * `>=`, not `>`: on an exact `created_at` tie the row must NOT be excluded. `created_at` is a
     * transaction timestamp, and this class's own ORDER BY comment already documents exact-microsecond
     * ties between independent transactions as a real, reproducible Postgres phenomenon — so a tie
     * between the merged floor and some OTHER, unrelated row for the same `(path, submitter)` is
     * reachable, and excluding it on `>` alone was verified (fix-round-1) to reproduce this exact
     * defect for that row: a live, never-superseded batch dropped from the accumulation base and
     * silently lost on the next edit, precisely the failure this predicate exists to prevent.
     *
     * A row-value tiebreak against `id`, matching the ORDER BY's `id DESC`, was tried and rejected:
     * `id` is `gen_random_uuid()`, carrying no temporal information, so `(created_at, id) > (floor,
     * floor_id)` does not resolve a genuine tie correctly — it answers by the luck of which random
     * UUID sorts higher, verified live to flip the excluded side depending only on that. It replaces
     * one deterministic bug with a coin flip on both edges rather than closing either. `id DESC` is a
     * safe tiebreak in the ORDER BY because every candidate there is equally valid "newest" content
     * and any deterministic choice is acceptable; it is not a safe tiebreak here because this
     * predicate has one correct answer per row and id carries no signal toward it.
     *
     * The mirror-image risk `>=` accepts — a true ancestor A tying EXACTLY with the descendant batch
     * that accumulated it and was later merged — requires two sequential, independently-triggered
     * transactions (A's original submission, then, after a human/API approval step, the later
     * resubmission that becomes the merged batch) to land on the identical Postgres microsecond.
     * Nothing in this class's write path can produce that; it is reachable only by forcing `created_at`
     * directly, the same way the ORDER BY's own tie tests do. Given a choice between a demonstrated,
     * reachable bug and a not-reachable one, and given the row-id alternative does not reliably close
     * either, `>=` is the correct edge.
     *
     * The floor is `publication_status = 'merged'` ALONE, never `IN ('merged','closed')`. A closed
     * batch published nothing, so letting it set the floor would exclude older rows on the strength
     * of content that is not in the repository. Pinned by `testClosedRowIsNotASupersessionFloor()`.
     */
    private const NOT_SUPERSEDED_BY_PUBLISHED = 'created_at >= COALESCE((
                    SELECT MAX(m.created_at)
                      FROM sourcedata_change_requests m
                     WHERE m.path = sourcedata_change_requests.path
                       AND m.submitted_by_sub = sourcedata_change_requests.submitted_by_sub
                       AND m.publication_status = :merged_floor
                ), TIMESTAMPTZ \'-infinity\')';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Submit a batch of proposed file changes, superseding any of the submitter's
     * own still-SUBMITTED batches that collide on path with one of these files.
     *
     * A colliding batch is cleared but not discarded: only its rows for the paths this
     * request restages are deleted, and the rest are re-parented onto the new batch, which
     * is therefore the submitter's cumulative proposal. See the class docblock.
     *
     * Deliberately narrower than the accumulation base {@see findUnpublishedContent()}
     * reads: an approved batch is a decision, so it is never touched here — it is carried
     * forward as content instead.
     *
     * @param list<array{path: string, operation: ChangeOperation, content: ?string}> $files
     * @param array<string, mixed> $metadata
     * @return array{batch_id: string, superseded_batch_ids: list<string>} The new batch id, plus
     *         the ids of the submitter's own still-submitted batches this submission folded into
     *         it. Those ids no longer exist, so the caller must surface them rather than let a
     *         batch the client was tracking vanish from its listing without explanation. They do
     *         NOT mean work was thrown away: everything they held that this request did not
     *         restage is in the new batch.
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
            // Supersede every colliding batch entirely — a batch is approved and rejected
            // as a unit, so none may be left half-superseded — but "entirely" means each of
            // its rows is either replaced or moved, not that all of them are deleted. Keyed
            // on path (matching idx_scr_unique_pending_path_submitter) rather than on
            // resource: see the class docblock for why a resource match alone is not
            // equivalent.
            $pathParams       = [];
            $pathPlaceholders = [];
            foreach (array_values($files) as $i => $file) {
                $key                = "path_{$i}";
                $pathPlaceholders[] = ":{$key}";
                $pathParams[$key]   = $file['path'];
            }
            $pathList = implode(', ', $pathPlaceholders);

            // Which of the submitter's own still-submitted batches collide. Resolved as its
            // own SELECT rather than as a subquery inside the DELETE because the ids are
            // needed twice below (to re-parent, then to delete) and are reported back to the
            // caller. `review_status = :submitted` here is what keeps an approved batch
            // untouchable: an approval is a decision, and is carried forward as CONTENT by
            // findUnpublishedContent() instead.
            $colliding = $this->db->prepare(
                'SELECT DISTINCT batch_id
                   FROM sourcedata_change_requests
                  WHERE submitted_by_sub = :sub
                    AND review_status = :submitted
                    AND path IN (' . $pathList . ')'
            );
            $colliding->execute([
                'sub'       => $submittedBySub,
                'submitted' => ChangeReviewStatus::SUBMITTED->value,
                ...$pathParams,
            ]);

            $supersededBatchIds = [];
            foreach ($colliding->fetchAll(PDO::FETCH_COLUMN) as $collidingId) {
                $supersededBatchIds[] = self::requireString($collidingId, 'batch_id');
            }
            $supersededBatchIds = array_values(array_unique($supersededBatchIds));

            if ($supersededBatchIds !== []) {
                $batchParams       = [];
                $batchPlaceholders = [];
                foreach ($supersededBatchIds as $i => $supersededId) {
                    $key                 = "batch_{$i}";
                    $batchPlaceholders[] = ":{$key}";
                    $batchParams[$key]   = $supersededId;
                }
                $batchList = implode(', ', $batchPlaceholders);

                // Carry forward first: re-parent every row of a colliding batch whose path
                // this submission does NOT restage. Those rows are not superseded by
                // anything — nothing in this request replaces them, and no accumulation read
                // will ever reach them, because a handler only rebuilds the paths it
                // restages. Deleting them with the rest of the batch is what silently lost
                // a decree's readings the moment its translation was corrected (readings are
                // optional on PATCH) and lost both sidecars on a setProperty/grade write,
                // which stages the aggregate alone.
                //
                // `created_at` is deliberately untouched: it records when the CONTENT was
                // written, and it is what findUnpublishedContent() orders by. Only
                // `updated_at` moves, exactly as it does for any other row transition.
                //
                // Re-parenting cannot violate idx_scr_unique_pending_path_submitter: the
                // rows keep their path and their `submitted` status, and the index already
                // guarantees the paths involved are distinct — across colliding batches
                // (two submitted rows could not share a path), and between them and this
                // request (these are precisely the paths it does not restage).
                //
                // A moved row also keeps its own resource_type/resource_id, and that is
                // only safe while every row of a batch shares one resource — listBatches()
                // collapses them with MIN() and hydrateBatch() derives the `permissions`
                // tuple from that. It holds today because a batch that has any non-restaged
                // path to carry is a decree or a regional-data batch (TestsHandler stages
                // exactly one file), and no other resource can stage those paths: the
                // decree corpus is `general_roman_calendar:decrees` alone, and two calendars
                // never share a file. A handler that broke that would need this UPDATE
                // revisited, not just its own staging.
                $carryForward = $this->db->prepare(
                    'UPDATE sourcedata_change_requests
                        SET batch_id = :new_batch_id,
                            updated_at = NOW()
                      WHERE submitted_by_sub = :sub
                        AND batch_id IN (' . $batchList . ')
                        AND path NOT IN (' . $pathList . ')'
                );
                $carryForward->execute([
                    'new_batch_id' => $batchId,
                    'sub'          => $submittedBySub,
                    ...$batchParams,
                    ...$pathParams,
                ]);

                // Then delete what is genuinely superseded: whatever still bears a colliding
                // batch id, which after the re-parent is exactly the rows this request
                // restages. `submitted_by_sub` is repeated here, not only on the SELECT
                // above: batch ids are unique per submitter today, so that predicate alone
                // happens to be sufficient, but the cross-submitter guarantee should be
                // structural rather than a consequence of that.
                $supersede = $this->db->prepare(
                    'DELETE FROM sourcedata_change_requests
                      WHERE submitted_by_sub = :sub
                        AND batch_id IN (' . $batchList . ')'
                );
                $supersede->execute([
                    'sub' => $submittedBySub,
                    ...$batchParams,
                ]);
            }

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
     * The bound values {@see self::UNPUBLISHED_PREDICATE} and
     * {@see self::NOT_SUPERSEDED_BY_PUBLISHED} expect.
     *
     * `merged` and `merged_floor` are bound separately, even though they carry the same
     * value, so the two clauses stay independently readable rather than coupled through a
     * shared placeholder name.
     *
     * @return array{submitted: string, approved: string, merged: string, merged_floor: string}
     */
    private static function unpublishedParams(): array
    {
        return [
            'submitted'    => ChangeReviewStatus::SUBMITTED->value,
            'approved'     => ChangeReviewStatus::APPROVED->value,
            'merged'       => ChangePublicationStatus::MERGED->value,
            'merged_floor' => ChangePublicationStatus::MERGED->value,
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
                AND ' . self::NOT_SUPERSEDED_BY_PUBLISHED . '
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
                AND ' . self::NOT_SUPERSEDED_BY_PUBLISHED . '
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
     * Set `publication_status` on every row of a batch, unconditionally on `review_status` AND
     * on the row's current `publication_status` — no guard at all.
     *
     * NOT the write any production path uses. The publisher records a real publication through
     * {@see recordPublication()} instead (which also clears `publish_attempts` and stamps the
     * git-side identifiers), and the merge poller uses its own guarded
     * {@see markBatchMerged()} / {@see markBatchClosedUnmerged()} (each conditioned on
     * `publication_status = 'open'`, so two racing pollers produce one transition and one
     * no-op) — see {@see releaseClaim()}'s own docblock for why it, too, cannot delegate here.
     * This method is test/ops tooling only: exactly what a repair script wants when an operator
     * needs to force a row into a specific state regardless of what it currently holds. It does
     * not touch `review_status` — publication and review are independent axes.
     *
     * @return int Rows transitioned.
     */
    public function markBatchPublicationStatus(string $batchId, ChangePublicationStatus $status): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status = :status,
                    updated_at = NOW()
              WHERE batch_id = :batch_id'
        );
        $stmt->execute([
            'status'   => $status->value,
            'batch_id' => $batchId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * How many CONSECUTIVE publish attempts a batch gets before the publisher stops claiming
     * it ("parks" it).
     *
     * The bound exists because a batch can fail deterministically — an illegal git-ref
     * character in a `resource_id`, a tree-path conflict, a payload a later validation change
     * rejects — and the runner stops the whole tick on a failure. Oldest-first ordering then
     * means that one batch is re-claimed first on every tick and every tick aborts before
     * reaching anything else: one editor's broken proposal indefinitely blocks every other
     * editor's good work, with nothing but a repeated log line to show for it.
     *
     * Five, not one: a GitHub blip, a token exchange that races an expiry, a transient 5xx must
     * NOT park a batch, and {@see recordPublication()} resets the counter, so the bound only
     * ever fires on failures that are genuinely consecutive. Five ticks of a cron interval is
     * long enough to ride out a transient outage and short enough that the queue is not held
     * hostage for a day.
     *
     * Parking is not deletion and not a dead-letter queue (this feature has none): the rows
     * are untouched, the batch stays `approved`/`none`, and clearing `publish_attempts` back
     * to 0 makes it claimable again. It must never be silent, which is why
     * {@see countParkedBatches()} feeds `GET /health` and the runner's own log line.
     */
    public const MAX_PUBLISH_ATTEMPTS = 5;

    /**
     * How many batches have exhausted {@see MAX_PUBLISH_ATTEMPTS} and are therefore no longer
     * being attempted.
     *
     * A batch that has silently stopped being attempted is the same class of defect as one
     * stranded `queued`: invisible to the operator, invisible to the editor, indistinguishable
     * from success on the editor's side. This is the number that makes it visible — reported by
     * `GET /health`'s `source_data_publisher` block and by every publish run's summary line.
     *
     * Counts only batches that would otherwise be claimable (`approved`, `none`), so a batch
     * whose attempts happen to be non-zero but which went on to publish, or which was rejected
     * or withdrawn afterwards, is not reported as stuck.
     *
     * The attempts test is `bool_or(>= MAX)`, deliberately the exact COMPLEMENT of the claim
     * query's `bool_and(< MAX)` rather than its mirror image. Every row of an approved batch
     * moves together today, so the two spellings agree — but if a batch ever did hold rows on
     * both sides of the bound, `bool_and(>= MAX)` would report it as neither claimable NOR
     * parked: stuck and invisible, which is the precise failure this count exists to prevent.
     * Complementary predicates make that hole unreachable by construction instead of by
     * argument.
     */
    public function countParkedBatches(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS parked
               FROM (
                   SELECT batch_id
                     FROM sourcedata_change_requests
                    GROUP BY batch_id
                   HAVING bool_and(review_status = :approved)
                      AND bool_and(publication_status = :none)
                      AND bool_or(publish_attempts >= :max_attempts)
               ) AS parked_batches'
        );
        $stmt->execute([
            'approved'     => ChangeReviewStatus::APPROVED->value,
            'none'         => ChangePublicationStatus::NONE->value,
            'max_attempts' => self::MAX_PUBLISH_ATTEMPTS,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return false === $row ? 0 : self::requireInt($row['parked'] ?? null, 'parked');
    }

    /**
     * Atomically claim the oldest approved-and-unpublished batch, or null if none exists.
     *
     * "Claimable" means every row of the batch is `review_status = 'approved'` AND
     * `publication_status = 'none'` AND `publish_attempts < MAX_PUBLISH_ATTEMPTS` — a batch is
     * never mixed-status (`decideBatch()` and `withdrawBatch()` each transition every
     * still-submitted row of a batch in one `UPDATE`, and every publish-side write below acts
     * on the whole batch), so aggregating with `bool_and(...)` over the whole batch is a safe
     * test.
     *
     * The attempts bound is what stops ONE failing batch from stranding the whole queue.
     * Candidates are ordered oldest-first and a failed publish returns its batch to `none`, so
     * without the bound the oldest failing batch is re-claimed first on every tick, forever,
     * and no other editor's approved work is ever reached. See {@see MAX_PUBLISH_ATTEMPTS}.
     * The predicate is repeated on the LOCK query for the same reason the status predicate is
     * (see below): the candidate snapshot can be stale by the time the lock runs, and a batch
     * another runner pushed over the bound in that window is `none` and unlocked, so the status
     * predicate alone would happily claim it.
     *
     * `$skipBatchIds` excludes batches the CALLER has already attempted in its current run.
     * They are not parked — a single failure must stay retryable on the next tick — but a
     * batch released back to `none` is instantly the oldest candidate again, so without this
     * a caller that continues past a failure re-claims the very batch it just failed on. That
     * is in-process retry by another name, and it is what the runner's "stop, don't hammer"
     * rule exists to avoid.
     *
     * `FOR UPDATE SKIP LOCKED` only holds its row lock for the lifetime of the surrounding
     * transaction. Without an explicit transaction wrapping BOTH the row lock and the
     * `queued` UPDATE, Postgres autocommit would release the lock the instant the locking
     * SELECT finished, and a second runner's SELECT could see the very same rows as
     * unlocked and still `none` before the first runner ever marks them `queued` —
     * double-claiming one editor's work as two separate publish attempts under one
     * branch. Same reasoning as
     * {@see \LiturgicalCalendar\Api\Services\Outbox\BackstopRunner::runOnce()}, which this
     * mirrors rather than reuses: that class is constructor-typed to the concrete
     * OutboxRepository and bound to the OpenFGA outbox, so it isn't reusable here.
     *
     * Postgres rejects `FOR UPDATE` combined with `GROUP BY` ("FOR UPDATE is not allowed
     * with GROUP BY clause"), so the aggregate `HAVING` that identifies a claimable batch
     * cannot itself take the row lock the way a flat, ungrouped query (like
     * OutboxRepository::pickupPending()) can. This is a two-step query instead: an
     * unlocked aggregate SELECT finds candidate batch ids oldest-first (`ORDER BY
     * MIN(created_at) ASC`, so a resource that is busy publishing cannot starve an older
     * approved batch waiting behind it), and the loop below then takes a real `FOR UPDATE
     * SKIP LOCKED` lock on every row of one candidate at a time. A batch is claimed only
     * when every one of its rows was actually locked — if a concurrent claim already holds
     * (any of) that batch's row locks, `SKIP LOCKED` drops those rows from the result, the
     * counts no longer match, and this method moves on to the next-oldest candidate rather
     * than blocking or taking a partial claim.
     *
     * The locking SELECT repeats `review_status = :approved AND publication_status = :none`,
     * even though the candidate SELECT already filtered on exactly that via `HAVING`. This
     * is NOT redundant, and must not be deleted as a simplification: the two SELECTs are
     * separated in time by an unbounded amount of work (the candidate list can be long, and
     * the loop can retry several times), and nothing locks a candidate's rows between when
     * it is read off the candidate cursor and when this SELECT runs. If some other runner
     * claims and COMMITS that exact batch in that window, its rows are `queued` — not
     * locked, since a COMMIT releases every lock the winning transaction held — and a
     * locking predicate of `WHERE batch_id = :batch_id FOR UPDATE SKIP LOCKED` alone would
     * find all N rows perfectly unlocked and available, lock them, see `lockedRowCount ===
     * rowCount` from the now-stale candidate snapshot, and claim the same batch a second
     * time. Repeating the status predicate here means `FOR UPDATE` re-evaluates it against
     * the latest COMMITTED row version at lock time (standard READ COMMITTED behaviour), so
     * a batch some other runner already committed to `queued` matches zero rows here,
     * `lockedRowCount !== rowCount` fires, and this method correctly moves on instead of
     * double-claiming. Demonstrated against live Postgres with two independent connections
     * in {@see \LiturgicalCalendar\Tests\Repositories\SourceDataChangeRequestPublishQueueTest::testTwoRealConcurrentRunnersNeverClaimTheSameBatch()},
     * which races two real OS processes against `claimNextPublishableBatch()` itself rather
     * than against a hand-copied query, so a regression here is what that test would catch.
     *
     * @param list<string> $skipBatchIds Batch ids to pass over, however claimable they look.
     *
     * @return PublishClaim|null The claimed batch id together with a freshly generated claim
     *                           token — see {@see PublishClaim} and {@see releaseClaim()} for
     *                           why the token exists — or null if nothing is claimable.
     */
    public function claimNextPublishableBatch(array $skipBatchIds = []): ?PublishClaim
    {
        $this->db->beginTransaction();
        try {
            $candidates = $this->db->prepare(
                'SELECT batch_id, COUNT(*) AS row_count
                   FROM sourcedata_change_requests
                  GROUP BY batch_id
                 HAVING bool_and(review_status = :approved)
                    AND bool_and(publication_status = :none)
                    AND bool_and(publish_attempts < :max_attempts)
                  ORDER BY MIN(created_at) ASC'
            );
            $candidates->execute([
                'approved'     => ChangeReviewStatus::APPROVED->value,
                'none'         => ChangePublicationStatus::NONE->value,
                'max_attempts' => self::MAX_PUBLISH_ATTEMPTS,
            ]);

            $lock = $this->db->prepare(
                'SELECT id
                   FROM sourcedata_change_requests
                  WHERE batch_id = :batch_id
                    AND review_status = :approved
                    AND publication_status = :none
                    AND publish_attempts < :max_attempts
                    FOR UPDATE SKIP LOCKED'
            );

            while (( $candidate = $candidates->fetch(PDO::FETCH_ASSOC) ) !== false) {
                /** @var array<string, mixed> $candidate */
                $batchId  = self::requireString($candidate['batch_id'] ?? null, 'batch_id');
                $rowCount = self::requireInt($candidate['row_count'] ?? null, 'row_count');

                if (in_array($batchId, $skipBatchIds, true)) {
                    // Already attempted (and failed) in the caller's current run. Its release
                    // put it back to `none`, so it is the oldest claimable candidate again and
                    // would be re-claimed immediately, re-attempted, and re-released — an
                    // in-process retry loop the caller explicitly does not want.
                    continue;
                }

                $lock->execute([
                    'batch_id'     => $batchId,
                    'approved'     => ChangeReviewStatus::APPROVED->value,
                    'none'         => ChangePublicationStatus::NONE->value,
                    'max_attempts' => self::MAX_PUBLISH_ATTEMPTS,
                ]);
                $lockedRowCount = count($lock->fetchAll(PDO::FETCH_COLUMN));

                if ($lockedRowCount !== $rowCount) {
                    // Contested: another runner already holds part (or all) of this
                    // batch's row locks. Leave it alone and try the next-oldest candidate.
                    continue;
                }

                $token = $this->newBatchId(); // gen_random_uuid(); same generator, different purpose

                $claim = $this->db->prepare(
                    'UPDATE sourcedata_change_requests
                        SET publication_status  = :queued,
                            publish_claim_token = :token,
                            updated_at          = NOW()
                      WHERE batch_id = :batch_id'
                );
                $claim->execute([
                    'queued'   => ChangePublicationStatus::QUEUED->value,
                    'token'    => $token,
                    'batch_id' => $batchId,
                ]);

                $this->db->commit();

                return new PublishClaim($batchId, $token);
            }

            $this->db->commit();

            return null;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Record that a claimed batch reached GitHub as an open pull request.
     *
     * Sets `publication_status` to `open`, stamps the git-side identifiers the batch was
     * published under, and clears `publish_attempts` — the bound in {@see MAX_PUBLISH_ATTEMPTS}
     * counts CONSECUTIVE failures, so a batch that eventually succeeds must not carry the
     * failures it recovered from into whatever phase 3 does with it. `base_sha` is the commit the publisher branched from — distinct
     * from each row's own `base_sha`, which is per-file accumulation-base bookkeeping set
     * at submission time.
     *
     * `WHERE ... AND publication_status = queued` guards a reachable overwrite: a slow runner
     * A can be outlived by {@see reclaimStaleClaims()} freeing its claim on the grace period,
     * letting a runner B claim, publish, and record the SAME batch first (`open`, with B's own
     * `commit_sha`/`pr_number`). If A's own publish then also succeeds — reachable when A pushed
     * first and B fast-forwarded past it — A calling this method afterward would otherwise
     * overwrite B's identifiers with A's older ones, and merge detection would then poll
     * whichever record won, against a pull request whose git history may not even contain A's
     * commit. The guard makes the FIRST recorder win and blocks the second: once a batch is no
     * longer `queued`, no later `recordPublication()` call can touch it.
     *
     * This is the MINIMAL fix, not the complete one: it identifies that SOME claim already
     * recorded a publish, not WHICH runner's identifiers are the correct ones to keep — the two
     * recorders are otherwise indistinguishable to this method, since it never sees a claim
     * token. The complete fix threads the claim token from {@see claimNextPublishableBatch()}
     * through {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface::publish()}
     * and into this method's own `WHERE`, so only the run holding the CURRENT claim can record —
     * deliberately not implemented here, to keep this change to a single added condition. The
     * caller ({@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher}) logs a
     * warning when this method reports zero rows, so a blocked second recorder is visible rather
     * than silent, but does not (and today cannot) attribute the block to the two identifiers.
     *
     * @return int Rows transitioned. Zero means no row was still `queued` for this batch id —
     *             either it does not exist, or another runner already recorded a publish for it.
     */
    public function recordPublication(
        string $batchId,
        string $branch,
        string $commitSha,
        ?int $prNumber,
        string $baseSha
    ): int {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status  = :open,
                    branch              = :branch,
                    commit_sha          = :commit_sha,
                    pr_number           = :pr_number,
                    base_sha            = :base_sha,
                    publish_attempts    = 0,
                    publish_claim_token = NULL,
                    updated_at          = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :queued'
        );
        $stmt->execute([
            'open'       => ChangePublicationStatus::OPEN->value,
            'branch'     => $branch,
            'commit_sha' => $commitSha,
            'pr_number'  => $prNumber,
            'base_sha'   => $baseSha,
            'batch_id'   => $batchId,
            'queued'     => ChangePublicationStatus::QUEUED->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * The DISTINCT pull request numbers among rows still `open`, oldest first.
     *
     * DISTINCT, not one row per batch, because the rolling branch is per RESOURCE: several
     * batches for one resource are published onto one branch and reuse one open pull request via
     * `findOpenPullRequest()`. Polling per batch would ask GitHub the same question N times and
     * get the same answer N times.
     *
     * `MIN(created_at)` orders it, so the oldest unresolved pull request is polled first and a
     * long queue cannot starve it.
     *
     * Rows with a NULL `pr_number` are deliberately NOT returned here — they are unpollable — and
     * are counted separately by {@see countOpenBatchesWithoutPullRequest()} so they cannot be
     * silently skipped.
     *
     * Each value is narrowed with {@see requireInt()} rather than blind-cast: a non-numeric
     * `pr_number` would blind-cast to `0`, and `0` is not inert here — it would be handed straight
     * to `getPullRequest(0)`, and the batch behind it would never be polled correctly again.
     * `requireInt()` throws instead, consistent with every other column this class reads back
     * through PDO.
     *
     * @return list<int>
     */
    public function listOpenPullRequestNumbers(): array
    {
        $stmt = $this->db->prepare(
            'SELECT pr_number
               FROM sourcedata_change_requests
              WHERE publication_status = :open
                AND pr_number IS NOT NULL
              GROUP BY pr_number
              ORDER BY MIN(created_at) ASC'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value]);

        /** @var list<int|string> $numbers */
        $numbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn (int|string $n): int => self::requireInt($n, 'pr_number'), $numbers);
    }

    /**
     * How many batches are `open` with no pull request number to poll.
     *
     * Never non-zero in practice: `SourceDataPublisher` opens a pull request whenever
     * `findOpenPullRequest()` returns null, and `openPullRequest()` returns an `int` or throws. So
     * a non-zero count here is an UNEXPLAINED state — a batch that is stuck forever, since nothing
     * will ever poll it. Counted rather than filtered out of the poller's query, because a row
     * quietly excluded from a `WHERE` is exactly as invisible as one stranded `queued`, which is
     * the defect class this feature keeps rediscovering.
     */
    public function countOpenBatchesWithoutPullRequest(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT batch_id) AS unpollable
               FROM sourcedata_change_requests
              WHERE publication_status = :open
                AND pr_number IS NULL'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return false === $row ? 0 : self::requireInt($row['unpollable'] ?? null, 'unpollable');
    }

    /**
     * Every `open` batch recorded against this pull request, with the commit it was published as.
     *
     * One row per BATCH, not per file: `recordPublication()` writes one commit sha across the
     * whole batch, so `MIN(commit_sha)` over the group is that single value, not an arbitrary
     * pick. Ordered by `MIN(created_at)` so the caller sees them in publication order.
     *
     * @return list<array{batch_id: string, commit_sha: ?string}>
     */
    public function listOpenBatchesForPullRequest(int $prNumber): array
    {
        $stmt = $this->db->prepare(
            'SELECT batch_id, MIN(commit_sha) AS commit_sha
               FROM sourcedata_change_requests
              WHERE publication_status = :open
                AND pr_number = :pr
              GROUP BY batch_id
              ORDER BY MIN(created_at) ASC'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value, 'pr' => $prNumber]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'batch_id'   => self::requireString($row['batch_id'] ?? null, 'batch_id'),
                'commit_sha' => is_string($row['commit_sha'] ?? null) ? $row['commit_sha'] : null,
            ],
            $rows
        );
    }

    /**
     * Record that a batch's content reached the base branch.
     *
     * Guarded on `publication_status = 'open'`, unlike {@see markBatchPublicationStatus()} which is
     * deliberately unconditional. The guard makes two racing pollers produce one transition and one
     * no-op instead of two writes of possibly-different merge shas — which is why the poller needs
     * no claim protocol of its own.
     *
     * `review_status` is untouched: a merge is not a review, and the batch was already approved.
     *
     * @return int Rows transitioned.
     */
    public function markBatchMerged(string $batchId, string $mergeCommitSha): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status     = :merged,
                    merge_commit_sha       = :merge_commit_sha,
                    publication_settled_at = NOW(),
                    updated_at             = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :open'
        );
        $stmt->execute([
            'merged'           => ChangePublicationStatus::MERGED->value,
            'merge_commit_sha' => $mergeCommitSha,
            'batch_id'         => $batchId,
            'open'             => ChangePublicationStatus::OPEN->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Record that a batch's pull request was closed without merging.
     *
     * Writes `review_status = 'rejected'` with it, which is what keeps the batch out of the
     * accumulation base — see `UNPUBLISHED_PREDICATE`'s docblock, where that pairing is a decision
     * rather than a coincidence. `rejected_reason` is generated rather than left null so an
     * editor's history explains why a batch they never withdrew is rejected.
     *
     * Nothing needs reverting: the change was never live anywhere.
     *
     * @return int Rows transitioned.
     */
    public function markBatchClosedUnmerged(string $batchId, string $reason): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status     = :closed,
                    review_status          = :rejected,
                    rejected_reason        = :reason,
                    publication_settled_at = NOW(),
                    updated_at             = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :open'
        );
        $stmt->execute([
            'closed'   => ChangePublicationStatus::CLOSED->value,
            'rejected' => ChangeReviewStatus::REJECTED->value,
            'reason'   => $reason,
            'batch_id' => $batchId,
            'open'     => ChangePublicationStatus::OPEN->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Put an `open` batch back to claimable, because its pull request merged WITHOUT it.
     *
     * Reachable whenever a reviewer merges concurrently with a publish: the batch's commit lands
     * on the branch, the merge takes the head it had a moment earlier, and the batch is left
     * pointing at a pull request that closed without carrying it. Marking it `merged` would assert
     * it reached the repository and make the publisher skip it forever, losing its content
     * silently — the same failure the age-based ancestor exclusion exists to avoid.
     *
     * `publish_attempts` is cleared: the batch spent no attempt, it was simply overtaken. The git
     * identifiers (`branch`, `commit_sha`, `pr_number`) are deliberately KEPT, so an operator
     * asking "what happened to this batch" can see which pull request passed it by.
     *
     * @return int Rows transitioned.
     */
    public function returnBatchToUnpublished(string $batchId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status  = :none,
                    publish_attempts    = 0,
                    publish_claim_token = NULL,
                    updated_at          = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :open'
        );
        $stmt->execute([
            'none'     => ChangePublicationStatus::NONE->value,
            'batch_id' => $batchId,
            'open'     => ChangePublicationStatus::OPEN->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * How many batches are awaiting a merge decision, and how long the oldest has waited.
     *
     * Reported by `GET /health`. An open batch is NOT an error — a pull request awaiting review is
     * the ordinary state — so the age is what carries the signal: a value that keeps climbing past
     * any plausible review time means the poller is not running at all.
     *
     * @return array{open_batches: int, oldest_open_age_seconds: int}
     */
    public function openBatchStats(): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT batch_id) AS open_batches,
                    COALESCE(EXTRACT(EPOCH FROM (NOW() - MIN(updated_at))), 0) AS oldest_age
               FROM sourcedata_change_requests
              WHERE publication_status = :open'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $row) {
            return ['open_batches' => 0, 'oldest_open_age_seconds' => 0];
        }

        return [
            'open_batches'            => self::requireInt($row['open_batches'] ?? null, 'open_batches'),
            'oldest_open_age_seconds' => self::requireInt($row['oldest_age'] ?? null, 'oldest_age'),
        ];
    }

    /**
     * Release a failed publish attempt: put a `queued` batch back to `none` so it is
     * claimable again on the next run, and count the attempt against
     * {@see MAX_PUBLISH_ATTEMPTS}.
     *
     * The increment rides on the SAME guarded `UPDATE` as the release, so an attempt is
     * counted exactly when a claim is actually given back — never when this call is a no-op
     * because someone else already settled the batch.
     *
     * Deliberately NOT built on {@see markBatchPublicationStatus()} — that method has NO guard
     * at all (see its own docblock), and this call needs one specific to releasing: it must
     * touch only a `queued` row still carrying THIS claim's token. `merged`/`closed` are not a
     * counterexample requiring an unconditional write either — {@see markBatchMerged()} and
     * {@see markBatchClosedUnmerged()} carry their own guard, `publication_status = 'open'`, and
     * would themselves regress if rewritten to delegate here. This method carries its own guard,
     * `AND publication_status = 'queued' AND publish_claim_token = :token`, so it releases
     * only a batch that is under OUR claim. Do not "simplify" this back into a delegating call
     * to `markBatchPublicationStatus()` — that is the exact regression this guard exists to
     * prevent.
     *
     * # The guard identifies WHOSE claim, not merely that one exists
     *
     * `queued` alone identifies *a* claim, not *whose* — that used to be the whole guard, and
     * it meant a runner whose publish failed late could release a claim a DIFFERENT runner had
     * since taken. Reachable without anything exotic: A claims -> the grace period elapses and
     * {@see reclaimStaleClaims()} releases A's claim (spending an attempt) -> B claims and
     * starts publishing -> A's own call finally fails and A releases. Before the token existed,
     * that last release matched on `queued` alone, revoking B's LIVE claim (spending a second
     * attempt) and putting the batch back to `none` while B was still working — up to TWO of
     * {@see MAX_PUBLISH_ATTEMPTS} spent per cycle instead of one.
     *
     * `publish_claim_token` closes that: {@see \LiturgicalCalendar\Api\Services\SourceData\PublishClaim}
     * carries the token `claimNextPublishableBatch()` generated for THIS claim, and the guard
     * above now requires it to match the token on the row. In the same A/B sequence, A's stale
     * release now matches zero rows — the row is still `queued`, but under B's token, not A's —
     * so it spends no attempt and returns {@see ClaimReleaseOutcome::CLAIM_LOST} rather than
     * {@see ClaimReleaseOutcome::RELEASED}. B's claim, and B's publish, are untouched. A third
     * runner can still claim and publish concurrently with B without a second reclaim being
     * involved, but that was always a separate, benign case — it lands on
     * ({@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher}'s `force: false`
     * plus a retry), never on lost work, and the token does nothing to change that.
     *
     * Why the guard matters: a caller here is, by construction, a runner whose OWN publish
     * attempt just failed — but "failed" can mean the process was merely SLOW, not dead. If
     * {@see reclaimStaleClaims()}'s grace period elapses while that runner's publish is still
     * genuinely in flight, a second runner can claim, publish, and {@see recordPublication()}
     * the very same batch (moving it to `open` with a real `commit_sha`/`pr_number`) before
     * the first runner's own GitHub call returns. The first runner's call then fails for real
     * (a non-fast-forward `updateRef()`, since the branch moved under it) and its caller
     * calls `releaseClaim()`. An unconditional release would revert that batch to `none`
     * while it still carries the successful publish's real commit and PR — silently
     * re-publishing already-published, successful work on the very next tick, indistinguishable
     * from a genuine failure. The `publication_status = 'queued'` guard makes this call a
     * no-op in that case (0 rows affected) instead: the row is already `open`, so it matches
     * nothing.
     *
     * # Why this returns a status and not a row count
     *
     * The guard makes "zero rows" ambiguous, and the readings are not all the same failure.
     * `open` means another runner genuinely published this batch, so this runner's failure was
     * redundant work. `none` means nothing is published anywhere — another runner's publish
     * failed too (a GitHub outage fails every runner identically), or {@see reclaimStaleClaims()}
     * released this batch out from under a merely-slow runner; this IS a real failure. `queued`
     * under a different token means another runner holds the live claim right now — also not a
     * failure of the OBSERVING call, but distinct from both: see {@see ClaimReleaseOutcome::CLAIM_LOST}.
     * Reading `none` as "settled elsewhere" is precisely the critical defect the final review of
     * this feature found: a real outage reported success and re-claimed the same batch every
     * iteration of the loop that exists to stop hammering. So this reports {@see ClaimReleaseOutcome},
     * and the caller branches on the observed state rather than on an integer.
     *
     * The observation and the release are ONE statement, deliberately. A `SELECT` after a
     * zero-row `UPDATE` would report a status the row may already have left; a data-modifying
     * CTE reads the pre-`UPDATE` version from the same snapshot the `UPDATE` evaluates against,
     * so the reported status is the one the release actually acted on.
     *
     * @param string $token The token from the {@see \LiturgicalCalendar\Api\Services\SourceData\PublishClaim}
     *                      this caller was handed by `claimNextPublishableBatch()`. Compared
     *                      against the row's current `publish_claim_token` so a release only
     *                      ever affects the claim the caller actually holds.
     *
     * @return ClaimReleaseOutcome What this call observed — see that enum for the full set of
     *                             readings and why a row count cannot distinguish them.
     */
    public function releaseClaim(string $batchId, string $token): ClaimReleaseOutcome
    {
        $stmt = $this->db->prepare(
            'WITH observed AS (
                 SELECT publication_status, publish_claim_token
                   FROM sourcedata_change_requests
                  WHERE batch_id = :batch_id
                  LIMIT 1
             ),
             released AS (
                 UPDATE sourcedata_change_requests
                    SET publication_status  = :none,
                        publish_claim_token = NULL,
                        publish_attempts    = publish_attempts + 1,
                        updated_at          = NOW()
                  WHERE batch_id = :batch_id
                    AND publication_status  = :queued
                    AND publish_claim_token = :token
                 RETURNING id
             )
             SELECT ( SELECT publication_status  FROM observed ) AS observed_status,
                    ( SELECT publish_claim_token FROM observed ) AS observed_token,
                    ( SELECT COUNT(*) FROM released )            AS released_rows'
        );
        $stmt->execute([
            'none'     => ChangePublicationStatus::NONE->value,
            'queued'   => ChangePublicationStatus::QUEUED->value,
            'token'    => $token,
            'batch_id' => $batchId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $row) {
            return ClaimReleaseOutcome::BATCH_MISSING;
        }

        if (self::requireInt($row['released_rows'] ?? null, 'released_rows') > 0) {
            return ClaimReleaseOutcome::RELEASED;
        }

        $observed = $row['observed_status'] ?? null;
        if (!is_string($observed)) {
            return ClaimReleaseOutcome::BATCH_MISSING;
        }

        $observedToken = $row['observed_token'] ?? null;

        // Still queued, but not under OUR token: another runner holds a live claim. Reported
        // distinctly so the caller neither treats it as published (SETTLED_ELSEWHERE) nor as
        // unclaimed work (NOT_CLAIMED) — and, critically, so no attempt is spent above.
        if (
            ChangePublicationStatus::QUEUED === ChangePublicationStatus::tryFrom($observed)
            && is_string($observedToken)
            && $observedToken !== $token
        ) {
            return ClaimReleaseOutcome::CLAIM_LOST;
        }

        // A `queued` observation that released nothing means a concurrent transaction moved
        // the row between this statement's snapshot and its row lock. Nothing was released and
        // nothing is known to be published, which is the NOT_CLAIMED reading — the
        // conservative one, and the one that keeps an unexplained state out of the
        // "settled, carry on" branch.
        return match (ChangePublicationStatus::tryFrom($observed)) {
            ChangePublicationStatus::OPEN,
            ChangePublicationStatus::MERGED,
            ChangePublicationStatus::CLOSED => ClaimReleaseOutcome::SETTLED_ELSEWHERE,
            default                         => ClaimReleaseOutcome::NOT_CLAIMED,
        };
    }

    /**
     * Release any row stranded `queued` whose `updated_at` is older than `$cutoff`, back to
     * `none`, so it is claimable again — counting the abandoned attempt against
     * {@see MAX_PUBLISH_ATTEMPTS}, exactly as {@see releaseClaim()} does.
     *
     * The increment here is not bookkeeping symmetry, it closes the sibling of the defect the
     * bound exists for. A caught failure consumes an attempt; a batch that KILLS the process
     * (an OOM on a large payload, a segfault) is caught by nothing, so if a reclaim were free
     * that batch would be re-claimed and re-crash forever — the same permanent head-of-line
     * block, reached through the one path that runs no PHP at all. The cost is that a merely
     * slow publish, reclaimed while still alive, spends attempts it did not deserve: up to TWO
     * per cycle, not one, because the reclaim here spends one and the slow runner's own late
     * `releaseClaim()` — which cannot tell that the claim it is releasing now belongs to
     * someone else, see that method's "What the guard does NOT provide" section — spends
     * another. So such a batch parks after three cycles rather than five. A batch parked that
     * way is visible in `/health` and un-parked by clearing `publish_attempts`, which is
     * strictly better than a queue nobody can drain.
     *
     * Recovers from a crash between {@see claimNextPublishableBatch()} and the publish
     * finishing — a SIGKILL, an OOM kill, or a cron timeout — that leaves a batch `queued`
     * with nothing left running to release it. Without this, that batch is never picked up
     * again: invisible to the operator, invisible to the editor, indistinguishable from
     * success on the editor's side. Mirrors
     * {@see \LiturgicalCalendar\Api\Services\Outbox\BackstopRunner}'s grace-window pattern
     * (a cutoff computed by the caller, not by this method) rather than reusing it — that
     * class is constructor-typed to the concrete `OutboxRepository` and bound to the OpenFGA
     * outbox, so it isn't reusable here, same as {@see claimNextPublishableBatch()}'s own
     * docblock explains for the claim side.
     *
     * Deliberately unlocked, unlike `claimNextPublishableBatch()`'s `FOR UPDATE SKIP LOCKED`:
     * reclaiming a batch that is, in fact, still being actively published by a live process
     * is tolerated rather than prevented. The worst case is a second, concurrent publish
     * attempt landing on the same branch, which `SourceDataPublisher::publish()` already
     * handles safely — a non-fast-forward `updateRef()` on whichever attempt loses the race,
     * which the runner retries on its next tick.
     *
     * @return int Rows transitioned.
     */
    public function reclaimStaleClaims(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status  = :none,
                    publish_claim_token = NULL,
                    publish_attempts    = publish_attempts + 1,
                    updated_at          = NOW()
              WHERE publication_status = :queued
                AND updated_at < :cutoff'
        );
        $stmt->execute([
            'none'   => ChangePublicationStatus::NONE->value,
            'queued' => ChangePublicationStatus::QUEUED->value,
            'cutoff' => $cutoff->format('Y-m-d H:i:s.uP'),
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
