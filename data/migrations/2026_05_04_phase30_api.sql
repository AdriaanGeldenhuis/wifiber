-- Phase 30 — REST API write scopes + inbound HMAC webhooks.
--
-- inbound_webhooks   — registered "sources" (Splynx, Zapier, etc.) that
--                      can POST signed payloads at /api/v1/webhooks/in.php.
-- inbound_deliveries — every signed POST we receive, stored for audit
--                      and replay debugging.  Body is kept in `payload`
--                      so the operator can re-process by hand if a
--                      handler bug ate the original event.
--
-- The outbound `webhooks` + `webhook_deliveries` tables (Phase 16) are
-- unchanged.  This phase is purely about *receiving* signed events.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase30_api.sql

CREATE TABLE IF NOT EXISTS inbound_webhooks (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name              VARCHAR(40)   NOT NULL,
  description       VARCHAR(200)  NOT NULL DEFAULT '',
  secret            VARCHAR(255)  NOT NULL,
  algo              ENUM('sha256','sha1','md5') NOT NULL DEFAULT 'sha256',
  signature_header  VARCHAR(60)   NOT NULL DEFAULT 'X-Hub-Signature-256',
  signature_prefix  VARCHAR(20)   NOT NULL DEFAULT 'sha256=',
  is_active         TINYINT(1)    NOT NULL DEFAULT 1,
  last_received_at  DATETIME      DEFAULT NULL,
  delivery_count    INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by        INT UNSIGNED  DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inbound_name (name),
  CONSTRAINT fk_inbound_webhook_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inbound_deliveries (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  inbound_id    INT UNSIGNED  DEFAULT NULL,
  source_name   VARCHAR(40)   NOT NULL DEFAULT '',
  event         VARCHAR(80)   NOT NULL DEFAULT '',
  body_sha256   CHAR(64)      NOT NULL,
  status        ENUM('verified','rejected') NOT NULL,
  reason        VARCHAR(120)  NOT NULL DEFAULT '',
  remote_ip     VARCHAR(45)   NOT NULL DEFAULT '',
  payload       MEDIUMTEXT    DEFAULT NULL,
  received_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inbound_at (received_at),
  KEY idx_inbound_status (status, received_at),
  CONSTRAINT fk_inbound_delivery_source
    FOREIGN KEY (inbound_id) REFERENCES inbound_webhooks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
