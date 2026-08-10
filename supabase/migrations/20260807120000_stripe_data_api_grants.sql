-- Data API grants for the paid-package tables, plus column-level read access on
-- packages so the Stripe references stay out of reach of the public anon role.
--
-- 20260714120000_explicit_data_api_grants.sql lists every table by name and was
-- written before stripe_connect_accounts and purchases existed, so those two ended
-- up with no grants at all: onboarding writes and the webhook's purchase insert
-- would both fail with "permission denied" on any project provisioned under the
-- current Supabase defaults.

-- Both tables are private — only the app's service-role key ever touches them.
GRANT SELECT, INSERT, UPDATE, DELETE
  ON TABLE public.stripe_connect_accounts, public.purchases
  TO service_role;

REVOKE ALL ON TABLE public.stripe_connect_accounts, public.purchases FROM anon, authenticated;

-- purchases.id is a SERIAL, so inserts need the backing sequence.
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO service_role;

-- packages holds the Stripe product/price/connected-account references alongside
-- the public catalogue columns. RLS is row-level, so the table-wide SELECT grant
-- anon holds today also hands out those three columns to anyone with the anon key.
-- Replace it with a column-level grant covering everything else. The column list is
-- built from the catalogue so none can be missed; columns added later need their own
-- grant, the same as every other object in this file.
DO $$
DECLARE
    readable_columns TEXT;
BEGIN
    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position)
    INTO readable_columns
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'packages'
      AND column_name NOT IN ('stripe_product_id', 'stripe_price_id', 'vendor_stripe_account_id');

    EXECUTE 'REVOKE SELECT ON TABLE public.packages FROM anon';
    EXECUTE format('GRANT SELECT (%s) ON TABLE public.packages TO anon', readable_columns);
END
$$;
