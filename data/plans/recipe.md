# WiFIBER wireless link management — full recipe

Single master document covering the eight phases already shipped on
`claude/link-management-recipe-nfL6R` plus the eight phases still to
build. Read this top-to-bottom in a fresh session to pick up the work
without backtracking through chat history.

---

## Part 0 — Quick status

- **Branch:** `claude/link-management-recipe-nfL6R`
- **Commits to date:** 9 (`1929c11..c703336`), all pushed to `origin`
- **PR:** not yet opened. To open: `gh pr create` (or use the GitHub web
  UI from the branch's compare page).
- **Phases shipped:** 1 (schema) → 2 (vendor adapters + poll worker)
  → 3 (admin UI) → 4 (push-to-radio) → 5 (freq planner + cable SNR)
  → 6 (NOC tiles + README) → 7 (gap fill: sites CRUD, discover, 2FA,
  audit, map overlay, cred-fail outage) → 8 (Fresnel, on-demand poll,
  CSV, bulk freq, airtime fairness).
- **Phases pending:** 9 (notifications) through 16 (integrations) —
  see Part 3.

---

## Part 1 — One-time setup before the polling/push workers can run

### 1.1 Apply migrations

```bash
cd ~/public_html
git fetch origin claude/link-management-recipe-nfL6R
git checkout claude/link-management-recipe-nfL6R
php bin/migrate-schema.php
```

The script applies `data/schema.sql` then every file under
`data/migrations/` in name order. All migrations are idempotent
(`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`). Re-running
after a `git pull` is safe.

### 1.2 Generate and install the at-rest credential key

`auth/helpers.php::encrypt_secret` uses libsodium's secretbox to
encrypt every credential row in `device_credentials`. Without the
key the polling and push workers refuse to operate.

```bash
php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
```

Open `data/db.php` (or `data/db.local.php`) and set the value
returned above as `'device_key' => '...'`. See `data/db.php.example`
for the full block.

### 1.3 Cron block

Drop this into the user crontab on the production server. Existing
entries (invoicing, ICMP polling, outage detection) are preserved;
the four new lines are the wireless additions.

```cron
# Existing (already running)
0 6 * * *      /usr/bin/php ~/public_html/bin/invoices-cron.php          >> ~/invoices.log         2>&1
*/5 * * * *    /usr/bin/php ~/public_html/bin/poll-devices.php  --quiet  >> ~/poll-devices.log     2>&1
*  *  *  *  *  /usr/bin/php ~/public_html/bin/detect-outages.php --quiet >> ~/detect-outages.log   2>&1

# New for the wireless stack
*  *  *  *  *  /usr/bin/php ~/public_html/bin/poll-wireless.php          --quiet >> ~/poll-wireless.log  2>&1
*  *  *  *  *  /usr/bin/php ~/public_html/bin/apply-wireless-changes.php --quiet >> ~/apply-wireless.log 2>&1
*  *  *  *  *  sleep 30 && /usr/bin/php ~/public_html/bin/apply-wireless-changes.php --quiet >> ~/apply-wireless.log 2>&1
30 4 * * *     /usr/bin/php ~/public_html/bin/check-cable-snr.php        --quiet >> ~/cable-snr.log     2>&1
```

Each worker holds an exclusive `flock()` on `data/*.lock` so
overlapping cron ticks skip rather than pile up.

### 1.4 First-radio onboarding (smoke test)

1. `/admin/sites.php` — add one tower (lat/lng + height_m).
2. `/admin/devices.php` — add one AP (vendor=ubiquiti, role=ap,
   mgmt_ip set). Click **Creds** → save https credentials.
3. `/admin/sectors.php` — create a sector pointing at that AP.
4. `/admin/devices.php` next to the AP, click **Poll** (or run
   `php bin/poll-wireless.php --once --verbose --only-device=N`).
5. `/admin/links.php` should now have a row per station the AP sees,
   coloured by health.
6. `/admin/link-view.php?id=1` should look like the UISP screenshot,
   populated.

If anything fails: tail `~/poll-wireless.log`; the verbose flag
prints per-device errors (auth fails, decrypt fails, vendor adapter
errors).

---

## Part 2 — What's already built (Phases 1-8)

### Phase 1 — Schema foundation (commit `1929c11`)

Four idempotent migrations + libsodium helpers.

| File | What it adds |
|---|---|
| `data/migrations/2026_05_03_phase10_link_telemetry.sql` | `wireless_links` per-side TX power, airtime, throughput, capacity, mode, security, SSID, MAC pair, modulation. `sectors` SSID, security (encrypted), mode, TDD. `devices` LAN speed, cable diag, firmware channel. `device_health` airtime, capacity, throughput, firmware, byte counters. |
| `data/migrations/2026_05_03_phase11_credentials.sql` | `device_credentials` (per-device-per-scheme; password / SSH key / SNMP community / API token all encrypted at rest). |
| `data/migrations/2026_05_03_phase12_link_history.sql` | `link_health_samples`, `rf_environment_samples`, `ethernet_health` time-series tables. |
| `data/migrations/2026_05_03_phase13_change_jobs.sql` | `wireless_change_jobs` queue + `wireless_change_log` immutable audit. |
| `auth/helpers.php` | `encrypt_secret()` / `decrypt_secret()` / `device_secret_key()` keyed off `data/db.php::device_key`. |
| `data/db.php.example` | New `'device_key' => ''` slot. |

### Phase 2 — Vendor adapters + telemetry worker (commit `d4cc500`)

| File | What it does |
|---|---|
| `auth/wireless.php` | Shared link helpers: `wireless_link_*` CRUD + scoring + sample upsert, `device_credentials_*` save/load/unlock, `rf_environment_*`, `ethernet_health_*`, `wireless_change_job_*`, `wireless_change_log_record`, `haversine_km`, `distance_between_devices_km`, `wireless_links_degraded_count`. |
| `auth/vendors/airos.php` | Ubiquiti AirOS adapter — `airos_login`, `airos_poll_device`, `airos_snapshot_config`, `airos_apply_config`, `airos_revert_config`. Talks to `/login.cgi`, `/status.cgi`, `/sta.cgi`, `/iflist.cgi`, `/scan.cgi`, `/sockets.cgi`, `/cfg.cgi`, `/system.cgi`. |
| `auth/vendors/routeros.php` | Mikrotik RouterOS REST (v7+) adapter — `routeros_poll_device`, `routeros_snapshot_config`, `routeros_apply_config`, `routeros_revert_config`. PATCHes `/rest/interface/wireless/{.id}`. |
| `auth/vendors/cambium.php` | Cambium ePMP / cnPilot adapter — SNMPv2c + cnMaestro REST fallback. |
| `auth/vendors/mimosa.php` | Mimosa B5/C5 adapter — LuCI JSON API. |
| `bin/poll-wireless.php` | Polling worker. Loops devices with credentials, dispatches by `vendors.vendor`, writes `device_health` + `link_health_samples` + `rf_environment_samples` + `ethernet_health`. Auto-creates `wireless_links` rows for unknown stations. |

Worker behaviour:
- Auto-creates a "pending" CPE device row when an AP reports an
  unknown station MAC, so onboarding a CPE is just "register it,
  link materialises on next poll".
- `--only-device=N`, `--only-vendor=ubiquiti`, `--once`, `--verbose`,
  `--retention-days=N`, `--rf-retention=H`.

### Phase 3 — Admin UI (commit `9fca6e1`)

| File | What it does |
|---|---|
| `admin/links.php` | Grid of every `wireless_links` row, ordered by health (worst first), green/yellow/red pills, search/filter, pre-stage form. |
| `admin/link-view.php` | The UISP-screenshot dashboard — server-rendered SVG charts (signal/noise/interference, RF env bars, CINR gauge), More Details / Wireless / Ethernet panels for both ends. No JS chart library. |
| `admin/sector-edit.php` | Two forms: edit sector record (DB only) + "Apply to radio" which enqueues a `wireless_change_jobs` row. Lists recent jobs for the sector with Cancel for queued ones. |
| `admin/devices.php` | New "Creds" details panel per row — manage `device_credentials` rows (encrypted at rest). |
| `admin/_layout.php` | Sidebar gains "Wireless links" + "Frequency planner". |

### Phase 4 — Push-to-radio worker (commit `13a0017`)

| File | What it does |
|---|---|
| `bin/apply-wireless-changes.php` | Picks queued jobs, snapshots live config, pushes CPE-first then AP for coordinated freq moves, waits 60 s for reconvergence, reverts everything from snapshot if the link doesn't recover, opens an outage on rollback so the NOC sees it. |

Vendor adapters' `*_apply_config` and `*_revert_config` were
written in Phase 2 alongside the read-only methods, so Phase 4 only
needed the orchestrator.

### Phase 5 — Freq planner + cable SNR (commit `61646af`)

| File | What it does |
|---|---|
| `admin/freq-planner.php` | Sector × channel matrix coloured by 24h `rf_environment_samples` interference. Black outline = current channel; green outline = recommended. **Apply recommendation** queues a coordinated freq move per sector. |
| `bin/check-cable-snr.php` | 7-day linear-regression slope on `ethernet_health.cable_snr_db` per device. Drop > 3 dB → audit alert. UISP shows the value, doesn't trend it. |

### Phase 6 — README + NOC tiles (commit `596c581`)

- `README.md` gains "Wireless link management", "Onboarding a new
  tower", "Moving a sector to a new frequency" sections plus the
  cron block.
- `/admin/` (NOC dashboard) — two new tiles: "Wireless links" with
  degraded count, "Pending changes" queue depth.

### Phase 7 — Gap fill (commit `f763b3a`)

| Target | What got fixed |
|---|---|
| `admin/sites.php` | New full-CRUD sites page (was only sidebar on map). |
| `admin/devices.php` | Discover panel: HTTPS-probes a /24 with `curl_multi`, lists responders. |
| `admin/sector-edit.php` + `admin/freq-planner.php` | 2FA step-up via new `auth/totp.php::totp_require_step_up`. |
| `admin/audit.php` | New "Wireless config changes" panel reading `wireless_change_log`. |
| `admin/map.php` + `assets/js/admin-map.js` | Map exposes `window.WIFIBER_MAP`; coloured ring on every AP site indicating worst link health. |
| `bin/poll-wireless.php` | Auto-opens device-scope outage at 3 consecutive auth fails; auto-resolves on next success. |

### Phase 8 — Polish (commit `79c0738`)

| Target | What got added |
|---|---|
| `admin/link-view.php` | `?tab=fresnel` — 1st-Fresnel-zone radius, 60% clearance, 4/3-Earth bulge, clearance-margin pill from AP/CPE site heights. |
| `admin/devices.php` | "Poll" button next to "Ping" — runs vendor adapter synchronously for one device. |
| `admin/links.php` | "Export CSV" respecting current filters. |
| `admin/sectors.php` | Multi-select checkboxes + bulk apply toolbar; each selected sector becomes its own `wireless_change_jobs` row. 2FA gate. |
| `admin/reports.php` | Airtime-fairness panel — top 25 customers by last-hour airtime share. |

---

## Part 3 — What's left to build (Phases 9-16)

The full per-phase file-paths-and-helpers spec lives in
`data/plans/next-phases.md`. Suggested rollout order matches the
list below; each phase is independent and reverts cleanly.

### Phase 9 — Customer notifications gateway (≈3 days)

Real SMS / WhatsApp / email pipe so existing
`outage_notify_affected()` and the new alerts from Phases 10-12
actually reach customers.

- New: `auth/notifications.php`, `auth/channels/{smtp,sms,whatsapp}.php`,
  migration adding `notification_log` + `users.notify_prefs`.
- Extend: `account/profile.php` (opt-in checkboxes),
  `auth/outages.php` (route through `notify_send()`),
  `bin/check-cable-snr.php`, `bin/poll-wireless.php` cred-fail path.

### Phase 10 — Predictive link-health alerts (≈2 days)

Catch problems hours-to-days before customers feel them.

- New: `bin/check-link-health.php` — signal-slope regression,
  link-budget vs measured, capacity saturation forecast.
- New: `link_alerts` table, `devices.antenna_gain_dbi` /
  `antenna_pattern`.
- New: `auth/tickets.php::ticket_create_system` for worker-driven
  tickets without a forged user_id.
- Extend `admin/link-view.php` with a Health-trend panel.

### Phase 11 — Coverage / RF heatmap on the map (≈3 days)

Predicted RSSI overlay per sector. Sales / planning win.

- New: `auth/coverage.php` — Hata / free-space / two-ray RF math.
- New: `?coverage_for=SECTOR_ID` poll endpoint returning a 64×64
  GeoJSON grid; inline JS in `admin/map.php` draws coloured tiles.
- Migration adds `sectors.antenna_gain_dbi` and pattern columns.

### Phase 12 — Active diagnostics (≈3 days)

iperf3 / traceroute / BGP looking-glass on demand from
`/admin/link-view.php`. Same `wireless_change_jobs`-shaped queue
pattern.

- New: `auth/diagnostics.php`, `bin/run-diagnostic.php`,
  `diagnostic_jobs` + `link_speedtests` tables.
- Uses `phpseclib3` for SSH to vendors that support iperf3 natively
  (RouterOS); AirOS needs the iperf3 binary uploaded once.

### Phase 13 — Scheduled changes + maintenance windows + multi-band (≈2 days)

- Add `wireless_change_jobs.scheduled_for` so the worker only picks
  up rows whose time has come.
- New `maintenance_windows` table; `outage_create` checks it before
  paging customers.
- Extend `admin/freq-planner.php` channel grid to 2.4 / 6 / 60 GHz
  driven by `data/regulatory.json`.

### Phase 14 — Topology auto-discovery + config drift detection (≈4 days)

Stop hand-curating `site_links` and stop trusting that the DB
matches the live radio config.

- New: `bin/discover-topology.php` — SNMP-walks LLDP / CDP MIBs,
  emits `site_link_candidates`.
- New: `bin/check-config-drift.php` — compares
  `vendor_snapshot_config()` to DB-of-record, emits
  `config_drift_alerts`.
- New: `admin/topology-review.php` (approve/ignore candidates).

### Phase 15 — Customer self-serve + read-only NOC role (≈2 days)

- New: `account/link-health.php` — customer-facing link view.
- New: `users.role` ENUM gains `noc_readonly`.
- Refactor `auth/helpers.php::require_role()` to accept arrays so
  read-only pages allow `['admin','noc_readonly']`.

### Phase 16 — External integrations (≈3 days)

- New: `auth/webhooks.php`, `bin/webhooks-fanout.php`, `webhooks` +
  `webhook_deliveries` + `api_tokens` tables.
- New: `api/v1/*.php` — read-only JSON for Grafana / Splynx;
  POST-able diagnostic queue.
- HMAC-signed delivery + retry with exponential backoff.

---

## Part 4 — Reused helpers cheat-sheet

When extending the code, reuse these rather than inventing new
patterns. They're battle-tested by Phases 1-8.

| Helper | Location | Used for |
|---|---|---|
| `pdo()` | `auth/helpers.php` | DB connection (singleton) |
| `csrf_field()` / `require_csrf()` | `auth/helpers.php` | Forms |
| `flash($type, $msg)` / `pop_flash()` | `auth/helpers.php` | Per-redirect alerts |
| `audit_log($action, [...])` | `auth/helpers.php` | Audit trail |
| `encrypt_secret()` / `decrypt_secret()` | `auth/helpers.php` | At-rest credential encryption |
| `totp_require_step_up($user, $code)` | `auth/totp.php` | 2FA gate on push actions |
| `flock()` lock pattern | `bin/poll-devices.php` | Single-flight cron workers |
| `device_record_poll_result($id, $ok, $rtt)` | `auth/devices.php` | ICMP debounce + status flip |
| `outage_create($scope, $id, $label, $count, $cause)` | `auth/outages.php` | NOC-visible outage rows |
| `outage_active($scope, $id)` / `outage_resolve($id, $note)` | `auth/outages.php` | Outage life-cycle |
| `wireless_change_job_enqueue($scope, $id, $user, $payload)` | `auth/wireless.php` | Queue a push-to-radio job |
| `wireless_change_log_record([...])` | `auth/wireless.php` | Per-device push audit row |
| `device_credentials_unlock($row)` | `auth/wireless.php` | Decrypt credential into vendor-adapter DTO |
| `haversine_km($lat1, $lng1, $lat2, $lng2)` | `auth/wireless.php` | Site-to-site distance |
| `csv_download($filename, $rows, $columns)` | `auth/csv.php` | Standardised CSV exports |

Vendor adapter contract (each vendor file in `auth/vendors/`):

```php
function VENDOR_poll_device(array $device, array $cred): array
function VENDOR_snapshot_config(array $device, array $cred): array
function VENDOR_apply_config(array $device, array $cred, array $payload): array
function VENDOR_revert_config(array $device, array $cred, array $snapshot): array
```

All four return `['ok' => bool, 'error' => string, ...]`.
`poll_device` additionally returns `device`, `links`, `rf_env`,
`ethernet`, `firmware`, `mac`, `serial`, `model`.

---

## Part 5 — Design invariants (don't violate)

1. **Migrations are append-only and idempotent.** Never edit a
   shipped migration; write a new one. `IF NOT EXISTS` on every
   `ADD COLUMN` / `ADD KEY` / `CREATE TABLE`.
2. **Vendor I/O lives only in `auth/vendors/*.php`.** The polling
   worker, push worker, and admin pages must dispatch through the
   four-function adapter contract — never `curl` directly from
   `bin/` or `admin/`.
3. **Every push-to-radio action is queued, snapshotted, and
   reversible.** If you add a new push kind, it goes through
   `wireless_change_jobs` so the existing rollback / outage / audit
   paths apply.
4. **Secrets are never written plaintext.** Use `encrypt_secret()`.
   Workers refuse to run if `device_key` is unset.
5. **No JS chart libraries.** Server-rendered SVG, matching
   `admin/device-view.php` and `admin/link-view.php`.
6. **2FA step-up on any state-changing push.** Apply / queue /
   bulk-apply / freq-planner / future Phase 12 diagnostic-runs all
   call `totp_require_step_up()`.
7. **Single-flight via `flock()`** on every cron worker. Lock files
   under `data/*.lock` (already in `.gitignore`).
8. **Audit everything.** `audit_log()` for high-level admin
   actions, `wireless_change_log_record()` for per-device push
   detail.

---

## Part 6 — How to start the next session

1. `git checkout claude/link-management-recipe-nfL6R`.
2. Read this file (`data/plans/recipe.md`) top-to-bottom.
3. Open `data/plans/next-phases.md` for the per-phase build spec.
4. Pick the next phase from Part 3's suggested order (default: 9 →
   notifications), branch off this branch, follow the per-phase
   "Files / Reused / Verification" block.
5. When the phase is verified end-to-end, commit with the same
   message style used by Phases 1-8 (one paragraph of intent
   + bulleted file list), push, open a PR if not already open.
6. Update **Part 0** at the top of this file with the new commit
   range and phase status before merging.

The branch is in a known-good, deployable state at every commit so
you can ship Phases 9-16 incrementally without holding a giant PR.
