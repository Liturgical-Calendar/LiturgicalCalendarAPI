<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3: claim ownership, and a stable cursor for publication notifications.
 *
 * `publish_claim_token` closes the hole `releaseClaim()`'s own docblock describes: its
 * `publication_status = 'queued'` guard identifies *a* claim, not *whose*, so a runner whose
 * publish failed late can release a claim a DIFFERENT runner has since taken — spending a second
 * attempt against `MAX_PUBLISH_ATTEMPTS` and parking a merely-slow batch in three cycles instead
 * of five. Comparing a token in the `WHERE` makes a stale release match nothing, which costs the
 * batch nothing.
 *
 * `publication_settled_at` is the notification cursor. `updated_at` cannot be: it moves on every
 * claim, release, reclaim and record, so it answers "when was this row last touched" rather than
 * "when did this become news for the submitter". This column is written once, by the transition to
 * `merged` or `closed`, and is compared against `user_notification_state.last_notification_seen_at`.
 */
final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sourcedata_change_requests.publish_claim_token and .publication_settled_at (phase 3)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN publish_claim_token UUID NULL');
        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN publication_settled_at TIMESTAMPTZ NULL');

        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.publish_claim_token IS '
            . "'Identifies WHICH runner holds the queued claim; compared in releaseClaim() so a stale release is a no-op'"
        );
        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.publication_settled_at IS '
            . "'Written once, by the transition to merged or closed; the notification cursor (updated_at is not)'"
        );

        // The merge poller scans DISTINCT pr_number among open rows.
        $this->addSql(
            'CREATE INDEX idx_scr_open_pr ON sourcedata_change_requests (pr_number) '
            . "WHERE publication_status = 'open'"
        );

        // The notifications inbox reads a submitter's settled batches, newest first.
        $this->addSql(
            'CREATE INDEX idx_scr_settled_for_submitter ON sourcedata_change_requests '
            . '(submitted_by_sub, publication_settled_at DESC) WHERE publication_settled_at IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_scr_settled_for_submitter');
        $this->addSql('DROP INDEX IF EXISTS idx_scr_open_pr');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS publication_settled_at');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS publish_claim_token');
    }
}
