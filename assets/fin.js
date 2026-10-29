/*
 * Finance Portal — the line editors and small helpers. Everything here is a
 * convenience: totals are shown as you type, rows are added and removed; the
 * server works every figure out again when the form is saved, and its figures
 * are the ones kept.
 */
(function () {
  'use strict';

  function num(v) {
    var n = parseFloat(String(v || '').replace(/[,\s₹]/g, ''));
    return isNaN(n) ? 0 : n;
  }
  function fmt(n) {
    return (Math.round(n * 100) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* A new row is a copy of the last one with its values cleared and its index moved on. */
  function addRow(table) {
    var body = table.tBodies[0];
    var last = body.rows[body.rows.length - 1];
    if (!last) { return; }
    var copy = last.cloneNode(true);
    var max = -1;
    body.querySelectorAll('[name]').forEach(function (el) {
      var m = el.name.match(/^[a-z_]+\[(\d+)\]/);
      if (m) { max = Math.max(max, parseInt(m[1], 10)); }
    });
    copy.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/^([a-z_]+)\[(\d+)\]/, function (_, base) { return base + '[' + (max + 1) + ']'; });
      if (el.hasAttribute('data-keep')) { el.remove(); return; }
      if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else if (el.type === 'checkbox') { el.checked = false; } else if (el.type !== 'hidden') { el.value = ''; } else { el.remove(); }
    });
    copy.querySelectorAll('[data-out]').forEach(function (el) { el.textContent = ''; });
    copy.querySelectorAll('a, .late').forEach(function (el) { if (!el.closest('button')) { el.remove(); } });
    var qty = copy.querySelector('[data-f="qty"]');
    if (qty) { qty.value = '1'; }
    body.appendChild(copy);
    recalc(table);
  }

  function recalc(table) {
    var kind = table.getAttribute('data-lines');
    var form = table.closest('form');
    if (kind === 'doc') {
      var sub = 0, tax = 0;
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        var q = tr.querySelector('[data-f="qty"]'), r = tr.querySelector('[data-f="rate"]'), t = tr.querySelector('[data-f="tax"]');
        if (!q || !r) { return; }
        var amt = Math.round(num(q.value) * num(r.value) * 100) / 100;
        var rate = t && t.selectedOptions[0] ? num(t.selectedOptions[0].getAttribute('data-rate')) : 0;
        var out = tr.querySelector('[data-out="amount"]');
        if (out) { out.textContent = amt ? fmt(amt) : ''; }
        sub += amt;
        tax += Math.round(amt * rate) / 100;
      });
      var tdsSel = form && form.querySelector('[data-f="tdscode"]');
      var tds = tdsSel && tdsSel.selectedOptions[0] ? Math.round(sub * num(tdsSel.selectedOptions[0].getAttribute('data-rate'))) / 100 : 0;
      set(form, 'sub', sub); set(form, 'tax', tax); set(form, 'tds', tds); set(form, 'total', sub + tax - tds);
    } else if (kind === 'journal') {
      var dr = 0, cr = 0;
      table.querySelectorAll('[data-f="dr"]').forEach(function (el) { dr += num(el.value); });
      table.querySelectorAll('[data-f="cr"]').forEach(function (el) { cr += num(el.value); });
      set(form, 'dr', dr); set(form, 'cr', cr); set(form, 'diff', dr - cr);
      var d = form && form.querySelector('[data-diff]');
      if (d) { d.classList.toggle('bad', Math.round((dr - cr) * 100) !== 0); }
    }
  }
  function set(form, key, v) {
    if (!form) { return; }
    var el = form.querySelector('[data-out="' + key + '"]');
    if (el && !el.closest('tbody')) { el.textContent = fmt(v); }
  }

  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-row-add]');
    if (add) {
      var scope = add.closest('form') || document;
      var table = scope.querySelector('table[data-lines]');
      if (table) { addRow(table); }
      return;
    }
    var del = e.target.closest('[data-row-del]');
    if (del) {
      var tr = del.closest('tr'), tb = tr.parentNode, t = tr.closest('table');
      if (tb.rows.length > 1) { tr.remove(); } else { tr.querySelectorAll('input:not([type=hidden])').forEach(function (i) { i.value = ''; }); }
      recalc(t);
    }
  });

  document.addEventListener('input', function (e) {
    var t = e.target.closest('table[data-lines]');
    if (t) { recalc(t); }
    if (e.target.matches('[data-f="tdscode"]')) { var tt = e.target.closest('form').querySelector('table[data-lines]'); if (tt) { recalc(tt); } }
  });
  document.addEventListener('change', function (e) {
    var t = e.target.closest('table[data-lines]') || (e.target.matches('[data-f="tdscode"]') && e.target.closest('form').querySelector('table[data-lines]'));
    if (t) { recalc(t); }
    if (e.target.matches('[data-fp-jtype]')) { showType(e.target); }
    if (e.target.matches('[data-check-all]')) {
      var g = e.target.getAttribute('data-check-all');
      document.querySelectorAll('[data-group="' + g + '"]:not(:disabled)').forEach(function (c) { c.checked = e.target.checked; });
    }
  });

  /* Accrual and intercompany fields show only for those journal types. */
  function showType(sel) {
    var form = sel.closest('form');
    form.querySelectorAll('[data-fp-show]').forEach(function (el) { el.hidden = el.getAttribute('data-fp-show') !== sel.value; });
  }

  document.querySelectorAll('table[data-lines]').forEach(recalc);
  document.querySelectorAll('[data-fp-jtype]').forEach(showType);
})();
