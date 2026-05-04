-- Phase 23 — Site extras: attachments (photos, contracts, deeds) and
-- contacts (landlord, key-holder, security, technical). Closes the
-- site-side gaps identified in the network-integration audit so the
-- "Sites" section is a self-contained record of every tower/PoP.
--
-- site_attachments: blobs live in data/site-attachments/, served only
-- through admin/site-attachment.php (mirrors how ticket attachments
-- work — never directly web-accessible).
--
-- site_contacts: free-form roster of who to call when a site needs
-- access. Marked is_primary so the NOC can see the canonical contact
-- without having to read every row.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase23_site_extras.sql

CREATE TABLE IF NOT EXISTS site_attachments (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  site_id      INT UNSIGNED  NOT NULL,
  kind         ENUM('photo','contract','deed','diagram','permit','other') NOT NULL DEFAULT 'photo',
  file_path    VARCHAR(255)  NOT NULL,
  file_name    VARCHAR(255)  NOT NULL DEFAULT '',
  file_size    INT UNSIGNED  NOT NULL DEFAULT 0,
  mime         VARCHAR(80)   NOT NULL DEFAULT '',
  caption      VARCHAR(255)  NOT NULL DEFAULT '',
  uploaded_by  INT UNSIGNED  DEFAULT NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sa_site (site_id, kind, created_at),
  CONSTRAINT fk_sa_site
    FOREIGN KEY (site_id)     REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_uploader
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_contacts (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  site_id     INT UNSIGNED  NOT NULL,
  role        ENUM('landlord','key_holder','security','technical','municipal','other')
                            NOT NULL DEFAULT 'other',
  name        VARCHAR(120)  NOT NULL,
  phone       VARCHAR(40)   NOT NULL DEFAULT '',
  email       VARCHAR(120)  NOT NULL DEFAULT '',
  notes       TEXT          DEFAULT NULL,
  is_primary  TINYINT(1)    NOT NULL DEFAULT 0,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sc_site (site_id, is_primary, role),
  CONSTRAINT fk_sc_site
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
