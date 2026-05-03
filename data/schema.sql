-- WiFIBER database schema
--
-- Apply on a fresh database with:
--   mysql -h <host> -u <user> -p <db> < data/schema.sql
--
-- All tables use InnoDB + utf8mb4 so emoji / non-ASCII data is safe.

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_no           VARCHAR(20)  DEFAULT NULL,
  username             VARCHAR(60)  NOT NULL,
  email                VARCHAR(120) NOT NULL DEFAULT '',
  name                 VARCHAR(100) NOT NULL DEFAULT '',
  surname              VARCHAR(60)  NOT NULL DEFAULT '',
  id_number            VARCHAR(20)  NOT NULL DEFAULT '',
  vat_number           VARCHAR(20)  NOT NULL DEFAULT '',
  role                 ENUM('admin','client') NOT NULL,
  customer_type        ENUM('residential','business') NOT NULL DEFAULT 'residential',
  status               ENUM('active','suspended','disconnected','lead') NOT NULL DEFAULT 'active',
  service_start        DATE         DEFAULT NULL,
  billing_day          TINYINT UNSIGNED DEFAULT NULL,
  payment_method       VARCHAR(20)  NOT NULL DEFAULT 'eft',
  phone                VARCHAR(40)  NOT NULL DEFAULT '',
  address              VARCHAR(200) NOT NULL DEFAULT '',
  lat                  DECIMAL(10,7) DEFAULT NULL,
  lng                  DECIMAL(10,7) DEFAULT NULL,
  alt_contact_name     VARCHAR(100) NOT NULL DEFAULT '',
  alt_contact_phone    VARCHAR(40)  NOT NULL DEFAULT '',
  package              VARCHAR(80)  NOT NULL DEFAULT '',
  product_id           INT UNSIGNED DEFAULT NULL,
  equipment_mac        VARCHAR(20)  NOT NULL DEFAULT '',
  equipment_ip         VARCHAR(45)  NOT NULL DEFAULT '',
  equipment_serial     VARCHAR(60)  NOT NULL DEFAULT '',
  equipment_model      VARCHAR(80)  NOT NULL DEFAULT '',
  notes                TEXT         DEFAULT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  totp_secret          VARCHAR(64)  DEFAULT NULL,
  totp_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
  totp_recovery_codes  JSON         DEFAULT NULL,
  totp_enabled_at      DATETIME     DEFAULT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login           DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username),
  UNIQUE KEY uniq_account_no (account_no),
  KEY idx_role (role),
  KEY idx_user_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tier_key      VARCHAR(40)   NOT NULL DEFAULT '',
  name          VARCHAR(120)  NOT NULL,
  down_mbps     DECIMAL(8,2)  NOT NULL DEFAULT 0,
  up_mbps       DECIMAL(8,2)  NOT NULL DEFAULT 0,
  monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  install_24mo  DECIMAL(10,2) NOT NULL DEFAULT 0,
  install_mtm   DECIMAL(10,2) NOT NULL DEFAULT 2799,
  contention    VARCHAR(20)   NOT NULL DEFAULT '',
  description   TEXT          DEFAULT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order    INT           NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name),
  KEY idx_tier (tier_key),
  KEY idx_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS throttle (
  ip         VARCHAR(45)  NOT NULL,
  fails      INT UNSIGNED NOT NULL DEFAULT 0,
  last_fail  INT UNSIGNED NOT NULL,
  PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  selector        CHAR(16)     NOT NULL,
  validator_hash  CHAR(64)     NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  expires_at      INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (selector),
  KEY idx_user (user_id),
  KEY idx_expires (expires_at),
  CONSTRAINT fk_pwreset_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  status      ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at   DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_status (status),
  KEY idx_updated (updated_at),
  CONSTRAINT fk_tickets_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id       INT UNSIGNED NOT NULL,
  author_id       INT UNSIGNED DEFAULT NULL,
  author_role     ENUM('admin','client') NOT NULL,
  author_label    VARCHAR(100) NOT NULL DEFAULT '',
  body            TEXT         NOT NULL,
  attachment_path VARCHAR(255) DEFAULT NULL,
  attachment_name VARCHAR(255) DEFAULT NULL,
  attachment_size INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ticket (ticket_id, created_at),
  KEY idx_author (author_id),
  CONSTRAINT fk_ticket_msg_ticket
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_msg_author
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number            VARCHAR(40)   NOT NULL DEFAULT '',
  user_id           INT UNSIGNED  NOT NULL,
  status            ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  issued_at         DATE          NOT NULL,
  due_at            DATE          NOT NULL,
  paid_at           DATETIME      DEFAULT NULL,
  period_start      DATE          DEFAULT NULL,
  subtotal          DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate          DECIMAL(5,2)  NOT NULL DEFAULT 0,
  vat_amount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  total             DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes             TEXT          DEFAULT NULL,
  last_reminder_at  DATETIME      DEFAULT NULL,
  created_by        INT UNSIGNED  DEFAULT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_number (number),
  UNIQUE KEY uniq_user_period (user_id, period_start),
  KEY idx_user (user_id),
  KEY idx_status_due (status, due_at),
  CONSTRAINT fk_invoices_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  invoice_id   INT UNSIGNED  NOT NULL,
  description  VARCHAR(200)  NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(10,2) NOT NULL DEFAULT 0,
  sort_order   INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_invoice (invoice_id, sort_order),
  CONSTRAINT fk_invoice_items_inv
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(200) NOT NULL,
  body         TEXT         NOT NULL,
  affected     VARCHAR(255) NOT NULL DEFAULT '',
  severity     ENUM('info','minor','major','critical') NOT NULL DEFAULT 'minor',
  status       ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating',
  started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME     DEFAULT NULL,
  created_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_started (status, started_at),
  CONSTRAINT fk_incident_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_updates (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  incident_id  INT UNSIGNED NOT NULL,
  status       ENUM('investigating','identified','monitoring','resolved') NOT NULL,
  body         TEXT         NOT NULL,
  created_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_incident_created (incident_id, created_at),
  CONSTRAINT fk_incident_update_inc
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE,
  CONSTRAINT fk_incident_update_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coverage_waitlist (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  address     VARCHAR(255) NOT NULL,
  name        VARCHAR(120) NOT NULL DEFAULT '',
  email       VARCHAR(120) NOT NULL DEFAULT '',
  phone       VARCHAR(40)  NOT NULL DEFAULT '',
  notes       TEXT         DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  status      ENUM('new','contacted','converted','dropped') NOT NULL DEFAULT 'new',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED DEFAULT NULL,
  username     VARCHAR(60)  DEFAULT NULL,
  action       VARCHAR(60)  NOT NULL,
  target_type  VARCHAR(40)  DEFAULT NULL,
  target_id    INT UNSIGNED DEFAULT NULL,
  meta         JSON         DEFAULT NULL,
  ip_address   VARCHAR(45)  DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at),
  KEY idx_user (user_id),
  KEY idx_action (action),
  KEY idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit (
  bucket      VARCHAR(80)  NOT NULL,
  counter     INT UNSIGNED NOT NULL DEFAULT 0,
  window_at   INT UNSIGNED NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_window (window_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
