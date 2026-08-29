<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Source-data change requests.
 *
 * One row per proposed file change. Rows sharing a `batch_id` were submitted by
 * one API write request and are reviewed together: a diocesan calendar and its
 * i18n files must not be approvable separately.
 *
 * Statuses are VARCHAR + CHECK rather than PostgreSQL ENUM types so that later
 * phases can add publication states without an ALTER TYPE inside a transactional
 * migration. This matches `access_requests`; `openfga_outbox` uses real enums and
 * is the exception, not the pattern.
 */
final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sourcedata_change_requests table (source-data change request workflow, phase 1)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sourcedata_change_requests (
                id                          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
                batch_id                    UUID         NOT NULL,
                resource_type               VARCHAR(64)  NOT NULL,
                resource_id                 VARCHAR(255) NOT NULL,
                path                        TEXT         NOT NULL,
                operation                   VARCHAR(16)  NOT NULL,
                content                     TEXT         NULL,
                base_sha                    VARCHAR(64)  NULL,
                submitted_by_sub            VARCHAR(255) NOT NULL,
                submitted_by_name           VARCHAR(255) NULL,
                submitted_by_email          VARCHAR(255) NULL,
                submitted_by_email_verified BOOLEAN      NOT NULL DEFAULT FALSE,
                review_status               VARCHAR(20)  NOT NULL DEFAULT 'submitted',
                publication_status          VARCHAR(20)  NOT NULL DEFAULT 'none',
                approved_by_sub             VARCHAR(255) NULL,
                approved_at                 TIMESTAMPTZ  NULL,
                rejected_reason             TEXT         NULL,
                pr_number                   INTEGER      NULL,
                branch                      TEXT         NULL,
                commit_sha                  VARCHAR(64)  NULL,
                merge_commit_sha            VARCHAR(64)  NULL,
                metadata                    JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_scr_operation CHECK (operation IN ('create', 'update', 'delete')),
                CONSTRAINT chk_scr_review_status CHECK (review_status IN ('submitted', 'approved', 'rejected', 'withdrawn')),
                CONSTRAINT chk_scr_publication_status CHECK (publication_status IN ('none', 'queued', 'open', 'merged', 'closed')),
                CONSTRAINT chk_scr_delete_has_no_content CHECK (operation <> 'delete' OR content IS NULL),
                CONSTRAINT chk_scr_write_has_content CHECK (operation = 'delete' OR content IS NOT NULL)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_scr_review_status ON sourcedata_change_requests (review_status, created_at)');
        $this->addSql('CREATE INDEX idx_scr_submitter ON sourcedata_change_requests (submitted_by_sub, created_at DESC)');
        $this->addSql('CREATE INDEX idx_scr_resource ON sourcedata_change_requests (resource_id, review_status)');
        $this->addSql('CREATE INDEX idx_scr_batch ON sourcedata_change_requests (batch_id)');

        // Save-equals-submit: one SUBMITTED proposal per (path, submitter) — and only that.
        // The index is PARTIAL, so it constrains nothing about approved, rejected or
        // withdrawn rows, any number of which may share a (path, submitter). Code that needs
        // "the submitter's newest content for this path" therefore cannot lean on uniqueness
        // and must order explicitly; see SourceDataChangeRequestRepository's class docblock.
        //
        // A resource is not 1:1 with a file — ChangeResource::decrees() covers the whole
        // decree corpus, and rite_calendar_test:<rite> covers every rite-level test — so the
        // repository's supersede DELETE keys on path, the same column this index does, not on
        // resource: deleting whole prior batches that collide on any incoming (path,
        // submitter), never a resource match alone. This index is then a defence-in-depth net
        // against races and direct inserts, exactly as
        // idx_access_requests_unique_pending_user_role is for access_requests.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX idx_scr_unique_pending_path_submitter
            ON sourcedata_change_requests (path, submitted_by_sub)
            WHERE review_status = 'submitted'
        SQL);

        $this->addSql("COMMENT ON TABLE sourcedata_change_requests IS 'Proposed edits to jsondata/sourcedata, reviewed here and published to GitHub as pull requests'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.batch_id IS 'Rows submitted by one API write request; approved and rejected together'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.path IS 'Repository-relative path, e.g. jsondata/sourcedata/rite/roman/calendars/nations/US/US.json'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.submitted_by_email_verified IS 'Only a verified email may be used as the git commit author email'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.publication_status IS 'GitHub-side state; phase 1 only ever writes none'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sourcedata_change_requests');
    }
}
