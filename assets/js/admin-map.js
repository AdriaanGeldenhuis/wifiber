/* Admin network map — Leaflet over OSM/Esri tiles. Reads bootstrap data
   from the inline <script type="application/json" id="map-data"> tag and
   talks back to /admin/map.php?ajax=1 for adds, moves and deletes. */

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
  // billing status is fine. retired/online/null all leave the marker
  // alone (no badge).
  const CLIENT_BADGE_COLOR = { offline: '#d44', unknown: '#aaa' };

  /* ---------- state ---------- */
  let mode = 'pan';            // 'pan' | 'add_site' | 'add_link'
  let pendingLinkFrom = null;  // first site clicked when adding a link
  const siteIndex = new Map(); // id -> {data, marker}
  const linkLines = new Map(); // id -> polyline

  /* ---------- mode toggling ---------- */
  function setMode(next) {
    mode = next;
    pendingLinkFrom = null;
    document.querySelectorAll('[data-mode]').forEach((b) => {
      b.classList.toggle('map-mode-active', b.dataset.mode === mode);
    });
    map.getContainer().style.cursor = mode === 'add_site' ? 'crosshair'
                                    : mode === 'add_link' ? 'pointer'
                                    : '';
  }
  document.querySelectorAll('[data-mode]').forEach((b) => {
    b.addEventListener('click', () => setMode(b.dataset.mode));
  });

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

  /* ---------- indexes (devices + sectors keyed by site/tower) ---------- */
  const devicesBySite = new Map(); // site_id -> [device, ...]
  (boot.devices || []).forEach((d) => {
    if (d.site_id == null) return;
    const sid = parseInt(d.site_id, 10);
    if (!devicesBySite.has(sid)) devicesBySite.set(sid, []);
    devicesBySite.get(sid).push(d);
  });
  const sectorsByTower = new Map(); // tower_id -> [sector, ...]
  (boot.sectors || []).forEach((s) => {
    const tid = parseInt(s.tower_id, 10);
    if (!sectorsByTower.has(tid)) sectorsByTower.set(tid, []);
    sectorsByTower.get(tid).push(s);
  });

  /* ---------- outage indexes ---------- */
  const outageSectorIds  = new Set((boot.outages && boot.outages.sector_ids) || []);
  const outageTowerIds   = new Set((boot.outages && boot.outages.tower_ids)  || []);
  const outageBySectorId = (boot.outages && boot.outages.by_sector_id) || {};

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
    if (!list.length) return '';
    const rows = list.map((d) => {
      const c = DEVICE_COLOR[d.status] || DEVICE_COLOR.unknown;
      const pill = '<span style="background:' + c + ';color:#fff;padding:0 5px;border-radius:6px;font-size:10px;">'
                 + escapeHtml(d.status) + '</span>';
      const meta = [d.role, d.vendor + (d.model ? ' ' + d.model : '')].filter(Boolean).join(' &middot; ');
      return '<li><a href="/admin/devices.php?search=' + encodeURIComponent(d.name) + '" style="color:inherit;">'
           + escapeHtml(d.name) + '</a> ' + pill + '<br><small class="muted">' + escapeHtml(meta) + '</small></li>';
    }).join('');
    return '<div style="margin-top:8px;border-top:1px solid rgba(255,255,255,0.1);padding-top:6px;">'
         + '<small><strong>Devices (' + list.length + ')</strong></small>'
         + '<ul style="margin:4px 0 0;padding-left:14px;">' + rows + '</ul>'
         + '</div>';
  }

  function sectorListHTML(towerId) {
    const list = sectorsByTower.get(towerId) || [];
    if (!list.length) return '';
    const rows = list.map((s) => {
      const c = BAND_COLOR[s.band] || BAND_COLOR.other;
      const dot = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + c + ';"></span>';
      const az  = s.azimuth_deg   != null ? s.azimuth_deg   + '&deg;' : '?';
      const bw  = s.beamwidth_deg != null ? s.beamwidth_deg + '&deg;' : '?';
      const fq  = s.frequency_mhz != null ? s.frequency_mhz + ' MHz'
                + (s.channel_width_mhz ? ' @ ' + s.channel_width_mhz : '')
                : '';
      return '<li>' + dot + ' <a href="/admin/sectors.php?tower_id=' + towerId + '" style="color:inherit;">'
           + escapeHtml(s.name) + '</a><br><small class="muted">'
           + escapeHtml(s.band) + ' &middot; ' + az + ' / ' + bw
           + (fq ? ' &middot; ' + escapeHtml(fq) : '')
           + '</small></li>';
    }).join('');
    return '<div style="margin-top:8px;border-top:1px solid rgba(255,255,255,0.1);padding-top:6px;">'
         + '<small><strong>Sectors (' + list.length + ')</strong></small>'
         + '<ul style="margin:4px 0 0;padding-left:14px;">' + rows + '</ul>'
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
      // Pulsing red halo to draw the eye on a busy map.
      const halo = L.circleMarker([s.lat, s.lng], {
        radius: 18, color: '#d44', weight: 2, fillColor: '#d44',
        fillOpacity: 0.15, opacity: 0.85, interactive: false,
      });
      halo.addTo(sitesLayer);
    }
    marker.bindPopup(sitePopupHTML(s));
    marker.on('dragend', async (e) => {
      const ll = e.target.getLatLng();
      const r = await postAction('move_site', { id: s.id, lat: ll.lat, lng: ll.lng });
      if (!r.ok) {
        alert(r.error || 'Move failed');
        marker.setLatLng([s.lat, s.lng]);
      } else {
        s.lat = ll.lat; s.lng = ll.lng;
        redrawLinksFor(s.id);
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
      +   (s.notes ? '<p style="margin:6px 0 0;">' + escapeHtml(s.notes) + '</p>' : '')
      +   '<div class="row" style="margin-top:8px;">'
      +     '<button type="button" class="btn btn-ghost btn-sm" data-edit-site="' + s.id + '">Edit</button>'
      +     '<button type="button" class="btn btn-danger btn-sm" data-delete-site="' + s.id + '">Delete</button>'
      +   '</div>'
      +   deviceListHTML(s.id)
      +   (s.type === 'tower' ? sectorListHTML(s.id) : '')
      + '</div>';
  }

  function siteEditFormHTML(s) {
    const types = ['tower', 'ap', 'ptp_endpoint', 'pop', 'other'];
    return ''
      + '<form data-map-form="update_site" class="map-popup">'
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
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(l.label || (l.type.toUpperCase() + ' link')) + '</strong><br>'
      +   '<small>' + escapeHtml(from.name) + ' &harr; ' + escapeHtml(to.name) + '</small><br>'
      +   '<small>' + escapeHtml(l.type) + (l.capacity_mbps ? ' &middot; ' + l.capacity_mbps + ' Mbps' : '') + (l.frequency ? ' &middot; ' + escapeHtml(l.frequency) : '') + '</small>'
      +   '<div class="row" style="margin-top:8px;">'
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
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(c.account_no || c.username) + '</strong><br>'
      +   escapeHtml(c.name || '') + '<br>'
      +   (c.address ? '<small>' + escapeHtml(c.address) + '</small><br>' : '')
      +   '<span style="display:inline-block;background:' + (STATUS_COLOR[c.status] || '#888')
      +     ';color:#fff;padding:1px 7px;border-radius:8px;font-size:11px;text-transform:uppercase;">' + escapeHtml(c.status) + '</span>'
      +   (c.sector_label
            ? '<div style="margin-top:6px;font-size:12px;"><span class="muted">Sector:</span> '
              + '<a href="/admin/sectors.php?search=' + encodeURIComponent(c.sector_label.split(' · ')[0])
              + '" style="color:inherit;">' + escapeHtml(c.sector_label) + '</a></div>'
            : '')
      +   (c.network_status
            ? '<div style="margin-top:2px;font-size:12px;"><span class="muted">AP:</span> '
              + '<span style="background:' + (DEVICE_COLOR[c.network_status] || '#888')
              + ';color:#fff;padding:0 6px;border-radius:6px;font-size:10px;">'
              + escapeHtml(c.network_status) + '</span></div>'
            : '')
      +   '<div class="row" style="margin-top:8px;">'
      +     '<a class="btn btn-ghost btn-sm" href="/admin/client-edit.php?id=' + c.id + '">Open record</a>'
      +   '</div>'
      + '</div>';
  }

  /* ---------- add-site mode ---------- */
  function openAddSitePopup(latlng) {
    const types = ['tower', 'ap', 'ptp_endpoint', 'pop', 'other'];
    const html = ''
      + '<form data-map-form="add_site" class="map-popup">'
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

  map.on('click', (e) => {
    if (mode === 'add_site') {
      openAddSitePopup(e.latlng);
    }
  });

  /* ---------- add-link mode ---------- */
  function handleAddLinkClick(site) {
    if (!pendingLinkFrom) {
      pendingLinkFrom = site;
      flashStatus('From: ' + site.name + ' — now click the destination site.');
      return;
    }
    if (pendingLinkFrom.id === site.id) {
      flashStatus('Pick a different site for the destination.');
      return;
    }
    const html = ''
      + '<form data-map-form="add_link" class="map-popup">'
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

  /* ---------- popup form / button delegation ---------- */
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-map-form]');
    if (!form) return;
    e.preventDefault();
    const action = form.dataset.mapForm;
    const fd = new FormData(form);
    fd.append('action', action);
    fd.append('_csrf', CSRF);
    const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
    const r = await res.json().catch(() => ({ ok: false, error: 'Server error' }));
    if (!r.ok) { alert(r.error || 'Save failed'); return; }
    location.reload();
  });

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
      const id = parseInt(e.target.dataset.editSite, 10);
      const entry = siteIndex.get(id);
      if (entry) entry.marker.setPopupContent(siteEditFormHTML(entry.data));
    }
  });

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

  function flashStatus(msg) {
    geoStatus.textContent = msg;
    setTimeout(() => { if (geoStatus.textContent === msg) geoStatus.textContent = ''; }, 4000);
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
    if (az == null || bw == null) return; // can't draw a cone without a direction

    const range = (tower.data.coverage_radius_m && tower.data.coverage_radius_m > 0)
                ? Number(tower.data.coverage_radius_m)
                : SECTOR_DEFAULT_RANGE_M;
    const bandColor = BAND_COLOR[sector.band] || BAND_COLOR.other;
    const inOutage  = outageSectorIds.has(sector.id);

    // Outage tints the cone red and bumps the outline weight so it
    // pops on a busy map. Healthy sectors keep their band colour.
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
  }

  function sectorPopupHTML(s, towerName) {
    const fq = s.frequency_mhz != null
      ? s.frequency_mhz + ' MHz' + (s.channel_width_mhz ? ' @ ' + s.channel_width_mhz + ' MHz wide' : '')
      : null;
    const outage = outageBySectorId[s.id];
    const outageBlock = outage
      ? '<div style="margin:6px 0;padding:6px 8px;background:rgba(220,68,68,0.18);border-left:3px solid #d44;font-size:12px;">'
        + '<strong>Active outage</strong><br>'
        + '<small>Started: ' + escapeHtml(outage.started_at) + '</small><br>'
        + (outage.cause ? '<small>Cause: ' + escapeHtml(outage.cause) + '</small><br>' : '')
        + '<small>' + outage.affected_count + ' customer'
          + (outage.affected_count === 1 ? '' : 's') + ' affected</small>'
        + '</div>'
      : '';
    return ''
      + '<div class="map-popup">'
      +   '<strong>' + escapeHtml(s.name) + '</strong><br>'
      +   '<small>' + escapeHtml(towerName) + ' &middot; ' + escapeHtml(s.band) + '</small>'
      +   outageBlock
      +   '<div style="margin-top:6px;font-size:12px;">'
      +     '<div>Azimuth: ' + (s.azimuth_deg   != null ? s.azimuth_deg   + '&deg;' : '—') + '</div>'
      +     '<div>Beam: '    + (s.beamwidth_deg != null ? s.beamwidth_deg + '&deg;' : '—') + '</div>'
      +     (fq ? '<div>Freq: ' + escapeHtml(fq) + '</div>' : '')
      +     (s.tx_power_dbm != null ? '<div>TX: ' + s.tx_power_dbm + ' dBm</div>' : '')
      +     (s.ap_device_name ? '<div>AP: ' + escapeHtml(s.ap_device_name) + '</div>' : '')
      +     (s.max_clients != null ? '<div>Max clients: ' + s.max_clients + '</div>' : '')
      +   '</div>'
      +   '<div class="row" style="margin-top:8px;">'
      +     '<a class="btn btn-ghost btn-sm" href="/admin/sectors.php?tower_id=' + s.tower_id + '">Open record</a>'
      +     (outage ? '<a class="btn btn-ghost btn-sm" href="/admin/outages.php">Outages</a>' : '')
      +   '</div>'
      + '</div>';
  }

  /* ---------- bootstrap ---------- */
  boot.sites.forEach(renderSite);
  boot.site_links.forEach(renderLink);
  boot.clients.forEach(renderClient);
  (boot.sectors || []).forEach(renderSector);
})();
