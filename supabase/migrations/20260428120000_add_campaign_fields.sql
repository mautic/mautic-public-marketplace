-- Add campaign_uuid to packages for linking to Mautic campaign UUID
ALTER TABLE packages ADD COLUMN IF NOT EXISTS campaign_uuid TEXT;

-- Add unique constraint on campaign_uuid (partial index, only where not null)
CREATE UNIQUE INDEX IF NOT EXISTS packages_campaign_uuid_unique
    ON packages (campaign_uuid)
    WHERE campaign_uuid IS NOT NULL;

