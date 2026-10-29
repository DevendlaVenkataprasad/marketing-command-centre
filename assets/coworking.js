/* Co-working Space (lib/cw): counting numbers, edit dialogs, filters, tooltips, seat-map search. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- numbers count up once */
  function inr(n) {
    var s = String(Math.round(Math.abs(n))), neg = n < 0;
    if (s.length > 3) { s = s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3); }
    return (neg ? '-' : '') + '₹' + s;
  }
  function fmt(v, f) {
    if (f === 'inr') { return inr(v); }
    if (f === 'pct') { return Math.round(v * 100) + '%'; }
    return Math.round(v).toLocaleString('en-IN');
  }
  if (!reduce) {
    document.querySelectorAll('.cw-count').forEach(function (el) {
      var to = parseFloat(el.getAttribute('data-count')) || 0, f = el.getAttribute('data-fmt'), t0 = null, dur = 1100;
      if (!to) { return; }
      el.textContent = fmt(0, f);
      function step(t) {
        if (t0 === null) { t0 = t; }
        var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(to * e, f);
        if (p < 1) { requestAnimationFrame(step); } else { el.textContent = fmt(to, f); }
      }
      requestAnimationFrame(step);
    });
  }

  /* ---- tooltips for anything with data-tip */
  var tip = document.getElementById('cwTip');
  function place(ev) {
    var x = ev.clientX + 14, y = ev.clientY + 14, w = tip.offsetWidth, h = tip.offsetHeight;
    if (x + w > window.innerWidth - 8) { x = ev.clientX - w - 14; }
    if (y + h > window.innerHeight - 8) { y = ev.clientY - h - 14; }
    tip.style.left = Math.max(8, x) + 'px';
    tip.style.top = Math.max(8, y) + 'px';
  }
  if (tip) {
    document.addEventListener('mouseover', function (ev) {
      var el = ev.target.closest && ev.target.closest('[data-tip]');
      if (!el) { tip.hidden = true; return; }
      tip.textContent = el.getAttribute('data-tip');
      tip.hidden = false;
      place(ev);
    });
    document.addEventListener('mousemove', function (ev) { if (!tip.hidden) { place(ev); } });
    document.addEventListener('focusin', function (ev) {
      var el = ev.target.closest && ev.target.closest('[data-tip]');
      if (!el) { tip.hidden = true; return; }
      var r = el.getBoundingClientRect();
      tip.textContent = el.getAttribute('data-tip');
      tip.hidden = false;
      place({ clientX: r.left, clientY: r.bottom });
    });
  }

  /* ---- edit dialogs: a button with data-dlg="space|startup|wait" and data-row (JSON; {} = add) */
  function fill(dlg, row) {
    var isNew = !row || !row.id;
    var h = dlg.querySelector('h2[data-new]');
    if (h) { h.textContent = h.getAttribute(isNew ? 'data-new' : 'data-edit'); }
    dlg.querySelectorAll('form').forEach(function (form) {
      form.reset();
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.name === 'csrf' || el.name === 'a' || el.type === 'submit') { return; }
        var v = row && row[el.name] != null ? String(row[el.name]) : '';
        if (el.type === 'checkbox') { el.checked = v === '1'; }
        else if (el.type === 'date') { el.value = v.slice(0, 10); }
        else if (el.tagName === 'INPUT' && el.getAttribute('inputmode') === 'decimal' && v !== '') { el.value = String(parseFloat(v)); }
        else { el.value = v; }
      });
    });
    var del = dlg.querySelector('.cw-del');
    if (del) { del.hidden = isNew; }
    var sub = dlg.querySelector('[data-title]');
    if (sub) { sub.textContent = (row && row._title) || ''; }
    // An application's history: what happened and which emails went.
    var hist = dlg.querySelector('[data-history]');
    if (hist) {
      var ul = hist.querySelector('ul'), lines = (row && row._history) || [];
      ul.textContent = '';
      lines.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
      hist.hidden = !lines.length;
    }
    vendorToggle(dlg);
  }
  function vendorToggle(scope) {
    var sel = scope.querySelector('[data-toggle-vendor]'), box = scope.querySelector('.cw-vendor');
    if (sel && box) { box.hidden = sel.value !== 'Vendor'; }
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-dlg]');
    if (b) {
      var dlg = document.getElementById('dlg-' + b.getAttribute('data-dlg'));
      if (!dlg) { return; }
      var row = {};
      try { row = JSON.parse(b.getAttribute('data-row') || '{}'); } catch (e) { row = {}; }
      fill(dlg, row);
      if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
      var first = dlg.querySelector('input:not([type=hidden]), select');
      if (first) { first.focus(); }
      return;
    }
    if (ev.target.closest('[data-close]')) {
      var d = ev.target.closest('dialog');
      if (d) { d.close ? d.close() : d.removeAttribute('open'); }
    }
  });
  document.querySelectorAll('dialog.cw-dlg').forEach(function (d) {
    // A click on the backdrop closes it.
    d.addEventListener('click', function (ev) { if (ev.target === d) { d.close(); } });
  });
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-toggle-vendor]')) { vendorToggle(ev.target.closest('form')); }
  });
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm') || (ev.submitter && ev.submitter.getAttribute('data-confirm-btn'));
    if (msg && !window.confirm(msg)) { ev.preventDefault(); }
  });

  /* ---- copy a link or embed code */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copy]');
    if (!b) { return; }
    var src = document.querySelector(b.getAttribute('data-copy'));
    if (!src) { return; }
    src.select();
    var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(src.value).then(done, function () { document.execCommand('copy'); done(); }); }
    else { document.execCommand('copy'); done(); }
  });

  /* ---- table filters: a search box (data-find-in) and selects (data-filter-in + data-key) */
  function filterTable(sel) {
    var table = document.querySelector(sel);
    if (!table) { return; }
    var q = '', keys = {};
    document.querySelectorAll('[data-find-in="' + sel + '"]').forEach(function (i) { q = i.value.trim().toLowerCase(); });
    document.querySelectorAll('[data-filter-in="' + sel + '"]').forEach(function (s) { if (s.value) { keys[s.getAttribute('data-key')] = s.value; } });
    var shown = 0;
    table.querySelectorAll('tbody tr[data-find]').forEach(function (tr) {
      var ok = q === '' || tr.getAttribute('data-find').indexOf(q) >= 0;
      Object.keys(keys).forEach(function (k) { if (tr.getAttribute('data-' + k) !== keys[k]) { ok = false; } });
      tr.hidden = !ok;
      shown += ok ? 1 : 0;
    });
    var none = table.parentNode.querySelector('.cw-none');
    if (none) { none.hidden = shown > 0 || !table.querySelector('tbody tr[data-find]'); }
  }
  document.querySelectorAll('[data-find-in]').forEach(function (i) { i.addEventListener('input', function () { filterTable(i.getAttribute('data-find-in')); }); });
  document.querySelectorAll('[data-filter-in]').forEach(function (s) { s.addEventListener('change', function () { filterTable(s.getAttribute('data-filter-in')); }); });

  /* ---- seat map: a floor plan with a panel beside it */
  var fp = document.querySelector('.cw-fp');
  if (fp) {
    var legend = document.getElementById('cwSeatFilter'), find = document.getElementById('cwSeatFind'), panel = document.getElementById('cwInspect');
    var empty = panel.querySelector('.cw-inspect-empty'), seatBox = panel.querySelector('.cw-inspect-seat'), canEdit = panel.getAttribute('data-edit') === '1';
    var state = '', seatsAll = Array.prototype.slice.call(fp.querySelectorAll('.cw-desk[data-info], .cw-cabin[data-info]')), selected = null;
    var escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return escMap[c]; }); }
    function applyFilter() {
      var q = find ? find.value.trim().toLowerCase() : '', any = state !== '' || q !== '';
      fp.classList.toggle('dim', any);
      seatsAll.forEach(function (s) {
        var hit = any && (state === '' || (' ' + s.getAttribute('data-state') + ' ').indexOf(' ' + state + ' ') >= 0)
                      && (q === '' || s.getAttribute('data-find').indexOf(q) >= 0);
        s.classList.toggle('hit', hit);
      });
    }
    legend.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-seat]');
      if (!b) { return; }
      state = b.getAttribute('data-seat');
      legend.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      applyFilter();
    });
    if (find) { find.addEventListener('input', applyFilter); }

    /* Every seat the same startup holds lights up together. */
    function peers(key, on) {
      seatsAll.forEach(function (s) { s.classList.toggle('peer', on && key !== '' && s.getAttribute('data-occ') === key); });
    }
    function settle() { peers(selected ? selected.getAttribute('data-occ') : '', !!selected); }
    fp.addEventListener('mouseover', function (ev) {
      var s = ev.target.closest('[data-occ]');
      if (s) { peers(s.getAttribute('data-occ'), true); }
    });
    fp.addEventListener('mouseout', function (ev) {
      var s = ev.target.closest('[data-occ]');
      if (s && !(ev.relatedTarget && s.contains(ev.relatedTarget))) { settle(); }
    });

    function row(label, val, cls) { return val ? '<div><dt>' + esc(label) + '</dt><dd' + (cls ? ' class="' + cls + '"' : '') + '>' + esc(val) + '</dd></div>' : ''; }
    function show(seat) {
      if (selected) { selected.classList.remove('sel'); }
      selected = seat;
      if (!seat) {
        seatBox.hidden = true;
        empty.hidden = false;
        peers('', false);
        return;
      }
      seat.classList.add('sel');
      settle();
      var d = {};
      try { d = JSON.parse(seat.getAttribute('data-info')); } catch (e) { d = {}; }
      var renew = d.renewal ? d.renewal + (d.days != null ? ' (' + (d.days < 0 ? Math.abs(d.days) + ' days over' : d.days + ' days left') + ')' : '') : '';
      seatBox.innerHTML = '<div class="cw-is-h"><div><span class="cw-is-tag"><span class="sw s-' + esc(d.state) + '"></span>' + esc(d.word)
        + (d.partner ? ' · ' + esc(d.managed) + '-managed' : '') + '</span>'
        + '<h2>' + esc(d.occupant || (d.type === 'Cabin' ? 'Empty cabin' : d.type === 'Open space' ? 'Empty open space' : 'Empty desk')) + '</h2><small>' + esc(d.space) + ' · ' + esc(d.zone) + '</small></div>'
        + '<button type="button" class="cw-x" data-unselect aria-label="Close">×</button></div><dl class="cw-is-dl">'
        + row('Seats held', d.seats > 1 ? d.seats + ' in this centre' : '')
        + row('Agreement', d.agreement ? d.agreement + (d.signed ? ', signed ' + d.signed : '') : (d.signed ? 'Signed ' + d.signed : ''))
        + row('Ends', d.ends) + row('Renewal', renew, d.days != null && d.days < 0 ? 'risk' : 'warn')
        + row('Notice', d.action, 'warn') + row('Billing', d.status, d.status && d.status !== 'OK' ? 'warn' : '')
        + row('Rent charged', d.rent && d.rent !== '₹0' ? d.rent + ' / month' : '') + row('Dues', d.dues, 'risk')
        + row('Email', d.email) + row('Phone', d.phone) + row('Vendor', d.vendor) + row('Checked', d.checked) + row('Notes', d.notes)
        + '</dl><div class="cw-is-acts">'
        + (canEdit ? '<button type="button" class="btn btn-primary btn-sm" data-dlg="space">Edit this space</button>' : '')
        + (d.occupant && d.seats > 1 ? '<button type="button" class="btn btn-sm" data-filter-occ="' + esc(seat.getAttribute('data-occ')) + '">Find all ' + esc(d.seats) + ' seats</button>' : '')
        + '</div>';
      var edit = seatBox.querySelector('[data-dlg]');
      if (edit) { edit.setAttribute('data-row', seat.getAttribute('data-row')); }
      empty.hidden = true;
      seatBox.hidden = false;
      if (window.innerWidth <= 1180) { panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' }); }
    }
    function seatsOf(key) { return seatsAll.filter(function (s) { return s.getAttribute('data-occ') === key; }); }
    document.addEventListener('click', function (ev) {
      var seat = ev.target.closest('.cw-desk[data-info], .cw-cabin[data-info]');
      if (seat) { show(seat === selected ? null : seat); return; }
      if (ev.target.closest('[data-unselect]')) { show(null); return; }
      var f = ev.target.closest('[data-filter-occ]');
      if (f) {
        find.value = '';
        state = '';
        legend.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-seat') === ''); });
        var mine = seatsOf(f.getAttribute('data-filter-occ'));
        fp.classList.add('dim');
        seatsAll.forEach(function (s) { s.classList.toggle('hit', mine.indexOf(s) >= 0); });
        if (mine[0]) { mine[0].scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' }); }
        return;
      }
      var li = ev.target.closest('.cw-holders li');
      if (li) {
        var list = seatsOf(li.getAttribute('data-occ'));
        if (list[0]) { show(list[0]); list[0].scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' }); }
      }
    });
    panel.addEventListener('mouseover', function (ev) { var li = ev.target.closest('.cw-holders li'); if (li) { peers(li.getAttribute('data-occ'), true); } });
    panel.addEventListener('mouseout', function (ev) { if (ev.target.closest('.cw-holders li')) { settle(); } });
    panel.addEventListener('keydown', function (ev) {
      var li = ev.target.closest('.cw-holders li');
      if (li && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); li.click(); }
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !document.querySelector('dialog[open]')) {
        show(null);
        if (find) { find.value = ''; }
        state = '';
        legend.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-seat') === ''); });
        applyFilter();
      }
    });
  }
})();
