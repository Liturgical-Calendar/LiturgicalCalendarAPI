-- Migration: Add Zitadel sync status tracking to role_requests table
-- This allows decoupling DB transactions from external Zitadel API calls

-- Add zitadel_sync_status column to track sync state
ALTER TABLE role_requests
ADD COLUMN IF NOT EXISTS zitadel_sync_status VARCHAR(20) DEFAULT NULL;

-- Add check constraint for valid sync statuses (idempotent)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_zitadel_sync_status'
    ) THEN
        ALTER TABLE role_requests ADD CONSTRAINT chk_zitadel_sync_status
            CHECK (zitadel_sync_status IS NULL OR zitadel_sync_status IN ('pending', 'synced', 'failed'));
    END IF;
END $$;

-- Add column to store sync error message if sync fails
ALTER TABLE role_requests
ADD COLUMN IF NOT EXISTS zitadel_sync_error TEXT DEFAULT NULL;

-- Create index for finding requests that need sync retry
CREATE INDEX IF NOT EXISTS idx_role_requests_sync_status ON role_requests(zitadel_sync_status)
WHERE zitadel_sync_status = 'failed';
