/* Incubation Management — small page behaviours. Every action still works without
   this file (the server validates and authorises everything); it adds
   confirmations, conditional fields, selection across pages, select-all,
   the field-type switch in the form builder and the weight total. */
(function () {
  'use strict';

  // Confirm before consequential actions.
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var btn = ev.submitter;
    var msg = (btn && btn.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); }
  }, true);

  // Creating a form: an Event or Program purpose asks for the event's or program's name.
  document.querySelectorAll('select[data-event-purpose]').forEach(function (sel) {
    var box = sel.form && sel.form.querySelector('[data-event-name]');
    if (!box) { return; }
    var input = box.querySelector('input');
    var label = box.querySelector('label');
    // Which purposes ask for a name, and what it is called: from Settings -> Form purposes.
    var names = { event: 'Event name', program: 'Program name' };
    try {
      var given = JSON.parse(sel.getAttribute('data-event-purpose') || 'null');
      if (given && typeof given === 'object') { names = given; }
    } catch (e) { /* keep the defaults */ }
    function sync() {
      var on = Object.prototype.hasOwnProperty.call(names, sel.value);
      box.hidden = !on;
      input.disabled = !on;
      input.required = on;
      if (on && label && label.firstChild) { label.firstChild.nodeValue = names[sel.value]; }
    }
    sel.addEventListener('change', sync);
    sync();
  });

  // Change a filter and the page follows.
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
  });

  /* ------------------------------------------------ conditional fields
     The same rules as inc_form_visible() on the server: a field shows when
     its condition holds and the field it depends on is itself shown; a
     section heading's condition governs every field until the next heading.
     A hidden field's inputs are disabled, so they are neither required nor sent. */
  function answers(form) {
    var out = {};
    form.querySelectorAll('.inc-f[data-uid]').forEach(function (box) {
      var uid = box.getAttribute('data-uid');
      var vals = [];
      box.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (el.disabled) { return; }
        if (el.type === 'radio' || el.type === 'checkbox') { if (el.checked) { vals.push(el.value); } }
        else if (el.tagName === 'SELECT' && el.multiple) { Array.prototype.forEach.call(el.selectedOptions, function (o) { vals.push(o.value); }); }
        else if (el.type !== 'file' && el.value.trim() !== '') { vals.push(el.value.trim()); }
      });
      out[uid] = vals;
    });
    return out;
  }
  function holds(cond, vals) {
    if (!cond || !cond.field) { return true; }
    var v = vals[cond.field] || [];
    var want = String(cond.value || '');
    switch (cond.op) {
      case 'filled': return v.length > 0;
      case 'empty': return v.length === 0;
      case 'neq': return v.indexOf(want) === -1;
      case 'in': return want.split('|').map(function (s) { return s.trim(); }).some(function (w) { return v.indexOf(w) !== -1; });
      default: return v.indexOf(want) !== -1;
    }
  }
  function applyConditions(form) {
    // Two passes settle chains (a field enabled in pass one can reveal another).
    for (var pass = 0; pass < 2; pass++) {
      var vals = answers(form);
      var visible = {};
      var sectionOn = true;
      form.querySelectorAll('.inc-sec[data-uid], .inc-f[data-uid]').forEach(function (box) {
        var uid = box.getAttribute('data-uid');
        var cond = null;
        try { cond = JSON.parse(box.getAttribute('data-cond') || 'null'); } catch (e) { cond = null; }
        var ok = holds(cond, vals) && (!cond || !cond.field || visible[cond.field]);
        var show;
        if (box.hasAttribute('data-section')) { sectionOn = ok; show = ok; }
        else { show = sectionOn && ok; }
        if (show) { visible[uid] = true; }
        box.hidden = !show;
        box.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = !show; });
      });
    }
  }
  document.querySelectorAll('.inc-applyform').forEach(function (form) {
    applyConditions(form);
    form.addEventListener('change', function () { applyConditions(form); });
    form.addEventListener('input', function (ev) { if (ev.target.tagName !== 'TEXTAREA') { applyConditions(form); } });
    if (form.hasAttribute('data-steps')) { steps(form); }
  });

  /* ---------------------------------------------- a form, step by step
     Each section heading starts a step (questions before the first heading
     are step one). A section hidden by its condition is skipped. Next checks
     the step's required answers first; the server checks everything again.
     Nothing is moved in the page: the boxes are only shown and hidden, so a
     form without JavaScript is the ordinary long form. */
  function steps(form) {
    var grid = form.querySelector('.inc-form');
    var foot = form.querySelector('.inc-formfoot');
    if (!grid || !foot) { return; }
    var boxes = Array.prototype.slice.call(grid.children);
    var groups = [];
    boxes.forEach(function (b) {
      if (b.hasAttribute('data-section') || !groups.length) { groups.push([]); }
      groups[groups.length - 1].push(b);
    });
    if (groups.length < 2) { return; }
    var submit = foot.querySelector('button:not([type="button"]), input[type="submit"]');
    var bar = document.createElement('div');
    bar.className = 'inc-steps';
    // Only the progress bar: the section's own heading below names the step.
    bar.innerHTML = '<div class="inc-steps-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"><i></i></div>';
    grid.parentNode.insertBefore(bar, grid);
    var back = document.createElement('button');
    back.type = 'button'; back.className = 'btn'; back.textContent = '← Back';
    var next = document.createElement('button');
    next.type = 'button'; next.className = 'btn btn-primary'; next.textContent = 'Next →';
    var msg = document.createElement('div');
    msg.className = 'inc-err inc-steps-msg'; msg.setAttribute('role', 'alert');
    foot.insertBefore(back, foot.firstChild);
    foot.appendChild(next);
    foot.parentNode.insertBefore(msg, foot);
    var at = 0;

    function live(i) {                       // a step whose section is shown
      return groups[i].some(function (b) { return !b.hidden; });
    }
    function order() {
      var out = [];
      groups.forEach(function (g, i) { if (live(i)) { out.push(i); } });
      return out;
    }
    function show(i) {
      at = i;
      groups.forEach(function (g, k) {
        g.forEach(function (b) { b.classList.toggle('inc-step-off', k !== i); });
      });
      var o = order(), pos = o.indexOf(i);
      var pct = Math.round((pos + 1) * 100 / o.length);
      bar.querySelector('i').style.width = pct + '%';
      bar.firstChild.setAttribute('aria-valuenow', String(pct));
      back.hidden = pos <= 0;
      var last = pos === o.length - 1;
      next.hidden = last;
      if (submit) { submit.hidden = !last; }
      msg.textContent = '';
    }
    function missing(i) {                    // the first required answer left blank in step i
      var bad = null;
      groups[i].forEach(function (b) {
        if (bad || b.hidden || !b.classList.contains('inc-f')) { return; }
        b.classList.remove('has-err');
        var ins = b.querySelectorAll('input, select, textarea');
        var ok = true;
        if (b.hasAttribute('data-req')) {
          var choice = b.querySelector('input[type="checkbox"], input[type="radio"]');
          if (choice) {
            ok = Array.prototype.some.call(ins, function (el) { return el.checked; });
          } else {
            ok = Array.prototype.every.call(ins, function (el) { return el.type === 'file' ? el.files.length > 0 : (el.value || '').trim() !== ''; });
          }
        }
        if (ok) {
          ok = Array.prototype.every.call(ins, function (el) { return el.type === 'checkbox' || el.type === 'radio' || !el.validity || el.validity.valid || el.validity.valueMissing; });
        }
        if (!ok) { bad = b; }
      });
      return bad;
    }
    next.addEventListener('click', function () {
      var bad = missing(at);
      if (bad) {
        bad.classList.add('has-err');
        var l = bad.querySelector('label');
        msg.textContent = 'Please complete "' + (l ? l.textContent.replace(/\s*\*\s*$/, '') : 'this question') + '" before going on.';
        var f = bad.querySelector('input, select, textarea');
        if (f) { f.focus(); }
        return;
      }
      var o = order(), pos = o.indexOf(at);
      if (pos < o.length - 1) { show(o[pos + 1]); bar.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    });
    back.addEventListener('click', function () {
      var o = order(), pos = o.indexOf(at);
      if (pos > 0) { show(o[pos - 1]); bar.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    });
    form.addEventListener('submit', function (ev) {
      var bad = missing(at);
      if (bad) { ev.preventDefault(); bad.classList.add('has-err'); msg.textContent = 'Please complete the highlighted answer.'; }
    });
    form.addEventListener('change', function () { show(live(at) ? at : order()[0]); });
    // After a refused submission, open on the first step with an error.
    var start = 0;
    for (var i = 0; i < groups.length; i++) {
      if (groups[i].some(function (b) { return b.classList.contains('has-err'); })) { start = i; break; }
    }
    show(live(start) ? start : order()[0]);
  }

  /* --------------------------------------------- selection across pages */
  document.querySelectorAll('[data-checkall]').forEach(function (box) {
    box.addEventListener('change', function () {
      document.querySelectorAll('input.inc-sel').forEach(function (c) {
        if (c.offsetParent !== null && c.checked !== box.checked) { c.checked = box.checked; c.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
  });
  document.querySelectorAll('.inc-bulk[data-selkey]').forEach(function (panel) {
    var key = 'inc.sel.' + panel.getAttribute('data-selkey');
    var form = panel.closest('form');
    var store = {};
    try { store = JSON.parse(sessionStorage.getItem(key) || '{}') || {}; } catch (e) { store = {}; }
    var save = function () { try { sessionStorage.setItem(key, JSON.stringify(store)); } catch (e) {} };
    var count = panel.querySelector('[data-selcount]');
    var note = panel.querySelector('[data-selnote]');
    var all = panel.querySelector('[data-allmatch]');
    var action = panel.querySelector('[data-bulkaction]');
    var sheet = panel.querySelector('.inc-bulk-sheet');
    var titles = {};
    try { titles = JSON.parse(panel.getAttribute('data-titles') || '{}'); } catch (e) { titles = {}; }
    // Tick boxes may sit outside the form (the board's cards use form="…").
    var onPage = Array.prototype.filter.call(document.querySelectorAll('input.inc-sel'), function (c) { return c.form === form; });
    var chosen = function () { return all && all.checked ? -1 : Object.keys(store).length; };
    var refresh = function () {
      var n = Object.keys(store).length, here = 0;
      onPage.forEach(function (c) { if (c.checked) { here++; } });
      count.textContent = all && all.checked ? 'All' : String(n);
      note.textContent = all && all.checked ? '' : (n > here ? '(' + (n - here) + ' on other pages)' : '');
      // The bar appears only once something is chosen.
      panel.classList.toggle('has-sel', n > 0 || !!(all && all.checked));
      if (!panel.classList.contains('has-sel')) { closeSheet(); }
    };
    var sync = function () {
      panel.querySelectorAll('[data-for]').forEach(function (el) {
        var on = el.getAttribute('data-for').split(' ').indexOf(action.value) !== -1;
        el.hidden = !on;
        el.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
      });
    };
    var closeSheet = function () { if (sheet) { sheet.hidden = true; } action.value = ''; };
    panel.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var act = btn.getAttribute('data-act');
        var more = btn.closest('details');
        if (more) { more.open = false; }
        action.value = act;
        sync();
        if (act.indexOf('export') === 0) {
          // Nothing to fill in: download straight away.
          if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
          action.value = '';
          return;
        }
        var n = chosen();
        panel.querySelector('[data-sheettitle]').textContent = titles[act] || btn.textContent;
        panel.querySelector('[data-sheetwho]').textContent = n === -1 ? 'For every application matching the filters.' : 'For ' + n + ' selected application' + (n === 1 ? '' : 's') + '.';
        sheet.hidden = false;
        var first = sheet.querySelector('.inc-bulk-row input:not([disabled]):not([type=hidden]), .inc-bulk-row select:not([disabled])');
        if (first) { first.focus(); }
      });
    });
    panel.querySelectorAll('[data-sheetclose]').forEach(function (b) { b.addEventListener('click', closeSheet); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && sheet && !sheet.hidden) { closeSheet(); } });
    onPage.forEach(function (c) {
      if (store[c.value]) { c.checked = true; }
      c.addEventListener('change', function () { if (c.checked) { store[c.value] = 1; } else { delete store[c.value]; } save(); refresh(); });
    });
    if (all) { all.addEventListener('change', refresh); }
    panel.querySelector('[data-selclear]').addEventListener('click', function () {
      store = {}; save(); onPage.forEach(function (c) { c.checked = false; }); if (all) { all.checked = false; } refresh();
    });
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      // Carry the selections made on other pages.
      var present = {};
      onPage.forEach(function (c) { if (c.checked) { present[c.value] = 1; } });
      Object.keys(store).forEach(function (id) {
        if (!present[id]) {
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = 'ids[]'; h.value = id; form.appendChild(h);
        }
      });
      if (action.value && action.value.indexOf('export') === -1) {
        // A change to records ends this selection; an export keeps it.
        setTimeout(function () { try { sessionStorage.removeItem(key); } catch (e) {} }, 0);
      }
    });
    sync();
    refresh();
  });

  /* ------------------------------------------------------------ board
     Its columns reach exactly to the bottom of the window, wherever the
     board starts (a search bar or tabs above it push it down), so the page
     itself never scrolls past it into empty space. */
  var fitBoards = function () {
    document.querySelectorAll('.kb').forEach(function (board) {
      var top = board.getBoundingClientRect().top + window.pageYOffset;
      board.style.setProperty('--kb-max', Math.max(320, window.innerHeight - top - 24) + 'px');
    });
  };
  fitBoards();
  window.addEventListener('resize', fitBoards);

  /* Drag a card to another column: the server moves it with the same rules
     as everywhere else; a refused move springs back with the reason. */
  document.querySelectorAll('.kb[data-move]').forEach(function (board) {
    var flash = board.querySelector('.kb-flash');
    var csrf = document.querySelector('input[name="csrf"]');
    var dragged = null, from = null, timer = null;
    var say = function (msg, bad) {
      flash.textContent = msg; flash.hidden = false;
      flash.className = 'kb-flash' + (bad ? ' bad' : '');
      clearTimeout(timer); timer = setTimeout(function () { flash.hidden = true; }, bad ? 7000 : 3000);
    };
    var recount = function (col) {
      var n = col.querySelector('.kb-count');
      var cards = col.querySelectorAll('.kb-card').length;
      var empty = col.querySelector('.kb-empty');
      if (empty) { empty.hidden = cards > 0; }
      return n;
    };
    var bump = function (col, d) { var n = recount(col); n.textContent = String((parseInt(n.textContent, 10) || 0) + d); };
    board.addEventListener('dragstart', function (ev) {
      var card = ev.target.closest && ev.target.closest('.kb-card');
      if (!card) { return; }
      dragged = card; from = card.closest('.kb-col');
      card.classList.add('dragging');
      ev.dataTransfer.effectAllowed = 'move';
      try { ev.dataTransfer.setData('text/plain', card.getAttribute('data-id')); } catch (e) {}
    });
    board.addEventListener('dragend', function () {
      if (dragged) { dragged.classList.remove('dragging'); }
      board.querySelectorAll('.kb-col.over').forEach(function (c) { c.classList.remove('over'); });
    });
    board.querySelectorAll('.kb-col[data-stage]').forEach(function (col) {
      col.addEventListener('dragover', function (ev) { if (dragged) { ev.preventDefault(); col.classList.add('over'); } });
      col.addEventListener('dragleave', function (ev) { if (!col.contains(ev.relatedTarget)) { col.classList.remove('over'); } });
      col.addEventListener('drop', function (ev) {
        ev.preventDefault();
        col.classList.remove('over');
        if (!dragged || col === from) { return; }
        var card = dragged, origin = from, list = col.querySelector('.kb-list');
        var reason = '';
        if (col.hasAttribute('data-reason')) {
          reason = window.prompt('Moving to "' + col.getAttribute('aria-label') + '" needs a reason:') || '';
          if (!reason.trim()) { return; }
        }
        list.insertBefore(card, list.firstChild);
        bump(col, 1); bump(origin, -1);
        card.classList.add('saving');
        var body = new FormData();
        body.append('action', 'board_move');
        body.append('id', card.getAttribute('data-id'));
        body.append('stage_id', col.getAttribute('data-stage'));
        body.append('reason', reason);
        if (csrf) { body.append('csrf', csrf.value); }
        fetch(window.location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .catch(function () { return { ok: false, msg: 'The connection dropped; nothing was changed.' }; })
          .then(function (res) {
            card.classList.remove('saving');
            if (res && res.ok) { say(res.msg || 'Moved.'); return; }
            origin.querySelector('.kb-list').insertBefore(card, origin.querySelector('.kb-list').firstChild);
            bump(origin, 1); bump(col, -1);
            say((res && res.msg) || 'That move is not allowed.', true);
          });
      });
    });
  });

  /* ----------------------------------------------------------- pop-up
     An application opens over the page instead of replacing it. The page
     inside is the ordinary application page in its chrome-less "embed" form,
     so every tab and action on it works as usual. If anything was done in
     it, the page underneath reloads on close to show the change. */
  if (window.self === window.top) {
    var pop = null;
    var openPop = function (href, title, full) {
      if (!pop) {
        pop = document.createElement('div');
        pop.className = 'inc-pop';
        pop.innerHTML = '<div class="inc-pop-shade" data-popclose></div><div class="inc-pop-box" role="dialog" aria-modal="true">'
          + '<div class="inc-pop-head"><strong class="inc-pop-title"></strong><a class="btn btn-sm inc-pop-full" target="_top">Open full page ↗</a>'
          + '<button type="button" class="inc-pop-x" data-popclose aria-label="Close">✕</button></div><iframe class="inc-pop-frame" title="Application"></iframe></div>';
        document.body.appendChild(pop);
        pop.addEventListener('click', function (ev) { if (ev.target.closest('[data-popclose]')) { closePop(); } });
        pop.querySelector('iframe').addEventListener('load', function () { pop.loads = (pop.loads || 0) + 1; });
      }
      pop.loads = 0;
      pop.querySelector('.inc-pop-title').textContent = title || '';
      pop.querySelector('.inc-pop-full').href = full || href;
      pop.querySelector('iframe').src = href + (href.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
      pop.hidden = false;
      document.documentElement.classList.add('inc-pop-on');
      pop.querySelector('.inc-pop-x').focus();
    };
    var closePop = function () {
      if (!pop || pop.hidden) { return; }
      var changed = pop.loads > 1;
      pop.hidden = true;
      pop.querySelector('iframe').src = 'about:blank';
      document.documentElement.classList.remove('inc-pop-on');
      if (changed) { window.location.reload(); }
    };
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest && ev.target.closest('a[data-popup]');
      if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) { return; }
      ev.preventDefault();
      // data-popup may name a lighter page for the pop-up; "Open full page" is the link itself.
      openPop(a.getAttribute('data-popup') || a.getAttribute('href'), a.textContent.trim(), a.getAttribute('href'));
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { closePop(); } });
  }

  // A column's own "select all": ticks every card in it, so a whole stage can be emailed at once.
  document.querySelectorAll('[data-colall]').forEach(function (box) {
    box.addEventListener('change', function () {
      box.closest('.kb-col').querySelectorAll('input.kb-sel').forEach(function (c) {
        if (c.checked !== box.checked) { c.checked = box.checked; c.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
  });

  document.querySelectorAll('[data-allpresent]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.form.querySelectorAll('select[name^="st["]').forEach(function (s) { if (!s.value) { s.value = 'present'; } });
    });
  });

  // Tabs inside a card (the dashboard breakdowns).
  document.querySelectorAll('[data-tabs]').forEach(function (box) {
    var btns = box.querySelectorAll('[data-tab]');
    var show = function (k) {
      btns.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === k); b.setAttribute('aria-selected', b.getAttribute('data-tab') === k ? 'true' : 'false'); });
      box.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== k; });
    };
    btns.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-tab')); }); });
    if (btns.length) { show(btns[0].getAttribute('data-tab')); }
  });

  /* ------------------------------------------------------ form builder */
  document.querySelectorAll('.inc-fieldedit').forEach(function (f) {
    var type = f.querySelector('[data-ftype]');
    var sync = function () {
      f.querySelectorAll('[data-show]').forEach(function (el) {
        el.hidden = el.getAttribute('data-show').split(' ').indexOf(type.value) === -1;
      });
    };
    type.addEventListener('change', sync);
    sync();
  });

  // Live total of the default pillar weights.
  var total = document.querySelector('[data-weight-total]');
  if (total) {
    var inputs = document.querySelectorAll('[data-weight]');
    var sum = function () {
      var s = 0;
      inputs.forEach(function (i) { s += parseFloat(i.value || '0') || 0; });
      total.textContent = (Math.round(s * 100) / 100).toString();
      total.parentNode.style.color = Math.abs(s - 100) < 0.001 ? '' : '#b3261e';
    };
    inputs.forEach(function (i) { i.addEventListener('input', sum); });
    sum();
  }

  // Gem questions: add, remove and reorder rows. Indexes only need to be unique;
  // the server keeps the rows in the order they arrive.
  document.querySelectorAll("[data-q-editor]").forEach(function (box) {
    var body = box.querySelector("tbody");
    var tpl = box.querySelector("template[data-q-template]");
    var next = body.querySelectorAll("[data-q-row]").length;
    var sync = function () {
      var rows = body.querySelectorAll("[data-q-row]");
      rows.forEach(function (r, i) {
        r.querySelector("[data-q-n]").textContent = (i + 1) + ".";
        r.querySelector("[data-q-move=\"-1\"]").disabled = i === 0;
        r.querySelector("[data-q-move=\"1\"]").disabled = i === rows.length - 1;
      });
    };
    var add = function () {
      var html = tpl.innerHTML.replace(/\[999\]/g, "[" + (next++) + "]");
      var t = document.createElement("tbody");
      t.innerHTML = html;
      var row = t.firstElementChild;
      body.appendChild(row);
      row.querySelectorAll("button[hidden]").forEach(function (b) { b.hidden = false; });
      sync();
      return row;
    };
    box.querySelectorAll("button[hidden]").forEach(function (b) { b.hidden = false; });
    // Script is on, so the spare blank rows are not needed: keep just one.
    var blanks = Array.prototype.filter.call(body.querySelectorAll("[data-q-row]"), function (r) {
      return r.querySelector("input[type=text]").value.trim() === "" && r.querySelector("input[type=hidden]").value === "";
    });
    blanks.slice(body.querySelectorAll("[data-q-row]").length > blanks.length ? 0 : 1).forEach(function (r) { r.remove(); });
    box.addEventListener("click", function (ev) {
      var b = ev.target.closest("button");
      if (!b || !box.contains(b)) { return; }
      var row = b.closest("[data-q-row]");
      if (b.hasAttribute("data-q-add")) {
        add().querySelector("input[type=text]").focus();
      } else if (b.hasAttribute("data-q-del") && row) {
        if (body.querySelectorAll("[data-q-row]").length === 1) { add(); }
        row.remove();
      } else if (b.hasAttribute("data-q-move") && row) {
        if (b.getAttribute("data-q-move") === "-1" && row.previousElementSibling) {
          body.insertBefore(row, row.previousElementSibling);
        } else if (b.getAttribute("data-q-move") === "1" && row.nextElementSibling) {
          body.insertBefore(row.nextElementSibling, row);
        }
      }
      sync();
    });
    sync();
  });
/* ------------------------------------------ department desk: bulk email
     Every card and list row carries a checkbox of the form "deskMail" (in
     the dialog). Ticking any shows the bar; "Email selected" opens the
     dialog with who it goes to. {name}, {startup}, {ref} insert at the cursor. */
  var dkBar = document.querySelector("[data-deskbar]");
  var dkDlg = document.getElementById("deskMailDlg");
  if (dkBar && dkDlg) {
    var dkBoxes = function () { return Array.prototype.slice.call(document.querySelectorAll("input[form=\"deskMail\"][name=\"ids[]\"]")); };
    var dkPicked = function () { return dkBoxes().filter(function (b) { return b.checked; }); };
    var dkSync = function () {
      var n = dkPicked().length;
      document.querySelectorAll("[data-dkcount]").forEach(function (el) { el.textContent = n; });
      document.querySelectorAll("[data-dkpl]").forEach(function (el) { el.textContent = n === 1 ? "" : "s"; });
      dkBar.hidden = n === 0;
      document.documentElement.classList.toggle("dk-bar-on", n > 0);
    };
    document.addEventListener("change", function (ev) {
      if (ev.target.matches && ev.target.matches("input[form=\"deskMail\"]")) { dkSync(); }
    });
    dkBar.querySelector("[data-dkclear]").addEventListener("click", function () {
      dkBoxes().forEach(function (b) { b.checked = false; });
      document.querySelectorAll("[data-colall], [data-checkall]").forEach(function (b) { b.checked = false; });
      dkSync();
    });
    dkBar.querySelector("[data-dkopen]").addEventListener("click", function () {
      var to = dkDlg.querySelector("[data-dkto]");
      to.innerHTML = "";
      dkPicked().forEach(function (b) {
        var chip = document.createElement("span");
        chip.className = "dk-chip" + (b.getAttribute("data-email") ? "" : " no-mail");
        chip.title = b.getAttribute("data-email") || "No email address: this one is skipped";
        chip.textContent = b.getAttribute("data-name") || b.value;
        to.appendChild(chip);
      });
      if (dkDlg.showModal) { dkDlg.showModal(); } else { dkDlg.setAttribute("open", ""); }
      var first = dkDlg.querySelector("input[name=\"mail_subject\"]");
      if (first) { first.focus(); }
    });
    dkDlg.addEventListener("click", function (ev) {
      if (ev.target === dkDlg || (ev.target.closest && ev.target.closest("[data-dkclose]"))) { dkDlg.close ? dkDlg.close() : dkDlg.removeAttribute("open"); }
    });
    dkSync();
  }
  document.addEventListener("click", function (ev) {
    var t = ev.target.closest && ev.target.closest(".dk-token");
    if (!t) { return; }
    var form = t.closest("form");
    var area = form && (form.dkLast || form.querySelector("textarea[name=\"mail_body\"]"));
    if (!area) { return; }
    var s = area.selectionStart || 0, e = area.selectionEnd || 0, tok = t.getAttribute("data-token");
    area.value = area.value.slice(0, s) + tok + area.value.slice(e);
    area.focus();
    area.selectionStart = area.selectionEnd = s + tok.length;
  });
  document.addEventListener("focusin", function (ev) {
    var f = ev.target.closest && ev.target.closest(".dk-mailform");
    if (f && (ev.target.name === "mail_subject" || ev.target.name === "mail_body")) { f.dkLast = ev.target; }
  });
})();
