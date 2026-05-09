-- Phase 37 — Nominatim geocode cache
--
-- Hashes search / reverse-geocode requests so a bulk client import or
-- a refreshing admin form doesn't keep hammering nominatim.openstreetmap.org
-- (their published rate limit is 1 req/sec, with usage-policy IP bans for
-- abuse). Local cache lookups are sub-millisecond — every cache hit saves
-- both a network round trip and a slot under the rate limit.
--
-- TTL is enforced application-side via created_at + a per-kind window.

CREATE TABLE IF NOT EXISTS nominatim_cache (
  cache_key   CHAR(64)        NOT NULL,
  kind        ENUM('search','reverse') NOT NULL,
  response    MEDIUMTEXT      NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cache_key),
  KEY nominatim_cache_kind (kind),
  KEY nominatim_cache_age  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
