-- Migration: Add 'revoked' status to role_requests table
-- This allows administrators to revoke previously approved role requests

-- Drop and recreate the constraint to include 'revoked'
ALTER TABLE role_requests DROP CONSTRAINT IF EXISTS chk_role_request_status;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_role_request_status'
    ) THEN
        ALTER TABLE role_requests ADD CONSTRAINT chk_role_request_status
            CHECK (status IN ('pending', 'approved', 'rejected', 'revoked'));
    END IF;
END $$;
