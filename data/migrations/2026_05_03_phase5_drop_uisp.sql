-- Phase 5 — Drop the UISP integration. Removes the cache tables and the
-- adopt-link columns added in phase 4. From here on, devices/sectors/links
-- are managed natively by Wifiber NOC (see the upcoming phase-1 migration).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase5_drop_uisp.sql

DROP TABLE IF EXISTS uisp_data_links;
DROP TABLE IF EXISTS uisp_devices;
DROP TABLE IF EXISTS uisp_sites;
DROP TABLE IF EXISTS uisp_clients;

ALTER TABLE sites
  DROP INDEX IF EXISTS uniq_sites_uisp_id,
  DROP COLUMN IF EXISTS uisp_id;

ALTER TABLE site_links
  DROP INDEX IF EXISTS uniq_site_links_uisp_id,
  DROP COLUMN IF EXISTS uisp_id;

ALTER TABLE users
  DROP INDEX IF EXISTS uniq_users_uisp_client,
  DROP COLUMN IF EXISTS uisp_client_id;
