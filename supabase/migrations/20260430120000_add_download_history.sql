CREATE TABLE IF NOT EXISTS download_history (
    id SERIAL PRIMARY KEY,
    auth0_user_id TEXT NOT NULL,
    package_name TEXT NOT NULL REFERENCES packages(name) ON DELETE CASCADE,
    package_version TEXT,
    downloaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS download_history_auth0_user_id_downloaded_at_idx
ON download_history(auth0_user_id, downloaded_at DESC);

CREATE INDEX IF NOT EXISTS download_history_package_name_idx
ON download_history(package_name);

ALTER TABLE public.download_history ENABLE ROW LEVEL SECURITY;
