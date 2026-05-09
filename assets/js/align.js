/* Antenna alignment meter — polls /admin/align.php?action=poll on a
   loop, paints big numbers, plays a pitched beep on each sample so the
   installer can tell the dish is improving without staring at the
   screen.

   The vendor adapter call is slow (5–15 s on AirOS), so we throttle
   strictly: never fire a new request while one is in flight, and pause
   entirely when the tab is hidden. */
(function () {
  var cfg = window.AL_CONFIG || {};
  var $   = function (id) { return document.getElementById(id); };

  var peak    = { signal: null, snr: null };
  var beepOn  = false;
  var audio   = null;
  var pending = false;
  var stopped = false;

  function setStatus(klass, text, ms) {
    $('al-status-dot').className  = 'al-dot ' + (klass || '');
    $('al-status-text').textContent = text || '';
    $('al-status-ms').textContent   = ms != null ? (ms + ' ms') : '';
  }

  function tone(freqHz, durSec) {
    if (!beepOn || !audio) return;
    var o = audio.createOscillator();
    var g = audio.createGain();
    o.connect(g); g.connect(audio.destination);
    o.type = 'sine';
    o.frequency.value = Math.max(120, Math.min(2200, freqHz));
    g.gain.setValueAtTime(0.0001, audio.currentTime);
    g.gain.exponentialRampToValueAtTime(0.18, audio.currentTime + 0.005);
    g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + (durSec || 0.08));
    o.start();
    o.stop(audio.currentTime + (durSec || 0.08) + 0.02);
  }

  function paintHit(d) {
    // Signal in dBm: clamp -100 .. -30 → 0%..100% bar
    var s = (typeof d.signal_dbm === 'number') ? d.signal_dbm : null;
    if (s != null) {
      $('al-signal').textContent = s;
      var pct = Math.max(0, Math.min(100, (s + 100) * 100 / 70));
      var col = s > -60 ? 'var(--good)' : (s > -75 ? 'var(--warn)' : 'var(--bad)');
      $('al-signal-fill').style.width      = pct + '%';
      $('al-signal-fill').style.background = col;
      if (peak.signal === null || s > peak.signal) {
        peak.signal = s;
        $('al-signal-peak').textContent = s;
      }
      // Pitched beep — stronger signal → higher pitch.
      tone(440 + (s + 100) * 18, 0.07);
    } else {
      // Leave empty — CSS .al-big:empty::before paints a small muted
      // placeholder. Avoids the 88px em-dash looking like a glitch.
      $('al-signal').textContent = '';
    }

    var n = (typeof d.snr_db === 'number') ? d.snr_db : null;
    if (n != null) {
      $('al-snr').textContent = n;
      var pct2 = Math.max(0, Math.min(100, n * 100 / 45));
      var col2 = n > 25 ? 'var(--good)' : (n > 15 ? 'var(--warn)' : 'var(--bad)');
      $('al-snr-fill').style.width      = pct2 + '%';
      $('al-snr-fill').style.background = col2;
      if (peak.snr === null || n > peak.snr) {
        peak.snr = n;
        $('al-snr-peak').textContent = n;
      }
    } else {
      $('al-snr').textContent = '';
    }

    $('al-ccq').textContent  = d.ccq_pct  != null ? Math.round(d.ccq_pct) + ' %' : '—';
    $('al-tx').textContent   = d.tx_mbps  != null ? Math.round(d.tx_mbps) : '—';
    $('al-rx').textContent   = d.rx_mbps  != null ? Math.round(d.rx_mbps) : '—';
    $('al-freq').textContent = d.freq_mhz != null ? d.freq_mhz : '—';
    $('al-mac').textContent  = d.station_mac || '—';
  }

  function paintStations(d) {
    var ul    = $('al-stations');
    var rows  = d.all_stations || [];
    var tgt   = (d.targets || []).map(function (m) { return (m || '').toUpperCase(); });
    $('al-stations-count').textContent = rows.length;
    ul.innerHTML = '';
    rows.forEach(function (r) {
      var li = document.createElement('li');
      var mac = (r.mac || '').toUpperCase();
      var sig = (r.signal != null) ? r.signal + ' dBm' : '— dBm';
      var snr = (r.snr    != null) ? r.snr + ' dB'    : '— dB';
      li.textContent = mac + ' · ' + sig + ' · SNR ' + snr;
      if (tgt.indexOf(mac.replace(/[^A-F0-9]/g, '')) >= 0) li.classList.add('is-target');
      ul.appendChild(li);
    });
  }

  function poll() {
    if (pending || stopped) return;
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
        paintStations(j);
        if (!j.station_found) {
          setStatus('al-dot-warn',
            j.targets && j.targets.length
              ? 'no station with target MAC on AP yet'
              : 'no CPE MAC saved — pick the strongest one below',
            j.ms);
          return;
        }
        paintHit(j);
        var note = j.auto_pick ? '· auto-picked strongest' : '';
        setStatus('al-dot-ok', 'live ' + new Date().toLocaleTimeString() + ' ' + note, j.ms);
      })
      .catch(function (e) {
        pending = false;
        setStatus('al-dot-err', 'network error');
      });
  }

  // Tap once to enable sound; required by mobile browsers (AudioContext
  // can only resume from a user gesture).
  $('al-beep-toggle').addEventListener('click', function () {
    if (!audio) {
      try { audio = new (window.AudioContext || window.webkitAudioContext)(); }
      catch (e) { audio = null; }
    }
    if (audio && audio.state === 'suspended') audio.resume();
    beepOn = !beepOn;
    this.textContent = beepOn ? '🔊' : '🔇';
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'hidden' && !pending) poll();
  });

  function loop() {
    poll();
    setTimeout(loop, cfg.interval || 2500);
  }
  loop();
})();
