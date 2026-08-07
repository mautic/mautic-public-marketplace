-- Anonymous (not-logged-in) visitors can download packages too; their history
-- rows carry no user id, so auth0_user_id must be nullable.
ALTER TABLE public.download_history ALTER COLUMN auth0_user_id DROP NOT NULL;
