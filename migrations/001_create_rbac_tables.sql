-- RBAC Tables for LiturgicalCalendar API
-- Run this migration to set up role-based access control

-- Enable pgcrypto extension for UUID generation
-- Note: This requires superuser privileges. If this fails, run as postgres:
-- psql -U postgres -d <dbname> -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Role requests table
-- Stores pending role assignment requests from users
CREATE TABLE IF NOT EXISTS role_requests (
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
    reviewed_at TIMESTAMP,
    CONSTRAINT chk_requested_role CHECK (requested_role IN ('developer', 'calendar_editor', 'test_editor')),
    CONSTRAINT chk_role_request_status CHECK (status IN ('pending', 'approved', 'rejected'))
);

CREATE INDEX IF NOT EXISTS idx_role_requests_status ON role_requests(status);
CREATE INDEX IF NOT EXISTS idx_role_requests_user ON role_requests(zitadel_user_id);
CREATE INDEX IF NOT EXISTS idx_role_requests_created ON role_requests(created_at);

-- Calendar-specific permissions table
-- For fine-grained access to specific national/diocesan calendars
CREATE TABLE IF NOT EXISTS user_calendar_permissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id VARCHAR(255) NOT NULL,
    calendar_type VARCHAR(20) NOT NULL,     -- 'national', 'diocesan', 'widerregion'
    calendar_id VARCHAR(50) NOT NULL,       -- 'USA', 'BOSTON', 'Americas'
    permission VARCHAR(10) NOT NULL,        -- 'read', 'write'
    granted_by VARCHAR(255),                -- Zitadel user ID of admin who granted
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(zitadel_user_id, calendar_type, calendar_id, permission),
    CONSTRAINT chk_calendar_type CHECK (calendar_type IN ('national', 'diocesan', 'widerregion')),
    CONSTRAINT chk_permission CHECK (permission IN ('read', 'write'))
);

CREATE INDEX IF NOT EXISTS idx_user_calendar_perms ON user_calendar_permissions(zitadel_user_id, calendar_type);

-- Permission requests table (for calendar-specific permissions)
CREATE TABLE IF NOT EXISTS permission_requests (
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
    reviewed_at TIMESTAMP,
    CONSTRAINT chk_perm_req_calendar_type CHECK (calendar_type IN ('national', 'diocesan', 'widerregion')),
    CONSTRAINT chk_perm_req_status CHECK (status IN ('pending', 'approved', 'rejected'))
);

CREATE INDEX IF NOT EXISTS idx_permission_requests_status ON permission_requests(status);
CREATE INDEX IF NOT EXISTS idx_permission_requests_user ON permission_requests(zitadel_user_id);

-- Applications table (for API developers)
CREATE TABLE IF NOT EXISTS applications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    zitadel_user_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    website VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_applications_user ON applications(zitadel_user_id);

-- API Keys table
CREATE TABLE IF NOT EXISTS api_keys (
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_scope CHECK (scope IN ('read', 'write')),
    CONSTRAINT chk_rate_limit CHECK (rate_limit_per_hour > 0)
);

CREATE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys(key_hash);
CREATE INDEX IF NOT EXISTS idx_api_keys_prefix ON api_keys(key_prefix);

-- Audit log table
CREATE TABLE IF NOT EXISTS audit_log (
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

CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log(zitadel_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action);
