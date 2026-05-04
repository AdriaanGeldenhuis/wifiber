-- Phase 22: append-only client notes log + multi-CPE per client.
--
-- Replaces the single users.notes textarea on the client editor with an
-- append-only log of timestamped, authored entries. The legacy column is
-- left in place so older readers don't break — new writes go to the new
-- table and the editor stops surfacing the textarea.
--
-- Adds a customer_id column on devices so an admin can link multiple
-- pieces of CPE (router + radio + switch) to the same client. The
-- existing single-CPE columns on the user record (equipment_*) remain
-- for legacy display.

CREATE TABLE IF NOT EXISTS client_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED DEFAULT NULL,
  body        TEXT         NOT NULL,
  is_pinned   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_client_notes_user (user_id, created_at),
  KEY idx_client_notes_pinned (user_id, is_pinned, created_at),
  CONSTRAINT fk_client_notes_user
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_client_notes_author
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED DEFAULT NULL AFTER site_id;

ALTER TABLE devices
  ADD KEY IF NOT EXISTS idx_dev_customer (customer_id);

-- Drop-then-add so re-running the migration cleanly replaces any prior
-- attempt without erroring on the duplicate-name check.
ALTER TABLE devices DROP FOREIGN KEY IF EXISTS fk_devices_customer;

ALTER TABLE devices
  ADD CONSTRAINT fk_devices_customer
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL;
