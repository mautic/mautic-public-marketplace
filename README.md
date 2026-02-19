# Mautic Marketplace (Public)

Symfony app for the public Mautic Marketplace, backed by the Supabase middleware API and Auth0.

## Local development (DDEV + Supabase)
Run the automated setup:
- `bin/dev-setup`

This starts DDEV, waits for Supabase services, applies migrations, and runs the `fetch_package` function to seed packages.

Local URLs:
- Kong API: http://localhost:8000
- Studio: http://localhost:3000

## Supabase project config (cloud)
The Supabase CLI scripts are still available for cloud projects:
- `composer supabase:link`
- `composer supabase:db:push`
- `composer supabase:functions:deploy`

## Tooling
- PHP CS Fixer: `composer cs:check` / `composer cs:fix`
- PHPStan: `composer phpstan`
- Rector: `composer rector`
- Tests: `composer test`

## Production Docker
- Build: `docker build -t mautic-marketplace .`
- Run: `docker compose up -d`

## Deployment
- DigitalOcean + GitHub Actions: `docs/deploy-digitalocean.md`

## Reference Links
- Existing marketplace UI in Mautic admin: https://github.com/mautic/mautic/tree/7.x/app/bundles/MarketplaceBundle
- Supabase middleware integration PR: https://github.com/mautic/mautic/pull/15500
- Supabase project (API + DB layer): https://github.com/mautic/mautic-marketplace
- Auth0 domain fix approach: https://github.com/mautic/marketplace-frontend
