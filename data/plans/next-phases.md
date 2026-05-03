# Recipe: Phases 9-16 — what's left and worth adding

## Context

Phases 1-8 (commits `1929c11..79c0738`) shipped the UISP-equivalent
wireless link management stack: schema, 4 vendor adapters, polling
worker, push-to-radio worker with auto-rollback, link dashboard, freq
planner, audit, sites/devices CRUD, NOC tiles, Fresnel zone calc,
bulk ops, airtime fairness, on-demand poll, CSV export, cred-fail
auto-outage. Operators can do everything UISP can do plus a handful
it can't.

This recipe lists the eight follow-up phases that round the platform
out — none are blockers, all are high-leverage. Each lands on its
own branch off `main`, follows the same idempotent-migrations + flock
+ vendor-adapter patterns established in Phases 1-8, and ships
independently.

Each phase below is structured: **Goal → Files → Reused helpers →
Verification**. Estimated effort is rough and assumes the operator
already knows the codebase.

---

## Phase 9 — Customer notifications gateway

**Goal:** wire a real SMS / WhatsApp / email pipe so the existing
`auth/outages.php::outage_notify_affected()` (and new alerts from
Phases 10-12) actually reach customers instead of TODO-stub-emailing.
README already lists this as deferred.

**New files:**
- `auth/notifications.php` — uniform `notify_send($user, $template, $data)` API.
  Routes by user preference: SMS for outage opens, email for monthly
  invoices, WhatsApp for two-way replies.
- `auth/channels/smtp.php` — wraps PHPMailer or stream_socket against
  the existing email config. Most of `outage_notify_affected` already
  speaks SMTP — extract its sender into here.
- `auth/channels/sms.php` — Twilio / ClickSend / SMSPortal (SA-local).
  One adapter per provider, picked via `data/db.php::sms_provider`.
- `auth/channels/whatsapp.php` — Twilio Business API or 360dialog.
- `data/migrations/2026_05_03_phase14_notifications.sql`:
  - `notification_log` (user_id, channel, template, status, error,
    cost_zar, sent_at) — for the "did this customer get the
    cyclone-warning SMS?" question.
  - `users.notify_prefs JSON` — per-customer opt-in flags
    (sms_outage, whatsapp_outage, email_invoice, …).

**Extended:**
- `account/profile.php` — checkbox grid for notify_prefs.
- `auth/outages.php::outage_notify_affected` — replace the inline
  email loop with `notify_send()`.
- `bin/check-cable-snr.php` and the credential-fail outage path in
  `bin/poll-wireless.php` — call `notify_send()` for the responsible
  customers instead of just an audit row.

**Reused:** `auth/helpers.php` PDO + audit, `auth/users.php`,
existing PHPMailer setup if any.

**Verification:** Send yourself a test outage from
`/admin/outages.php` → manual create → confirm SMS arrives within
30 s, `notification_log` has a `sent` row and your phone bill shows
the right cost.

**Effort:** ~3 days (most of it is provider quirks).

---

## Phase 10 — Predictive link-health alerts

**Goal:** catch link problems hours-to-days before customers feel them.
Signal-drop alerts, link-budget regression, capacity-saturation
forecasts, automatic ticket creation.

**New files:**
- `bin/check-link-health.php` — nightly worker. For every
  `wireless_links` row with > 24 h of `link_health_samples`,
  computes:
  1. **Signal slope** — linear regression on `signal_local_dbm` over
     7 days. If extrapolated drop > 6 dB → alert (matches the cable
     SNR pattern but on RF).
  2. **Link budget** — theoretical signal from
     `tx_power_dbm + antenna_gain - free_space_loss(dist_km, freq_mhz)`
     vs. measured. If measured is > 8 dB worse than budget → "your
     install is degrading" alert.
  3. **Capacity saturation** — 7-day average of
     `throughput_local_mbps / capacity_local_mbps`. If > 80 % → "the
     sector needs splitting" recommendation.
- `data/migrations/2026_05_03_phase15_link_alerts.sql`:
  - `link_alerts` (link_id, kind ENUM, severity, opened_at,
    resolved_at, ticket_id, notes) — one row per active alert.
  - `devices.antenna_gain_dbi` and `devices.antenna_pattern` so the
    link-budget calc has the data it needs.

**Extended:**
- `admin/link-view.php` — new "Health trend" panel showing the
  7-day signal slope inline.
- `admin/links.php` — extra column for active `link_alerts` count,
  with a yellow/red badge.
- `auth/tickets.php::ticket_create_system($subject, $body, $client_id)`
  — new helper so workers can open tickets without forging a
  user_id.

**Reused:** `auth/wireless.php` link helpers, `bin/check-cable-snr.php`
linear regression code (extract `_linreg` to `auth/wireless.php`).

**Verification:** Throttle a CPE's TX power down 6 dB and wait 24 h;
the alert should land in `link_alerts` and a ticket should appear in
`/admin/tickets.php` against that customer.

**Effort:** ~2 days.

---

## Phase 11 — Coverage / RF heatmap on the network map

**Goal:** show predicted RSSI from each sector overlaid on the map so
sales / install-planning sees where signal is good before they sell.
UISP doesn't do this at all.

**New files:**
- `auth/coverage.php` — pure-PHP RF math:
  `predict_rssi($sector, $lat, $lng) → dBm` using Hata, free-space, or
  a simple two-ray model parametrised by the sector's
  `frequency_mhz`, `tx_power_dbm`, `antenna_gain_dbi`,
  `azimuth_deg`, `beamwidth_deg`, and the receiver lat/lng.
- `admin/map.php` (poll endpoint extension) — new
  `?coverage_for=SECTOR_ID` returns a 64×64 GeoJSON grid of predicted
  RSSI cells. Inline JS in `map.php` (similar to the wireless-link
  ring overlay) draws them as semi-transparent coloured tiles.
- `data/migrations/2026_05_03_phase16_antennas.sql` — adds
  `antenna_gain_dbi`, `antenna_pattern_h`, `antenna_pattern_v` to
  `sectors` (default to 16 dBi 90° / 30° if unset).

**Extended:**
- `assets/js/admin-map.js` (or inline in map.php) — toggle button:
  "Show predicted coverage for selected sector".
- `admin/sectors.php` — new "Coverage preview" link per row that
  opens the map pre-toggled.
- `coverage.php` (the public-site version) — overlay the same data
  for marketing.

**Reused:** Leaflet + map_data bootstrap, the existing
`window.WIFIBER_MAP` hook from Phase 7.

**Verification:** Drop a sector, set TX power 22 dBm / 30°
beamwidth, toggle coverage — green cells should fan out in the
azimuth direction up to ~3-5 km.

**Effort:** ~3 days (the RF math is the meat).

---

## Phase 12 — Active diagnostics (speed test, traceroute, looking glass)

**Goal:** on-demand, real-traffic measurements from inside the
network. Useful when a customer says "internet is slow" and you need
to prove it isn't the wireless link.

**New files:**
- `auth/diagnostics.php` — wrappers for each tool. Each returns a
  uniform DTO `{ok, command, output, parsed}`.
- `bin/run-diagnostic.php` — picks queued `diagnostic_jobs` rows and
  executes them via SSH (using `phpseclib3`) or REST. Same single-flight
  flock pattern as the other workers.
- `data/migrations/2026_05_03_phase17_diagnostics.sql`:
  - `diagnostic_jobs` (kind, scope, scope_id, requested_by, status,
    payload, result, started_at, finished_at) — same shape as
    `wireless_change_jobs`.
  - `link_speedtests` (link_id, mbps_down, mbps_up, jitter_ms,
    polled_at) — fact table for the link-view trend.

**Diagnostic kinds:**
- `iperf3` — between AP and CPE over SSH (RouterOS has it native;
  AirOS needs the binary uploaded once).
- `traceroute` — from any device with SSH access to a target IP.
- `ping_n` — sustained ping (1000 packets, jitter + loss).
- `bgp_lookup` — POP-side BGP table query (`/ip route print` on
  RouterOS, `show ip bgp` on Cisco/Juniper).

**Extended:**
- `admin/link-view.php` — "Run speed test" button below the dials.
  Polls `diagnostic_jobs.status` like the freq-move flow does.
- `admin/devices.php` — "Traceroute from here" inline on each row.
- `admin/audit.php` — new tab listing recent diagnostic jobs.

**Reused:** `wireless_change_jobs` queue pattern, `auth/totp.php::totp_require_step_up`
for diagnostics that change network state.

**Verification:** From `/admin/link-view.php?id=N` click "Run
speed test" → 30 s later a `link_speedtests` row exists, the link-
view header shows the latest Mbps.

**Effort:** ~3 days (`phpseclib3` + per-vendor SSH command parsing).

---

## Phase 13 — Multi-band freq planning + scheduled changes + maintenance windows

**Goal:** extend the freq planner past 5 GHz, queue changes for
off-peak, and suppress alerts during planned work.

**New files:**
- `data/migrations/2026_05_03_phase18_scheduling.sql`:
  - `wireless_change_jobs.scheduled_for DATETIME NULL` — workers
    skip rows whose `scheduled_for` is in the future.
  - `maintenance_windows` (scope, scope_id, starts_at, ends_at,
    reason, created_by) — alert routes look this up before paging.

**Extended:**
- `admin/freq-planner.php` — extend the channel grid with the 2.4
  GHz (1-13), 6 GHz (UNII-5/6/7/8) and 60 GHz blocks. Country
  regulatory data driven by `data/regulatory.json` (start with ZA;
  contributions encouraged).
- `admin/sector-edit.php` Apply form — new "Schedule for" datetime
  input. Defaults to "now".
- `admin/sectors.php` bulk apply — same.
- `bin/apply-wireless-changes.php` — `WHERE scheduled_for IS NULL OR
  scheduled_for <= NOW()`.
- `auth/outages.php::outage_create` — checks active
  `maintenance_windows` covering the scope; if matched, mark the
  outage `suppressed=1` and skip notifications.
- `admin/maintenance.php` — **new** small CRUD page for windows.

**Reused:** `wireless_change_jobs` queue, freq-planner's matrix.

**Verification:** Queue a freq move with `scheduled_for = tomorrow 03:00`,
confirm `apply-wireless-changes` doesn't pick it up tonight; open a
maintenance window matching the move's sector, confirm no
notifications fire when the rollback hypothetically opens an outage.

**Effort:** ~2 days.

---

## Phase 14 — Topology auto-discovery + configuration drift detection

**Goal:** stop hand-curating `site_links` and stop trusting that
DB-of-record matches the live radio config.

**New files:**
- `bin/discover-topology.php` — nightly worker. SNMP-walks LLDP /
  CDP MIBs on each switch / router to learn neighbours; emits
  candidate `site_links` rows for an admin to approve in
  `admin/topology-review.php`.
- `bin/check-config-drift.php` — nightly worker. For each device
  with credentials, runs `vendor_snapshot_config()` and compares
  live values against what the DB says (sectors.frequency_mhz,
  ssid, security, tx_power_dbm). Emits a `config_drift_alerts` row
  if they differ — useful for catching "someone logged into the AP
  and changed the channel manually".
- `data/migrations/2026_05_03_phase19_topology.sql`:
  - `site_link_candidates` (from_device_id, to_device_id, source
    ENUM('lldp','cdp','manual'), confidence, observed_at)
  - `config_drift_alerts` (device_id, field, expected, observed,
    detected_at, resolved_at)
- `admin/topology-review.php` — "Approve" / "Ignore" candidate
  links; approved → `site_links`.

**Extended:**
- `admin/devices.php` — drift badge on each row.
- `admin/index.php` NOC dashboard — drift count tile.
- `auth/vendors/*.php` — each gains a uniform `vendor_lldp_neighbours()`
  for the discoverer.

**Reused:** vendor adapter pattern, `admin/audit.php` for the alert
trail.

**Verification:** Manually change a frequency on an AP via its own
UI, leaving the WiFIBER DB untouched; next nightly drift check
should flag it.

**Effort:** ~4 days.

---

## Phase 15 — Customer self-serve link diagnostics + read-only NOC role

**Goal:** customers see their own link health without logging tickets;
NOC tier-1 operators can view everything without being able to push
to radios.

**New files:**
- `account/link-health.php` — customer-facing version of
  `admin/link-view.php`. Pulls only the customer's own
  `wireless_links` row(s). Shows: signal pill, recent outages on
  their sector, "run a speed test" button (queues a Phase-12 job).
- `data/migrations/2026_05_03_phase20_roles.sql`:
  - `users.role` ENUM gains `noc_readonly`.

**Extended:**
- `auth/helpers.php::require_role()` — accept arrays so a page can
  allow `['admin', 'noc_readonly']`.
- Every admin page that mutates state — gate by
  `require_role('admin')` while read-only pages drop to
  `require_role(['admin','noc_readonly'])`. Most existing
  `admin/index.php`, `outages.php`, `tickets.php` (read), `links.php`,
  `link-view.php`, `device-view.php`, `audit.php` qualify.
- `account/_layout.php` — add nav entry for "Link health".

**Reused:** existing role gating, `admin/link-view.php` rendering
(extract its inner panels into an includable partial).

**Verification:** Create a NOC tier-1 admin, log in, confirm the
"Apply" button on `/admin/sector-edit.php` is hidden / 403s, but
all view pages render normally.

**Effort:** ~2 days.

---

## Phase 16 — External integrations: webhooks out + REST API in

**Goal:** plug WiFIBER into existing tooling: Slack alerts, Splynx
billing sync, a Grafana data source for the bigger NOC stack.

**New files:**
- `auth/webhooks.php` — `webhook_fire($event, $payload)`. Looks up
  matching subscribers in the `webhooks` table and POSTs JSON with
  an HMAC signature header.
- `bin/webhooks-fanout.php` — picks queued webhook deliveries and
  retries with exponential backoff (matches the `apply-wireless-changes`
  retry pattern).
- `api/v1/*.php` — read-only JSON API:
  - `GET /api/v1/links` — list `wireless_links` (token-auth).
  - `GET /api/v1/links/{id}/samples?from=...&to=...` — time-series
    for Grafana.
  - `GET /api/v1/devices/{id}/health` — last 24 h.
  - `POST /api/v1/diagnostics` — enqueue a Phase-12 job
    (token-auth + same TOTP step-up if the destination is push-capable).
- `data/migrations/2026_05_03_phase21_integrations.sql`:
  - `webhooks` (url, secret, events JSON, last_fired_at, fail_count)
  - `webhook_deliveries` (webhook_id, event, payload, status,
    attempts, next_attempt_at)
  - `api_tokens` (user_id, token_hash, scopes JSON, expires_at)

**Extended:**
- `admin/integrations.php` — **new** CRUD page for webhooks + tokens.
- `auth/outages.php::outage_create` and `outage_resolve` — call
  `webhook_fire('outage.opened', …)` and `'outage.resolved'`.
- `bin/apply-wireless-changes.php` — `webhook_fire('wireless.config_applied', …)`.

**Reused:** existing `enforce_global_post_rate_limit` for the API,
audit log for token usage.

**Verification:** Register a webhook pointing at `webhook.site`; open
an outage; confirm the POST arrives within 5 s with a valid HMAC.

**Effort:** ~3 days.

---

## Suggested rollout order

1. **Phase 9 (notifications)** — unblocks every other phase that
   wants to alert customers. Do this first.
2. **Phase 10 (predictive)** — biggest customer-facing win for the
   work; surfaces problems before tickets.
3. **Phase 13 (scheduled + maintenance)** — small, makes the
   push-to-radio worker safer.
4. **Phase 14 (drift)** — catches a class of bugs that's hard to
   reproduce otherwise.
5. **Phase 11 (coverage)** — sales / planning win, less urgent.
6. **Phase 12 (diagnostics)** — needed once tickets blame "your
   wireless" too often.
7. **Phase 15 (self-serve + read-only role)** — customer-facing
   polish + NOC scaling.
8. **Phase 16 (integrations)** — only when you actually want a
   third-party sync.

Total effort ≈ 22 days end-to-end, single dev. Phases 9-13 are the
high-value chunk (≈ 12 days) and stand alone.

---

## What this recipe deliberately leaves out

- **Tower environmental telemetry** (UPS battery, temperature,
  fan failures, wind) — needs hardware (Sensorpush, Nagios+SNMP
  traps) we don't yet have. Add as Phase 17 once sensors are
  installed.
- **Mobile-native admin app** — the existing PHP admin pages are
  responsive enough on tablet. A dedicated app is a separate project,
  not part of this stack.
- **AI / ML link forecasting** — the Phase 10 linear-regression
  predictor handles 90 % of cases; ML adds complexity for marginal
  improvement on small (<1000 link) networks.
- **Cross-vendor MCS-rate normalisation** — vendors report different
  modulation labels. The existing `wireless_links.modulation` text
  field is good enough; canonicalising would be a week of work for
  cosmetic gain.

These can land later if/when the need is concrete.
