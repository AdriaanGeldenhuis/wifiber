/**
 * Dashboard liveness — ticks the "Xs ago" suffix on every freshness
 * badge once a second and auto-reloads the page on a configurable
 * cadence so that sparklines, sample tables and signal numbers
 * refresh without the operator clicking anything.
 *
 * Loaded on every admin page (defer'd from portal-footer.php). It's a
 * no-op when no data-poll-fresh-at elements are on the page.
 *
 * Markup contract:
 *
 *   <span class="poll-badge poll-badge-live"
 *         data-poll-fresh-at="2026-05-08 14:23:01"
 *         data-poll-stale-after="180"
 *         data-poll-dead-after="900">
 *     <span class="poll-dot"></span>
 *     <span class="poll-label">Live</span>
 *     <span class="poll-age">· 12s ago</span>
 *   </span>
 *
 * The PHP helper poll_badge_html() emits exactly this shape — see
 * /auth/poll_status.php.
 *
 * To enable auto-reload for a page, set on <body>:
 *
 *   <body data-auto-refresh-seconds="60">
 *
 * The reloader pauses while the tab is hidden so we don't burn requests
 * for nobody.
 */

(function () {
  'use strict';

  var COLOURS = {
    live:  { bg: '#4ade80', fg: '#001218', label: 'Live' },
    stale: { bg: '#e8a814', fg: '#001218', label: 'Stale' },
    dead:  { bg: '#ff5470', fg: '#001218', label: 'Polling stopped' },
    never: { bg: '#6b7480', fg: '#f4f6f8', label: 'No data yet' },
  };

  function ageSeconds(iso) {
    if (!iso) return null;
    // Parse "YYYY-MM-DD HH:MM:SS" as local server time (best effort —
    // the server-rendered timestamp is in the DB's session timezone).
    var t = Date.parse(iso.replace(' ', 'T'));
    if (!isFinite(t)) return null;
    return Math.max(0, Math.floor((Date.now() - t) / 1000));
  }

  function humanAge(s) {
    if (s === null || s === undefined) return 'never';
    if (s < 60)    return s + 's ago';
    if (s < 3600)  return Math.floor(s / 60)    + 'm ago';
    if (s < 86400) return Math.floor(s / 3600)  + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  }

  function classify(s, staleAfter, deadAfter) {
    if (s === null || s === undefined) return 'never';
    if (s <= staleAfter) return 'live';
    if (s <= deadAfter)  return 'stale';
    return 'dead';
  }

  function tick() {
    var nodes = document.querySelectorAll('[data-poll-fresh-at]');
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      var iso = n.getAttribute('data-poll-fresh-at') || '';
      var staleAfter = parseInt(n.getAttribute('data-poll-stale-after') || '180', 10);
      var deadAfter  = parseInt(n.getAttribute('data-poll-dead-after')  || '900', 10);
      var s = iso ? ageSeconds(iso) : null;
      var state = classify(s, staleAfter, deadAfter);
      var c = COLOURS[state] || COLOURS.never;

      // Update the suffix text without touching the dot or label DOM.
      var ageEl = n.querySelector('.poll-age');
      if (ageEl) ageEl.textContent = '· ' + humanAge(s);

      // Update colour + label only if the state actually changed —
      // avoids style thrashing every second.
      var prev = n.getAttribute('data-poll-state') || '';
      if (prev !== state) {
        n.setAttribute('data-poll-state', state);
        n.style.background = c.bg;
        n.style.color      = c.fg;
        var labelEl = n.querySelector('.poll-label');
        if (labelEl) labelEl.textContent = c.label;
        // Replace the badge's state class for any custom CSS hooks.
        n.className = n.className.replace(/poll-badge-(live|stale|dead|never)/g, '').trim();
        n.classList.add('poll-badge', 'poll-badge-' + state);
      }
    }
  }

  function startTicker() {
    tick();
    setInterval(function () {
      if (document.visibilityState !== 'hidden') tick();
    }, 1000);
  }

  function startAutoRefresh() {
    var body = document.body;
    if (!body) return;
    var n = parseInt(body.getAttribute('data-auto-refresh-seconds') || '0', 10);
    if (!n || n < 5) return;
    // Reload only when the tab is visible AND the operator isn't
    // typing into a form. Otherwise we'd wipe whatever they were
    // entering. The deadline keeps moving as long as a form is
    // focused or the tab is hidden — refresh fires on the next idle
    // tick after both conditions clear.
    var deadline = Date.now() + n * 1000;
    var isUserBusy = function () {
      var el = document.activeElement;
      if (!el) return false;
      var tag = (el.tagName || '').toUpperCase();
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
      if (el.isContentEditable) return true;
      // Open <details>/<dialog> usually means a UI panel is in use.
      if (document.querySelector('details[open]:focus-within, dialog[open]')) return true;
      return false;
    };
    var refresh = function () {
      var u = new URL(window.location.href);
      u.searchParams.set('_t', String(Date.now()));
      window.location.replace(u.toString());
    };
    setInterval(function () {
      if (document.visibilityState === 'hidden') return;
      if (isUserBusy()) {
        // Push the deadline 10s into the future so the operator gets
        // a quiet moment after they finish typing.
        deadline = Math.max(deadline, Date.now() + 10000);
        return;
      }
      if (Date.now() >= deadline) refresh();
    }, 1000);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState !== 'hidden' && !isUserBusy() && Date.now() >= deadline) refresh();
    });
  }

  /**
   * Inline "Poll now" button. Wired by markup like
   *   <button data-poll-device-now="42" data-poll-device-name="Foo-AP">Poll now</button>
   * The button posts to /admin/devices.php?action=poll_wireless_now in
   * AJAX mode (the handler is the same one /admin/devices.php uses for
   * its inline form). On success it toasts and triggers a soft reload
   * 5s later so freshly-collected samples land in the sparklines.
   */
  function startPollNowButtons() {
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('[data-poll-device-now]');
      if (!btn) return;
      e.preventDefault();
      var id = btn.getAttribute('data-poll-device-now');
      var name = btn.getAttribute('data-poll-device-name') || ('device #' + id);
      var token = (document.querySelector('meta[name="csrf-token"]') || {}).content;
      btn.disabled = true;
      var orig = btn.textContent;
      btn.textContent = 'Polling…';
      if (window.toast) window.toast('Polling ' + name + '… (synchronous, ~5–15s)', 'info', 3500);
      var fd = new FormData();
      fd.append('action',  'poll_wireless_now');
      fd.append('id',      id);
      fd.append('ajax',    '1');
      fd.append('_csrf',   token || '');
      try {
        var res = await fetch('/admin/devices.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        var j   = await res.json().catch(function () { return { ok: false, message: 'Bad response' }; });
        btn.disabled = false;
        btn.textContent = orig;
        if (window.toast) {
          window.toast(j.message || (j.ok ? 'Poll triggered' : 'Poll failed'),
                       j.ok ? 'success' : 'error', 6000);
        }
        if (j.ok) {
          setTimeout(function () { window.location.reload(); }, 5000);
        }
      } catch (err) {
        btn.disabled = false;
        btn.textContent = orig;
        if (window.toast) window.toast('Network error', 'error');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      startTicker();
      startAutoRefresh();
      startPollNowButtons();
    });
  } else {
    startTicker();
    startAutoRefresh();
    startPollNowButtons();
  }
})();
