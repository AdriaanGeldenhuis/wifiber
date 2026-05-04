-- Phase 21 — External integrations: webhooks out + REST API in.
--
-- webhooks: external URLs that get POSTed to when an event fires.
-- HMAC-signed (X-Wifiber-Signature header) for authenticity. events
-- is a JSON array of subscription patterns:
--   ["outage.*", "wireless.config_applied"]
--
-- webhook_deliveries: per-attempt log with retries on transient errors
-- (5xx, network failure). Exponential backoff 1m, 5m, 30m, 2h.
--
-- api_tokens: bearer tokens for the read-only JSON API at /api/v1/*.
-- Hashed at rest (sha256). Scopes: 'read', 'diag' (POST diagnostics).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase21_integrations.sql

CREATE TABLE IF NOT EXISTS webhooks (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  url             VARCHAR(500)  NOT NULL,
  secret          VARCHAR(80)   NOT NULL,
  events_json     JSON          NOT NULL,
  is_active       TINYINT(1)    NOT NULL DEFAULT 1,
  last_fired_at   DATETIME      DEFAULT NULL,
  last_status     SMALLINT      DEFAULT NULL,
  fail_count      INT UNSIGNED  NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED  DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wh_active (is_active),
  CONSTRAINT fk_wh_user
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  webhook_id       INT UNSIGNED  NOT NULL,
  event            VARCHAR(80)   NOT NULL,
  payload_json     JSON          NOT NULL,
  status           ENUM('queued','sent','failed','giving_up') NOT NULL DEFAULT 'queued',
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_response    SMALLINT      DEFAULT NULL,
  last_error       VARCHAR(500)  NOT NULL DEFAULT '',
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at      DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_wd_pending (status, next_attempt_at),
  KEY idx_wd_hook (webhook_id, created_at),
  CONSTRAINT fk_wd_hook
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED  DEFAULT NULL,
  label         VARCHAR(80)   NOT NULL DEFAULT '',
  token_hash    CHAR(64)      NOT NULL,
  scopes_json   JSON          NOT NULL,
  expires_at    DATETIME      DEFAULT NULL,
  last_used_at  DATETIME      DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token_hash (token_hash),
  KEY idx_at_user (user_id),
  CONSTRAINT fk_at_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
