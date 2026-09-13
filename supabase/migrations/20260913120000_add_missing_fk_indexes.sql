-- reviews."objectId" is a FK column referencing packages(name)
-- (ON UPDATE CASCADE ON DELETE CASCADE) and is also the join/filter key used
-- by get_view() and related RPCs on every marketplace listing request, but
-- it has never had an index (only reviews_pkey on id exists). Every listing
-- query, package rename, and package delete has been doing a full
-- sequential scan of reviews as a result, driving up disk IO.
--
-- versions.package_name does not need a matching index: it's already the
-- leading column of the existing versions_package_name_version_key unique
-- constraint, which Postgres can use directly for package_name-only lookups.

CREATE INDEX IF NOT EXISTS reviews_objectid_idx
ON reviews("objectId");
