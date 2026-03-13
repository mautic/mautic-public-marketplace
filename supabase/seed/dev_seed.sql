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
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/example-plugin',
    'Example version for local development.',
    '1.0.0',
    '1.0.0.0',
    'mautic-plugin',
    '^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO NOTHING;

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
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/alpha-plugin',
    'Alpha version.',
    '0.1.0',
    '0.1.0.0',
    'mautic-plugin',
    '^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO NOTHING;

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
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/zebra-theme',
    'Zebra version.',
    '2.0.0',
    '2.0.0.0',
    'mautic-theme',
    '^4.4 || ^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO NOTHING;

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
    version,
    version_normalized,
    type,
    smv,
    time
)
VALUES (
    'mautic/welcome-campaign',
    'Welcome campaign v1.',
    '1.0.0',
    '1.0.0.0',
    'mautic-resource',
    '^5.0',
    NOW()
)
ON CONFLICT (package_name, version) DO NOTHING;
