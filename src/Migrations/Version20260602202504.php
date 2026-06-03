<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OpenFGA async reconciliation outbox (Options B+C from issue #567).
 *
 * One row per OpenFGA tuple operation (write or delete) that the API has
 * committed to perform. The handler inserts the row in the same Postgres
 * transaction as the business write (e.g. access_requests.status = 'approved'),
 * so commit atomicity gives us durable intent. A systemd consumer drains via
 * Redis Streams on the fast path; a cron backstop catches the cracks.
 */
final class Version20260602202504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create openfga_outbox table for async reconciliation (issue #567 Options B+C)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql("CREATE TYPE outbox_op AS ENUM ('write_tuple', 'delete_tuple')");
        $this->addSql("CREATE TYPE outbox_status AS ENUM ('pending', 'retrying', 'succeeded', 'failed_terminal')");

        $this->addSql(<<<'SQL'
            CREATE TABLE openfga_outbox (
                id                BIGSERIAL    PRIMARY KEY,
                operation         outbox_op    NOT NULL,
                fga_user          TEXT         NOT NULL,
                fga_relation      TEXT         NOT NULL,
                fga_object        TEXT         NOT NULL,
                status            outbox_status NOT NULL DEFAULT 'pending',
                attempts          SMALLINT     NOT NULL DEFAULT 0,
                next_attempt_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                last_error        TEXT         NULL,
                last_error_code   TEXT         NULL,
                metadata          JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                completed_at      TIMESTAMPTZ  NULL
            )
        SQL);

        // Deliberately a standalone UNIQUE INDEX rather than an inline CONSTRAINT ... UNIQUE
        // inside CREATE TABLE: Doctrine DBAL mis-parses the double-parenthesis expression form
        // "(metadata->>'idempotency_key')" when it appears as an inline table constraint.
        // Postgres enforces uniqueness identically either way.
        // To reverse: use  DROP INDEX openfga_outbox_idempotency_unique
        //             NOT  ALTER TABLE openfga_outbox DROP CONSTRAINT openfga_outbox_idempotency_unique
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX openfga_outbox_idempotency_unique
                ON openfga_outbox ((metadata->>'idempotency_key'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_pickup ON openfga_outbox (status, next_attempt_at)
                WHERE status IN ('pending', 'retrying')
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_dlq ON openfga_outbox (status, created_at)
                WHERE status = 'failed_terminal'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_metadata_request ON openfga_outbox ((metadata->>'access_request_id'))
                WHERE metadata ?? 'access_request_id'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        // Drop indexes explicitly before the table for auditability and symmetry with up().
        // (DROP TABLE would cascade them automatically, but explicit reversal is clearer.)
        // The unique index is an INDEX, not a CONSTRAINT — use DROP INDEX, not DROP CONSTRAINT.
        $this->addSql('DROP INDEX IF EXISTS openfga_outbox_idempotency_unique');
        $this->addSql('DROP INDEX IF EXISTS idx_outbox_pickup');
        $this->addSql('DROP INDEX IF EXISTS idx_outbox_dlq');
        $this->addSql('DROP INDEX IF EXISTS idx_outbox_metadata_request');
        $this->addSql('DROP TABLE IF EXISTS openfga_outbox');
        // Drop enum types in reverse-declaration order (outbox_status declared after outbox_op).
        $this->addSql('DROP TYPE IF EXISTS outbox_status');
        $this->addSql('DROP TYPE IF EXISTS outbox_op');
    }
}
