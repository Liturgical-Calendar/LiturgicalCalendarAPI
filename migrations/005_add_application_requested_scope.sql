-- Add requested_scope column to applications table
-- Allows developers to request read-only or read+write access
-- which restricts API key generation capabilities after approval

-- Add requested_scope column with default (nullable initially for backfill)
ALTER TABLE applications
ADD COLUMN IF NOT EXISTS requested_scope VARCHAR(10) DEFAULT 'read';

-- Backfill: Set existing NULL values to 'read' (safe default)
UPDATE applications SET requested_scope = 'read' WHERE requested_scope IS NULL;

-- Add NOT NULL constraint after backfill (idempotent check)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'applications'
          AND column_name = 'requested_scope'
          AND is_nullable = 'YES'
    ) THEN
        ALTER TABLE applications ALTER COLUMN requested_scope SET NOT NULL;
    END IF;
END $$;

-- Add check constraint separately (idempotent, scoped to applications table)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_application_requested_scope'
          AND conrelid = 'applications'::regclass
    ) THEN
        ALTER TABLE applications ADD CONSTRAINT chk_application_requested_scope
            CHECK (requested_scope IN ('read', 'write'));
    END IF;
END $$;
