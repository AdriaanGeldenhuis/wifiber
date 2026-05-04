/**
 * Reusable address picker: Nominatim autocomplete + Leaflet pin.
 *
 * Wires up any element marked with `data-addr-picker="<endpoint>"` where
 * <endpoint> is the URL to POST/GET ?suggest= and ?reverse_lat=&reverse_lng=
 * to. Required descendants (looked up by data-* attribute):
 *
 *   data-addr-input        the visible address text input
 *   data-addr-suggestions  empty <div> for the dropdown
 *   data-addr-lat          (input) latitude
 *   data-addr-lng          (input) longitude
 *   data-addr-map          (div)   leaflet map container
 *   data-addr-hint         (optional) status line
 *   data-addr-locate       (button, optional) "use my location"
 *   data-addr-reverse      (button, optional) "fill address from pin"
 *   data-addr-clear        (button, optional) reset everything
 *   data-addr-result       (div, optional) coverage-check banner
 *   data-addr-coverage     (truthy) run live coverage_check on input
 */
(function () {
  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function whenLeaflet(fn, tries) {
    tries = tries || 0;
    if (typeof L !== 'undefined') return fn();
    if (tries > 100) return; // ~5s — give up
    setTimeout(function () { whenLeaflet(fn, tries + 1); }, 50);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function initOne(root) {
    var endpoint = root.getAttribute('data-addr-picker') || '';
    if (!endpoint) return;

    var addrInput  = root.querySelector('[data-addr-input]');
    var sugBox     = root.querySelector('[data-addr-suggestions]');
    var latInput   = root.querySelector('[data-addr-lat]');
    var lngInput   = root.querySelector('[data-addr-lng]');
    var mapEl      = root.querySelector('[data-addr-map]');
    var hint       = root.querySelector('[data-addr-hint]');
    var locateBtn  = root.querySelector('[data-addr-locate]');
    var reverseBtn = root.querySelector('[data-addr-reverse]');
    var clearBtn   = root.querySelector('[data-addr-clear]');
    var resultBox  = root.querySelector('[data-addr-result]');
    var doCoverage = root.hasAttribute('data-addr-coverage');

    if (!addrInput || !sugBox || !mapEl) return;

    var DEFAULT_CENTER = [-26.7100, 27.8300]; // Vaal Triangle
    var startLat = latInput ? parseFloat(latInput.value) : NaN;
    var startLng = lngInput ? parseFloat(lngInput.value) : NaN;
    var hasStart = isFinite(startLat) && isFinite(startLng);

    var map = L.map(mapEl).setView(
      hasStart ? [startLat, startLng] : DEFAULT_CENTER,
      hasStart ? 16 : 11
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    setTimeout(function () { map.invalidateSize(); }, 100);

    var marker = null;
    function setCoords(lat, lng, opts) {
      opts = opts || {};
      var ll = [lat, lng];
      if (marker) {
        marker.setLatLng(ll);
      } else {
        marker = L.marker(ll, { draggable: true }).addTo(map);
        marker.on('drag dragend', function () {
          var p = marker.getLatLng();
          if (latInput) latInput.value = p.lat.toFixed(7);
          if (lngInput) lngInput.value = p.lng.toFixed(7);
        });
      }
      if (latInput) latInput.value = (+lat).toFixed(7);
      if (lngInput) lngInput.value = (+lng).toFixed(7);
      if (opts.recenter) map.setView(ll, opts.zoom || Math.max(map.getZoom(), 16));
    }
    if (hasStart) setCoords(startLat, startLng);

    map.on('click', function (e) {
      setCoords(e.latlng.lat, e.latlng.lng);
      if (hint) hint.textContent = 'Pin placed. Click "Fill address from pin" to look up the street.';
    });

    function onCoordsTyped() {
      if (!latInput || !lngInput) return;
      var la = parseFloat(latInput.value);
      var ln = parseFloat(lngInput.value);
      if (isFinite(la) && isFinite(ln)) setCoords(la, ln, { recenter: true });
    }
    if (latInput) latInput.addEventListener('change', onCoordsTyped);
    if (lngInput) lngInput.addEventListener('change', onCoordsTyped);

    /* ---------- Coverage check ---------- */
    var checkAbort = null, checkTimer = null;
    function showResult(payload, address) {
      if (!resultBox) return;
      if (!payload) { resultBox.hidden = true; return; }
      resultBox.hidden = false;
      resultBox.classList.remove('is-yes', 'is-no');
      if (payload.matched && payload.area) {
        resultBox.classList.add('is-yes');
        var matched = payload.matched_term && payload.matched_term !== payload.area.name
          ? ' (matched on <em>' + escapeHtml(payload.matched_term) + '</em>)'
          : '';
        resultBox.innerHTML =
          '<strong>In coverage</strong>' +
          'This address falls inside <em>' + escapeHtml(payload.area.name) + '</em>' + matched + '.';
      } else {
        resultBox.classList.add('is-no');
        resultBox.innerHTML =
          '<strong>Not in coverage</strong>' +
          'No coverage area matches "' + escapeHtml(address) + '". Add a matching alias or suburb on the Areas tab if it should match.';
      }
    }
    function runCoverageCheck(address) {
      if (!doCoverage || !resultBox) return;
      clearTimeout(checkTimer);
      if (checkAbort) checkAbort.abort();
      if (!address || address.trim().length < 2) { resultBox.hidden = true; return; }
      checkTimer = setTimeout(function () {
        checkAbort = new AbortController();
        fetch(endpoint + (endpoint.indexOf('?') < 0 ? '?' : '&') + 'coverage_check=' + encodeURIComponent(address), {
          credentials: 'same-origin', signal: checkAbort.signal
        })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j && j.ok) showResult(j.result, address); })
          .catch(function () {});
      }, 250);
    }

    /* ---------- Address autocomplete ---------- */
    var sugAbort = null, sugTimer = null, sugResults = [], sugIndex = -1;
    function clearSug() {
      sugBox.innerHTML = ''; sugBox.hidden = true;
      sugResults = []; sugIndex = -1;
    }
    function renderSug() {
      sugBox.innerHTML = sugResults.map(function (r, i) {
        return '<div class="addr-suggestion' + (i === sugIndex ? ' is-active' : '') +
          '" data-i="' + i + '">' + escapeHtml(r.display_name) + '</div>';
      }).join('');
      sugBox.hidden = sugResults.length === 0;
    }
    function pickSuggestion(i) {
      var r = sugResults[i];
      if (!r) return;
      addrInput.value = r.display_name;
      setCoords(r.lat, r.lng, { recenter: true });
      if (hint) hint.textContent = 'Address picked. Drag the pin if needed.';
      clearSug();
      runCoverageCheck(r.display_name);
    }
    addrInput.addEventListener('input', function () {
      clearTimeout(sugTimer);
      if (sugAbort) sugAbort.abort();
      var q = addrInput.value.trim();
      runCoverageCheck(q);
      if (q.length < 3) { clearSug(); return; }
      sugTimer = setTimeout(function () {
        sugAbort = new AbortController();
        fetch(endpoint + (endpoint.indexOf('?') < 0 ? '?' : '&') + 'suggest=' + encodeURIComponent(q), {
          credentials: 'same-origin', signal: sugAbort.signal
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.ok) return clearSug();
            sugResults = j.results || [];
            sugIndex = -1;
            renderSug();
          })
          .catch(function () {});
      }, 350);
    });
    addrInput.addEventListener('keydown', function (e) {
      if (sugBox.hidden || !sugResults.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        sugIndex = (sugIndex + 1) % sugResults.length;
        renderSug();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        sugIndex = (sugIndex - 1 + sugResults.length) % sugResults.length;
        renderSug();
      } else if (e.key === 'Enter' && sugIndex >= 0) {
        e.preventDefault();
        pickSuggestion(sugIndex);
      } else if (e.key === 'Escape') {
        clearSug();
      }
    });
    addrInput.addEventListener('blur', function () { setTimeout(clearSug, 200); });
    sugBox.addEventListener('mousedown', function (e) {
      var item = e.target.closest('.addr-suggestion');
      if (item) pickSuggestion(+item.dataset.i);
    });

    /* ---------- Use my location ---------- */
    if (locateBtn) {
      locateBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
          if (hint) hint.textContent = 'Geolocation not supported in this browser.';
          return;
        }
        if (hint) hint.textContent = 'Getting your location…';
        navigator.geolocation.getCurrentPosition(
          function (pos) {
            setCoords(pos.coords.latitude, pos.coords.longitude, { recenter: true, zoom: 18 });
            if (hint) hint.textContent = 'Located. Click "Fill address from pin" to look up the street.';
          },
          function (err) { if (hint) hint.textContent = 'Could not get location: ' + err.message; },
          { enableHighAccuracy: true, timeout: 10000 }
        );
      });
    }

    /* ---------- Reverse geocode (pin -> address) ---------- */
    if (reverseBtn) {
      reverseBtn.addEventListener('click', function () {
        if (!marker) { if (hint) hint.textContent = 'Drop a pin on the map first.'; return; }
        var ll = marker.getLatLng();
        if (hint) hint.textContent = 'Looking up address…';
        fetch(endpoint + (endpoint.indexOf('?') < 0 ? '?' : '&') + 'reverse_lat=' + ll.lat + '&reverse_lng=' + ll.lng, {
          credentials: 'same-origin'
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j && j.ok && j.display_name) {
              addrInput.value = j.display_name;
              if (hint) hint.textContent = 'Address filled from pin location.';
              runCoverageCheck(j.display_name);
            } else if (hint) {
              hint.textContent = 'No address found for that pin.';
            }
          })
          .catch(function () { if (hint) hint.textContent = 'Address lookup failed.'; });
      });
    }

    /* ---------- Clear ---------- */
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        addrInput.value = '';
        if (latInput) latInput.value = '';
        if (lngInput) lngInput.value = '';
        if (marker) { map.removeLayer(marker); marker = null; }
        map.setView(DEFAULT_CENTER, 11);
        if (resultBox) resultBox.hidden = true;
        clearSug();
        if (hint) hint.textContent = 'Click anywhere on the map to drop a pin, drag it to fine-tune, or pick a suggestion above.';
      });
    }
  }

  ready(function () {
    var roots = document.querySelectorAll('[data-addr-picker]');
    if (!roots.length) return;
    whenLeaflet(function () { roots.forEach(initOne); });
  });
})();
