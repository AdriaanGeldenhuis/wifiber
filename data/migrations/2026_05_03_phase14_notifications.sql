-- Phase 14 — Customer notifications gateway.
--
-- Adds the per-customer opt-in flags + a notification_log fact table so
-- "did this customer get the cyclone-warning SMS?" is one query away.
-- The new auth/notifications.php helper writes to notification_log; the
-- existing outage_notify_affected() / outage_notify_resolved() in
-- auth/outages.php route through it.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase14_notifications.sql

CREATE TABLE IF NOT EXISTS notification_log (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED  DEFAULT NULL,
  channel     ENUM('email','sms','whatsapp','webhook') NOT NULL DEFAULT 'email',
  template    VARCHAR(60)   NOT NULL DEFAULT '',
  recipient   VARCHAR(160)  NOT NULL DEFAULT '',
  subject     VARCHAR(200)  NOT NULL DEFAULT '',
  status      ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error       VARCHAR(500)  NOT NULL DEFAULT '',
  cost_zar    DECIMAL(8,4)  DEFAULT NULL,
  meta_json   JSON          DEFAULT NULL,
  sent_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nl_user (user_id),
  KEY idx_nl_template (template),
  KEY idx_nl_sent (sent_at),
  CONSTRAINT fk_nl_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS notify_prefs JSON DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS phone_e164   VARCHAR(20) NOT NULL DEFAULT '';
