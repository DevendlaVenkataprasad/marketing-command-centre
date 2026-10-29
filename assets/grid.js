/* Shared grid behaviour: in-place edit, autosave, live sync, add, delete, history. */
(function () {
  'use strict';

  var cfg = window.FABO_GRID;
  if (!cfg) { return; }

  var body     = document.getElementById('gridbody');
  var stateEl  = document.getElementById('savestate');
  var drawer   = document.getElementById('drawer');
  var drawerBd = document.getElementById('drawerbody');
  var inFlight = 0;
  var errored  = false;

  function q(params) {
    var p = new URLSearchParams(params);
    if (cfg.token) { p.set('k', cfg.token); }
    /* From the admin portal, say so: the same browser may also be signed in
       to a team member's portal, and these calls are the admin's. */
    if (cfg.admin) { p.set('as', 'admin'); }
    p.set('sheet', cfg.sheet);
    return p;
  }

  function post(url, params) {
    var p = q(params);
    p.set('csrf', cfg.csrf);
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'fetch',
        'Accept': 'application/json'
      },
      body: p.toString()
    }).then(function (r) {
      /* A PHP error comes back as a page, not JSON. Say so with the status
         rather than calling it a network error, which it is not. */
      return r.text().then(function (t) {
        try { return JSON.parse(t); }
        catch (e) { return { ok: false, error: 'Server error (' + r.status + '). Nothing was changed.' }; }
      });
    });
  }

  function get(url, params) {
    return fetch(url + '?' + q(params).toString(), {
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  /* ------------------------------------------------------------- state */

  function setState(kind, text) {
    if (!stateEl) { return; }
    stateEl.className = 'savestate on ' + kind;
    stateEl.textContent = text;
    if (kind === 'saved') {
      setTimeout(function () {
        if (inFlight === 0 && !errored) { stateEl.className = 'savestate'; }
      }, 1600);
    }
  }

  function beginSave() { inFlight++; errored = false; setState('saving', 'Saving…'); }
  function endSave(ok, msg) {
    inFlight = Math.max(0, inFlight - 1);
    if (!ok) { errored = true; setState('error', msg || 'Could not save'); return; }
    if (inFlight === 0) { setState('saved', 'Saved'); }
  }

  /* -------------------------------------------------------- cell paint */

  /** Repaints one row from a server payload, leaving the focused cell alone. */
  function paintRow(tr, payload) {
    var active = document.activeElement;
    cfg.columns.forEach(function (col) {
      var td = tr.querySelector('td[data-field="' + col.field + '"]');
      if (!td) { return; }
      var input = td.querySelector('.js-cell');
      if (input) {
        if (input !== active) {
          var raw = payload.raw[col.field];
          if (input.value !== raw) { input.value = raw === null ? '' : raw; }
        }
      } else {
        var span = td.querySelector('.cell');
        if (span) { span.textContent = payload.disp[col.field] || ''; }
      }
      if (col.field === 'rag_status') {
        td.className = td.className.replace(/\brag-\w+\b/g, '').trim();
        var rag = ragClass(payload.disp[col.field] || '');
        if (rag) { td.className += ' ' + rag; }
      }
      // Tone follows the value: a status change recolours the cell at once.
      td.className = td.className.replace(/\btone-\w+\b/g, '').trim();
      var tone = payload.tone && payload.tone[col.field];
      if (tone) { td.className += ' ' + tone; }
    });
  }

  function ragClass(v) {
    if (v === 'Red - Overdue')    { return 'rag-red'; }
    if (v === 'Amber - Due Soon') { return 'rag-amber'; }
    if (v === 'Green - On Track') { return 'rag-green'; }
    if (v === 'Done')             { return 'rag-done'; }
    return '';
  }

  function renumber() {
    Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr, i) {
      var rn = tr.querySelector('.rn');
      if (rn) { rn.textContent = i + 1; }
    });
  }

  /* ------------------------------------------------------------ saving */

  function saveCell(td, input) {
    var tr = td.closest('tr');
    beginSave();
    post(cfg.api + 'save.php', {
      id: tr.getAttribute('data-id'),
      field: td.getAttribute('data-field'),
      value: input.value
    }).then(function (res) {
      if (!res.ok) { endSave(false, res.error); return; }
      paintRow(tr, res.row);
      endSave(true);
      // Some cells do more than store a value — say so rather than just "Saved".
      if (res.notice) { showNotice(res.notice); }
    }).catch(function () { endSave(false, 'Network error'); });
  }

  /**
   * Deleting is the administrator's to do. When the server refuses, this turns
   * the refusal into the next step: say why, and send the request.
   *
   * `extra` carries whatever identifies the thing — a row id, or a row id and
   * an attachment id — so the same box serves both.
   */
  function askToDelete(res, endpoint, extra) {
    var box = document.getElementById('delask');
    if (!box) {
      box = document.createElement('div');
      box.id = 'delask';
      box.className = 'drawer';
      box.innerHTML = '<div class="dhead"><strong>Request a deletion</strong>' +
                      '<button type="button" class="dclose" id="delaskx">&times;</button></div>' +
                      '<div class="dbody" id="delaskbody"></div>';
      document.body.appendChild(box);
      box.addEventListener('click', function (ev) {
        if (ev.target.id === 'delaskx') { box.classList.remove('on'); }
      });
    }
    var body = document.getElementById('delaskbody');
    body.innerHTML =
      '<div class="askedbox"><strong>' + esc(res.error) + '</strong>' +
      (res.label ? '<div class="note">' + esc(res.label) + '</div>' : '') +
      (res.already_asked
        ? '<div class="who">A request for this is already waiting. Sending again will remind them.</div>'
        : '') +
      '</div>' +
      '<div class="delask">' +
      '<label style="font-size:12.5px;font-weight:600;color:var(--ink-soft)">' +
      'Why should this be removed?</label>' +
      '<textarea id="delaskwhy" placeholder="The administrator reads this before deciding."></textarea>' +
      '<div class="row"><button class="btn btn-primary btn-sm" id="delasksend">Send request</button>' +
      '<button class="btn btn-sm" id="delaskcancel">Cancel</button></div>' +
      '<div class="optmsg" id="delaskmsg"></div></div>';

    box.classList.add('on');
    var why = document.getElementById('delaskwhy');
    if (why) { why.focus(); }

    document.getElementById('delaskcancel').onclick = function () { box.classList.remove('on'); };
    document.getElementById('delasksend').onclick = function () {
      var params = { action: endpoint === 'delrow.php' ? 'request' : 'delete_request',
                     reason: why ? why.value.trim() : '' };
      Object.keys(extra).forEach(function (k) { params[k] = extra[k]; });
      beginSave();
      post(cfg.api + endpoint, params).then(function (r) {
        if (!r.ok) { endSave(false, r.error); return; }
        endSave(true);
        box.classList.remove('on');
        showNotice(r.message || 'Request sent.');
      }).catch(function () { endSave(false, 'Network error'); });
    };
  }

  /* A sentence about what a save actually did — who was emailed, and that a
     notice reached their dashboard. It stays until dismissed or superseded. */
  function showNotice(text) {
    var bar = document.getElementById('gridnotice');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'gridnotice';
      bar.className = 'gridnotice';
      document.body.appendChild(bar);
      bar.addEventListener('click', function () { bar.classList.remove('on'); });
    }
    bar.textContent = text;
    bar.classList.add('on');
    clearTimeout(bar.__t);
    bar.__t = setTimeout(function () { bar.classList.remove('on'); }, 9000);
  }

  if (body && cfg.perm === 'edit') {
    body.addEventListener('change', function (ev) {
      var input = ev.target.closest('.js-cell');
      if (!input) { return; }
      saveCell(input.closest('td'), input);
    });
  }

  /* ------------------------------------------------------------- rows */

  var addBtn = document.getElementById('addrow');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      beginSave();
      var pick = document.getElementById('clientpick');
      post(cfg.api + 'addrow.php', pick && pick.value ? { client: pick.value } : {}).then(function (res) {
        if (!res.ok) { endSave(false, res.error); return; }
        endSave(true);
        window.location.reload();
      }).catch(function () { endSave(false, 'Network error'); });
    });
  }

  if (body) {
    body.addEventListener('click', function (ev) {
      var del = ev.target.closest('.js-del');
      if (del) {
        var tr = del.closest('tr');
        if (!window.confirm('Delete this row? This cannot be undone.')) { return; }
        beginSave();
        post(cfg.api + 'delrow.php', { id: tr.getAttribute('data-id') }).then(function (res) {
          if (!res.ok) {
            endSave(false, res.error);
            // Not allowed to delete: offer to ask instead of stopping here.
            if (res.needs_request) { askToDelete(res, 'delrow.php', { id: tr.getAttribute('data-id') }); }
            return;
          }
          tr.remove();
          renumber();
          endSave(true);
        }).catch(function () { endSave(false, 'Network error'); });
        return;
      }

      var hist = ev.target.closest('.js-history');
      if (hist) {
        var row = hist.closest('tr');
        drawerBd.innerHTML = '<div class="empty">Loading…</div>';
        drawer.classList.add('on');
        get(cfg.api + 'history.php', { id: row.getAttribute('data-id') }).then(function (res) {
          if (!res.ok || !res.entries.length) {
            drawerBd.innerHTML = '<div class="empty">No changes recorded for this row yet.</div>';
            return;
          }
          drawerBd.innerHTML = res.entries.map(function (h) {
            var from = h.old === null || h.old === '' ? '<em style="color:#8a93a3">blank</em>'
                                                      : '<del>' + esc(h.old) + '</del>';
            var to   = h.new === null || h.new === '' ? '<em style="color:#8a93a3">blank</em>'
                                                      : '<ins>' + esc(h.new) + '</ins>';
            return '<div class="entry"><div class="meta">' + esc(h.when) + ' · ' + esc(h.who) +
                   '</div><strong>' + esc(h.field) + '</strong><br>' + from + ' → ' + to + '</div>';
          }).join('');
        });
      }
    });
  }

  /* The portal root, relative to this page. cfg.api is '../api/' on an admin
     page and 'api/' elsewhere, so stripping 'api/' leaves exactly the hop
     back to the root. An href the server hands us is root-relative and has
     to be resolved against that, not against the page. */
  function rootUrl(href) {
    if (!href || /^(https?:)?\/\//.test(href)) { return href; }
    return cfg.api.replace(/api\/$/, '') + href;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* --------------------------------------------------------- live sync */

  function sync() {
    if (document.hidden || inFlight > 0) { return; }
    get(cfg.api + 'rows.php', {}).then(function (res) {
      if (!res.ok) { return; }
      var seen = {};
      res.rows.forEach(function (payload) {
        seen[payload._id] = true;
        var tr = body.querySelector('tr[data-id="' + payload._id + '"]');
        if (tr) { paintRow(tr, payload); }
      });
      // A row added or removed elsewhere changes the row set: reload, but only
      // while nothing here has focus.
      /* Compared with the sheet's whole row count where the page gave one:
         a filtered view, or Upcoming / Completed Events, draws fewer rows
         than the server sends, and that is not a change. */
      var mine = (typeof cfg.total === 'number') ? cfg.total
                                                 : body.querySelectorAll('tr').length;
      var focused = document.activeElement && document.activeElement.closest &&
                    document.activeElement.closest('#gridbody');
      if (!focused && mine !== res.rows.length) { window.location.reload(); }
    }).catch(function () { /* a dropped poll is not worth showing */ });
  }

  if (body) { setInterval(sync, 7000); }

  /* A row arrived from an intake form: scroll to it and flash it. */
  if (body && cfg.focusRow) {
    var target = body.querySelector('tr[data-id="' + cfg.focusRow + '"]');
    if (target) {
      target.classList.add('justadded');
      target.scrollIntoView({ block: 'center', behavior: 'smooth' });
      setTimeout(function () { target.classList.remove('justadded'); }, 6000);
    }
  }

  /* ================================================ customising tools */
  /* Who may do what is the server's answer, not "is this the admin".
     Two separate rights, because they are not equally weighty:
       cfg.customise  — shape a dropdown's options (anyone who may edit)
       cfg.renameCols — rename a heading (the head of the organisation)
     Both are scoped to the viewer's own organisation server-side. */
  if (!cfg.customise) { return; }

  /* ---- 1. Click a column header to rename it in place ---- */
  var thead = cfg.renameCols ? document.querySelector('table.grid thead') : null;
  if (thead) {
    thead.addEventListener('click', function (ev) {
      if (ev.target.closest('.js-opts')) { return; }
      var th = ev.target.closest('th.renamable');
      if (!th || th.querySelector('input.hedit')) { return; }
      var label = th.querySelector('.hlabel');
      var current = label.textContent;
      var input = document.createElement('input');
      input.className = 'hedit';
      input.value = current;
      input.placeholder = th.getAttribute('data-default');
      input.title = 'Enter to save. Esc to cancel. Empty resets to "' + th.getAttribute('data-default') + '"';
      label.style.display = 'none';
      label.parentNode.insertBefore(input, label);
      input.focus(); input.select();

      var done = false;
      function finish(save) {
        if (done) { return; } done = true;
        var v = input.value.trim();
        input.remove();
        label.style.display = '';
        if (!save || v === current) { return; }
        beginSave();
        post(cfg.api + 'column.php', { field: th.getAttribute('data-field'), label: v }).then(function (res) {
          if (!res.ok) { endSave(false, res.error); return; }
          label.textContent = res.label;
          th.classList.toggle('renamed', !res.is_default);
          endSave(true);
        }).catch(function () { endSave(false, 'Network error'); });
      }
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); finish(true); }
        if (e.key === 'Escape') { finish(false); }
      });
      input.addEventListener('blur', function () { finish(true); });
    });
    Array.prototype.forEach.call(thead.querySelectorAll('th.renamable'), function (th) {
      var l = th.querySelector('.hlabel');
      if (l && l.textContent !== th.getAttribute('data-default')) { th.classList.add('renamed'); }
    });
  }

  /* ---- 2. Repaint every <select> of a list after its options change ---- */
  var ADD = '__add__';
  var MANAGE = '__manage__';
  function repaintList(listName, active) {
    Array.prototype.forEach.call(body.querySelectorAll('td[data-list="' + listName + '"] select'), function (sel) {
      var cur = sel.value;
      sel.innerHTML = '';
      sel.appendChild(new Option('', ''));
      var seen = false;
      active.forEach(function (v) { sel.appendChild(new Option(v, v)); if (v === cur) { seen = true; } });
      if (cur && cur !== ADD && !seen) { sel.appendChild(new Option(cur, cur)); }
      var sep = new Option('────────', ''); sep.disabled = true; sel.appendChild(sep);
      sel.appendChild(new Option('＋ Add option…', ADD));
      sel.appendChild(new Option('－ Remove / edit options…', MANAGE));
      sel.value = (cur === ADD || cur === MANAGE) ? '' : cur;
    });
  }

  /* ---- 3. "+ Add option" inside any dropdown cell ---- */
  if (body) {
    body.addEventListener('change', function (ev) {
      var sel = ev.target;
      if (!sel.matches('select.js-cell') || (sel.value !== ADD && sel.value !== MANAGE)) { return; }
      ev.stopImmediatePropagation();                     // do not autosave the marker
      var td = sel.closest('td');
      var listName = td.getAttribute('data-list');
      var prev = sel.getAttribute('data-prev') || '';
      if (sel.value === MANAGE) {                        // open the options panel for this list
        sel.value = prev;
        openOpts(listName);
        return;
      }
      var v = window.prompt('New option for "' + listName + '":');
      if (!v || !v.trim()) { sel.value = prev; return; }
      beginSave();
      post(cfg.api + 'listopts.php', { list: listName, action: 'add', value: v.trim() }).then(function (res) {
        if (!res.ok) { endSave(false, res.error); sel.value = prev; return; }
        repaintList(listName, res.active);
        sel.value = v.trim();
        endSave(true);
        saveCell(td, sel);                               // and store it on this row
      }).catch(function () { endSave(false, 'Network error'); sel.value = prev; });
    }, true);                                            // capture: runs before autosave
    body.addEventListener('focusin', function (ev) {
      var sel = ev.target;
      if (sel.matches && sel.matches('select.js-cell')) { sel.setAttribute('data-prev', sel.value); }
    });
  }


  /* ---- 5. File and image columns on a built sheet ---- */
  if (body) {
    function cellFileCall(td, form, then) {
      var tr = td.closest('tr');
      form.append('csrf', cfg.csrf);
      form.append('sheet', cfg.sheet);
      form.append('id', tr.getAttribute('data-id'));
      form.append('field', td.getAttribute('data-field'));
      if (cfg.token) { form.append('k', cfg.token); }
      beginSave();
      fetch(cfg.api + 'cellfile.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: form
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) { endSave(false, res.error); return; }
        endSave(true);
        then(res);
      }).catch(function () { endSave(false, 'Network error'); });
    }

    /* Redraws the cell around whatever it now holds. */
    function paintCellFile(box, res) {
      var kind = box.getAttribute('data-kind');
      box.innerHTML = '';
      if (res && res.id) {
        if (kind === 'image' && res.image) {
          var a = document.createElement('a');
          a.href = rootUrl(res.href); a.target = '_blank'; a.rel = 'noopener';
          var img = document.createElement('img');
          img.src = rootUrl(res.href); img.alt = res.name;
          a.appendChild(img);
          box.appendChild(a);
        } else {
          var link = document.createElement('a');
          link.href = rootUrl(res.href); link.target = '_blank'; link.rel = 'noopener';
          link.className = 'cellfilename';
          link.textContent = res.name;
          box.appendChild(link);
        }
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'cellfilex js-cellfiledel';
        x.title = 'Take this off the row';
        x.innerHTML = '&times;';
        box.appendChild(x);
      }
      var label = document.createElement('label');
      label.className = 'cellfileadd';
      label.textContent = (res && res.id) ? 'Replace'
                        : (kind === 'image' ? 'Add image' : 'Add file');
      var input = document.createElement('input');
      input.type = 'file';
      input.className = 'js-cellfile';
      input.hidden = true;
      if (kind === 'image') { input.accept = 'image/*'; }
      label.appendChild(input);
      box.appendChild(label);
    }

    body.addEventListener('change', function (ev) {
      var input = ev.target;
      if (!input.classList || !input.classList.contains('js-cellfile')) { return; }
      if (!input.files || !input.files.length) { return; }
      var box = input.closest('.cellfile');
      var td  = input.closest('td');
      var form = new FormData();
      form.append('file', input.files[0]);
      cellFileCall(td, form, function (res) { paintCellFile(box, res); });
    });

    body.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.js-cellfiledel') : null;
      if (!b) { return; }
      ev.preventDefault();
      if (!window.confirm('Take this off the row? The file is deleted.')) { return; }
      var box = b.closest('.cellfile');
      var td  = b.closest('td');
      var form = new FormData();
      form.append('action', 'clear');
      cellFileCall(td, form, function () { paintCellFile(box, null); });
    });

    /* A tick box saves as 1 or 0, like any other cell. */
    body.addEventListener('change', function (ev) {
      var box = ev.target;
      if (!box.classList || !box.classList.contains('js-cellbox')) { return; }
      var td = box.closest('td');
      beginSave();
      post(cfg.api + 'save.php', {
        id: td.closest('tr').getAttribute('data-id'),
        field: td.getAttribute('data-field'),
        value: box.checked ? '1' : '0'
      }).then(function (res) {
        if (!res.ok) { endSave(false, res.error); return; }
        endSave(true);
      }).catch(function () { endSave(false, 'Network error'); });
    });
  }

  /* ---- 4. Gear on a header: manage the options in a side panel ---- */
  var od = document.getElementById('optsdrawer');
  var ob = document.getElementById('optsbody');
  var ot = document.getElementById('optstitle');

  function renderOpts(listName, res) {
    ot.textContent = listName;
    var h = (res.scope ? '<div class="optscope">' + esc(res.scope) + '</div>' : '') +
            '<div class="optmsg" id="optmsg">' + esc(res.message || '') + '</div>' +
            '<form class="optadd" id="optadd"><input placeholder="Add an option…" required autocomplete="off">' +
            '<button class="btn btn-primary btn-sm">Add</button></form>';
    if (!res.options.length) { h += '<div class="empty">No options yet.</div>'; }
    res.options.forEach(function (o, i) {
      h += '<div class="optrow' + (o.active ? '' : ' hidden') + '" data-id="' + o.id + '">' +
           '<input class="oval" value="' + esc(o.value) + '" data-was="' + esc(o.value) + '" ' +
           'title="Edit the name, then press Save">' +
           '<button data-act="rename" class="osave" hidden title="Save the new name">Save</button>' +
           '<button data-act="up" title="Move up"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>' +
           '<button data-act="down" title="Move down"' + (i === res.options.length - 1 ? ' disabled' : '') + '>&darr;</button>' +
           '<button data-act="toggle" title="' + (o.active ? 'Hide from the dropdown (rows keep it)' : 'Show again') + '">' + (o.active ? 'Hide' : 'Show') + '</button>' +
           '<button data-act="remove" class="danger" title="Remove from the list (no row is changed)">Remove</button>' +
           '</div>';
    });
    h += '<div class="hint" style="margin-top:10px">Everything here saves as you do it. ' +
         'A renamed option needs its <b>Save</b> button — it appears once you change the text. ' +
         '<b>Hide</b> stops offering a value but keeps it on rows that have it. ' +
         '<b>Remove</b> deletes it from the list only. Data rows are never altered.</div>';
    ob.innerHTML = h;
    repaintList(listName, res.active);
  }

  function optsCall(listName, params) {
    return post(cfg.api + 'listopts.php', Object.assign({ list: listName }, params));
  }

  function openOpts(listName) {
    ob.innerHTML = '<div class="empty">Loading…</div>';
    od.classList.add('on');
    od.setAttribute('data-list', listName);
    get(cfg.api + 'listopts.php', { list: listName }).then(function (res) {
      if (!res.ok) { ob.innerHTML = '<div class="empty">' + esc(res.error) + '</div>'; return; }
      renderOpts(listName, res);
    });
  }
  document.addEventListener('click', function (ev) {
    var g = ev.target.closest('.js-opts');
    if (g) { openOpts(g.getAttribute('data-list')); }
  });

  if (od) {
    od.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var listName = od.getAttribute('data-list');
      var inp = ev.target.querySelector('input');
      optsCall(listName, { action: 'add', value: inp.value }).then(function (res) {
        if (!res.ok) { document.getElementById('optmsg').textContent = res.error; return; }
        renderOpts(listName, res);
        ob.querySelector('#optadd input').focus();
      });
    });
    od.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-act]');
      if (!b) { return; }
      var row = b.closest('.optrow');
      var listName = od.getAttribute('data-list');
      var act = b.getAttribute('data-act');
      if (act === 'remove' && !window.confirm('Remove this option from the dropdown? Rows already using it are not changed.')) { return; }
      // A rename is the one action that carries a value: the edited text.
      var args = { action: act, id: row.getAttribute('data-id') };
      if (act === 'rename') { args.value = row.querySelector('input.oval').value; }
      optsCall(listName, args).then(function (res) {
        if (!res.ok) { document.getElementById('optmsg').textContent = res.error; return; }
        renderOpts(listName, res);
      });
    });
    /* The Save button only exists once there is something to save, so the
       panel does not sit there offering to save what has not been touched. */
    od.addEventListener('input', function (ev) {
      if (!ev.target.matches || !ev.target.matches('input.oval')) { return; }
      var save = ev.target.closest('.optrow').querySelector('.osave');
      if (save) { save.hidden = ev.target.value.trim() === ev.target.getAttribute('data-was'); }
    });

    // Enter still works, for anyone who never reaches for the mouse.
    od.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' || !ev.target.matches('input.oval')) { return; }
      ev.preventDefault();
      var save = ev.target.closest('.optrow').querySelector('.osave');
      if (save && !save.hidden) { save.click(); }     // nothing changed, nothing to send
    });
  }


  /* ---- 6. The composer: one line to fill in, then Save ----
     Add row used to drop an empty row into the grid and leave you to hunt
     across it. This opens a single line with every field on it — uploads
     included — and creates the row once, complete. */
  (function () {
    var bar = document.getElementById('addrow');
    if (!bar || cfg.perm !== 'edit') { return; }

    var head = document.querySelector('table.grid thead tr');
    var open = false;
    var draft = '';                       // set by the first upload

    function cell(col, td) {
      if (col.type === 'file' || col.type === 'image') {
        var box = document.createElement('div');
        box.className = 'cellfile';
        box.setAttribute('data-kind', col.type);
        box.setAttribute('data-new', '1');
        var label = document.createElement('label');
        label.className = 'cellfileadd';
        label.textContent = col.type === 'image' ? 'Add image' : 'Add file';
        var f = document.createElement('input');
        f.type = 'file';
        f.className = 'js-newfile';
        f.hidden = true;
        if (col.type === 'image') { f.accept = 'image/*'; }
        label.appendChild(f);
        box.appendChild(label);
        td.appendChild(box);
        return;
      }
      if (col.type === 'checkbox') {
        var c = document.createElement('input');
        c.type = 'checkbox';
        c.className = 'js-new js-cellbox';
        c.setAttribute('data-field', col.field);
        td.appendChild(c);
        return;
      }
      if (col.list) {
        var sel = document.createElement('select');
        sel.className = 'js-new';
        sel.setAttribute('data-field', col.field);
        sel.appendChild(new Option('', ''));
        (window.FABO_LISTS && window.FABO_LISTS[col.list] || []).forEach(function (v) {
          sel.appendChild(new Option(v, v));
        });
        td.appendChild(sel);
        return;
      }
      var i = document.createElement('input');
      i.className = 'js-new';
      i.setAttribute('data-field', col.field);
      i.type = col.type === 'date' ? 'date'
             : (col.type === 'int' || col.type === 'dec' || col.type === 'money' ? 'number' : 'text');
      if (col.type === 'dec' || col.type === 'money') { i.step = '0.01'; }
      td.appendChild(i);
    }

    function build() {
      var tr = document.createElement('tr');
      tr.id = 'composer';
      tr.className = 'composer';

      /* Save and Cancel live in the pinned first cell as well as under the
         line, so they are in reach however far across the sheet you are. */
      var first = document.createElement('td');
      first.className = 'corner';
      first.innerHTML = '<span class="composertag">New</span>' +
        '<button type="button" class="btn btn-primary btn-sm js-csave" title="Save this row (Enter)">Save</button>' +
        '<button type="button" class="btn btn-sm js-ccancel" title="Cancel (Esc)">&times;</button>';
      tr.appendChild(first);

      cfg.columns.forEach(function (col) {
        var td = document.createElement('td');
        td.setAttribute('data-field', col.field);
        if (col.type === 'computed' || col.type === 'id') {
          td.innerHTML = '<span class="ro">auto</span>';
        } else {
          cell(col, td);
        }
        tr.appendChild(td);
      });
      return tr;
    }

    function close() {
      var tr = document.getElementById('composer');
      if (tr) { tr.remove(); }
      var act = document.getElementById('composeract');
      if (act) { act.remove(); }
      open = false;
      document.dispatchEvent(new CustomEvent('fabo:gridchange'));
      draft = '';
    }

    function actions() {
      var tr = document.createElement('tr');
      tr.id = 'composeract';
      tr.className = 'composeract';
      var td = document.createElement('td');
      td.colSpan = cfg.columns.length + 1;
      td.innerHTML = '<div class="composerbar">' +
                     '<button type="button" class="btn btn-primary btn-sm" id="composersave">Save row</button>' +
                     '<button type="button" class="btn btn-sm" id="composercancel">Cancel</button>' +
                     '<span class="composermsg" id="composermsg"></span>' +
                     '<span class="composerhint">Tab to move across &middot; Enter to save &middot; Esc to cancel</span>' +
                     '</div>';
      tr.appendChild(td);
      return tr;
    }

    function say(text, bad) {
      var m = document.getElementById('composermsg');
      if (!m) { return; }
      m.textContent = text || '';
      m.className = 'composermsg' + (bad ? ' bad' : '');
    }

    bar.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      if (open) { close(); return; }
      if (!body) { return; }
      body.insertBefore(build(), body.firstChild);
      body.insertBefore(actions(), body.children[1] || null);
      open = true;
      /* Wherever the sheet had been scrolled to, start the new line at its
         beginning, in view. */
      var gb = body.closest('.gridbox');
      if (gb) {
        gb.scrollTop = 0;
        gb.scrollLeft = 0;
        gb.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
      document.dispatchEvent(new CustomEvent('fabo:gridchange'));
      var f = document.querySelector('#composer .js-new');
      if (f) { f.focus({ preventScroll: true }); }
    }, true);

    document.addEventListener('click', function (ev) {
      var hit = ev.target.closest ? ev.target.closest('#composercancel, .js-ccancel, #composersave, .js-csave') : null;
      if (hit && (hit.id === 'composercancel' || hit.classList.contains('js-ccancel'))) {
        ev.preventDefault();
        /* A file chosen but never saved is dropped, so an abandoned line
           leaves nothing behind. */
        if (draft) {
          cfg.columns.forEach(function (col) {
            if (col.type !== 'file' && col.type !== 'image') { return; }
            var f = new FormData();
            f.append('action', 'clear');
            f.append('csrf', cfg.csrf);
            f.append('sheet', cfg.sheet);
            f.append('id', '0');
            f.append('draft', draft);
            f.append('field', col.field);
            if (cfg.token) { f.append('k', cfg.token); }
            fetch(cfg.api + 'cellfile.php', { method: 'POST', body: f }).catch(function () {});
          });
        }
        close();
        return;
      }
      if (!hit) { return; }
      ev.preventDefault();

      var btn = hit;
      btn.disabled = true;
      say('Saving…');

      var body2 = new URLSearchParams();
      body2.set('csrf', cfg.csrf);
      body2.set('sheet', cfg.sheet);
      if (cfg.token) { body2.set('k', cfg.token); }
      if (draft) { body2.set('draft', draft); }
      var pick = document.getElementById('clientpick');
      if (pick && pick.value) { body2.set('client', pick.value); }

      Array.prototype.forEach.call(document.querySelectorAll('#composer .js-new'), function (el) {
        var v = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        if (v !== '') { body2.set('f[' + el.getAttribute('data-field') + ']', v); }
      });

      fetch(cfg.api + 'newrow.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                   'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: body2.toString()
      }).then(function (r) { return r.json(); }).then(function (res) {
        btn.disabled = false;
        if (!res.ok) { say(res.error || 'That did not save.', true); return; }
        close();
        /* Reload so the new row appears with everything computed, in the
           order the sheet sorts by. */
        location.reload();
      }).catch(function () { btn.disabled = false; say('Could not reach the server.', true); });
    });

    /* Enter on the line saves it; Esc puts it away. A long-text box keeps
       Enter for its own new lines. */
    document.addEventListener('keydown', function (ev) {
      if (!open || !ev.target.closest || !ev.target.closest('#composer')) { return; }
      if (ev.key === 'Escape') {
        ev.preventDefault();
        document.getElementById('composercancel').click();
      } else if (ev.key === 'Enter' && ev.target.tagName !== 'TEXTAREA' && !ev.isComposing) {
        ev.preventDefault();
        document.getElementById('composersave').click();
      }
    });

    /* An upload on the composer's line: staged until the row exists. */
    document.addEventListener('change', function (ev) {
      var input = ev.target;
      if (!input.classList || !input.classList.contains('js-newfile')) { return; }
      if (!input.files || !input.files.length) { return; }
      var box = input.closest('.cellfile');
      var td  = input.closest('td');

      var f = new FormData();
      f.append('file', input.files[0]);
      f.append('csrf', cfg.csrf);
      f.append('sheet', cfg.sheet);
      f.append('id', '0');
      f.append('field', td.getAttribute('data-field'));
      if (draft) { f.append('draft', draft); }
      if (cfg.token) { f.append('k', cfg.token); }

      say('Uploading…');
      fetch(cfg.api + 'cellfile.php', { method: 'POST', body: f })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) { say(res.error || 'That did not upload.', true); return; }
          draft = res.draft || draft;
          say('');
          paintNewFile(box, res);
        }).catch(function () { say('Could not reach the server.', true); });
    });

    /* Taking a staged file off the line again. */
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.js-newfiledel') : null;
      if (!b) { return; }
      ev.preventDefault();
      var box = b.closest('.cellfile');
      var td  = b.closest('td');
      var f = new FormData();
      f.append('action', 'clear');
      f.append('csrf', cfg.csrf);
      f.append('sheet', cfg.sheet);
      f.append('id', '0');
      f.append('draft', draft);
      f.append('field', td.getAttribute('data-field'));
      if (cfg.token) { f.append('k', cfg.token); }
      fetch(cfg.api + 'cellfile.php', { method: 'POST', body: f })
        .then(function (r) { return r.json(); })
        .then(function () { paintNewFile(box, null); })
        .catch(function () { say('Could not reach the server.', true); });
    });

    function paintNewFile(box, res) {
      var kind = box.getAttribute('data-kind');
      box.innerHTML = '';
      if (res && res.id) {
        if (kind === 'image' && res.image) {
          var img = document.createElement('img');
          img.src = res.href; img.alt = res.name;
          box.appendChild(img);
        } else {
          var sp = document.createElement('span');
          sp.className = 'cellfilename';
          sp.textContent = res.name;
          box.appendChild(sp);
        }
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'cellfilex js-newfiledel';
        x.title = 'Take this off';
        x.innerHTML = '&times;';
        box.appendChild(x);
      }
      var label = document.createElement('label');
      label.className = 'cellfileadd';
      label.textContent = (res && res.id) ? 'Replace'
                        : (kind === 'image' ? 'Add image' : 'Add file');
      var f = document.createElement('input');
      f.type = 'file';
      f.className = 'js-newfile';
      f.hidden = true;
      if (kind === 'image') { f.accept = 'image/*'; }
      label.appendChild(f);
      box.appendChild(label);
    }
  })();


  /* ---- 7. The Images column: a gallery in a cell ---- */
  if (body) {
    function rowImgCall(td, form, then) {
      form.append('csrf', cfg.csrf);
      form.append('sheet', cfg.sheet);
      form.append('id', td.closest('tr').getAttribute('data-id'));
      form.append('field', td.getAttribute('data-field'));
      if (cfg.token) { form.append('k', cfg.token); }
      beginSave();
      fetch(cfg.api + 'rowimages.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: form
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) { endSave(false, res.error); return; }
        endSave(true);
        if (res.warn) { showNotice(res.warn); }
        then(res);
      }).catch(function () { endSave(false, 'Network error'); });
    }

    body.addEventListener('change', function (ev) {
      var input = ev.target;
      if (!input.classList || !input.classList.contains('js-rowimg')) { return; }
      if (!input.files || !input.files.length) { return; }
      var box = input.closest('.cellimgs');
      var td  = input.closest('td');
      var form = new FormData();
      for (var i = 0; i < input.files.length; i++) { form.append('images[]', input.files[i]); }
      input.value = '';
      rowImgCall(td, form, function (res) {
        var label = box.querySelector('.cellimgadd');
        (res.images || []).forEach(function (img) {
          var fig = document.createElement('figure');
          fig.setAttribute('data-image', img.id);
          fig.innerHTML = '<img src="' + esc(rootUrl(img.href)) + '" alt="' + esc(img.name) + '">' +
                          '<button type="button" class="cellimgx" title="Remove this image">&times;</button>';
          box.insertBefore(fig, label);
        });
      });
    });

    body.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.cellimgx') : null;
      if (!b) { return; }
      ev.preventDefault();
      if (!window.confirm('Remove this image? The file is deleted.')) { return; }
      var fig = b.closest('figure');
      var td  = b.closest('td');
      var form = new FormData();
      form.append('action', 'drop');
      form.append('image', fig.getAttribute('data-image'));
      rowImgCall(td, form, function () { fig.remove(); });
    });
  }

  /* ============================================ files & approvals */

  var fd = document.getElementById('filesdrawer');
  var fb = document.getElementById('filesbody');
  var ft = document.getElementById('filestitle');
  var rd = document.getElementById('reviewdrawer');
  var rb = document.getElementById('reviewbody');
  var rt = document.getElementById('reviewtitle');

  function rowTitle(tr) {
    var first = tr.querySelector('td[data-field] input, td[data-field] .cell');
    var id = tr.querySelector('td.idcell .cell');
    return (id ? id.textContent.trim() + ' · ' : '') +
           (first ? (first.value !== undefined ? first.value : first.textContent).trim() : '');
  }

  function setFileCount(tr, n) {
    var btn = tr.querySelector('.js-files');
    if (btn) { btn.innerHTML = '\u{1F4CE}' + (n ? '<b>' + n + '</b>' : ''); }
    var td = tr.querySelector('td[data-field="asset_count"] .cell');
    if (td) { td.textContent = n; }
  }

  /* ---- files drawer ---- */
  function renderFiles(tr, res) {
    var h = '';
    if (res.can_edit) {
      h += '<label class="dropzone" id="dz">Drop a file here, or click to choose' +
           '<input type="file" id="dzinput"></label><div class="optmsg" id="fmsg"></div>';
    }
    if (!res.files.length) {
      h += '<div class="empty">No files on this row yet.</div>';
    } else {
      /* Documents first, then pictures -- the same two columns a shared
         sheet fills in, so a file is found where it was put. */
      var docs = res.files.filter(function (f) { return !f.is_image || f.is_doc; });
      var pics = res.files.filter(function (f) { return f.is_image && !f.is_doc; });
      [['Documents', docs], ['Images', pics]].forEach(function (grp) {
        if (!grp[1].length) { return; }
        h += '<div class="filegroup"><h4>' + grp[0] + ' <span>' + grp[1].length + '</span></h4><div class="filelist">';
        grp[1].forEach(function (f) {
          var ext = (f.name.split('.').pop() || '?').slice(0, 4);
          h += '<div class="fileitem" data-id="' + f.id + '">' +
               (f.is_image ? '<img class="thumb" src="' + esc(f.url) + '" alt="">'
                           : '<div class="ficon">' + esc(ext) + '</div>') +
               '<div class="meta"><a href="' + esc(f.url) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a>' +
               '<div class="sub">' + esc(f.size) + ' · ' + esc(f.by) + ' · ' + esc(f.when) + '</div></div>' +
               (res.can_edit ? '<button class="btn btn-sm btn-danger js-delfile">Remove</button>' : '') +
               '</div>';
        });
        h += '</div></div>';
      });
    }
    fb.innerHTML = h;
    setFileCount(tr, res.files.length);
    if (res.can_edit) { wireDrop(tr); }
  }

  function loadFiles(tr) {
    fb.innerHTML = '<div class="empty">Loading…</div>';
    fd.classList.add('on');
    fd.__tr = tr;
    ft.textContent = cfg.assets === 'design' ? 'Design files'
                   : (cfg.assets === 'content' ? 'Content files' : 'Files on this row');
    get(cfg.api + 'attachments.php', { id: tr.getAttribute('data-id') }).then(function (res) {
      if (!res.ok) { fb.innerHTML = '<div class="empty">' + esc(res.error) + '</div>'; return; }
      renderFiles(tr, res);
    });
  }

  function uploadFile(tr, file) {
    var msg = document.getElementById('fmsg');
    if (msg) { msg.textContent = 'Uploading ' + file.name + '…'; }
    var form = new FormData();
    form.append('file', file);
    form.append('csrf', cfg.csrf);
    form.append('sheet', cfg.sheet);
    form.append('id', tr.getAttribute('data-id'));
    if (cfg.token) { form.append('k', cfg.token); }
    beginSave();
    fetch(cfg.api + 'upload.php', {
      method: 'POST', body: form,
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { endSave(false, res.error); if (msg) { msg.textContent = res.error; } return; }
      endSave(true);
      loadFiles(tr);
    }).catch(function () { endSave(false, 'Network error'); });
  }

  function wireDrop(tr) {
    var dz = document.getElementById('dz');
    var inp = document.getElementById('dzinput');
    if (!dz) { return; }
    inp.addEventListener('change', function () {
      if (inp.files && inp.files[0]) { uploadFile(tr, inp.files[0]); }
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('over'); });
    });
    dz.addEventListener('drop', function (e) {
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) { uploadFile(tr, f); }
    });
  }

  if (fd) {
    fd.addEventListener('click', function (ev) {
      var del = ev.target.closest('.js-delfile');
      if (!del) { return; }
      var item = del.closest('.fileitem');
      var tr = fd.__tr;
      if (!window.confirm('Remove this file?')) { return; }
      post(cfg.api + 'attachments.php', {
        id: tr.getAttribute('data-id'), action: 'delete', attachment_id: item.getAttribute('data-id')
      }).then(function (res) {
        if (!res.ok) {
          endSave(false, res.error);
          // A document is the organisation's record too — ask instead.
          if (res.needs_request) {
            askToDelete(res, 'attachments.php', {
              id: tr.getAttribute('data-id'), attachment_id: item.getAttribute('data-id')
            });
          }
          return;
        }
        renderFiles(tr, res);
      });
    });
  }

  /* ---- review drawer ---- */
  function renderReview(tr, res) {
    var tone = 'tone-' + res.tone;
    var h = '<div class="reviewnow ' + tone + '">' +
            '<div class="state">' + esc(res.label) + '</div>' +
            (res.by ? '<div class="who">' + esc(res.by) + ' · ' + esc(res.when || '') + '</div>' : '') +
            (res.remarks ? '<div class="rem">' + esc(res.remarks) + '</div>' : '') +
            '</div>';
    // Why this row is in front of you. Only ever set for someone who was asked.
    if (res.asked_by) {
      h += '<div class="askedbox"><strong>You have been asked to review this</strong>' +
           (res.asked_note ? '<div class="note">' + esc(res.asked_note) + '</div>' : '') +
           '<div class="who">Requested by ' + esc(res.asked_by) + '</div></div>';
    }
    if (res.can_edit) {
      h += '<div class="reviewacts">' +
           '<button class="btn btn-approve" data-state="approved">✓ Approve</button>' +
           '<button class="btn btn-reject" data-state="rejected">✕ Reject</button>' +
           (res.state !== 'pending' ? '<button class="btn btn-sm" data-state="pending">Back to pending</button>' : '') +
           '</div>' +
           '<div class="rejectbox" id="rejectbox" style="display:none">' +
           '<label style="font-size:12.5px;font-weight:600;color:var(--ink-soft)">Why is this rejected? <span class="req">*</span></label>' +
           '<textarea id="rejremarks" placeholder="What needs to change before this can be approved?"></textarea>' +
           '<div class="warn" id="rejwarn">Remarks are required to reject.</div>' +
           '<button class="btn btn-reject btn-sm" id="rejconfirm" style="margin-top:8px">Confirm rejection</button>' +
           '<button class="btn btn-sm" id="rejcancel" style="margin-top:8px">Cancel</button>' +
           '</div>';
    }
    if (!res.can_edit && !res.can_request) {
      h += '<div class="optmsg">This item has not been sent to you for approval — ' +
           'you can see where it stands, but only the items sent to you can be decided.</div>';
    }
    h += '<div class="optmsg" id="rmsg"></div>';

    // Whoever owns the row chooses who has to sign it off, row by row.
    if (res.can_request) {
      h += '<div class="requestbox">' +
           '<h4>Send this item for approval</h4>';
      if (res.requests.length) {
        h += '<div class="sentlist">';
        res.requests.forEach(function (r) {
          h += '<div class="sentitem"><span><strong>' + esc(r.name) + '</strong>' +
               (r.org ? ' · ' + esc(r.org) : '') +
               '<br><span class="meta">Sent ' + esc(r.when) +
               (r.answered ? ' · answered ' + esc(r.answered) : ' · awaiting a reply') + '</span></span>' +
               '<button class="btn btn-sm js-unask" data-id="' + r.client_id + '">Withdraw</button></div>';
        });
        h += '</div>';
      }
      if (res.candidates.length) {
        var n = res.candidates.length;
        h += '<div class="askhead">' +
             '<input type="search" id="askfind" placeholder="Filter ' + n + ' name' +
               (n === 1 ? '' : 's') + '…">' +
             '<button type="button" class="btn btn-sm" id="askall">Select all</button>' +
             '<button type="button" class="btn btn-sm" id="asknone">Clear</button>' +
             '<span class="askcount" id="askcount"></span>' +
             '</div>';
        h += '<div class="asklist" id="asklist">';
        res.candidates.forEach(function (c) {
          // The searchable text lives on the row so filtering needs no re-render.
          var find = (c.name + ' ' + (c.org || '') + ' ' + (c.email || '')).toLowerCase();
          h += '<label data-find="' + esc(find) + '">' +
               '<input type="checkbox" class="js-askwho" value="' + c.id + '"> ' +
               '<span>' + esc(c.name) + (c.org ? ' · ' + esc(c.org) : '') +
               (c.head ? ' <span class="ro head">your head</span>' : '') +
               (c.kind === 'team' ? ' <span class="ro">team</span>' : '') +
               (c.email ? '' : ' <span class="ro">no email</span>') + '</span></label>';
        });
        h += '</div>' +
             '<textarea id="asknote" placeholder="What are you asking them to check? This goes into the email."></textarea>' +
             '<button class="btn btn-primary btn-sm" id="asksend">Send for approval</button>';
      } else {
        h += '<div class="empty">Nobody with access to this sheet yet. Give someone ' +
             'access first, then send them the row.</div>';
      }
      h += '</div>';
    }

    if (res.log.length) {
      h += '<h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--ink-soft);margin:16px 0 4px">Decision history</h4>';
      res.log.forEach(function (l) {
        h += '<div class="entry"><div class="meta">' + esc(l.when) + ' · ' + esc(l.by) + '</div>' +
             '<span class="pill tone-' + esc(l.tone) + '"><i class="dot"></i>' + esc(l.state) + '</span>' +
             (l.remarks ? '<div style="margin-top:5px">' + esc(l.remarks) + '</div>' : '') + '</div>';
      });
    }
    rb.innerHTML = h;
    wireAskList();
    if (res.row) { paintRow(tr, res.row); }
  }

  /* The recipient picker: filter, select all, and a running count. With a
     hundred clients on a sheet, scrolling a checkbox list is not a workflow. */
  function wireAskList() {
    var list  = document.getElementById('asklist');
    var find  = document.getElementById('askfind');
    var count = document.getElementById('askcount');
    if (!list) { return; }

    function rows() {
      return Array.prototype.slice.call(list.querySelectorAll('label'));
    }
    function visible() {
      return rows().filter(function (l) { return l.style.display !== 'none'; });
    }
    function tally() {
      if (!count) { return; }
      var on = list.querySelectorAll('.js-askwho:checked').length;
      var shown = visible().length;
      var all = rows().length;
      count.textContent = on + ' selected' + (shown < all ? ' · ' + shown + ' of ' + all + ' shown' : '');
      count.classList.toggle('on', on > 0);
    }

    if (find) {
      find.addEventListener('input', function () {
        var want = find.value.trim().toLowerCase();
        rows().forEach(function (l) {
          l.style.display = (!want || l.getAttribute('data-find').indexOf(want) !== -1) ? '' : 'none';
        });
        tally();
      });
    }
    // "Select all" means everyone currently listed, so it respects the filter.
    var all = document.getElementById('askall');
    if (all) {
      all.addEventListener('click', function () {
        visible().forEach(function (l) { l.querySelector('.js-askwho').checked = true; });
        tally();
      });
    }
    var none = document.getElementById('asknone');
    if (none) {
      none.addEventListener('click', function () {
        rows().forEach(function (l) { l.querySelector('.js-askwho').checked = false; });
        tally();
      });
    }
    list.addEventListener('change', tally);
    tally();
  }

  function loadReview(tr) {
    rb.innerHTML = '<div class="empty">Loading…</div>';
    rd.classList.add('on');
    rd.__tr = tr;
    rt.textContent = 'Review · ' + rowTitle(tr);
    get(cfg.api + 'approve.php', { id: tr.getAttribute('data-id') }).then(function (res) {
      if (!res.ok) { rb.innerHTML = '<div class="empty">' + esc(res.error) + '</div>'; return; }
      renderReview(tr, res);
    });
  }

  function decide(tr, state, remarks) {
    beginSave();
    post(cfg.api + 'approve.php', {
      id: tr.getAttribute('data-id'), state: state, remarks: remarks || ''
    }).then(function (res) {
      if (!res.ok) {
        endSave(false, res.error);
        var m = document.getElementById('rmsg');
        if (m) { m.textContent = res.error; m.style.color = 'var(--tone-risk-ink)'; }
        return;
      }
      endSave(true);
      renderReview(tr, res);
    }).catch(function () { endSave(false, 'Network error'); });
  }

  /* Sends the row to the chosen people, or withdraws an earlier request. */
  function ask(tr, action, ids, note) {
    beginSave();
    post(cfg.api + 'approve.php', {
      id: tr.getAttribute('data-id'), action: action, to: ids.join(','), note: note || ''
    }).then(function (res) {
      var m = document.getElementById('rmsg');
      if (!res.ok) {
        endSave(false, res.error);
        if (m) { m.textContent = res.error; m.style.color = 'var(--tone-risk-ink)'; }
        return;
      }
      endSave(true);
      renderReview(tr, res);
      // Say plainly who was emailed and who was not, rather than a bare "sent".
      m = document.getElementById('rmsg');
      if (m && res.sent && res.sent.length) {
        m.innerHTML = res.sent.map(function (s) {
          return esc(s.name) + ' — ' + (s.ok ? 'emailed' : 'not emailed (' + esc(s.detail) + ')') +
                 ', notice posted to their dashboard';
        }).join('<br>');
        m.style.color = 'var(--ink-soft)';
      } else if (m && action === 'cancel') {
        m.textContent = 'Request withdrawn.';
        m.style.color = 'var(--ink-soft)';
      }
    }).catch(function () { endSave(false, 'Network error'); });
  }

  if (rd) {
    rd.addEventListener('click', function (ev) {
      var tr = rd.__tr;

      var un = ev.target.closest('.js-unask');
      if (un) {
        if (!window.confirm('Withdraw this approval request?')) { return; }
        ask(tr, 'cancel', [un.getAttribute('data-id')], '');
        return;
      }
      if (ev.target.id === 'asksend') {
        var who = Array.prototype.slice.call(rd.querySelectorAll('.js-askwho:checked'))
                       .map(function (c) { return c.value; });
        var msg = document.getElementById('rmsg');
        if (!who.length) {
          if (msg) { msg.textContent = 'Choose at least one person.'; msg.style.color = 'var(--tone-risk-ink)'; }
          return;
        }
        var nEl = document.getElementById('asknote');
        ask(tr, 'request', who, nEl ? nEl.value.trim() : '');
        return;
      }

      var b = ev.target.closest('button[data-state]');
      if (b) {
        var st = b.getAttribute('data-state');
        if (st === 'rejected') {
          document.getElementById('rejectbox').style.display = 'block';
          document.getElementById('rejremarks').focus();
          return;
        }
        decide(tr, st, '');
        return;
      }
      if (ev.target.id === 'rejcancel') {
        document.getElementById('rejectbox').style.display = 'none';
        return;
      }
      if (ev.target.id === 'rejconfirm') {
        var ta = document.getElementById('rejremarks');
        var v = ta.value.trim();
        if (!v) {                                  // the server enforces this too
          ta.style.borderColor = 'var(--red)';
          document.getElementById('rejwarn').textContent = 'Please say what needs changing — remarks are required.';
          ta.focus();
          return;
        }
        decide(tr, 'rejected', v);
      }
    });
  }

  /* Picking a client reloads the sheet showing only their rows; the download
     button then carries the same filter, so you get exactly what you see. */
  (function () {
    var pick = document.getElementById('clientpick');
    if (!pick) { return; }
    pick.addEventListener('change', function () {
      var base = pick.getAttribute('data-base') || '';
      var v = pick.value;
      window.location = base + (v ? (base.indexOf('?') === -1 ? '?' : '&') +
                                    'client=' + encodeURIComponent(v) : '');
    });
  })();

  /* ---- open from the row tools ---- */
  if (body) {
    body.addEventListener('click', function (ev) {
      var f = ev.target.closest('.js-files');
      if (f) { loadFiles(f.closest('tr')); return; }
      var r = ev.target.closest('.js-review');
      if (r) { loadReview(r.closest('tr')); }
    });
  }
})();

/* Saves the sheet dashboard SVG as a PNG. No library: the SVG is serialised,
   drawn onto a canvas at 2x and handed to the browser as a download. */
(function () {
  var btn = document.getElementById('dashpng');
  var svg = document.getElementById('sheetdashsvg');
  if (!btn || !svg) { return; }

  btn.addEventListener('click', function () {
    var label = btn.textContent;
    btn.textContent = 'Rendering…';
    btn.disabled = true;

    var scale = 2;
    var w = svg.viewBox.baseVal.width || svg.width.baseVal.value;
    var h = svg.viewBox.baseVal.height || svg.height.baseVal.value;

    var clone = svg.cloneNode(true);
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    clone.setAttribute('width', w);
    clone.setAttribute('height', h);
    var source = new XMLSerializer().serializeToString(clone);
    var url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(source);

    var img = new Image();
    img.onload = function () {
      var canvas = document.createElement('canvas');
      canvas.width = w * scale;
      canvas.height = h * scale;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(function (blob) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = btn.getAttribute('data-file') + '.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
        btn.textContent = 'Saved';
        setTimeout(function () { btn.textContent = label; btn.disabled = false; }, 1600);
      }, 'image/png');
    };
    img.onerror = function () {
      btn.textContent = 'Could not render';
      setTimeout(function () { btn.textContent = label; btn.disabled = false; }, 2000);
    };
    img.src = url;
  });
})();

/* ===================================== per-row checklist (all sheets) */
(function () {
  var cfg = window.FABO_GRID;
  if (!cfg || !cfg.checklist) { return; }

  var body = document.getElementById('gridbody');
  var cd   = document.getElementById('checkdrawer');
  var cb   = document.getElementById('checkbody');
  var ct   = document.getElementById('checktitle');
  if (!body || !cd) { return; }

  var EYE = '\u{1F441}', PEN = '\u270E', BOX = '\u2610';
  var editing = false;              // is the "edit the checklist" panel open?

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function req(method, params) {
    var p = new URLSearchParams(params);
    if (cfg.token) { p.set('k', cfg.token); }
    p.set('sheet', cfg.sheet);
    p.set('id', cd.__tr.getAttribute('data-id'));
    if (method === 'POST') {
      p.set('csrf', cfg.csrf);
      return fetch(cfg.api + 'checklist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                   'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: p.toString()
      }).then(function (r) { return r.json(); });
    }
    return fetch(cfg.api + 'checklist.php?' + p.toString(), {
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  function call(params) {
    return req('POST', params).then(function (res) {
      if (!res.ok) {
        var m = document.getElementById('ckmsg');
        if (m) { m.textContent = res.error; m.style.color = 'var(--tone-risk-ink)'; }
        return null;
      }
      render(cd.__tr, res);
      return res;
    });
  }

  function render(tr, res) {
    var pct  = res.total ? Math.round(res.done / res.total * 100) : 0;
    var mOk  = res.must === 0 || res.must_done >= res.must;
    var tone = res.done === 0 ? 'muted' : (!mOk ? (res.must_done === 0 ? 'risk' : 'warn')
                                                : (res.done >= res.total ? '' : ''));

    var h = '<div class="ckprog"><div class="bar ' + tone + '"><i style="width:' + pct + '%"></i></div>' +
            '<span class="n">' + res.done + '/' + res.total + '</span></div>';

    if (res.must > 0) {
      h += '<div class="ckmust ' + (mOk ? 'ok' : 'no') + '">' +
           (mOk ? '\u2713 All ' + res.must + ' mandatory items are done'
                : res.must_done + ' of ' + res.must + ' mandatory items done \u2014 ' +
                  (res.must - res.must_done) + ' still outstanding') + '</div>';
    }
    h += '<div class="optmsg" id="ckmsg">' + esc(res.message || '') + '</div>';

    if (!editing) {
      res.items.forEach(function (it, i) {
        if (!it.active) { return; }
        h += '<div class="ckitem' + (it.done ? ' on' : '') + '" data-i="' + i + '" data-item="' + esc(it.item) + '">' +
             '<div class="top">' +
             '<input type="checkbox"' + (it.done ? ' checked' : '') + (res.can_edit ? '' : ' disabled') + '>' +
             '<span class="lbl">' + esc(it.item) +
             (it.required ? '<span class="req" title="Mandatory">*</span>' : '') + '</span>' +
             '<span class="acts">' +
             (it.has_note ? '<button class="js-eye haseye" title="View the description">' + EYE + '</button>' : '') +
             (res.can_edit ? '<button class="js-pen" title="' + (it.has_note ? 'Edit the description' : 'Write a description') + '">' + PEN + '</button>' : '') +
             '</span></div></div>';
      });
      if (res.is_admin) {
        h += '<button class="btn btn-sm js-ckmanage" style="margin-top:14px">\u2699 Edit this checklist</button>';
      }
      h += '<div class="hint" style="margin-top:10px">' +
           (res.can_edit ? 'Tick what is done. Use ' + PEN + ' to write a description; it then shows behind the ' + EYE + ' icon. '
                         : 'You have view-only access to this sheet. ') +
           '<span class="req">*</span> marks a mandatory item.</div>';
    } else {
      /* ---- the editor ---- */
      h += '<form class="optadd" id="ckadd">' +
           '<input placeholder="Add a checklist item\u2026" required autocomplete="off">' +
           '<label class="ckreq"><input type="checkbox" id="ckaddreq"> mandatory</label>' +
           '<button class="btn btn-primary btn-sm">Add</button></form>';
      res.items.forEach(function (it) {
        h += '<div class="ckrow' + (it.active ? '' : ' off') + '" data-tid="' + it.template_id + '">' +
             '<input class="ckname" value="' + esc(it.item) + '" title="Edit and press Enter to rename">' +
             '<button data-act="toggle_required" class="' + (it.required ? 'on' : '') + '" title="' +
               (it.required ? 'Mandatory — click to make optional' : 'Optional — click to make mandatory') + '">' +
               (it.required ? '\u2731 mandatory' : 'optional') + '</button>' +
             '<button data-act="move_up" title="Move up">\u2191</button>' +
             '<button data-act="move_down" title="Move down">\u2193</button>' +
             '<button data-act="toggle_active" title="' + (it.active ? 'Hide from every row' : 'Show again') + '">' +
               (it.active ? 'Hide' : 'Show') + '</button>' +
             '<button data-act="delete_item" class="danger" title="Delete from every row">Delete</button>' +
             '</div>';
      });
      h += '<button class="btn btn-sm js-ckdone" style="margin-top:14px">\u2190 Back to the checklist</button>' +
           '<div class="hint" style="margin-top:10px"><b>Hide</b> keeps the ticks but stops showing the item. ' +
           '<b>Delete</b> removes it, and its ticks and descriptions, from every row on this sheet. ' +
           'Renaming carries the ticks and descriptions across.</div>';
    }

    cb.innerHTML = h;
    cb.__res = res;

    var btn = tr.querySelector('.js-check');
    if (btn) { btn.innerHTML = BOX + '<b>' + res.done + '/' + res.total + '</b>'; }
    var cell = tr.querySelector('td[data-field="checklist"] .cell');
    if (cell) { cell.textContent = res.total ? res.done + '/' + res.total : ''; }
  }

  function load(tr) {
    editing = false;
    cb.innerHTML = '<div class="empty">Loading\u2026</div>';
    cd.classList.add('on');
    cd.__tr = tr;
    var idc = tr.querySelector('td.idcell .cell');
    ct.textContent = 'Checklist' + (idc && idc.textContent.trim() ? ' \u00b7 ' + idc.textContent.trim() : '');
    req('GET', {}).then(function (res) {
      if (!res.ok) { cb.innerHTML = '<div class="empty">' + esc(res.error) + '</div>'; return; }
      render(tr, res);
    });
  }

  body.addEventListener('click', function (ev) {
    var b = ev.target.closest('.js-check');
    if (b) { load(b.closest('tr')); }
  });

  cd.addEventListener('change', function (ev) {
    if (!ev.target.matches('.ckitem input[type=checkbox]')) { return; }
    call({ action: 'toggle', item: ev.target.closest('.ckitem').getAttribute('data-item'),
           done: ev.target.checked ? '1' : '0' });
  });

  cd.addEventListener('submit', function (ev) {
    if (ev.target.id !== 'ckadd') { return; }
    ev.preventDefault();
    var inp = ev.target.querySelector('input[type=text], input:not([type])');
    var rq  = document.getElementById('ckaddreq');
    call({ action: 'add_item', item: inp.value, required: rq && rq.checked ? '1' : '' });
  });

  cd.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || !ev.target.matches('.ckname')) { return; }
    ev.preventDefault();
    call({ action: 'rename_item', template_id: ev.target.closest('.ckrow').getAttribute('data-tid'),
           item: ev.target.value });
  });

  cd.addEventListener('click', function (ev) {
    if (ev.target.closest('.js-ckmanage')) { editing = true;  render(cd.__tr, cb.__res); return; }
    if (ev.target.closest('.js-ckdone'))   { editing = false; render(cd.__tr, cb.__res); return; }

    var tb = ev.target.closest('.ckrow button[data-act]');
    if (tb) {
      var act = tb.getAttribute('data-act');
      if (act === 'delete_item' &&
          !window.confirm('Delete this item from every row on this sheet? Its ticks and descriptions go with it.')) { return; }
      call({ action: act, template_id: tb.closest('.ckrow').getAttribute('data-tid') });
      return;
    }

    var row = ev.target.closest('.ckitem');
    if (!row) { return; }
    var res = cb.__res || { items: [] };
    var it  = res.items[parseInt(row.getAttribute('data-i'), 10)] || {};

    if (ev.target.closest('.js-eye')) {
      var open = row.querySelector('.cknote');
      if (open) { open.remove(); return; }
      var n = document.createElement('div');
      n.className = 'cknote';
      n.innerHTML = esc(it.note) +
        (it.by ? '<div class="who">' + esc(it.by) + (it.when ? ' \u00b7 ' + esc(it.when) : '') + '</div>' : '');
      row.appendChild(n);
      return;
    }

    if (ev.target.closest('.js-pen')) {
      if (row.querySelector('.ckedit')) { return; }
      var box = document.createElement('div');
      box.className = 'ckedit';
      box.innerHTML = '<textarea placeholder="What was done, links, who to ask\u2026">' + esc(it.note) + '</textarea>' +
        '<div class="row"><button class="btn btn-primary btn-sm js-cksave">Save</button>' +
        '<button class="btn btn-sm js-ckcancel">Cancel</button>' +
        (it.has_note ? '<button class="btn btn-danger btn-sm js-ckclear">Clear</button>' : '') + '</div>';
      row.appendChild(box);
      box.querySelector('textarea').focus();
      return;
    }

    if (ev.target.closest('.js-ckcancel')) { row.querySelector('.ckedit').remove(); return; }

    if (ev.target.closest('.js-cksave') || ev.target.closest('.js-ckclear')) {
      var clearing = !!ev.target.closest('.js-ckclear');
      var ta = row.querySelector('.ckedit textarea');
      call({ action: 'note', item: row.getAttribute('data-item'), note: clearing ? '' : ta.value });
    }
  });
})();


/* ============================== the event budget (sheets with the column) */
/*
 * The Budget Required cell opens this. Each head the admin has allowed gets
 * one box, and that box is the only thing a team member can change: the heads
 * themselves, their allocations and their thresholds belong to Sheet
 * Management and are not editable from here at any level.
 *
 * Typing a figure saves a DRAFT and tells nobody. Submitting is the act that
 * asks: anything inside its limit is approved on the spot, anything over goes
 * to the budget owner, who approves it or sends it back with a reason. A line
 * that is sent back returns to draft as soon as its amount is edited, so the
 * team can correct it and submit again.
 *
 * The allocation columns are drawn only when the server said this viewer may
 * see them, and the server also simply leaves those numbers out of the JSON
 * for everybody else -- so this flag decides the layout, never the secrecy.
 * Turning it on in a console would draw empty columns and reveal nothing.
 */
(function () {
  var cfg = window.FABO_GRID;
  if (!cfg || !cfg.budget) { return; }

  var body = document.getElementById('gridbody');
  var bd   = document.getElementById('budgetdrawer');
  var bb   = document.getElementById('budgetbody');
  var bt   = document.getElementById('budgettitle');
  if (!body || !bd) { return; }

  var seeAlloc = !!cfg.budgetSeeAlloc;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* Indian grouping, to match the rest of the portal: 12,34,567 not 1,234,567. */
  function money(n) {
    n = Math.round(Number(n) || 0);
    var s = String(Math.abs(n)), out;
    if (s.length > 3) {
      var last3 = s.slice(-3), rest = s.slice(0, -3);
      out = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3;
    } else { out = s; }
    return (n < 0 ? '-' : '') + '₹' + out;
  }

  function params(extra) {
    var p = new URLSearchParams(extra || {});
    if (cfg.token) { p.set('k', cfg.token); }
    p.set('sheet', cfg.sheet);
    p.set('id', bd.__tr.getAttribute('data-id'));
    return p;
  }

  function get(extra) {
    return fetch(cfg.api + 'budget.php?' + params(extra).toString(), {
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  function post(extra) {
    var p = params(extra);
    p.set('csrf', cfg.csrf);
    return fetch(cfg.api + 'budget.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: p.toString()
    }).then(function (r) { return r.json(); });
  }

  function send(form) {                     /* multipart, for the file itself */
    var p = params({});
    form.append('csrf', cfg.csrf);
    return fetch(cfg.api + 'budget.php?' + p.toString(), {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: form
    }).then(function (r) { return r.json(); });
  }

  function say(msg, bad) {
    var m = document.getElementById('bqmsg');
    if (!m) { return; }
    m.textContent = msg || '';
    m.style.color = bad ? 'var(--tone-risk-ink)' : 'var(--ink-soft)';
  }

  /* What one line's status column says. */
  function verdict(l) {
    if (!l.state || l.state === 'none') { return ''; }

    var h = '<span class="pill tone-' + esc(l.tone) + '">' + esc(l.word) + '</span>';

    /* The owner's reason, against the red. A team member cannot see the limit
       that was broken, so this sentence is the whole of what they learn -- it
       is why a rejection has to carry one. */
    if (l.remarks) {
      h += '<div class="bqwhy' + (l.state === 'rejected' ? ' bad' : '') + '">'
         + esc(l.remarks)
         + (l.decided_by
            ? '<span class="bqn">' + esc(l.decided_by)
              + (l.decided_at ? ' · ' + esc(l.decided_at) : '') + '</span>'
            : '')
         + '</div>';
    } else if (l.message) {
      h += '<div class="bqn">' + esc(l.message) + '</div>';
    }
    return h;
  }

  /* The owner's two buttons, on anything they still have to answer. */
  function decide(l) {
    if (l.state !== 'pending' && l.state !== 'rejected' && l.state !== 'approved') {
      return '';
    }
    var h = '<div class="bqdecide">';
    if (l.state !== 'approved') {
      h += '<button type="button" class="btn btn-sm btn-primary js-bqok">Approve</button>';
    }
    if (l.state !== 'rejected') {
      h += '<button type="button" class="btn btn-sm danger js-bqno">Send back</button>';
    }
    return h + '</div>';
  }

  /* Whose approval a line carries. The budget owner picks it; everybody
     else reads it. Only on a line with an amount -- nothing else was asked. */
  function approver(l, res) {
    if (!(l.required > 0)) { return '<span class="bqn">—</span>'; }
    if (!res.may_decide) {
      return l.approved_by ? '<span class="pill tone-good">' + esc(l.approved_by) + '</span>'
                           : '<span class="bqn">—</span>';
    }
    var h = '<select class="js-bqby" aria-label="Approved by"><option value=""></option>';
    (res.approvers || []).forEach(function (o) {
      h += '<option' + (o === l.approved_by ? ' selected' : '') + '>' + esc(o) + '</option>';
    });
    return h + '</select>';
  }

  function render(res) {
    var editable = res.editable !== false;
    /* Completed Events: the amounts are the plan, kept for reference. The
       drawer still opens there, for the invoices. */
    var ro       = !!(bd.__tr && bd.__tr.querySelector('.js-budget[data-ro]'));
    var done     = res.stage === 'completed';
    var docLabel = res.doc_label || 'Documents';

    /* Events: the committee decides the event and this budget, nobody else.
       One line says where it stands and what to do next. */
    var cm = !!res.committee_mode;
    var cmLine = '';
    if (cm && !done) {
      var who = res.committee ? esc(res.committee) : 'the committee';
      if (!res.committee) {
        cmLine = '<div class="bqcm"><strong>Next:</strong> enter the amount for each head and attach the quotations, '
               + 'then choose a <strong>Committee</strong> for this event (the Committee column). The committee reviews '
               + 'the event and this budget — its decision is final.</div>';
      } else if (res.committee_state === 'rejected') {
        cmLine = '<div class="bqcm bad"><strong>Sent back by ' + who + '.</strong> Read the remarks beside each head, '
               + 'correct the amounts (or attach better quotations), then press <strong>Send to committee again</strong>.</div>';
      } else if (res.committee_state === 'approved' && !res.draft) {
        cmLine = '<div class="bqcm good"><strong>Approved by ' + who + '.</strong> Budget Allocated now shows the approved amount.</div>';
      } else if (res.draft) {
        cmLine = '<div class="bqcm"><strong>' + res.draft + ' change' + (res.draft === 1 ? '' : 's') + ' not sent yet.</strong> '
               + 'Press <strong>Send to committee</strong> when you are ready — ' + who + ' reviews it afresh.</div>';
      } else {
        cmLine = '<div class="bqcm"><strong>With ' + who + ' for review.</strong> You will be told when it decides.</div>';
      }
    }

    var h = cm && !done
          ? cmLine + '<p class="hint">Enter what this event needs against each head and attach the '
            + '<strong>quotations</strong> under ' + esc(docLabel) + '. The committee checks the amounts and quotations, '
            + 'and approves or sends them back with remarks.</p>'
          : done
          ? '<p class="hint">This event has taken place. Attach the <strong>invoices</strong> '
            + 'against each head under ' + esc(docLabel) + ' — the committee reviews them. '
            + 'The quotations attached while planning are kept beside them for comparison.'
            + (ro ? ' Amounts are read-only here; change them on Programs Data.' : '')
            + '</p>'
          : '<p class="hint">Enter what this event needs against each head, attach the '
            + '<strong>quotations</strong> under ' + esc(docLabel) + ' for the committee to '
            + 'verify, then submit it. '
            + (seeAlloc
               ? 'You can see each allocation because you set them; the team cannot. '
               + 'Anything over its limit waits here for your answer.'
               : 'Anything over the limit for that head goes to the budget owner, '
               + 'and their answer appears here.')
            + '</p>';

    h += '<table class="data bqtable"><thead><tr>'
       + '<th>Budget head</th>'
       + (seeAlloc ? '<th class="num">Allocated</th><th class="num">Limit</th>' : '')
       + '<th class="num">Amount required</th>'
       + '<th>Status</th>'
       + (res.may_decide ? '<th>Decision</th>' : '')
       + '<th>Approved By</th>'
       + '<th>' + esc(docLabel) + '</th></tr></thead><tbody>';

    res.lines.forEach(function (l) {
      /* An approved or waiting line is not edited in place: changing it would
         void the answer, so the team sends it back to draft deliberately. */
      var frozen = l.state === 'approved' || l.state === 'pending' || ro;
      /* The count on the button is of the kind this stage collects. */
      var n = done ? (l.invoices || 0) : (l.quotes || 0);

      h += '<tr data-item="' + l.item_id + '" data-line="' + l.line_id + '">'
         + '<td><strong>' + esc(l.name) + '</strong>'
         + (l.retired ? '<div class="bqn">This head has since been removed. '
                      + 'What was asked for is kept.</div>' : '')
         + (l.by ? '<div class="bqn">last set by ' + esc(l.by) + '</div>' : '')
         + '</td>';

      if (seeAlloc) {
        h += '<td class="num">' + (l.allocated > 0 ? money(l.allocated) : '—') + '</td>'
           + '<td class="num">' + (l.allocated > 0
               ? money(l.ceiling) + '<div class="bqn">+' + (Number(l.pct) || 0) + '%</div>'
               : '—') + '</td>';
      }

      h += '<td class="num"><input class="bqamt js-bqamt" type="number" min="0" step="0.01"'
         + ' value="' + (l.required > 0 ? l.required : '') + '"'
         + (editable && !l.retired && !frozen ? '' : ' disabled')
         + ' inputmode="decimal" placeholder="0"></td>'
         + '<td class="bqv">' + verdict(l) + '</td>'
         + (res.may_decide ? '<td class="bqd">' + decide(l) + '</td>' : '')
         + '<td class="bqby">' + approver(l, res) + '</td>'
         + '<td class="bqfiles">'
         + '<button type="button" class="btn btn-sm js-bqfiles">'
         + (n ? '\u{1F4CE} ' + n + (done ? ' invoice' : ' quotation') + (n === 1 ? '' : 's')
              : '\u{1F4CE} ' + (done ? 'Attach invoice' : 'Attach quotation'))
         + '</button>'
         + (done && l.quotes ? '<div class="bqn">' + l.quotes + ' quotation'
                               + (l.quotes === 1 ? '' : 's') + ' from planning</div>' : '')
         + '</td></tr>';
    });

    h += '</tbody><tfoot><tr>'
       + '<th>Total required</th>'
       + (seeAlloc ? '<th></th><th></th>' : '')
       + '<th class="num"><strong>' + money(res.total) + '</strong></th>'
       + '<th colspan="' + (res.may_decide ? 4 : 3) + '">';

    if (res.rejected) {
      h += '<span class="pill tone-risk">' + res.rejected + ' sent back</span> ';
    }
    if (res.pending) {
      h += '<span class="pill tone-warn">' + res.pending + ' waiting</span> ';
    }
    if (res.draft) {
      h += '<span class="pill">' + res.draft + ' not submitted</span>';
    }
    h += '</th></tr></tfoot></table>';

    /* What the event's Budget Allocated now reads. */
    h += '<div class="bqfoot"><div><span class="bqn">Approved, and now on the row '
       + 'as Budget Allocated</span><strong>' + money(res.approved_total || 0)
       + '</strong></div>';

    if (editable && !ro && cm && !done) {
      /* The one act on Events: hand the budget to the committee. */
      h += '<button type="button" class="btn btn-primary js-bqsubmit"'
         + (res.can_send ? '' : ' disabled')
         + (res.committee ? '' : ' title="Choose a Committee for this event first"')
         + '>' + (res.committee_state === 'rejected' ? 'Send to committee again' : 'Send to committee')
         + '</button>';
    } else if (editable && !ro && !cm) {
      h += '<button type="button" class="btn btn-primary js-bqsubmit"'
         + (res.draft ? '' : ' disabled')
         + '>Submit for approval'
         + (res.draft ? ' (' + res.draft + ')' : '') + '</button>';
    }
    h += '</div>';

    if (res.rejected && !res.may_decide && !cm) {
      h += '<p class="hint bqfix">Something was sent back. Change the amount '
         + 'against it — the reason is beside it — and submit again.</p>';
    }

    h += '<div id="bqmsg" class="hint" style="margin-top:10px"></div>';

    bb.innerHTML = h;
    bb.__res = res;
    paint(res);
  }

  /* The grid cell behind the drawer, kept in step without a reload. */
  function paint(res) {
    var tr = bd.__tr;
    if (!tr) { return; }
    var cell = tr.querySelector('.js-budget');
    if (!cell) { return; }

    var bits = res.filled
      ? money(res.total) + '<i class="bqn">' + res.filled + ' head'
        + (res.filled === 1 ? '' : 's')
        + (res.files ? ' · ' + res.files + ' file' + (res.files === 1 ? '' : 's') : '')
        + '</i>'
      : '<i class="bqn">Open the budget</i>';

    var cm = !!res.committee_mode;
    if (res.rejected) {
      bits += '<b class="bqflag bqbad" title="' + (cm ? 'Sent back by the committee — open for the remarks' : 'Open for the reason') + '">'
            + res.rejected + ' sent back</b>';
    } else if (res.pending) {
      bits += cm ? '<b class="bqflag bqwait" title="The committee is reviewing this budget">with the committee</b>'
                 : '<b class="bqflag bqwait" title="Waiting on the budget owner">'
            + res.pending + ' awaiting approval</b>';
    } else if (res.draft) {
      bits += '<b class="bqflag">' + (cm ? 'not sent yet' : res.draft + ' not submitted') + '</b>';
    }

    cell.innerHTML = bits;
    var td = cell.closest('td');
    if (td) {
      td.classList.remove('tone-risk', 'tone-warn', 'tone-good');
      if (res.rejected)     { td.classList.add('tone-risk'); }
      else if (res.pending) { td.classList.add('tone-warn'); }
      else if (res.draft)   { /* typed, not yet asked for: left plain */ }
      else if (res.approved) { td.classList.add('tone-good'); }
    }

    /* Budget Allocated is kept from the approved lines, so it moves here too
       rather than waiting for somebody to reload the page. */
    var alloc = tr.querySelector('td[data-field="budget_allocated"] .cell');
    if (alloc && typeof res.approved_total === 'number') {
      var mark = alloc.querySelector('.keptmark');
      alloc.textContent = res.approved_total > 0 ? money(res.approved_total) : '';
      if (mark) { alloc.appendChild(mark); }
    }
  }

  function load(tr) {
    bb.innerHTML = '<div class="empty">Loading…</div>';
    bd.classList.add('on');
    bd.__tr = tr;
    var name = tr.querySelector('td[data-field="event_name"] input, td[data-field="event_name"] .cell');
    var idc  = tr.querySelector('td.idcell .cell');
    var who  = (name && (name.value || name.textContent) || '').trim()
            || (idc && idc.textContent.trim()) || '';
    bt.textContent = 'Budget' + (who ? ' · ' + who : '');
    get({}).then(function (res) {
      if (!res.ok) { bb.innerHTML = '<div class="empty">' + esc(res.error) + '</div>'; return; }
      render(res);
    });
  }

  body.addEventListener('click', function (ev) {
    var b = ev.target.closest('.js-budget');
    if (b) { ev.preventDefault(); load(b.closest('tr')); }
  });

  /* An amount is saved when the box is left, not on every keystroke. It saves
     as a draft and tells nobody; submitting is the act that asks. */
  bd.addEventListener('change', function (ev) {
    /* The owner records whose approval the line carries. */
    if (ev.target.matches('.js-bqby')) {
      var row = ev.target.closest('tr');
      say('Saving…');
      post({ action: 'approved_by', line_id: row.getAttribute('data-line'),
             approved_by: ev.target.value }).then(function (res) {
        if (!res.ok) { say(res.error, true); return; }
        render(res);
        say(res.message || 'Saved.');
      });
      return;
    }
    if (!ev.target.matches('.js-bqamt')) { return; }
    var tr = ev.target.closest('tr');
    say('Saving…');
    post({ action: 'set', item_id: tr.getAttribute('data-item'),
           required: ev.target.value }).then(function (res) {
      if (!res.ok) { say(res.error, true); return; }
      render(res);
      say('Saved as a draft. Submit it when you are ready.');
    });
  });

  bd.addEventListener('click', function (ev) {
    /* ---------------------------------------------- the team submits */
    if (ev.target.closest('.js-bqsubmit')) {
      say('Sending…');
      post({ action: 'submit' }).then(function (res) {
        if (!res.ok) { say(res.error, true); return; }
        render(res);
        say(res.message || 'Submitted.');
      });
      return;
    }

    /* ------------------------------------------- the owner answers */
    var yes = ev.target.closest('.js-bqok');
    var no  = ev.target.closest('.js-bqno');
    if (yes || no) {
      var row = (yes || no).closest('tr');
      var why = '';
      if (no) {
        /* Compulsory, because the team cannot see the limit it broke: without
           a reason they are told "no" and nothing else. The server refuses an
           empty one too, so this prompt is the convenience, not the rule. */
        why = window.prompt('Why is this not approved? The team sees this '
                          + 'instead of the amount.', '');
        if (why === null) { return; }
        if (!why.trim()) { say('A reason is needed to send it back.', true); return; }
      }
      say('Saving…');
      post({ action: 'decide', line_id: row.getAttribute('data-line'),
             decision: yes ? 'approved' : 'rejected',
             remarks: why }).then(function (res) {
        if (!res.ok) { say(res.error, true); return; }
        render(res);
        say(res.message || 'Saved.');
      });
      return;
    }

    /* ------------------------------------------------- the documents */
    var fb = ev.target.closest('.js-bqfiles');
    if (fb) {
      var tr = fb.closest('tr');
      openFiles(tr.getAttribute('data-item'), tr.getAttribute('data-line'),
                tr.querySelector('strong').textContent);
      return;
    }
    if (ev.target.closest('.js-bqback')) { get({}).then(render); return; }

    var del = ev.target.closest('.js-bqdelfile');
    if (del) {
      if (!window.confirm('Remove this document?')) { return; }
      post({ action: 'delete_file', attachment_id: del.getAttribute('data-id'),
             line_id: del.getAttribute('data-line') }).then(function (res) {
        if (!res.ok) { say(res.error, true); return; }
        openFiles(del.getAttribute('data-item'), del.getAttribute('data-line'),
                  del.getAttribute('data-name'));
      });
    }
  });

  function openFiles(itemId, lineId, name) {
    bb.innerHTML = '<div class="empty">Loading…</div>';
    get({ line: lineId }).then(function (res) {
      if (!res.ok) { bb.innerHTML = '<div class="empty">' + esc(res.error) + '</div>'; return; }

      var files = res.line_files || [];
      var done  = res.stage === 'completed';
      var h = '<button type="button" class="btn btn-sm js-bqback">← Back to the budget</button>'
            + '<h3 style="margin:14px 0 4px;font-size:14px">' + esc(name) + ' · '
            + esc(res.doc_label || 'Documents') + '</h3>'
            + '<p class="hint">' + (done
               ? 'The event has taken place: attach the invoices (and receipts) for this '
                 + 'head. The committee reviews them against the quotations below.'
               : 'Attach the quotations for this head — vendor quotes, estimates, travel '
                 + 'fares. The committee verifies them before the event.') + '</p>';

      /* One list per kind: invoices first once the event is over, then the
         quotations it was planned on. Before it, quotations only. */
      function list(kind, title, emptyText) {
        var mine = files.filter(function (f) { return (f.kind || 'quotation') === kind; });
        var out = '<h4 class="bqkind">' + esc(title) + ' (' + mine.length + ')</h4>';
        if (!mine.length) { return out + '<div class="empty">' + esc(emptyText) + '</div>'; }
        out += '<ul class="bqlist">';
        mine.forEach(function (f) {
          out += '<li><a href="' + esc(f.url) + '" target="_blank" rel="noopener">'
               + esc(f.name) + '</a>'
               + '<span class="bqn">' + esc(f.size) + ' · ' + esc(f.when)
               + (f.by ? ' · ' + esc(f.by) : '') + '</span>'
               + '<button type="button" class="bqx js-bqdelfile" data-id="' + f.id
               + '" data-line="' + lineId + '" data-item="' + itemId
               + '" data-name="' + esc(name) + '" title="Remove">×</button></li>';
        });
        return out + '</ul>';
      }

      if (done) {
        h += list('invoice', 'Invoices', 'No invoice attached yet.');
      }
      h += list('quotation', done ? 'Quotations (from planning)' : 'Quotations',
                'No quotation attached yet.');

      if (res.editable !== false) {
        h += '<form id="bqup" style="margin-top:14px">'
           + '<input type="hidden" name="action" value="upload">'
           + '<input type="hidden" name="item_id" value="' + itemId + '">'
           + '<input type="file" name="file" required>'
           + '<button class="btn btn-sm" style="margin-left:8px">'
           + (done ? 'Upload invoice' : 'Upload quotation') + '</button>'
           + '</form>';
      }
      h += '<div id="bqmsg" class="hint" style="margin-top:10px"></div>';
      bb.innerHTML = h;
      bb.__item = itemId;
      bb.__name = name;
    });
  }

  bd.addEventListener('submit', function (ev) {
    if (ev.target.id !== 'bqup') { return; }
    ev.preventDefault();
    say('Uploading…');
    send(new FormData(ev.target)).then(function (res) {
      if (!res.ok) { say(res.error, true); return; }
      paint(res);
      openFiles(bb.__item, findLine(res, bb.__item), bb.__name);
    });
  });

  /* After the first upload the line exists where it did not before, so its id
     has to be read back out of the fresh payload. */
  function findLine(res, itemId) {
    var hit = (res.lines || []).filter(function (l) {
      return String(l.item_id) === String(itemId);
    })[0];
    return hit ? hit.line_id : 0;
  }
})();

/* ==================================================== frozen panes
   A wide sheet is filled in by scrolling across it, and the further across
   you go the less you can tell which row you are on. So, like a spreadsheet:
   the header rows stay at the top, the row's ID and its name stay at the
   left, the new-row line stays under the header while you scroll down, and
   the toolbar with "+ Add row" stays under the page header. All measured
   from the page as drawn, so renamed, hidden or reordered columns follow. */
(function () {
  var cfg  = window.FABO_GRID;
  var box  = document.querySelector('.gridbox');
  var grid = box && box.querySelector('table.grid');
  if (!cfg || !grid) { return; }

  var cols = cfg.columns || [];
  var head = grid.tHead;

  /* The columns to pin: the ID, and the first plain-text column among the
     first eight -- the row's name (campaign, event, title, store...). */
  function pinned() {
    var out = [];
    for (var i = 0; i < cols.length; i++) {
      if (cols[i].type === 'id') { out.push(i); break; }
    }
    for (var j = 0; j < Math.min(cols.length, 8); j++) {
      if ((cols[j].type === 'text' || cols[j].type === 'long') && !cols[j].list && out.indexOf(j) < 0) {
        out.push(j);
        break;
      }
    }
    return out.sort(function (a, b) { return a - b; });
  }

  function clear() {
    Array.prototype.forEach.call(grid.querySelectorAll('.frz'), function (c) {
      c.classList.remove('frz', 'frz-last');
      c.style.left = '';
    });
  }

  function layout() {
    clear();

    // Header rows: the second row sits under the group banner, not on it.
    var hr = head ? head.rows : [];
    var top = 0;
    for (var r = 0; r < hr.length; r++) {
      Array.prototype.forEach.call(hr[r].cells, function (c) { c.style.top = top + 'px'; });
      top += hr[r].offsetHeight;
    }
    box.style.setProperty('--grid-head', top + 'px');

    var comp = document.getElementById('composer');
    box.style.setProperty('--grid-comp', (comp ? comp.offsetHeight : 0) + 'px');

    // Pinned columns, only while they leave most of the view for the rest.
    var headRow = hr.length ? hr[hr.length - 1] : null;
    if (!headRow) { return; }
    var corner = headRow.cells[0];
    var left   = corner ? corner.offsetWidth : 0;
    var plan   = [];
    pinned().forEach(function (i) {
      var th = headRow.cells[i + 1];
      if (!th) { return; }
      if (left + th.offsetWidth > box.clientWidth * 0.5) { return; }
      plan.push([i, left]);
      left += th.offsetWidth;
    });
    if (!plan.length) { return; }

    Array.prototype.forEach.call(grid.rows, function (tr) {
      if (tr.cells.length !== cols.length + 1) { return; }   // group banner, action bar
      plan.forEach(function (p, k) {
        var cell = tr.cells[p[0] + 1];
        if (!cell) { return; }
        cell.classList.add('frz');
        if (k === plan.length - 1) { cell.classList.add('frz-last'); }
        cell.style.left = p[1] + 'px';
      });
    });
  }

  // The toolbar stays reachable under the page's own sticky header.
  var bar = box.previousElementSibling;
  while (bar && !bar.classList.contains('toolbar')) { bar = bar.previousElementSibling; }
  if (!bar) { bar = document.querySelector('.toolbar'); }
  function stickBar() {
    if (!bar || !document.getElementById('addrow')) { return; }
    var ah = document.querySelector('.apphead');
    bar.classList.add('toolbar-stick');
    bar.style.top = (ah ? ah.offsetHeight : 0) + 'px';
  }

  var queued = false;
  function soon() {
    if (queued) { return; }
    queued = true;
    window.requestAnimationFrame(function () { queued = false; layout(); stickBar(); });
  }

  soon();
  window.addEventListener('load', soon);
  window.addEventListener('resize', soon);
  document.addEventListener('fabo:gridchange', soon);
  if (window.MutationObserver && grid.tBodies[0]) {
    new MutationObserver(soon).observe(grid.tBodies[0], { childList: true });
  }
})();
