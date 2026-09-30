<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schedule state and leases for the job runner (#1008).
 *
 * One row per registered job, plus the reserved `supervisor` row whose lease keeps a single supervisor
 * active. A lease is `lease_owner` + `lease_until`, taken with a guarded UPDATE, and every comparison uses
 * the database clock, so hosts with skewed clocks still agree on who holds what. See
 * docs/superpowers/specs/2026-09-29-job-runner-design.md §5.
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create job_schedule for the job runner (#1008)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE job_schedule (
                name                  TEXT         PRIMARY KEY,
                next_due_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                lease_owner           TEXT         NULL,
                lease_until           TIMESTAMPTZ  NULL,
                last_started_at       TIMESTAMPTZ  NULL,
                last_finished_at      TIMESTAMPTZ  NULL,
                last_success_at       TIMESTAMPTZ  NULL,
                last_status           TEXT         NULL
                    CHECK (last_status IN ('succeeded', 'failed', 'timed_out', 'refused')),
                last_error            TEXT         NULL,
                last_duration_ms      INTEGER      NULL,
                consecutive_failures  INTEGER      NOT NULL DEFAULT 0
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP TABLE IF EXISTS job_schedule');
    }
}
