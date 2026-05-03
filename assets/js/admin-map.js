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
  const coverageLayer = L.layerGroup();

  /* UISP overlay layers — added to the map only if UISP is configured. */
  const uispSitesLayer   = L.layerGroup();
  const uispDevicesLayer = L.layerGroup();
  const uispLinksLayer   = L.layerGroup();
  const uispClientsLayer = L.layerGroup();
  const uispEnabled = !!(boot.uisp && boot.uisp.enabled);
  if (uispEnabled) {
    uispSitesLayer.addTo(map);
    uispDevicesLayer.addTo(map);
    uispLinksLayer.addTo(map);
    uispClientsLayer.addTo(map);
  }

  /* ---------- icons ---------- */
  const SITE_COLOR  = { tower: '#08e', ap: '#0c8', ptp_endpoint: '#f80', pop: '#80f', other: '#888' };
  const STATUS_COLOR = { active: '#0c8', lead: '#08e', suspended: '#fa0', disconnected: '#888' };
  const LINK_COLOR   = { ptp: '#08e', ptmp: '#0c8', fiber: '#f0a', backhaul: '#f80' };
  const UISP_STATUS_COLOR = { online: '#0c8', offline: '#d44', unknown: '#888' };

  function dotIcon(color, size) {
    const s = size || 14;
    return L.divIcon({
      className: 'wf-marker',
      html: '<span style="display:block;width:' + s + 'px;height:' + s + 'px;border-radius:50%;background:' + color
          + ';border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.5);"></span>',
      iconSize: [s + 4, s + 4],
      iconAnchor: [(s + 4) / 2, (s + 4) / 2],
    });
  }

  function diamondIcon(color, size) {
    const s = size || 18;
    return L.divIcon({
      className: 'wf-uisp-marker',
      html: '<span style="display:block;width:' + s + 'px;height:' + s + 'px;background:' + color
          + ';border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.5);transform:rotate(45deg);"></span>',
      iconSize: [s + 6, s + 6],
      iconAnchor: [(s + 6) / 2, (s + 6) / 2],
    });
  }

  function ringIcon(color, size) {
    const s = size || 11;
    return L.divIcon({
      className: 'wf-uisp-marker',
      html: '<span style="display:block;width:' + s + 'px;height:' + s + 'px;border-radius:50%;background:transparent;border:3px solid '
          + color + ';box-shadow:0 0 3px rgba(0,0,0,.5);"></span>',
      iconSize: [s + 6, s + 6],
      iconAnchor: [(s + 6) / 2, (s + 6) / 2],
    });
  }

  function pillHTML(text, status) {
    const color = UISP_STATUS_COLOR[status] || '#888';
    return '<span class="map-uisp-pill ' + escapeHtml(status || 'unknown') + '" style="background:' + color
         + ';color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;">' + escapeHtml(text) + '</span>';
  }

  /* ---------- state ---------- */
  let mode = 'pan';            // 'pan' | 'add_site' | 'add_link'
  let pendingLinkFrom = null;  // first site clicked when adding a link
  const siteIndex = new Map(); // id -> {data, marker}
  const linkLines = new Map(); // id -> polyline

  /* UISP indexes — populated before rendering so popups can cross-reference. */
  const uispSiteById          = new Map(); // uisp_id -> uisp site row
  const uispDeviceById        = new Map(); // uisp_id -> uisp device row
  const uispClientById        = new Map(); // uisp_id -> uisp client row
  const linkedSiteByUispId    = new Map(); // uisp_id -> manual site row
  const linkedClientByUispId  = new Map(); // uisp_client_id -> manual client row

  function devicesForUispSite(uispSiteId) {
    const out = [];
    uispDeviceById.forEach(d => { if (d.uisp_site_id === uispSiteId) out.push(d); });
    return out;
  }

  function uispBadgeForSite(s) {
    if (!s.uisp_id) return '';
    const linked = uispSiteById.get(s.uisp_id);
    let html = '<div style="margin-top:6px;border-top:1px solid rgba(255,255,255,0.1);padding-top:6px;">'
             + '<small>UISP: <code>' + escapeHtml(s.uisp_id) + '</code></small>';
    if (!linked) {
      html += ' ' + pillHTML('not in cache', 'unknown');
    } else {
      const devs = devicesForUispSite(s.uisp_id);
      const on = devs.filter(d => d.status === 'online').length;
      const off = devs.filter(d => d.status === 'offline').length;
      const un = devs.length - on - off;
      html += '<br><small>'
           + (on  ? pillHTML(on + ' online',   'online')   + ' ' : '')
           + (off ? pillHTML(off + ' offline', 'offline')  + ' ' : '')
           + (un  ? pillHTML(un + ' unknown',  'unknown')  + ' ' : '')
           + (devs.length === 0 ? '<span class="muted">no devices in cache</span>' : '')
           + '</small>';
    }
    html += '<form data-map-form="unlink_site" style="display:inline;margin-left:6px;">'
         +    '<input type="hidden" name="site_id" value="' + s.id + '">'
         +    '<button type="submit" class="btn btn-ghost btn-sm">Unlink</button>'
         +  '</form>'
         + '</div>';
    return html;
  }

  function uispBadgeForClient(c) {
    if (!c.uisp_client_id) return '';
    const linked = uispClientById.get(c.uisp_client_id);
    let html = '<div style="margin-top:6px;border-top:1px solid rgba(255,255,255,0.1);padding-top:6px;">'
             + '<small>UISP: <code>' + escapeHtml(c.uisp_client_id) + '</code></small>';
    if (linked) {
      html += '<br><small>' + pillHTML(linked.status || 'unknown', /online|active/i.test(linked.status || '') ? 'online' : 'unknown') + '</small>';
    } else {
      html += ' ' + pillHTML('not in cache', 'unknown');
    }
    html += '<form data-map-form="unlink_client" style="display:inline;margin-left:6px;">'
         +    '<input type="hidden" name="user_id" value="' + c.id + '">'
         +    '<button type="submit" class="btn btn-ghost btn-sm">Unlink</button>'
         +  '</form>'
         + '</div>';
    return html;
  }

  function unlinkedManualSitesOptions() {
    return boot.sites.filter(s => !s.uisp_id)
      .map(s => '<option value="' + s.id + '">' + escapeHtml(s.name + ' (' + s.type + ')') + '</option>')
      .join('');
  }
  function unlinkedManualClientsOptions() {
    return boot.clients.filter(u => !u.uisp_client_id)
      .map(u => '<option value="' + u.id + '">' + escapeHtml((u.account_no || ('#' + u.id)) + ' · ' + (u.name || u.username || '')) + '</option>')
      .join('');
  }

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

  /* UISP toggles (only present if UISP is enabled) */
  function bindUispToggle(id, layer) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', (e) => {
      e.target.checked ? layer.addTo(map) : map.removeLayer(layer);
    });
  }
  bindUispToggle('toggle-uisp-sites',   uispSitesLayer);
  bindUispToggle('toggle-uisp-devices', uispDevicesLayer);
  bindUispToggle('toggle-uisp-links',   uispLinksLayer);
  bindUispToggle('toggle-uisp-clients', uispClientsLayer);

  /* ---------- render sites ---------- */
  function siteTypeLabel(t) {
    return ({ tower: 'Tower', ap: 'AP / Sector', ptp_endpoint: 'PTP endpoint', pop: 'PoP / NOC', other: 'Other' }[t] || t);
  }

  function renderSite(s) {
    const marker = L.marker([s.lat, s.lng], {
      draggable: true,
      icon: dotIcon(SITE_COLOR[s.type] || '#888', 16),
    });
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
      +   uispBadgeForSite(s)
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
    const marker = L.marker([c.lat, c.lng], {
      draggable: true,
      icon: dotIcon(STATUS_COLOR[c.status] || '#888', 11),
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
      +   '<div class="row" style="margin-top:8px;">'
      +     '<a class="btn btn-ghost btn-sm" href="/admin/client-edit.php?id=' + c.id + '">Open record</a>'
      +   '</div>'
      +   uispBadgeForClient(c)
      + '</div>';
  }

  /* ---------- UISP renderers ---------- */
  function renderUispSite(s) {
    if (s.lat == null || s.lng == null) return;
    if (linkedSiteByUispId.has(s.uisp_id)) return; // manual marker covers it

    const m = L.marker([s.lat, s.lng], { icon: diamondIcon('#80f', 18) });
    m.bindPopup(uispSitePopupHTML(s));
    m.addTo(uispSitesLayer);
  }

  function uispSitePopupHTML(s) {
    const opts = unlinkedManualSitesOptions();
    const linkForm = opts
      ? '<form data-map-form="link_site" class="map-popup" style="margin-top:8px;border-top:1px solid rgba(255,255,255,0.1);padding-top:8px;">'
        + '<input type="hidden" name="uisp_id" value="' + escapeHtml(s.uisp_id) + '">'
        + '<label>Adopt as manual site<select name="site_id" required>'
        +   '<option value="">— pick a manual site —</option>' + opts
        + '</select></label>'
        + '<button type="submit" class="btn btn-ghost btn-sm">Link</button>'
        + '</form>'
      : '';
    return '<div class="map-popup">'
      + '<strong>' + escapeHtml(s.name) + '</strong><br>'
      + '<small>UISP site</small>'
      + (s.address ? '<p style="margin:6px 0 0;">' + escapeHtml(s.address) + '</p>' : '')
      + (s.is_stale ? '<div style="margin-top:6px;">' + pillHTML('stale', 'unknown') + '</div>' : '')
      + '<div style="margin-top:6px;"><small>UISP id: <code>' + escapeHtml(s.uisp_id) + '</code></small></div>'
      + linkForm
      + '</div>';
  }

  function renderUispDevice(d) {
    let lat = d.lat, lng = d.lng;
    if (lat == null || lng == null) {
      const parent = uispSiteById.get(d.uisp_site_id);
      if (parent) { lat = parent.lat; lng = parent.lng; }
    }
    if (lat == null || lng == null) return;

    const color = UISP_STATUS_COLOR[d.status] || UISP_STATUS_COLOR.unknown;
    const m = L.marker([lat, lng], { icon: ringIcon(color, 11) });
    m.bindPopup(uispDevicePopupHTML(d));
    m.addTo(uispDevicesLayer);
  }

  function uispDevicePopupHTML(d) {
    return '<div class="map-popup">'
      + '<strong>' + escapeHtml(d.name) + '</strong><br>'
      + '<small>' + escapeHtml(d.type || 'device') + (d.role ? ' · ' + escapeHtml(d.role) : '') + '</small>'
      + '<div style="margin:6px 0;">' + pillHTML(d.status || 'unknown', d.status) + (d.is_stale ? ' ' + pillHTML('stale', 'unknown') : '') + '</div>'
      + (d.model        ? '<small>Model: ' + escapeHtml(d.model) + '</small><br>' : '')
      + (d.mac          ? '<small>MAC: '   + escapeHtml(d.mac)   + '</small><br>' : '')
      + (d.ip           ? '<small>IP: '    + escapeHtml(d.ip)    + '</small><br>' : '')
      + (d.signal_dbm != null ? '<small>Signal: ' + d.signal_dbm + ' dBm</small><br>' : '')
      + (d.last_seen_at ? '<small>Last seen: ' + escapeHtml(d.last_seen_at) + '</small>' : '')
      + '</div>';
  }

  function renderUispDataLink(l) {
    const a = uispDeviceById.get(l.from_device_uisp_id);
    const b = uispDeviceById.get(l.to_device_uisp_id);
    if (!a || !b) return;
    const aSite = a.uisp_site_id ? uispSiteById.get(a.uisp_site_id) : null;
    const bSite = b.uisp_site_id ? uispSiteById.get(b.uisp_site_id) : null;
    const fromLat = a.lat != null ? a.lat : (aSite ? aSite.lat : null);
    const fromLng = a.lng != null ? a.lng : (aSite ? aSite.lng : null);
    const toLat   = b.lat != null ? b.lat : (bSite ? bSite.lat : null);
    const toLng   = b.lng != null ? b.lng : (bSite ? bSite.lng : null);
    if (fromLat == null || fromLng == null || toLat == null || toLng == null) return;

    const ok  = /active|connected|up/i.test(l.status || '');
    const bad = /inactive|disconnected|offline|down/i.test(l.status || '');
    const color = ok ? '#0c8' : bad ? '#d44' : '#bb2';

    const line = L.polyline([[fromLat, fromLng], [toLat, toLng]], {
      color: color, weight: 2, opacity: 0.85, dashArray: '5 4',
    });
    line.bindPopup(
      '<div class="map-popup">'
      + '<strong>UISP link</strong><br>'
      + '<small>' + escapeHtml(a.name) + ' &harr; ' + escapeHtml(b.name) + '</small><br>'
      + (l.frequency ? '<small>' + escapeHtml(l.frequency) + (l.capacity_mbps ? ' · ' + l.capacity_mbps + ' Mbps' : '') + '</small><br>' : '')
      + '<div style="margin-top:6px;">' + pillHTML(l.status || 'unknown', ok ? 'online' : bad ? 'offline' : 'unknown') + '</div>'
      + '</div>'
    );
    line.addTo(uispLinksLayer);
  }

  function renderUispClient(c) {
    if (c.lat == null || c.lng == null) return;
    if (linkedClientByUispId.has(c.uisp_id)) return; // manual marker covers it

    const m = L.marker([c.lat, c.lng], { icon: ringIcon('#08e', 9) });
    m.bindPopup(uispClientPopupHTML(c));
    m.addTo(uispClientsLayer);
  }

  function uispClientPopupHTML(c) {
    const opts = unlinkedManualClientsOptions();
    const linkForm = opts
      ? '<form data-map-form="link_client" class="map-popup" style="margin-top:8px;border-top:1px solid rgba(255,255,255,0.1);padding-top:8px;">'
        + '<input type="hidden" name="uisp_id" value="' + escapeHtml(c.uisp_id) + '">'
        + '<label>Adopt as portal client<select name="user_id" required>'
        +   '<option value="">— pick a portal client —</option>' + opts
        + '</select></label>'
        + '<button type="submit" class="btn btn-ghost btn-sm">Link</button>'
        + '</form>'
      : '';
    const ok = /active|online/i.test(c.status || '');
    return '<div class="map-popup">'
      + '<strong>' + escapeHtml(c.name) + '</strong><br>'
      + (c.account_no   ? '<small>' + escapeHtml(c.account_no)   + '</small><br>' : '')
      + (c.email        ? '<small>' + escapeHtml(c.email)        + '</small><br>' : '')
      + (c.address_full ? '<small>' + escapeHtml(c.address_full) + '</small><br>' : '')
      + '<div style="margin-top:6px;">' + pillHTML(c.status || 'unknown', ok ? 'online' : 'unknown') + (c.is_stale ? ' ' + pillHTML('stale', 'unknown') : '') + '</div>'
      + '<div style="margin-top:6px;"><small>UISP id: <code>' + escapeHtml(c.uisp_id) + '</code></small></div>'
      + linkForm
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

  /* ---------- Sync UISP button ---------- */
  const uispSyncBtn = document.getElementById('uisp-sync-btn');
  const uispSyncStatusEl = document.getElementById('uisp-sync-status');
  if (uispSyncBtn) {
    uispSyncBtn.addEventListener('click', async () => {
      uispSyncBtn.disabled = true;
      if (uispSyncStatusEl) uispSyncStatusEl.textContent = 'Syncing UISP…';
      const r = await postAction('uisp_sync', {});
      if (r.ok) {
        if (uispSyncStatusEl) uispSyncStatusEl.textContent = 'Synced — reloading…';
        setTimeout(() => location.reload(), 800);
      } else {
        if (uispSyncStatusEl) uispSyncStatusEl.textContent = 'Sync failed';
        alert(r.error || (r.errors && r.errors.join(' | ')) || 'Sync failed');
        uispSyncBtn.disabled = false;
      }
    });
  }

  /* ---------- bootstrap ----------
     Order matters: index UISP and "linked" maps before rendering anything,
     so manual popups can show UISP status pills and UISP renderers can
     skip entries that already have a manual marker. */
  if (uispEnabled && boot.uisp) {
    (boot.uisp.sites   || []).forEach(s => uispSiteById.set(s.uisp_id, s));
    (boot.uisp.devices || []).forEach(d => uispDeviceById.set(d.uisp_id, d));
    (boot.uisp.clients || []).forEach(c => uispClientById.set(c.uisp_id, c));
  }
  boot.sites.forEach(s   => { if (s.uisp_id)        linkedSiteByUispId  .set(s.uisp_id, s); });
  boot.clients.forEach(c => { if (c.uisp_client_id) linkedClientByUispId.set(c.uisp_client_id, c); });

  boot.sites.forEach(renderSite);
  boot.site_links.forEach(renderLink);
  boot.clients.forEach(renderClient);

  if (uispEnabled && boot.uisp) {
    (boot.uisp.sites      || []).forEach(renderUispSite);
    (boot.uisp.devices    || []).forEach(renderUispDevice);
    (boot.uisp.data_links || []).forEach(renderUispDataLink);
    (boot.uisp.clients    || []).forEach(renderUispClient);
  }
})();
