/**
 * The department live sheet.
 *
 * Standalone on purpose: this page has no session, no permissions and no
 * FABO_GRID, so it cannot use the portal's grid script. What it needs is
 * small — edit a cell, add a row, attach images — and every request carries
 * the sealed token, which is the whole of its authority.
 */
(function () {
  'use strict';

  var cfg = window.FABO_LS;
  if (!cfg) { return; }

  /* ------------------------------------------------- whose sheet is this

     The page must belong to the link in the address bar. A stored copy of
     somebody else's page (a cache between here and the server) would carry
     their link inside it and send every edit to their sheet. So: if the two
     differ, fetch this link's own page fresh, and never save in the
     meantime. One retry only, so a stubborn cache cannot loop the page. */
  var locked = false;
  function fresh(why) {
    locked = true;
    var bar = document.getElementById('lsstate');
    if (bar) { bar.className = 'ls-state bad'; bar.textContent = why; }
    var key = 'fabo_ls_retry';
    var tried = false;
    try { tried = sessionStorage.getItem(key) === location.search; } catch (e) { /* storage blocked */ }
    if (tried) {
      if (bar) { bar.textContent = why + ' Close this tab and open your link again.'; }
      return;
    }
    try { sessionStorage.setItem(key, location.search); } catch (e) { /* storage blocked */ }
    var u = new URL(location.href);
    u.searchParams.set('_', String(Date.now()));
    location.replace(u.toString());
  }
  var urlT = new URLSearchParams(location.search).get('t');
  if (urlT && urlT !== cfg.t) {
    fresh('This page was for a different link. Loading yours…');
    return;
  }
  try { sessionStorage.removeItem('fabo_ls_retry'); } catch (e) { /* storage blocked */ }

  var body  = document.getElementById('lsbody');
  var state = document.getElementById('lsstate');
  var empty = document.getElementById('lsempty');
  var pending = 0;
  var failed  = false;

  /* ------------------------------------------------------------ state */

  function setState(kind, text) {
    if (!state) { return; }
    state.className = 'ls-state ' + kind;
    state.innerHTML = '<i></i>' + esc(text);
  }
  function begin() { pending++; failed = false; setState('busy', 'Saving…'); }
  function end(ok, msg) {
    pending = Math.max(0, pending - 1);
    if (!ok) { failed = true; setState('bad', msg || 'Not saved'); return; }
    if (pending === 0) { setState('', 'Live — everything saved'); }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* ------------------------------------------------- talking to the server

     Shared hosting counts requests. A burst of them -- one per cell, which
     is what Save all used to send -- is read as a flood: the host first
     answers 503 (too busy), then its firewall answers 403 for every page on
     the site, this one included. So requests go out through a queue, two at
     a time, and an answer that came from the host rather than from the
     portal (busy, refused, no connection) is tried again after a pause
     instead of being shown. Only the portal's own answers are final. */
  var MAX_LIVE = 2;
  var RETRY_MS = [1500, 3000, 6000, 12000, 20000];
  var live = 0;
  var waiting = [];

  function pump() {
    while (live < MAX_LIVE && waiting.length) {
      var job = waiting.shift();
      live++;
      send(job.opts, 0).then(job.done, job.done).then(function () { live--; pump(); });
    }
  }

  function send(opts, attempt) {
    return fetch(cfg.api, opts).then(function (r) {
      /* Read as text first: a host that refuses answers with its own page,
         not JSON. */
      return r.text().then(function (t) {
        try { return JSON.parse(t.slice(Math.max(0, t.indexOf('{')))); } catch (e) {
          if (r.status === 413) {
            return { ok: false, error: 'That file is bigger than this server accepts' +
              (cfg.maxWords ? ' (limit ' + cfg.maxWords + ')' : '') + '. Nothing was uploaded.' };
          }
          /* The host's own refusal (its 403 page): it has paused this
             network for sending too much at once. Asking again at once only
             keeps the pause going, so stop and say so. */
          if (r.status === 403 || r.status === 429) {
            return { ok: false, transient: true,
                     error: 'The server has paused this network for a few minutes. Your changes are kept on this page — press Save all in 5 minutes.' };
          }
          return null;                      // the host, not the portal: try again
        }
      });
    }, function () { return null; })        // no connection: try again
      .then(function (res) {
        if (res) { return res; }
        if (attempt >= RETRY_MS.length) {
          return { ok: false, transient: true,
                   error: 'The server is busy. Your changes are kept on this page — press Save all in a minute.' };
        }
        setState('busy', 'Saving… the server is busy, trying again');
        return new Promise(function (ok) { setTimeout(ok, RETRY_MS[attempt]); })
          .then(function () { return send(opts, attempt + 1); });
      });
  }

  function call(params, isForm) {
    if (locked) {
      return Promise.resolve({ ok: false, error: 'Not saved — reload your link.' });
    }
    var opts = { method: 'POST', cache: 'no-store',
                 headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } };
    if (isForm) {
      params.append('t', cfg.t);
      opts.body = params;
    } else {
      params.set('t', cfg.t);
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opts.body = params.toString();
    }
    return new Promise(function (done) {
      waiting.push({ opts: opts, done: done });
      pump();
    }).then(function (res) {
      /* The server names the department it acted for. Anything else means
         this page is not this link's, and nothing more is sent from it. */
      if (cfg.dept && res && res.dept && Number(res.dept) !== Number(cfg.dept)) {
        fresh('This page belongs to a different department. Loading yours…');
        return { ok: false, error: 'Not saved — wrong sheet.' };
      }
      return res;
    });
  }

  /* Ask once, on opening, whose sheet the link really is. */
  (function () {
    var p = new URLSearchParams();
    p.set('action', 'whoami');
    call(p).catch(function () { /* offline: the first save will ask again */ });
  })();

  /* --------------------------------------------------------- numbering */

  function renumber() {
    var n = 0;
    Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
      var cell = tr.querySelector('.ls-n');
      if (cell) { cell.textContent = ++n; }
    });
    var c = document.getElementById('lscount');
    var w = document.getElementById('lscountword');
    if (c) { c.textContent = n; }
    if (w) { w.textContent = (n === 1 ? 'row' : 'rows') + ' open for editing.'; }
    if (empty) { empty.hidden = n > 0; }

    /* Submit follows the same count.
     *
     * It is drawn by the server, which knows how many rows there were when
     * the page was sent -- none, on a sheet somebody has just been given. Rows
     * added here never reached it, so it sat disabled reading "Submit" while
     * the line beside it said two rows were open. Whoever was filling the
     * sheet in had no way to hand it over. */
    var sub = document.getElementById('lssubmit');
    if (sub) {
      sub.disabled = n === 0;
      sub.textContent = n === 0 ? 'Submit'
                                : 'Submit ' + n + (n === 1 ? ' row' : ' rows');
    }
  }

  /* ------------------------------------------------------ editing cells */

  /* What each cell last saved as, so only a real change is ever sent. */
  function markSaved(el, value) { el.setAttribute('data-saved', value == null ? el.value : value); }
  function isDirty(el) { return el.value !== el.getAttribute('data-saved'); }
  function keyOf(td) { return td.closest('tr').getAttribute('data-id') + '|' + td.getAttribute('data-field'); }

  /* One request per cell at a time: a cell changed again while its save is
     on the way is sent once more when that one comes back. */
  var inflight = {};
  var again = {};

  function saveCell(td, el) {
    var key = keyOf(td);
    if (!isDirty(el)) { td.classList.remove('ls-bad'); return; }
    if (inflight[key]) { again[key] = [td, el]; return; }
    inflight[key] = true;
    var tr = td.closest('tr');
    var value = el.value;
    begin();
    var p = new URLSearchParams();
    p.set('action', 'save');
    p.set('id', tr.getAttribute('data-id'));
    p.set('field', td.getAttribute('data-field'));
    p.set('value', value);
    call(p).then(function (res) {
      if (res.ok) { markSaved(el, value); }
      end(!!res.ok, res.error);
      td.classList.toggle('ls-bad', !res.ok && !res.transient);
    }).catch(function () { end(false, 'Not saved yet — press Save all in a minute.'); })
      .then(function () {
        delete inflight[key];
        var next = again[key];
        delete again[key];
        if (next) { saveCell(next[0], next[1]); }
      });
  }

  body.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el.classList || !el.classList.contains('ls-cell')) { return; }
    saveCell(el.closest('td'), el);
  });

  /* A long field grows as it is filled, so nothing is typed out of sight. */
  body.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.tagName !== 'TEXTAREA') { return; }
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  });

  /* Enter moves down the column rather than doing nothing, which is what a
     spreadsheet does and what fingers expect. */
  body.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.shiftKey) { return; }
    var el = ev.target;
    if (!el.classList || !el.classList.contains('ls-cell')) { return; }
    if (el.tagName === 'TEXTAREA') { return; }
    ev.preventDefault();
    var td = el.closest('td');
    var tr = td.closest('tr');
    var next = tr.nextElementSibling;
    if (!next) { return; }
    var cell = next.querySelector('td[data-field="' + td.getAttribute('data-field') + '"] .ls-cell');
    if (cell) { cell.focus(); if (cell.select) { cell.select(); } }
  });

  /* ------------------------------------------------------- adding a row */

  function cellFor(field, meta, value) {
    var v = value == null ? '' : value;
    if (meta.type === 'long') {
      var t = document.createElement('textarea');
      t.className = 'ls-cell';
      t.rows = 1;
      t.value = v;
      markSaved(t);
      return t;
    }
    if (meta.list) {
      var s = document.createElement('select');
      s.className = 'ls-cell';
      s.appendChild(new Option('— select —', ''));
      (cfg.lists[meta.list] || []).forEach(function (o) {
        s.appendChild(new Option(o, o, false, o === v));
      });
      markSaved(s);
      return s;
    }
    var i = document.createElement('input');
    i.className = 'ls-cell';
    i.type = meta.type === 'date' ? 'date'
           : (meta.type === 'int' || meta.type === 'dec' || meta.type === 'money'
              ? 'number' : 'text');
    if (meta.type === 'dec' || meta.type === 'money') { i.step = '0.01'; }
    i.value = v;
    markSaved(i);
    return i;
  }

  function buildRow(row) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-id', row.id);

    var num = document.createElement('td');
    num.className = 'ls-num';
    num.innerHTML = '<span class="ls-n"></span><small>' + esc(row.ref) + '</small>';
    tr.appendChild(num);

    if (cfg.budget && !cfg.budgetAfter) { tr.appendChild(budgetCell()); }
    Object.keys(cfg.fields).forEach(function (field) {
      var td = document.createElement('td');
      td.setAttribute('data-field', field);
      td.appendChild(cellFor(field, cfg.fields[field], (row.values || {})[field]));
      tr.appendChild(td);
      if (cfg.budget && field === cfg.budgetAfter) { tr.appendChild(budgetCell()); }
    });

    var img = document.createElement('td');
    img.className = 'ls-imgcol';
    img.setAttribute('data-images', '');
    img.innerHTML = '<div class="ls-imgs">' + addLabel() + '</div>';
    tr.appendChild(img);

    var docs = document.createElement('td');
    docs.className = 'ls-filecol';
    docs.setAttribute('data-files', '');
    docs.innerHTML = '<ul class="ls-files">' + (row.files || []).map(fileItem).join('') + '</ul>' + attachLabel();
    tr.appendChild(docs);

    var act = document.createElement('td');
    act.className = 'ls-rowact';
    act.innerHTML = '<button type="button" class="ls-del" title="Remove this row">&times;</button>';
    tr.appendChild(act);
    return tr;
  }

  function attachLabel() {
    return '<label class="ls-fileadd" title="PDF, Word, Excel, PowerPoint, CSV, text or ZIP — any size, as many as you like">' +
           '+ Add document<input type="file" accept="' + esc(cfg.docAccept || '') + '" multiple hidden></label>';
  }

  /* A badge naming the kind of document, from its extension. */
  function kindOf(name) {
    var ext = String(name || '').split('.').pop().toLowerCase();
    var map = { pdf: 'PDF', doc: 'DOC', docx: 'DOC', xls: 'XLS', xlsx: 'XLS', csv: 'CSV',
                ppt: 'PPT', pptx: 'PPT', txt: 'TXT', zip: 'ZIP', mp4: 'VID', mov: 'VID' };
    return map[ext] || (ext ? ext.slice(0, 3).toUpperCase() : 'FILE');
  }

  function fileItem(f) {
    return '<li data-file="' + f.id + '">' +
           '<span class="ls-kind">' + esc(kindOf(f.name)) + '</span>' +
           '<a href="' + esc(cfg.dl + '?a=' + f.id + '&t=' + encodeURIComponent(cfg.t)) + '" title="' +
           esc(f.name) + '">' + esc(f.name) + '</a>' +
           '<small>' + esc(f.size || '') + '</small>' +
           '<button type="button" class="ls-filex" title="Remove this document">&times;</button></li>';
  }

  /* A file over the server's limit, said before anything is sent; null if none. */
  function tooLarge(files) {
    var max = cfg.maxBytes || 25 * 1024 * 1024;
    var total = 0, big = null;
    Array.prototype.forEach.call(files, function (f) { total += f.size; if (f.size > max && !big) { big = f; } });
    if (big) { return big.name + ' is over ' + (cfg.maxWords || '25 MB') + ', the most this server accepts. Nothing was uploaded.'; }
    if (total > max) { return 'Together these are over ' + (cfg.maxWords || '25 MB') + ' — add them a few at a time.'; }
    return null;
  }

  /* Pictures and documents of any size, as many as are chosen: one file to
     a request, one after another, and a file bigger than one request may
     carry in pieces that the server puts back together. each(res) is told
     of every file; resolves with the errors, if any. */
  function sendFiles(action, field, rowId, files, each) {
    var size = cfg.chunkBytes || 8 * 1024 * 1024, errs = [];
    function one(f, i, count, id) {
      var form = new FormData();
      form.append('action', action);
      form.append('id', rowId);
      if (count > 1) {
        form.append('chunk_id', id);
        form.append('chunk_index', i);
        form.append('chunk_count', count);
        form.append('chunk_offset', i * size);
        form.append('chunk_total', f.size);
        form.append('chunk_name', f.name);
        setState('busy', 'Uploading ' + f.name + ' — ' + Math.floor(i * 100 / count) + '%');
      }
      form.append(field, count > 1 ? f.slice(i * size, (i + 1) * size) : f, f.name);
      return call(form, true).then(function (res) {
        if (res.ok && res.partial) { return one(f, i + 1, count, id); }
        return res;
      });
    }
    return files.reduce(function (p, f, n) {
      return p.then(function () {
        if (files.length > 1) { setState('busy', 'Uploading ' + (n + 1) + ' of ' + files.length + '…'); }
        var id = '';
        while (id.length < 32) { id += Math.random().toString(36).slice(2); }
        return one(f, 0, Math.max(1, Math.ceil(f.size / size)), id.replace(/[^a-z0-9]/g, '').slice(0, 32))
          .then(function (res) {
            if (!res.ok) { errs.push(res.error); return; }
            if (res.warn) { errs.push(res.warn); }
            each(res);
          }, function () { errs.push(f.name + ': no connection.'); });
      });
    }, Promise.resolve()).then(function () { return errs; });
  }

  function addLabel() {
    return '<label class="ls-imgadd" title="Any image type">+' +
           '<input type="file" accept="image/*" multiple hidden></label>';
  }

  /* ------------------------------------------------- Budget Required
     The event's budget form -- one amount per head the admin allows, with
     the quotations behind it -- for an upcoming event only. Amounts save as
     they are left and stay drafts while the sheet is the department's; on
     Submit they go to the admin for review (api/deptsheet.php). No
     allocation or limit is ever sent to this page. */

  function money(n) {
    n = Math.round(Number(n) || 0);
    var s = String(Math.abs(n)), out = s;
    if (s.length > 3) {
      out = s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3);
    }
    return (n < 0 ? '-' : '') + '₹' + out;
  }

  function budgetCell() {
    var td = document.createElement('td');
    td.className = 'ls-budgetcol';
    td.setAttribute('data-budget', '');
    td.innerHTML = '<button type="button" class="ls-budget" title="Budget for this event">Enter budget</button>';
    return td;
  }

  /* Has this row's event happened, going by the dates in its cells now? */
  function rowDone(tr) {
    var end = tr.querySelector('td[data-field="end_date"] .ls-cell');
    var start = tr.querySelector('td[data-field="start_date"] .ls-cell');
    var last = (end && end.value) || (start && start.value) || '';
    if (!last) { return false; }
    var d = new Date();
    var today = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-'
              + String(d.getDate()).padStart(2, '0');
    return last <= today;
  }

  /* The cell follows the row's dates: a budget is for an upcoming event. */
  function refreshBudgetCell(tr, res) {
    var td = tr.querySelector('td[data-budget]');
    if (!td) { return; }
    if (rowDone(tr)) {
      td.innerHTML = '<span class="ls-budgetoff" title="A budget is entered for upcoming events only.">'
                   + 'Upcoming events only</span>';
      return;
    }
    var b = td.querySelector('.ls-budget');
    if (!b) { td.innerHTML = ''; b = budgetCell().firstChild; td.appendChild(b); }
    if (res) {
      b.innerHTML = res.filled
        ? esc(money(res.total)) + '<small>' + res.filled + ' head' + (res.filled === 1 ? '' : 's')
          + (res.files ? ' · ' + res.files + ' file' + (res.files === 1 ? '' : 's') : '') + '</small>'
        : 'Enter budget';
    }
  }

  var bBox = null;
  function budgetBox() {
    if (bBox) { return bBox; }
    var m = document.createElement('div');
    m.className = 'wcmodal evfill';
    m.hidden = true;
    m.innerHTML =
      '<div class="wcmodal-card evfill-card ls-budgetcard" role="dialog" aria-modal="true" aria-labelledby="lsbTitle">' +
        '<h3 id="lsbTitle">Budget</h3>' +
        '<div id="lsbBody"><div class="hint">Loading…</div></div>' +
        '<div class="hint" id="lsbMsg"></div>' +
        '<div class="wcmodal-foot"><button type="button" class="btn btn-primary" id="lsbDone">Done</button></div>' +
      '</div>';
    document.body.appendChild(m);
    function close() { m.hidden = true; }
    m.querySelector('#lsbDone').addEventListener('click', close);
    m.addEventListener('click', function (ev) { if (ev.target === m) { close(); } });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !m.hidden) { close(); }
    });
    bBox = { modal: m, body: m.querySelector('#lsbBody'), msg: m.querySelector('#lsbMsg'),
             title: m.querySelector('#lsbTitle'), tr: null };

    /* An amount is saved when its box is left; a file as soon as it is picked. */
    bBox.body.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.matches('.lsb-amt')) {
        var p = new URLSearchParams();
        p.set('action', 'budget_set');
        p.set('id', bBox.tr.getAttribute('data-id'));
        p.set('item_id', t.closest('tr').getAttribute('data-item'));
        p.set('required', t.value);
        budgetCall(p, false, 'Saved. It goes to the admin for review when you submit the sheet.');
        return;
      }
      if (t.matches('.lsb-file') && t.files && t.files.length) {
        var big = tooLarge(t.files);
        if (big) { bBox.msg.textContent = big; t.value = ''; return; }
        var f = new FormData();
        f.append('action', 'budget_upload');
        f.append('id', bBox.tr.getAttribute('data-id'));
        f.append('item_id', t.closest('tr').getAttribute('data-item'));
        f.append('file', t.files[0]);
        budgetCall(f, true, 'Uploaded.');
      }
    });
    bBox.body.addEventListener('click', function (ev) {
      var x = ev.target.closest ? ev.target.closest('.lsb-drop') : null;
      if (!x || !window.confirm('Remove this document?')) { return; }
      var p = new URLSearchParams();
      p.set('action', 'budget_dropfile');
      p.set('id', bBox.tr.getAttribute('data-id'));
      p.set('file', x.getAttribute('data-file'));
      budgetCall(p, false, 'Removed.');
    });
    return bBox;
  }

  function budgetCall(p, isForm, done) {
    bBox.msg.textContent = 'Saving…';
    begin();
    call(p, isForm).then(function (res) {
      if (!res || !res.ok) {
        end(false, (res && res.error) || 'Not saved');
        bBox.msg.textContent = (res && res.error) || 'That did not save.';
        return;
      }
      end(true);
      renderBudget(res);
      bBox.msg.textContent = done || '';
    }).catch(function () { end(false, 'No connection'); bBox.msg.textContent = 'Could not reach the server.'; });
  }

  function renderBudget(res) {
    var b = bBox, editable = res.editable !== false;
    refreshBudgetCell(b.tr, res);
    var h = '';
    if (!res.open) {
      h += '<p class="hint">This event’s dates have passed. A budget is entered for upcoming events only.</p>';
    } else {
      h += '<p class="hint">Enter what this event needs against each head, and attach the quotations. '
         + 'Everything is sent to the admin for review when you press <strong>Submit</strong> on this sheet; '
         + 'their answer appears on the main sheet.</p>';
    }
    if (!res.lines || !res.lines.length) {
      b.body.innerHTML = h + '<div class="empty">No budget heads have been set up yet. Ask the admin.</div>';
      return;
    }
    h += '<table class="data bqtable lsb-table"><thead><tr><th>Budget head</th>'
       + '<th class="num">Amount required</th><th>Documents / Quotation</th></tr></thead><tbody>';
    var files = res.line_files || {};
    res.lines.forEach(function (l) {
      var frozen = l.state === 'approved' || l.state === 'pending';
      h += '<tr data-item="' + l.item_id + '"><td><strong>' + esc(l.name) + '</strong>'
         + (l.word && l.state !== 'draft' && l.state !== 'unset'
            ? '<div><span class="pill tone-' + esc(l.tone) + '">' + esc(l.word) + '</span></div>' : '')
         + (l.remarks ? '<div class="bqn">' + esc(l.remarks) + '</div>' : '')
         + '</td><td class="num"><input class="lsb-amt bqamt" type="number" min="0" step="0.01" '
         + 'inputmode="decimal" placeholder="0" value="' + (l.required > 0 ? l.required : '') + '"'
         + (editable && !l.retired && !frozen ? '' : ' disabled') + '></td><td><ul class="lsb-files">';
      (files[l.line_id] || []).forEach(function (f) {
        h += '<li><a href="' + esc(cfg.dl + '?a=' + f.id + '&t=' + encodeURIComponent(cfg.t))
           + '" target="_blank" rel="noopener">' + esc(f.name) + '</a> <small>' + esc(f.size) + '</small>'
           + (editable && f.mine ? ' <button type="button" class="ls-filex lsb-drop" data-file="'
              + f.id + '" title="Remove">&times;</button>' : '') + '</li>';
      });
      h += '</ul>' + (editable && !l.retired
           ? '<label class="ls-fileadd">+ Attach<input type="file" class="lsb-file" hidden></label>' : '')
         + '</td></tr>';
    });
    h += '</tbody><tfoot><tr><th>Total required</th><th class="num"><strong>' + esc(money(res.total))
       + '</strong></th><th></th></tr></tfoot></table>';
    b.body.innerHTML = h;
  }

  function openBudget(tr) {
    var b = budgetBox();
    b.tr = tr;
    var n = tr.querySelector('td[data-field="event_name"] .ls-cell');
    var ref = tr.querySelector('.ls-num small');
    var name = (n && n.value) || (ref && ref.textContent) || '';
    b.title.textContent = 'Budget' + (name ? ' · ' + name : '');
    b.body.innerHTML = '<div class="hint">Loading…</div>';
    b.msg.textContent = '';
    b.modal.hidden = false;
    var p = new URLSearchParams();
    p.set('action', 'budget');
    p.set('id', tr.getAttribute('data-id'));
    call(p).then(function (res) {
      if (!res || !res.ok) {
        b.body.innerHTML = '<div class="empty">' + esc((res && res.error) || 'Not available.') + '</div>';
        return;
      }
      renderBudget(res);
    }).catch(function () { b.body.innerHTML = '<div class="empty">Could not reach the server.</div>'; });
  }

  if (cfg.budget) {
    body.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.ls-budget') : null;
      if (b) { ev.preventDefault(); openBudget(b.closest('tr')); }
    });
    /* A date changed: the event may have moved past today, or back. */
    body.addEventListener('change', function (ev) {
      var td = ev.target.closest ? ev.target.closest('td[data-field="start_date"], td[data-field="end_date"]') : null;
      if (td) { refreshBudgetCell(td.closest('tr')); }
    });
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

  /* ------------------------------------- adding an event, Upcoming or Completed
     + Add row first asks which kind of event this is. Upcoming opens the
     planning columns; Completed opens every column, results included. The
     row is made, already filled in, only when the box is saved -- so a box
     that is closed leaves no empty row behind -- and it lands on the sheet
     with all its columns, the rest still there to fill. */
  var addBox = null;

  function stageBox() {
    if (addBox) { return addBox; }
    var m = document.createElement('div');
    m.className = 'wcmodal evfill';
    m.hidden = true;
    m.innerHTML =
      '<div class="wcmodal-card evfill-card" role="dialog" aria-modal="true" aria-labelledby="lsStageTitle">' +
        '<h3 id="lsStageTitle">Add an event</h3>' +
        '<div class="hint">Is this event still to come, or has it already happened? ' +
          'Upcoming opens the planning columns; Completed opens every column, results included.</div>' +
        '<label for="lsStage">Event is</label>' +
        '<select id="lsStage">' +
          '<option value="">— choose —</option>' +
          '<option value="upcoming">Upcoming — still to come</option>' +
          '<option value="completed">Completed — already happened</option>' +
        '</select>' +
        '<form id="lsStageForm" novalidate>' +
          '<div class="ss-addfields" id="lsStageFields"></div>' +
          '<div class="hint err" id="lsStageErr"></div>' +
          '<div class="wcmodal-foot">' +
            '<button type="button" class="btn" id="lsStageCancel">Cancel</button>' +
            '<button type="submit" class="btn btn-primary" id="lsStageGo" disabled>Add event</button>' +
          '</div>' +
        '</form>' +
      '</div>';
    document.body.appendChild(m);

    var pick   = m.querySelector('#lsStage');
    var fields = m.querySelector('#lsStageFields');
    var err    = m.querySelector('#lsStageErr');
    var go     = m.querySelector('#lsStageGo');
    var form   = m.querySelector('#lsStageForm');

    function close() { m.hidden = true; }
    m.querySelector('#lsStageCancel').addEventListener('click', close);
    m.addEventListener('click', function (ev) { if (ev.target === m) { close(); } });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !m.hidden) { close(); }
    });

    /* The columns for the kind chosen, in the sheet's own order. */
    pick.addEventListener('change', function () {
      var stage = pick.value;
      fields.innerHTML = '';
      err.textContent = '';
      go.disabled = !stage;
      if (!stage) { return; }
      var n = 0;
      Object.keys(cfg.fields).forEach(function (field) {
        if (stage === 'upcoming' && (cfg.stages || {})[field] === 'result') { return; }
        var meta = cfg.fields[field];
        var l = document.createElement('label');
        l.htmlFor = 'lsf_' + field;
        l.textContent = meta.label + (stage === 'completed' && field === 'end_date' ? ' *' : '');
        var inp = cellFor(field, meta, '');
        inp.id = 'lsf_' + field;
        inp.setAttribute('data-field', field);
        if (inp.tagName === 'TEXTAREA') { inp.rows = 3; }
        fields.appendChild(l);
        fields.appendChild(inp);
        n++;
      });
      var h = document.createElement('div');
      h.className = 'hint';
      h.textContent = stage === 'completed'
        ? n + ' columns, results included. The End Date (or Start Date) must be today or earlier.'
        : n + ' planning columns. The results are filled in once the event is over.';
      fields.insertBefore(h, fields.firstChild);
      designGate(fields);
      if (stage === 'upcoming' && cfg.budget) { fields.appendChild(budgetSection()); }
      if (stage === 'upcoming') { fields.appendChild(resultsOwner()); }
      var first = fields.querySelector('input, select, textarea');
      if (first) { first.focus(); }
    });

    /* Budget Required, in the same box: one amount per head the admin
       allows, and the quotations behind each. No limits are shown -- this
       page is never told them. Saved with the event, as drafts; they go to
       the admin for review when the sheet is submitted. */
    function budgetSection() {
      var wrap = document.createElement('div');
      wrap.className = 'lsx-budget';
      var heads = cfg.budgetHeads || [];
      var h = '<h4>Budget Required</h4>';
      if (!heads.length) {
        wrap.innerHTML = h + '<div class="hint">No budget heads have been set up yet.</div>';
        return wrap;
      }
      h += '<div class="hint">Enter what this event needs against each head, and attach the '
         + 'quotations. It goes to the admin for review when you submit the sheet.</div>'
         + '<table class="data bqtable lsb-table"><thead><tr><th>Budget head</th>'
         + '<th class="num">Amount required</th><th>Documents / Quotation</th></tr></thead><tbody>';
      heads.forEach(function (hd) {
        h += '<tr><td><strong>' + esc(hd.name) + '</strong></td>'
           + '<td class="num"><input class="lsx-bamt bqamt" data-item="' + hd.id
           + '" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0"></td>'
           + '<td><label class="ls-fileadd">+ Attach<input type="file" class="lsx-bfile" data-item="'
           + hd.id + '" multiple hidden></label><ul class="lsb-files"></ul></td></tr>';
      });
      h += '</tbody><tfoot><tr><th>Total required</th><th class="num"><strong class="lsx-btotal">'
         + esc(money(0)) + '</strong></th><th></th></tr></tfoot></table>';
      wrap.innerHTML = h;
      return wrap;
    }

    /* Who sends the results once the event is over. Handed-in rows leave
       this sheet, so they are emailed a link to a short results form the day
       after it ends (lib/evresults.php). Not data-field: not a column. */
    function resultsOwner() {
      var c = cfg.contact || {};
      var wrap = document.createElement('div');
      wrap.className = 'ss-resowner';
      wrap.innerHTML = '<strong>Who will send the results after the event?</strong>'
        + '<div class="hint">The day after the event ends they get an email with a link to a short '
        + 'results form, and it is listed under Results due here. Reminders follow until the results are in.</div>'
        + '<label for="lsResName">Name</label><input id="lsResName">'
        + '<label for="lsResEmail">Email</label><input id="lsResEmail" type="email" placeholder="name@example.com">';
      wrap.querySelector('#lsResName').value = c.name || '';
      wrap.querySelector('#lsResEmail').value = c.email || '';
      return wrap;
    }

    /* The total follows the amounts; a chosen file is listed by name. */
    fields.addEventListener('input', function (ev) {
      if (!ev.target.matches('.lsx-bamt')) { return; }
      var sum = 0;
      Array.prototype.forEach.call(fields.querySelectorAll('.lsx-bamt'), function (i) {
        sum += Number(i.value) || 0;
      });
      var t = fields.querySelector('.lsx-btotal');
      if (t) { t.textContent = money(sum); }
    });
    fields.addEventListener('change', function (ev) {
      if (!ev.target.matches('.lsx-bfile')) { return; }
      var ul = ev.target.closest('td').querySelector('.lsb-files');
      ul.innerHTML = Array.prototype.map.call(ev.target.files, function (f) {
        return '<li>' + esc(f.name) + '</li>';
      }).join('');
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var stage = pick.value;
      if (!stage) { err.textContent = 'Choose Upcoming or Completed first.'; return; }
      var vals = {};
      Array.prototype.forEach.call(fields.querySelectorAll('[data-field]'), function (i) {
        if ((i.value || '').trim() !== '') { vals[i.getAttribute('data-field')] = i.value; }
      });
      if (!Object.keys(vals).length) { err.textContent = 'Fill in at least the event’s name and dates.'; return; }
      var p = new FormData();
      p.append('action', 'addfilled');
      p.append('stage', stage);
      p.append('values', JSON.stringify(vals));
      if (stage === 'upcoming') {
        var budget = {}, all = [];
        Array.prototype.forEach.call(fields.querySelectorAll('.lsx-bamt'), function (i) {
          if ((i.value || '').trim() !== '') { budget[i.getAttribute('data-item')] = i.value; }
        });
        p.append('budget', JSON.stringify(budget));
        Array.prototype.forEach.call(fields.querySelectorAll('.lsx-bfile'), function (i) {
          Array.prototype.forEach.call(i.files || [], function (f) {
            p.append('bfile_' + i.getAttribute('data-item') + '[]', f);
            all.push(f);
          });
        });
        var big = all.length ? tooLarge(all) : null;
        if (big) { err.textContent = big; return; }
        var rn = fields.querySelector('#lsResName');
        var re = fields.querySelector('#lsResEmail');
        var mail = re ? re.value.trim() : '';
        if (mail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) {
          err.textContent = 'The results email does not look like an email address.';
          re.focus();
          return;
        }
        p.append('results_name', rn ? rn.value.trim() : '');
        p.append('results_email', mail);
      }
      go.disabled = true;
      err.textContent = 'Adding…';
      begin();
      call(p, true).then(function (res) {
        if (!res || !res.ok) {
          end(true);
          go.disabled = false;
          err.textContent = (res && res.error) || 'That did not go through. Nothing was added.';
          return;
        }
        close();
        var tr = buildRow(res.row);
        body.appendChild(tr);
        renumber();
        if (cfg.budget) { refreshBudgetCell(tr, res.budget || null); }
        tr.scrollIntoView({ block: 'nearest' });
        if (res.warn) { end(false, res.warn); } else { end(true); }
      }).catch(function () {
        end(false, 'No connection');
        go.disabled = false;
        err.textContent = 'Could not reach the server. Nothing was added.';
      });
    });

    addBox = { modal: m, pick: pick, fields: fields, err: err, go: go };
    return addBox;
  }

  document.getElementById('lsadd').addEventListener('click', function (ev) {
    if (!cfg.stages) { return; }             // an older page: the plain add below
    ev.stopImmediatePropagation();
    var b = stageBox();
    b.pick.value = '';
    b.fields.innerHTML = '';
    b.err.textContent = '';
    b.go.disabled = true;
    b.modal.hidden = false;
    b.pick.focus();
  });

  document.getElementById('lsadd').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    begin();
    var p = new URLSearchParams();
    p.set('action', 'add');
    call(p).then(function (res) {
      btn.disabled = false;
      if (!res.ok) { end(false, res.error); return; }
      end(true);
      var tr = buildRow(res.row);
      body.appendChild(tr);
      renumber();
      var first = tr.querySelector('.ls-cell');
      if (first) { first.focus(); }
      tr.scrollIntoView({ block: 'nearest' });
    }).catch(function () { btn.disabled = false; end(false, 'No connection'); });
  });

  /* ----------------------------------------------------- removing a row */

  body.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.ls-del') : null;
    if (!b) { return; }
    if (!window.confirm('Remove this row and any images and documents on it? This cannot be undone.')) { return; }
    var tr = b.closest('tr');
    begin();
    var p = new URLSearchParams();
    p.set('action', 'delete');
    p.set('id', tr.getAttribute('data-id'));
    call(p).then(function (res) {
      if (!res.ok) { end(false, res.error); return; }
      end(true);
      tr.remove();
      renumber();
    }).catch(function () { end(false, 'No connection'); });
  });

  /* -------------------------------------------------------- the images */

  function figure(rowId, img) {
    var full = cfg.dl + '?a=' + img.id + '&t=' + encodeURIComponent(cfg.t);
    return '<figure data-image="' + img.id + '">' +
           '<a href="' + esc(full) + '" target="_blank" rel="noopener">' +
           '<img src="' + esc(cfg.dl + '?a=' + img.id + '&thumb=1&t=' + encodeURIComponent(cfg.t)) +
           '" alt="' + esc(img.name) + '" loading="lazy" decoding="async"></a>' +
           '<button type="button" class="ls-imgx" title="Remove this image">&times;</button>' +
           '</figure>';
  }

  /* A picture that will not load is tried once more, then shown as a plain
     tile, so a broken icon and its file name do not spill over the grid. */
  body.addEventListener('error', function (ev) {
    var img = ev.target;
    if (!img || img.tagName !== 'IMG' || !img.closest('.ls-imgs')) { return; }
    var fig = img.closest('figure');
    if (!img.dataset.retried) {
      img.dataset.retried = '1';
      img.src = img.src + (img.src.indexOf('?') < 0 ? '?' : '&') + 'r=' + Date.now();
      return;
    }
    if (fig) { fig.classList.add('ls-imgbad'); fig.title = img.alt; }
  }, true);

  body.addEventListener('change', function (ev) {
    var input = ev.target;
    if (input.type !== 'file' || !input.closest('.ls-imgadd')) { return; }
    if (!input.files || !input.files.length) { return; }
    var tr  = input.closest('tr');
    var box = input.closest('.ls-imgs');
    var files = Array.prototype.slice.call(input.files);
    input.value = '';
    begin();
    var label = box.querySelector('.ls-imgadd');
    sendFiles('upload', 'images[]', tr.getAttribute('data-id'), files, function (res) {
      (res.images || []).forEach(function (img) {
        label.insertAdjacentHTML('beforebegin', figure(tr.getAttribute('data-id'), img));
      });
    }).then(function (errs) {
      if (errs.length) { end(false, errs.join(' ')); } else { end(true); }
    });
  });

  body.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.ls-imgx') : null;
    if (!b) { return; }
    var fig = b.closest('figure');
    var tr  = b.closest('tr');
    if (!window.confirm('Remove this image?')) { return; }
    begin();
    var p = new URLSearchParams();
    p.set('action', 'dropimage');
    p.set('id', tr.getAttribute('data-id'));
    p.set('image', fig.getAttribute('data-image'));
    call(p).then(function (res) {
      if (!res.ok) { end(false, res.error); return; }
      end(true);
      fig.remove();
    }).catch(function () { end(false, 'No connection'); });
  });

  /* ----------------------------------------------------- the documents */

  body.addEventListener('change', function (ev) {
    var input = ev.target;
    if (input.type !== 'file' || !input.closest('.ls-fileadd')) { return; }
    if (!input.files || !input.files.length) { return; }
    var tr   = input.closest('tr');
    var list = tr.querySelector('.ls-files');
    var label = input.closest('.ls-fileadd');

    var files = Array.prototype.slice.call(input.files);
    input.value = '';
    label.classList.add('busy');
    begin();
    setState('busy', 'Uploading…');
    sendFiles('attach', 'files[]', tr.getAttribute('data-id'), files, function (res) {
      (res.files || []).forEach(function (f) { list.insertAdjacentHTML('beforeend', fileItem(f)); });
    }).then(function (errs) {
      label.classList.remove('busy');
      if (errs.length) { end(false, errs.join(' ')); } else { end(true); }
    });
  });

  body.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.ls-filex') : null;
    if (!b) { return; }
    var li = b.closest('li');
    var tr = b.closest('tr');
    var name = (li.querySelector('a') || {}).textContent || 'this document';
    if (!window.confirm('Remove ' + name + '?')) { return; }
    begin();
    var p = new URLSearchParams();
    p.set('action', 'dropfile');
    p.set('id', tr.getAttribute('data-id'));
    p.set('file', li.getAttribute('data-file'));
    call(p).then(function (res) {
      if (!res.ok) { end(false, res.error); return; }
      end(true);
      li.remove();
    }).catch(function () { end(false, 'No connection'); });
  });

  /* --------------------------------------------------------- Save all */

  /* Cells save themselves as they change. Save all commits a cell left
     focused, then sends whatever has not reached the server yet -- only the
     changed cells, a hundred to a request, one request after another. It
     used to send every cell on the sheet at once, one request each, and the
     host took that burst for an attack (503, then 403 on the whole site). */
  var BATCH = 100;

  function saveAll() {
    if (document.activeElement && body.contains(document.activeElement) && document.activeElement.blur) {
      document.activeElement.blur();
    }
    var dirty = Array.prototype.filter.call(body.querySelectorAll('.ls-cell'), function (el) {
      return isDirty(el) && !inflight[keyOf(el.closest('td'))];
    });
    if (!dirty.length) {
      if (pending === 0) { setState('', 'Live — everything saved'); }
      return Promise.resolve(true);
    }
    var chunks = [];
    for (var i = 0; i < dirty.length; i += BATCH) { chunks.push(dirty.slice(i, i + BATCH)); }
    var allOk = true;
    return chunks.reduce(function (prev, chunk) {
      return prev.then(function () {
        var sent = chunk.map(function (el) {
          var td = el.closest('td');
          return { el: el, td: td, key: keyOf(td), value: el.value };
        });
        var p = new URLSearchParams();
        p.set('action', 'saveall');
        p.set('cells', JSON.stringify(sent.map(function (c) {
          return { id: c.td.closest('tr').getAttribute('data-id'),
                   field: c.td.getAttribute('data-field'), value: c.value };
        })));
        begin();
        return call(p).then(function (res) {
          if (!res.ok) { allOk = false; end(false, res.error); return; }
          var bad = '';
          sent.forEach(function (c) {
            var r = (res.results || {})[c.key];
            if (r === true) {
              markSaved(c.el, c.value);
              c.td.classList.remove('ls-bad');
            } else {
              bad = bad || r || 'Not saved';
              c.td.classList.add('ls-bad');
            }
          });
          if (bad) { allOk = false; }
          end(!bad, bad);
        });
      });
    }, Promise.resolve()).then(function () { return allOk; });
  }

  document.getElementById('lssave').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    saveAll().then(function () { btn.disabled = false; },
                   function () { btn.disabled = false; });
  });

  /* Submit hands the sheet over, so it waits for every change to land
     first: a row submitted while its last edits were still on the way
     would reach the organisers without them. */
  function idle() {
    return new Promise(function (ok) {
      var t0 = Date.now();
      (function wait() {
        if (pending === 0 || Date.now() - t0 > 90000) { ok(); return; }
        setTimeout(wait, 250);
      })();
    });
  }
  var subForm = document.querySelector('.ls-submitform');
  if (subForm) {
    subForm.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }       // they said no to the confirm
      var needs = pending > 0 || Array.prototype.some.call(body.querySelectorAll('.ls-cell'), isDirty);
      if (!needs) { return; }
      ev.preventDefault();
      setState('busy', 'Saving your last changes before submitting…');
      idle().then(saveAll).then(idle).then(function () {
        var left = Array.prototype.some.call(body.querySelectorAll('.ls-cell'), isDirty);
        if (left) {
          setState('bad', 'Some changes could not be saved yet, so nothing was submitted. Press Save all, then Submit again.');
          return;
        }
        subForm.submit();
      });
    });
  }

  /* Every cell the server drew is, by definition, saved. */
  Array.prototype.forEach.call(body.querySelectorAll('.ls-cell'), function (el) { markSaved(el); });

  /* Leaving with a change not yet saved would lose it. */
  window.addEventListener('beforeunload', function (ev) {
    if (pending > 0) { ev.preventDefault(); ev.returnValue = ''; }
  });

  /* A textarea that arrived with text in it should already be the right size. */
  Array.prototype.forEach.call(body.querySelectorAll('textarea'), function (t) {
    t.style.height = 'auto';
    t.style.height = Math.min(t.scrollHeight, 120) + 'px';
  });

  renumber();
})();
