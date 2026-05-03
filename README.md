# WiFIBER

Custom PHP/CSS/JS site, customer portal and home-grown NOC for
[wifiber.co.za](https://wifiber.co.za) &mdash; a wireless ISP serving
the Vaal Triangle. Replaces the previous UISP integration with a
native device, sector, polling and outage stack.

## Stack

- PHP 8 (no framework, no build step)
- MariaDB / MySQL (utf8mb4, InnoDB, foreign keys enforced)
- Vanilla CSS (custom properties for theming)
- Vanilla JS + Leaflet for the network map
- Apache `.htaccess` for pretty URLs, HTTPS redirect, security headers and caching
- Cron-driven CLI workers for device polling, outage detection and invoicing

## Layout

```
.
├── index.php / pricing.php / coverage.php / legal.php / contact.php
├── status.php           # Public service-status page (incidents + outages)
├── 404.php / sitemap.php / robots.txt
├── .htaccess
│
├── includes/            # Public-site templating (config, header, footer)
├── assets/              # CSS, JS, images
│
├── admin/               # Admin portal (NOC + CRM + billing)
│   ├── index.php          NOC dashboard
│   ├── map.php            Network map with sectors and outage overlay
│   ├── devices.php        Devices CRUD + filter + Ping-now
│   ├── device-view.php    Single device: 24h RTT sparkline + samples
│   ├── sectors.php        Sectors CRUD with capacity column
│   ├── outages.php        Auto-detected outages: active + history
│   ├── reports.php        Customer growth, revenue, outage stats, capacity
│   ├── clients.php / client-edit.php   CRM
│   ├── products.php / pricing.php       Catalogue + public pricing
│   ├── invoices.php / invoice-edit.php  Billing
│   ├── tickets.php / incidents.php      Support + service status
│   ├── audit.php / admins.php / settings.php / 2fa.php / images.php / coverage.php / legal.php / slides.php
│   └── _layout.php / _users-table.php
│
├── account/             # Customer portal
│   ├── index.php          Dashboard (connection, invoices, tickets)
│   ├── profile.php / invoices.php / tickets.php / password.php / forgot.php / reset.php
│   └── _layout.php        (renders the outage banner site-wide)
│
├── auth/                # Shared helpers used by both portals + cron
│   ├── helpers.php        Sessions, CSRF, PDO, audit log, rate limit, users
│   ├── totp.php           2FA
│   ├── sites.php          Sites + site_links + Nominatim geocoder
│   ├── devices.php        Devices CRUD + ICMP ping + status flip
│   ├── sectors.php        Sectors CRUD with customer + AP joins
│   ├── outages.php        Outage CRUD + auto-detector
│   ├── products.php / invoices.php / tickets.php / incidents.php / coverage.php
│   └── portal-header.php / portal-footer.php
│
├── bin/                 # CLI workers (cron + ad-hoc)
│   ├── poll-devices.php       ICMP polling worker
│   ├── detect-outages.php     Auto-detect / auto-resolve outages
│   ├── invoices-cron.php      Monthly billing + overdue reminders
│   ├── backup-data.php        Database snapshot
│   ├── migrate-schema.php     Apply data/migrations/*.sql in order
│   ├── migrate-users-to-db.php
│   ├── deploy.sh / rollback.sh
│
└── data/
    ├── schema.sql                Full schema for fresh installs
    ├── migrations/               Numbered ALTER scripts (apply in order)
    ├── db.php / db.local.php     DB credentials (gitignored)
    ├── site.json / pricing.json / coverage.json / slides.json / legal.json
    ├── admin-ips.json            Optional admin IP allowlist
    └── *.lock                    Single-flight locks for cron workers (gitignored)
```

## Database & migrations

Fresh install:

```bash
mysql -h <host> -u <user> -p <db> < data/schema.sql
```

For an existing install, apply the numbered migration scripts in
order (each is idempotent thanks to `IF NOT EXISTS` and
`IF EXISTS` clauses, so re-running is safe):

```
data/migrations/2026_05_03_extend_clients.sql
data/migrations/2026_05_03_phase2_products.sql
data/migrations/2026_05_03_phase3_sites.sql
data/migrations/2026_05_03_phase5_drop_uisp.sql      ← drops the old UISP cache
data/migrations/2026_05_03_phase6_devices.sql        ← devices + device_health
data/migrations/2026_05_03_phase7_sectors.sql        ← sectors + wireless_links
data/migrations/2026_05_03_phase8_users_sector.sql   ← users.sector_id FK
data/migrations/2026_05_03_phase9_outages.sql        ← outages table
```

`bin/migrate-schema.php` walks this directory and applies anything
unapplied (it tracks state in a small bookkeeping table). Running it
manually is fine; running it from a deploy hook is also fine.

## Cron jobs

All paths assume the production install location
`/usr/home/wifibfjedj/public_html` &mdash; adjust accordingly.

```cron
# Monthly invoicing + overdue reminders
0 6 * * *      /usr/bin/php ~/public_html/bin/invoices-cron.php  >> ~/invoices.log 2>&1

# Device polling — ICMP ping each device, flip online/offline
*/5 * * * *    /usr/bin/php ~/public_html/bin/poll-devices.php   --quiet >> ~/poll-devices.log 2>&1

# Outage auto-detect / auto-resolve
* * * * *      /usr/bin/php ~/public_html/bin/detect-outages.php --quiet >> ~/detect-outages.log 2>&1
```

Each worker holds a `flock()` on a `data/*.lock` file so a slow run
can't pile up overlapping cron ticks.

## Network polling

`bin/poll-devices.php` ICMP-pings every non-retired device with a
`mgmt_ip` set, in parallel batches (default 32 at a time, override
with `--max-parallel=N`). For each result it inserts a row into
`device_health` and flips `devices.status` between `online` and
`offline` &mdash; but only when the last two samples agree, so a
single dropped packet doesn't churn things. Successful pings bump
`devices.last_seen_at`. Old samples are pruned at 30 days
(`--retention=DAYS`).

The `Ping` button on each row of `/admin/devices.php` runs the same
single-device pipeline synchronously and flashes the result.

`/admin/device-view.php?id=N` shows a 24-hour SVG sparkline of RTT
plus the most recent 100 samples. Pure server-rendered SVG &mdash;
no charting library, no JS.

Vendor-specific polling (RouterOS API, AirOS SSH, SNMP) isn't in
this worker yet; the per-vendor adapters that fill in CPU / memory /
signal / client-count will write to the same `device_health` table
when they land.

## Outages

A sector outage opens when its assigned AP device flips to `offline`
and resolves when the AP comes back online. The detector deliberately
ignores `unknown` (haven't-polled-yet) so first-poll noise doesn't
open false outages.

Where outages surface:
- `/admin/` &mdash; red panel at the top of the NOC dashboard
- `/admin/outages.php` &mdash; active list + manual close + 50 most
  recent resolved
- `/admin/map.php` &mdash; affected sector cones outline red, towers
  with any active outage wear a red halo + corner badge
- `/admin/client-edit.php` &mdash; "Network activity" card shows the
  active outage (if any) plus 5 most recent on this customer's sector
- `/admin/reports.php` &mdash; 30-day MTTR, customer-minutes, opens-
  per-day bar chart
- `/account/` &mdash; affected customers see a yellow banner on every
  page of their portal: "We're aware. You don't need to log a ticket."
- `/status.php` &mdash; public page rolls outages up to the tower
  level and surfaces them alongside manually-published incidents

Customer notifications (SMS / WhatsApp / email) are deferred until
a gateway is wired in.

## Deploying to the server

The site lives in `/usr/home/wifibfjedj/public_html` (a.k.a.
`~/public_html`) on the production server.

```bash
cd ~/public_html
git pull origin main
# Apply any new migrations:
php bin/migrate-schema.php
```

PHP runs straight from the working tree &mdash; no build step.

## Editing the site yourself

You don't need to know PHP to update the common stuff. Three files
cover most edits:

### 1. Phone, emails, address &mdash; `includes/config.php`
Open this file and edit the values inside `$site = [ ... ]`.

### 2. Homepage slider &mdash; `includes/slides.php`
Each slide is one block in the array. Comments at the top of the file
explain every field. To add a slide, copy a block. To remove one,
delete its block. To reorder, cut/paste the blocks in the order you
want.

To swap a slider image:
1. Save your new image (1920&times;1080 JPG/WEBP works best) into
   `assets/images/slider/` &mdash; e.g. `slider-1.webp`.
2. Make sure the `image` field in `slides.php` matches the filename.

### 3. Brand colours &mdash; top of `assets/css/style.css`
The CSS custom properties at the very top (`--bg`, `--accent`, etc.)
drive the whole theme.
