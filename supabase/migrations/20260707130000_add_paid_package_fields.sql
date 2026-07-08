-- Paid packages (issue #45): pricing model, currency and Stripe Connect references.
--
-- The existing packages.price column (NUMERIC(10,2), added with the upload form) stays
-- the decimal amount the whole app already works with; conversion to Stripe's integer
-- "cents" happens at the Stripe API boundary, not in storage. Here we only add the
-- surrounding metadata the marketplace needs to tell free from paid packages.
ALTER TABLE public.packages
    ADD COLUMN IF NOT EXISTS pricing_model TEXT NOT NULL DEFAULT 'free',
    ADD COLUMN IF NOT EXISTS currency TEXT,
    ADD COLUMN IF NOT EXISTS stripe_product_id TEXT,
    ADD COLUMN IF NOT EXISTS stripe_price_id TEXT,
    ADD COLUMN IF NOT EXISTS vendor_stripe_account_id TEXT;

-- Only ever 'free' or 'paid'.
ALTER TABLE public.packages DROP CONSTRAINT IF EXISTS packages_pricing_model_check;
ALTER TABLE public.packages
    ADD CONSTRAINT packages_pricing_model_check CHECK (pricing_model IN ('free', 'paid'));

-- Keep pricing_model consistent with any price already stored.
UPDATE public.packages
    SET pricing_model = 'paid'
    WHERE price IS NOT NULL AND price > 0;
