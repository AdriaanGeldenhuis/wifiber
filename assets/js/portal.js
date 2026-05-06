/* Portal-side enhancers. Loaded on every /admin and /account page so the
 * Content-Security-Policy can stay strict (script-src 'self' — no inline
 * handlers anywhere). All hooks are declarative via data-* attributes.
 *
 * Hooks provided:
 *   <form data-confirm="msg">               — confirm before submit
 *   <form>                                  — auto-disable submit + spinner
 *   <form data-no-loading>                  — opt out of the spinner
 *   <select data-auto-submit>               — submit parent form on change
 *   <input data-select-all>                 — .select() on click
 *   <button data-copy="text"> / [data-copy-target="#id"]
 *                                           — copy to clipboard, flip "✓"
 *   <table data-bulk="#bulkbarid">          — wires header + row checkboxes
 *                                              to a sticky .bulk-bar
 *   window.toast(msg, type='info', ms=3500) — append a toast notification
 */
(function () {
  'use strict';

  /* ---------- confirm ---------- */
  document.addEventListener('submit', function (e) {
    var msg = e.target && e.target.dataset && e.target.dataset.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
  }, true);

  /* ---------- submit loading state ----------
     Disables the submit button and paints a spinner on it during the
     request so people don't double-click on slow networks. Excludes
     forms tagged with data-no-loading and forms whose handler called
     preventDefault() (e.g. AJAX-only forms manage their own state). */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented) return;
    if (!form || form.tagName !== 'FORM') return;
    if ('noLoading' in form.dataset) return;
    var btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (!btn || btn.disabled) return;
    btn.classList.add('is-loading');
    btn.disabled = true;
    // Re-enable after a generous timeout so a navigation failure doesn't
    // leave the button permanently dead.
    setTimeout(function () {
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }, 12000);
  });

  /* ---------- auto-submit selects ---------- */
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.dataset && 'autoSubmit' in t.dataset && t.form) t.form.submit();
  }, true);

  /* ---------- select-all on click ---------- */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.dataset && 'selectAll' in t.dataset && typeof t.select === 'function') {
      t.select();
    }
  }, true);

  /* ---------- toast ---------- */
  function ensureStack() {
    var stack = document.querySelector('.toast-stack');
    if (stack) return stack;
    stack = document.createElement('div');
    stack.className = 'toast-stack';
    document.body.appendChild(stack);
    return stack;
  }
  window.toast = function (msg, type, ms) {
    var stack = ensureStack();
    var el = document.createElement('div');
    el.className = 'toast' + (type ? ' toast-' + type : '');
    el.textContent = String(msg || '');
    stack.appendChild(el);
    var dismiss = function () {
      el.classList.add('is-leaving');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 250);
    };
    setTimeout(dismiss, typeof ms === 'number' ? ms : 3500);
    el.addEventListener('click', dismiss);
  };

  /* ---------- copy-to-clipboard ----------
     Two ways to provide what to copy:
       <button data-copy="literal text"> ... </button>
       <button data-copy-target="#someInputOrCode"> ... </button>  */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy], [data-copy-target]');
    if (!btn) return;
    e.preventDefault();
    var text = btn.dataset.copy;
    if (!text && btn.dataset.copyTarget) {
      var src = document.querySelector(btn.dataset.copyTarget);
      if (src) text = ('value' in src) ? src.value : src.textContent;
    }
    if (text == null) return;
    var done = function () {
      btn.classList.add('is-copied');
      setTimeout(function () { btn.classList.remove('is-copied'); }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(String(text)).then(done, function () {
        legacyCopy(String(text)); done();
      });
    } else {
      legacyCopy(String(text)); done();
    }
  });
  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  /* ---------- bulk select / bulk-bar ----------
     Markup:
       <div class="bulk-bar" id="invoicesBulk" data-bulk-action="/admin/invoices.php">
         <span class="bulk-count">0 selected</span>
         <div class="bulk-actions">
           <button data-bulk="mark_paid">Mark paid</button>
           <button data-bulk="delete" class="btn-danger">Delete</button>
         </div>
       </div>
       <table class="data-table" data-bulk="#invoicesBulk">
         <thead><tr><th class="col-bulk"><input type="checkbox" class="row-check-all"></th>...</tr></thead>
         <tbody>
           <tr><td class="col-bulk"><input type="checkbox" class="row-check" value="123"></td>...</tr>
         </tbody>
       </table>

     Each [data-bulk] button on the bar fires a POST to data-bulk-action
     with action=<button.dataset.bulk>, ids=<csv>, _csrf=<token>. The
     CSRF token is read from <meta name="csrf-token"> on the page. */
  function wireBulkTable(table) {
    var bar = document.querySelector(table.dataset.bulk);
    if (!bar) return;
    var allBox  = table.querySelector('.row-check-all');
    var rowBoxes = function () { return table.querySelectorAll('.row-check'); };
    var sync = function () {
      var checked = [];
      rowBoxes().forEach(function (b) {
        if (b.checked) {
          checked.push(b.value);
          b.closest('tr').classList.add('is-selected');
        } else {
          b.closest('tr').classList.remove('is-selected');
        }
      });
      bar.classList.toggle('is-visible', checked.length > 0);
      var cnt = bar.querySelector('.bulk-count');
      if (cnt) cnt.textContent = checked.length + ' selected';
      if (allBox) {
        var total = rowBoxes().length;
        allBox.checked       = total > 0 && checked.length === total;
        allBox.indeterminate = checked.length > 0 && checked.length < total;
      }
      bar.dataset.selectedIds = checked.join(',');
    };
    if (allBox) {
      allBox.addEventListener('change', function () {
        rowBoxes().forEach(function (b) { b.checked = allBox.checked; });
        sync();
      });
    }
    table.addEventListener('change', function (e) {
      if (e.target.classList.contains('row-check')) sync();
    });
    bar.querySelectorAll('[data-bulk]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var ids = (bar.dataset.selectedIds || '').split(',').filter(Boolean);
        if (!ids.length) return;
        var confirmMsg = btn.dataset.confirm;
        if (confirmMsg && !window.confirm(confirmMsg.replace('{n}', ids.length))) return;
        var fd = new FormData();
        fd.append('action', btn.dataset.bulk);
        fd.append('ids', ids.join(','));
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.append('_csrf', meta.content);
        var endpoint = bar.dataset.bulkAction || location.pathname;
        btn.disabled = true;
        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
          .then(function (j) {
            btn.disabled = false;
            if (j && j.ok) {
              window.toast(j.message || (ids.length + ' updated'), 'success');
              setTimeout(function () { location.reload(); }, 600);
            } else {
              window.toast((j && j.error) || 'Bulk action failed', 'error');
            }
          })
          .catch(function () {
            btn.disabled = false;
            window.toast('Network error', 'error');
          });
      });
    });
    sync();
  }
  document.querySelectorAll('table[data-bulk]').forEach(wireBulkTable);

  /* ---------- mobile sidebar drawer ----------
     Below the CSS breakpoint the sidebar slides in from the left on
     hamburger tap. Closes on backdrop tap, ESC, or any nav-item click
     so the user lands on the new page without the drawer covering
     half of it. */
  function setSideOpen(on) {
    document.body.classList.toggle('is-side-open', !!on);
    var btn = document.querySelector('.portal-toggle');
    if (btn) {
      btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      btn.setAttribute('aria-label', on ? 'Close navigation' : 'Open navigation');
    }
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-side-toggle]')) {
      e.preventDefault();
      setSideOpen(!document.body.classList.contains('is-side-open'));
      return;
    }
    if (!document.body.classList.contains('is-side-open')) return;
    if (e.target.closest('[data-side-close]')) { setSideOpen(false); return; }
    if (e.target.closest('.portal-nav-item'))  { setSideOpen(false); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('is-side-open')) {
      setSideOpen(false);
    }
  });
  /* If the viewport is resized back to desktop while the drawer is
     open (e.g. rotating a tablet from portrait to landscape), the
     overlay state isn't meaningful any more — drop it. */
  window.addEventListener('resize', function () {
    if (window.innerWidth > 960 && document.body.classList.contains('is-side-open')) {
      setSideOpen(false);
    }
  });
})();
