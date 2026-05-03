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
  const map = L.map('map', { zoomControl: true }).setView(boot.center, boot.zoom);

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
  tileLayers.Streets.addTo(map);
  L.control.layers(tileLayers, {}, { position: 'topright', collapsed: false }).addTo(map);

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
      +   '</div>'
      +   deviceListHTML(s.id)
      +   (s.type === 'tower' ? sectorListHTML(s.id) : '')
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
    });
    line.bindPopup(linkPopupHTML(l, a.data, b.data));
    line.addTo(linksLayer);
    linkLines.set(l.id, { line, data: l });
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

    const stroke = inOutage ? '#d44' : bandColor;
    const fill   = inOutage ? '#d44' : bandColor;

    const poly = L.polygon(
      sectorPolygon(tower.data.lat, tower.data.lng, Number(az), Number(bw), range),
      {
        color: stroke,
        weight: inOutage ? 3 : 1.5,
        fillColor: fill,
        fillOpacity: inOutage ? 0.25 : 0.15,
        opacity: inOutage ? 0.95 : 0.7,
      }
    );
    poly.bindPopup(sectorPopupHTML(sector, tower.data.name));
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
    const fq = s.frequency_mhz != null
      ? s.frequency_mhz + ' MHz' + (s.channel_width_mhz ? ' @ ' + s.channel_width_mhz + ' MHz wide' : '')
      : null;
    const outage = outageBySectorId[s.id];
    const outageBlock = outage
      ? '<div class="pp-outage">'
        + '<strong>Active outage</strong><br>'
        + 'Started: ' + escapeHtml(outage.started_at) + '<br>'
        + (outage.cause ? 'Cause: ' + escapeHtml(outage.cause) + '<br>' : '')
        + outage.affected_count + ' customer' + (outage.affected_count === 1 ? '' : 's') + ' affected'
        + '</div>'
      : '';
    const kv = [];
    kv.push(['Azimuth', s.azimuth_deg   != null ? s.azimuth_deg   + '°' : '—']);
    kv.push(['Beam',    s.beamwidth_deg != null ? s.beamwidth_deg + '°' : '—']);
    if (fq)                       kv.push(['Freq',  fq]);
    if (s.tx_power_dbm   != null) kv.push(['TX',    s.tx_power_dbm + ' dBm']);
    if (s.ap_device_name)         kv.push(['AP',    s.ap_device_name]);
    if (s.max_clients    != null) kv.push(['Max',   s.max_clients + ' clients']);
    const kvHtml = '<dl class="pp-kv">'
      + kv.map(([k, v]) => '<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(String(v)) + '</dd>').join('')
      + '</dl>';
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(s.name) + '</strong><br>'
      +   '<small>' + escapeHtml(towerName) + ' · ' + escapeHtml(s.band) + '</small>'
      +   outageBlock
      +   kvHtml
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
})();
