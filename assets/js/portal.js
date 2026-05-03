/* Portal-side enhancers. Loaded on every /admin and /account page so the
 * Content-Security-Policy can stay strict (script-src 'self' — no inline
 * handlers anywhere). All hooks are declarative via data-* attributes. */
(function () {
  'use strict';

  // <form data-confirm="Are you sure?"> — block submit if the user cancels.
  document.addEventListener('submit', function (e) {
    var msg = e.target && e.target.dataset && e.target.dataset.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
  }, true);

  // <select data-auto-submit> — submit the parent form on any change.
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.dataset && 'autoSubmit' in t.dataset && t.form) t.form.submit();
  }, true);

  // <input data-select-all> / <code data-select-all> — select() on click.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.dataset && 'selectAll' in t.dataset && typeof t.select === 'function') {
      t.select();
    }
  }, true);
})();
