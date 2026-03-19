Project AI Instructions

Purpose
- Build the public Mautic Marketplace app in Symfony using Supabase middleware and Auth0.

Stack
- Symfony (latest), PHP (confirm version in composer).
- DDEV for local dev; include Supabase local stack as DDEV service(s).
- Production Docker: single container, no workers.
- UI: Carbon Design System; Twig templates; mostly vanilla JS.

Key Integrations
- Supabase middleware API for package list/search/filter/detail and reviews.
- Auth0 for authentication and review submission.

Quality
- PHP CS Fixer with Symfony rules, PHPStan, Rector.
- Functional tests for critical flows (list, detail, auth, review).

Conventions
- Prefer small, incremental changes with clear commit boundaries.
- Keep templates accessible and responsive.
- Avoid heavy JS frameworks unless necessary.

Commands
- Execute using ddev exec
