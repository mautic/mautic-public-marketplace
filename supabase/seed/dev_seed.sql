ALTER TABLE versions ADD COLUMN IF NOT EXISTS validation_errors TEXT DEFAULT NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'reviews' AND column_name = 'user_id'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'reviews' AND column_name = 'auth0_user_id'
    ) THEN
        EXECUTE 'ALTER TABLE reviews RENAME COLUMN user_id TO auth0_user_id';
    END IF;
END $$;

INSERT INTO packages (
    name,
    description,
    type,
    repository,
    downloads,
    favers,
    url,
    displayname,
    maintainers,
    latest_mautic_support,
    time,
    created_at
)
VALUES (
    'mautic/example-plugin',
    'Example package for local development.',
    'mautic-plugin',
    'https://github.com/mautic/example-plugin',
    '{"total": 1234}'::jsonb,
    10,
    'https://packagist.org/packages/mautic/example-plugin',
    'Example Plugin',
    '[{"name": "escopecz", "avatar_url": "https://www.gravatar.com/avatar/06d22001?d=identicon"}]'::jsonb,
    true,
    NOW() - INTERVAL '5 days',
    NOW() - INTERVAL '5 days'
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    maintainers = EXCLUDED.maintainers,
    latest_mautic_support = EXCLUDED.latest_mautic_support,
    time = EXCLUDED.time;

INSERT INTO versions (
    package_name,
    description,
    keywords,
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/example-plugin',
    'Example version for local development.',
    '["example", "plugin", "local-development"]'::jsonb,
    '1.0.0',
    '1.0.0.0',
    'mautic-plugin',
    '^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO UPDATE SET
    keywords = EXCLUDED.keywords;

INSERT INTO packages (
    name,
    description,
    type,
    repository,
    downloads,
    favers,
    url,
    displayname,
    maintainers,
    latest_mautic_support,
    time,
    created_at
)
VALUES (
    'mautic/alpha-plugin',
    'Alpha plugin for sorting.',
    'mautic-plugin',
    'https://github.com/mautic/alpha-plugin',
    '{"total": 10}'::jsonb,
    2,
    'https://packagist.org/packages/mautic/alpha-plugin',
    'Alpha Plugin',
    '[{"name": "rcheesley", "avatar_url": "https://www.gravatar.com/avatar/bc9131bb?d=identicon"}]'::jsonb,
    true,
    NOW() - INTERVAL '60 days',
    NOW() - INTERVAL '60 days'
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    maintainers = EXCLUDED.maintainers,
    latest_mautic_support = EXCLUDED.latest_mautic_support,
    time = EXCLUDED.time;

INSERT INTO versions (
    package_name,
    description,
    keywords,
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/alpha-plugin',
    'Alpha version.',
    '["alpha", "plugin", "sorting"]'::jsonb,
    '0.1.0',
    '0.1.0.0',
    'mautic-plugin',
    '^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO UPDATE SET
    keywords = EXCLUDED.keywords;

INSERT INTO packages (
    name,
    description,
    type,
    repository,
    downloads,
    favers,
    url,
    displayname,
    maintainers,
    latest_mautic_support,
    time,
    created_at
)
VALUES (
    'mautic/zebra-theme',
    'Zebra theme for sorting.',
    'mautic-theme',
    'https://github.com/mautic/zebra-theme',
    '{"total": 5000}'::jsonb,
    5,
    'https://packagist.org/packages/mautic/zebra-theme',
    'Zebra Theme',
    '[{"name": "escopecz", "avatar_url": "https://www.gravatar.com/avatar/06d22001?d=identicon"}]'::jsonb,
    true,
    NOW() - INTERVAL '200 days',
    NOW() - INTERVAL '200 days'
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    maintainers = EXCLUDED.maintainers,
    latest_mautic_support = EXCLUDED.latest_mautic_support,
    time = EXCLUDED.time;

INSERT INTO versions (
    package_name,
    description,
    keywords,
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/zebra-theme',
    'Zebra version.',
    '["theme", "zebra", "responsive"]'::jsonb,
    '2.0.0',
    '2.0.0.0',
    'mautic-theme',
    '^4.4 || ^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO UPDATE SET
    keywords = EXCLUDED.keywords;

INSERT INTO packages (
    name,
    description,
    type,
    repository,
    downloads,
    favers,
    url,
    displayname,
    maintainers,
    latest_mautic_support,
    time,
    created_at
)
VALUES (
    'mautic/welcome-campaign',
    'Welcome drip campaign resource template.',
    'mautic-resource',
    'https://github.com/mautic/welcome-campaign',
    '{"total": 500}'::jsonb,
    3,
    'https://packagist.org/packages/mautic/welcome-campaign',
    'Welcome Campaign',
    '[{"name": "rcheesley", "avatar_url": "https://www.gravatar.com/avatar/bc9131bb?d=identicon"}]'::jsonb,
    true,
    NOW() - INTERVAL '10 days',
    NOW() - INTERVAL '10 days'
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    maintainers = EXCLUDED.maintainers,
    latest_mautic_support = EXCLUDED.latest_mautic_support,
    time = EXCLUDED.time;

INSERT INTO versions (
    package_name,
    description,
    keywords,
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/welcome-campaign',
    'Welcome campaign v1.',
    '["campaign", "welcome", "resource", "automation"]'::jsonb,
    '1.0.0',
    '1.0.0.0',
    'mautic-resource',
    '^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO UPDATE SET
    keywords = EXCLUDED.keywords;

INSERT INTO packages (
    name,
    description,
    type,
    repository,
    github_stars,
    github_watchers,
    github_forks,
    github_open_issues,
    language,
    dependents,
    suggesters,
    downloads,
    favers,
    url,
    displayname,
    maintainers,
    latest_mautic_support,
    time,
    created_at
)
VALUES (
    'mautic/customer-reengagement-campaign',
    E'Prebuilt lifecycle campaign for re-engaging inactive contacts with a win-back email series, SMS follow-up, and CRM-driven segmentation.\n\nIncludes import-ready assets for emails, segments, and decision rules so the detail page has realistic resource content to render.',
    'mautic-resource',
    'https://github.com/mautic/customer-reengagement-campaign',
    48,
    12,
    9,
    2,
    'English',
    14,
    6,
    '{"total": 2847}'::jsonb,
    19,
    'https://packagist.org/packages/mautic/customer-reengagement-campaign',
    'Customer Re-engagement Campaign',
    '[{"name": "mautic", "avatar_url": "https://www.gravatar.com/avatar/06d22001?d=identicon"}, {"name": "community-team", "avatar_url": "https://www.gravatar.com/avatar/bc9131bb?d=identicon"}]'::jsonb,
    true,
    NOW() - INTERVAL '3 days',
    NOW() - INTERVAL '21 days'
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    github_stars = EXCLUDED.github_stars,
    github_watchers = EXCLUDED.github_watchers,
    github_forks = EXCLUDED.github_forks,
    github_open_issues = EXCLUDED.github_open_issues,
    language = EXCLUDED.language,
    dependents = EXCLUDED.dependents,
    suggesters = EXCLUDED.suggesters,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    maintainers = EXCLUDED.maintainers,
    latest_mautic_support = EXCLUDED.latest_mautic_support,
    time = EXCLUDED.time;

INSERT INTO versions (
    package_name,
    description,
    keywords,
    homepage,
    version,
    version_normalized,
    license,
    authors,
    source,
    dist,
    type,
    support,
    funding,
    time,
    extra,
    require,
    smv,
    validation_errors
)
VALUES (
    'mautic/customer-reengagement-campaign',
    'Initial stable release of the re-engagement automation campaign.',
    '["campaign", "resource", "automation", "sms", "email"]'::jsonb,
    'https://github.com/mautic/customer-reengagement-campaign',
    '1.1.0',
    '1.1.0.0',
    '["GPL-3.0-or-later"]'::jsonb,
    '[{"name": "Mautic Community Team"}]'::jsonb,
    '{"type": "git", "url": "https://github.com/mautic/customer-reengagement-campaign.git", "reference": "8d3f2aa"}'::jsonb,
    '{"type": "zip", "url": "https://example.test/customer-reengagement-campaign-1.1.0.zip", "reference": "8d3f2aa"}'::jsonb,
    'mautic-resource',
    '{"issues": "https://github.com/mautic/customer-reengagement-campaign/issues"}'::jsonb,
    '[{"type": "github", "url": "https://github.com/sponsors/mautic"}]'::jsonb,
    NOW() - INTERVAL '14 days',
    '{"mautic_resource_type": "campaign", "import_format": "zip"}'::jsonb,
    '{"mautic/core-lib": "^5.0"}'::jsonb,
    '^5.0',
    NULL
)
ON CONFLICT (package_name, version) DO UPDATE SET
    description = EXCLUDED.description,
    keywords = EXCLUDED.keywords,
    homepage = EXCLUDED.homepage,
    version_normalized = EXCLUDED.version_normalized,
    license = EXCLUDED.license,
    authors = EXCLUDED.authors,
    source = EXCLUDED.source,
    dist = EXCLUDED.dist,
    type = EXCLUDED.type,
    support = EXCLUDED.support,
    funding = EXCLUDED.funding,
    time = EXCLUDED.time,
    extra = EXCLUDED.extra,
    require = EXCLUDED.require,
    smv = EXCLUDED.smv,
    validation_errors = EXCLUDED.validation_errors;

INSERT INTO versions (
    package_name,
    description,
    keywords,
    homepage,
    version,
    version_normalized,
    license,
    authors,
    source,
    dist,
    type,
    support,
    funding,
    time,
    extra,
    require,
    smv,
    validation_errors
)
VALUES (
    'mautic/customer-reengagement-campaign',
    'Adds a branching win-back path and refreshed sample content for testing resource detail pages.',
    '["campaign", "resource", "automation", "re-engagement", "win-back"]'::jsonb,
    'https://github.com/mautic/customer-reengagement-campaign',
    '1.2.0',
    '1.2.0.0',
    '["GPL-3.0-or-later"]'::jsonb,
    '[{"name": "Mautic Community Team"}]'::jsonb,
    '{"type": "git", "url": "https://github.com/mautic/customer-reengagement-campaign.git", "reference": "a91bc4e"}'::jsonb,
    '{"type": "zip", "url": "https://example.test/customer-reengagement-campaign-1.2.0.zip", "reference": "a91bc4e"}'::jsonb,
    'mautic-resource',
    '{"issues": "https://github.com/mautic/customer-reengagement-campaign/issues", "docs": "https://example.test/docs/customer-reengagement-campaign"}'::jsonb,
    '[{"type": "github", "url": "https://github.com/sponsors/mautic"}]'::jsonb,
    NOW() - INTERVAL '3 days',
    '{"mautic_resource_type": "campaign", "import_format": "zip", "estimated_import_time": "2 minutes"}'::jsonb,
    '{"mautic/core-lib": "^5.0 || ^6.0"}'::jsonb,
    '^5.0 || ^6.0',
    NULL
)
ON CONFLICT (package_name, version) DO UPDATE SET
    description = EXCLUDED.description,
    keywords = EXCLUDED.keywords,
    homepage = EXCLUDED.homepage,
    version_normalized = EXCLUDED.version_normalized,
    license = EXCLUDED.license,
    authors = EXCLUDED.authors,
    source = EXCLUDED.source,
    dist = EXCLUDED.dist,
    type = EXCLUDED.type,
    support = EXCLUDED.support,
    funding = EXCLUDED.funding,
    time = EXCLUDED.time,
    extra = EXCLUDED.extra,
    require = EXCLUDED.require,
    smv = EXCLUDED.smv,
    validation_errors = EXCLUDED.validation_errors;

DELETE FROM reviews
WHERE auth0_user_id IN ('local-seed-qa-demo', 'local-seed-automation-tester');

INSERT INTO reviews (
    "user",
    rating,
    review,
    "objectId",
    picture,
    auth0_user_id
)
VALUES
(
    'QA Demo User',
    5,
    'Useful seed package for validating mautic-resource cards, detail content, and review display locally.',
    'mautic/customer-reengagement-campaign',
    'https://www.gravatar.com/avatar/06d22001?d=identicon',
    'local-seed-qa-demo'
),
(
    'Automation Tester',
    4,
    'Import flow looks realistic enough for frontend checks and filtering tests.',
    'mautic/customer-reengagement-campaign',
    'https://www.gravatar.com/avatar/bc9131bb?d=identicon',
    'local-seed-automation-tester'
);
