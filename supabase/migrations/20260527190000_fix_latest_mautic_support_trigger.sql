-- The original trigger required versions.smv to contain the literal "^5.0"
-- composer constraint string, hard-coding the supported Mautic line. Packages
-- submitted via the campaign-share flow store smv as a plain numeric version
-- ("5.0", "6.0", "7.0"), which never matched and left latest_mautic_support
-- null, hiding the package from /browse. Bumping the Mautic line in future
-- shouldn't require touching this trigger.
--
-- Set the flag whenever a version has any major.minor smv value (the column
-- means "package declares a Mautic version", not "package targets X.Y").
-- Version-line filtering belongs in get_view / browse filters, not here.

CREATE OR REPLACE FUNCTION update_latest_mautic_support()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE packages p
    SET latest_mautic_support = CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM versions v
                    WHERE v.package_name = p.name
                    AND v.smv ~ '\d+\.\d+'
                ) THEN true
                ELSE null
              END
    WHERE p.name = COALESCE(NEW.package_name, OLD.package_name);

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Recompute the flag for every existing package so previously-published rows
-- become visible without manual intervention.
UPDATE packages p
SET latest_mautic_support = CASE
            WHEN EXISTS (
                SELECT 1
                FROM versions v
                WHERE v.package_name = p.name
                AND v.smv ~ '\d+\.\d+'
            ) THEN true
            ELSE null
          END;
