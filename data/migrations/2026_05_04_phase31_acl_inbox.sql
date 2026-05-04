-- Phase 31 — ACL extension + in-app inbox + per-user Slack webhook.
--
-- 1. Extend users.role with finer staff tiers so a billing clerk can't
--    push-to-radio and a technician can't reissue invoices.
--      super_admin  full access incl. role assignment
--      admin        existing all-purpose admin
--      billing      invoices, payments, products, RADIUS, customers
--      support      tickets, customers (read), outages, maintenance
--      technician   sites, devices, sectors, links, push-to-radio
--      noc_readonly existing read-only NOC tier
--      viewer       like noc_readonly but without sensitive billing data
--      client       existing customer portal
--
--    auth/acl.php::acl_can() centralises the rule table; admin pages
--    call acl_require() in place of bare require_admin_write() where
--    finer granularity is useful.
--
-- 2. admin_inbox: in-app notification queue surfaced as a bell icon in
--    the portal header. Distinct from notification_log (which is the
--    delivery audit trail for email/sms/whatsapp/slack) — this is what
--    a logged-in operator sees the moment they open admin.
--
-- 3. users.slack_webhook: per-user override for the Slack channel so
--    individual NOC engineers can DM themselves rather than spamming
--    the whole channel default.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase31_acl_inbox.sql

ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'super_admin','admin','billing','support','technician',
    'noc_readonly','viewer','client'
  ) NOT NULL DEFAULT 'client';

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS slack_webhook VARCHAR(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS admin_inbox (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  -- NULL = visible to every staff member (NOC-wide announcement).
  -- A user_id targets a specific operator (e.g. "your queued change finished").
  user_id      INT UNSIGNED  DEFAULT NULL,
  -- Coarse audience filter for NULL user_id rows: 'noc','billing','support','any'.
  -- Lets us fan out an outage alert to NOC-eligible roles only.
  audience     VARCHAR(20)   NOT NULL DEFAULT 'any',
  severity     ENUM('info','warning','error','success') NOT NULL DEFAULT 'info',
  title        VARCHAR(160)  NOT NULL,
  body         TEXT          DEFAULT NULL,
  link         VARCHAR(255)  DEFAULT NULL,
  -- Optional dedupe key — a worker that re-detects the same condition
  -- can pass the same dedupe_key to skip re-posting until the user has
  -- read the existing one.
  dedupe_key   VARCHAR(120)  DEFAULT NULL,
  -- Per-user read state lives in admin_inbox_read so a single broadcast
  -- row can be marked-read by each operator independently.
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inbox_user      (user_id, created_at),
  KEY idx_inbox_audience  (audience, created_at),
  KEY idx_inbox_dedupe    (dedupe_key, created_at),
  CONSTRAINT fk_inbox_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_inbox_read (
  inbox_id     INT UNSIGNED  NOT NULL,
  user_id      INT UNSIGNED  NOT NULL,
  read_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (inbox_id, user_id),
  KEY idx_inbox_read_user (user_id, read_at),
  CONSTRAINT fk_inbox_read_inbox
    FOREIGN KEY (inbox_id) REFERENCES admin_inbox(id) ON DELETE CASCADE,
  CONSTRAINT fk_inbox_read_user
    FOREIGN KEY (user_id)  REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
