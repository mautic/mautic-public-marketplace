-- The upload form lets publishers override the package name, and a re-shared
-- campaign (matched by campaign_uuid) renames the existing row in place rather
-- than keeping the original name. For a rename of packages.name to succeed,
-- every child FK that references it must cascade the update — they were
-- previously NO ACTION, which blocked the rename while child rows existed.

ALTER TABLE versions DROP CONSTRAINT IF EXISTS versions_package_name_fkey;
ALTER TABLE versions
    ADD CONSTRAINT versions_package_name_fkey
    FOREIGN KEY (package_name) REFERENCES packages(name)
    ON UPDATE CASCADE ON DELETE CASCADE;

ALTER TABLE reviews DROP CONSTRAINT IF EXISTS "reviews_objectId_fkey";
ALTER TABLE reviews
    ADD CONSTRAINT "reviews_objectId_fkey"
    FOREIGN KEY ("objectId") REFERENCES packages(name)
    ON UPDATE CASCADE ON DELETE CASCADE;

ALTER TABLE download_history DROP CONSTRAINT IF EXISTS download_history_package_name_fkey;
ALTER TABLE download_history
    ADD CONSTRAINT download_history_package_name_fkey
    FOREIGN KEY (package_name) REFERENCES packages(name)
    ON UPDATE CASCADE ON DELETE CASCADE;
