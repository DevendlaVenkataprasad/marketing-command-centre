/* HR Management — small page behaviours. Every action still works without
   this file: it only adds confirmations, select-all, and the live leave-day
   preview. */
(function () {
  'use strict';

  // Confirm before destructive or important actions.
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var btn = ev.submitter || f.querySelector('[data-confirm]');
    var msg = (btn && btn.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  }, true);

  // Change a filter and the list follows.
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
  });

  // Select-all checkboxes.
  document.querySelectorAll('[data-checkall]').forEach(function (box) {
    box.addEventListener('change', function () {
      var name = box.getAttribute('data-checkall');
      document.querySelectorAll('input[type="checkbox"][name="' + name + '"]').forEach(function (c) {
        if (!c.disabled) { c.checked = box.checked; }
      });
    });
  });

  // Show the one-day choices or the multi-day half-day choices.
  var lf = document.getElementById('hr-leave-form');
  if (lf) {
    var s = lf.querySelector('[name="start_date"]');
    var e = lf.querySelector('[name="end_date"]');
    var one = lf.querySelector('[data-oneday]');
    var many = lf.querySelector('[data-manyday]');
    var out = document.getElementById('hr-leave-preview');
    var timer = null;
    var sync = function () {
      if (s && e && s.value && (!e.value || e.value < s.value)) { e.value = s.value; }
      var single = s && e && s.value && s.value === e.value;
      if (one) { one.hidden = !single; }
      if (many) { many.hidden = single; }
      clearTimeout(timer);
      timer = setTimeout(preview, 250);
    };
    var preview = function () {
      if (!out || !s.value) { return; }
      var data = new URLSearchParams(new FormData(lf));
      data.set('p', 'api');
      data.set('do', 'leave_days');
      data.delete('csrf');
      fetch('hr.php?' + data.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          out.hidden = false;
          out.className = j.ok ? 'hr-note' : 'hr-warn';
          out.textContent = j.ok ? j.text : (j.error || 'Could not work out the days.');
        })
        .catch(function () { out.hidden = true; });
    };
    lf.addEventListener('change', sync);
    sync();
  }

  // A link to #section opens a closed <details> there (e.g. "Apply for leave").
  var openTarget = function () {
    var id = location.hash.slice(1);
    var el = id && document.getElementById(id);
    if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView({ block: 'start' }); }
  };
  window.addEventListener('hashchange', openTarget);
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[href^="#"]');
    if (a) { setTimeout(openTarget, 0); }
  });
  openTarget();

  // Org chart: expand or collapse every branch.
  document.querySelectorAll('[data-tree]').forEach(function (b) {
    b.addEventListener('click', function () {
      var open = b.getAttribute('data-tree') === 'open';
      document.querySelectorAll('.hr-tree details').forEach(function (d) { d.open = open; });
    });
  });
})();
