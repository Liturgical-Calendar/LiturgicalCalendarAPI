<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline schema for the LiturgicalCalendar application database.
 *
 * Mirrors the table/index/comment DDL in scripts/init-db.sql as of
 * 2026-05-18. Created tables:
 *
 *   - access_requests  unified role + permission requests
 *   - applications     registered apps for API key issuance
 *   - api_keys         API keys for application authentication
 *   - audit_log        security / compliance audit trail
 *
 * Also enables the pgcrypto extension (gen_random_uuid()).
 *
 * IMPORTANT for environments where the schema was bootstrapped out
 * of band from init-db.sql before this migration existed:
 *
 *   - The STAGING server's litcal_staging database was manually
 *     populated from init-db.sql DDL on 2026-05-18. The baseline
 *     marker has already been INSERTed there as part of this PR's
 *     pre-merge prep — no further action needed for staging.
 *
 *   - Any docker-compose local-dev container whose db volume was
 *     created BEFORE pulling this PR has the schema but not the
 *     marker. Insert it manually or wipe the volume and let
 *     init-db.sql re-bootstrap.
 *
 *   - litcal_production is still empty (no tables) — auth features
 *     haven't shipped to prod yet. When the first production deploy
 *     happens this migration will apply normally and create the
 *     schema. DO NOT pre-mark Version20260518120000 as applied on
 *     production; that would cause CREATE-TABLE statements to be
 *     silently skipped and leave production without the tables.
 *
 * To pre-mark a baseline as already applied (only when the schema
 * already exists out-of-band, as on staging), either via direct SQL:
 *
 *     INSERT INTO doctrine_migration_versions
 *         (version, executed_at, execution_time)
 *     VALUES
 *         ('LiturgicalCalendar\\Api\\Migrations\\Version20260518120000',
 *          NOW(), 0)
 *     ON CONFLICT (version) DO NOTHING;
 *
 * ...or by running, in an environment that has `bin/doctrine-migrations`
 * (i.e. local dev, NOT the deployed server which excludes bin/):
 *
 *     bin/doctrine-migrations version --add \
 *         'LiturgicalCalendar\Api\Migrations\Version20260518120000' \
 *         --no-interaction
 *
 * Fresh environments (a brand-new Postgres with neither tables nor
 * tracking row) run this migration normally and end up with the
 * full schema + the baseline row recorded by Doctrine automatically.
 */
final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline schema: access_requests, applications, api_keys, audit_log';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        // --- access_requests ---------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE access_requests (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                zitadel_user_id VARCHAR(255) NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                user_name VARCHAR(255),
                requested_role VARCHAR(50) NOT NULL,
                permissions JSONB NOT NULL DEFAULT '[]',
                justification TEXT,
                credentials TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                reviewed_by VARCHAR(255),
                review_notes TEXT,
                zitadel_sync_status VARCHAR(20) DEFAULT NULL,
                zitadel_sync_error TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP,
                CONSTRAINT chk_requested_role CHECK (requested_role IN ('developer', 'calendar_editor', 'test_editor')),
                CONSTRAINT chk_access_request_status CHECK (status IN ('pending', 'approved', 'rejected', 'revoked')),
                CONSTRAINT chk_zitadel_sync_status CHECK (zitadel_sync_status IS NULL OR zitadel_sync_status IN ('pending', 'synced', 'failed'))
            )
        SQL);

        $this->addSql('CREATE INDEX idx_access_requests_status ON access_requests(status)');
        $this->addSql('CREATE INDEX idx_access_requests_user ON access_requests(zitadel_user_id)');
        $this->addSql('CREATE INDEX idx_access_requests_created ON access_requests(created_at)');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_access_requests_sync_status ON access_requests(zitadel_sync_status)
            WHERE zitadel_sync_status = 'failed'
        SQL);

        // At most one pending request per (user, role): defense-in-depth
        // against races and direct DB inserts. Application layer also
        // checks via hasPendingRequest().
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX idx_access_requests_unique_pending_user_role
            ON access_requests(zitadel_user_id, requested_role)
            WHERE status = 'pending'
        SQL);

        $this->addSql("COMMENT ON TABLE access_requests IS 'Unified role + permission requests — role via Zitadel, permissions via OpenFGA'");
        $this->addSql("COMMENT ON COLUMN access_requests.requested_role IS 'Zitadel role: developer, calendar_editor, test_editor'");
        $this->addSql("COMMENT ON COLUMN access_requests.permissions IS 'JSON array of OpenFGA tuples: [{object_type, object_id, relation}, ...]'");
        $this->addSql("COMMENT ON COLUMN access_requests.status IS 'Status: pending, approved, rejected, revoked'");
        $this->addSql("COMMENT ON COLUMN access_requests.zitadel_sync_status IS 'Zitadel role sync: null (not attempted), pending, synced, failed'");

        // --- applications ------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE applications (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                zitadel_user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                website VARCHAR(500),
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                requested_scope VARCHAR(10) NOT NULL DEFAULT 'read',
                reviewed_by VARCHAR(255),
                review_notes TEXT,
                reviewed_at TIMESTAMP,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT chk_application_status CHECK (status IN ('pending', 'approved', 'rejected', 'revoked')),
                CONSTRAINT chk_application_requested_scope CHECK (requested_scope IN ('read', 'write'))
            )
        SQL);

        $this->addSql('CREATE INDEX idx_applications_user ON applications(zitadel_user_id)');
        $this->addSql('CREATE INDEX idx_applications_status ON applications(status)');
        $this->addSql("COMMENT ON TABLE applications IS 'Registered applications for API key management'");

        // --- api_keys ----------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE api_keys (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                application_id UUID NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
                key_hash VARCHAR(255) UNIQUE NOT NULL,
                key_prefix VARCHAR(20) NOT NULL,
                name VARCHAR(100),
                scope VARCHAR(20) DEFAULT 'read',
                rate_limit_per_hour INTEGER DEFAULT 100,
                is_active BOOLEAN DEFAULT TRUE,
                last_used_at TIMESTAMP,
                expires_at TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT chk_api_keys_scope CHECK (scope IN ('read', 'write'))
            )
        SQL);

        $this->addSql('CREATE INDEX idx_api_keys_hash ON api_keys(key_hash)');
        $this->addSql('CREATE INDEX idx_api_keys_prefix ON api_keys(key_prefix)');
        $this->addSql("COMMENT ON TABLE api_keys IS 'API keys for application authentication'");
        $this->addSql("COMMENT ON COLUMN api_keys.key_hash IS 'SHA-256 hash of the API key'");
        $this->addSql("COMMENT ON COLUMN api_keys.key_prefix IS 'First 20 characters for identification'");
        $this->addSql("COMMENT ON COLUMN api_keys.scope IS 'Scope: read, write'");

        // --- audit_log ---------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                zitadel_user_id VARCHAR(255),
                action VARCHAR(100) NOT NULL,
                resource_type VARCHAR(50) NOT NULL,
                resource_id VARCHAR(100),
                details JSONB,
                ip_address INET,
                user_agent TEXT,
                success BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->addSql('CREATE INDEX idx_audit_log_user ON audit_log(zitadel_user_id)');
        $this->addSql('CREATE INDEX idx_audit_log_created ON audit_log(created_at)');
        $this->addSql('CREATE INDEX idx_audit_log_action ON audit_log(action)');
        $this->addSql("COMMENT ON TABLE audit_log IS 'Audit trail for security and compliance'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        // Drop in FK dependency order: api_keys references applications.
        $this->addSql('DROP TABLE IF EXISTS audit_log');
        $this->addSql('DROP TABLE IF EXISTS api_keys');
        $this->addSql('DROP TABLE IF EXISTS applications');
        $this->addSql('DROP TABLE IF EXISTS access_requests');

        // Intentionally NOT dropping the pgcrypto extension — other
        // databases on the same Postgres instance (litcal_production,
        // bibleget_dev, etc.) may rely on it. Extensions are
        // per-database in Postgres, but treating "create extension"
        // as best-effort and "drop extension" as a no-op is safer
        // than risking a load-bearing extension going away under us.
    }
}
