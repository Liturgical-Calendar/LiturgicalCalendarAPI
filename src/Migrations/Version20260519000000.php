<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * api_keys: drop redundant idx_api_keys_hash, add idx_api_keys_application_id.
 *
 * Two index issues flagged by CodeRabbit on PR #599's baseline schema:
 *
 *   1. idx_api_keys_hash is redundant. `key_hash` is declared
 *      VARCHAR(255) UNIQUE NOT NULL, and Postgres auto-creates a unique
 *      B-tree index to enforce the constraint (api_keys_key_hash_key).
 *      The separate non-unique index is dead weight — second index to
 *      maintain on every write, no query-planner benefit.
 *
 *   2. idx_api_keys_application_id is missing. `application_id` is a
 *      foreign key (UUID NOT NULL REFERENCES applications(id) ON DELETE
 *      CASCADE). Postgres does NOT auto-index FK columns, so queries
 *      like "list keys for application X" and cascade-deletes from
 *      applications do sequential scans on api_keys.
 *
 * Idempotency: `DROP INDEX IF EXISTS` and `CREATE INDEX IF NOT EXISTS`
 * make this safe to re-run against an environment that already had the
 * fix applied out of band.
 */
final class Version20260519000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'api_keys: drop redundant idx_api_keys_hash, add idx_api_keys_application_id';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_api_keys_hash');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_api_keys_application_id ON api_keys(application_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_api_keys_application_id');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys(key_hash)');
    }
}
