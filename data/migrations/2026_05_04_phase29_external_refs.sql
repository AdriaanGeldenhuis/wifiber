-- Phase 29 — external_ref columns on every table the importers write.
--
-- Idempotency for `bin/import-uisp.php`, `bin/import-splynx.php` and
-- `bin/import-radius.php` is keyed on (external_src, external_ref):
--
--   external_src  short tag for the source system: 'uisp', 'splynx',
--                  'radius', etc.
--   external_ref  the canonical id over there: UISP's `id`, Splynx's
--                  `id`, FreeRADIUS's username, …
--
-- Re-running an importer is therefore safe: rows that already match a
-- (src, ref) pair get UPDATEd in place; new ones are INSERTed; rows
-- without an external_ref are left alone (so manually-created accounts
-- aren't trampled by an import).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase29_external_refs.sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS external_ref VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_src VARCHAR(20) DEFAULT NULL;

ALTER TABLE users
  ADD KEY IF NOT EXISTS idx_users_external_ref (external_src, external_ref);

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS external_ref VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_src VARCHAR(20) DEFAULT NULL;

ALTER TABLE devices
  ADD KEY IF NOT EXISTS idx_devices_external_ref (external_src, external_ref);

ALTER TABLE sites
  ADD COLUMN IF NOT EXISTS external_ref VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_src VARCHAR(20) DEFAULT NULL;

ALTER TABLE sites
  ADD KEY IF NOT EXISTS idx_sites_external_ref (external_src, external_ref);

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS external_ref VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_src VARCHAR(20) DEFAULT NULL;

ALTER TABLE invoices
  ADD KEY IF NOT EXISTS idx_invoices_external_ref (external_src, external_ref);

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS external_ref VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_src VARCHAR(20) DEFAULT NULL;

ALTER TABLE products
  ADD KEY IF NOT EXISTS idx_products_external_ref (external_src, external_ref);

ALTER TABLE wireless_links
  ADD COLUMN IF NOT EXISTS external_ref VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS external_src VARCHAR(20) DEFAULT NULL;

ALTER TABLE wireless_links
  ADD KEY IF NOT EXISTS idx_wlinks_external_ref (external_src, external_ref);

-- Run history so an admin can see when each importer last ran, what
-- changed, and which rows failed without scrolling cron logs.
CREATE TABLE IF NOT EXISTS import_runs (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  source       VARCHAR(20)   NOT NULL,
  resource     VARCHAR(40)   NOT NULL,
  started_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME      DEFAULT NULL,
  rows_total   INT UNSIGNED  NOT NULL DEFAULT 0,
  rows_created INT UNSIGNED  NOT NULL DEFAULT 0,
  rows_updated INT UNSIGNED  NOT NULL DEFAULT 0,
  rows_skipped INT UNSIGNED  NOT NULL DEFAULT 0,
  rows_failed  INT UNSIGNED  NOT NULL DEFAULT 0,
  dry_run      TINYINT(1)    NOT NULL DEFAULT 0,
  triggered_by INT UNSIGNED  DEFAULT NULL,
  notes        TEXT          DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_import_runs_source (source, resource, started_at),
  CONSTRAINT fk_import_runs_user
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
