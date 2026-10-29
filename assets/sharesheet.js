/* ------------------------------------------------------------------ *
 * The shared live sheet (s.php).
 *
 * Draws every column of the shared sheet from what the server says the
 * sheet is today, saves each cell as it is left, and asks again every few
 * seconds so the page stays live: rows the team adds or changes on the main
 * sheet appear here, and a column renamed, added or taken off on Sheet
 * Management reaches the open page without a reload.
 *
 * Built with DOM calls and textContent throughout -- every value here was
 * typed by somebody, and none of it is ever treated as markup.
 * ------------------------------------------------------------------ */
(function () {
  'use strict';

  var C = window.FABO_SS;
  if (!C) { return; }

  var POLL_MS = 12000;

  var state = {
    columns: C.columns,          // [{field,label,type,list,mode,note}]
    lists: C.lists,              // {listName: [values]}
    sig: C.sig,
    rows: {},                    // id -> payload
    order: [],                   // ids in sheet order
    pending: 0,                  // saves and uploads in flight
    dead: false
  };

  var body   = document.getElementById('ssbody');
  var head   = document.getElementById('sshead');
  var empty  = document.getElementById('ssempty');
  var stateEl = document.getElementById('ssstate');
  var countEl = document.getElementById('sscount');
  var syncEl  = document.getElementById('sssync');

  /* ------------------------------------------------------------ helpers */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  function fileUrl(id) {
    return C.dl + '?f=' + encodeURIComponent(id) + '&s=' + encodeURIComponent(C.k);
  }

  function kindOf(name) {
    var ext = String(name || '').split('.').pop().toLowerCase();
    var map = { pdf: 'PDF', doc: 'DOC', docx: 'DOC', xls: 'XLS', xlsx: 'XLS', csv: 'CSV',
                ppt: 'PPT', pptx: 'PPT', txt: 'TXT', zip: 'ZIP', mp4: 'VID', mov: 'VID' };
    return map[ext] || (ext ? ext.slice(0, 3).toUpperCase() : 'FILE');
  }

  function say(text, tone) {
    stateEl.className = 'ls-state' + (tone ? ' ' + tone : '');
    stateEl.lastChild.textContent = text;
  }

  function idle() {
    if (state.pending === 0 && !state.dead) { say('Live — everything saved'); }
  }

  var toastTimer = null;
  function toast(text, bad) {
    var t = document.getElementById('sstoast');
    t.textContent = text;
    t.className = 'ss-toast show' + (bad ? ' bad' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.className = 'ss-toast'; }, bad ? 6000 : 2600);
  }

  /* Shared hosting reads a burst of requests as a flood: it answers 503,
     then its firewall answers 403 for the whole site. So requests go out
     two at a time, and an answer from the host rather than the portal
     (busy, refused, no connection) is tried again after a pause instead of
     being shown. Only the portal's own JSON answers are final. */
  var MAX_LIVE = 2, RETRY_MS = [1500, 3000, 6000, 12000, 20000], live = 0, waiting = [];

  function pump() {
    while (live < MAX_LIVE && waiting.length) {
      var job = waiting.shift();
      live++;
      send(job.fd, 0).then(job.ok, job.no).then(function () { live--; pump(); });
    }
  }

  function send(fd, attempt) {
    return fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t.slice(Math.max(0, t.indexOf('{')))); } catch (e) {
            if (r.status === 413) {
              return { status: r.status, j: { ok: false,
                error: 'That file is bigger than this server accepts (limit ' + C.maxWords + '). Nothing was uploaded.' } };
            }
            /* The host's own 403 page: it has paused this network for sending
               too much at once, and asking again at once keeps it paused. */
            if (r.status === 403 || r.status === 429) {
              return { status: r.status, j: { ok: false,
                error: 'The server has paused this network for a few minutes. Your change is kept on this page — try again in 5 minutes.' } };
            }
          }
          return j ? { status: r.status, j: j } : null;   // null: the host answered, not the portal
        });
      }, function () { return null; })
      .then(function (res) {
        if (res) { return res; }
        if (attempt >= RETRY_MS.length) {
          return { status: 0, j: { ok: false,
            error: 'The server is busy. Your change is kept on this page — try again in a minute.' } };
        }
        say('Saving… the server is busy, trying again', 'busy');
        return new Promise(function (ok) { setTimeout(ok, RETRY_MS[attempt]); })
          .then(function () { return send(fd, attempt + 1); });
      });
  }

  /** One call to the endpoint; resolves with the JSON, rejects with a sentence. */
  function call(action, data, files) {
    var fd = new FormData();
    fd.append('k', C.k);
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    (files || []).forEach(function (f) { if (f[2]) { fd.append(f[0], f[1], f[2]); } else { fd.append(f[0], f[1]); } });
    return new Promise(function (ok, no) {
      waiting.push({ fd: fd, ok: ok, no: no });
      pump();
    }).then(function (res) {
      // Only the portal's own refusal closes the sheet, never the host's page.
      if (res.status === 403 && !res.j.ok) { gone(res.j.error); }
      if (!res.j.ok) { throw new Error(res.j.error || 'That did not go through.'); }
      return res.j;
    });
  }

  /** The link was closed while the page was open: stop, and say why. */
  function gone(msg) {
    if (state.dead) { return; }
    state.dead = true;
    say('Link closed', 'bad');
    var b = document.getElementById('ssgone');
    b.textContent = msg || 'This link is no longer available.';
    b.hidden = false;
    document.querySelectorAll('#ssgrid input, #ssgrid select, #ssgrid textarea, #ssgrid button, #ssadd')
      .forEach(function (n) { n.disabled = true; });
  }

  function busy(on) {
    state.pending += on ? 1 : -1;
    if (state.pending < 0) { state.pending = 0; }
    if (state.pending) { say('Saving…', 'busy'); } else { idle(); }
  }

  /* ---------------------------------------------------------- columns */

  /* Everything but the picture gallery sits in the sheet's own order; the
     gallery joins Documents under one Attachments heading at the end. */
  function mainCols() {
    return state.columns.filter(function (c) { return c.mode !== 'images'; });
  }
  function galleryCol() {
    var g = state.columns.filter(function (c) { return c.mode === 'images'; })[0];
    return g || { field: '', label: 'Images', type: 'images', mode: 'images', list: null, note: '' };
  }

  /* ------------------------------------------------------------- header */

  function drawHead() {
    head.textContent = '';
    var tr = el('tr', 'ls-h1');
    var th0 = el('th', 'ls-num', '#');
    th0.rowSpan = 2;
    tr.appendChild(th0);
    mainCols().forEach(function (c) {
      var th = el('th', 'ss-th-' + c.mode);
      th.rowSpan = 2;
      th.dataset.field = c.field;
      th.appendChild(document.createTextNode(c.label));
      if (c.mode === 'read') {
        var i = el('span', 'ss-lock', 'auto');
        i.title = c.note || 'Filled in by the portal.';
        th.appendChild(i);
      }
      tr.appendChild(th);
    });
    var grp = el('th', 'ls-attgroup', 'Attachments');
    grp.colSpan = 2;
    tr.appendChild(grp);
    var act = el('th', 'ls-rowact');
    act.rowSpan = 2;
    tr.appendChild(act);
    head.appendChild(tr);
    var tr2 = el('tr', 'ls-h2');
    var g = galleryCol();
    var thi = el('th', 'ls-imgcol', 'Images');
    if (g.label && g.label !== 'Images') { thi.title = 'The sheet’s ' + g.label + ' column'; }
    tr2.appendChild(thi);
    tr2.appendChild(el('th', 'ls-filecol', 'Documents'));
    head.appendChild(tr2);
  }

  /* -------------------------------------------------------------- cells */

  function editor(c, row) {
    var v = row.values[c.field] !== undefined ? row.values[c.field] : '';
    var n;
    if (c.type === 'checkbox') {
      n = el('input', 'ss-check');
      n.type = 'checkbox';
      n.checked = v === '1';
      return n;
    }
    if (c.list) {
      n = el('select', 'ls-cell');
      n.appendChild(new Option('— select —', ''));
      var vals = state.lists[c.list] || [];
      vals.forEach(function (o) { n.appendChild(new Option(o, o)); });
      if (v !== '' && vals.indexOf(v) < 0) { n.appendChild(new Option(v + ' (not in list)', v)); }
      n.value = v;
      return n;
    }
    if (c.type === 'long') {
      n = el('textarea', 'ls-cell');
      n.rows = 1;
      n.value = v;
      return n;
    }
    n = el('input', 'ls-cell');
    n.type = c.type === 'date' ? 'date'
           : (['int', 'dec', 'money'].indexOf(c.type) >= 0 ? 'number'
           : (c.type === 'url' ? 'url' : 'text'));
    if (c.type === 'dec' || c.type === 'money') { n.step = 'any'; }
    if (c.type === 'url') { n.placeholder = 'https://'; }
    n.value = v;
    return n;
  }

  function cellValue(input) {
    return input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
  }

  function fillCell(td, c, row) {
    td.textContent = '';
    td.className = 'ss-' + c.mode;
    td.dataset.field = c.field;
    if (c.mode === 'edit') {
      var lock = row.locked && row.locked[c.field];
      if (lock) {
        td.classList.add('ss-shut');
        var s = el('span', 'ss-ro', row.values[c.field] || '—');
        s.title = lock;
        td.appendChild(s);
        td.title = lock;
        return;
      }
      var inp = editor(c, row);
      inp.dataset.saved = cellValue(inp);
      td.appendChild(inp);
      return;
    }
    if (c.mode === 'read') {
      var r = el('span', 'ss-ro', row.values[c.field] || '');
      if (c.note) { r.title = c.note; }
      td.appendChild(r);
      return;
    }
    if (c.mode === 'images') {
      td.classList.add('ls-imgcol');
      var box = el('div', 'ls-imgs');
      (row.images || []).forEach(function (a) {
        var fig = el('figure');
        fig.dataset.file = a.id;
        var link = el('a');
        link.href = fileUrl(a.id);
        link.target = '_blank';
        link.rel = 'noopener';
        var img = el('img');
        img.src = fileUrl(a.id) + '&thumb=1';
        img.decoding = 'async';
        img.alt = a.name;
        img.loading = 'lazy';
        link.appendChild(img);
        fig.appendChild(link);
        var x = el('button', 'ls-imgx js-drop', '×');
        x.type = 'button';
        x.title = 'Remove this picture';
        fig.appendChild(x);
        box.appendChild(fig);
      });
      var add = el('label', 'ls-imgadd', '+');
      add.title = 'Add pictures — any size, as many as you like';
      var f = el('input', 'js-up-images');
      f.type = 'file';
      f.accept = 'image/*';
      f.multiple = true;
      f.hidden = true;
      add.appendChild(f);
      box.appendChild(add);
      td.appendChild(box);
      return;
    }
    if (c.mode === 'file') {
      var cur = row.cells ? row.cells[c.field] : null;
      if (cur) {
        var chip = el('div', 'ss-chip');
        chip.dataset.file = cur.id;
        if (cur.image) {
          var th = el('img');
          th.src = fileUrl(cur.id) + '&thumb=1';
          th.alt = '';
          chip.appendChild(th);
        } else {
          chip.appendChild(el('span', 'ls-kind', kindOf(cur.name)));
        }
        var a2 = el('a', '', cur.name);
        a2.href = fileUrl(cur.id);
        a2.target = '_blank';
        a2.rel = 'noopener';
        chip.appendChild(a2);
        var x2 = el('button', 'ls-filex js-drop', '×');
        x2.type = 'button';
        x2.title = 'Remove';
        chip.appendChild(x2);
        td.appendChild(chip);
      } else {
        var up = el('label', 'ls-fileadd', c.type === 'image' ? '+ Image' : '+ File');
        var fi = el('input', 'js-up-cell');
        fi.type = 'file';
        fi.hidden = true;
        fi.accept = c.type === 'image' ? 'image/*' : C.docAccept;
        up.appendChild(fi);
        td.appendChild(up);
      }
    }
  }

  function fillDocs(td, row) {
    td.textContent = '';
    td.className = 'ls-filecol';
    var ul = el('ul', 'ls-files');
    (row.files || []).forEach(function (a) {
      var li = el('li');
      li.dataset.file = a.id;
      li.appendChild(el('span', 'ls-kind', kindOf(a.name)));
      var link = el('a', '', a.name);
      link.href = fileUrl(a.id);
      link.title = a.name;
      li.appendChild(link);
      li.appendChild(el('small', '', a.size));
      var x = el('button', 'ls-filex js-drop', '×');
      x.type = 'button';
      x.title = 'Remove this document';
      li.appendChild(x);
      ul.appendChild(li);
    });
    td.appendChild(ul);
    var add = el('label', 'ls-fileadd', '+ Add document');
    add.title = 'PDF, Word, Excel, PowerPoint, CSV, text or ZIP — any size, as many as you like';
    var f = el('input', 'js-up-files');
    f.type = 'file';
    f.accept = C.docAccept;
    f.multiple = true;
    f.hidden = true;
    add.appendChild(f);
    td.appendChild(add);
  }

  /* --------------------------------------------------------------- rows */

  function drawRow(row) {
    var tr = el('tr');
    tr.dataset.id = row.id;
    var num = el('td', 'ls-num');
    num.appendChild(el('span', 'ls-n', ''));
    num.appendChild(el('small', '', row.ref || ''));
    tr.appendChild(num);
    mainCols().forEach(function (c) {
      var td = el('td');
      fillCell(td, c, row);
      tr.appendChild(td);
    });
    var gal = el('td');
    fillCell(gal, galleryCol(), row);
    tr.appendChild(gal);
    var docs = el('td');
    fillDocs(docs, row);
    tr.appendChild(docs);
    var act = el('td', 'ls-rowact');
    if (row.mine) {
      var del = el('button', 'ls-del js-del', '×');
      del.type = 'button';
      del.title = 'Remove this row';
      act.appendChild(del);
    } else {
      var t = el('span', 'ss-team', '•');
      t.title = 'Added by the team — it can be edited here, not removed.';
      act.appendChild(t);
    }
    tr.appendChild(act);
    if (!row.mine) { tr.classList.add('ss-theirs'); }
    return tr;
  }

  /**
   * Bring a drawn row up to date with a fresh payload, leaving alone the
   * cell somebody is typing in and any cell whose save has not come back.
   */
  function refreshRow(tr, row) {
    var active = document.activeElement;
    var cells = tr.children;
    var cols = mainCols();
    cols.concat([galleryCol()]).forEach(function (c, i) {
      var td = cells[i + 1];
      if (!td) { return; }
      if (td.contains(active) || td.classList.contains('ss-saving')) { return; }
      if (c.mode === 'edit') {
        var inp = td.querySelector('.ls-cell, .ss-check');
        var lockedNow = !!(row.locked && row.locked[c.field]);
        if (!inp || lockedNow !== td.classList.contains('ss-shut')) { fillCell(td, c, row); return; }
        var v = row.values[c.field] !== undefined ? row.values[c.field] : '';
        if (cellValue(inp) !== inp.dataset.saved) { return; }        // typed, not yet left
        if (inp.dataset.saved !== v) {
          if (inp.tagName === 'SELECT') {
            fillCell(td, c, row);                                     // options may differ
          } else if (inp.type === 'checkbox') {
            inp.checked = v === '1';
          } else {
            inp.value = v;
          }
          var now = td.querySelector('.ls-cell, .ss-check');
          if (now) { now.dataset.saved = cellValue(now); }
          td.classList.add('ss-flash');
          setTimeout(function () { td.classList.remove('ss-flash'); }, 1400);
        }
        return;
      }
      fillCell(td, c, row);
    });
    var docs = cells[cols.length + 2];
    if (docs && !docs.contains(active) && !docs.classList.contains('ss-saving')) { fillDocs(docs, row); }
    state.rows[row.id] = row;
    drawDue();
  }

  function renumber() {
    var trs = body.querySelectorAll('tr');
    trs.forEach(function (tr, i) { tr.querySelector('.ls-n').textContent = i + 1; });
    countEl.textContent = trs.length;
    document.getElementById('sscountword').textContent = trs.length === 1 ? 'row' : 'rows';
    empty.hidden = trs.length > 0;
    drawDue();
  }

  /* ------------------------------------------------------ results due
     Events of this sheet that have ended and still owe their results, above
     the grid, each with its own results link (lib/evresults.php). Redrawn
     from the rows whenever they change, so it empties as results come in. */
  var dueBox = document.getElementById('ssdue');
  function drawDue() {
    if (!dueBox) { return; }
    var list = document.getElementById('ssduelist');
    var due = [];
    Object.keys(state.rows).forEach(function (id) {
      var r = state.rows[id].results;
      if (r && r.url && r.key !== 'submitted') { due.push(r); }
    });
    var rank = { overdue: 0, due: 1, started: 2 };
    due.sort(function (a, b) {
      return (rank[a.key] - rank[b.key]) || ((b.days || 0) - (a.days || 0));
    });
    list.textContent = '';
    due.forEach(function (r) {
      var li = el('li', 'ss-due-' + r.key);
      var txt = el('div', 'ss-duetxt');
      txt.appendChild(el('strong', '', r.title));
      var bits = [];
      if (r.ended) { bits.push('ended ' + r.ended); }
      if (r.owner) { bits.push('results from ' + r.owner); }
      txt.appendChild(el('small', '', bits.join(' · ')));
      li.appendChild(txt);
      li.appendChild(el('span', 'ss-duepill', r.key === 'overdue' ? 'Overdue · ' + r.days + ' days'
                                            : (r.key === 'started' ? 'In progress — not submitted' : 'Due')));
      var a = el('a', 'btn btn-primary ss-duego', r.key === 'started' ? 'Finish results' : 'Add results');
      a.href = r.url;
      a.target = '_blank';
      a.rel = 'noopener';
      li.appendChild(a);
      list.appendChild(li);
    });
    document.getElementById('ssduen').textContent = due.length ? '(' + due.length + ')' : '';
    dueBox.hidden = !due.length;
  }

  function drawAll(rows) {
    body.textContent = '';
    state.rows = {};
    rows.forEach(function (r) { state.rows[r.id] = r; body.appendChild(drawRow(r)); });
    renumber();
  }

  /** Merge a fresh list of rows into the page without redrawing it. */
  function merge(rows) {
    var seen = {};
    var prev = null;
    rows.forEach(function (r) {
      seen[r.id] = true;
      var tr = body.querySelector('tr[data-id="' + r.id + '"]');
      if (tr) {
        refreshRow(tr, r);
      } else {
        tr = drawRow(r);
        state.rows[r.id] = r;
        if (prev && prev.nextSibling) { body.insertBefore(tr, prev.nextSibling); }
        else if (prev) { body.appendChild(tr); }
        else { body.insertBefore(tr, body.firstChild); }
        tr.classList.add('ss-new');
      }
      prev = tr;
    });
    body.querySelectorAll('tr').forEach(function (tr) {
      if (!seen[tr.dataset.id] && !tr.contains(document.activeElement)) {
        delete state.rows[tr.dataset.id];
        tr.remove();
      }
    });
    renumber();
  }

  function rowEl(node) { return node.closest ? node.closest('tr[data-id]') : null; }

  /* --------------------------------------------------------------- live */

  function poll(force) {
    if (state.dead) { return Promise.resolve(); }
    if (!force && (document.hidden || state.pending)) { return Promise.resolve(); }
    return call('rows', {}).then(function (j) {
      if (j.sig !== state.sig) {
        /* The sheet itself changed: columns renamed, added or removed. Wait
           for whoever is typing to leave the cell, then redraw it all. */
        if (body.contains(document.activeElement)) { return; }
        state.columns = j.columns;
        state.lists = j.lists;
        state.sig = j.sig;
        drawHead();
        drawAll(j.rows);
        toast('The sheet\'s columns were updated by the team.');
      } else {
        state.lists = j.lists;
        merge(j.rows);
      }
      syncEl.textContent = 'Updated ' + j.at;
    }).catch(function (e) {
      if (!state.dead) { say('Offline — retrying', 'bad'); syncEl.textContent = e.message; }
    }).then(function () {
      if (!state.dead && state.pending === 0 && stateEl.classList.contains('bad') && navigator.onLine !== false) {
        idle();
      }
    });
  }

  setInterval(function () { poll(false); }, POLL_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(false); } });

  /* ------------------------------------------------------------- saving */

  function save(input) {
    var td = input.closest('td');
    var tr = rowEl(input);
    if (!td || !tr) { return; }
    var v = cellValue(input);
    if (v === input.dataset.saved) { return; }
    td.classList.remove('ls-bad');
    td.classList.add('ss-saving');
    busy(true);
    call('save', { id: tr.dataset.id, field: td.dataset.field, value: v }).then(function (j) {
      input.dataset.saved = v;
      td.classList.remove('ss-saving');
      td.removeAttribute('title');
      busy(false);
      refreshRow(tr, j.row);                // computed columns follow the edit
    }, function (e) {
      td.classList.remove('ss-saving');
      td.classList.add('ls-bad');
      td.title = e.message;
      busy(false);
      say('Not saved', 'bad');
      toast(e.message, true);
    });
  }

  body.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.matches('select.ls-cell, .ss-check, input[type=date].ls-cell')) { save(t); return; }
    if (t.matches('.js-up-images')) { upload(t, 'upload', 'images[]'); return; }
    if (t.matches('.js-up-files')) { upload(t, 'attach', 'files[]'); return; }
    if (t.matches('.js-up-cell')) { upload(t, 'cellfile', 'file'); }
  });

  body.addEventListener('focusout', function (ev) {
    var t = ev.target;
    if (t.matches('input.ls-cell, textarea.ls-cell')) { save(t); }
  });

  body.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (ev.key === 'Enter' && t.matches('input.ls-cell')) {
      ev.preventDefault();
      save(t);
      /* Down a row, as a spreadsheet does. */
      var td = t.closest('td');
      var tr = rowEl(t);
      var next = tr && tr.nextElementSibling;
      var idx = Array.prototype.indexOf.call(tr.children, td);
      var into = next && next.children[idx] && next.children[idx].querySelector('.ls-cell');
      if (into) { into.focus(); } else { t.blur(); }
    }
    if (ev.key === 'Escape' && t.matches('.ls-cell')) {
      t.value = t.dataset.saved || '';
      t.blur();
    }
  });

  /* ------------------------------------------------------------ uploads */

  function upload(input, action, name) {
    var tr = rowEl(input);
    var td = input.closest('td');
    if (!tr || !input.files.length) { return; }
    var files = Array.prototype.slice.call(input.files);
    var data = { id: tr.dataset.id };
    if (action === 'cellfile') { data.field = td.dataset.field; }
    var label = input.parentNode;
    label.classList.add('busy');
    td.classList.add('ss-saving');
    busy(true);
    /* One file to a request, one after another: the host takes only so
       many files, and so many megabytes, in one go. */
    var done = 0, last = null, bad = [];
    files.reduce(function (p, f, i) {
      return p.then(function () {
        if (files.length > 1) { say('Uploading ' + (i + 1) + ' of ' + files.length + '…', 'busy'); }
        return sendFile(action, data, name, f).then(function (j) {
          done++;
          last = j;
          if (j.warn) { bad.push(j.warn); }
        }, function (e) { bad.push(e.message); });
      });
    }, Promise.resolve()).then(function () {
      td.classList.remove('ss-saving');
      label.classList.remove('busy');
      input.value = '';
      busy(false);
      if (last) { refreshRow(tr, last.row); }
      var msg = done === 1 ? last.message
              : done ? done + (action === 'upload' ? ' pictures added.' : ' documents attached.') : '';
      toast((msg + ' ' + bad.join(' ')).trim(), bad.length > 0);
    });
  }

  /* A file bigger than one request may carry goes in pieces, one after
     another, and the server puts it back together -- so no file is too big. */
  function sendFile(action, data, name, f) {
    var size = C.chunkBytes || 8 * 1024 * 1024;
    if (f.size <= size) { return call(action, data, [[name, f]]); }
    var count = Math.ceil(f.size / size), id = '';
    while (id.length < 32) { id += Math.random().toString(36).slice(2); }
    id = id.replace(/[^a-z0-9]/g, '').slice(0, 32);
    function piece(i) {
      var d = { chunk_id: id, chunk_index: i, chunk_count: count, chunk_offset: i * size,
                chunk_total: f.size, chunk_name: f.name };
      Object.keys(data).forEach(function (k) { d[k] = data[k]; });
      say('Uploading ' + f.name + ' — ' + Math.floor(i * 100 / count) + '%', 'busy');
      return call(action, d, [[name, f.slice(i * size, (i + 1) * size), f.name]]).then(function (j) {
        return j.partial ? piece(i + 1) : j;
      });
    }
    return piece(0);
  }

  /* ------------------------------------------------- removing, adding */

  body.addEventListener('click', function (ev) {
    var drop = ev.target.closest('.js-drop');
    var del  = ev.target.closest('.js-del');
    if (drop) {
      var holder = drop.closest('[data-file]');
      var tr = rowEl(drop);
      if (!holder || !tr || !confirm('Remove this file?')) { return; }
      var td = drop.closest('td');
      td.classList.add('ss-saving');
      busy(true);
      call('drop', { id: tr.dataset.id, file: holder.dataset.file }).then(function (j) {
        td.classList.remove('ss-saving');
        busy(false);
        refreshRow(tr, j.row);
        toast('Removed.');
      }, function (e) { td.classList.remove('ss-saving'); busy(false); toast(e.message, true); });
      return;
    }
    if (del) {
      var row = rowEl(del);
      if (!row || !confirm('Remove this row from the sheet? Its pictures and documents go with it.')) { return; }
      busy(true);
      call('delete', { id: row.dataset.id }).then(function () {
        busy(false);
        delete state.rows[row.dataset.id];
        row.remove();
        renumber();
        toast('Row removed.');
      }, function (e) { busy(false); toast(e.message, true); });
    }
  });

  /* ------------------------------------------- adding an event, filled in
     On the Events sheet, + Add row asks first whether the event is Upcoming
     or Completed. Upcoming offers the planning columns only; Completed offers
     all of them, results included. The row is made, already filled in, when
     the box is saved -- so an abandoned box leaves no empty row behind. */
  function showRow(j) {
    if (!j.row) { return poll(true); }
    state.rows[j.row.id] = j.row;
    var tr = drawRow(j.row);
    body.appendChild(tr);
    tr.classList.add('ss-new');
    renumber();
    if (empty) { empty.hidden = true; }
    tr.scrollIntoView({ block: 'nearest' });
    return tr;
  }

  var addBox = null;
  function stageBox() {
    if (addBox) { return addBox; }
    var m = el('div', 'wcmodal evfill');
    m.hidden = true;
    var card = el('div', 'wcmodal-card evfill-card');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.appendChild(el('h3', '', 'Add an event'));
    card.appendChild(el('div', 'hint',
      'Is this event still to come, or has it already happened? Upcoming asks for the planning ' +
      'columns; Completed asks for every column, results included.'));
    var lab = el('label', '', 'Event is');
    lab.htmlFor = 'ssStage';
    card.appendChild(lab);
    var pick = el('select');
    pick.id = 'ssStage';
    pick.appendChild(new Option('— choose —', ''));
    pick.appendChild(new Option('Upcoming — still to come', 'upcoming'));
    pick.appendChild(new Option('Completed — already happened', 'completed'));
    card.appendChild(pick);
    var form = el('form');
    form.noValidate = true;
    var fields = el('div', 'ss-addfields');
    form.appendChild(fields);
    var err = el('div', 'hint err');
    form.appendChild(err);
    var foot = el('div', 'wcmodal-foot');
    var cancel = el('button', 'btn', 'Cancel');
    cancel.type = 'button';
    var go = el('button', 'btn btn-primary', 'Add event');
    go.type = 'submit';
    go.disabled = true;
    foot.appendChild(cancel);
    foot.appendChild(go);
    form.appendChild(foot);
    card.appendChild(form);
    m.appendChild(card);
    document.body.appendChild(m);

    function close() { m.hidden = true; }
    cancel.addEventListener('click', close);
    m.addEventListener('click', function (ev) { if (ev.target === m) { close(); } });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !m.hidden) { close(); }
    });


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

    /* The columns for the kind chosen, in the sheet's order. */
    pick.addEventListener('change', function () {
      var stage = pick.value;
      fields.textContent = '';
      err.textContent = '';
      go.disabled = !stage;
      if (!stage) { return; }
      var n = 0;
      mainCols().forEach(function (c) {
        if (c.mode !== 'edit') { return; }
        if (stage === 'upcoming' && C.stages[c.field] === 'result') { return; }
        var l = el('label', '', c.label +
          (stage === 'completed' && c.field === 'end_date' ? ' *' : ''));
        l.htmlFor = 'ssf_' + c.field;
        var inp = editor(c, { values: {} });
        inp.id = 'ssf_' + c.field;
        inp.dataset.field = c.field;
        if (inp.tagName === 'TEXTAREA') { inp.rows = 3; }
        fields.appendChild(l);
        fields.appendChild(inp);
        n++;
      });
      fields.insertBefore(el('div', 'hint', stage === 'completed'
        ? n + ' columns, results included. The End Date (or Start Date) must be today or earlier.'
        : n + ' planning columns. The results are filled in once the event is over.'), fields.firstChild);
      designGate(fields);
      /* Who sends the results once it is over: they are emailed a link to a
         short results form the day after the event ends (lib/evresults.php). */
      if (stage === 'upcoming') {
        var box = el('div', 'ss-resowner');
        box.appendChild(el('strong', '', 'Who will send the results after the event?'));
        box.appendChild(el('div', 'hint',
          'The day after the event ends they get an email with a link to a short results form ' +
          '— no need to find this sheet. Reminders follow until the results are in.'));
        var ln = el('label', '', 'Name');
        ln.htmlFor = 'ssResName';
        var iname = el('input');
        iname.id = 'ssResName';
        iname.value = (C.contact && C.contact.name) || '';
        var le = el('label', '', 'Email');
        le.htmlFor = 'ssResEmail';
        var imail = el('input');
        imail.id = 'ssResEmail';
        imail.type = 'email';
        imail.placeholder = 'name@example.com';
        imail.value = (C.contact && C.contact.email) || '';
        box.appendChild(ln);
        box.appendChild(iname);
        box.appendChild(le);
        box.appendChild(imail);
        fields.appendChild(box);
      }
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var stage = pick.value;
      if (!stage) { err.textContent = 'Choose Upcoming or Completed first.'; return; }
      var vals = {};
      fields.querySelectorAll('[data-field]').forEach(function (i) {
        var v = cellValue(i);
        if (i.type === 'checkbox' ? v === '1' : v.trim() !== '') { vals[i.dataset.field] = v; }
      });
      if (!Object.keys(vals).length) { err.textContent = 'Fill in at least the event’s name and dates.'; return; }
      go.disabled = true;
      err.textContent = 'Adding…';
      busy(true);
      var rn = document.getElementById('ssResName');
      var re = document.getElementById('ssResEmail');
      var mail = re ? re.value.trim() : '';
      if (mail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) {
        err.textContent = 'The results email does not look like an email address.';
        re.focus();
        return;
      }
      go.disabled = true;
      call('addfilled', { stage: stage, values: JSON.stringify(vals),
                          results_name: rn ? rn.value.trim() : '', results_email: mail }).then(function (j) {
        busy(false);
        close();
        showRow(j);
        toast(j.message + (j.warn ? ' ' + j.warn : ''), !!j.warn);
      }, function (e) {
        busy(false);
        go.disabled = false;
        err.textContent = e.message;
      });
    });

    addBox = { modal: m, pick: pick, fields: fields, err: err, go: go };
    return addBox;
  }

  document.getElementById('ssadd').addEventListener('click', function (ev) {
    if (!C.stages) { return; }               // not Events: the plain add below
    ev.stopImmediatePropagation();
    var b = stageBox();
    b.pick.value = '';
    b.fields.textContent = '';
    b.err.textContent = '';
    b.go.disabled = true;
    b.modal.hidden = false;
    b.pick.focus();
  });

  document.getElementById('ssadd').addEventListener('click', function () {
    var b = this;
    b.disabled = true;
    busy(true);
    call('add', {}).then(function (j) {
      busy(false);
      b.disabled = false;
      if (!j.row) { return poll(true); }
      state.rows[j.row.id] = j.row;
      var tr = drawRow(j.row);
      body.appendChild(tr);
      tr.classList.add('ss-new');
      renumber();
      var first = tr.querySelector('.ls-cell');
      if (first) { first.focus(); }
      tr.scrollIntoView({ block: 'nearest' });
    }, function (e) { busy(false); b.disabled = false; toast(e.message, true); });
  });

  /* "Save all": leaving the cell in hand saves it; the rest saved already. */
  document.getElementById('sssave').addEventListener('click', function () {
    var a = document.activeElement;
    if (a && body.contains(a)) { a.blur(); }
    body.querySelectorAll('.ls-cell').forEach(function (i) {
      if (cellValue(i) !== i.dataset.saved) { save(i); }
    });
    if (!state.pending) { toast('Everything is saved.'); }
  });

  /* Leaving with a save still on its way would lose it. */
  window.addEventListener('beforeunload', function (ev) {
    if (state.pending) { ev.preventDefault(); ev.returnValue = ''; }
  });

  /* ------------------------------------------------------------ go */

  drawHead();
  drawAll(C.rows);
})();
