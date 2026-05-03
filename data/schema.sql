-- WiFIBER database schema
--
-- Apply on a fresh database with:
--   mysql -h <host> -u <user> -p <db> < data/schema.sql
--
-- All tables use InnoDB + utf8mb4 so emoji / non-ASCII data is safe.

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(60)  NOT NULL,
  email                VARCHAR(120) NOT NULL DEFAULT '',
  name                 VARCHAR(100) NOT NULL DEFAULT '',
  role                 ENUM('admin','client') NOT NULL,
  phone                VARCHAR(40)  NOT NULL DEFAULT '',
  address              VARCHAR(200) NOT NULL DEFAULT '',
  package              VARCHAR(80)  NOT NULL DEFAULT '',
  password_hash        VARCHAR(255) NOT NULL,
  totp_secret          VARCHAR(64)  DEFAULT NULL,
  totp_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
  totp_recovery_codes  JSON         DEFAULT NULL,
  totp_enabled_at      DATETIME     DEFAULT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login           DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username),
  KEY idx_role (role)
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
