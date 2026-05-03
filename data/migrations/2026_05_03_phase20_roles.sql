-- Phase 20 — Read-only NOC role.
--
-- Adds 'noc_readonly' to the users.role ENUM. NOC tier-1 operators
-- can log into /admin/, view everything (links, link-view, devices,
-- audit, outages, reports) but cannot push-to-radio, edit sectors or
-- mutate database state.
--
-- auth/helpers.php::require_role accepts an array now so pages that
-- want to allow both roles do require_role(['admin','noc_readonly']).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase20_roles.sql

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','client','noc_readonly') NOT NULL DEFAULT 'client';
