<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Snapshot the review DECISION, so a rejected change request can notify its submitter (#925).
 *
 * # Why a new column, when `review_status` already says approved or rejected
 *
 * Because `review_status` is not a record of the decision — it is the batch's CURRENT position,
 * and something else moves it afterwards. `SourceDataChangeRequestRepository::markBatchClosedUnmerged()`
 * writes `review_status = 'rejected'` when a published batch's pull request is closed without
 * merging, on a batch a human APPROVED. Reading `review_status` to describe a review decision
 * would therefore tell that submitter "your proposal was rejected" and stamp it with the moment
 * they were approved — a notification that reports an untruth, which is exactly the class of
 * defect this repository has had to fix repeatedly elsewhere.
 *
 * `review_decision` is written by `decideBatch()` and by nothing else. It is the outcome AS
 * DECIDED, frozen at the decision, and no later transition touches it.
 *
 * # Why there is no `review_settled_at` alongside it
 *
 * There is already a single-write review-decision cursor: `approved_at`. Despite its name it is
 * stamped for BOTH outcomes — `decideBatch()` sets `approved_at = NOW()` on approve and on reject
 * alike — and its `WHERE review_status = 'submitted'` guard is what makes the decision single-shot,
 * so the column is written exactly once in a batch's lifetime and never moved again. That is
 * precisely the property `publication_settled_at` was added for on the publication axis, and
 * `updated_at` (which moves on every claim, release, reclaim and record) fails. Adding a
 * `review_settled_at` that would always equal `approved_at` would buy nothing and create a pair
 * that can drift. The column comment below carries the correction so the misleading name cannot
 * mislead a reader of the schema.
 *
 * # Backfill
 *
 * Existing decided batches get their decision reconstructed, in two exact cases rather than by
 * copying `review_status` wholesale:
 *
 *  - A batch that never left `publication_status = 'none'` was never published, so nothing can have
 *    rewritten its `review_status`: it still says what was decided.
 *  - A batch that reached any other publication status must have been approved to get there —
 *    `claimNextPublishableBatch()` claims only `review_status = 'approved'` rows — regardless of
 *    what its `review_status` says now. This is the case that repairs the closed-unmerged rows.
 *
 * A batch with `approved_at IS NULL` was never decided and stays NULL, which the inbox reads as
 * "no review-decision notification for this row".
 *
 * The backfill makes historical decisions visible in the inbox the first time a user opens it,
 * exactly as adding `publication_settled_at` did for historical publications. That is intended:
 * the point of the issue is that these decisions were never announced at all.
 */
final class Version20260901120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sourcedata_change_requests.review_decision so a review decision — approve OR reject — can notify its submitter (#925)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN review_decision VARCHAR(20) NULL');

        $this->addSql(
            'ALTER TABLE sourcedata_change_requests '
            . "ADD CONSTRAINT chk_scr_review_decision CHECK (review_decision IS NULL OR review_decision IN ('approved', 'rejected'))"
        );

        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.review_decision IS '
            . "'The review outcome AS DECIDED, written once by decideBatch(); review_status is the CURRENT position and is rewritten by markBatchClosedUnmerged()'"
        );

        // The name predates the discovery that it stamps rejections too. Correct it here rather
        // than renaming the column, which every query and test would have to follow.
        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.approved_at IS '
            . "'When the batch was DECIDED, approved or rejected alike; written once by decideBatch(), and the review-decision notification cursor'"
        );

        // The notifications inbox reads a submitter's decided batches, newest first — the review
        // -decision counterpart of idx_scr_settled_for_submitter.
        $this->addSql(
            'CREATE INDEX idx_scr_decided_for_submitter ON sourcedata_change_requests '
            . '(submitted_by_sub, approved_at DESC) WHERE review_decision IS NOT NULL'
        );

        // A batch still at publication_status 'none' was never published, so nothing has
        // rewritten its review_status: it still records the decision.
        $this->addSql(
            'UPDATE sourcedata_change_requests SET review_decision = review_status '
            . "WHERE approved_at IS NOT NULL AND publication_status = 'none' "
            . "AND review_status IN ('approved', 'rejected')"
        );

        // Anything that got past 'none' was claimed for publication, which requires approval —
        // whatever its review_status says today.
        $this->addSql(
            "UPDATE sourcedata_change_requests SET review_decision = 'approved' "
            . "WHERE approved_at IS NOT NULL AND publication_status <> 'none'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_scr_decided_for_submitter');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP CONSTRAINT IF EXISTS chk_scr_review_decision');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS review_decision');
        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.approved_at IS NULL'
        );
    }
}
