<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add applications.is_system flag for first-party (official project) applications.
 *
 * A system application's API keys are trusted first-party principals: they are exempt from
 * per-resource FGA governance if/when read authorization is gated (see
 * docs/superpowers/specs/2026-06-29-official-first-party-api-keys-design.md). The flag is set
 * ONLY by the scripts/mint-official-key.php admin script; the user-facing application and
 * access-request flows never set it, so users cannot mint ungated keys.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add applications.is_system flag for first-party official-UI applications';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE applications ADD COLUMN is_system BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE applications DROP COLUMN IF EXISTS is_system');
    }
}
