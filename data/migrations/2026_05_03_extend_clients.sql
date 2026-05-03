-- Extend the users table with Splynx-style client fields.
--
-- One-time migration. Apply on the production database with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_extend_clients.sql
--
-- Existing client rows get NULL account_no until an admin opens their
-- record in /admin/client-edit.php — the page lazily generates an
-- account number from the surname (e.g. GEL0001) on first save.
--
-- account_no is UNIQUE. MySQL/MariaDB allow multiple NULL values in a
-- UNIQUE column so that's safe for existing rows.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_no        VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS surname           VARCHAR(60)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS id_number         VARCHAR(20)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS vat_number        VARCHAR(20)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS customer_type     ENUM('residential','business') NOT NULL DEFAULT 'residential',
  ADD COLUMN IF NOT EXISTS status            ENUM('active','suspended','disconnected','lead') NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS service_start     DATE         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS billing_day       TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS payment_method    VARCHAR(20)  NOT NULL DEFAULT 'eft',
  ADD COLUMN IF NOT EXISTS lat               DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lng               DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS alt_contact_name  VARCHAR(100) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS alt_contact_phone VARCHAR(40)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS equipment_mac     VARCHAR(20)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS equipment_ip      VARCHAR(45)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS equipment_serial  VARCHAR(60)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS equipment_model   VARCHAR(80)  NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS notes             TEXT         DEFAULT NULL;

ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uniq_account_no (account_no);
