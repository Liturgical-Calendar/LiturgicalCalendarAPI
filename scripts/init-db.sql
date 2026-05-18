-- LiturgicalCalendar Database Initialization
-- This script runs on first PostgreSQL startup to create required databases and users.
--
-- This is a BOOTSTRAP-ONLY script. Application-table DDL (access_requests,
-- applications, api_keys, audit_log) lives in Doctrine migrations under
-- src/Migrations/ and is applied via `composer db:migrate` (or by hitting
-- `POST /_ops/migrate` once the API server is running). See CLAUDE.md →
-- "Local Development Bootstrap".
--
-- What this script does NOT do:
--   * Create application tables (handled by Version20260518120000 and later)
--   * Pre-mark migrations as applied (Doctrine records that itself)
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

-- Enable pgcrypto extension for UUID generation (gen_random_uuid()).
-- Runs as postgres superuser inside the litcal database; required by
-- the baseline migration's CREATE TABLE ... DEFAULT gen_random_uuid().
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Create the Doctrine migrations tracking table empty so the litcal
-- user has guaranteed write access on first migrate. (Doctrine's
-- sync-metadata-storage would create it on its own, but doing it here
-- keeps ownership/permissions explicit and matches the shape Doctrine
-- expects.) No baseline INSERT — the first /_ops/migrate run applies
-- Version20260518120000 normally and Doctrine records the row itself.
CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    executed_at TIMESTAMP NULL,
    execution_time INTEGER NULL
);
GRANT ALL PRIVILEGES ON doctrine_migration_versions TO litcal;
