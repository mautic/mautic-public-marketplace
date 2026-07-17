# Supabase migrations

## Data API grants are explicit (required)

Since Supabase's Data API change (new projects 2026-05-30, existing projects
2026-10-30), tables in the `public` schema are **not** exposed to PostgREST
(`/rest/v1/`) by default. A missing grant surfaces as PostgREST error `42501`
at runtime.

Every migration that creates a table, sequence, or RPC function must grant
access explicitly. Roles used by this app:

- `anon` — public reads and RPC calls (`SupabaseClient::query()` / `rpc()`)
- `service_role` — all writes and private reads (`mutate()` / `queryPrivate()`)
- `authenticated` — not used; do not grant to it without a reason

Template for a new table:

```sql
CREATE TABLE public.your_table (...);

ALTER TABLE public.your_table ENABLE ROW LEVEL SECURITY;
-- add RLS policies as needed

GRANT SELECT ON public.your_table TO anon;                          -- only if publicly readable
GRANT SELECT, INSERT, UPDATE, DELETE ON public.your_table TO service_role;
GRANT USAGE, SELECT ON SEQUENCE public.your_table_id_seq TO service_role;  -- for SERIAL PKs
```

Template for a new RPC function (new name or new signature — `CREATE OR
REPLACE` of an existing signature keeps its grants):

```sql
GRANT EXECUTE ON FUNCTION public.your_function(arg_types) TO anon, service_role;
```

Functions are `SECURITY INVOKER`: helper functions called inside an RPC also
need `EXECUTE` for the calling role.

The baseline grants for everything existing before this convention live in
`20260714120000_explicit_data_api_grants.sql`.
