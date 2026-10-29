/*
 * Fill the gaps: one event, only its empty columns.
 *
 * On Upcoming and Completed Events each row has a pencil. It opens that event
 * with just the columns of this view that are still empty -- on Upcoming the
 * planning ones, on Completed the results -- so filling an event in is a
 * short form rather than a scroll along a wide row.
 *
 * What counts as empty is read from the cells as drawn, not remembered from
 * when the page loaded: a cell filled a moment ago is not asked about again,
 * and a results cell still shut until the event ends has no box to fill.
 *
 * Each answer is saved through the same api/save.php as typing in the grid,
 * so every rule the grid obeys -- lists, locks, approvals -- holds here too,
 * and the value lands on the one row in Programs Data.
 */
(function () {
  'use strict';

  var cfg    = window.FABO_GRID;
  var fields = window.FABO_FILL || [];
  var body   = document.getElementById('gridbody');
  if (!cfg || !body || !fields.length) { return; }

  function post(params) {
    var p = new URLSearchParams(params);
    if (cfg.token) { p.set('k', cfg.token); }
    if (cfg.admin) { p.set('as', 'admin'); }
    p.set('sheet', cfg.sheet);
    p.set('csrf', cfg.csrf);
    return fetch(cfg.api + 'save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: p.toString()
    }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) { return { ok: false, error: 'Server error (' + r.status + ').' }; }
      });
    });
  }

  /** The grid's own box for a field on this row, if it is open and empty. */
  function gap(tr, field) {
    var td = tr.querySelector('td[data-field="' + field + '"]');
    var el = td && td.querySelector('.js-cell');
    if (!el || el.type === 'checkbox') { return null; }
    return (el.value || '').trim() === '' ? el : null;
  }

  function gaps(tr) {
    var out = [];
    fields.forEach(function (f) {
      var el = gap(tr, f.field);
      if (el) { out.push({ field: f.field, label: f.label, cell: el }); }
    });
    return out;
  }

  /** The badge on the row's pencil, kept to what is actually left. */
  function badge(tr) {
    var btn = tr.querySelector('.js-evfill');
    if (!btn) { return; }
    var n = gaps(tr).length;
    btn.innerHTML = '&#9998;' + (n ? '<b>' + n + '</b>' : '');
    btn.classList.toggle('evfill-done', !n);
    btn.title = n ? 'Fill the ' + n + ' empty column' + (n === 1 ? '' : 's') + ' of this event'
                  : 'Nothing left to fill on this view';
  }

  /** A box like the grid's, without the grid's add-an-option markers. */
  function control(item) {
    var src = item.cell, el;
    if (src.tagName === 'SELECT') {
      el = document.createElement('select');
      Array.prototype.forEach.call(src.options, function (o) {
        if (o.disabled || o.value === '__add__' || o.value === '__manage__') { return; }
        el.appendChild(new Option(o.text, o.value));
      });
      el.value = '';
    } else if (src.closest('td').classList.contains('t-long')) {
      el = document.createElement('textarea');
      el.rows = 3;
    } else {
      el = document.createElement('input');
      el.type = src.type || 'text';
      if (src.step) { el.step = src.step; }
    }
    el.setAttribute('data-field', item.field);
    el.id = 'evf_' + item.field;
    return el;
  }

  function name(tr) {
    var n = tr.querySelector('td[data-field="event_name"] .js-cell, td[data-field="event_name"] .cell');
    var id = tr.querySelector('td.idcell .cell');
    var a = n ? (n.value !== undefined ? n.value : n.textContent) : '';
    var b = id ? id.textContent : '';
    return [b, a].filter(function (s) { return (s || '').trim() !== ''; }).join(' · ') || 'This event';
  }

  /* ---------------------------------------------------------- the box */

  var modal = document.createElement('div');
  modal.className = 'wcmodal evfill';
  modal.hidden = true;
  modal.innerHTML =
    '<div class="wcmodal-card evfill-card" role="dialog" aria-modal="true" aria-labelledby="evfillTitle">' +
      '<h3 id="evfillTitle"></h3>' +
      '<div class="hint" id="evfillHint"></div>' +
      '<form id="evfillForm" novalidate><div id="evfillBody"></div>' +
        '<div class="hint err" id="evfillErr"></div>' +
        '<div class="wcmodal-foot">' +
          '<button type="button" class="btn" id="evfillCancel">Close</button>' +
          '<button type="submit" class="btn btn-primary" id="evfillSave">Save</button>' +
        '</div></form>' +
    '</div>';
  document.body.appendChild(modal);

  var form   = modal.querySelector('#evfillForm');
  var boxes  = modal.querySelector('#evfillBody');
  var errEl  = modal.querySelector('#evfillErr');
  var saveBt = modal.querySelector('#evfillSave');
  var current = null;          // { tr, items }

  function close() {
    modal.hidden = true;
    current = null;
  }


  /* Request Design & Publicity Support: its questions (the other design_*
     columns) show only once it is Yes. Nothing is asked that the event
     already answers -- see lib/eventdesign.php. $rowValue is the row's own
     answer when the box does not ask the question itself. */
  function designGate(box, rowValue) {
    var sw = box.querySelector('[data-field="design_support"]');
    var deps = Array.prototype.filter.call(box.querySelectorAll('[data-field^="design_"]'),
      function (el) { return el.getAttribute('data-field') !== 'design_support'; });
    if (!deps.length) { return; }
    if (sw && !box.querySelector('.design-gate-hint')) {
      var hint = document.createElement('div');
      hint.className = 'hint design-gate-hint';
      hint.textContent = 'Yes opens the design questions. The design team receives the request once the event is approved, and everyone is emailed then.';
      sw.parentNode.insertBefore(hint, sw.nextSibling);
    }
    var apply = function () {
      var on = sw ? sw.value === 'Yes' : rowValue === 'Yes';
      deps.forEach(function (el) {
        el.hidden = !on;
        el.disabled = !on;
        var lab = el.id ? box.querySelector('label[for="' + el.id + '"]') : null;
        if (lab) { lab.hidden = !on; }
      });
    };
    if (sw) { sw.addEventListener('change', apply); }
    apply();
  }

  function open(tr) {
    var items = gaps(tr);
    current = { tr: tr, items: items };
    modal.querySelector('#evfillTitle').textContent = name(tr);
    boxes.innerHTML = '';
    errEl.textContent = '';
    saveBt.disabled = false;
    saveBt.hidden = !items.length;

    var view = cfg.view === 'completed' ? 'Completed Events' : 'Upcoming Events';
    modal.querySelector('#evfillHint').textContent = items.length
      ? items.length + ' column' + (items.length === 1 ? ' is' : 's are') + ' still empty on ' + view +
        '. Fill what you know and leave the rest; whatever you save goes straight to Programs Data.'
      : 'Nothing is left to fill on ' + view + ' for this event.';

    items.forEach(function (it) {
      var lab = document.createElement('label');
      lab.htmlFor = 'evf_' + it.field;
      lab.textContent = it.label;
      boxes.appendChild(lab);
      boxes.appendChild(control(it));
    });
    var dsCell = tr.querySelector('td[data-field="design_support"] .js-cell');
    designGate(boxes, dsCell ? dsCell.value : '');
    modal.hidden = false;
    var first = boxes.querySelector('input, select, textarea');
    if (first) { first.focus(); }
  }

  /* Saved one at a time, in order: a Send for Approval among them sends an
     email, and doing those in parallel with everything else would make a
     failure halfway hard to explain. */
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!current) { return; }
    var tr = current.tr;
    var todo = [];
    Array.prototype.forEach.call(boxes.querySelectorAll('[data-field]'), function (el) {
      var v = (el.value || '').trim();
      if (v !== '') { todo.push({ field: el.getAttribute('data-field'), value: v, el: el }); }
    });
    if (!todo.length) { errEl.textContent = 'Nothing has been filled in yet.'; return; }

    saveBt.disabled = true;
    errEl.textContent = 'Saving…';
    var failed = [], notes = [];

    (function next(i) {
      if (i >= todo.length) {
        badge(tr);
        if (!failed.length && notes.length) {
          /* Saved, and one of them did more than store a value (a Send for
             Approval, say). Said rather than closed over. */
          errEl.textContent = notes.join(' ');
          saveBt.hidden = true;
          return;
        }
        if (!failed.length) {
          close();
          var st = document.getElementById('savestate');
          if (st) { st.className = 'savestate on saved'; st.textContent = 'Saved'; }
          return;
        }
        saveBt.disabled = false;
        errEl.textContent = failed.join(' ');
        return;
      }
      var t = todo[i];
      post({ id: tr.getAttribute('data-id'), field: t.field, value: t.value }).then(function (res) {
        if (res && res.ok) {
          /* Put it in the grid's own box, so the row reads right at once;
             the live sync brings the worked-out columns along within seconds. */
          var td = tr.querySelector('td[data-field="' + t.field + '"]');
          var cell = td && td.querySelector('.js-cell');
          if (cell) {
            if (cell.tagName === 'SELECT' &&
                !Array.prototype.some.call(cell.options, function (o) { return o.value === t.value; })) {
              cell.appendChild(new Option(t.value, t.value));
            }
            cell.value = res.row && res.row.raw && res.row.raw[t.field] !== undefined
              ? res.row.raw[t.field] : t.value;
          }
          var lab = boxes.querySelector('label[for="evf_' + t.field + '"]');
          if (lab) { lab.remove(); }
          t.el.remove();
          if (res.notice) { notes.push(res.notice); }
        } else {
          failed.push((labelOf(t.field) + ': ' + ((res && res.error) || 'not saved.')));
        }
        next(i + 1);
      }).catch(function () {
        failed.push(labelOf(t.field) + ': could not reach the server.');
        next(i + 1);
      });
    })(0);
  });

  function labelOf(field) {
    for (var i = 0; i < fields.length; i++) {
      if (fields[i].field === field) { return fields[i].label; }
    }
    return field;
  }

  modal.querySelector('#evfillCancel').addEventListener('click', close);
  modal.addEventListener('click', function (ev) { if (ev.target === modal) { close(); } });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hidden) { close(); }
  });

  body.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.js-evfill') : null;
    if (!btn) { return; }
    ev.preventDefault();
    open(btn.closest('tr'));
  });

  /* Typing straight into the grid fills a gap too; keep the count honest. */
  body.addEventListener('change', function (ev) {
    var tr = ev.target.closest ? ev.target.closest('tr[data-id]') : null;
    if (tr) { badge(tr); }
  });
})();
