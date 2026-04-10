DROP FUNCTION IF EXISTS get_view;
DROP FUNCTION IF EXISTS get_available_languages;

CREATE OR REPLACE FUNCTION get_view(
    _limit INT,
    _offset INT,
    _orderby TEXT DEFAULT 'downloads',
    _orderdir TEXT DEFAULT 'desc',
    _query TEXT DEFAULT NULL,
    _smv TEXT[] DEFAULT NULL,
    _type TEXT DEFAULT NULL,
    _language TEXT[] DEFAULT NULL,
    _date_range TEXT DEFAULT NULL,
    _popularity TEXT DEFAULT NULL
)
RETURNS JSON AS $$
DECLARE
    todo JSON;
    total INT;
    sql_query TEXT;
    date_filter TEXT := '';
BEGIN
    IF _date_range = '7d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''7 days''';
    ELSIF _date_range = '30d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''30 days''';
    ELSIF _date_range = '90d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''90 days''';
    ELSIF _date_range = '365d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''365 days''';
    END IF;

    IF _popularity = 'most_popular' THEN
        _orderby := 'downloads';
        _orderdir := 'desc';
    ELSIF _popularity = 'top_rated' THEN
        _orderby := 'rating';
        _orderdir := 'desc';
    ELSIF _popularity = 'newest' THEN
        _orderby := 'time';
        _orderdir := 'desc';
    ELSIF _popularity = 'rising' THEN
        _orderby := 'downloads';
        _orderdir := 'desc';
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''30 days''';
    END IF;

    IF _orderdir NOT IN ('asc', 'desc') THEN
        _orderdir := 'desc';
    END IF;

    IF _orderby = 'downloads' THEN
        _orderby := '(p.downloads ->> ''total'')::INT';
    ELSIF _orderby = 'rating' THEN
        _orderby := 'COALESCE(ROUND(AVG(r.rating), 1), 0)';
    ELSIF _orderby = 'time' THEN
        _orderby := 'COALESCE(p.time, p.created_at)';
    ELSE
        _orderby := 'p.name';
    END IF;

    EXECUTE format(
        'SELECT COUNT(DISTINCT p.name)
         FROM packages p
         LEFT JOIN reviews r ON p.name = r."objectId"
         WHERE p.latest_mautic_support = TRUE
           AND (%L IS NULL OR p.name ILIKE ''%%'' || %L || ''%%'' OR p.maintainers::text ILIKE ''%%'' || %L || ''%%'')
           AND (%L IS NULL OR p.type = %L)
           AND (
                COALESCE(array_length(%L::TEXT[], 1), 0) = 0
                OR EXISTS (
                    SELECT 1
                    FROM versions v
                    WHERE v.package_name = p.name
                      AND EXISTS (
                          SELECT 1
                          FROM unnest(%L::TEXT[]) AS selected_smv
                          WHERE v.smv ILIKE ''%%'' || selected_smv || ''%%''
                      )
                )
           )
           AND (
                COALESCE(array_length(%L::TEXT[], 1), 0) = 0
                OR (
                    CASE
                        WHEN p.language IS NULL OR btrim(p.language) = '''' THEN NULL
                        WHEN lower(btrim(p.language)) IN (''en'', ''en-us'', ''en-gb'', ''english'') THEN ''english''
                        WHEN lower(btrim(p.language)) IN (''nl'', ''nl-nl'', ''dutch'', ''nederlands'') THEN ''dutch''
                        ELSE lower(btrim(p.language))
                    END
                ) = ANY(%L::TEXT[])
           )
           ' || date_filter,
        _query, _query, _query, _type, _type, _smv, _smv, _language, _language
    ) INTO total;

    sql_query := format(
        'SELECT JSON_AGG(t)
         FROM (
             SELECT
                 p.name,
                 p.url,
                 p.repository,
                 p.description,
                 (p.downloads ->> ''total'')::INT as downloads,
                 p.favers,
                 p.type,
                 p.displayname,
                 p.language,
                 p.banner_url,
                 (SELECT lv.validation_errors FROM versions lv WHERE lv.package_name = p.name ORDER BY lv.time DESC NULLS LAST LIMIT 1) AS validation_errors,
                 COALESCE(ROUND(AVG(r.rating), 1), 0) AS average_rating,
                 COALESCE(COUNT(r.review), 0) AS total_review,
                 COALESCE(p.time, p.created_at) AS time
             FROM packages p
             LEFT JOIN reviews r ON p.name = r."objectId"
             WHERE p.latest_mautic_support = TRUE
               AND (%L IS NULL OR p.name ILIKE ''%%'' || %L || ''%%'' OR p.maintainers::text ILIKE ''%%'' || %L || ''%%'')
               AND (%L IS NULL OR p.type = %L)
               AND (
                    COALESCE(array_length(%L::TEXT[], 1), 0) = 0
                    OR EXISTS (
                        SELECT 1
                        FROM versions v
                        WHERE v.package_name = p.name
                          AND EXISTS (
                              SELECT 1
                              FROM unnest(%L::TEXT[]) AS selected_smv
                              WHERE v.smv ILIKE ''%%'' || selected_smv || ''%%''
                          )
                    )
               )
               AND (
                    COALESCE(array_length(%L::TEXT[], 1), 0) = 0
                    OR (
                        CASE
                            WHEN p.language IS NULL OR btrim(p.language) = '''' THEN NULL
                            WHEN lower(btrim(p.language)) IN (''en'', ''en-us'', ''en-gb'', ''english'') THEN ''english''
                            WHEN lower(btrim(p.language)) IN (''nl'', ''nl-nl'', ''dutch'', ''nederlands'') THEN ''dutch''
                            ELSE lower(btrim(p.language))
                        END
                    ) = ANY(%L::TEXT[])
               )
               ' || date_filter || '
             GROUP BY p.name, p.url, p.repository, p.description, p.downloads, p.favers, p.type, p.displayname, p.language, p.banner_url, p.time, p.created_at
             ORDER BY %s %s, p.name ASC
             LIMIT %L OFFSET %L
         ) t',
        _query, _query, _query, _type, _type, _smv, _smv, _language, _language, _orderby, _orderdir, _limit, _offset
    );

    EXECUTE sql_query INTO todo;

    RETURN JSON_BUILD_OBJECT(
        'results', todo,
        'total', total
    );
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION get_available_languages(
    _query TEXT DEFAULT NULL,
    _smv TEXT[] DEFAULT NULL,
    _type TEXT DEFAULT NULL,
    _date_range TEXT DEFAULT NULL,
    _popularity TEXT DEFAULT NULL
)
RETURNS JSON AS $$
DECLARE
    languages JSON;
    date_filter TEXT := '';
BEGIN
    IF _date_range = '7d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''7 days''';
    ELSIF _date_range = '30d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''30 days''';
    ELSIF _date_range = '90d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''90 days''';
    ELSIF _date_range = '365d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''365 days''';
    END IF;

    IF _popularity = 'rising' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''30 days''';
    END IF;

    EXECUTE format(
        'SELECT COALESCE(json_agg(language_value ORDER BY language_value), ''[]''::json)
         FROM (
             SELECT DISTINCT btrim(p.language) AS language_value
             FROM packages p
             WHERE p.latest_mautic_support = TRUE
               AND p.language IS NOT NULL
               AND btrim(p.language) <> ''''
               AND (%L IS NULL OR p.name ILIKE ''%%'' || %L || ''%%'' OR p.maintainers::text ILIKE ''%%'' || %L || ''%%'')
               AND (%L IS NULL OR p.type = %L)
               AND (
                    COALESCE(array_length(%L::TEXT[], 1), 0) = 0
                    OR EXISTS (
                        SELECT 1
                        FROM versions v
                        WHERE v.package_name = p.name
                          AND EXISTS (
                              SELECT 1
                              FROM unnest(%L::TEXT[]) AS selected_smv
                              WHERE v.smv ILIKE ''%%'' || selected_smv || ''%%''
                          )
                    )
               )
               ' || date_filter || '
         ) available_languages',
        _query, _query, _query, _type, _type, _smv, _smv
    ) INTO languages;

    RETURN languages;
END;
$$ LANGUAGE plpgsql STABLE;

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
            'banner_url', p.banner_url,
            'versions', jsonb_object_agg(
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
            ),
            'reviews', jsonb_object_agg(
                COALESCE(r.user, 'no reviews'),
                jsonb_build_object(
                    'name', r.user,
                    'rating', r.rating,
                    'review', r.review
                )
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
    JOIN versions v ON p.name = v.package_name
    LEFT JOIN reviews r ON p.name = r."objectId"
    WHERE p.name = packag_name
    GROUP BY p.name, p.displayname, p.description, p.time, p.maintainers, p.banner_url, p.type, p.repository, p.github_stars, p.github_watchers, p.github_forks, p.github_open_issues, p.language, p.dependents, p.suggesters, p.downloads, p.favers;

    RETURN package_data;
END;
$$ LANGUAGE plpgsql STABLE;
