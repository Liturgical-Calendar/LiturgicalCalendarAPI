-- Add approval status workflow to applications table
-- Run this migration to enable application approval workflow

-- Add status column (without constraint for idempotency)
ALTER TABLE applications
ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending';

-- Add check constraint separately (idempotent)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_application_status'
    ) THEN
        ALTER TABLE applications ADD CONSTRAINT chk_application_status
            CHECK (status IN ('pending', 'approved', 'rejected', 'revoked'));
    END IF;
END $$;

-- Add admin review tracking columns
ALTER TABLE applications
ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(255),
ADD COLUMN IF NOT EXISTS review_notes TEXT,
ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP;

-- Create index for status filtering
CREATE INDEX IF NOT EXISTS idx_applications_status ON applications(status);

-- Backward compatibility: Set existing applications to approved
-- (only affects rows where status is still NULL)
UPDATE applications SET status = 'approved' WHERE status IS NULL;
