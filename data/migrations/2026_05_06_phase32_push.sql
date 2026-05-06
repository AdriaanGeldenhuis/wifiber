-- Phase 32 — Firebase Cloud Messaging (FCM) push channel.
--
-- Adds 'push' as a first-class delivery channel alongside email / SMS /
-- WhatsApp / Slack, plus a device_tokens table so the upcoming native
-- app can register an FCM token per install and we can deliver to every
-- live device for a user.
--
-- The actual FCM dispatch lives in auth/channels/push.php and reads
-- credentials from data/db.local.php (`notify_push` block) — same
-- pattern as the SMS / WhatsApp channels.
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_06_phase32_push.sql

-- 1. Add 'push' to the notification_log channel ENUM so logging works
--    end-to-end. Existing rows keep their value — the ENUM grows, no
--    data is rewritten.
ALTER TABLE notification_log
  MODIFY COLUMN channel ENUM('email','sms','whatsapp','slack','webhook','push')
                  NOT NULL DEFAULT 'email';

-- 2. device_tokens — one row per (user, device install). We keep the
--    full token in the row because FCM accepts unmodified tokens and a
--    token is what FCM hands the app at registration time. last_seen_at
--    is bumped every time the app pings us (login, foreground, etc.) so
--    a worker can prune dead installs.
CREATE TABLE IF NOT EXISTS device_tokens (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED  NOT NULL,
  platform      ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  token         VARCHAR(512)  NOT NULL,
  app_version   VARCHAR(32)   NOT NULL DEFAULT '',
  device_label  VARCHAR(120)  NOT NULL DEFAULT '',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  registered_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  KEY idx_dt_user (user_id, is_active),
  CONSTRAINT fk_dt_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
