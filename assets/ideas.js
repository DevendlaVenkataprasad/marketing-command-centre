/* Startup Discovery: dialogs, copy buttons, confirmations, busy buttons, the
   AI-mode tabs and the form builder. Everything works without it; this only
   makes it quicker. */
(function () {
  'use strict';

  function copyText(text, btn) {
    var done = function () {
      if (!btn) return;
      var was = btn.textContent;
      btn.textContent = 'Copied';
      btn.classList.add('done');
      setTimeout(function () { btn.textContent = was; btn.classList.remove('done'); }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
    } else {
      fallback(text);
      done();
    }
  }
  function fallback(text) {
    var t = document.createElement('textarea');
    t.value = text;
    t.setAttribute('readonly', '');
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.appendChild(t);
    t.select();
    try { document.execCommand('copy'); } catch (e) { /* the box stays selectable */ }
    document.body.removeChild(t);
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copy]');
    if (b) { ev.preventDefault(); copyText(b.getAttribute('data-copy'), b); return; }
    b = ev.target.closest('[data-copy-from]');
    if (b) {
      ev.preventDefault();
      var src = document.getElementById(b.getAttribute('data-copy-from'));
      if (src) { copyText(src.value, b); }
      // "Copy & open Gemini": the prompt is on the clipboard and the tool opens in a new tab.
      var go = b.getAttribute('data-go');
      if (go) window.open(go, '_blank', 'noopener');
      if (b.hasAttribute('data-tool')) {
        var own = b.closest('[data-own]');
        var tn = own && own.querySelector('[data-toolname]');
        if (tn && b.getAttribute('data-tool')) tn.value = b.getAttribute('data-tool');
        var ta = own && own.querySelector('[data-reply]');
        if (ta) setTimeout(function () { ta.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }, 200);
      }
      return;
    }
    b = ev.target.closest('[data-open]');
    if (b) {
      var d = document.getElementById(b.getAttribute('data-open'));
      if (d && d.showModal) { ev.preventDefault(); d.showModal(); }
      return;
    }
    b = ev.target.closest('[data-close]');
    if (b) { var dl = b.closest('dialog'); if (dl) { ev.preventDefault(); dl.close(); } return; }
    b = ev.target.closest('[data-tab]');
    if (b) {
      var bar = b.parentNode;
      Array.prototype.forEach.call(bar.querySelectorAll('[data-tab]'), function (x) {
        var on = x === b;
        x.classList.toggle('on', on);
        x.setAttribute('aria-selected', on ? 'true' : 'false');
        var p = document.getElementById(x.getAttribute('data-tab'));
        if (p) p.hidden = !on;
      });
      return;
    }
    b = ev.target.closest('[data-addq]');
    if (b) {
      var tpl = document.getElementById('qTpl');
      var list = document.querySelector('[data-qs]');
      if (tpl && list) {
        var n = 'n' + Date.now();
        var wrap = document.createElement('div');
        wrap.innerHTML = tpl.innerHTML.replace(/__N__/g, n);
        var li = wrap.firstElementChild;
        list.appendChild(li);
        var inp = li.querySelector('input[name$="[label]"]');
        if (inp) inp.focus();
      }
      return;
    }
    b = ev.target.closest('[data-delq]');
    if (b) { var row = b.closest('li'); if (row) row.remove(); return; }
  });

  // A whole table row opens its record: in the slide-in panel when the table has them, else as a page.
  document.addEventListener('click', function (ev) {
    if (ev.defaultPrevented || ev.button !== 0) return;
    var link = ev.target.closest('a[data-panel-link]');
    if (link) {
      if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) return;     // a new tab: the full page
      var lr = link.closest('tr[data-panel]');
      if (lr) { ev.preventDefault(); openPanel(lr); }
      return;
    }
    if (ev.target.closest('a, button, input, select, textarea, label, form')) return;
    var tr = ev.target.closest('tr[data-href]');
    if (!tr) return;
    if (tr.hasAttribute('data-panel') && tr.closest('[data-panels]')) { openPanel(tr); return; }
    window.location.href = tr.getAttribute('data-href');
  });

  /* ------------------------------------------------- the slide-in panel */
  var pn = null;          // {wrap, box, body, title, full, prev, next}
  var pnRow = null;       // the row shown
  var pnBack = null;      // what had focus before
  var pnReq = 0;

  function el(tag, cls, html) { var x = document.createElement(tag); if (cls) x.className = cls; if (html != null) x.innerHTML = html; return x; }

  function buildPanel() {
    if (pn) return pn;
    var wrap = el('div', 'iv-pn-wrap iv');
    wrap.innerHTML =
      '<div class="iv-pn-backdrop" data-pn-close></div>' +
      '<aside class="iv-pn" role="dialog" aria-modal="true" aria-labelledby="ivPnTitle" tabindex="-1">' +
        '<header class="iv-pn-top">' +
          '<div class="iv-pn-nav"><button type="button" class="iv-pn-ic" data-pn-prev aria-label="Previous" title="Previous (←)">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>' +
          '<button type="button" class="iv-pn-ic" data-pn-next aria-label="Next" title="Next (→)">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button></div>' +
          '<strong id="ivPnTitle" class="iv-pn-title">Student</strong>' +
          '<a class="iv-pn-ic" data-pn-fulllink href="#" aria-label="Open as a full page" title="Open as a full page">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-8 8M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg></a>' +
          '<button type="button" class="iv-pn-ic" data-pn-close aria-label="Close" title="Close (Esc)">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
        '</header><div class="iv-pn-body" aria-live="polite"></div></aside>';
    document.body.appendChild(wrap);
    pn = { wrap: wrap, box: wrap.querySelector('.iv-pn'), body: wrap.querySelector('.iv-pn-body'), title: wrap.querySelector('.iv-pn-title'),
           full: wrap.querySelector('[data-pn-fulllink]'), prev: wrap.querySelector('[data-pn-prev]'), next: wrap.querySelector('[data-pn-next]') };
    wrap.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-pn-close]')) { ev.preventDefault(); closePanel(); return; }
      if (ev.target.closest('[data-pn-prev]')) { step(-1); return; }
      if (ev.target.closest('[data-pn-next]')) { step(1); return; }
      if (ev.target.closest('[data-pn-edit]')) { showEdit(true); return; }
      if (ev.target.closest('[data-pn-view]')) { showEdit(false); return; }
    });
    return pn;
  }

  function rowsOf(tr) { var t = tr && tr.closest('[data-panels]'); return t ? Array.prototype.slice.call(t.querySelectorAll('tbody tr[data-panel]')) : []; }

  function skeleton() {
    return '<div class="iv-sk"><div class="iv-sk-head"><span class="iv-sk-av"></span><div><span class="iv-sk-l w60"></span><span class="iv-sk-l w40"></span></div></div>' +
           '<span class="iv-sk-l"></span><span class="iv-sk-l w80"></span><span class="iv-sk-l w60"></span><span class="iv-sk-l"></span><span class="iv-sk-l w40"></span></div>';
  }

  function openPanel(tr, url) {
    var p = buildPanel();
    var wasOpen = p.wrap.classList.contains('on');
    if (!wasOpen) pnBack = document.activeElement;
    if (pnRow) pnRow.classList.remove('iv-row-on');
    pnRow = tr || null;
    if (pnRow) pnRow.classList.add('iv-row-on');
    var nameCell = tr ? tr.querySelector('strong') : null;
    p.title.textContent = nameCell ? nameCell.textContent : 'Student';
    p.body.innerHTML = skeleton();
    p.body.scrollTop = 0;
    var rows = rowsOf(tr), i = rows.indexOf(tr);
    p.prev.disabled = !tr || i <= 0;
    p.next.disabled = !tr || i < 0 || i >= rows.length - 1;
    p.full.href = tr ? tr.getAttribute('data-href') : '#';
    document.documentElement.classList.add('iv-pn-lock');
    p.wrap.classList.add('on');
    if (!wasOpen) setTimeout(function () { p.box.focus(); }, 30);
    var mine = ++pnReq;
    var src = url || (tr && tr.getAttribute('data-panel'));
    fetch(src, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t, redirected: r.redirected }; }); })
      .then(function (res) {
        if (mine !== pnReq) return;                    // another student was asked for since
        if (res.redirected || /<html/i.test(res.t)) {  // signed out meanwhile: the page itself
          window.location.href = src.replace(/[?&]embed=1/, '');
          return;
        }
        p.body.innerHTML = res.t;
        var v = p.body.querySelector('[data-pn-title]');
        if (v) { p.title.textContent = v.getAttribute('data-pn-title'); p.full.href = v.getAttribute('data-pn-full') || p.full.href; }
        markUrl(tr ? tr.getAttribute('data-id') : null);
      })
      .catch(function () { if (mine === pnReq) p.body.innerHTML = '<div class="iv-pn-missing"><p>Could not load this student. Check the connection and try again.</p></div>'; });
  }

  function step(dir) {
    if (!pnRow) return;
    var rows = rowsOf(pnRow), i = rows.indexOf(pnRow) + dir;
    if (i >= 0 && i < rows.length) { openPanel(rows[i]); rows[i].scrollIntoView({ block: 'nearest' }); }
  }

  function showEdit(on) {
    if (!pn) return;
    var v = pn.body.querySelector('.iv-pn-view'), e = pn.body.querySelector('.iv-pn-edit');
    if (!v || !e) return;
    v.hidden = on; e.hidden = !on;
    pn.body.scrollTop = 0;
    if (on) { var f = e.querySelector('input:not([type=hidden]), select, textarea'); if (f) f.focus(); }
  }

  function closePanel() {
    if (!pn || !pn.wrap.classList.contains('on')) return;
    pn.wrap.classList.remove('on');
    pnReq++;
    document.documentElement.classList.remove('iv-pn-lock');
    if (pnRow) pnRow.classList.remove('iv-row-on');
    pnRow = null;
    markUrl(null);
    if (pnBack && pnBack.focus) pnBack.focus();
  }

  // The address keeps the open student, so a refresh (or a link) opens it again.
  function markUrl(id) {
    try {
      var u = new URL(window.location.href);
      if (id) u.searchParams.set('open', id); else u.searchParams.delete('open');
      history.replaceState(null, '', u.toString());
    } catch (e) { /* old browser: the address simply stays */ }
  }

  document.addEventListener('keydown', function (ev) {
    if (!pn || !pn.wrap.classList.contains('on')) return;
    if (ev.key === 'Escape') { ev.preventDefault(); closePanel(); return; }
    var typing = ev.target.closest && ev.target.closest('input, textarea, select, [contenteditable]');
    if (typing) return;
    if (ev.key === 'ArrowLeft') { ev.preventDefault(); step(-1); }
    if (ev.key === 'ArrowRight') { ev.preventDefault(); step(1); }
    if (ev.key === 'Tab') {                       // keep focus inside the panel
      var f = pn.box.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    }
  });

  // Back on the list after saving (or from a link): the student opens again.
  var auto = document.querySelector('[data-panels][data-open-id]');
  if (auto) {
    var row = auto.querySelector('tr[data-id="' + auto.getAttribute('data-open-id') + '"]');
    if (row) openPanel(row); else openPanel(null, auto.getAttribute('data-open-panel'));
  } else {
    var t = document.querySelector('[data-panels]');
    var m = /[?&]open=(\d+)/.exec(window.location.search);
    if (t && m) { var r2 = t.querySelector('tr[data-id="' + m[1] + '"]'); if (r2) openPanel(r2); }
  }

  // Close a dialog by clicking its backdrop.
  document.addEventListener('click', function (ev) {
    if (ev.target.tagName === 'DIALOG' && ev.target.open) {
      var r = ev.target.getBoundingClientRect();
      if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) ev.target.close();
    }
  });

  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var sub = ev.submitter;
    var ask = (sub && sub.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (ask && !window.confirm(ask)) { ev.preventDefault(); return; }
    var busy = f.getAttribute('data-busy');
    if (busy) {
      var btn = sub || f.querySelector('button:not([type=button])');
      if (btn) {
        // Keep the pressed button's name/value: disabling it would drop them from the post.
        if (btn.name) {
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = btn.name; h.value = btn.value;
          f.appendChild(h);
        }
        setTimeout(function () { btn.disabled = true; btn.textContent = busy; }, 0);
      }
    }
  });

  /* ---------------------------- own AI tool: read the pasted reply live */
  // Mirrors iv_reply_json / iv_tally on the server (which decides): finds the
  // JSON in a chatty reply, checks the code, and adds up the marks.
  function readReply(text) {
    text = text.trim().replace(/^```(?:json)?|```$/gm, '').trim();
    try { return JSON.parse(text); } catch (e) { /* look inside */ }
    var a = text.indexOf('{'), b = text.lastIndexOf('}');
    if (a < 0 || b <= a) return null;
    try { return JSON.parse(text.slice(a, b + 1)); } catch (e2) { return null; }
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function num(x) { return (Math.round(x * 10) / 10).toString(); }

  Array.prototype.forEach.call(document.querySelectorAll('[data-own]'), function (own) {
    var ta = own.querySelector('[data-reply]'), out = own.querySelector('[data-preview]'), save = own.querySelector('[data-ownsave]');
    var code = own.getAttribute('data-code') || '';
    var crit = [];
    try { crit = JSON.parse(own.getAttribute('data-crit') || '[]'); } catch (e) { crit = []; }
    if (!ta || !out) return;
    function render() {
      var v = ta.value;
      if (!v.trim()) { out.innerHTML = ''; out.className = 'hp-preview'; if (save) save.disabled = true; return; }
      var j = readReply(v), msg = '', ok = false;
      if (!j || !Array.isArray(j.criteria)) {
        msg = 'No marks found yet. Paste the whole reply, including the part in { } brackets.';
      } else if (String(j.check || '').trim().toUpperCase() !== code.toUpperCase()) {
        msg = 'This reply does not carry this idea’s check code (' + esc(code) + '). Copy the prompt from this page again, ask the AI, and paste its new reply.';
      } else {
        var byN = {}, total = 0, max = 0, rows = '', missing = '';
        j.criteria.forEach(function (c, k) { if (c && typeof c === 'object') byN[parseInt(c.n || k + 1, 10)] = c; });
        crit.forEach(function (c, k) {
          var g = byN[k + 1];
          max += c.max;
          if (!g || g.score === '' || isNaN(parseFloat(g.score))) { if (!missing) missing = c.name; return; }
          var s = Math.max(0, Math.min(c.max, Math.round(parseFloat(g.score) * 2) / 2));
          total += s;
          rows += '<tr><td>' + esc(c.name) + '</td><td class="n">' + num(s) + ' / ' + num(c.max) + '</td></tr>';
        });
        if (missing) msg = 'The reply leaves out “' + esc(missing) + '”. Ask the AI to score every criterion, then paste again.';
        else {
          ok = true;
          out.innerHTML = '<div class="hp-pv-ok"><strong>Reads as ' + num(total) + ' / ' + num(max) + '</strong><span>All ' + crit.length + ' criteria found. Check code matches.</span></div>'
            + '<details><summary>Marks per criterion</summary><table class="iv-table iv-rubric"><tbody>' + rows + '</tbody></table></details>';
        }
      }
      out.className = 'hp-preview ' + (ok ? 'ok' : 'bad');
      if (!ok) out.innerHTML = '<p>' + msg + '</p>';
      if (save) save.disabled = !ok;
    }
    ta.addEventListener('input', render);
    render();
  });

  /* ------------------------------------- Students: tick and decide many */
  var bulk = document.querySelector('[data-bulkbar]');
  if (bulk) {
    var ticks = function () { return Array.prototype.slice.call(document.querySelectorAll('[data-tick]')); };
    var all = document.querySelector('[data-checkall]');
    var bulkSync = function () {
      var t = ticks(), on = t.filter(function (x) { return x.checked; });
      bulk.hidden = on.length === 0;
      bulk.querySelector('[data-tickcount]').textContent = on.length;
      t.forEach(function (x) { var tr = x.closest('tr'); if (tr) tr.classList.toggle('ticked', x.checked); });
      if (all) { all.checked = on.length > 0 && on.length === t.length; all.indeterminate = on.length > 0 && on.length < t.length; }
    };
    document.addEventListener('change', function (ev) {
      if (ev.target.matches('[data-checkall]')) { ticks().forEach(function (x) { x.checked = ev.target.checked; }); bulkSync(); }
      else if (ev.target.matches('[data-tick]')) bulkSync();
    });
    document.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-untick]')) { ticks().forEach(function (x) { x.checked = false; }); bulkSync(); }
    });
    bulkSync();
  }

  /* --------------------- Top picks: rank by score, and say why if not */
  var picks = document.querySelector('[data-picks]');
  if (picks) {
    var top = parseInt(picks.getAttribute('data-picks'), 10) || 3;
    var rows = Array.prototype.slice.call(picks.querySelectorAll('tr[data-pick]'));
    var reason = picks.querySelector('[data-reason]'), why = picks.querySelector('[data-reasonwhy]');
    var pct = function (tr) { var v = tr.getAttribute('data-pct'); return v === '' ? null : parseFloat(v); };
    var rank = function (tr) { return parseInt(tr.querySelector('select').value, 10) || 0; };
    var check = function () {
      var bad = '';
      rows.forEach(function (p) {
        var pr = rank(p), pp = pct(p);
        if (bad || !pr || pp === null) return;
        rows.forEach(function (q) {
          var qp = pct(q), qr = rank(q);
          if (bad || q === p || qp === null || qp <= pp + 0.0001) return;
          if (!qr || qr > pr) bad = '“' + p.getAttribute('data-title') + '” is ranked above “' + q.getAttribute('data-title') + '”, which scored higher.';
        });
      });
      if (reason) { reason.hidden = !bad; if (why) why.textContent = bad; }
    };
    picks.addEventListener('change', check);
    picks.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-rankbyscore]')) {
        var order = rows.filter(function (r) { return pct(r) !== null; }).sort(function (a, b) { return pct(b) - pct(a); });
        rows.forEach(function (r) { r.querySelector('select').value = '0'; });
        order.slice(0, top).forEach(function (r, k) { r.querySelector('select').value = String(k + 1); });
        check();
      } else if (ev.target.closest('[data-rankclear]')) {
        rows.forEach(function (r) { r.querySelector('select').value = '0'; });
        check();
      }
    });
    check();
  }

  // New dashboard: the new-college boxes only while "a new one" is chosen.
  var sel = document.querySelector('[data-newcollege]');
  if (sel) {
    var sync = function () {
      var isNew = sel.value === '0';
      Array.prototype.forEach.call(sel.form.querySelectorAll('[data-nc]'), function (x) { x.hidden = !isNew; });
    };
    sel.addEventListener('change', sync);
    sync();
  }
})();

// Gems: the rubric's weights added up while they are typed.
(function () {
  var ta = document.querySelector('[data-crit]');
  var out = document.querySelector('[data-crit-total]');
  if (!ta || !out) return;
  var sum = function () {
    var t = 0;
    ta.value.split(/\r?\n/).forEach(function (l) { var p = l.split('|'); var w = parseFloat((p[1] || '').trim()); if (p.length > 1 && w > 0) t += w; });
    out.textContent = (Math.round(t * 10) / 10).toString();
  };
  ta.addEventListener('input', sum);
})();
