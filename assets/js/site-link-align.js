/* PTP backbone alignment — same polling pattern as align.js but
   updates two columns (near + far) instead of one. */
(function () {
  var cfg = window.AL_CONFIG || {};
  var $   = function (id) { return document.getElementById(id); };

  var peak    = { near: null, far: null };
  var beepOn  = false;
  var audio   = null;
  var pending = false;

  function setStatus(klass, text, ms) {
    $('al-status-dot').className   = 'al-dot ' + (klass || '');
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
    g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + (durSec || 0.06));
    o.start();
    o.stop(audio.currentTime + (durSec || 0.06) + 0.02);
  }

  function paintSide(side, data) {
    var s   = (data && typeof data.signal_dbm === 'number') ? data.signal_dbm : null;
    var snr = (data && typeof data.snr_db     === 'number') ? data.snr_db     : null;
    if (s == null) {
      $('al-' + side + '-sig').textContent  = '—';
      $('al-' + side + '-fill').style.width = '0%';
    } else {
      $('al-' + side + '-sig').textContent = s;
      var pct = Math.max(0, Math.min(100, (s + 100) * 100 / 70));
      var col = s > -55 ? 'var(--good)' : (s > -70 ? 'var(--warn)' : 'var(--bad)');
      $('al-' + side + '-fill').style.width      = pct + '%';
      $('al-' + side + '-fill').style.background = col;
      if (peak[side] === null || s > peak[side]) {
        peak[side] = s;
        $('al-' + side + '-peak').textContent = s;
      }
    }
    $('al-' + side + '-snr').textContent = (snr == null) ? '—' : snr;
  }

  function paint(d) {
    if (!d.station_found) {
      setStatus('al-dot-warn', 'AP polled — far-end radio not associated yet', d.ms);
      paintSide('near', null);
      paintSide('far',  null);
      return;
    }
    paintSide('near', d.near || {});
    paintSide('far',  d.far  || {});
    $('al-ccq').textContent   = d.ccq_pct       != null ? Math.round(d.ccq_pct) + ' %' : '—';
    $('al-freq').textContent  = d.freq_mhz      != null ? d.freq_mhz : '—';
    $('al-width').textContent = d.channel_width != null ? d.channel_width : '—';
    $('al-mac').textContent   = d.station_mac || '—';

    // One pitched beep per cycle, tied to the WORSE of the two sides
    // — that way the tech is incentivised to peak the bad direction.
    var nearSig = d.near && d.near.signal_dbm;
    var farSig  = d.far  && d.far.signal_dbm;
    var worst = (nearSig != null && farSig != null) ? Math.min(nearSig, farSig)
              : (nearSig != null ? nearSig : farSig);
    if (worst != null) tone(440 + (worst + 100) * 18, 0.06);

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
