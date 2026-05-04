-- Phase 28 — Splynx-equivalent billing automation.
--
-- Layers on top of the existing invoices / invoice_items / users.product_id
-- plumbing: tracks payments separately from invoice rows (so the operator
-- can record an EFT before reconciling it against an invoice), credit
-- notes for refunds / write-offs, and currency columns so a future
-- multi-currency split doesn't need another migration.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase28_billing.sql

-- -------------------------------------------------------------------------
-- payments — every received payment, manual or gateway-driven.
-- An unallocated payment (invoice_id NULL) is "money on account" that the
-- operator can later apply to a specific invoice.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED  NOT NULL,
  invoice_id      INT UNSIGNED  DEFAULT NULL,
  method          ENUM('eft','debit_order','cash','card','payfast','yoco','stripe','credit_note','other')
                                NOT NULL DEFAULT 'eft',
  amount          DECIMAL(10,2) NOT NULL,
  currency        CHAR(3)       NOT NULL DEFAULT 'ZAR',
  reference       VARCHAR(120)  NOT NULL DEFAULT '',
  external_id     VARCHAR(120)  DEFAULT NULL,
  status          ENUM('pending','received','refunded','failed') NOT NULL DEFAULT 'received',
  received_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes           VARCHAR(255)  NOT NULL DEFAULT '',
  recorded_by     INT UNSIGNED  DEFAULT NULL,
  source          ENUM('manual','bank_csv','gateway','api','credit_note') NOT NULL DEFAULT 'manual',
  source_meta     JSON          DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_external (method, external_id),
  KEY idx_payments_user    (user_id, received_at),
  KEY idx_payments_invoice (invoice_id),
  KEY idx_payments_ref     (reference),
  CONSTRAINT fk_payments_user
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_payments_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_recorder
    FOREIGN KEY (recorded_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- credit_notes — a refund / write-off / goodwill credit.  When applied,
-- a payments row of method='credit_note' is created against the target
-- invoice and the credit note's status flips to 'applied'.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credit_notes (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  number        VARCHAR(40)   NOT NULL,
  user_id       INT UNSIGNED  NOT NULL,
  invoice_id    INT UNSIGNED  DEFAULT NULL,
  amount        DECIMAL(10,2) NOT NULL,
  currency      CHAR(3)       NOT NULL DEFAULT 'ZAR',
  reason        VARCHAR(255)  NOT NULL DEFAULT '',
  status        ENUM('open','applied','void') NOT NULL DEFAULT 'open',
  issued_at     DATE          NOT NULL,
  applied_at    DATETIME      DEFAULT NULL,
  created_by    INT UNSIGNED  DEFAULT NULL,
  notes         TEXT          DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_number (number),
  KEY idx_credit_user (user_id, issued_at),
  CONSTRAINT fk_credit_user
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_credit_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_creator
    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- currency on invoices and products so a future multi-currency split
-- doesn't need another DDL pass.  Defaults to ZAR which matches today's
-- behaviour exactly.
-- -------------------------------------------------------------------------
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'ZAR';
ALTER TABLE products ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'ZAR';

-- -------------------------------------------------------------------------
-- reminder_count on invoices so the dunning ladder can step T+3 → T+7 →
-- T+14 without dispatching the same notice twice.  last_reminder_at
-- already exists; this counts how many we've sent.
-- -------------------------------------------------------------------------
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- -------------------------------------------------------------------------
-- billing_day index for the per-day cron lookup.
-- -------------------------------------------------------------------------
ALTER TABLE users ADD KEY IF NOT EXISTS idx_users_billing_day (billing_day, status);
