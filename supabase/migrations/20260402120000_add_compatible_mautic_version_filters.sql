-- Drop the old 9-parameter version so PostgREST only exposes the array-based signature.
DROP FUNCTION IF EXISTS get_view(INT, INT, TEXT, TEXT, TEXT, TEXT, TEXT, TEXT, TEXT);

CREATE OR REPLACE FUNCTION get_view(
    _limit INT,
    _offset INT,
    _orderby TEXT DEFAULT 'downloads',
    _orderdir TEXT DEFAULT 'desc',
    _query TEXT DEFAULT NULL,
    _smv TEXT[] DEFAULT NULL,
    _type TEXT DEFAULT NULL,
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
           ' || date_filter,
        _query, _query, _query, _type, _type, _smv, _smv
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
               ' || date_filter || '
             GROUP BY p.name, p.url, p.repository, p.description, p.downloads, p.favers, p.type, p.displayname, p.time, p.created_at
             ORDER BY %s %s, p.name ASC
             LIMIT %L OFFSET %L
         ) t',
        _query, _query, _query, _type, _type, _smv, _smv, _orderby, _orderdir, _limit, _offset
    );

    EXECUTE sql_query INTO todo;

    RETURN JSON_BUILD_OBJECT(
        'results', todo,
        'total', total
    );
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION get_compatible_mautic_versions()
RETURNS JSON AS $$
DECLARE
    versions JSON;
BEGIN
    SELECT COALESCE(json_agg(option_value ORDER BY option_value), '[]'::json)
    INTO versions
    FROM (
        SELECT DISTINCT trim(split_value) AS option_value
        FROM packages p
        JOIN versions v ON v.package_name = p.name
        CROSS JOIN LATERAL regexp_split_to_table(COALESCE(v.smv, ''), '\s*\|\|\s*') AS split_value
        WHERE p.latest_mautic_support = TRUE
          AND trim(split_value) <> ''
    ) version_options;

    RETURN versions;
END;
$$ LANGUAGE plpgsql STABLE;
