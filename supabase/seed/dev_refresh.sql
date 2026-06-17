-- Move validation_errors from packages to versions table (needed for already-initialized databases)
ALTER TABLE packages DROP COLUMN IF EXISTS validation_errors;
ALTER TABLE versions ADD COLUMN IF NOT EXISTS validation_errors TEXT DEFAULT NULL;

UPDATE packages
SET language = 'English'
WHERE name IN (
    'mautic/customer-reengagement-campaign',
    'mautic/revenue-recovery-campaign'
)
  AND language = 'PHP';

UPDATE versions
SET keywords = CASE
    WHEN package_name = 'mautic/example-plugin' THEN '["example", "plugin", "local-development"]'::jsonb
    WHEN package_name = 'mautic/alpha-plugin' THEN '["alpha", "plugin", "sorting"]'::jsonb
    WHEN package_name = 'mautic/zebra-theme' THEN '["theme", "zebra", "responsive"]'::jsonb
    WHEN package_name = 'mautic/welcome-campaign' THEN '["campaign", "welcome", "resource", "automation"]'::jsonb
    WHEN package_name = 'mautic/customer-reengagement-campaign' THEN '["campaign", "resource", "automation", "re-engagement", "win-back"]'::jsonb
    ELSE keywords
END
WHERE package_name IN (
    'mautic/example-plugin',
    'mautic/alpha-plugin',
    'mautic/zebra-theme',
    'mautic/welcome-campaign',
    'mautic/customer-reengagement-campaign'
);

-- Drop old get_view overloads to prevent PostgREST conflict
DROP FUNCTION IF EXISTS get_view(INT, INT, TEXT, TEXT, TEXT, TEXT) CASCADE;
DROP FUNCTION IF EXISTS get_view(INT, INT, TEXT, TEXT, TEXT, TEXT, TEXT) CASCADE;
DROP FUNCTION IF EXISTS get_view(INT, INT, TEXT, TEXT, TEXT, TEXT, TEXT, TEXT, TEXT, TEXT) CASCADE;
DROP FUNCTION IF EXISTS get_view(INT, INT, TEXT, TEXT, TEXT, TEXT[], TEXT, TEXT[], INT, BOOLEAN, TEXT, TEXT, TEXT, TEXT) CASCADE;
DROP FUNCTION IF EXISTS get_available_languages(TEXT, TEXT[], TEXT, INT, BOOLEAN, TEXT, TEXT, TEXT, TEXT) CASCADE;

CREATE OR REPLACE FUNCTION package_matches_query(
    _package_name TEXT,
    _maintainers JSONB,
    _query TEXT
)
RETURNS BOOLEAN AS $$
BEGIN
    RETURN _query IS NULL
        OR _package_name ILIKE '%' || _query || '%'
        OR _maintainers::text ILIKE '%' || _query || '%'
        OR EXISTS (
            SELECT 1
            FROM versions v
            WHERE v.package_name = _package_name
              AND jsonb_typeof(v.keywords) = 'array'
              AND EXISTS (
                  SELECT 1
                  FROM jsonb_array_elements_text(v.keywords) AS keyword(value)
                  WHERE keyword.value ILIKE '%' || _query || '%'
              )
        );
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION get_view(
    _limit INT,
    _offset INT,
    _orderby TEXT DEFAULT 'downloads',
    _orderdir TEXT DEFAULT 'desc',
    _query TEXT DEFAULT NULL,
    _smv TEXT[] DEFAULT NULL,
    _type TEXT DEFAULT NULL,
    _language TEXT[] DEFAULT NULL,
    _minimum_rating INT DEFAULT NULL,
    _unrated_only BOOLEAN DEFAULT FALSE,
    _rated_by TEXT DEFAULT NULL,
    _submitted_by TEXT DEFAULT NULL,
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
    -- Map date_range preset to interval filter
    IF _date_range = '7d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''7 days''';
    ELSIF _date_range = '30d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''30 days''';
    ELSIF _date_range = '90d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''90 days''';
    ELSIF _date_range = '365d' THEN
        date_filter := 'AND COALESCE(p.time, p.created_at) >= NOW() - INTERVAL ''365 days''';
    END IF;

    -- Override orderby/orderdir when popularity preset is used
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

    -- Sanitise sort direction to prevent SQL injection
    IF _orderdir NOT IN ('asc', 'desc') THEN
        _orderdir := 'desc';
    END IF;

    -- Map orderby to SQL expression
    IF _orderby = 'downloads' THEN
        _orderby := '(p.downloads ->> ''total'')::INT';
    ELSIF _orderby = 'rating' THEN
        _orderby := 'COALESCE(ROUND(AVG(r.rating), 1), 0)';
    ELSIF _orderby = 'time' THEN
        _orderby := 'COALESCE(p.time, p.created_at)';
    ELSE
        _orderby := 'p.name';
    END IF;

    -- Count total matching rows
    EXECUTE format(
        'SELECT COUNT(DISTINCT p.name)
         FROM packages p
         LEFT JOIN reviews r ON p.name = r."objectId"
         WHERE p.latest_mautic_support = TRUE
           AND package_matches_query(p.name, p.maintainers, %L)
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
           AND (
                %L IS NULL
                OR COALESCE((
                    SELECT ROUND(AVG(r2.rating), 1)
                    FROM reviews r2
                    WHERE r2."objectId" = p.name
                ), 0) >= CASE WHEN %L = 5 THEN 4.6 ELSE %L END
           )
           AND (
                %L IS NOT TRUE
                OR NOT EXISTS (
                    SELECT 1
                    FROM reviews ur
                    WHERE ur."objectId" = p.name
                )
           )
           AND (
                %L IS NULL
                OR EXISTS (
                    SELECT 1
                    FROM reviews rr
                    WHERE rr."objectId" = p.name
                      AND rr.auth0_user_id = %L
                )
           )
           AND (
                %L IS NULL
                OR p.auth0_user_id = %L
           )
           ' || date_filter,
        _query, _type, _type, _smv, _smv, _language, _language, _minimum_rating, _minimum_rating, _minimum_rating, _unrated_only, _rated_by, _rated_by, _submitted_by, _submitted_by
    ) INTO total;

    -- Fetch paginated results
    sql_query := format(
        'SELECT JSON_AGG(t)
         FROM (SELECT
                  p.name,
                  p.url,
                  p.repository,
                  p.description,
                  (p.downloads ->> ''total'')::INT as downloads,
                  p.favers,
                  p.type,
                  p.displayname,
                  p.language,
                  (SELECT lv.validation_errors FROM versions lv WHERE lv.package_name = p.name ORDER BY lv.time DESC NULLS LAST LIMIT 1) AS validation_errors,
                  COALESCE(ROUND(AVG(r.rating), 1), 0) AS average_rating,
                  COALESCE(COUNT(r.review), 0) AS total_review,
                  COALESCE(p.time, p.created_at) AS time
               FROM packages p
               LEFT JOIN reviews r ON p.name = r."objectId"
               WHERE p.latest_mautic_support = TRUE
                 AND package_matches_query(p.name, p.maintainers, %L)
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
                 AND (
                      %L IS NULL
                      OR COALESCE((
                          SELECT ROUND(AVG(r2.rating), 1)
                          FROM reviews r2
                          WHERE r2."objectId" = p.name
                      ), 0) >= CASE WHEN %L = 5 THEN 4.6 ELSE %L END
                 )
                 AND (
                      %L IS NOT TRUE
                      OR NOT EXISTS (
                          SELECT 1
                          FROM reviews ur
                          WHERE ur."objectId" = p.name
                      )
                 )
                 AND (
                      %L IS NULL
                      OR EXISTS (
                          SELECT 1
                          FROM reviews rr
                          WHERE rr."objectId" = p.name
                            AND rr.auth0_user_id = %L
                      )
                 )
                 AND (
                      %L IS NULL
                      OR p.auth0_user_id = %L
                 )
                 ' || date_filter || '
               GROUP BY p.name, p.url, p.repository, p.description, p.downloads, p.favers, p.type, p.displayname, p.language, p.time, p.created_at
               ORDER BY %s %s, p.name ASC
               LIMIT %L OFFSET %L
        ) t', _query, _type, _type, _smv, _smv, _language, _language, _minimum_rating, _minimum_rating, _minimum_rating, _unrated_only, _rated_by, _rated_by, _submitted_by, _submitted_by, _orderby, _orderdir, _limit, _offset);

    EXECUTE sql_query INTO todo;

    RETURN JSON_BUILD_OBJECT(
        'results', todo,
        'total', total
    );
END;
$$
 LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION get_available_languages(
    _query TEXT DEFAULT NULL,
    _smv TEXT[] DEFAULT NULL,
    _type TEXT DEFAULT NULL,
    _minimum_rating INT DEFAULT NULL,
    _unrated_only BOOLEAN DEFAULT FALSE,
    _rated_by TEXT DEFAULT NULL,
    _submitted_by TEXT DEFAULT NULL,
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
               AND package_matches_query(p.name, p.maintainers, %L)
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
                    %L IS NULL
                    OR COALESCE((
                        SELECT ROUND(AVG(r2.rating), 1)
                        FROM reviews r2
                        WHERE r2."objectId" = p.name
                    ), 0) >= CASE WHEN %L = 5 THEN 4.6 ELSE %L END
               )
               AND (
                    %L IS NOT TRUE
                    OR NOT EXISTS (
                        SELECT 1
                        FROM reviews ur
                        WHERE ur."objectId" = p.name
                    )
               )
               AND (
                    %L IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM reviews rr
                        WHERE rr."objectId" = p.name
                          AND rr.auth0_user_id = %L
                    )
               )
               AND (
                    %L IS NULL
                    OR p.auth0_user_id = %L
               )
               ' || date_filter || '
         ) available_languages',
        _query, _type, _type, _smv, _smv, _minimum_rating, _minimum_rating, _minimum_rating, _unrated_only, _rated_by, _rated_by, _submitted_by, _submitted_by
    ) INTO languages;

    RETURN languages;
END;
$$ LANGUAGE plpgsql STABLE;

-- Recreate get_pack with validation_errors per version
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
            'tags', (
                SELECT v.keywords
                FROM versions v
                WHERE v.package_name = p.name
                  AND v.keywords IS NOT NULL
                ORDER BY v.time DESC NULLS LAST
                LIMIT 1
            ),
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
                        'smv', v.smv,
                        'validation_errors', v.validation_errors
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
            'url', p.url,
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
