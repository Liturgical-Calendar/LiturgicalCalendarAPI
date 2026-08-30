<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give the publisher's branch-head sha its own column, so `base_sha` can go back to being
 * per-file.
 *
 * Phase 1 defined `base_sha` as "the blob sha the edit was authored against; drives the
 * rebase check". Phase 2 then wrote something else entirely into it: `recordPublication()`
 * stamped the batch-level BRANCH HEAD COMMIT sha across every row of a published batch,
 * overwriting whatever per-file value was there. Both values are wanted, and they are not
 * the same kind of thing — one is a blob sha per file, captured at submission; the other is
 * a commit sha per batch, captured at publish — so they get two columns rather than one that
 * means whichever of the two was written last.
 *
 * The backfill moves every existing value, because every non-null `base_sha` in the table
 * today was written by `recordPublication()`: nothing else has ever written the column.
 * `base_sha` is then cleared on those rows rather than left holding a commit sha that a
 * rebase check would compare against a blob sha and always find "moved".
 *
 * Rows in flight at the moment this migration runs keep a null `base_sha` — there is no way
 * to reconstruct what they were authored against after the fact — and that null is readable:
 * a null on an `update`/`delete` row means "unknown, predates the column being written",
 * while a null on a `create` row means "there was no upstream file", which is the column's
 * ordinary value for a create.
 */
final class Version20260830130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split sourcedata_change_requests.base_sha (per-file blob) from .publish_base_sha (batch-level branch head)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN publish_base_sha VARCHAR(64) NULL');

        // Every non-null base_sha in the table was written by recordPublication() and is a
        // commit sha, not a blob sha. Move it, then clear the column it was squatting in.
        $this->addSql(
            'UPDATE sourcedata_change_requests SET publish_base_sha = base_sha, base_sha = NULL WHERE base_sha IS NOT NULL'
        );

        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.base_sha IS '
            . "'Per-file git blob sha the edit was authored against, captured at submission; null means no upstream file (a create) or a row predating this definition'"
        );
        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.publish_base_sha IS '
            . "'Batch-level commit sha the publish branched from, written across every row by recordPublication()'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        // Restore the pre-split state: the branch head goes back into base_sha, discarding
        // any genuine per-file blob sha captured while this migration was applied. That loss
        // is the point of reverting — the old column cannot hold both.
        $this->addSql(
            'UPDATE sourcedata_change_requests SET base_sha = publish_base_sha WHERE publish_base_sha IS NOT NULL'
        );
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS publish_base_sha');
        $this->addSql('COMMENT ON COLUMN sourcedata_change_requests.base_sha IS NULL');
    }
}
