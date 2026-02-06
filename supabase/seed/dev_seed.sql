INSERT INTO packages (
    name,
    description,
    type,
    repository,
    downloads,
    favers,
    url,
    displayname,
    latest_mautic_support,
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
    true,
    NOW()
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    latest_mautic_support = EXCLUDED.latest_mautic_support;

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
    latest_mautic_support,
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
    true,
    NOW()
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    latest_mautic_support = EXCLUDED.latest_mautic_support;

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
    latest_mautic_support,
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
    true,
    NOW()
)
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    type = EXCLUDED.type,
    repository = EXCLUDED.repository,
    downloads = EXCLUDED.downloads,
    favers = EXCLUDED.favers,
    url = EXCLUDED.url,
    displayname = EXCLUDED.displayname,
    latest_mautic_support = EXCLUDED.latest_mautic_support;

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
