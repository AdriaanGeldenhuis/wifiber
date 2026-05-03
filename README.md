# WiFIBER

Custom PHP/CSS/JS website for [wifiber.co.za](https://wifiber.co.za) &mdash; a wireless ISP serving the Vaal Triangle.

## Stack

- Plain PHP (no framework, no build step) for templating and the contact form
- Vanilla CSS (custom properties for theming)
- Vanilla JS for the mobile menu, pricing tier switcher, legal-page tabs and form submission
- Apache `.htaccess` for pretty URLs, HTTPS redirect, security headers and caching

## Layout

```
.
├── index.php          # Home
├── pricing.php        # Pricing (3 tiers x 8 speeds)
├── coverage.php       # Coverage map
├── legal.php          # POPI / T&Cs / Code of Conduct / Cookies
├── contact.php        # Contact form handler (mail())
├── 404.php            # Not-found page
├── .htaccess          # Routing, HTTPS, security, caching
├── includes/
│   ├── config.php     # Site-wide constants (phone, emails, address)
│   ├── header.php     # <head>, header bar, opens <main>
│   └── footer.php     # closes <main>, footer, scripts
└── assets/
    ├── css/style.css
    ├── js/main.js
    └── images/        # logos, partner logos, coverage map
```

## UISP integration

The admin network map (`/admin/map.php`) can pull live sites, devices,
data-links and CRM clients from a UISP installation and overlay them on
the same map as the manually-maintained records.

### One-time setup

1. Apply the migration:

   ```bash
   mysql -h <host> -u <user> -p <db> < data/migrations/2026_05_03_phase4_uisp.sql
   ```

2. In UISP, generate an **App key** under *Settings &rarr; Integrations*.

3. Open `/admin/uisp.php`, paste the base URL (e.g. `https://uisp.example.com`)
   and the App key, click **Save settings**, then **Test connection** and
   **Sync now**. Status, version and per-entity counts appear below.

4. (Optional) Schedule background syncs:

   ```cron
   0,15,30,45 * * * *  /usr/bin/php ~/public_html/bin/uisp-sync.php --quiet >> ~/uisp-sync.log 2>&1
   ```

### What the map shows

- **UISP sites** &mdash; diamond markers, purple by default
- **UISP devices** &mdash; ring markers coloured by status (green online, red offline)
- **UISP data-links** &mdash; dashed lines coloured by link state
- **UISP CRM clients** &mdash; small ring markers placed at their address GPS

A manually-managed site or client can be **adopted** to its UISP counterpart
(via the popup dropdowns or the table on `/admin/uisp.php`). Adopted
records merge into a single map marker and the manual popup shows the
UISP status pill plus a per-site device-status summary. Linking is
reversible.

If UISP is unreachable, the map keeps working from the cached snapshot
and shows an "UISP offline" banner.

## Deploying to the server

The site lives in `/usr/home/wifibfjedj/public_html` (a.k.a. `~/public_html`) on the production server.

### One-time setup (already done if you ran the SSH key steps)

```bash
cd ~/public_html
git remote -v   # should point at git@github.com:AdriaanGeldenhuis/wifiber.git
```

### Deploying changes

```bash
cd ~/public_html
git pull origin main
```

That's it. PHP runs straight from the working tree &mdash; no build step.

## Editing the site yourself

You don't need to know PHP to update the common stuff. Three files cover most edits:

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
The CSS custom properties at the very top (`--bg`, `--accent`, etc.) drive the whole theme.
