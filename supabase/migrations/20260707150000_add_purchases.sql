-- Records completed purchases of paid packages (paid packages, issue #45). Written
-- by the Stripe webhook when a Checkout session completes; used to grant the buyer
-- access to the package archive.
CREATE TABLE IF NOT EXISTS purchases (
    id SERIAL PRIMARY KEY,
    auth0_user_id TEXT NOT NULL,
    package_name TEXT NOT NULL REFERENCES packages(name) ON DELETE CASCADE,
    stripe_checkout_session_id TEXT NOT NULL,
    stripe_payment_intent_id TEXT,
    amount NUMERIC(10, 2),
    currency TEXT,
    status TEXT NOT NULL DEFAULT 'completed',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- One completed purchase row per Checkout session (webhooks can be delivered more than once).
CREATE UNIQUE INDEX IF NOT EXISTS purchases_checkout_session_id_key
ON purchases(stripe_checkout_session_id);

CREATE INDEX IF NOT EXISTS purchases_user_package_idx
ON purchases(auth0_user_id, package_name);

ALTER TABLE public.purchases ENABLE ROW LEVEL SECURITY;
