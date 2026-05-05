/* Admin network map — Leaflet over OSM/Esri tiles. Reads bootstrap data
   from the inline <script type="application/json" id="map-data"> tag and
   talks back to /admin/map.php?ajax=1 for adds, moves, edits and deletes.

   Modes:
     pan         (default — click sites/sectors/clients for popups)
     add_site    (click empty map to drop a new site)
     add_link    (click two sites to connect them)
     add_sector  (anchored on a tower; first click sets azimuth, mouse
                  movement after that adjusts beamwidth, second click
                  opens the sector form)
*/

(function () {
  'use strict';

  const dataEl = document.getElementById('map-data');
  if (!dataEl || typeof L === 'undefined') return;

  const boot = JSON.parse(dataEl.textContent);
  const CSRF = boot.csrf;
  const ENDPOINT = '/admin/map.php?ajax=1';

  /* ---------- helpers ---------- */
  const escapeHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  async function postAction(action, body) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', CSRF);
    Object.entries(body || {}).forEach(([k, v]) => fd.append(k, v == null ? '' : v));
    const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
    let json;
    try { json = await res.json(); } catch { json = { ok: false, error: 'Server error' }; }
    return json;
  }

  /* ---------- map + layers ---------- */

  // Persisted view state — operator returns to the same zoom, pan,
  // tile (Streets / Satellite) and toggle layout they left.
  // Versioned so we can change the schema without colliding with
  // somebody's stale entry.
  const MAP_STATE_KEY = 'wifiber.admin-map.v1';
  function loadMapState() {
    try { return JSON.parse(localStorage.getItem(MAP_STATE_KEY) || 'null'); }
    catch (e) { return null; }
  }
  function saveMapState(patch) {
    try {
      const cur = loadMapState() || {};
      const merged = Object.assign(cur, patch);
      if (patch && patch.toggles) {
        merged.toggles = Object.assign(cur.toggles || {}, patch.toggles);
      }
      localStorage.setItem(MAP_STATE_KEY, JSON.stringify(merged));
    } catch (e) { /* localStorage unavailable / quota — ignore */ }
  }
  const savedState = loadMapState();
  const initialCenter = (savedState && Array.isArray(savedState.center) && savedState.center.length === 2)
                        ? savedState.center : boot.center;
  const initialZoom   = (savedState && typeof savedState.zoom === 'number') ? savedState.zoom : boot.zoom;

  const map = L.map('map', { zoomControl: true }).setView(initialCenter, initialZoom);
  // Expose the map for inline overlays (wireless link health rings, etc.)
  // that live in PHP files rather than this bundle.
  window.WIFIBER_MAP = map;

  const tileLayers = {
    Streets: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }),
    Satellite: L.tileLayer(
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { attribution: 'Tiles &copy; Esri', maxZoom: 19 }
    ),
  };
  const initialTile = (savedState && tileLayers[savedState.tile]) ? savedState.tile : 'Streets';
  tileLayers[initialTile].addTo(map);
  L.control.layers(tileLayers, {}, { position: 'topright', collapsed: false }).addTo(map);

  // Persist viewport + tile choice on every change.  Round to 6 dp so
  // the localStorage payload doesn't churn on sub-meter pan jitter.
  map.on('moveend zoomend', () => {
    const c = map.getCenter();
    saveMapState({
      center: [+c.lat.toFixed(6), +c.lng.toFixed(6)],
      zoom:   map.getZoom(),
    });
  });
  map.on('baselayerchange', (e) => {
    if (e && e.name) saveMapState({ tile: e.name });
  });

  const sitesLayer    = L.layerGroup().addTo(map);
  const linksLayer    = L.layerGroup().addTo(map);
  const clientsLayer  = L.layerGroup().addTo(map);
  const sectorsLayer  = L.layerGroup().addTo(map);
  const coverageLayer = L.layerGroup();
  // Sector preview lives outside sectorsLayer so it can't be hidden by
  // the user's "Sectors" toggle while they're mid-draw.
  const previewLayer  = L.layerGroup().addTo(map);

  /* ---------- icons ---------- */
  const SITE_COLOR    = { tower: '#08e', ap: '#0c8', ptp_endpoint: '#f80', pop: '#80f', other: '#888' };
  const STATUS_COLOR  = { active: '#0c8', lead: '#08e', suspended: '#fa0', disconnected: '#888' };
  const LINK_COLOR    = { ptp: '#08e', ptmp: '#0c8', fiber: '#f0a', backhaul: '#f80' };
  const DEVICE_COLOR  = { online: '#0c8', offline: '#d44', unknown: '#888', retired: '#555' };
  const BAND_COLOR    = { '2.4GHz': '#f80', '5GHz': '#08e', '6GHz': '#80f', '60GHz': '#f0a', 'other': '#888' };

  // Optional `badgeColor` paints a small notification badge on the
  // top-right corner of the dot — used to flag e.g. a customer marker
  // whose sector AP is offline without disturbing the existing
  // billing-status colour scheme.
  function dotIcon(color, size, badgeColor) {
    const s = size || 14;
    const badge = badgeColor
      ? '<span style="position:absolute;top:-2px;right:-2px;width:7px;height:7px;border-radius:50%;background:'
        + badgeColor + ';border:1.5px solid #fff;box-shadow:0 0 2px rgba(0,0,0,.6);"></span>'
      : '';
    return L.divIcon({
      className: 'wf-marker',
      html: '<span style="position:relative;display:block;width:' + s + 'px;height:' + s
          + 'px;border-radius:50%;background:' + color
          + ';border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.5);">' + badge + '</span>',
      iconSize: [s + 6, s + 6],
      iconAnchor: [(s + 6) / 2, (s + 6) / 2],
    });
  }

  // Customer marker badge — flags network-side problems even when the
  // billing status is fine.
  const CLIENT_BADGE_COLOR = { offline: '#d44', unknown: '#aaa' };

  /* ---------- state ---------- */
  let mode = 'pan';            // 'pan' | 'add_site' | 'add_link' | 'add_sector'
  let pendingLinkFrom = null;  // first site clicked in add_link
  let sectorDraft = null;      // { tower, stage: 'azimuth' | 'beamwidth', azimuth, beamwidth }
  const siteIndex     = new Map(); // id -> {data, marker}
  const linkLines     = new Map(); // id -> {line, data}
  const sectorIndex   = new Map(); // id -> {data, layer}
  const devicesBySite = new Map(); // site_id -> [device, ...]
  const sectorsByTower= new Map(); // tower_id -> Set(sector_id)

  /* ---------- index seeds ---------- */
  (boot.devices || []).forEach((d) => {
    if (d.site_id == null) return;
    const sid = parseInt(d.site_id, 10);
    if (!devicesBySite.has(sid)) devicesBySite.set(sid, []);
    devicesBySite.get(sid).push(d);
  });

  /* ---------- outage indexes ---------- */
  const outageSectorIds  = new Set((boot.outages && boot.outages.sector_ids) || []);
  const outageTowerIds   = new Set((boot.outages && boot.outages.tower_ids)  || []);
  const outageBySectorId = (boot.outages && boot.outages.by_sector_id) || {};

  /* ---------- mode toggling ---------- */
  function setMode(next, ctx) {
    mode = next;
    pendingLinkFrom = null;
    sectorDraft = null;
    previewLayer.clearLayers();

    document.querySelectorAll('[data-mode]').forEach((b) => {
      b.classList.toggle('map-mode-active', b.dataset.mode === mode);
    });

    const cur = map.getContainer();
    cur.style.cursor = mode === 'add_site'   ? 'crosshair'
                    :  mode === 'add_link'   ? 'pointer'
                    :  mode === 'add_sector' ? 'crosshair'
                    :  '';

    if (mode === 'add_sector' && ctx && ctx.tower) {
      sectorDraft = { tower: ctx.tower, stage: 'azimuth', azimuth: 0, beamwidth: 60 };
      setHint('Move the cursor to set sector direction, then click to lock azimuth. Esc cancels.');
    } else if (mode === 'add_site') {
      setHint('Click anywhere on the map to drop a new site.');
    } else if (mode === 'add_link') {
      setHint('Click the first site, then click the second to connect them.');
    } else {
      setHint('');
    }
  }

  document.querySelectorAll('[data-mode]').forEach((b) => {
    b.addEventListener('click', () => setMode(b.dataset.mode));
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && mode !== 'pan') setMode('pan');
  });

  function setHint(msg) {
    const el = document.getElementById('map-hint');
    if (!el) return;
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
  }

  /* ---------- layer toggles ---------- */
  document.getElementById('toggle-sites').addEventListener('change', (e) => {
    e.target.checked ? sitesLayer.addTo(map) : map.removeLayer(sitesLayer);
  });
  document.getElementById('toggle-links').addEventListener('change', (e) => {
    e.target.checked ? linksLayer.addTo(map) : map.removeLayer(linksLayer);
  });
  document.getElementById('toggle-clients').addEventListener('change', (e) => {
    e.target.checked ? clientsLayer.addTo(map) : map.removeLayer(clientsLayer);
  });
  document.getElementById('toggle-coverage').addEventListener('change', (e) => {
    e.target.checked ? coverageLayer.addTo(map) : map.removeLayer(coverageLayer);
  });
  document.getElementById('toggle-sectors').addEventListener('change', (e) => {
    e.target.checked ? sectorsLayer.addTo(map) : map.removeLayer(sectorsLayer);
  });

  // Persist + restore per-toggle state across page loads. Covers the
  // base layer toggles above and the overlay toggles defined in the
  // inline scripts at the bottom of map.php (which install their own
  // change handlers — our listener piggybacks rather than fighting
  // them, and the restore step dispatches a synthetic change so the
  // existing handlers wire/unwire their layers).
  const PERSISTED_TOGGLES = [
    'toggle-sites', 'toggle-links', 'toggle-clients',
    'toggle-coverage', 'toggle-sectors',
    'toggle-signal', 'toggle-rfdensity', 'toggle-throughput',
  ];
  PERSISTED_TOGGLES.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', () => {
      saveMapState({ toggles: { [id]: el.checked } });
    });
  });
  // Outage history is a <select>, not a checkbox.
  const outagePicker = document.getElementById('toggle-outage-history');
  if (outagePicker) {
    outagePicker.addEventListener('change', () => {
      saveMapState({ outage_history: outagePicker.value || '' });
    });
  }
  // Restore on load — set the checkbox to its saved state and
  // dispatch a synthetic change so the existing handler add/removes
  // the layer to match.  Two passes: an immediate one for the toggles
  // wired here in admin-map.js (sites/links/clients/coverage/sectors),
  // and a deferred one ~350ms later for the signal / RF / throughput
  // toggles whose handlers are bound by the inline scripts at the
  // bottom of map.php (those poll for window.WIFIBER_MAP every 250ms).
  function restoreToggles(ids) {
    if (!savedState || !savedState.toggles) return;
    ids.forEach((id) => {
      const want = savedState.toggles[id];
      if (want === undefined) return;
      const el = document.getElementById(id);
      if (!el) return;
      el.checked = !!want;
      el.dispatchEvent(new Event('change'));
    });
  }
  setTimeout(() => restoreToggles([
    'toggle-sites', 'toggle-links', 'toggle-clients',
    'toggle-coverage', 'toggle-sectors',
  ]), 0);
  setTimeout(() => restoreToggles([
    'toggle-signal', 'toggle-rfdensity', 'toggle-throughput',
  ]), 350);
  if (savedState && savedState.outage_history && outagePicker) {
    setTimeout(() => {
      if (outagePicker.value === savedState.outage_history) return;
      outagePicker.value = savedState.outage_history;
      outagePicker.dispatchEvent(new Event('change'));
    }, 350);
  }

  /* ---------- geometry: cone polygon from azimuth + beamwidth + range ---------- */
  // Walks an arc on the WGS84 sphere from (azimuth - beamwidth/2) to
  // (azimuth + beamwidth/2). Good enough for sectors a few km wide;
  // accuracy degrades past tens of km but that's not what sectors are.
  const EARTH_R = 6371000;

  function destination(lat, lng, bearingDeg, distanceM) {
    const br   = bearingDeg * Math.PI / 180;
    const lat1 = lat * Math.PI / 180;
    const lng1 = lng * Math.PI / 180;
    const dr   = distanceM / EARTH_R;
    const lat2 = Math.asin(Math.sin(lat1) * Math.cos(dr)
                         + Math.cos(lat1) * Math.sin(dr) * Math.cos(br));
    const lng2 = lng1 + Math.atan2(
      Math.sin(br) * Math.sin(dr) * Math.cos(lat1),
      Math.cos(dr) - Math.sin(lat1) * Math.sin(lat2)
    );
    return [lat2 * 180 / Math.PI, lng2 * 180 / Math.PI];
  }

  // Initial bearing from (lat1, lng1) to (lat2, lng2) in degrees clockwise from north.
  function bearing(lat1, lng1, lat2, lng2) {
    const φ1 = lat1 * Math.PI / 180, φ2 = lat2 * Math.PI / 180;
    const Δλ = (lng2 - lng1) * Math.PI / 180;
    const y = Math.sin(Δλ) * Math.cos(φ2);
    const x = Math.cos(φ1) * Math.sin(φ2) - Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ);
    const θ = Math.atan2(y, x) * 180 / Math.PI;
    return (θ + 360) % 360;
  }

  function sectorPolygon(towerLat, towerLng, azimuth, beamwidth, rangeM) {
    const half  = beamwidth / 2;
    const steps = Math.max(6, Math.ceil(beamwidth / 5));
    const pts = [[towerLat, towerLng]];
    for (let i = 0; i <= steps; i++) {
      const ang = azimuth - half + (beamwidth * i / steps);
      pts.push(destination(towerLat, towerLng, ang, rangeM));
    }
    pts.push([towerLat, towerLng]);
    return pts;
  }

  /* ---------- popup helpers (devices + sectors at a site) ---------- */
  function deviceListHTML(siteId) {
    const list = devicesBySite.get(siteId) || [];
    const rows = list.map((d) => {
      const c = DEVICE_COLOR[d.status] || DEVICE_COLOR.unknown;
      const pill = '<span class="pp-pill" style="background:' + c + ';">' + escapeHtml(d.status) + '</span>';
      const meta = [d.role, d.vendor + (d.model ? ' ' + d.model : '')].filter(Boolean).join(' · ');
      return '<li>'
           +   '<div class="pp-row">'
           +     '<span class="pp-name">' + escapeHtml(d.name) + '</span>'
           +     pill
           +     '<button type="button" class="pp-act" data-edit-device="' + d.id + '">Edit</button>'
           +     '<button type="button" class="pp-act pp-act-danger" data-delete-device="' + d.id + '" aria-label="Delete">×</button>'
           +   '</div>'
           +   (meta ? '<div class="pp-meta">' + escapeHtml(meta) + '</div>' : '')
           + '</li>';
    }).join('');
    return '<div class="pp-section">'
         +   '<div class="pp-section-head">'
         +     '<strong>Devices (' + list.length + ')</strong>'
         +     '<button type="button" class="pp-act pp-act-primary" data-add-device="' + siteId + '">+ Add</button>'
         +   '</div>'
         +   (list.length ? '<ul class="pp-list">' + rows + '</ul>' : '')
         + '</div>';
  }

  function sectorListHTML(towerId) {
    const ids = sectorsByTower.get(towerId) || new Set();
    const list = [...ids].map((id) => sectorIndex.get(id)).filter(Boolean).map((e) => e.data);
    const rows = list.map((s) => {
      const c = BAND_COLOR[s.band] || BAND_COLOR.other;
      const dot = '<span class="pp-dot" style="background:' + c + ';"></span>';
      const az  = s.azimuth_deg   != null ? s.azimuth_deg   + '°' : '—';
      const bw  = s.beamwidth_deg != null ? s.beamwidth_deg + '°' : '—';
      const fq  = s.frequency_mhz != null
        ? s.frequency_mhz + ' MHz' + (s.channel_width_mhz ? ' @ ' + s.channel_width_mhz : '')
        : '';
      const meta = [escapeHtml(s.band), 'az ' + az + ' · bw ' + bw, fq && escapeHtml(fq)].filter(Boolean).join(' · ');
      return '<li>'
           +   '<div class="pp-row">'
           +     dot
           +     '<a href="#" class="pp-name" data-focus-sector="' + s.id + '">' + escapeHtml(s.name) + '</a>'
           +     '<button type="button" class="pp-act" data-edit-sector="' + s.id + '">Edit</button>'
           +     '<button type="button" class="pp-act pp-act-danger" data-delete-sector="' + s.id + '" aria-label="Delete">×</button>'
           +   '</div>'
           +   '<div class="pp-meta">' + meta + '</div>'
           + '</li>';
    }).join('');
    return '<div class="pp-section">'
         +   '<div class="pp-section-head">'
         +     '<strong>Sectors (' + list.length + ')</strong>'
         +     '<button type="button" class="pp-act pp-act-primary" data-add-sector="' + towerId + '">+ Add</button>'
         +   '</div>'
         +   (list.length ? '<ul class="pp-list">' + rows + '</ul>' : '')
         + '</div>';
  }

  /* ---------- render sites ---------- */
  function siteTypeLabel(t) {
    return ({ tower: 'Tower', ap: 'AP / Sector', ptp_endpoint: 'PTP endpoint', pop: 'PoP / NOC', other: 'Other' }[t] || t);
  }

  function renderSite(s) {
    const inOutage = outageTowerIds.has(s.id);
    const badge    = inOutage ? '#d44' : null;
    const marker = L.marker([s.lat, s.lng], {
      draggable: true,
      icon: dotIcon(SITE_COLOR[s.type] || '#888', 16, badge),
      zIndexOffset: inOutage ? 1000 : 0,
    });
    if (inOutage) {
      const halo = L.circleMarker([s.lat, s.lng], {
        radius: 18, color: '#d44', weight: 2, fillColor: '#d44',
        fillOpacity: 0.15, opacity: 0.85, interactive: false,
      });
      halo.addTo(sitesLayer);
    }
    // Pass a function so the popup re-renders its sector/device lists
    // each time it opens — counts and rows stay fresh after AJAX adds.
    marker.bindPopup(() => sitePopupHTML(s));
    // Stash the site id on the marker so the popupopen dispatcher (used
    // by the bottom detail panel) can resolve it without a Map scan.
    marker.wfSiteId = s.id;
    marker.on('dragend', async (e) => {
      const ll = e.target.getLatLng();
      const r = await postAction('move_site', { id: s.id, lat: ll.lat, lng: ll.lng });
      if (!r.ok) {
        alert(r.error || 'Move failed');
        marker.setLatLng([s.lat, s.lng]);
      } else {
        s.lat = ll.lat; s.lng = ll.lng;
        redrawLinksFor(s.id);
        redrawSectorsFor(s.id);
      }
    });
    marker.on('click', (e) => {
      if (mode === 'add_link') {
        e.originalEvent && e.originalEvent.stopPropagation();
        handleAddLinkClick(s);
      }
    });
    marker.addTo(sitesLayer);
    siteIndex.set(s.id, { data: s, marker });

    if (s.coverage_radius_m) {
      const c = L.circle([s.lat, s.lng], {
        radius: s.coverage_radius_m,
        color: SITE_COLOR[s.type] || '#888',
        weight: 1, fillOpacity: 0.05,
      });
      c.addTo(coverageLayer);
    }
  }

  function sitePopupHTML(s) {
    // Slim popup — just the title and admin actions. The bottom detail
    // panel surfaces the full overview, and the right sidebar lists
    // every device / sector / link connected to this site, so there's
    // no need to repeat any of that here.
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(s.name) + '</strong><br>'
      +   '<small>' + escapeHtml(siteTypeLabel(s.type)) + '</small>'
      +   (s.notes ? '<p>' + escapeHtml(s.notes) + '</p>' : '')
      +   '<div class="pp-actions">'
      +     '<button type="button" class="btn btn-ghost btn-sm" data-edit-site="' + s.id + '">Edit</button>'
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-site="' + s.id + '">Delete</button>'
      +     (s.type === 'tower'
            ? '<button type="button" class="btn btn-primary btn-sm" data-add-sector="' + s.id + '">+ Sector</button>'
            : '')
      +     (s.type === 'tower'
            ? '<button type="button" class="btn btn-ghost btn-sm" data-add-device="' + s.id + '">+ Device</button>'
            : '')
      +   '</div>'
      + '</div>';
  }

  function siteEditFormHTML(s) {
    const types = ['tower', 'ap', 'ptp_endpoint', 'pop', 'other'];
    return ''
      + '<form data-map-form="update_site" data-reload="1" class="map-popup">'
      +   '<input type="hidden" name="id" value="' + s.id + '">'
      +   '<label>Name<input name="name" required value="' + escapeHtml(s.name) + '"></label>'
      +   '<label>Type<select name="type">'
      +     types.map((t) => '<option value="' + t + '"' + (t === s.type ? ' selected' : '') + '>' + siteTypeLabel(t) + '</option>').join('')
      +   '</select></label>'
      +   '<label>Coverage radius (m)<input name="coverage_radius_m" type="number" min="0" value="' + (s.coverage_radius_m || '') + '"></label>'
      +   '<label>Notes<input name="notes" value="' + escapeHtml(s.notes || '') + '"></label>'
      +   '<input type="hidden" name="lat" value="' + s.lat + '">'
      +   '<input type="hidden" name="lng" value="' + s.lng + '">'
      +   '<input type="hidden" name="is_active" value="1">'
      +   '<button type="submit" class="btn btn-primary btn-sm">Save</button>'
      + '</form>';
  }

  /* ---------- render links ---------- */
  function renderLink(l) {
    const a = siteIndex.get(l.from_site_id);
    const b = siteIndex.get(l.to_site_id);
    if (!a || !b) return;
    const line = L.polyline([[a.data.lat, a.data.lng], [b.data.lat, b.data.lng]], {
      color: LINK_COLOR[l.type] || '#888',
      weight: l.type === 'fiber' ? 4 : 3,
      dashArray: l.type === 'ptmp' ? '6 4' : null,
      opacity: 0.85,
      className: 'wf-link',
    });
    line.bindPopup(linkPopupHTML(l, a.data, b.data));
    // UISP-style rich tooltip on hover (distance + capacity + endpoints).
    line.bindTooltip(linkTooltipHTML(l, a.data, b.data), {
      sticky: true,
      direction: 'top',
      offset: [0, -8],
      className: 'leaflet-link-tip',
    });
    // Stash link metadata on the polyline so the detail panel module
    // can pull it out from a click event without crossing IIFE walls.
    line.wfLinkId = l.id;
    line.addTo(linksLayer);
    linkLines.set(l.id, { line, data: l });
  }

  // Great-circle distance in metres. Used by the hover tooltip + detail
  // panel; kept inline so we don't depend on the PHP-side haversine
  // which only runs server-side.
  function distanceMetres(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const φ1 = lat1 * Math.PI / 180, φ2 = lat2 * Math.PI / 180;
    const Δφ = (lat2 - lat1) * Math.PI / 180;
    const Δλ = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(Δφ/2) ** 2 + Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ/2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
  }
  function fmtDistance(m) {
    if (m >= 1000) return (m / 1000).toFixed(2) + ' km';
    return Math.round(m) + ' m';
  }

  function linkTooltipHTML(l, from, to) {
    const dist = distanceMetres(from.lat, from.lng, to.lat, to.lng);
    const cap  = l.capacity_mbps != null ? l.capacity_mbps + ' Mbps' : '—';
    const freq = l.frequency ? escapeHtml(l.frequency) : '—';
    return ''
      + '<div class="ltip-title">' + escapeHtml(l.label || (l.type.toUpperCase() + ' link')) + '</div>'
      + '<div class="ltip-row"><span>Distance</span><strong>' + fmtDistance(dist) + '</strong></div>'
      + '<div class="ltip-row"><span>Capacity</span><strong>' + cap + '</strong></div>'
      + '<div class="ltip-row"><span>Frequency</span><strong>' + freq + '</strong></div>'
      + '<div class="ltip-route">' + escapeHtml(from.name) + ' ↔ ' + escapeHtml(to.name) + '</div>';
  }

  function linkPopupHTML(l, from, to) {
    const meta = [escapeHtml(l.type)]
      .concat(l.capacity_mbps ? [l.capacity_mbps + ' Mbps'] : [])
      .concat(l.frequency     ? [escapeHtml(l.frequency)]   : [])
      .join(' · ');
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(l.label || (l.type.toUpperCase() + ' link')) + '</strong><br>'
      +   '<small>' + escapeHtml(from.name) + ' ↔ ' + escapeHtml(to.name) + '</small>'
      +   '<p>' + meta + '</p>'
      +   '<div class="pp-actions">'
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-link="' + l.id + '">Delete link</button>'
      +   '</div>'
      + '</div>';
  }

  function redrawLinksFor(siteId) {
    linkLines.forEach((entry) => {
      const l = entry.data;
      if (l.from_site_id !== siteId && l.to_site_id !== siteId) return;
      const a = siteIndex.get(l.from_site_id);
      const b = siteIndex.get(l.to_site_id);
      if (a && b) entry.line.setLatLngs([[a.data.lat, a.data.lng], [b.data.lat, b.data.lng]]);
    });
  }

  /* ---------- render clients ---------- */
  function renderClient(c) {
    if (c.lat == null || c.lng == null) return;
    const badge = CLIENT_BADGE_COLOR[c.network_status] || null;
    const marker = L.marker([c.lat, c.lng], {
      draggable: true,
      icon: dotIcon(STATUS_COLOR[c.status] || '#888', 11, badge),
    });
    marker.bindPopup(clientPopupHTML(c));
    marker.wfClientId = c.id;
    marker.on('dragend', async (e) => {
      const ll = e.target.getLatLng();
      const r = await postAction('move_client', { id: c.id, lat: ll.lat, lng: ll.lng });
      if (!r.ok) {
        alert(r.error || 'Move failed');
        marker.setLatLng([c.lat, c.lng]);
      } else {
        c.lat = ll.lat; c.lng = ll.lng;
      }
    });
    marker.addTo(clientsLayer);
  }

  function clientPopupHTML(c) {
    const statusColor = STATUS_COLOR[c.status] || '#888';
    const apColor     = DEVICE_COLOR[c.network_status] || '#888';
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(c.account_no || c.username) + '</strong><br>'
      +   '<small>' + escapeHtml(c.name || '') + '</small>'
      +   (c.address ? '<p>' + escapeHtml(c.address) + '</p>' : '')
      +   '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">'
      +     '<span class="pp-pill" style="background:' + statusColor + ';">' + escapeHtml(c.status) + '</span>'
      +     (c.network_status
              ? '<span class="pp-pill" style="background:' + apColor + ';">AP ' + escapeHtml(c.network_status) + '</span>'
              : '')
      +   '</div>'
      +   (c.sector_label
            ? '<dl class="pp-kv"><dt>Sector</dt><dd>'
              + '<a href="/admin/sectors.php?search=' + encodeURIComponent(c.sector_label.split(' · ')[0]) + '">'
              + escapeHtml(c.sector_label) + '</a></dd></dl>'
            : '')
      +   '<div class="pp-actions">'
      +     '<a class="btn btn-ghost btn-sm" href="/admin/client-edit.php?id=' + c.id + '">Open record</a>'
      +   '</div>'
      + '</div>';
  }

  /* ---------- add-site mode ---------- */
  function openAddSitePopup(latlng) {
    const types = ['tower', 'ap', 'ptp_endpoint', 'pop', 'other'];
    const html = ''
      + '<form data-map-form="add_site" data-reload="1" class="map-popup">'
      +   '<strong>Add site here</strong>'
      +   '<input type="hidden" name="lat" value="' + latlng.lat + '">'
      +   '<input type="hidden" name="lng" value="' + latlng.lng + '">'
      +   '<label>Name<input name="name" required></label>'
      +   '<label>Type<select name="type">'
      +     types.map((t) => '<option value="' + t + '">' + siteTypeLabel(t) + '</option>').join('')
      +   '</select></label>'
      +   '<label>Coverage radius (m)<input name="coverage_radius_m" type="number" min="0" placeholder="e.g. 1500"></label>'
      +   '<label>Notes<input name="notes"></label>'
      +   '<input type="hidden" name="is_active" value="1">'
      +   '<button type="submit" class="btn btn-primary btn-sm">Add site</button>'
      + '</form>';
    L.popup({ minWidth: 240 }).setLatLng(latlng).setContent(html).openOn(map);
  }

  /* ---------- add-link mode ---------- */
  function handleAddLinkClick(site) {
    if (!pendingLinkFrom) {
      pendingLinkFrom = site;
      setHint('From: ' + site.name + ' — now click the destination site.');
      return;
    }
    if (pendingLinkFrom.id === site.id) {
      setHint('Pick a different site for the destination.');
      return;
    }
    const html = ''
      + '<form data-map-form="add_link" data-reload="1" class="map-popup">'
      +   '<strong>New link</strong>'
      +   '<small>' + escapeHtml(pendingLinkFrom.name) + ' &rarr; ' + escapeHtml(site.name) + '</small>'
      +   '<input type="hidden" name="from_site_id" value="' + pendingLinkFrom.id + '">'
      +   '<input type="hidden" name="to_site_id"   value="' + site.id + '">'
      +   '<label>Type<select name="type">'
      +     '<option value="ptp">PTP</option>'
      +     '<option value="ptmp">PTMP</option>'
      +     '<option value="fiber">Fiber</option>'
      +     '<option value="backhaul">Backhaul</option>'
      +   '</select></label>'
      +   '<label>Label<input name="label" placeholder="e.g. VDB &harr; Sasol"></label>'
      +   '<div class="row">'
      +     '<label>Capacity (Mbps)<input name="capacity_mbps" type="number" step="0.1"></label>'
      +     '<label>Frequency<input name="frequency" placeholder="5GHz"></label>'
      +   '</div>'
      +   '<button type="submit" class="btn btn-primary btn-sm">Add link</button>'
      + '</form>';
    L.popup({ minWidth: 260 })
      .setLatLng([(pendingLinkFrom.lat + site.lat) / 2, (pendingLinkFrom.lng + site.lng) / 2])
      .setContent(html)
      .openOn(map);
    pendingLinkFrom = null;
  }

  /* ---------- map clicks (mode dispatcher) ---------- */
  map.on('click', (e) => {
    if (mode === 'add_site') {
      openAddSitePopup(e.latlng);
      return;
    }
    if (mode === 'add_sector' && sectorDraft) {
      if (sectorDraft.stage === 'azimuth') {
        sectorDraft.stage = 'beamwidth';
        setHint('Move the cursor to set beamwidth, then click again to confirm. Esc cancels.');
        return;
      }
      if (sectorDraft.stage === 'beamwidth') {
        openAddSectorPopup(sectorDraft);
        previewLayer.clearLayers();
        // stay in add_sector mode? No — switch back to pan after one
        // sector. The popup handles the actual save.
        sectorDraft = null;
        setMode('pan');
        return;
      }
    }
  });

  map.on('mousemove', (e) => {
    if (mode !== 'add_sector' || !sectorDraft) return;
    const t = sectorDraft.tower;
    const az = bearing(t.lat, t.lng, e.latlng.lat, e.latlng.lng);
    if (sectorDraft.stage === 'azimuth') {
      sectorDraft.azimuth = Math.round(az);
    } else {
      // Beam width = twice the angular distance from the locked azimuth
      // to the cursor (clamped 5..359). The expression below gives the
      // shortest unsigned angle between the two bearings (0..180), which
      // is half the cone's opening — so the cone "opens" as the cursor
      // moves away from the centerline, UISP-style.
      const delta = Math.abs(((az - sectorDraft.azimuth + 540) % 360) - 180);
      sectorDraft.beamwidth = Math.max(5, Math.min(359, Math.round(delta * 2)));
    }
    drawSectorPreview();
  });

  function drawSectorPreview() {
    previewLayer.clearLayers();
    if (!sectorDraft) return;
    const t = sectorDraft.tower;
    const range = (t.coverage_radius_m && t.coverage_radius_m > 0) ? Number(t.coverage_radius_m) : 1500;
    const poly = L.polygon(
      sectorPolygon(t.lat, t.lng, sectorDraft.azimuth, sectorDraft.beamwidth, range),
      {
        color: '#05DAFD', weight: 2, dashArray: '4 4',
        fillColor: '#05DAFD', fillOpacity: 0.15, opacity: 0.95,
        interactive: false,
      }
    );
    poly.addTo(previewLayer);
    // Floating azimuth/beamwidth pill at the cone's centerline tip.
    const tip = destination(t.lat, t.lng, sectorDraft.azimuth, range);
    const label = L.marker(tip, {
      interactive: false,
      icon: L.divIcon({
        className: 'wf-sector-label',
        html: '<span class="wf-sector-label-pill">'
            + 'az ' + sectorDraft.azimuth + '° · bw ' + sectorDraft.beamwidth + '°</span>',
        iconSize: [1, 1], iconAnchor: [60, 12],
      }),
    });
    label.addTo(previewLayer);
  }

  function openAddSectorPopup(draft) {
    const t = draft.tower;
    const aps = (devicesBySite.get(t.id) || []).filter((d) => d.role === 'ap');
    const apOpts = '<option value="">— none —</option>'
      + aps.map((d) => '<option value="' + d.id + '">' + escapeHtml(d.name) + '</option>').join('');
    const bands = ['2.4GHz', '5GHz', '6GHz', '60GHz', 'other'];
    const html = ''
      + '<form data-map-form="add_sector" class="map-popup" style="min-width:260px;">'
      +   '<strong>New sector on ' + escapeHtml(t.name) + '</strong>'
      +   '<input type="hidden" name="tower_id" value="' + t.id + '">'
      +   '<label>Name<input name="name" required placeholder="e.g. North 5GHz"></label>'
      +   '<div class="row">'
      +     '<label>Azimuth°<input name="azimuth_deg" type="number" min="0" max="359" required value="' + draft.azimuth + '"></label>'
      +     '<label>Beam°<input name="beamwidth_deg" type="number" min="1" max="360" required value="' + draft.beamwidth + '"></label>'
      +   '</div>'
      +   '<label>Band<select name="band">'
      +     bands.map((b) => '<option value="' + b + '"' + (b === '5GHz' ? ' selected' : '') + '>' + b + '</option>').join('')
      +   '</select></label>'
      +   '<div class="row">'
      +     '<label>Freq (MHz)<input name="frequency_mhz" type="number" min="0" placeholder="5180"></label>'
      +     '<label>Width (MHz)<input name="channel_width_mhz" type="number" min="0" placeholder="20"></label>'
      +   '</div>'
      +   '<div class="row">'
      +     '<label>TX (dBm)<input name="tx_power_dbm" type="number" min="-20" max="40" placeholder="20"></label>'
      +     '<label>Max clients<input name="max_clients" type="number" min="0" placeholder="64"></label>'
      +   '</div>'
      +   '<label>AP device<select name="ap_device_id">' + apOpts + '</select></label>'
      +   '<label>Notes<input name="notes"></label>'
      +   '<button type="submit" class="btn btn-primary btn-sm">Add sector</button>'
      + '</form>';
    const tip = destination(t.lat, t.lng, draft.azimuth,
      (t.coverage_radius_m && t.coverage_radius_m > 0) ? Number(t.coverage_radius_m) : 1500);
    L.popup({ minWidth: 260 }).setLatLng(tip).setContent(html).openOn(map);
  }

  /* ---------- render sectors ---------- */
  // A sector cone is anchored on its tower's lat/lng. Range falls back
  // to the tower's coverage_radius_m, then a 1500 m default — sectors
  // don't carry their own range yet (Phase 4 visualisation, not config).
  const SECTOR_DEFAULT_RANGE_M = 1500;

  function renderSector(sector) {
    const tower = siteIndex.get(sector.tower_id);
    if (!tower) return;
    const az = sector.azimuth_deg;
    const bw = sector.beamwidth_deg;
    if (az == null || bw == null) {
      // Track it in the index even without a cone so it shows in the
      // tower popup list and can be edited to add a direction.
      sectorIndex.set(sector.id, { data: sector, layer: null });
      addSectorToTowerIndex(sector);
      return;
    }

    const range = (tower.data.coverage_radius_m && tower.data.coverage_radius_m > 0)
                ? Number(tower.data.coverage_radius_m)
                : SECTOR_DEFAULT_RANGE_M;
    const bandColor = BAND_COLOR[sector.band] || BAND_COLOR.other;
    const inOutage  = outageSectorIds.has(sector.id);

    // Capacity tint — outage > over-capacity > near-capacity > band colour.
    // Reads customer_count / max_clients off the bootstrap payload so the
    // map flags an AP that's running out of headroom without the operator
    // having to open the sector popup.
    const cap = (sector.max_clients && sector.max_clients > 0 && sector.customer_count != null)
              ? sector.customer_count / sector.max_clients
              : null;
    const overCap = cap !== null && cap >= 1.0;
    const nearCap = cap !== null && cap >= 0.85 && cap < 1.0;

    let stroke, fill, weight, fillOpacity, opacity;
    if (inOutage) {
      stroke = fill = '#d44';
      weight = 3; fillOpacity = 0.25; opacity = 0.95;
    } else if (overCap) {
      stroke = fill = '#d44';
      weight = 2.5; fillOpacity = 0.22; opacity = 0.9;
    } else if (nearCap) {
      stroke = fill = '#f97316';
      weight = 2; fillOpacity = 0.20; opacity = 0.85;
    } else {
      stroke = fill = bandColor;
      weight = 1.5; fillOpacity = 0.15; opacity = 0.7;
    }

    const poly = L.polygon(
      sectorPolygon(tower.data.lat, tower.data.lng, Number(az), Number(bw), range),
      {
        color: stroke,
        weight: weight,
        fillColor: fill,
        fillOpacity: fillOpacity,
        opacity: opacity,
      }
    );
    poly.bindPopup(sectorPopupHTML(sector, tower.data.name));
    poly.wfSectorId = sector.id;
    poly.addTo(sectorsLayer);
    sectorIndex.set(sector.id, { data: sector, layer: poly });
    addSectorToTowerIndex(sector);
  }

  function addSectorToTowerIndex(sector) {
    const tid = parseInt(sector.tower_id, 10);
    if (!sectorsByTower.has(tid)) sectorsByTower.set(tid, new Set());
    sectorsByTower.get(tid).add(sector.id);
  }

  function removeSectorFromIndex(sectorId) {
    const entry = sectorIndex.get(sectorId);
    if (!entry) return;
    if (entry.layer) sectorsLayer.removeLayer(entry.layer);
    const tid = parseInt(entry.data.tower_id, 10);
    if (sectorsByTower.has(tid)) sectorsByTower.get(tid).delete(sectorId);
    sectorIndex.delete(sectorId);
  }

  function redrawSectorsFor(towerId) {
    const ids = sectorsByTower.get(towerId);
    if (!ids) return;
    [...ids].forEach((sid) => {
      const e = sectorIndex.get(sid);
      if (!e) return;
      removeSectorFromIndex(sid);
      renderSector(e.data);
    });
  }

  function sectorPopupHTML(s, towerName) {
    // Slim popup — title + outage badge + admin actions only. The
    // bottom detail panel already shows azimuth / beam / frequency /
    // TX / AP / capacity / link health, and the right sidebar lists
    // every customer assigned to this sector, so we don't repeat it.
    const outage = outageBySectorId[s.id];
    const outageBlock = outage
      ? '<div class="pp-outage">'
        + '<strong>Active outage</strong><br>'
        + 'Started: ' + escapeHtml(outage.started_at) + '<br>'
        + (outage.cause ? 'Cause: ' + escapeHtml(outage.cause) + '<br>' : '')
        + outage.affected_count + ' customer' + (outage.affected_count === 1 ? '' : 's') + ' affected'
        + '</div>'
      : '';
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(s.name) + '</strong><br>'
      +   '<small>' + escapeHtml(towerName) + ' · ' + escapeHtml(s.band) + '</small>'
      +   outageBlock
      +   '<div class="pp-actions">'
      +     '<button type="button" class="btn btn-ghost btn-sm" data-edit-sector="' + s.id + '">Edit</button>'
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-sector="' + s.id + '">Delete</button>'
      +   '</div>'
      + '</div>';
  }

  function sectorEditFormHTML(s) {
    const tower = siteIndex.get(s.tower_id);
    const aps = tower ? (devicesBySite.get(tower.data.id) || []).filter((d) => d.role === 'ap') : [];
    const apOpts = '<option value="">— none —</option>'
      + aps.map((d) => '<option value="' + d.id + '"' + (s.ap_device_id == d.id ? ' selected' : '') + '>' + escapeHtml(d.name) + '</option>').join('');
    const bands = ['2.4GHz', '5GHz', '6GHz', '60GHz', 'other'];
    return ''
      + '<form data-map-form="update_sector" class="map-popup" style="min-width:260px;">'
      +   '<input type="hidden" name="id" value="' + s.id + '">'
      +   '<input type="hidden" name="tower_id" value="' + s.tower_id + '">'
      +   '<label>Name<input name="name" required value="' + escapeHtml(s.name) + '"></label>'
      +   '<div class="row">'
      +     '<label>Azimuth°<input name="azimuth_deg" type="number" min="0" max="359" value="' + (s.azimuth_deg ?? '') + '"></label>'
      +     '<label>Beam°<input name="beamwidth_deg" type="number" min="1" max="360" value="' + (s.beamwidth_deg ?? '') + '"></label>'
      +   '</div>'
      +   '<label>Band<select name="band">'
      +     bands.map((b) => '<option value="' + b + '"' + (b === s.band ? ' selected' : '') + '>' + b + '</option>').join('')
      +   '</select></label>'
      +   '<div class="row">'
      +     '<label>Freq (MHz)<input name="frequency_mhz" type="number" min="0" value="' + (s.frequency_mhz ?? '') + '"></label>'
      +     '<label>Width (MHz)<input name="channel_width_mhz" type="number" min="0" value="' + (s.channel_width_mhz ?? '') + '"></label>'
      +   '</div>'
      +   '<div class="row">'
      +     '<label>TX (dBm)<input name="tx_power_dbm" type="number" min="-20" max="40" value="' + (s.tx_power_dbm ?? '') + '"></label>'
      +     '<label>Max clients<input name="max_clients" type="number" min="0" value="' + (s.max_clients ?? '') + '"></label>'
      +   '</div>'
      +   '<label>AP device<select name="ap_device_id">' + apOpts + '</select></label>'
      +   '<label>Notes<input name="notes" value="' + escapeHtml(s.notes || '') + '"></label>'
      +   '<button type="submit" class="btn btn-primary btn-sm">Save sector</button>'
      + '</form>';
  }

  /* ---------- device add/edit forms ---------- */
  function deviceFormHTML(siteId, d) {
    const vendors = ['mikrotik', 'ubiquiti', 'cambium', 'mimosa', 'other'];
    const roles   = ['ap', 'cpe', 'router', 'switch', 'backhaul', 'ups', 'other'];
    const statuses= ['online', 'offline', 'unknown', 'retired'];
    const isEdit = !!d;
    return ''
      + '<form data-map-form="' + (isEdit ? 'update_device' : 'add_device') + '" class="map-popup" style="min-width:240px;">'
      +   (isEdit ? '<input type="hidden" name="id" value="' + d.id + '">' : '')
      +   '<input type="hidden" name="site_id" value="' + siteId + '">'
      +   '<strong>' + (isEdit ? 'Edit device' : 'New device') + '</strong>'
      +   '<label>Name<input name="name" required value="' + escapeHtml(isEdit ? d.name : '') + '"></label>'
      +   '<div class="row">'
      +     '<label>Vendor<select name="vendor">'
      +       vendors.map((v) => '<option value="' + v + '"' + (isEdit && d.vendor === v ? ' selected' : '') + '>' + v + '</option>').join('')
      +     '</select></label>'
      +     '<label>Role<select name="role">'
      +       roles.map((r) => '<option value="' + r + '"' + (isEdit && d.role === r ? ' selected' : (!isEdit && r === 'ap' ? ' selected' : '')) + '>' + r + '</option>').join('')
      +     '</select></label>'
      +   '</div>'
      +   '<label>Model<input name="model" value="' + escapeHtml(isEdit ? (d.model || '') : '') + '"></label>'
      +   '<div class="row">'
      +     '<label>MAC<input name="mac" value="' + escapeHtml(isEdit ? (d.mac || '') : '') + '"></label>'
      +     '<label>Mgmt IP<input name="mgmt_ip" value="' + escapeHtml(isEdit ? (d.mgmt_ip || '') : '') + '"></label>'
      +   '</div>'
      +   '<label>Status<select name="status">'
      +     statuses.map((s) => '<option value="' + s + '"' + (isEdit && d.status === s ? ' selected' : (!isEdit && s === 'unknown' ? ' selected' : '')) + '>' + s + '</option>').join('')
      +   '</select></label>'
      +   '<label>Notes<input name="notes" value="' + escapeHtml(isEdit ? (d.notes || '') : '') + '"></label>'
      +   '<button type="submit" class="btn btn-primary btn-sm">Save</button>'
      + '</form>';
  }

  /* ---------- popup form / button delegation ---------- */
  function handleSaveResult(action, r, form) {
    map.closePopup();

    if (form && form.dataset.reload === '1') {
      location.reload();
      return;
    }

    if (action === 'add_sector' || action === 'update_sector') {
      if (r.sector) {
        if (action === 'update_sector') removeSectorFromIndex(r.sector.id);
        renderSector(r.sector);
        const cnt = document.getElementById('count-sectors');
        if (cnt && action === 'add_sector') cnt.textContent = String(parseInt(cnt.textContent || '0', 10) + 1);
      }
      return;
    }

    if (action === 'add_device' || action === 'update_device') {
      if (r.device) {
        const sid = parseInt(r.device.site_id, 10);
        if (!devicesBySite.has(sid)) devicesBySite.set(sid, []);
        const list = devicesBySite.get(sid);
        const i = list.findIndex((x) => x.id === r.device.id);
        if (i >= 0) list[i] = r.device; else list.push(r.device);
        const cnt = document.getElementById('count-devices');
        if (cnt && action === 'add_device') cnt.textContent = String(parseInt(cnt.textContent || '0', 10) + 1);
      }
      return;
    }
  }

  // Single, clean submit handler (replaces the messy one above).
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-map-form]');
    if (!form || form.dataset.handled === '1') return;
    form.dataset.handled = '1';
    e.preventDefault();
    e.stopImmediatePropagation();
    const action = form.dataset.mapForm;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    const fd = new FormData(form);
    fd.append('action', action);
    fd.append('_csrf', CSRF);
    let r;
    try {
      const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
      r = await res.json();
    } catch {
      r = { ok: false, error: 'Server error' };
    }
    if (submitBtn) submitBtn.disabled = false;
    delete form.dataset.handled;

    if (!r.ok) { alert(r.error || 'Save failed'); return; }
    handleSaveResult(action, r, form);
  }, true);

  document.addEventListener('click', async (e) => {
    if (e.target.matches('[data-delete-site]')) {
      const id = e.target.dataset.deleteSite;
      if (!confirm('Delete this site? Any links touching it will also be removed.')) return;
      const r = await postAction('delete_site', { id });
      if (!r.ok) { alert(r.error); return; }
      location.reload();
    }
    if (e.target.matches('[data-delete-link]')) {
      const id = e.target.dataset.deleteLink;
      if (!confirm('Delete this link?')) return;
      const r = await postAction('delete_link', { id });
      if (!r.ok) { alert(r.error); return; }
      location.reload();
    }
    if (e.target.matches('[data-edit-site]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.editSite, 10);
      const entry = siteIndex.get(id);
      if (entry) entry.marker.setPopupContent(siteEditFormHTML(entry.data));
    }

    /* ----- sector actions ----- */
    if (e.target.matches('[data-add-sector]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.addSector, 10);
      const entry = siteIndex.get(id);
      if (!entry) return;
      map.closePopup();
      setMode('add_sector', { tower: entry.data });
    }
    if (e.target.matches('[data-edit-sector]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.editSector, 10);
      const entry = sectorIndex.get(id);
      if (!entry) return;
      const tower = siteIndex.get(entry.data.tower_id);
      const html = sectorEditFormHTML(entry.data);
      map.closePopup();
      const tip = (tower && entry.data.azimuth_deg != null)
        ? destination(tower.data.lat, tower.data.lng, entry.data.azimuth_deg,
            (tower.data.coverage_radius_m && tower.data.coverage_radius_m > 0)
              ? Number(tower.data.coverage_radius_m) : SECTOR_DEFAULT_RANGE_M)
        : (tower ? [tower.data.lat, tower.data.lng] : map.getCenter());
      L.popup({ minWidth: 260 }).setLatLng(tip).setContent(html).openOn(map);
    }
    if (e.target.matches('[data-delete-sector]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.deleteSector, 10);
      if (!confirm('Delete this sector?')) return;
      const r = await postAction('delete_sector', { id });
      if (!r.ok) { alert(r.error); return; }
      removeSectorFromIndex(id);
      map.closePopup();
      const cnt = document.getElementById('count-sectors');
      if (cnt) cnt.textContent = String(Math.max(0, parseInt(cnt.textContent || '0', 10) - 1));
    }
    if (e.target.matches('[data-focus-sector]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.focusSector, 10);
      const entry = sectorIndex.get(id);
      if (!entry || !entry.layer) return;
      map.closePopup();
      entry.layer.openPopup();
      map.fitBounds(entry.layer.getBounds(), { maxZoom: 16, padding: [40, 40] });
    }

    /* ----- device actions ----- */
    if (e.target.matches('[data-add-device]')) {
      e.preventDefault();
      const sid = parseInt(e.target.dataset.addDevice, 10);
      const entry = siteIndex.get(sid);
      if (!entry) return;
      map.closePopup();
      L.popup({ minWidth: 260 })
        .setLatLng([entry.data.lat, entry.data.lng])
        .setContent(deviceFormHTML(sid, null))
        .openOn(map);
    }
    if (e.target.matches('[data-edit-device]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.editDevice, 10);
      const dev = findDevice(id);
      if (!dev) return;
      const entry = siteIndex.get(parseInt(dev.site_id, 10));
      if (!entry) return;
      map.closePopup();
      L.popup({ minWidth: 260 })
        .setLatLng([entry.data.lat, entry.data.lng])
        .setContent(deviceFormHTML(dev.site_id, dev))
        .openOn(map);
    }
    if (e.target.matches('[data-delete-device]')) {
      e.preventDefault();
      const id = parseInt(e.target.dataset.deleteDevice, 10);
      if (!confirm('Delete this device?')) return;
      const r = await postAction('delete_device', { id });
      if (!r.ok) { alert(r.error); return; }
      // Drop it from the by-site index.
      devicesBySite.forEach((list, sid) => {
        const i = list.findIndex((x) => x.id === id);
        if (i >= 0) list.splice(i, 1);
      });
      const cnt = document.getElementById('count-devices');
      if (cnt) cnt.textContent = String(Math.max(0, parseInt(cnt.textContent || '0', 10) - 1));
      map.closePopup();
    }
  });

  function findDevice(id) {
    for (const list of devicesBySite.values()) {
      const d = list.find((x) => x.id === id);
      if (d) return d;
    }
    return null;
  }

  /* ---------- bulk geocode ---------- */
  const geocodeBtn = document.getElementById('geocode-all-btn');
  const geoStatus  = document.getElementById('geocode-status');
  geocodeBtn.addEventListener('click', async () => {
    const queue = boot.clients.filter((c) => (c.lat == null || c.lng == null) && c.address);
    if (!queue.length) { geoStatus.textContent = 'Nothing to geocode.'; return; }
    geocodeBtn.disabled = true;
    let done = 0, hit = 0;
    for (const c of queue) {
      geoStatus.textContent = 'Geocoding ' + (++done) + '/' + queue.length + ' — ' + c.username;
      const r = await postAction('geocode_client', { id: c.id });
      if (r.ok) hit++;
      // Honour Nominatim's 1 req/sec policy
      await new Promise((r) => setTimeout(r, 1100));
    }
    geoStatus.textContent = 'Done. ' + hit + ' located, ' + (done - hit) + ' missed. Reload to see them.';
    geocodeBtn.disabled = false;
  });

  /* ---------- bootstrap ---------- */
  boot.sites.forEach(renderSite);
  boot.site_links.forEach(renderLink);
  boot.clients.forEach(renderClient);
  (boot.sectors || []).forEach(renderSector);

  /* ==========================================================
     UISP-style enhancements
     ----------------------------------------------------------
     Adds: bottom detail panel for links/sites, hover tooltip
     on links (already wired in renderLink), live cursor coord
     readout, fit-all / locate / measure tools, search jump.
     Self-contained — only reads from already-built indices.
     ========================================================== */

  /* ---------- live coord readout ---------- */
  const coordLat  = document.getElementById('coord-lat');
  const coordLng  = document.getElementById('coord-lng');
  const coordZoom = document.getElementById('coord-zoom');
  function fmtCoord(v) { return (v >= 0 ? ' ' : '') + v.toFixed(5); }
  if (coordLat && coordLng) {
    map.on('mousemove', (e) => {
      coordLat.textContent = fmtCoord(e.latlng.lat);
      coordLng.textContent = fmtCoord(e.latlng.lng);
    });
  }
  if (coordZoom) {
    const updateZ = () => { coordZoom.textContent = String(map.getZoom()); };
    map.on('zoomend', updateZ);
    updateZ();
  }

  /* ---------- fit-all + locate + measure quick-tools ---------- */
  const fitBtn     = document.getElementById('qt-fit-all');
  const locateBtn  = document.getElementById('qt-locate');
  const measureBtn = document.getElementById('qt-measure');

  if (fitBtn) {
    fitBtn.addEventListener('click', () => {
      const pts = [];
      siteIndex.forEach((e) => pts.push([e.data.lat, e.data.lng]));
      // Include placed clients too so the bound is faithful to what's drawn.
      (boot.clients || []).forEach((c) => {
        if (c.lat != null && c.lng != null) pts.push([c.lat, c.lng]);
      });
      if (!pts.length) return;
      map.fitBounds(L.latLngBounds(pts), { padding: [50, 50], maxZoom: 16 });
    });
  }
  if (locateBtn) {
    locateBtn.addEventListener('click', () => {
      map.setView(boot.center, boot.zoom);
    });
  }

  // ----- distance measure tool -----
  // Click once to set point A, second click locks point B and shows
  // the live distance as a small chip on the dashed line. Esc clears.
  let measureActive = false;
  let measureA      = null;
  let measureLine   = null;
  let measureDot    = null;
  let measureTip    = null;
  function clearMeasure() {
    measureActive = false;
    measureA = null;
    if (measureLine)  { map.removeLayer(measureLine);  measureLine = null; }
    if (measureDot)   { map.removeLayer(measureDot);   measureDot = null; }
    if (measureTip)   { map.removeLayer(measureTip);   measureTip = null; }
    if (measureBtn) measureBtn.classList.remove('is-active');
    map.getContainer().classList.remove('mdp-measuring');
    map.getContainer().style.cursor = '';
    setHint('');
  }
  function startMeasure() {
    if (measureActive) { clearMeasure(); return; }
    // Don't fight the existing add-site / add-link / add-sector modes.
    if (mode !== 'pan') setMode('pan');
    measureActive = true;
    if (measureBtn) measureBtn.classList.add('is-active');
    map.getContainer().style.cursor = 'crosshair';
    setHint('Click two points on the map to measure distance. Esc cancels.');
  }
  if (measureBtn) measureBtn.addEventListener('click', startMeasure);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && measureActive) clearMeasure();
  });

  map.on('click', (e) => {
    if (!measureActive) return;
    L.DomEvent.stopPropagation(e);
    if (!measureA) {
      measureA = e.latlng;
      measureDot = L.circleMarker(measureA, {
        radius: 4, color: '#05DAFD', weight: 2,
        fillColor: '#001218', fillOpacity: 1,
      }).addTo(map);
      return;
    }
    // Second click — lock the line and leave the chip in place. Click
    // the measure button again (or Esc) to clear.
    const b = e.latlng;
    if (measureLine) { map.removeLayer(measureLine); measureLine = null; }
    if (measureTip)  { map.removeLayer(measureTip);  measureTip = null; }
    measureLine = L.polyline([measureA, b], {
      color: '#05DAFD', weight: 2, dashArray: '6 5', opacity: .9,
    }).addTo(map);
    const dist = distanceMetres(measureA.lat, measureA.lng, b.lat, b.lng);
    const mid = [(measureA.lat + b.lat) / 2, (measureA.lng + b.lng) / 2];
    measureTip = L.tooltip({
      permanent: true, direction: 'center',
      className: 'leaflet-measure-tip', offset: [0, 0],
    }).setLatLng(mid).setContent(fmtDistance(dist)).addTo(map);
    L.circleMarker(b, {
      radius: 4, color: '#05DAFD', weight: 2,
      fillColor: '#001218', fillOpacity: 1,
    }).addTo(map);
    measureA = null;  // ready for next pair, but keep tool active
    setHint('Click two more points, or press Esc to finish.');
  });

  // Live preview while drawing the second leg.
  map.on('mousemove', (e) => {
    if (!measureActive || !measureA) return;
    if (measureLine) map.removeLayer(measureLine);
    measureLine = L.polyline([measureA, e.latlng], {
      color: '#05DAFD', weight: 2, dashArray: '4 4', opacity: .55,
    }).addTo(map);
    if (measureTip) map.removeLayer(measureTip);
    const dist = distanceMetres(measureA.lat, measureA.lng, e.latlng.lat, e.latlng.lng);
    measureTip = L.tooltip({
      permanent: true, direction: 'top', offset: [0, -8],
      className: 'leaflet-measure-tip',
    }).setLatLng(e.latlng).setContent(fmtDistance(dist)).addTo(map);
  });

  /* ---------- search box ---------- */
  const searchInput   = document.getElementById('map-search-input');
  const searchResults = document.getElementById('map-search-results');
  let searchCursor    = -1;
  let searchHits      = [];

  function buildSearchHits() {
    const hits = [];
    siteIndex.forEach((e) => {
      hits.push({ kind: 'site', id: e.data.id, label: e.data.name,
                  meta: siteTypeLabel(e.data.type),
                  color: SITE_COLOR[e.data.type] || '#888',
                  lat: e.data.lat, lng: e.data.lng, ref: e });
    });
    linkLines.forEach((e) => {
      const a = siteIndex.get(e.data.from_site_id);
      const b = siteIndex.get(e.data.to_site_id);
      if (!a || !b) return;
      hits.push({ kind: 'link', id: e.data.id,
                  label: e.data.label || (a.data.name + ' ↔ ' + b.data.name),
                  meta: e.data.type,
                  color: LINK_COLOR[e.data.type] || '#888',
                  lat: (a.data.lat + b.data.lat) / 2,
                  lng: (a.data.lng + b.data.lng) / 2,
                  ref: e });
    });
    (boot.clients || []).forEach((c) => {
      if (c.lat == null || c.lng == null) return;
      hits.push({ kind: 'client', id: c.id,
                  label: c.account_no || c.username || c.name || ('client ' + c.id),
                  meta: c.name || '',
                  color: STATUS_COLOR[c.status] || '#888',
                  lat: c.lat, lng: c.lng });
    });
    return hits;
  }
  let allHits = buildSearchHits();

  function renderSearchResults(q) {
    if (!searchResults) return;
    if (!q) {
      searchResults.classList.remove('is-open');
      searchResults.innerHTML = '';
      searchHits = [];
      searchCursor = -1;
      return;
    }
    const ql = q.toLowerCase();
    searchHits = allHits.filter((h) => {
      return (h.label || '').toLowerCase().includes(ql)
          || (h.meta  || '').toLowerCase().includes(ql);
    }).slice(0, 12);
    searchCursor = searchHits.length ? 0 : -1;
    if (!searchHits.length) {
      searchResults.innerHTML = '<div class="msr-empty">No matches</div>';
    } else {
      searchResults.innerHTML = searchHits.map((h, i) => ''
        + '<div class="msr-row' + (i === searchCursor ? ' is-cursor' : '') + '" data-idx="' + i + '">'
        +   '<span class="msr-dot" style="background:' + h.color + ';"></span>'
        +   '<span>' + escapeHtml(h.label) + '</span>'
        +   '<span class="msr-meta">' + escapeHtml(h.kind === 'link' ? ('link · ' + h.meta) : h.meta) + '</span>'
        + '</div>').join('');
    }
    searchResults.classList.add('is-open');
  }
  function jumpToHit(h) {
    if (!h) return;
    map.setView([h.lat, h.lng], Math.max(map.getZoom(), 14), { animate: true });
    if (h.kind === 'site' && h.ref && h.ref.marker) {
      h.ref.marker.openPopup();
      openSiteDetail(h.ref.data);
    } else if (h.kind === 'link' && h.ref && h.ref.line) {
      h.ref.line.openPopup();
      openLinkDetail(h.ref.data);
    }
  }
  if (searchInput && searchResults) {
    searchInput.addEventListener('input', (e) => {
      // Refresh hits each keystroke so newly added sites are findable
      // without a full reload.
      allHits = buildSearchHits();
      renderSearchResults(e.target.value.trim());
    });
    searchInput.addEventListener('focus', () => {
      if (searchInput.value.trim()) renderSearchResults(searchInput.value.trim());
    });
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' && searchHits.length) {
        e.preventDefault();
        searchCursor = (searchCursor + 1) % searchHits.length;
        renderSearchResults(searchInput.value.trim());
      } else if (e.key === 'ArrowUp' && searchHits.length) {
        e.preventDefault();
        searchCursor = (searchCursor - 1 + searchHits.length) % searchHits.length;
        renderSearchResults(searchInput.value.trim());
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (searchCursor >= 0 && searchHits[searchCursor]) {
          jumpToHit(searchHits[searchCursor]);
          searchResults.classList.remove('is-open');
          searchInput.blur();
        }
      } else if (e.key === 'Escape') {
        searchResults.classList.remove('is-open');
        searchInput.blur();
      }
    });
    searchResults.addEventListener('mousedown', (e) => {
      const row = e.target.closest('.msr-row');
      if (!row) return;
      const i = parseInt(row.dataset.idx, 10);
      jumpToHit(searchHits[i]);
      searchResults.classList.remove('is-open');
      searchInput.blur();
    });
    document.addEventListener('click', (e) => {
      if (!searchResults.contains(e.target) && e.target !== searchInput) {
        searchResults.classList.remove('is-open');
      }
    });
  }

  /* ---------- detail panel ---------- */
  const panel       = document.getElementById('map-detail-panel');
  const panelGrid   = document.getElementById('mdp-grid');
  const panelClose  = document.getElementById('mdp-close');
  const shellEl     = document.querySelector('.map-shell');
  let selectedFeature = null;   // {kind, id, layer}
  let pulseRing       = null;

  // Mirror the bottom-strip's actual rendered height into a CSS
  // custom property so the right-side panel can sit exactly 12px
  // above it regardless of content.  Without this the sidebar
  // either overlaps the strip on tall content or leaves a gap on
  // short content.
  if (panel && shellEl && typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver((entries) => {
      for (const entry of entries) {
        const h = Math.round(entry.contentRect.height);
        if (h > 0) shellEl.style.setProperty('--mdp-h', h + 'px');
      }
    });
    ro.observe(panel);
  }

  /* ---------- right-side panel (related entities) ----------
     Opened in addition to the bottom panel — gives the operator the
     "list of things connected to what you clicked": sectors+links
     for a tower, clients for a sector, etc.  Each tab renders
     [{kind, id, name, meta, color, pill}] rows; clicking a row
     triggers the same flow as clicking the feature on the map. */
  const sidePanel    = document.getElementById('map-side-panel');
  const spTitleEl    = document.getElementById('msp-title');
  const spSubtitleEl = document.getElementById('msp-subtitle');
  const spTabsEl     = document.getElementById('msp-tabs');
  const spBodyEl     = document.getElementById('msp-body');
  const spCloseEl    = document.getElementById('msp-close');
  let sideContext = null;        // { tabs: [...], activeIdx }

  function closeSidePanel() {
    if (!sidePanel) return;
    sidePanel.classList.remove('is-open');
    sidePanel.setAttribute('aria-hidden', 'true');
    if (shellEl) shellEl.classList.remove('is-side-open');
    sideContext = null;
  }
  function openSidePanel(opts) {
    if (!sidePanel) return;
    spTitleEl.textContent    = opts.title    || '—';
    spSubtitleEl.textContent = opts.subtitle || '';
    sideContext = { tabs: opts.tabs || [], activeIdx: 0 };
    renderSideTabs();
    renderSideBody();
    sidePanel.classList.add('is-open');
    sidePanel.setAttribute('aria-hidden', 'false');
    if (shellEl) shellEl.classList.add('is-side-open');
  }
  function renderSideTabs() {
    if (!sideContext) return;
    spTabsEl.innerHTML = sideContext.tabs.map((t, i) => ''
      + '<button type="button" class="msp-tab' + (i === sideContext.activeIdx ? ' is-active' : '') + '" data-tab="' + i + '" role="tab">'
      +   escapeHtml(t.label)
      +   ' <span class="msp-tab-count">' + ((t.items && t.items.length) || 0) + '</span>'
      + '</button>'
    ).join('');
  }
  function renderSideBody() {
    if (!sideContext) return;
    const tab = sideContext.tabs[sideContext.activeIdx];
    if (!tab || !tab.items || !tab.items.length) {
      spBodyEl.innerHTML = '<div class="msp-empty">' + escapeHtml((tab && tab.empty) || 'Nothing connected.') + '</div>';
      return;
    }
    spBodyEl.innerHTML = '<ul class="msp-list">'
      + tab.items.map((it) => ''
        + '<li class="msp-item" data-msp-kind="' + escapeHtml(it.kind || '') + '" data-msp-id="' + escapeHtml(String(it.id ?? '')) + '">'
        +   '<span class="msp-item-dot" style="background:' + (it.color || '#888') + ';"></span>'
        +   '<div class="msp-item-body">'
        +     '<div class="msp-item-name">' + escapeHtml(it.name || '') + '</div>'
        +     (it.meta ? '<div class="msp-item-meta">' + escapeHtml(it.meta) + '</div>' : '')
        +   '</div>'
        +   (it.pill ? '<span class="msp-item-pill' + (it.pillClass ? ' ' + it.pillClass : '') + '">' + escapeHtml(it.pill) + '</span>' : '')
        + '</li>').join('')
      + '</ul>';
  }
  if (spCloseEl) spCloseEl.addEventListener('click', closeSidePanel);
  if (spTabsEl) {
    spTabsEl.addEventListener('click', (e) => {
      const t = e.target.closest('.msp-tab');
      if (!t || !sideContext) return;
      const i = parseInt(t.dataset.tab, 10);
      if (isNaN(i)) return;
      sideContext.activeIdx = i;
      renderSideTabs();
      renderSideBody();
    });
  }
  if (spBodyEl) {
    spBodyEl.addEventListener('click', (e) => {
      const li = e.target.closest('.msp-item');
      if (!li) return;
      const kind = li.dataset.mspKind;
      const id   = parseInt(li.dataset.mspId, 10);
      if (!kind || isNaN(id)) return;
      // Reuse the same dispatch flow as a real click on the map: open
      // the popup which then triggers popupopen → openXDetail.
      if (kind === 'site' || kind === 'tower') {
        const entry = siteIndex.get(id);
        if (entry) {
          map.setView([entry.data.lat, entry.data.lng], Math.max(map.getZoom(), 15), { animate: true });
          entry.marker.openPopup();
        }
      } else if (kind === 'sector') {
        const entry = sectorIndex.get(id);
        if (entry && entry.layer) {
          map.fitBounds(entry.layer.getBounds(), { maxZoom: 16, padding: [40, 40] });
          entry.layer.openPopup();
        }
      } else if (kind === 'link') {
        const entry = linkLines.get(id);
        if (entry) {
          map.fitBounds(entry.line.getBounds(), { padding: [60, 60], maxZoom: 16 });
          entry.line.openPopup();
        }
      } else if (kind === 'client') {
        const c = clientById.get(id);
        if (c && c.lat != null && c.lng != null) {
          map.setView([c.lat, c.lng], Math.max(map.getZoom(), 17), { animate: true });
          openClientDetail(c);
        }
      }
    });
  }

  /* ---------- builders for sidebar contents per feature ---------- */
  function buildTowerSidebarTabs(site) {
    // Sectors at this tower
    const sectorIds = sectorsByTower.get(site.id) || new Set();
    const sectorItems = [...sectorIds].map((sid) => sectorIndex.get(sid)).filter(Boolean).map((e) => {
      const s = e.data;
      const az = s.azimuth_deg != null ? 'az ' + s.azimuth_deg + '°' : '';
      const bw = s.beamwidth_deg != null ? 'bw ' + s.beamwidth_deg + '°' : '';
      const fq = s.frequency_mhz != null ? s.frequency_mhz + ' MHz' : '';
      const meta = [s.band, az, bw, fq].filter(Boolean).join(' · ');
      const cap = (s.max_clients && s.customer_count != null)
                ? s.customer_count + ' / ' + s.max_clients : null;
      const apOff = outageSectorIds && outageSectorIds.has(s.id);
      return {
        kind: 'sector', id: s.id, name: s.name, meta: meta,
        color: BAND_COLOR[s.band] || '#888',
        pill: apOff ? 'outage' : (cap || s.band),
        pillClass: apOff ? 'is-danger' : (s.max_clients && s.customer_count >= s.max_clients ? 'is-warn' : ''),
      };
    });

    // Backbone links touching this tower
    const linkItems = [];
    linkLines.forEach((e) => {
      const l = e.data;
      if (l.from_site_id !== site.id && l.to_site_id !== site.id) return;
      const otherId = l.from_site_id === site.id ? l.to_site_id : l.from_site_id;
      const other = siteIndex.get(otherId);
      if (!other) return;
      const dist = distanceMetres(site.lat, site.lng, other.data.lat, other.data.lng);
      const meta = [l.type ? l.type.toUpperCase() : null,
                    l.capacity_mbps ? l.capacity_mbps + ' Mbps' : null,
                    l.frequency || null,
                    fmtDistance(dist)].filter(Boolean).join(' · ');
      linkItems.push({
        kind: 'link', id: l.id,
        name: l.label || (other.data.name + ' link'),
        meta: '→ ' + other.data.name + ' · ' + meta,
        color: LINK_COLOR[l.type] || '#888',
        pill: l.type, pillClass: 'is-muted',
      });
    });

    // Devices at this tower
    const devs = devicesBySite.get(site.id) || [];
    const devItems = devs.map((d) => ({
      kind: 'device', id: d.id, name: d.name,
      meta: [d.role, d.vendor + (d.model ? ' ' + d.model : '')].filter(Boolean).join(' · '),
      color: DEVICE_COLOR[d.status] || '#888',
      pill: d.status,
      pillClass: d.status === 'offline' ? 'is-danger' : (d.status === 'online' ? '' : 'is-muted'),
    }));

    return [
      { label: 'Sectors', items: sectorItems, empty: 'No sectors on this tower.' },
      { label: 'Links',   items: linkItems,   empty: 'No backbone links from here.' },
      { label: 'Devices', items: devItems,    empty: 'No devices on this tower.' },
    ];
  }

  function buildSectorSidebarTabs(sector) {
    // Clients keyed to this sector via boot.clients[].sector_id
    const items = (boot.clients || [])
      .filter((c) => Number(c.sector_id) === Number(sector.id))
      .map((c) => {
        const apOff = c.network_status === 'offline';
        const placed = c.lat != null && c.lng != null;
        return {
          kind: 'client', id: c.id,
          name: c.account_no || c.username || c.name || ('client ' + c.id),
          meta: [c.name, placed ? null : 'unplaced', c.address].filter(Boolean).join(' · '),
          color: STATUS_COLOR[c.status] || '#888',
          pill: apOff ? 'AP down' : c.status,
          pillClass: apOff ? 'is-danger' : (c.status === 'suspended' ? 'is-warn' : ''),
        };
      });
    return [
      { label: 'Clients', items, empty: 'No customers assigned to this sector.' },
    ];
  }

  function buildLinkSidebarTabs(link) {
    const a = siteIndex.get(link.from_site_id);
    const b = siteIndex.get(link.to_site_id);
    function tabFor(siteEntry) {
      if (!siteEntry) return { label: '?', items: [] };
      const site = siteEntry.data;
      const sectorIds = sectorsByTower.get(site.id) || new Set();
      const sectorItems = [...sectorIds].map((sid) => sectorIndex.get(sid)).filter(Boolean).map((e) => {
        const s = e.data;
        const az = s.azimuth_deg != null ? 'az ' + s.azimuth_deg + '°' : '';
        const bw = s.beamwidth_deg != null ? 'bw ' + s.beamwidth_deg + '°' : '';
        const fq = s.frequency_mhz != null ? s.frequency_mhz + ' MHz' : '';
        const meta = [s.band, az, bw, fq].filter(Boolean).join(' · ');
        return {
          kind: 'sector', id: s.id, name: s.name, meta,
          color: BAND_COLOR[s.band] || '#888',
        };
      });
      return { label: site.name, items: sectorItems, empty: 'No sectors on this site.' };
    }
    return [tabFor(a), tabFor(b)];
  }
  function clearSelection() {
    if (selectedFeature && selectedFeature.layer && selectedFeature.layer._path) {
      selectedFeature.layer._path.classList.remove('is-mdp-selected');
    }
    if (pulseRing) { map.removeLayer(pulseRing); pulseRing = null; }
    selectedFeature = null;
  }
  function closePanel() {
    if (!panel) return;
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    if (shellEl) shellEl.classList.remove('is-bottom-open');
    clearSelection();
    closeSidePanel();
  }
  if (panelClose) panelClose.addEventListener('click', closePanel);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (sidePanel && sidePanel.classList.contains('is-open')) {
        closeSidePanel();
        return;
      }
      if (panel && panel.classList.contains('is-open')) closePanel();
    }
  });

  // Health-bar marker position from a 0..100 score, returns a percentage.
  function healthPct(score) {
    if (score == null || isNaN(score)) return 50;   // unknown → middle
    return Math.max(0, Math.min(100, score));
  }

  function siteCardHTML(site, role) {
    const devs    = (devicesBySite.get(site.id) || []);
    const onlineD = devs.filter((d) => d.status === 'online').length;
    const sectors = (sectorsByTower.get(site.id) || new Set()).size;
    const wl      = (boot.wireless_link_summary && boot.wireless_link_summary[site.id]) || null;
    const typeLbl = siteTypeLabel(site.type);
    const typeCol = SITE_COLOR[site.type] || '#888';
    const pillStyle = 'color:' + typeCol + ';background:' + typeCol + '20;';
    const cells = [];
    cells.push(['Location', site.lat.toFixed(5) + ', ' + site.lng.toFixed(5)]);
    if (site.coverage_radius_m) cells.push(['Coverage', site.coverage_radius_m + ' m']);
    cells.push(['Devices', devs.length + (devs.length ? ' · ' + onlineD + ' online' : '')]);
    if (site.type === 'tower') cells.push(['Sectors', String(sectors)]);
    if (wl) cells.push(['Wireless links', wl.count + (wl.degraded ? ' · ' + wl.degraded + ' degraded' : '')]);
    return ''
      + '<div class="mdp-card" data-role="' + role + '">'
      +   '<div class="mdp-name">'
      +     '<input type="text" value="' + escapeHtml(site.name) + '" readonly>'
      +     '<span class="mdp-type-pill" style="' + pillStyle + '">' + escapeHtml(typeLbl) + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv">'
      +     cells.map(([k, v]) => ''
      +         '<div class="mdp-cell' + (k === 'Location' ? ' mdp-cell-wide' : '') + '">'
      +         '<span class="mdp-label">' + escapeHtml(k) + '</span>'
      +         '<span class="mdp-val" title="' + escapeHtml(String(v)) + '">' + escapeHtml(String(v)) + '</span>'
      +       '</div>').join('')
      +   '</div>'
      + '</div>';
  }

  // Heuristic: with no measured signal on site_links, infer a notional
  // "expected" signal from distance + capacity. Lets the gradient bar
  // give the operator a visual cue rather than always sitting blank.
  function expectedSignalDbm(distM, capMbps, freq) {
    // Simplified free-space path loss approximation, anchored so
    // 1 km at 5 GHz lands around -55 dBm and 10 km lands around -75 dBm.
    // Capacity slightly biases — high-capacity links tend to be tighter LoS.
    if (!distM) return null;
    const km = distM / 1000;
    let dbm = -55 - 20 * Math.log10(Math.max(0.1, km));
    if (capMbps && capMbps > 500) dbm += 2;   // assume better link budget
    if (freq && /60\s*GHz/i.test(freq)) dbm -= 6;
    return Math.round(dbm);
  }
  function dbmToPct(dbm) {
    // Map -95..-45 dBm to 0..100 % for the gradient marker.
    if (dbm == null) return 50;
    const p = ((dbm + 95) / 50) * 100;
    return Math.max(0, Math.min(100, p));
  }

  async function openLinkDetail(link) {
    if (!panel || !panelGrid) return;
    const a = siteIndex.get(link.from_site_id);
    const b = siteIndex.get(link.to_site_id);
    if (!a || !b) return;
    const fromSite = a.data, toSite = b.data;

    // Render an immediate skeleton from in-memory data so the panel is
    // visible right away; the fetch fills in real signal/SNR/throughput
    // when it arrives.
    panel.classList.remove('is-site');
    renderLinkPanel({
      from: { id: fromSite.id, name: fromSite.name, type: fromSite.type, lat: fromSite.lat, lng: fromSite.lng },
      to:   { id: toSite.id,   name: toSite.name,   type: toSite.type,   lat: toSite.lat,   lng: toSite.lng },
      link: { id: link.id, type: link.type, label: link.label,
              capacity_mbps: link.capacity_mbps, frequency: link.frequency },
      distance_km: distanceMetres(fromSite.lat, fromSite.lng, toSite.lat, toSite.lng) / 1000,
      wireless_link: null,
    });
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    if (shellEl) shellEl.classList.add('is-bottom-open');

    clearSelection();
    const entry = linkLines.get(link.id);
    if (entry) {
      selectedFeature = { kind: 'link', id: link.id, layer: entry.line };
      if (entry.line._path) entry.line._path.classList.add('is-mdp-selected');
    }

    // Sidebar: sectors at each endpoint tower (so the operator can
    // see "what's behind this link" at a glance).
    openSidePanel({
      title: (fromSite.name + ' ↔ ' + toSite.name),
      subtitle: 'Link · sectors at each endpoint',
      tabs: buildLinkSidebarTabs(link),
    });

    const j = await fetchDetail('link', link.id);
    if (!j) return;
    if (!selectedFeature || selectedFeature.kind !== 'link' || selectedFeature.id !== link.id) return;
    renderLinkPanel(j);
  }

  // Renders the link panel from a {from, to, link, distance_km, wireless_link}
  // payload. Used both for the in-memory skeleton render and the API
  // response render — same template, just more data populated when the
  // API replies.
  function renderLinkPanel(j) {
    const link = j.link;
    const cap  = link.capacity_mbps;
    const distM = (j.distance_km || 0) * 1000;
    const wl   = j.wireless_link;

    // Prefer real measured signal from the matched wireless_link;
    // fall back to a free-space-loss heuristic so the bar isn't empty.
    let sig = null, snr = null, ccq = null, hp = null;
    let tputL = null, tputR = null, capL = null, capR = null;
    let modu = null, mode = null, lastAge = null;
    if (wl) {
      sig = wl.signal_dbm != null ? wl.signal_dbm : null;
      snr = wl.snr_db     != null ? wl.snr_db     : null;
      ccq = wl.ccq_pct    != null ? wl.ccq_pct    : null;
      hp  = wl.health_score != null ? wl.health_score : null;
      tputL = wl.throughput_local_mbps  != null ? wl.throughput_local_mbps  : null;
      tputR = wl.throughput_remote_mbps != null ? wl.throughput_remote_mbps : null;
      capL  = wl.capacity_local_mbps    != null ? wl.capacity_local_mbps    : null;
      capR  = wl.capacity_remote_mbps   != null ? wl.capacity_remote_mbps   : null;
      modu  = wl.modulation || null;
      mode  = wl.wireless_mode || null;
      lastAge = wl.last_evaluated_at ? fmtAge(wl.last_evaluated_at) : null;
    }
    const sigShown = sig != null ? sig : expectedSignalDbm(distM, cap, link.frequency);
    const sigPct   = dbmToPct(sigShown);
    const capPct   = cap ? Math.max(8, Math.min(100, (cap / 1000) * 100)) : 35;
    const linkTypeLabel = (link.type || '').toUpperCase();
    const linkLabel     = link.label || (linkTypeLabel + ' link');
    const totalT = (tputL != null || tputR != null) ? ((tputL || 0) + (tputR || 0)) : null;
    const health = healthBucket(hp);
    const signalLabel = wl ? 'Measured signal' + (lastAge ? ' · ' + lastAge : '') : 'Expected signal';

    const wlBlock = wl ? ''
      + '<div class="mdp-kv" style="grid-template-columns:repeat(4,1fr);margin-top:6px;">'
      +   kvCell('Signal',     fmtDbm(sig))
      +   kvCell('SNR',        snr != null ? snr + ' dB' : '—')
      +   kvCell('CCQ',        ccq != null ? Math.round(ccq) + '%' : '—')
      +   kvCell('Health',     hp != null ? hp + ' / 100' : '—', { color: health.c })
      +   kvCell('Throughput', totalT != null ? fmtMbps(totalT) : '—')
      +   kvCell('Capacity',   capL || capR ? fmtMbps((capL || 0) + (capR || 0)) : (cap != null ? cap + ' Mbps' : '—'))
      +   kvCell('Mode',       mode || '—')
      +   kvCell('Modulation', modu || '—')
      + '</div>'
      : '';

    const centerHTML = ''
      + '<div class="mdp-center">'
      +   '<div class="mdp-cap-row">'
      +     '<span>' + escapeHtml(linkLabel) + '</span>'
      +     '<span class="mdp-cap-val">' + (cap != null ? cap + ' Mbps' : 'Capacity —') + '</span>'
      +   '</div>'
      +   '<div class="mdp-cap-bar"><div class="mdp-cap-fill" style="width:' + capPct.toFixed(0) + '%;"></div></div>'
      +   '<div class="mdp-distance">'
      +     '<span>' + escapeHtml(j.from.name) + '</span>'
      +     '<span class="mdp-arrow"></span>'
      +     '<span class="mdp-dist-val">' + fmtDistance(distM) + '</span>'
      +     '<span class="mdp-arrow"></span>'
      +     '<span>' + escapeHtml(j.to.name) + '</span>'
      +   '</div>'
      +   '<div class="mdp-signal-bar">'
      +     '<div class="mdp-signal-marker" style="left:calc(' + sigPct.toFixed(0) + '% - 1.5px);" title="' + (sigShown != null ? sigShown + ' dBm' : 'unknown') + '"></div>'
      +   '</div>'
      +   '<div class="mdp-signal-meta">'
      +     '<span>' + signalLabel + '</span>'
      +     '<span>' + (sigShown != null ? sigShown + ' dBm' : 'no data') + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv" style="grid-template-columns:1fr 1fr 1fr;">'
      +     kvCell('Type',      linkTypeLabel)
      +     kvCell('Frequency', link.frequency || '—')
      +     kvCell('Capacity',  cap != null ? cap + ' Mbps' : '—')
      +   '</div>'
      +   wlBlock
      +   '<div class="mdp-actions">'
      +     '<button type="button" class="btn btn-ghost btn-sm" data-mdp-zoom-link="' + link.id + '">Zoom to</button>'
      +     (wl && wl.id ? '<a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=' + wl.id + '">Live link</a>' : '')
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-link="' + link.id + '">Delete</button>'
      +   '</div>'
      + '</div>';

    panelGrid.innerHTML = siteCardFromDetail(j.from, 'a') + centerHTML + siteCardFromDetail(j.to, 'b');
  }

  function openSiteDetail(site) {
    if (!panel || !panelGrid) return;
    const devs    = (devicesBySite.get(site.id) || []);
    const onlineD = devs.filter((d) => d.status === 'online').length;
    const sectors = (sectorsByTower.get(site.id) || new Set()).size;
    const wl      = (boot.wireless_link_summary && boot.wireless_link_summary[site.id]) || null;

    // Connected backbone links from this site (PTP / fibre / backhaul rows).
    const connections = [];
    linkLines.forEach((e) => {
      if (e.data.from_site_id === site.id || e.data.to_site_id === site.id) {
        const otherId = e.data.from_site_id === site.id ? e.data.to_site_id : e.data.from_site_id;
        const other = siteIndex.get(otherId);
        if (other) {
          const d = distanceMetres(site.lat, site.lng, other.data.lat, other.data.lng);
          connections.push({ name: other.data.name, type: e.data.type, dist: d, cap: e.data.capacity_mbps });
        }
      }
    });

    const wlScore = wl && wl.worst != null ? wl.worst : null;
    const sigPct  = healthPct(wlScore);
    const cap     = connections.reduce((sum, c) => sum + (c.cap || 0), 0);
    const capPct  = cap ? Math.max(8, Math.min(100, (cap / 1000) * 100)) : 35;

    const centerHTML = ''
      + '<div class="mdp-center">'
      +   '<div class="mdp-cap-row">'
      +     '<span>Site overview</span>'
      +     '<span class="mdp-cap-val">' + (cap ? cap + ' Mbps backbone' : (devs.length + ' device' + (devs.length === 1 ? '' : 's'))) + '</span>'
      +   '</div>'
      +   '<div class="mdp-cap-bar"><div class="mdp-cap-fill" style="width:' + capPct.toFixed(0) + '%;"></div></div>'
      + (connections.length
          ? '<div class="mdp-distance" style="border:none;padding:2px 0;font-size:11px;">'
            + '<span style="color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:600;font-size:10px;">Connections</span>'
            + '<span style="margin-left:auto;color:var(--text-dim);">'
            + connections.map((c) => escapeHtml(c.name) + ' (' + fmtDistance(c.dist) + ')').join(' · ')
            + '</span>'
            + '</div>'
          : ''
        )
      +   '<div class="mdp-signal-bar">'
      +     '<div class="mdp-signal-marker" style="left:calc(' + sigPct.toFixed(0) + '% - 1.5px);"></div>'
      +   '</div>'
      +   '<div class="mdp-signal-meta">'
      +     '<span>Wireless link health</span>'
      +     '<span>' + (wlScore != null ? wlScore + ' / 100' : 'no data') + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv" style="grid-template-columns:1fr 1fr 1fr;">'
      +     '<div class="mdp-cell"><span class="mdp-label">Devices</span><span class="mdp-val">' + devs.length + ' · ' + onlineD + ' online</span></div>'
      +     '<div class="mdp-cell"><span class="mdp-label">Sectors</span><span class="mdp-val">' + sectors + '</span></div>'
      +     '<div class="mdp-cell"><span class="mdp-label">Backbone</span><span class="mdp-val">' + connections.length + ' link' + (connections.length === 1 ? '' : 's') + '</span></div>'
      +   '</div>'
      +   '<div class="mdp-actions">'
      +     '<button type="button" class="btn btn-ghost btn-sm" data-mdp-zoom-site="' + site.id + '">Zoom to</button>'
      +     (site.type === 'tower'
            ? '<button type="button" class="btn btn-primary btn-sm" data-add-sector="' + site.id + '">+ Sector</button>'
            : '')
      +     '<button type="button" class="btn btn-ghost btn-sm" data-edit-site="' + site.id + '">Edit</button>'
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-site="' + site.id + '">Delete</button>'
      +   '</div>'
      + '</div>';

    panel.classList.remove('is-site');
    panelGrid.innerHTML = siteCardHTML(site, 'a') + centerHTML;
    panel.classList.add('is-open', 'is-site');
    panel.setAttribute('aria-hidden', 'false');
    if (shellEl) shellEl.classList.add('is-bottom-open');

    // Pulse ring under the selected site marker.
    clearSelection();
    pulseRing = L.marker([site.lat, site.lng], {
      interactive: false,
      icon: L.divIcon({
        className: '',
        html: '<div class="mdp-pulse-marker"></div>',
        iconSize: [22, 22], iconAnchor: [11, 11],
      }),
    }).addTo(map);
    selectedFeature = { kind: 'site', id: site.id };

    // Sidebar: sectors + backbone links + devices for this site
    // (the operator-asked "everything connected to this tower").
    openSidePanel({
      title: site.name,
      subtitle: siteTypeLabel(site.type) + ' · everything connected',
      tabs: buildTowerSidebarTabs(site),
    });
  }

  // Zoom-to handlers in the panel
  document.addEventListener('click', (e) => {
    const zl = e.target.closest('[data-mdp-zoom-link]');
    if (zl) {
      const id = parseInt(zl.dataset.mdpZoomLink, 10);
      const entry = linkLines.get(id);
      if (entry) map.fitBounds(entry.line.getBounds(), { padding: [60, 60], maxZoom: 16 });
      return;
    }
    const zs = e.target.closest('[data-mdp-zoom-site]');
    if (zs) {
      const id = parseInt(zs.dataset.mdpZoomSite, 10);
      const entry = siteIndex.get(id);
      if (entry) map.setView([entry.data.lat, entry.data.lng], Math.max(map.getZoom(), 16), { animate: true });
      return;
    }
  });

  // Index clients by id once so the detail panel doesn't have to scan
  // the boot list every time something is selected.
  const clientById = new Map();
  (boot.clients || []).forEach((c) => clientById.set(c.id, c));

  // ----- detail-fetch helper (?detail=kind&id=...) ----------------
  async function fetchDetail(kind, id) {
    try {
      const r = await fetch('/admin/map.php?detail=' + encodeURIComponent(kind)
                          + '&id=' + encodeURIComponent(id),
                          { credentials: 'same-origin' });
      const j = await r.json();
      return (j && j.ok) ? j : null;
    } catch (e) { return null; }
  }

  // ----- formatting helpers used by every panel ------------------
  function fmtDbm(v)  { return v != null ? v + ' dBm' : '—'; }
  function fmtMbps(v) { return v != null ? (Math.abs(v) >= 100 ? Math.round(v) : v.toFixed(1)) + ' Mbps' : '—'; }
  function fmtPct(v)  { return v != null ? Math.round(v) + '%' : '—'; }
  function fmtAge(iso) {
    if (!iso) return '—';
    const t = Date.parse(iso);
    if (isNaN(t)) return iso;
    const sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (sec < 60)   return sec + 's ago';
    if (sec < 3600) return Math.floor(sec / 60)  + 'm ago';
    if (sec < 86400)return Math.floor(sec / 3600)+ 'h ago';
    return Math.floor(sec / 86400) + 'd ago';
  }
  function healthBucket(score) {
    if (score == null) return { c: '#888',     l: 'unknown' };
    if (score >= 75)   return { c: '#22c55e',  l: 'healthy' };
    if (score >= 50)   return { c: '#eab308',  l: 'fair'    };
    if (score >= 25)   return { c: '#f97316',  l: 'degraded'};
    return { c: '#dc2626', l: 'critical' };
  }
  function snrPct(snr) {
    if (snr == null) return null;
    return Math.max(0, Math.min(100, ((snr - 5) / 35) * 100));   // 5..40 dB → 0..100 %
  }
  function dbmToPctStrict(dbm) {
    if (dbm == null) return null;
    return Math.max(0, Math.min(100, ((dbm + 95) / 50) * 100));  // -95..-45 dBm
  }

  // Generic key/value cell row — used by every panel for compact stats
  function kvCell(label, value, opts) {
    opts = opts || {};
    const wide = opts.wide ? ' mdp-cell-wide' : '';
    const cls  = opts.color ? ' style="color:' + opts.color + ';"' : '';
    return '<div class="mdp-cell' + wide + '">'
         +   '<span class="mdp-label">' + escapeHtml(label) + '</span>'
         +   '<span class="mdp-val"' + cls + ' title="' + escapeHtml(String(value)) + '">'
         +     escapeHtml(String(value)) + '</span>'
         + '</div>';
  }

  // Decorate a thin {id,name,type,lat,lng} reference with counts the
  // panel needs (devices / sectors / backbone links). Pulled straight
  // from the in-memory indices so it's free even when the API detail
  // payload doesn't carry them — keeps the right-side endpoint card
  // from sitting half-empty in sector/link panels.
  function enrichSiteForCard(s) {
    if (!s || s.id == null) return s || {};
    const id = Number(s.id);
    const out = Object.assign({}, s);
    const devs = devicesBySite.get(id) || [];
    out.devices = {
      n: (s.devices && s.devices.n != null) ? s.devices.n : devs.length,
      online: (s.devices && s.devices.online != null)
              ? s.devices.online
              : devs.filter((d) => d.status === 'online').length,
    };
    out.sector_count = (sectorsByTower.get(id) || new Set()).size;
    let lc = 0;
    linkLines.forEach((e) => {
      if (e.data.from_site_id === id || e.data.to_site_id === id) lc++;
    });
    out.backbone_count = lc;
    const entry = siteIndex.get(id);
    if (entry && out.coverage_radius_m == null) out.coverage_radius_m = entry.data.coverage_radius_m;
    return out;
  }

  // Shared "endpoint card" for any site (used by link + sector panels)
  function siteCardFromDetail(s, role) {
    if (!s) return '<div class="mdp-card" data-role="' + role + '"><div class="mdp-name"><input value="—" readonly></div></div>';
    const e = enrichSiteForCard(s);
    const typeLbl = siteTypeLabel(e.type);
    const typeCol = SITE_COLOR[e.type] || '#888';
    const cells = [];
    cells.push(kvCell('Location', (e.lat != null ? e.lat.toFixed(5) : '—') + ', ' + (e.lng != null ? e.lng.toFixed(5) : '—'), { wide: true }));
    if (e.devices) {
      cells.push(kvCell('Devices', e.devices.n + (e.devices.online != null ? ' · ' + e.devices.online + ' on' : '')));
    }
    if (e.sector_count != null && (e.type === 'tower' || e.sector_count > 0)) {
      cells.push(kvCell('Sectors', String(e.sector_count)));
    }
    if (e.backbone_count != null) {
      cells.push(kvCell('Backbone', e.backbone_count + ' link' + (e.backbone_count === 1 ? '' : 's')));
    }
    if (e.coverage_radius_m) cells.push(kvCell('Coverage', e.coverage_radius_m + ' m'));
    return ''
      + '<div class="mdp-card" data-role="' + role + '">'
      +   '<div class="mdp-name">'
      +     '<input type="text" value="' + escapeHtml(e.name) + '" readonly>'
      +     '<span class="mdp-type-pill" style="color:' + typeCol + ';background:' + typeCol + '20;">' + escapeHtml(typeLbl) + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv">' + cells.join('') + '</div>'
      + '</div>';
  }

  /* ---------- Sector detail ---------- */
  async function openSectorDetail(sector) {
    if (!panel || !panelGrid) return;
    // Skeleton with what's in memory (sectorIndex) — fill in from API
    // when the fetch returns. Lets the panel feel snappy on slow links.
    const towerEntry = siteIndex.get(sector.tower_id);
    showSectorSkeleton(sector, towerEntry ? towerEntry.data : null);
    selectedFeature = { kind: 'sector', id: sector.id, layer: (sectorIndex.get(sector.id) || {}).layer };
    if (selectedFeature.layer && selectedFeature.layer._path) selectedFeature.layer._path.classList.add('is-mdp-selected');
    if (pulseRing) { map.removeLayer(pulseRing); pulseRing = null; }

    // Sidebar: clients connected to this sector
    openSidePanel({
      title: sector.name,
      subtitle: 'Sector · clients on this AP',
      tabs: buildSectorSidebarTabs(sector),
    });

    const j = await fetchDetail('sector', sector.id);
    if (!j) return;
    if (!selectedFeature || selectedFeature.kind !== 'sector' || selectedFeature.id !== sector.id) return; // user moved on
    renderSectorDetail(j);
  }

  function showSectorSkeleton(s, tower) {
    const sec = s;
    const towerCard = tower ? siteCardFromDetail({
      id: tower.id, name: tower.name, type: tower.type, lat: tower.lat, lng: tower.lng,
    }, 'tower') : '<div class="mdp-card"></div>';
    panel.classList.remove('is-site');
    panelGrid.innerHTML = ''
      + sectorCardHTML(sec, null, null, null)
      + sectorCenterHTML(sec, null, null, null)
      + towerCard;
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    if (shellEl) shellEl.classList.add('is-bottom-open');
  }

  function renderSectorDetail(j) {
    const s = j.sector, t = j.tower, ap = j.ap_device, st = j.stats || {}, oa = j.outage;
    const towerCard = t ? siteCardFromDetail({
      id: t.id, name: t.name, type: t.type, lat: t.lat, lng: t.lng,
    }, 'tower') : '<div class="mdp-card"></div>';
    panel.classList.remove('is-site');
    panelGrid.innerHTML = ''
      + sectorCardHTML(s, ap, j.customer_count, oa)
      + sectorCenterHTML(s, ap, st, oa)
      + towerCard;
  }

  function sectorCardHTML(s, ap, customerCount, outage) {
    const az = s.azimuth_deg != null ? s.azimuth_deg + '°' : '—';
    const bw = s.beamwidth_deg != null ? s.beamwidth_deg + '°' : '—';
    const fq = s.frequency_mhz != null
             ? s.frequency_mhz + ' MHz' + (s.channel_width_mhz ? ' / ' + s.channel_width_mhz + ' MHz' : '')
             : '—';
    const tx = s.tx_power_dbm != null ? s.tx_power_dbm + ' dBm' : '—';
    const bandCol = BAND_COLOR[s.band] || BAND_COLOR.other;
    const cells = [
      kvCell('Azimuth', az), kvCell('Beamwidth', bw),
      kvCell('Frequency', fq, { wide: true }),
      kvCell('TX power', tx),
      kvCell('Mode', s.wireless_mode || '—'),
    ];
    if (s.ssid)        cells.push(kvCell('SSID', s.ssid, { wide: true }));
    if (s.security)    cells.push(kvCell('Security', s.security));
    if (ap)            cells.push(kvCell('AP', ap.name + ' · ' + ap.status));
    if (s.max_clients) {
      const cnt = customerCount != null ? customerCount : '—';
      cells.push(kvCell('Customers', cnt + ' / ' + s.max_clients));
    } else if (customerCount != null) {
      cells.push(kvCell('Customers', String(customerCount)));
    }
    return ''
      + '<div class="mdp-card" data-role="sector">'
      +   '<div class="mdp-name">'
      +     '<input type="text" value="' + escapeHtml(s.name) + '" readonly>'
      +     '<span class="mdp-type-pill" style="color:' + bandCol + ';background:' + bandCol + '20;">' + escapeHtml(s.band) + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv">' + cells.join('') + '</div>'
      + '</div>';
  }

  function sectorCenterHTML(s, ap, st, outage) {
    const cap = (s.max_clients && s.max_clients > 0 && st && st.link_count != null)
              ? Math.min(100, Math.round((st.link_count / s.max_clients) * 100))
              : null;
    const capPct = cap != null ? cap : 35;
    const health = st && st.avg_health != null ? st.avg_health : null;
    const sigPct = health != null ? health : 50;
    const tput   = st && st.total_throughput != null ? st.total_throughput : null;
    const peak   = st && st.peak_throughput  != null ? st.peak_throughput  : null;
    const apOff  = ap && ap.status === 'offline';
    const outageBlock = outage
      ? '<div class="pp-outage" style="margin:0 0 8px;">'
        + '<strong>Active outage</strong> · started ' + escapeHtml(outage.started_at)
        + (outage.cause ? ' · ' + escapeHtml(outage.cause) : '')
        + (outage.affected_count ? ' · ' + outage.affected_count + ' affected' : '')
        + '</div>'
      : '';
    return ''
      + '<div class="mdp-center">'
      +   outageBlock
      +   '<div class="mdp-cap-row">'
      +     '<span>Capacity</span>'
      +     '<span class="mdp-cap-val">' + (cap != null ? cap + '%' : '—') + '</span>'
      +   '</div>'
      +   '<div class="mdp-cap-bar"><div class="mdp-cap-fill" style="width:' + capPct + '%;background:' + (cap != null && cap >= 90 ? 'linear-gradient(90deg,#f97316,#dc2626)' : (cap != null && cap >= 70 ? 'linear-gradient(90deg,#eab308,#f97316)' : 'linear-gradient(90deg,#05DAFD,#0c8)')) + ';"></div></div>'
      +   '<div class="mdp-distance">'
      +     '<span>Throughput</span>'
      +     '<span class="mdp-arrow"></span>'
      +     '<span class="mdp-dist-val">' + (tput != null ? fmtMbps(tput) : 'no data') + '</span>'
      +     '<span class="mdp-arrow"></span>'
      +     '<span>peak ' + (peak != null ? fmtMbps(peak) : '—') + '</span>'
      +   '</div>'
      +   '<div class="mdp-signal-bar">'
      +     '<div class="mdp-signal-marker" style="left:calc(' + sigPct + '% - 1.5px);"></div>'
      +   '</div>'
      +   '<div class="mdp-signal-meta">'
      +     '<span>Avg link health</span>'
      +     '<span>' + (health != null ? health + ' / 100' : 'no data') + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv" style="grid-template-columns:repeat(4,1fr);">'
      +     kvCell('Links',      st && st.link_count != null ? st.link_count : 0)
      +     kvCell('Avg signal', st && st.avg_signal != null ? st.avg_signal + ' dBm' : '—')
      +     kvCell('Avg SNR',    st && st.avg_snr    != null ? st.avg_snr    + ' dB'  : '—')
      +     kvCell('Avg CCQ',    st && st.avg_ccq    != null ? Math.round(st.avg_ccq) + '%' : '—')
      +   '</div>'
      +   '<div class="mdp-actions">'
      +     '<button type="button" class="btn btn-ghost btn-sm" data-edit-sector="' + s.id + '">Edit</button>'
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-sector="' + s.id + '">Delete</button>'
      +     (s.id ? '<a class="btn btn-ghost btn-sm" href="/admin/sector-edit.php?id=' + s.id + '">Open</a>' : '')
      +     (ap && ap.id ? '<a class="btn btn-ghost btn-sm" href="/admin/device-view.php?id=' + ap.id + '">AP</a>' : '')
      +   '</div>'
      + '</div>';
  }

  /* ---------- Client detail ---------- */
  async function openClientDetail(client) {
    if (!panel || !panelGrid) return;
    showClientSkeleton(client);
    selectedFeature = { kind: 'client', id: client.id };
    if (pulseRing) { map.removeLayer(pulseRing); pulseRing = null; }
    // Clients don't carry a useful related-list of their own — the
    // sector that owns them is already shown in the right card of
    // the bottom panel. Close the sidebar to give the map back.
    closeSidePanel();
    if (client.lat != null && client.lng != null) {
      pulseRing = L.marker([client.lat, client.lng], {
        interactive: false,
        icon: L.divIcon({ className: '', html: '<div class="mdp-pulse-marker"></div>',
                          iconSize: [22, 22], iconAnchor: [11, 11] }),
      }).addTo(map);
    }
    const j = await fetchDetail('client', client.id);
    if (!j) return;
    if (!selectedFeature || selectedFeature.kind !== 'client' || selectedFeature.id !== client.id) return;
    renderClientDetail(j);
  }

  function showClientSkeleton(c) {
    panel.classList.remove('is-site');
    panelGrid.innerHTML = ''
      + clientCardHTML(c)
      + clientCenterHTML(c, null, null, null)
      + '<div class="mdp-card"><div class="mdp-name"><input value="…" readonly></div></div>';
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    if (shellEl) shellEl.classList.add('is-bottom-open');
  }

  function renderClientDetail(j) {
    const c   = j.client;
    const sec = j.sector;
    const t   = j.tower;
    const ap  = j.ap_device;
    const wl  = j.wireless_link;
    const sectorCardHTML = sec ? ''
      + '<div class="mdp-card" data-role="sector">'
      +   '<div class="mdp-name">'
      +     '<input type="text" value="' + escapeHtml(sec.name) + '" readonly>'
      +     '<span class="mdp-type-pill" style="color:' + (BAND_COLOR[sec.band] || '#888') + ';background:' + (BAND_COLOR[sec.band] || '#888') + '20;">' + escapeHtml(sec.band || '') + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv">'
      +     kvCell('Tower', t ? t.name : '—', { wide: true })
      +     kvCell('Azimuth',   sec.azimuth_deg != null ? sec.azimuth_deg + '°' : '—')
      +     kvCell('Beamwidth', sec.beamwidth_deg != null ? sec.beamwidth_deg + '°' : '—')
      +     kvCell('Frequency', sec.frequency_mhz != null ? sec.frequency_mhz + ' MHz' : '—')
      +     kvCell('Channel',   sec.channel_width_mhz != null ? sec.channel_width_mhz + ' MHz' : '—')
      +     (ap ? kvCell('AP', ap.name + ' · ' + ap.status, { wide: true, color: ap.status === 'offline' ? '#dc2626' : null }) : '')
      +   '</div>'
      + '</div>'
      : '<div class="mdp-card"><div class="mdp-name"><input value="No sector assigned" readonly></div></div>';

    panel.classList.remove('is-site');
    panelGrid.innerHTML = clientCardHTML(c) + clientCenterHTML(c, wl, j.distance_km, ap) + sectorCardHTML;
  }

  function clientCardHTML(c) {
    const statusCol = STATUS_COLOR[c.status] || '#888';
    const cells = [];
    cells.push(kvCell('Username',  c.username || '—', { wide: true }));
    if (c.name)     cells.push(kvCell('Name',    c.name,    { wide: true }));
    if (c.address)  cells.push(kvCell('Address', c.address, { wide: true }));
    if (c.phone)    cells.push(kvCell('Phone',   c.phone));
    if (c.email)    cells.push(kvCell('Email',   c.email));
    if (c.lat != null && c.lng != null) {
      cells.push(kvCell('Coords', c.lat.toFixed(5) + ', ' + c.lng.toFixed(5), { wide: true }));
    }
    return ''
      + '<div class="mdp-card" data-role="client">'
      +   '<div class="mdp-name">'
      +     '<input type="text" value="' + escapeHtml(c.account_no || c.username || ('client ' + c.id)) + '" readonly>'
      +     '<span class="mdp-type-pill" style="color:' + statusCol + ';background:' + statusCol + '20;">' + escapeHtml(c.status || '') + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv">' + cells.join('') + '</div>'
      + '</div>';
  }

  function clientCenterHTML(c, wl, distKm, ap) {
    const sig = wl && wl.signal_dbm != null ? wl.signal_dbm : null;
    const snr = wl && wl.snr_db     != null ? wl.snr_db     : null;
    const ccq = wl && wl.ccq_pct    != null ? wl.ccq_pct    : null;
    const hp  = wl && wl.health_score != null ? wl.health_score : null;
    const tx  = wl && wl.tx_rate_mbps != null ? wl.tx_rate_mbps : null;
    const rx  = wl && wl.rx_rate_mbps != null ? wl.rx_rate_mbps : null;
    const tputL = wl && wl.throughput_local_mbps != null ? wl.throughput_local_mbps : null;
    const tputR = wl && wl.throughput_remote_mbps != null ? wl.throughput_remote_mbps : null;
    const totalT = (tputL != null || tputR != null)
                   ? ((tputL || 0) + (tputR || 0)) : null;
    const cap   = wl && (wl.capacity_local_mbps || wl.capacity_remote_mbps);
    const sigPct = (() => {
      const p = dbmToPctStrict(sig);
      if (p != null) return p;
      const sp = snrPct(snr);
      return sp != null ? sp : 50;
    })();
    const health = healthBucket(hp);
    const lastAge = wl && wl.last_evaluated_at ? fmtAge(wl.last_evaluated_at) : null;
    const apOff = ap && ap.status === 'offline';

    const apBanner = apOff
      ? '<div class="pp-outage" style="margin:0 0 8px;background:rgba(220,68,68,.18);border-left-color:#d44;">'
        + '<strong>AP offline</strong> · ' + escapeHtml(ap.name)
        + (ap.last_seen_at ? ' · last seen ' + fmtAge(ap.last_seen_at) : '')
        + '</div>'
      : '';
    const noWlBanner = !wl
      ? '<div class="pp-outage" style="margin:0 0 8px;background:rgba(120,140,160,.16);border-left-color:#888;">'
        + 'No live wireless-link sample for this client yet.'
        + '</div>'
      : '';

    return ''
      + '<div class="mdp-center">'
      +   apBanner
      +   noWlBanner
      +   '<div class="mdp-cap-row">'
      +     '<span>Throughput' + (lastAge ? ' · ' + lastAge : '') + '</span>'
      +     '<span class="mdp-cap-val">' + (totalT != null ? fmtMbps(totalT) : '—') + '</span>'
      +   '</div>'
      +   '<div class="mdp-cap-bar"><div class="mdp-cap-fill" style="width:' + (totalT != null && cap ? Math.min(100, (totalT / cap) * 100) : 35).toFixed(0) + '%;"></div></div>'
      +   '<div class="mdp-distance">'
      +     '<span>Tower</span>'
      +     '<span class="mdp-arrow"></span>'
      +     '<span class="mdp-dist-val">' + (distKm != null ? distKm + ' km' : '—') + '</span>'
      +     '<span class="mdp-arrow"></span>'
      +     '<span>CPE</span>'
      +   '</div>'
      +   '<div class="mdp-signal-bar">'
      +     '<div class="mdp-signal-marker" style="left:calc(' + sigPct.toFixed(0) + '% - 1.5px);" title="' + (sig != null ? sig + ' dBm' : (snr != null ? snr + ' dB SNR' : 'unknown')) + '"></div>'
      +   '</div>'
      +   '<div class="mdp-signal-meta">'
      +     '<span>' + (sig != null ? 'Signal ' + sig + ' dBm' : (snr != null ? 'SNR ' + snr + ' dB' : 'No signal data')) + '</span>'
      +     '<span style="color:' + health.c + ';font-weight:600;">' + (hp != null ? 'Health ' + hp : health.l) + '</span>'
      +   '</div>'
      +   '<div class="mdp-kv" style="grid-template-columns:repeat(4,1fr);">'
      +     kvCell('Signal', fmtDbm(sig))
      +     kvCell('SNR',    snr != null ? snr + ' dB' : '—')
      +     kvCell('CCQ',    ccq != null ? Math.round(ccq) + '%' : '—')
      +     kvCell('Mode',   wl && wl.wireless_mode ? wl.wireless_mode : '—')
      +     kvCell('TX rate', tx != null ? fmtMbps(tx) : '—')
      +     kvCell('RX rate', rx != null ? fmtMbps(rx) : '—')
      +     kvCell('TX power', wl && wl.tx_power_dbm_local != null ? wl.tx_power_dbm_local + ' dBm' : '—')
      +     kvCell('Modulation', wl && wl.modulation ? wl.modulation : '—')
      +   '</div>'
      +   '<div class="mdp-actions">'
      +     '<a class="btn btn-ghost btn-sm" href="/admin/client-edit.php?id=' + c.id + '">Open record</a>'
      +     (wl && wl.id ? '<a class="btn btn-ghost btn-sm" href="/admin/link-view.php?id=' + wl.id + '">Live link</a>' : '')
      +   '</div>'
      + '</div>';
  }

  /* ---------- Wire popupopen → open the right detail panel ---------- */
  map.on('popupopen', (e) => {
    const src = e.popup && e.popup._source;
    if (!src) return;
    if (src.wfLinkId != null) {
      const entry = linkLines.get(src.wfLinkId);
      if (entry) openLinkDetail(entry.data);
      return;
    }
    if (src.wfSectorId != null) {
      const entry = sectorIndex.get(src.wfSectorId);
      if (entry) openSectorDetail(entry.data);
      return;
    }
    if (src.wfSiteId != null) {
      const entry = siteIndex.get(src.wfSiteId);
      if (entry) openSiteDetail(entry.data);
      return;
    }
    if (src.wfClientId != null) {
      const c = clientById.get(src.wfClientId);
      if (c) openClientDetail(c);
      return;
    }
  });
  map.on('popupclose', (e) => {
    const src = e.popup && e.popup._source;
    if (!selectedFeature || !src) return;
    if      (selectedFeature.kind === 'link'   && src.wfLinkId   === selectedFeature.id) closePanel();
    else if (selectedFeature.kind === 'sector' && src.wfSectorId === selectedFeature.id) closePanel();
    else if (selectedFeature.kind === 'site'   && src.wfSiteId   === selectedFeature.id) closePanel();
    else if (selectedFeature.kind === 'client' && src.wfClientId === selectedFeature.id) closePanel();
  });

  /* ---------- live refresh (device status + active outages) ----------
     Every 30 seconds we hit /admin/map.php?poll=1 for a snapshot of
     device.status by id and the current set of sector_ids in active
     outage. Sector cones flip red/normal in place; the toolbar's
     outage count badge updates. We don't refetch sites or links since
     those don't change without an admin action. */
  async function refreshLive() {
    try {
      const r = await fetch('/admin/map.php?poll=1', { credentials: 'same-origin' });
      const j = await r.json();
      if (!j || !j.ok) return;

      // Update device status in the by-site index, so device popup
      // pills stay current next time they open.
      const newStatuses = j.devices || {};
      devicesBySite.forEach((list) => {
        list.forEach((d) => {
          const upd = newStatuses[d.id];
          if (upd) { d.status = upd.status; d.last_seen_at = upd.last_seen_at; }
        });
      });

      // Diff outage set and re-render cones whose state flipped.
      const newOutageIds = new Set(j.outage_sector_ids || []);
      const flipped = [];
      sectorIndex.forEach((entry, sid) => {
        const wasOut = outageSectorIds.has(sid);
        const nowOut = newOutageIds.has(sid);
        if (wasOut !== nowOut) flipped.push(sid);
      });
      outageSectorIds.clear();
      newOutageIds.forEach((id) => outageSectorIds.add(id));
      flipped.forEach((sid) => {
        const e = sectorIndex.get(sid);
        if (!e) return;
        removeSectorFromIndex(sid);
        renderSector(e.data);
      });

      // Update the outage count chip in the toolbar.
      const counts = document.getElementById('count-sectors');
      if (counts && typeof j.outage_count === 'number') {
        // not the sectors count; but if there's an outage chip, mark it
        const outageBadge = document.querySelector('.map-counts a[href="/admin/outages.php"] strong');
        if (outageBadge) outageBadge.textContent = String(j.outage_count);
      }
    } catch (e) { /* network burp — try again next tick */ }
  }
  setInterval(refreshLive, 30000);
})();
