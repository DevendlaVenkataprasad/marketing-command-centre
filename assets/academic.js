/* Academic desk and Campus portal: copy buttons, confirmations, the department
   list following the institution, role-specific fields, and list filtering. */
(function () {
  'use strict';

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copy]');
    if (!b) { return; }
    var input = b.parentElement.querySelector('input');
    if (!input) { return; }
    input.select();
    var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1400); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
    } else {
      document.execCommand('copy');
      done();
    }
  });

  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); }
  });

  document.querySelectorAll('form').forEach(function (form) {
    var inst = form.querySelector('[data-edu-inst]');
    var dept = form.querySelector('[data-edu-dept]');
    var data = form.querySelector('[data-edu-depts]');
    if (inst && dept && data) {
      var map = {};
      try { map = JSON.parse(data.textContent) || {}; } catch (e) {}
      var fill = function () {
        var keep = dept.value;
        while (dept.options.length > 1) { dept.remove(1); }
        (map[inst.value] || []).forEach(function (d) {
          var o = new Option(d[1], d[0]);
          if (String(d[0]) === keep) { o.selected = true; }
          dept.add(o);
        });
      };
      inst.addEventListener('change', fill);
    }
    var role = form.querySelector('[data-edu-role]');
    if (role) {
      var show = function () {
        form.querySelectorAll('[data-for]').forEach(function (el) {
          var on = el.getAttribute('data-for').split(' ').indexOf(role.value) !== -1;
          el.classList.toggle('edu-hide', !on);
          // A hidden field is not sent, so two fields may share a name (only the shown one counts).
          (el.matches('input,select,textarea') ? [el] : el.querySelectorAll('input,select,textarea')).forEach(function (i) { i.disabled = !on; });
        });
      };
      role.addEventListener('change', show);
      show();
    }
  });

  document.querySelectorAll('[data-filter]').forEach(function (box) {
    var list = document.querySelector(box.getAttribute('data-filter'));
    if (!list) { return; }
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase();
      list.querySelectorAll('li').forEach(function (li) {
        li.hidden = q !== '' && li.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });
})();

/* On a phone the tab strip scrolls: bring the open tab (and filter) into view. */
document.querySelectorAll('.cp-tabs a.on, .cp-seg a.on').forEach(function (a) {
  var bar = a.parentElement;
  if (bar.scrollWidth > bar.clientWidth) {
    bar.scrollLeft = a.offsetLeft - (bar.clientWidth - a.offsetWidth) / 2;
  }
});

/* A button that asks first (forms with several buttons). */
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('button[data-confirm-btn]');
  if (b && !window.confirm(b.getAttribute('data-confirm-btn'))) { ev.preventDefault(); }
});
