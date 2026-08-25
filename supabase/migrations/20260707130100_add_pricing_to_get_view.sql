-- Surface pricing on the card/listing source so package cards can show a
-- "Free" or price badge (paid packages, issue #45). Only the SELECT list and
-- GROUP BY gain price/pricing_model/currency; the parameterized filters are
-- untouched, and the signature is unchanged from 20260626140000.

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

    sql_query := format(
        'SELECT JSON_AGG(t)
         FROM (
             SELECT
                 p.name,
                 p.url,
                 p.repository,
                 p.description,
                 p.headline,
                 p.banner_url,
                 (p.downloads ->> ''total'')::INT as downloads,
                 p.favers,
                 p.type,
                 p.displayname,
                 p.language,
                 p.price,
                 p.pricing_model,
                 p.currency,
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
             GROUP BY p.name, p.url, p.repository, p.description, p.headline, p.banner_url, p.downloads, p.favers, p.type, p.displayname, p.language, p.price, p.pricing_model, p.currency, p.time, p.created_at
             ORDER BY %s %s, p.name ASC
             LIMIT %L OFFSET %L
         ) t',
        _query, _type, _type, _smv, _smv, _language, _language, _minimum_rating, _minimum_rating, _minimum_rating, _unrated_only, _rated_by, _rated_by, _submitted_by, _submitted_by, _orderby, _orderdir, _limit, _offset
    );

    EXECUTE sql_query INTO todo;

    RETURN JSON_BUILD_OBJECT(
        'results', todo,
        'total', total
    );
END;
$$ LANGUAGE plpgsql STABLE;
