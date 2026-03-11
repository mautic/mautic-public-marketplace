# Deploy to a DigitalOcean Droplet via GitHub Actions (Supabase + Auth0)

Deploy the Mautic Marketplace (Supabase + Auth0) to a DigitalOcean droplet via GitHub Actions.

## Deployment workflow
1. GitHub Actions builds a Docker image.
2. The image is transferred to the droplet with `docker save` → `scp` → `docker load`.
3. The droplet deploy script runs the container and manages Nginx + Certbot if domains are configured.
4. Supabase functions/migrations run **only** on tag builds.

## Implementation files
- GitHub Actions workflows:
  - `.github/workflows/deploy-prod.yml`
  - `.github/workflows/deploy-staging.yml`
- Droplet deploy script: `scripts/deploy/remote_deploy.sh`

## Environment and secrets
GitHub Actions secrets (only for deploy + Supabase CLI):
- `DO_SSH_HOST`, `DO_SSH_USER`, `DO_SSH_KEY`, `DO_SSH_PORT` (optional)
- `SUPABASE_ACCESS_TOKEN`, `SUPABASE_PROJECT_ID`
- `SUPABASE_STAGING_ACCESS_TOKEN`, `SUPABASE_STAGING_PROJECT_ID`

Droplet environment files (application config):
- `/etc/marketplace/prod.env` (prod app env vars)
- `/etc/marketplace/staging.env` (staging app env vars)

Droplet host config:
- Docker installed
- Optional: `ufw` firewall and fail2ban
- Nginx reverse proxy for HTTPS and routing

### Where to get secrets
**DigitalOcean (SSH access):**
- Create an SSH key pair locally and add the **public** key to the droplet.
- Store the **private** key in GitHub Actions as `DO_SSH_KEY`.
- `DO_SSH_HOST` is the droplet public IP or hostname.
- `DO_SSH_USER` is the SSH user (e.g., `root` or a dedicated deploy user).

**Auth0:**
- Get values from the Auth0 dashboard for your application and API.
- Store client ID/secret and issuer/tenant URL in GitHub Actions.

**Database / Symfony:**
- `APP_SECRET` should be a long random string.
- `DATABASE_URL` should point to your production database.

**Supabase (hosted):**
- Get the Supabase Project ID (Reference ID) from `Project Settings` > `General Settings`.
- Copy API keys (`anon public`, `service_role`) from `Project Settings` > `API`.
- Store `SUPABASE_URL`, `SUPABASE_ANON_KEY`, and `SUPABASE_SERVICE_ROLE_KEY` in the droplet env files (`/etc/marketplace/prod.env` or `/etc/marketplace/staging.env`).
- Store a Supabase access token as `SUPABASE_ACCESS_TOKEN` in GitHub Actions **only** for running CLI migrations/functions.
- Staging uses a **separate Supabase account/project**. Put its keys in `/etc/marketplace/staging.env`.

## Supabase migrations & functions (tagged releases only)
When a tag is created (e.g., `1.2.release`), the workflow runs from the repo root:
- `supabase link --project-ref <PROJECT_ID>`
- `supabase functions deploy fetch_package`
- `supabase db push`

These steps run only on `refs/tags/*`.

## Prod deploy (manual or tag)
- **Tag push:** runs prod deploy and Supabase migrations/functions.
- **Manual:** GitHub Actions → **Deploy Prod** → Run workflow.

## Droplet setup (one-time)
### Create the droplet
- **OS:** Ubuntu 22.04 LTS
- **Size:** 1 vCPU / 1–2 GB RAM (start with 1 GB)
- **Network:** public IPv4
- **SSH:** add your public key
- **DNS:** create A records pointing to the droplet public IPv4:
  - `marketplace.mautic.org` → `<droplet-ip>`
  - `marketplace-staging.mautic.org` → `<droplet-ip>`

### Install required packages
```bash
ssh root@<droplet-ip>

apt-get update
apt-get install -y docker.io nginx certbot python3-certbot-nginx
systemctl enable --now docker nginx
```

### Droplet environment
Create `/etc/marketplace/deploy.env` (used by the deploy script):
```bash
cat > /etc/marketplace/deploy.env <<'EOF'
APP_DOMAIN=marketplace.mautic.org
APP_DOMAIN_PORT=8080
STAGING_DOMAIN=marketplace-staging.mautic.org
STAGING_DOMAIN_PORT=8081
LETSENCRYPT_EMAIL=ops@mautic.org
EOF
```

Create `/etc/marketplace/prod.env` for production secrets:
```bash
cat > /etc/marketplace/prod.env <<'EOF'
APP_ENV=prod
APP_SECRET=change-me
DATABASE_URL=postgresql://user:pass@host:5432/dbname
SUPABASE_API_BASE=https://marketplace-api.mautic.org
SUPABASE_URL=https://your-prod-project.supabase.co
SUPABASE_ANON_KEY=change-me
SUPABASE_SERVICE_ROLE_KEY=change-me
AUTH0_DOMAIN=your-tenant.us.auth0.com
AUTH0_CLIENT_ID=change-me
AUTH0_CLIENT_SECRET=change-me
EOF
```

Create `/etc/marketplace/staging.env` for staging secrets:
```bash
cat > /etc/marketplace/staging.env <<'EOF'
APP_ENV=prod
APP_SECRET=change-me
DATABASE_URL=postgresql://user:pass@host:5432/dbname
SUPABASE_API_BASE=https://marketplace-api.mautic.org
SUPABASE_URL=https://your-staging-project.supabase.co
SUPABASE_ANON_KEY=change-me
SUPABASE_SERVICE_ROLE_KEY=change-me
AUTH0_DOMAIN=your-tenant.us.auth0.com
AUTH0_CLIENT_ID=change-me
AUTH0_CLIENT_SECRET=change-me
EOF
```

Ensure DNS A records exist for both `marketplace.mautic.org` and `marketplace-staging.mautic.org`.

Ensure the deploy user can read the env files:
```bash
chown deploy:deploy /etc/marketplace/prod.env /etc/marketplace/staging.env
chmod 600 /etc/marketplace/prod.env /etc/marketplace/staging.env
```

Note: if you change env files, you must re-deploy (recreate) the container for changes to take effect. `docker restart` is not enough because `--env-file` is only read at container creation time.

### Reverse proxy notes
- The deploy script manages Nginx + Certbot when domains are set.
- SSL setup runs only if a domain is provided via droplet environment (e.g., `APP_DOMAIN`).

### Deploy user (recommended)
Create a dedicated deploy user and allow the deploy script to run required commands.

```bash
# create user and add ssh key
adduser --disabled-password --gecos "" deploy
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
cat >> /home/deploy/.ssh/authorized_keys <<'EOF'
<paste-public-key-here>
EOF
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
```

Grant access to Docker:
```bash
usermod -aG docker deploy
```

Log out and back in to apply group membership changes:
```bash
exit
# reconnect with ssh
```

Add passwordless sudo for the commands the deploy script uses:
```bash
cat > /etc/sudoers.d/marketplace-deploy <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/docker, /usr/bin/systemctl, /usr/bin/apt-get, /usr/sbin/nginx, /usr/bin/certbot, /usr/bin/tee, /bin/ln, /bin/rm
EOF
chmod 440 /etc/sudoers.d/marketplace-deploy
```

If you use this deploy user, set `DO_SSH_USER=deploy`.

## Production deploys
- **Tag push:** Create a tag `MAJOR.MINOR.<anything>` (e.g., `1.2.3` or `1.2.release`) to deploy prod and run Supabase migrations/functions.
- **Manual:** GitHub Actions → **Deploy Prod** → Run workflow.

## Staging and PR deploys
- Staging deploys are manual only.
- Use GitHub Actions → **Deploy Staging** → set `source`:
  - Branch name (e.g., `main`) to deploy that branch, or
  - PR number to deploy a PR.
- Optional: set `dockerfile_source` to control which Dockerfile is used.
  - Use `same` to match `source` (default).
  - Use a branch name or PR number to override.
- The workflow requires at least one approved review from a user with write access before it will deploy a PR.
- Staging responses include `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` to discourage indexing.
- The staging workflow also runs Supabase functions/migrations against the staging Supabase project.

## Auth0 configuration
Create an Auth0 **Single Page Application** and configure it for staging/prod:

- **Application type:** Single Page Application (SPA)
- **Allowed Callback URLs:**
  - `https://marketplace-staging.mautic.org/auth/callback`
  - `https://marketplace.mautic.org/auth/callback`
- **Allowed Logout URLs:**
  - `https://marketplace-staging.mautic.org/`
  - `https://marketplace.mautic.org/`
- **Allowed Web Origins:**
  - `https://marketplace-staging.mautic.org`
  - `https://marketplace.mautic.org`

Set these env vars in `/etc/marketplace/prod.env` and `/etc/marketplace/staging.env`:
- `AUTH0_DOMAIN` (e.g., `mautic-dev.us.auth0.com`)
- `AUTH0_CLIENT_ID` (SPA client ID)
- `AUTH0_CLIENT_SECRET` is not used by the SPA SDK but kept for parity

## Rollback
- Re-deploy the previous image tag on the droplet.
- If a migration is non-reversible, document manual rollback steps.
