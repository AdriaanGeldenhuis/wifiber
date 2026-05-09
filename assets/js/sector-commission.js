/* Sector AP commissioning poller — refreshes the page tiles every few
   seconds with the AP's current device telemetry, station count, and
   RF environment scan. Pauses while hidden. */
(function () {
  var cfg     = window.SC_CONFIG || {};
  var $       = function (id) { return document.getElementById(id); };
  var pending = false;

  function setStatus(klass, text, ms) {
    $('al-status-dot').className   = 'al-dot ' + (klass || '');
    $('al-status-text').textContent = text || '';
    $('al-status-ms').textContent   = ms != null ? (ms + ' ms') : '';
  }

  function fmtUptime(s) {
    if (s == null) return '—';
    if (s < 60)         return s + 's';
    if (s < 3600)       return Math.round(s / 60) + 'm';
    if (s < 86400)      return Math.round(s / 3600) + 'h';
    return Math.round(s / 86400) + 'd';
  }

  function paint(d) {
    var ok = (d.ap_status === 'online');
    var statusEl = $('sc-status');
    statusEl.textContent = ok ? '✓' : '✗';
    statusEl.className   = 'sc-tile-big ' + (ok ? 'sc-pill-ok' : 'sc-pill-bad');

    $('sc-station-count').textContent = d.station_count != null ? d.station_count : '—';
    $('sc-stations-count').textContent = d.station_count || 0;

    $('sc-freq').textContent  = d.frequency_mhz || '—';
    $('sc-width').textContent = d.channel_width || '—';
    $('sc-cpu').textContent   = d.cpu_pct != null ? Math.round(d.cpu_pct) : '—';
    $('sc-mem').textContent   = d.mem_pct != null ? Math.round(d.mem_pct) : '—';
    $('sc-uptime').textContent = fmtUptime(d.uptime_seconds);
    $('sc-tx').textContent    = d.tx_power_dbm != null ? d.tx_power_dbm : '—';

    var cfg = d.configured || {};
    $('sc-freq-cfg').textContent  = cfg.frequency_mhz != null ? cfg.frequency_mhz + ' MHz' : '—';
    $('sc-width-cfg').textContent = cfg.channel_width != null ? cfg.channel_width + ' MHz' : '—';

    /* Colour the freq tile red if AP is broadcasting on a different
       frequency than the sector record claims — that's a config drift
       worth flagging during commissioning. */
    var freqEl = $('sc-freq');
    if (d.frequency_mhz != null && cfg.frequency_mhz != null && d.frequency_mhz !== cfg.frequency_mhz) {
      freqEl.className = 'sc-tile-big sc-pill-bad';
    } else {
      freqEl.className = 'sc-tile-big';
    }

    var sl = $('sc-stations-list');
    sl.innerHTML = '';
    if (!d.stations || !d.stations.length) {
      sl.innerHTML = '<li class="muted">no associated stations</li>';
    } else {
      d.stations.forEach(function (s) {
        var li = document.createElement('li');
        var sigCls = s.signal == null ? '' : (s.signal > -65 ? 'sc-pill-ok' : (s.signal > -75 ? 'sc-pill-warn' : 'sc-pill-bad'));
        li.innerHTML = '<span class="sc-mac">' + (s.mac || '?') + '</span>'
                     + '<span class="' + sigCls + '">' + (s.signal != null ? s.signal + ' dBm' : '— dBm') + '</span>'
                     + '<span>SNR ' + (s.snr != null ? s.snr : '—') + '</span>'
                     + '<span class="muted">' + (s.tx != null ? Math.round(s.tx) : '—') + '/'
                     + (s.rx != null ? Math.round(s.rx) : '—') + ' Mbps</span>';
        sl.appendChild(li);
      });
    }

    var rl = $('sc-rf-list');
    rl.innerHTML = '';
    if (!d.rf_top || !d.rf_top.length) {
      rl.innerHTML = '<li class="muted">no scan data — adapter may not have rf_env support</li>';
    } else {
      d.rf_top.forEach(function (r) {
        var li = document.createElement('li');
        li.innerHTML = '<span class="sc-mac">' + (r.ssid || '<no SSID>') + '</span>'
                     + '<span>' + (r.freq_mhz != null ? r.freq_mhz + ' MHz' : '—') + '</span>'
                     + '<span class="' + (r.rssi_dbm > -60 ? 'sc-pill-warn' : '') + '">'
                     + (r.rssi_dbm != null ? r.rssi_dbm + ' dBm' : '—') + '</span>';
        rl.appendChild(li);
      });
    }

    setStatus('al-dot-ok', 'live ' + new Date().toLocaleTimeString(), d.ms);
  }

  function poll() {
    if (pending) return;
    if (document.visibilityState === 'hidden') return;
    pending = true;
    setStatus('al-dot-busy', 'polling AP…');
    fetch(cfg.pollUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        pending = false;
        if (!j || !j.ok) {
          setStatus('al-dot-err', (j && j.error) || 'poll failed', j && j.ms);
          return;
        }
        paint(j);
      })
      .catch(function () {
        pending = false;
        setStatus('al-dot-err', 'network error');
      });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'hidden' && !pending) poll();
  });

  function loop() {
    poll();
    setTimeout(loop, cfg.interval || 3500);
  }
  loop();
})();
