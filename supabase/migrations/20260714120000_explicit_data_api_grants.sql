-- Explicit Data API grants for all tables, sequences and RPC functions.
-- Supabase no longer exposes "public" schema objects to the Data API by
-- default, so every object the app reads or writes through PostgREST needs
-- an explicit GRANT. Roles: anon = public reads and RPC calls,
-- service_role = writes and private reads. "authenticated" is not used.

GRANT USAGE ON SCHEMA public TO anon, service_role;

-- Publicly readable tables (row visibility still governed by RLS policies)
GRANT SELECT ON TABLE public.packages, public.versions, public.reviews TO anon;

GRANT SELECT, INSERT, UPDATE, DELETE
  ON TABLE public.packages, public.versions, public.reviews, public.download_history
  TO service_role;

-- download_history is private: environments provisioned under the old
-- defaults gave anon/authenticated table access, so revoke it explicitly
REVOKE ALL ON TABLE public.download_history FROM anon, authenticated;

-- Inserts into SERIAL primary keys need access to the backing sequences
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO service_role;

-- RPC functions are SECURITY INVOKER, so the calling role also needs
-- EXECUTE on any helper functions they use
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO anon, service_role;
