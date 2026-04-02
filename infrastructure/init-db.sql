-- LiturgicalCalendar Database Initialization
-- This script runs on first PostgreSQL startup to create required databases and users.

-- Create Zitadel database and user
CREATE USER zitadel WITH PASSWORD 'zitadel';
CREATE DATABASE zitadel OWNER zitadel;
GRANT ALL PRIVILEGES ON DATABASE zitadel TO zitadel;

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

-- Role requests table
-- Stores pending role assignment requests from users
CREATE TABLE role_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    user_name VARCHAR(255),
    requested_role VARCHAR(50) NOT NULL,  -- 'developer', 'calendar_editor', 'test_editor'
    justification TEXT,
    status VARCHAR(20) DEFAULT 'pending',  -- 'pending', 'approved', 'rejected'
    reviewed_by VARCHAR(255),              -- Admin's Zitadel user ID
    review_notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP
);

CREATE INDEX idx_role_requests_status ON role_requests(status);
CREATE INDEX idx_role_requests_user ON role_requests(zitadel_user_id);
CREATE INDEX idx_role_requests_created ON role_requests(created_at);

COMMENT ON TABLE role_requests IS 'Pending role assignment requests from users';
COMMENT ON COLUMN role_requests.requested_role IS 'Role: developer, calendar_editor, test_editor';
COMMENT ON COLUMN role_requests.status IS 'Status: pending, approved, rejected';

-- Calendar-specific permissions (beyond Zitadel roles)
CREATE TABLE user_calendar_permissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id VARCHAR(255) NOT NULL,
    calendar_type VARCHAR(20) NOT NULL,
    calendar_id VARCHAR(50) NOT NULL,
    permission VARCHAR(10) NOT NULL,
    granted_by VARCHAR(255),
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(zitadel_user_id, calendar_type, calendar_id, permission)
);

CREATE INDEX idx_user_calendar_perms ON user_calendar_permissions(zitadel_user_id, calendar_type);

COMMENT ON TABLE user_calendar_permissions IS 'Calendar-specific permissions beyond Zitadel roles';
COMMENT ON COLUMN user_calendar_permissions.zitadel_user_id IS 'Zitadel user ID (sub claim from OIDC token)';
COMMENT ON COLUMN user_calendar_permissions.calendar_type IS 'Type: national, diocesan, widerregion';
COMMENT ON COLUMN user_calendar_permissions.calendar_id IS 'Calendar identifier: USA, BOSTON, Americas, etc.';
COMMENT ON COLUMN user_calendar_permissions.permission IS 'Permission level: read, write';

-- Permission requests (approval workflow)
CREATE TABLE permission_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    user_name VARCHAR(255),
    calendar_type VARCHAR(20) NOT NULL,
    calendar_id VARCHAR(50) NOT NULL,
    justification TEXT,
    credentials TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    reviewed_by VARCHAR(255),
    review_notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP
);

CREATE INDEX idx_permission_requests_status ON permission_requests(status);
CREATE INDEX idx_permission_requests_user ON permission_requests(zitadel_user_id);

COMMENT ON TABLE permission_requests IS 'Permission request workflow for calendar access';
COMMENT ON COLUMN permission_requests.status IS 'Status: pending, approved, rejected';

-- Applications (for API developers)
CREATE TABLE applications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    website VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_applications_user ON applications(zitadel_user_id);

COMMENT ON TABLE applications IS 'Registered applications for API key management';

-- API Keys
CREATE TABLE api_keys (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id UUID NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    key_hash VARCHAR(255) UNIQUE NOT NULL,
    key_prefix VARCHAR(20) NOT NULL,
    name VARCHAR(100),
    scope VARCHAR(20) DEFAULT 'read',
    rate_limit_per_hour INTEGER DEFAULT 1000,
    is_active BOOLEAN DEFAULT TRUE,
    last_used_at TIMESTAMP,
    expires_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_api_keys_hash ON api_keys(key_hash);
CREATE INDEX idx_api_keys_prefix ON api_keys(key_prefix);

COMMENT ON TABLE api_keys IS 'API keys for application authentication';
COMMENT ON COLUMN api_keys.key_hash IS 'SHA-256 hash of the API key';
COMMENT ON COLUMN api_keys.key_prefix IS 'First 8 characters for identification';
COMMENT ON COLUMN api_keys.scope IS 'Scope: read, read_write';

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
