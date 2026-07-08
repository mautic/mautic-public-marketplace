-- Per-vendor Stripe Connect account (paid packages, issue #45). A vendor onboards
-- once via Stripe-hosted Account Links; the resulting connected-account id and its
-- capability flags are stored here and copied onto packages.vendor_stripe_account_id
-- when they publish a paid package.
CREATE TABLE IF NOT EXISTS stripe_connect_accounts (
    auth0_user_id TEXT PRIMARY KEY,
    stripe_account_id TEXT NOT NULL,
    charges_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    payouts_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    details_submitted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Writes go through the service-role key from the marketplace app; lock the table
-- down otherwise, consistent with the other app-owned tables.
ALTER TABLE public.stripe_connect_accounts ENABLE ROW LEVEL SECURITY;
