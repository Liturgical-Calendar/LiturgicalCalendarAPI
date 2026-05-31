<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #573: user-facing notification bookmark.
 *
 * One row per Zitadel user, holding the last time they marked
 * their notifications inbox as seen. Absence of a row is treated
 * as "unseen since epoch" via the read path (no placeholder row
 * is ever inserted on read).
 */
final class Version20260530140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_notification_state table for issue #573';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_notification_state (
                user_id                     VARCHAR(255) PRIMARY KEY,
                last_notification_seen_at   TIMESTAMP NOT NULL DEFAULT TIMESTAMP 'epoch'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP TABLE IF EXISTS user_notification_state');
    }
}
