-- Phase 8 — Customer-to-sector linking.
--
-- Adds users.sector_id so a customer record knows which radio sector
-- they're attached to. Pairs with the existing users.site_id (which
-- tower they sit on). Both nullable: a customer might be on a tower
-- but not yet pinned to a specific sector, or might be pre-install.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase8_users_sector.sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS sector_id INT UNSIGNED DEFAULT NULL AFTER site_id;

ALTER TABLE users
  ADD KEY IF NOT EXISTS idx_user_sector (sector_id);

ALTER TABLE users
  ADD CONSTRAINT IF NOT EXISTS fk_users_sector
    FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE SET NULL;
