-- Phase 25 — Wireless-link PHY counters + Fresnel alert kind.
--
-- Extends link_health_samples with the per-poll PHY-layer counters that
-- AirOS (and to a lesser degree RouterOS / Cambium) expose, so the
-- per-link trend page can plot retransmit pressure alongside SNR. None
-- of these are required — vendor adapters write NULL when the radio
-- doesn't expose them, which is normal for older firmware.
--
-- wireless_links.mtu_bytes is per-link (not per-sample) — set once at
-- install time and almost never changes during the link's life.
--
-- link_alerts.kind ENUM gains 'fresnel_blocked' so check-link-health.php
-- can raise an alert when a link's measured signal sits >X dB worse
-- than the theoretical link budget AND its calculated Fresnel zone is
-- obstructed (the "you've got trees in the path" finding).
--
-- Apply with:
--   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_04_phase25_link_phy.sql

ALTER TABLE link_health_samples
  ADD COLUMN IF NOT EXISTS tx_retries  INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rx_retries  INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS ack_pct     DECIMAL(5,2) DEFAULT NULL;

ALTER TABLE wireless_links
  ADD COLUMN IF NOT EXISTS mtu_bytes   SMALLINT UNSIGNED DEFAULT NULL;

-- ENUM extension is rewrite-free in MariaDB / MySQL 8 because the new
-- value is appended (no existing rows reference it yet).
ALTER TABLE link_alerts
  MODIFY COLUMN kind ENUM('signal_drop','link_budget','capacity_saturation','fresnel_blocked') NOT NULL;
