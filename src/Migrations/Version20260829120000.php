<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bound how many times one batch may be attempted before the publisher stops attempting it.
 *
 * Without a bound, ONE deterministically-failing batch stalls the entire queue forever:
 * candidates are ordered oldest-first, a failed publish returns the batch to `none`, and the
 * runner stops on failure — so the oldest failing batch is re-claimed first on every tick and
 * every tick aborts before reaching anything else. Every other editor's approved work is never
 * attempted, with no error of its own to show for it. Reachable through ordinary use: an
 * illegal git-ref character in a `resource_id`, a tree-path conflict, or a payload a later
 * validation change rejects.
 *
 * `publish_attempts` counts CONSECUTIVE attempts against a batch:
 * `SourceDataChangeRequestRepository::releaseClaim()` and `reclaimStaleClaims()` both increment
 * it (a crash consumes an attempt exactly as a caught failure does — otherwise a batch that
 * OOM-kills the process on every attempt loops forever, the same defect reached through the
 * one path that catches nothing), and `recordPublication()` resets it, so a batch that finally
 * publishes carries no residue. Once it reaches
 * `SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS` the batch stops being claimable and
 * the rest of the queue drains past it.
 *
 * A parked batch must never be a silent one — that is the same class of bug as a stranded one.
 * It is surfaced in `GET /health`'s `source_data_publisher` block, in every run's log line, and
 * in the runbook's SQL.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sourcedata_change_requests.publish_attempts (bounded publish retries, phase 2)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN publish_attempts INTEGER NOT NULL DEFAULT 0');
        $this->addSql(
            'ALTER TABLE sourcedata_change_requests '
            . 'ADD CONSTRAINT chk_scr_publish_attempts_non_negative CHECK (publish_attempts >= 0)'
        );
        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.publish_attempts IS '
            . "'Consecutive publish attempts; at MAX_PUBLISH_ATTEMPTS the batch is parked (no longer claimed) and reported by /health'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE sourcedata_change_requests DROP CONSTRAINT IF EXISTS chk_scr_publish_attempts_non_negative');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS publish_attempts');
    }
}
