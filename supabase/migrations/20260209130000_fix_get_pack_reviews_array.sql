-- Fix get_pack to return reviews as a JSON array instead of object.
CREATE OR REPLACE FUNCTION get_pack(packag_name TEXT)
RETURNS JSON AS $$
DECLARE
    package_data JSON;
BEGIN
    SELECT jsonb_build_object(
        'package', jsonb_build_object(
            'name', p.name,
            'displayname', p.displayname,
            'description', p.description,
            'time', p.time,
            'maintainers', p.maintainers,
            'versions', (
                SELECT jsonb_object_agg(
                    v.version,
                    jsonb_build_object(
                        'name', v.package_name,
                        'description', v.description,
                        'keywords', v.keywords,
                        'homepage', v.homepage,
                        'version', v.version,
                        'version_normalized', v.version_normalized,
                        'license', v.license,
                        'source', v.source,
                        'dist', v.dist,
                        'type', v.type,
                        'authors', v.authors,
                        'support', v.support,
                        'funding', v.funding,
                        'time', v.time,
                        'extra', v.extra,
                        'require', v.require,
                        'smv', v.smv
                    )
                )
                FROM versions v WHERE v.package_name = p.name
            ),
            'reviews', (
                SELECT COALESCE(jsonb_agg(
                    jsonb_build_object(
                        'name', r."user",
                        'rating', r.rating,
                        'review', r.review,
                        'picture', r.picture,
                        'created_at', r.created_at
                    )
                    ORDER BY r.created_at DESC
                ), '[]'::jsonb)
                FROM reviews r WHERE r."objectId" = p.name
            ),
            'type', p.type,
            'repository', p.repository,
            'github_stars', p.github_stars,
            'github_watchers', p.github_watchers,
            'github_forks', p.github_forks,
            'github_open_issues', p.github_open_issues,
            'language', p.language,
            'dependents', p.dependents,
            'suggesters', p.suggesters,
            'downloads', p.downloads,
            'favers', p.favers
        )
    ) INTO package_data
    FROM packages p
    WHERE p.name = packag_name;

    RETURN package_data;
END;
$$ LANGUAGE plpgsql STABLE;
