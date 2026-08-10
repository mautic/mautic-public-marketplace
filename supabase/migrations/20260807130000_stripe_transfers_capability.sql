-- Tracks whether a vendor's connected account can actually receive a split.
--
-- Destination charges fail with "the account referenced in the 'destination' parameter
-- is missing the required capabilities: transfers" until Stripe activates that specific
-- capability. details_submitted only says the vendor finished the onboarding form, and
-- charges_enabled describes the connected account taking charges directly, which is not
-- what a destination charge uses — a vendor can receive money with charges_enabled false
-- and transfers active. So neither existing flag answers "can this vendor be paid?".
ALTER TABLE public.stripe_connect_accounts
    ADD COLUMN IF NOT EXISTS transfers_enabled BOOLEAN NOT NULL DEFAULT FALSE;

-- Accounts stored before this column existed are re-checked against Stripe on the next
-- onboarding return or account.updated webhook, so FALSE is the safe starting point.
