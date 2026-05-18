-- LiturgicalCalendar Database Initialization
-- This script runs on first PostgreSQL startup to create required databases and users.
--
-- NOTE: The passwords below are DEVELOPMENT DEFAULTS ONLY.
-- For production, override them via environment variables in docker-compose.yml
-- (POSTGRES_PASSWORD, and create users with strong generated passwords).

-- Create Zitadel database and user
CREATE USER zitadel WITH PASSWORD 'zitadel';
CREATE DATABASE zitadel OWNER zitadel;
GRANT ALL PRIVILEGES ON DATABASE zitadel TO zitadel;

-- Create OpenFGA database and user (fine-grained authorization engine)
CREATE USER openfga WITH PASSWORD 'openfga_secure_password';
CREATE DATABASE openfga OWNER openfga;
GRANT ALL PRIVILEGES ON DATABASE openfga TO openfga;

-- Create application database and user for LiturgicalCalendar-specific data
CREATE USER litcal WITH PASSWORD 'litcal_secure_password';
CREATE DATABASE litcal OWNER litcal;
GRANT ALL PRIVILEGES ON DATABASE litcal TO litcal;

-- Connect to litcal database to create schema
\c litcal

-- Grant schema permissions
GRANT ALL ON SCHEMA public TO litcal;

-- Enable pgcrypto extension for UUID generation
-- This runs as postgres superuser (which has permissions), but in the litcal database context
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Unified access requests table
-- Bundles a Zitadel role request with fine-grained OpenFGA permissions.
-- On approval: Zitadel role is granted + all OpenFGA tuples are created.
-- On revoke: Zitadel role is removed + all OpenFGA tuples are deleted.
--
-- Granted calendar permissions are managed by OpenFGA relationship tuples.
-- See infrastructure/openfga-model.json and scripts/setup-openfga.sh.
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
);

CREATE INDEX idx_access_requests_status ON access_requests(status);
CREATE INDEX idx_access_requests_user ON access_requests(zitadel_user_id);
CREATE INDEX idx_access_requests_created ON access_requests(created_at);
CREATE INDEX idx_access_requests_sync_status ON access_requests(zitadel_sync_status)
WHERE zitadel_sync_status = 'failed';

-- At most one pending request per (user, role): defense-in-depth against races
-- and direct DB inserts. Application layer also checks via hasPendingRequest().
CREATE UNIQUE INDEX idx_access_requests_unique_pending_user_role
ON access_requests(zitadel_user_id, requested_role)
WHERE status = 'pending';

COMMENT ON TABLE access_requests IS 'Unified role + permission requests — role via Zitadel, permissions via OpenFGA';
COMMENT ON COLUMN access_requests.requested_role IS 'Zitadel role: developer, calendar_editor, test_editor';
COMMENT ON COLUMN access_requests.permissions IS 'JSON array of OpenFGA tuples: [{object_type, object_id, relation}, ...]';
COMMENT ON COLUMN access_requests.status IS 'Status: pending, approved, rejected, revoked';
COMMENT ON COLUMN access_requests.zitadel_sync_status IS 'Zitadel role sync: null (not attempted), pending, synced, failed';

-- Applications (for API developers)
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
);

CREATE INDEX idx_applications_user ON applications(zitadel_user_id);
CREATE INDEX idx_applications_status ON applications(status);

COMMENT ON TABLE applications IS 'Registered applications for API key management';

-- API Keys
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
);

CREATE INDEX idx_api_keys_hash ON api_keys(key_hash);
CREATE INDEX idx_api_keys_prefix ON api_keys(key_prefix);

COMMENT ON TABLE api_keys IS 'API keys for application authentication';
COMMENT ON COLUMN api_keys.key_hash IS 'SHA-256 hash of the API key';
COMMENT ON COLUMN api_keys.key_prefix IS 'First 20 characters for identification';
COMMENT ON COLUMN api_keys.scope IS 'Scope: read, write';

-- Audit log
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
);

CREATE INDEX idx_audit_log_user ON audit_log(zitadel_user_id);
CREATE INDEX idx_audit_log_created ON audit_log(created_at);
CREATE INDEX idx_audit_log_action ON audit_log(action);

COMMENT ON TABLE audit_log IS 'Audit trail for security and compliance';

-- Grant permissions to litcal user on all tables
-- (No sequence grants needed since we use UUID primary keys instead of SERIAL)
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO litcal;

-- Mark the Doctrine baseline migration as already applied so the API's
-- /_ops/migrate endpoint sees nothing to do on first run (which is what
-- we want — the schema above is identical to what
-- src/Migrations/Version20260518120000.php would create).
--
-- We create the tracking table here with the same shape Doctrine
-- creates via `migrations:sync-metadata-storage`, then insert the
-- baseline row. Doctrine on first call will detect the table exists
-- and is up-to-date; nothing fires CREATE TABLE conflicts.
CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    executed_at TIMESTAMP NULL,
    execution_time INTEGER NULL
);
GRANT ALL PRIVILEGES ON doctrine_migration_versions TO litcal;

INSERT INTO doctrine_migration_versions (version, executed_at, execution_time)
VALUES
    ('LiturgicalCalendar\Api\Migrations\Version20260518120000',
     CURRENT_TIMESTAMP, 0)
ON CONFLICT (version) DO NOTHING;
