-- Adds columns required by the multi-step upload form (spec: Upload package page).
-- Spec sections: Step 2 (Basics), Step 3 (Details), Step 4 (Pricing & legal),
-- and submission status tracker (Pending/Published).

ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS headline VARCHAR(60),
    ADD COLUMN IF NOT EXISTS languages JSONB,
    ADD COLUMN IF NOT EXISTS works_with JSONB,
    ADD COLUMN IF NOT EXISTS banner_url TEXT,
    ADD COLUMN IF NOT EXISTS gallery JSONB,
    ADD COLUMN IF NOT EXISTS price NUMERIC(10, 2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS license_type TEXT,
    ADD COLUMN IF NOT EXISTS status TEXT DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS github_url TEXT,
    ADD COLUMN IF NOT EXISTS packagist_url TEXT,
    ADD COLUMN IF NOT EXISTS documentation TEXT,
    ADD COLUMN IF NOT EXISTS ip_ownership_accepted BOOLEAN DEFAULT FALSE;

ALTER TABLE packages
    DROP CONSTRAINT IF EXISTS packages_status_check;

ALTER TABLE packages
    ADD CONSTRAINT packages_status_check CHECK (status IN ('pending', 'published'));

CREATE INDEX IF NOT EXISTS packages_status_idx ON packages(status);

-- Storage bucket for banner + gallery images. Public read so URLs in `banner_url`
-- and `gallery` resolve directly without signed URLs.
INSERT INTO storage.buckets (id, name, public)
VALUES ('package-media', 'package-media', TRUE)
ON CONFLICT (id) DO NOTHING;
