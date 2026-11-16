/* CEO Dashboard. Reads everything through api/ceo.php, which decides what this
   person may see; nothing here is a permission. Near-real-time by polling: the
   host limits connections, so a held-open stream would cost more than it gives. */
(function () {
  'use strict';
  var conf = JSON.parse(document.getElementById('ceo-conf').textContent);
  var app = document.getElementById('ceo-app');
  var root = document.getElementById('main');
  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var LIVE_MS = 30000;
  var S = { boot: null, f: {}, data: null, cards: {}, feedSeen: {}, lastOk: 0, skew: 0, timer: null, failing: 0, hidden: {}, netMetric: 'open', view: {} };

  /* ------------------------------------------------------------ helpers */
  function esc(v) { return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function num(v) { return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 1 }); }
  function money(v) {
    var a = Math.abs(v), s = v < 0 ? '-' : '';
    if (a >= 1e7) { return s + '₹' + (a / 1e7).toLocaleString('en-IN', { maximumFractionDigits: 2 }) + ' Cr'; }
    if (a >= 1e5) { return s + '₹' + (a / 1e5).toLocaleString('en-IN', { maximumFractionDigits: 2 }) + ' L'; }
    return s + '₹' + Math.round(a).toLocaleString('en-IN');
  }
  function fmt(v, unit) {
    if (v === null || v === undefined) { return '—'; }
    if (unit === 'pct') { return num(v) + '%'; }
    if (unit === 'money') { return money(+v); }
    return num(v);
  }
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function shortDate(s) {
    if (!s) { return ''; }
    var d = new Date(String(s).slice(0, 10) + 'T00:00:00');
    return isNaN(d) ? s : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  function ago(s) {
    if (!s) { return ''; }
    var t = new Date(String(s).replace(' ', 'T')).getTime();
    if (isNaN(t)) { return s; }
    var d = Math.max(0, Math.round((Date.now() + S.skew - t) / 1000));
    if (d < 60) { return 'just now'; }
    if (d < 3600) { return Math.floor(d / 60) + ' min ago'; }
    if (d < 86400) { return Math.floor(d / 3600) + ' hr ago'; }
    if (d < 86400 * 30) { return Math.floor(d / 86400) + 'd ago'; }
    return shortDate(s);
  }
  function col(n) { return 'var(--c' + (n || 1) + ')'; }
  function can(a) { return S.boot && S.boot.access.actions.indexOf(a) >= 0; }
  function ico(p) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>'; }
  var I = {
    refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
    screen: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    up: '<path d="M12 19V5M6 11l6-6 6 6"/>', down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'
  };

  function toast(msg, err) {
    var box = $('.ceo-toasts') || document.body.appendChild(Object.assign(document.createElement('div'), { className: 'ceo-toasts', role: 'status' }));
    var t = document.createElement('div');
    t.className = 'ceo-toast' + (err ? ' err' : '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, 4800);
  }

  function qs(extra) {
    var p = new URLSearchParams();
    var f = S.f;
    ['from', 'to', 'spoke', 'module', 'dept'].forEach(function (k) { if (f[k]) { p.set(k, f[k]); } });
    if (conf.admin) { p.set('as', 'admin'); p.set('org', conf.org); }
    Object.keys(extra || {}).forEach(function (k) { if (extra[k] !== '' && extra[k] !== undefined && extra[k] !== null) { p.set(k, extra[k]); } });
    return p;
  }
  function api(r, extra) {
    var p = qs(extra);
    p.set('r', r);
    return fetch(conf.api + '?' + p.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(handle);
  }
  function post(r, body) {
    var p = new URLSearchParams({ r: r });
    if (conf.admin) { p.set('as', 'admin'); p.set('org', conf.org); }
    // A form post, not a JSON body: fabo_csrf_check() reads an empty $_POST as an upload that was too big.
    var form = new URLSearchParams({ csrf: conf.csrf, payload: JSON.stringify(body || {}) });
    return fetch(conf.api + '?' + p.toString(), { method: 'POST', credentials: 'same-origin', body: form }).then(handle);
  }
  function handle(res) {
    return res.json().catch(function () { return { ok: false, error: 'The server sent something unexpected.' }; }).then(function (j) {
      if (res.status === 401) { location.href = (conf.admin ? '../' : '') + 'login.php?next=' + encodeURIComponent(location.pathname + location.search); }
      if (!j.ok) { var e = new Error(j.error || 'That did not work.'); e.status = res.status; e.body = j; throw e; }
      if (j.server_time) { S.skew = new Date(j.server_time.replace(' ', 'T')).getTime() - Date.now(); }
      return j;
    });
  }

  /* ------------------------------------------------------------ filters */
  var PERIODS = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['mtd', 'This month'], ['qtd', 'Quarter'], ['fy', 'Year'], ['custom', 'Custom']];
  function periodDates(k) {
    var t = new Date((S.boot ? S.boot.today : ymd(new Date())) + 'T00:00:00'), to = ymd(t), m = t.getMonth(), y = t.getFullYear();
    if (k === 'today') { return [to, to]; }
    if (k === '7d') { return [ymd(new Date(y, m, t.getDate() - 6)), to]; }
    if (k === '30d') { return [ymd(new Date(y, m, t.getDate() - 29)), to]; }
    if (k === 'mtd') { return [ymd(new Date(y, m, 1)), to]; }
    if (k === 'qtd') { return [ymd(new Date(y, Math.floor(m / 3) * 3, 1)), to]; }
    if (k === 'fy') { return [ymd(new Date(m < 3 ? y - 1 : y, 3, 1)), to]; }
    return [S.f.from, S.f.to];
  }
  function saveFilters() {
    try { sessionStorage.setItem('ceo.f.' + conf.org, JSON.stringify(S.f)); } catch (e) {}
    var p = new URLSearchParams(location.search);
    ['period', 'from', 'to', 'spoke', 'module', 'dept'].forEach(function (k) { if (S.f[k]) { p.set(k, S.f[k]); } else { p.delete(k); } });
    history.replaceState(null, '', location.pathname + '?' + p.toString());
  }
  function loadFilters() {
    var saved = {};
    try { saved = JSON.parse(sessionStorage.getItem('ceo.f.' + conf.org) || '{}') || {}; } catch (e) {}
    var u = new URLSearchParams(location.search);
    ['period', 'from', 'to', 'spoke', 'module', 'dept'].forEach(function (k) { if (u.get(k)) { saved[k] = u.get(k); } });
    S.f = { period: saved.period || 'mtd', from: saved.from || '', to: saved.to || '', spoke: saved.spoke || '', module: saved.module || '', dept: saved.dept || '' };
    if (S.f.period !== 'custom' || !S.f.from) {
      var d = periodDates(S.f.period === 'custom' ? 'mtd' : S.f.period);
      S.f.from = d[0]; S.f.to = d[1];
    }
    // Anything no longer in this person's scope is dropped, not sent.
    if (S.f.spoke && !S.boot.spokes.some(function (s) { return s.key === S.f.spoke; })) { S.f.spoke = ''; }
    if (S.f.module && !S.boot.modules.some(function (m) { return m.key === S.f.module; })) { S.f.module = ''; }
    if (!can('filter')) { S.f.spoke = S.f.module = S.f.dept = ''; }
  }
  function filterBar() {
    if (!can('filter')) {
      return '<div class="ceo-filters"><span class="ceo-note-inline">This month. Your access does not include filters.</span></div>';
    }
    var f = S.f;
    var opt = function (list, val, none) {
      return '<option value="">' + esc(none) + '</option>' + list.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('');
    };
    var spokes = S.boot.spokes.map(function (s) { return [s.key, s.name + (s.kind === 'hub' ? ' (hub)' : '')]; });
    var mods = S.boot.modules.map(function (m) { return [m.key, m.label]; });
    var depts = S.boot.depts.map(function (d) { return [d.id, d.name]; });
    return '<div class="ceo-filters" role="group" aria-label="Filters">'
      + '<div class="ceo-seg" role="group" aria-label="Reporting period">' + PERIODS.map(function (p) { return '<button type="button" data-period="' + p[0] + '" aria-pressed="' + (f.period === p[0]) + '">' + p[1] + '</button>'; }).join('') + '</div>'
      + (f.period === 'custom' ? '<label class="ceo-f">From <input type="date" id="ceo-from" value="' + esc(f.from) + '" max="' + esc(f.to) + '"></label><label class="ceo-f">To <input type="date" id="ceo-to" value="' + esc(f.to) + '" min="' + esc(f.from) + '"></label>' : '')
      + '<label class="ceo-f"><span class="sr">Hub or spoke</span><select id="ceo-spoke" aria-label="Hub or spoke">' + opt(spokes, f.spoke, S.boot.access.limited ? 'All my spokes' : 'All hub & spokes') + '</select></label>'
      + '<label class="ceo-f"><span class="sr">Module</span><select id="ceo-module" aria-label="Module">' + opt(mods, f.module, 'All modules') + '</select></label>'
      + (depts.length ? '<label class="ceo-f"><span class="sr">Department</span><select id="ceo-dept" aria-label="Department">' + opt(depts, f.dept, 'All departments') + '</select></label>' : '')
      + '<span class="grow"></span>'
      + ((f.spoke || f.module || f.dept) ? '<button class="ceo-chip" type="button" id="ceo-clear">Clear filters ×</button>' : '')
      + '</div>';
  }
  function bindFilters() {
    $$('[data-period]').forEach(function (b) {
      b.onclick = function () {
        S.f.period = b.dataset.period;
        if (S.f.period !== 'custom') { var d = periodDates(S.f.period); S.f.from = d[0]; S.f.to = d[1]; }
        changed();
      };
    });
    [['#ceo-spoke', 'spoke'], ['#ceo-module', 'module'], ['#ceo-dept', 'dept'], ['#ceo-from', 'from'], ['#ceo-to', 'to']].forEach(function (x) {
      var el = $(x[0]);
      if (el) { el.onchange = function () { if (x[1] === 'from' || x[1] === 'to') { if (!el.value) { return; } } S.f[x[1]] = el.value; changed(); }; }
    });
    var c = $('#ceo-clear');
    if (c) { c.onclick = function () { S.f.spoke = S.f.module = S.f.dept = ''; changed(); }; }
  }
  function changed() { saveFilters(); frame(); route(true); }
  function setSpoke(key) { S.f.spoke = S.f.spoke === key ? '' : key; changed(); }

  /* ------------------------------------------------------------ frame */
  var TITLES = { overview: ['CEO Dashboard', 'Live Executive Overview'], spokes: ['Hub & Spokes', 'Every centre side by side'], targets: ['Targets vs Actual', 'How the organisation stands against what it set out to do'],
    actions: ['Pending Actions', 'Everything waiting on someone, across every module'], activity: ['Live Activity', 'What is happening across the organisation'], reports: ['Reports', 'Compile, preview, export and schedule'],
    access: ['Access Management', 'Who can see what on the CEO Dashboard'], setup: ['KPI Registry & Audit', 'Definitions, data coverage, layout and the audit trail'] };

  function frame() {
    var t = TITLES[conf.page] || TITLES.overview, a = S.boot.access;
    var orgs = Object.keys(conf.orgs);
    var pick = conf.admin && orgs.length > 1 ? '<select class="ceo-orgpick" id="ceo-org" aria-label="Organisation">' + orgs.map(function (id) { return '<option value="' + esc(id) + '"' + (+id === +conf.org ? ' selected' : '') + '>' + esc(conf.orgs[id]) + '</option>'; }).join('') + '</select>' : '';
    app.innerHTML = '<section class="ceo-hero"><div class="ceo-aurora" aria-hidden="true"><i></i><i></i><i></i></div>'
      + '<div class="ceo-hero-top"><div><div class="ceo-eyebrow"><b>' + esc(conf.orgName) + '</b><span class="ceo-chip-profile">' + esc(a.owner ? 'Owner' : a.profile) + '</span></div>'
      + '<h1>' + esc(t[0]) + '</h1><p class="sub">' + esc(t[1]) + '</p></div>'
      + '<div class="ceo-tools">' + pick
      + '<span class="ceo-pill" id="ceo-status" data-state="busy" role="status" aria-live="polite"><span class="ceo-dot"></span><span id="ceo-status-t">Connecting…</span><small id="ceo-status-s"></small></span>'
      + '<button type="button" class="ceo-ibtn" id="ceo-refresh" title="Refresh now" aria-label="Refresh now">' + ico(I.refresh) + '</button>'
      + '<button type="button" class="ceo-ibtn" id="ceo-board" title="Boardroom mode" aria-label="Boardroom mode: full screen, dark">' + ico(I.screen) + '</button>'
      + '</div></div><div id="ceo-strip"></div></section>'
      + (['overview', 'spokes', 'targets', 'actions', 'activity'].indexOf(conf.page) >= 0 ? filterBar() : '')
      + '<div id="ceo-view"></div>';
    bindFilters();
    $('#ceo-refresh').onclick = function () { var b = this; b.classList.add('spin'); route(true, true).finally(function () { setTimeout(function () { b.classList.remove('spin'); }, 300); }); };
    $('#ceo-board').onclick = toggleBoard;
    var o = $('#ceo-org');
    if (o) { o.onchange = function () { location.href = 'ceo.php?p=' + conf.page + '&org=' + o.value; }; }
  }
  function toggleBoard() {
    var on = root.dataset.mode === 'boardroom';
    if (on) { delete root.dataset.mode; if (document.fullscreenElement) { document.exitFullscreen(); } } else {
      root.dataset.mode = 'boardroom';
      if (root.requestFullscreen) { root.requestFullscreen().catch(function () {}); }
    }
  }
  document.addEventListener('fullscreenchange', function () { if (!document.fullscreenElement && root.dataset.mode === 'boardroom') { delete root.dataset.mode; } });

  function status(state, text, sub) {
    var p = $('#ceo-status');
    if (!p) { return; }
    p.dataset.state = state;
    $('#ceo-status-t').textContent = text;
    $('#ceo-status-s').textContent = sub || '';
  }
  function tickStatus() {
    if (!S.lastOk) { return; }
    var s = Math.round((Date.now() - S.lastOk) / 1000);
    if (document.hidden) { status('paused', 'Paused', 'tab hidden'); return; }
    if (S.failing) {
      status('lost', 'Connection lost', 'last good ' + new Date(S.lastOk).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }));
    } else {
      if (['access', 'reports', 'setup'].indexOf(conf.page) >= 0) { status('live', 'Connected', 'loaded ' + new Date(S.lastOk).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })); return; }
      status('live', 'Live', (s < 5 ? 'updated now' : 'updated ' + (s < 60 ? s + 's' : Math.floor(s / 60) + 'm') + ' ago') + ' · refreshes every ' + LIVE_MS / 1000 + 's');
    }
  }

  /* ------------------------------------------------------------ routing */
  function route(fresh, force) {
    var fn = VIEWS[conf.page] || VIEWS.overview;
    status('busy', 'Refreshing…');
    return fn(!!fresh, !!force).then(function () {
      S.lastOk = Date.now(); S.failing = 0; tickStatus();
      var v = $('#ceo-view'); if (v) { v.classList.remove('ceo-stale'); }
    }).catch(function (e) {
      if (e.status === 403) {
        // Access changed under us: say so, keep nothing on screen.
        $('#ceo-view').innerHTML = '<div class="ceo-card"><div class="ceo-empty"><b>Your access has changed</b>' + esc(e.message) + '</div></div>';
      } else if (fresh && !S.data) {
        $('#ceo-view').innerHTML = '<div class="ceo-error" role="alert">' + esc(e.message) + ' <button class="ceo-btn sm" id="ceo-retry">Try again</button></div>';
        var r = $('#ceo-retry'); if (r) { r.onclick = function () { route(true); }; }
      }
      S.failing++;
      var v = $('#ceo-view'); if (v) { v.classList.add('ceo-stale'); }
      S.lastOk = S.lastOk || Date.now();
      tickStatus();
      if (fresh) { toast(e.message, true); }
    });
  }
  function schedule() {
    clearTimeout(S.timer);
    var wait = LIVE_MS * Math.min(8, Math.pow(2, S.failing));
    S.timer = setTimeout(function () {
      if (document.hidden || conf.page === 'access' || conf.page === 'reports' || conf.page === 'setup' || $('dialog[open]')) { schedule(); return; }
      route(false).finally(schedule);
    }, wait);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && S.boot && Date.now() - S.lastOk > LIVE_MS) { route(false); } tickStatus(); });
  setInterval(tickStatus, 5000);

  /* ------------------------------------------------------------ pieces */
  function count(el, to, unit) {
    if (calm) { el.innerHTML = valueHtml(to, unit); return; }
    var from = +(el.dataset.v || 0), t0 = performance.now(), d = from === to ? 0 : 950;
    el.dataset.v = to;
    if (!d) { el.innerHTML = valueHtml(to, unit); return; }
    (function step(now) {
      var k = Math.min(1, (now - t0) / d), e = 1 - Math.pow(1 - k, 4), v = from + (to - from) * e;
      el.innerHTML = valueHtml(k < 1 ? (unit === 'pct' ? Math.round(v * 10) / 10 : Math.round(v)) : to, unit);
      if (k < 1) { requestAnimationFrame(step); }
    })(t0);
  }
  function valueHtml(v, unit) {
    if (unit === 'pct') { return num(v) + '<small>%</small>'; }
    if (unit === 'money') { return esc(money(v)); }
    return num(v);
  }
  function spark(vals) {
    if (!vals || vals.length < 2) { return ''; }
    var w = 74, h = 24, min = Math.min.apply(null, vals), max = Math.max.apply(null, vals), r = (max - min) || 1;
    var pts = vals.map(function (v, i) { return [(i / (vals.length - 1)) * w, h - 3 - ((v - min) / r) * (h - 6)]; });
    var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join('');
    return '<svg class="ceo-spark" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true"><path class="a" d="' + d + 'L' + w + ' ' + h + 'L0 ' + h + 'Z"/><path class="l" pathLength="1" d="' + d + '"/></svg>';
  }
  function delta(k) {
    if (k.delta === null || k.delta === undefined) {
      return '<span class="ceo-delta none">' + (k.kind === 'stock' ? 'No earlier reading yet' : (k.prev === 0 ? 'None last period' : 'No comparison')) + '</span>';
    }
    if (+k.delta === 0) { return '<span class="ceo-delta">No change</span>'; }
    var up = k.delta > 0, tone = k.better === 'neutral' ? '' : ((up === (k.better === 'up')) ? 'good' : 'bad');
    var words = (up ? 'Up ' : 'Down ') + Math.abs(k.delta) + (k.unit === 'pct' ? ' points' : ' percent') + ' on the previous period';
    return '<span class="ceo-delta ' + tone + '" title="' + esc(words) + '">' + ico(up ? I.up : I.down) + Math.abs(k.delta) + (k.unit === 'pct' ? ' pts' : '%') + '</span>';
  }
  var STATE_WORDS = { no_data: ['No data yet', ''], restricted: ['Restricted', 'lock'], not_split: ['Not recorded by spoke', ''], not_granted: ['Module not held', ''], not_set_up: ['Module not set up yet', ''], failed: ['Source could not be read', ''], unavailable: ['Unavailable', ''] };
  function kpiCard(k, i) {
    var color = k.module === 'ops' ? 'var(--c-accent)' : col(moduleColor(k.module));
    var href = k.href === 'ceo:actions' ? 'ceo.php?p=actions' : k.href;
    var tag = href ? 'a' : 'div';
    var head = '<div class="t"><span>' + esc(k.label) + '</span><button type="button" class="ceo-info" data-info="' + esc(k.key) + '" aria-label="How ' + esc(k.label) + ' is worked out">' + ico(I.info) + '</button></div>';
    var hrefAttr = href ? ' href="' + esc(href) + '"' : '';
    if (k.state !== 'ok') {
      var w = STATE_WORDS[k.state] || [k.state, ''];
      return '<' + 'div class="ceo-kpi off ceo-rise' + (k.state === 'failed' ? ' failed' : '') + '" style="--i:' + i + '" data-k="' + esc(k.key) + '">' + head
        + '<div class="v">' + (w[1] ? ico(I.lock) : '') + esc(w[0]) + '</div><div class="f"><span>' + esc(k.note || k.module_label) + '</span></div></div>';
    }
    return '<' + tag + ' class="ceo-kpi ceo-rise" style="--i:' + i + ';--kc:' + color + '"' + hrefAttr + ' data-k="' + esc(k.key) + '">' + head
      + '<div class="v" data-unit="' + esc(k.unit) + '" data-to="' + esc(k.value) + '">' + (calm ? valueHtml(k.value, k.unit) : '0') + '</div>'
      + '<div class="row">' + delta(k) + spark(k.spark) + '</div>'
      + '<div class="f"><span>' + esc(k.kind === 'flow' ? periodLabel() : 'As of now') + '</span><span data-at="' + esc(k.at || '') + '">' + esc(ago(k.at)) + '</span></div></' + tag + '>';
  }
  function periodLabel() {
    var m = PERIODS.filter(function (p) { return p[0] === S.f.period; })[0];
    return S.f.period === 'custom' ? shortDate(S.f.from) + ' – ' + shortDate(S.f.to) : (m ? m[1] : '');
  }
  function moduleColor(k) { var m = (S.boot.modules || []).filter(function (x) { return x.key === k; })[0]; return m ? m.color : 1; }
  function bindCards(box) {
    $$('.v[data-to]', box).forEach(function (el) { count(el, +el.dataset.to, el.dataset.unit); });
    box.onclick = function (e) {
      var b = e.target.closest('[data-info]');
      if (!b) { return; }
      e.preventDefault(); e.stopPropagation();
      var k = S.cards[b.dataset.info];
      dialog(k.label, '<p style="margin:0 0 8px">' + esc(k.calc || 'You do not have access to how this figure is made.') + '</p>'
        + '<p class="ceo-sub">' + esc(k.module_label) + ' · ' + (k.kind === 'flow' ? 'Counted over the period' : 'A reading as of now') + ' · Cached for up to ' + Math.round(k.ttl / 60 * 10) / 10 + ' min</p>'
        + (k.note ? '<p class="ceo-sub">' + esc(k.note) + '</p>' : ''), [['Close', null]]);
    };
  }
  function updateCards(cards) {
    cards.forEach(function (k) {
      var was = S.cards[k.key], el = $('[data-k="' + k.key + '"]');
      S.cards[k.key] = k;
      if (!el) { return; }
      var at = $('[data-at]', el);
      if (at) { at.dataset.at = k.at || ''; at.textContent = ago(k.at); }
      if (was && k.state === 'ok' && was.state === 'ok' && +was.value !== +k.value) {
        var v = $('.v', el); v.dataset.v = was.value; count(v, +k.value, k.unit);
        var row = $('.row', el); if (row) { row.innerHTML = delta(k) + spark(k.spark); }
        el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
      } else if (was && was.state !== k.state) {
        el.outerHTML = kpiCard(k, 0);
        var n = $('[data-k="' + k.key + '"]');
        $$('.v[data-to]', n).forEach(function (x) { count(x, +x.dataset.to, x.dataset.unit); });
      }
    });
  }
  function strip(d) {
    var box = $('#ceo-strip');
    if (!box || !d) { return; }
    var over = (d.action_counts && d.action_counts.overdue) || 0;
    var connected = (d.coverage || S.coverage || []).filter(function (c) { return c.state === 'ok'; }).length, total = (d.coverage || S.coverage || []).length;
    if (d.coverage) { S.coverage = d.coverage; }
    box.innerHTML = '<div class="ceo-strip"><a href="ceo.php?p=actions"><b>' + num(d.actions_total || 0) + '</b><span>Open actions</span></a>'
      + '<a href="ceo.php?p=actions&type=overdue"><b>' + num(over) + '</b><span>Overdue</span></a>'
      + '<a href="ceo.php?p=actions&type=approval"><b>' + num((d.action_counts && d.action_counts.approval) || 0) + '</b><span>Awaiting approval</span></a>'
      + '<div><b>' + (total ? connected + ' of ' + total : '—') + '</b><span>Modules connected</span></div></div>';
  }
  function itemRow(i, isNew) {
    var tag = i.href ? 'a' : 'div';
    var late = i.status === 'Overdue' || i.status === 'Delayed' || i.status === 'Escalated';
    var meta = [i.module_label, i.spoke_name, i.owner].filter(Boolean).join(' · ');
    return '<' + tag + ' class="ceo-item' + (isNew ? ' new' : '') + '"' + (i.href ? ' href="' + esc(i.href) + '"' : '') + '><span class="ic" style="background:' + col(i.color) + '"></span>'
      + '<span style="min-width:0"><div class="ti">' + esc(i.title) + '</div>' + (i.detail ? '<div class="de">' + esc(i.detail) + '</div>' : '') + '<div class="me">' + esc(meta) + '</div></span>'
      + '<span class="ri"><span class="ceo-status ' + (late ? 'bad' : (i.status === 'Pending' || i.status === 'Due' || i.status === 'Due soon' ? 'warn' : '')) + '">' + esc(i.status) + '</span>'
      + '<span class="me">' + esc(i.due ? 'Due ' + shortDate(i.due) : ago(i.when)) + '</span></span></' + tag + '>';
  }
  function feedRow(i, isNew) {
    var tag = i.href ? 'a' : 'div';
    return '<' + tag + ' class="ceo-item' + (isNew ? ' new' : '') + '"' + (i.href ? ' href="' + esc(i.href) + '"' : '') + '><span class="ic" style="background:' + col(i.color) + '"></span>'
      + '<span style="min-width:0"><div class="ti">' + esc(i.title) + '</div>' + (i.detail ? '<div class="de">' + esc(i.detail) + '</div>' : '') + '<div class="me">' + esc([i.module_label, i.spoke_name].filter(Boolean).join(' · ')) + '</div></span>'
      + '<span class="ri"><span class="me">' + esc(ago(i.when)) + '</span></span></' + tag + '>';
  }
  function emptyBox(t, s) { return '<div class="ceo-empty"><b>' + esc(t) + '</b>' + esc(s || '') + '</div>'; }
  function feedKey(i) { return [i.module, i.title, i.when, i.detail].join('|'); }

  /* ---- line chart: one axis, crosshair, legend toggles, table view */
  function trendChart(box, t) {
    if (!t.series.length || !t.series.some(function (s) { return s.total > 0; })) { box.innerHTML = emptyBox('No dated activity in this period', 'Choose a longer period, or check data coverage below.'); return; }
    var on = t.series.filter(function (s) { return !S.hidden[s.module]; });
    var showTable = S.view.trendTable;
    var legend = '<div class="ceo-legend">' + t.series.map(function (s) {
      return '<button type="button" data-s="' + esc(s.module) + '" aria-pressed="' + !S.hidden[s.module] + '"><i style="background:' + col(s.color) + '"></i>' + esc(s.label) + ' <b>' + num(s.total) + '</b></button>';
    }).join('') + '</div>';
    if (showTable) {
      box.innerHTML = legend + '<div class="ceo-scroll" style="max-height:290px"><table class="ceo-table"><thead><tr><th>Period</th>' + t.series.map(function (s) { return '<th class="num">' + esc(s.label) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + t.labels.map(function (l, i) { return '<tr><td>' + esc(l) + '</td>' + t.series.map(function (s) { return '<td class="num">' + num(s.values[i]) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
    } else {
      var W = Math.max(320, box.clientWidth || 640), H = 270, L = 42, R = 12, T = 10, B = 26;
      var max = 1; on.forEach(function (s) { s.values.forEach(function (v) { if (v > max) { max = v; } }); });
      var step = Math.pow(10, Math.floor(Math.log10(max))), nice = Math.ceil(max / step) * step; if (nice / step <= 2) { nice = Math.ceil(max / (step / 2)) * (step / 2); }
      var n = t.labels.length, X = function (i) { return L + (n < 2 ? 0 : (i / (n - 1)) * (W - L - R)); }, Y = function (v) { return T + (1 - v / nice) * (H - T - B); };
      var g = '';
      for (var k = 0; k <= 4; k++) { var v = nice * k / 4, y = Y(v); g += '<line class="grid" x1="' + L + '" x2="' + (W - R) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 7) + '" y="' + (y + 4) + '" text-anchor="end">' + esc(short(v)) + '</text>'; }
      var every = Math.ceil(n / Math.max(2, Math.floor((W - L) / 72)));
      t.labels.forEach(function (l, i) { if (i % every === 0) { g += '<text x="' + X(i) + '" y="' + (H - 7) + '" text-anchor="middle">' + esc(bucket(l, t.unit)) + '</text>'; } });
      var paths = on.map(function (s, si) {
        var d = s.values.map(function (v, i) { return (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1); }).join('');
        return (si === 0 ? '<path class="ar" style="fill:' + col(s.color) + '" d="' + d + 'L' + X(n - 1) + ' ' + Y(0) + 'L' + X(0) + ' ' + Y(0) + 'Z"/>' : '')
          + '<path class="ln" pathLength="1" style="stroke:' + col(s.color) + ';animation-delay:' + si * 90 + 'ms" d="' + d + '"/>';
      }).join('');
      box.innerHTML = legend + '<div class="ceo-chart" id="ceo-tr"><svg viewBox="0 0 ' + W + ' ' + H + '" height="' + H + '" role="img" aria-label="Activity over time. Use the table view for the figures.">' + g + paths + '<line class="xh" id="ceo-xh" y1="' + T + '" y2="' + (H - B) + '" style="display:none"/></svg><div class="ceo-tip" id="ceo-tp" style="opacity:0"></div></div>';
      var c = $('#ceo-tr'), xh = $('#ceo-xh'), tp = $('#ceo-tp');
      c.onmousemove = function (e) {
        var r = c.getBoundingClientRect(), x = (e.clientX - r.left) * (W / r.width), i = Math.max(0, Math.min(n - 1, Math.round((x - L) / ((W - L - R) / Math.max(1, n - 1)))));
        xh.style.display = ''; xh.setAttribute('x1', X(i)); xh.setAttribute('x2', X(i));
        tp.innerHTML = '<b>' + esc(bucket(t.labels[i], t.unit)) + '</b>' + on.map(function (s) { return '<div><span><i style="background:' + col(s.color) + '"></i>' + esc(s.label) + '</span><span>' + num(s.values[i]) + '</span></div>'; }).join('');
        tp.style.opacity = 1;
        var px = X(i) / W * r.width; tp.style.left = (px > r.width - 180 ? px - 170 : px + 12) + 'px'; tp.style.top = '8px';
      };
      c.onmouseleave = function () { xh.style.display = 'none'; tp.style.opacity = 0; };
    }
    $$('[data-s]', box).forEach(function (b) { b.onclick = function () { S.hidden[b.dataset.s] = !S.hidden[b.dataset.s]; trendChart(box, t); }; });
  }
  function short(v) { return v >= 1000 ? (v / 1000).toLocaleString('en-IN', { maximumFractionDigits: 1 }) + 'k' : String(Math.round(v * 10) / 10); }
  function bucket(l, unit) {
    if (unit === 'day') { return shortDate(l); }
    if (unit === 'month') { var d = new Date(l + '-01T00:00:00'); return d.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' }); }
    return l.slice(5);
  }

  /* ---- the constellation: the hub with its spokes, sized by what is chosen */
  var NET = [['open', 'Open items'], ['inc_active', 'Startups'], ['mk_events', 'Events'], ['cw_occupancy', 'Occupancy']];
  function netValue(s, m) { return m === 'open' ? s.open : (s.values[m] === undefined ? null : s.values[m]); }
  function constellation(box, hub) {
    var list = hub.spokes;
    if (!list.length) { box.innerHTML = emptyBox('No hub or spokes set up', 'Add them to the Hub / Spoke list or as co-working centres.'); return; }
    var usable = NET.filter(function (m) { return m[0] === 'open' || hub.columns.some(function (c) { return c.key === m[0]; }); });
    if (!usable.some(function (m) { return m[0] === S.netMetric; })) { S.netMetric = 'open'; }
    var m = S.netMetric, unit = m === 'cw_occupancy' ? 'pct' : 'count';
    var W = 460, H = 380, cx = W / 2, cy = H / 2 - 2, R = 136;
    var center = list.filter(function (s) { return s.kind === 'hub'; })[0] || null;
    var ring = list.filter(function (s) { return s !== center; });
    var max = Math.max.apply(null, list.map(function (s) { return +netValue(s, m) || 0; }).concat([1]));
    var size = function (s, base) { return base + 15 * Math.sqrt((+netValue(s, m) || 0) / max); };
    var parts = '', links = '';
    var node = function (s, x, y, r, i, hubNode) {
      var v = netValue(s, m), late = s.overdue > 0;
      var label = s.name + ': ' + (v === null ? 'not recorded' : fmt(v, unit)) + (late ? ', ' + s.overdue + ' overdue' : '');
      return '<g class="node' + (hubNode ? ' hub' : '') + (late ? ' late' : '') + (S.f.spoke === s.key ? ' on' : '') + '" role="button" tabindex="0" data-spoke="' + esc(s.key) + '" aria-label="' + esc(label) + '" aria-pressed="' + (S.f.spoke === s.key) + '">'
        + '<g class="g-in" style="--i:' + i + '"><circle class="h" cx="' + x + '" cy="' + y + '" r="' + (r + 9) + '"/><circle class="b" cx="' + x + '" cy="' + y + '" r="' + r + '"/>'
        + '<text class="val" x="' + x + '" y="' + y + '" style="font-size:' + (v !== null && fmt(v, unit).length > 4 ? 13 : 17) + 'px">' + esc(v === null ? '–' : fmt(v, unit)) + '</text>'
        + '<text class="nm" x="' + x + '" y="' + (y + r + 21) + '">' + esc(s.name) + '</text>' + (hubNode ? '<text class="sb" x="' + x + '" y="' + (y + r + 35) + '">Hub</text>' : (late ? '<text class="sb" x="' + x + '" y="' + (y + r + 35) + '">' + s.overdue + ' overdue</text>' : '')) + '</g></g>';
    };
    ring.forEach(function (s, i) {
      var a = -Math.PI / 2 + (i / ring.length) * Math.PI * 2, x = cx + Math.cos(a) * R * 1.32, y = cy + Math.sin(a) * R * .94;
      x = Math.round(x); y = Math.round(y);
      if (center) {
        links += '<path id="ceo-l' + i + '" class="link" d="M' + cx + ' ' + cy + 'L' + x + ' ' + y + '"/>';
        if (!calm) { links += '<circle class="pulse" r="3"><animateMotion dur="' + (2.8 + (i % 3) * .7) + 's" begin="' + (i * .5) + 's" repeatCount="indefinite" keyPoints="' + (i % 2 ? '1;0' : '0;1') + '" keyTimes="0;1" calcMode="linear"><mpath href="#ceo-l' + i + '"/></animateMotion></circle>'; }
      }
      parts += node(s, x, y, size(s, 21), i + 1, false);
    });
    if (center) { parts += node(center, cx, cy, size(center, 31), 0, true); }
    box.innerHTML = '<div class="ceo-seg sm" role="group" aria-label="What the circles show" style="margin-bottom:6px">' + usable.map(function (x) { return '<button type="button" data-m="' + x[0] + '" aria-pressed="' + (x[0] === m) + '">' + x[1] + '</button>'; }).join('') + '</div>'
      + '<div class="ceo-net"><svg viewBox="0 0 ' + W + ' ' + H + '" role="group" aria-label="Hub and spokes. Choose one to filter the dashboard.">' + links + parts + '</svg></div>';
    $$('[data-m]', box).forEach(function (b) { b.onclick = function () { S.netMetric = b.dataset.m; constellation(box, hub); }; });
    $$('.node', box).forEach(function (n) {
      if (!can('filter')) { n.style.cursor = 'default'; return; }
      n.onclick = function () { setSpoke(n.dataset.spoke); };
      n.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setSpoke(n.dataset.spoke); } };
    });
  }

  function ring(pct, color) {
    var p = Math.max(0, Math.min(100, pct || 0));
    return '<div class="ceo-ring" style="--rc:' + color + ';--off:' + (100 - p) + '"><svg width="58" height="58" viewBox="0 0 58 58" aria-hidden="true"><circle class="tk" cx="29" cy="29" r="25"/><circle class="vl" cx="29" cy="29" r="25" pathLength="100"/></svg><span>' + (pct === null ? '–' : Math.round(pct) + '%') + '</span></div>';
  }
  var T_WORDS = { achieved: 'Achieved', on_track: 'On track', at_risk: 'At risk', behind: 'Behind', no_data: 'No data' };
  function targetRow(t) {
    return '<div class="ceo-target">' + ring(t.pct, col(t.color)) + '<div class="b"><div class="n">' + esc(t.label) + '</div>'
      + '<div class="d">' + esc(fmt(t.actual, t.unit)) + ' of ' + esc(fmt(t.target, t.unit)) + ' · ' + esc(t.spoke_name) + ' · ' + esc(t.period_type) + (t.owner ? ' · ' + esc(t.owner) : '') + '</div></div>'
      + '<span class="ceo-status ' + esc(t.status) + '">' + esc(T_WORDS[t.status]) + '</span></div>';
  }
  function deptTable(rows) {
    return '<div class="ceo-dept head"><span>Department</span><span class="n">Open</span><span class="n">Late</span><span class="n">Delivered</span></div>' + rows.map(function (d) {
      var tag = d.href ? 'a' : 'div';
      return '<' + tag + ' class="ceo-dept"' + (d.href ? ' href="' + esc(d.href) + '"' : '') + '><span class="nm"><i style="background:' + col(d.color) + '"></i><span>' + esc(d.label) + '</span></span>'
        + (d.state === 'ok' ? '<span class="n">' + num(d.open) + '</span><span class="n' + (d.late ? ' bad' : '') + '">' + num(d.late) + '</span><span class="n" title="' + esc(d.done_label) + '">' + (d.done === null ? '—' : num(d.done)) + '</span>'
                            : '<span class="n" style="grid-column:span 3;font-weight:500;color:var(--c-mute)">' + (d.state === 'not_granted' ? 'Module not held' : 'Not set up yet') + '</span>') + '</' + tag + '>';
    }).join('');
  }
  function covList(c) {
    return '<div class="ceo-cov">' + c.map(function (x) {
      return '<div class="ceo-item" style="grid-template-columns:auto 1fr auto"><span class="ic" style="background:' + col(x.color) + '"></span><span><div class="ti">' + esc(x.label) + '</div><div class="me">' + esc(x.gap) + '</div></span>'
        + '<span class="ceo-status ' + (x.state === 'ok' ? 'ok' : (x.state === 'failed' ? 'bad' : 'warn')) + '">' + esc(x.state_label) + '</span></div>';
    }).join('') + '</div>';
  }
  function skeleton() {
    var c = ''; for (var i = 0; i < 10; i++) { c += '<div class="ceo-sk" style="height:142px;border-radius:14px"></div>'; }
    return '<div class="ceo-kpis">' + c + '</div><div class="ceo-grid"><div class="ceo-sk ceo-span-8" style="height:340px"></div><div class="ceo-sk ceo-span-4" style="height:340px"></div></div>';
  }

  /* ------------------------------------------------------------ views */
  var VIEWS = {};

  VIEWS.overview = function (fresh, force) {
    var v = $('#ceo-view');
    if (fresh) { S.data = null; if (!force) { v.innerHTML = skeleton(); } }
    if (!fresh && S.data) { return live(force); }
    return api('overview', { force: force ? 1 : '' }).then(function (d) {
      S.data = d;
      S.cards = {};
      d.cards.forEach(function (k) { S.cards[k.key] = k; });
      strip(d);
      var parts = [];
      if (S.f.spoke && d.cards.some(function (k) { return k.state === 'not_split'; })) {
        parts.push('<div class="ceo-banner info">Some modules do not record which spoke an item belongs to, so those cards stay organisation-wide and are marked. See Data coverage below.</div>');
      }
      parts.push('<div class="ceo-kpis" id="ceo-cards">' + d.cards.map(kpiCard).join('') + '</div>');
      parts.push('<div class="ceo-grid">'
        + '<section class="ceo-card ceo-span-8 ceo-rise" style="--i:6"><div class="ceo-card-h"><div><h2>Activity across the organisation</h2><p>Records created per ' + esc(d.trend.unit) + ', ' + esc(periodLabel().toLowerCase()) + '</p></div><button type="button" class="ceo-tbl-toggle" id="ceo-tt">' + (S.view.trendTable ? 'Show chart' : 'Show table') + '</button></div><div id="ceo-trend"></div></section>'
        + '<section class="ceo-card ceo-span-4 ceo-rise" style="--i:8"><div class="ceo-card-h"><div><h2>Hub &amp; spokes</h2><p>Select one to filter everything</p></div><a class="ceo-link" href="ceo.php?p=spokes">Compare</a></div><div id="ceo-net"></div></section>'
        + '</div>');
      parts.push('<div class="ceo-grid">'
        + '<section class="ceo-card ceo-span-5 ceo-rise" style="--i:10"><div class="ceo-card-h"><div><h2>Targets vs actual</h2><p>Current period for each target</p></div><a class="ceo-link" href="ceo.php?p=targets">All targets</a></div>'
        + (d.targets.length ? d.targets.slice(0, 5).map(targetRow).join('') : emptyBox('No targets set yet', can('targets') ? 'Set them on Targets vs actual.' : 'Management sets these.')) + '</section>'
        + '<section class="ceo-card ceo-span-7 ceo-rise" style="--i:11"><div class="ceo-card-h"><div><h2>Department performance</h2><p>Open and late items, and what was delivered this period</p></div></div>' + deptTable(d.departments) + '</section>'
        + '</div>');
      parts.push('<div class="ceo-grid">'
        + '<section class="ceo-card ceo-span-7 ceo-rise" style="--i:12"><div class="ceo-card-h"><div><h2>Pending actions</h2><p id="ceo-act-sub">' + num(d.actions_total) + ' open, most urgent first</p></div><a class="ceo-link" href="ceo.php?p=actions">Open queue</a></div><div id="ceo-actions">' + actionTop(d) + '</div></section>'
        + '<section class="ceo-card ceo-span-5 ceo-rise" style="--i:13"><div class="ceo-card-h"><div><h2>Live activity</h2><p>Newest first</p></div><a class="ceo-link" href="ceo.php?p=activity">All activity</a></div><div class="ceo-feed" id="ceo-feed">' + feedHtml(d.feed) + '</div></section>'
        + '</div>');
      parts.push('<div class="ceo-grid">'
        + (d.funnel.length ? '<section class="ceo-card ceo-span-5 ceo-rise" style="--i:14"><div class="ceo-card-h"><div><h2>Incubation pipeline</h2><p>Applications in each stage now</p></div></div>' + funnel(d.funnel) + '</section>' : '')
        + '<section class="ceo-card ' + (d.funnel.length ? 'ceo-span-7' : 'ceo-span-12') + ' ceo-rise" style="--i:15"><div class="ceo-card-h"><div><h2>Data coverage</h2><p>What each module can and cannot report</p></div></div>' + covList(d.coverage) + '</section>'
        + '</div>');
      v.innerHTML = parts.join('');
      bindCards($('#ceo-cards'));
      trendChart($('#ceo-trend'), d.trend);
      constellation($('#ceo-net'), d.hub);
      $('#ceo-tt').onclick = function () { S.view.trendTable = !S.view.trendTable; this.textContent = S.view.trendTable ? 'Show chart' : 'Show table'; trendChart($('#ceo-trend'), d.trend); };
    });
  };
  function actionTop(d) { return d.action_top.length ? d.action_top.map(function (i) { return itemRow(i); }).join('') : emptyBox('Nothing waiting', 'No open items in your scope.'); }
  function feedHtml(list) {
    var first = !Object.keys(S.feedSeen).length, html = list.map(function (i) { var k = feedKey(i), isNew = !first && !S.feedSeen[k]; S.feedSeen[k] = 1; return feedRow(i, isNew); }).join('');
    return html || emptyBox('No recent activity', 'Nothing in the last 45 days that you may see.');
  }
  function funnel(rows) {
    var max = Math.max.apply(null, rows.map(function (r) { return r.n; }).concat([1]));
    return rows.map(function (r, i) { return '<div class="ceo-bar" style="--i:' + i + '"><span class="lb" title="' + esc(r.name) + '">' + esc(r.name) + '</span><span class="tr"><i style="--w:' + (r.n / max) + ';--bc:var(--c1)"></i></span><span class="vl">' + num(r.n) + '</span></div>'; }).join('');
  }
  function live(force) {
    return api('live', { force: force ? 1 : '' }).then(function (d) {
      S.data = Object.assign(S.data || {}, d);
      updateCards(d.cards);
      strip(d);
      var a = $('#ceo-actions'); if (a) { a.innerHTML = actionTop(d); $('#ceo-act-sub').textContent = num(d.actions_total) + ' open, most urgent first'; }
      var f = $('#ceo-feed'); if (f) { f.innerHTML = feedHtml(d.feed); }
    });
  }

  VIEWS.spokes = function (fresh) {
    var v = $('#ceo-view');
    if (fresh) { v.innerHTML = '<div class="ceo-sk" style="height:420px"></div>'; }
    return Promise.all([api('spokes'), api('live')]).then(function (r) {
      var d = r[0], hub = d.hub; S.data = d; strip(r[1]);
      if (!hub.spokes.length) { v.innerHTML = '<div class="ceo-card">' + emptyBox('No hub or spokes in your access') + '</div>'; return; }
      var cols = hub.columns;
      var best = {};
      cols.forEach(function (c) { if (c.better === 'neutral') { return; } var vals = hub.spokes.map(function (s) { return s.values[c.key]; }).filter(function (x) { return x !== undefined && x !== null; }); if (vals.length > 1) { best[c.key] = c.better === 'up' ? Math.max.apply(null, vals) : Math.min.apply(null, vals); } });
      var pick = S.view.spokeKpi && cols.some(function (c) { return c.key === S.view.spokeKpi; }) ? S.view.spokeKpi : (cols[0] ? cols[0].key : '');
      var pc = cols.filter(function (c) { return c.key === pick; })[0];
      var maxV = Math.max.apply(null, hub.spokes.map(function (s) { return +s.values[pick] || 0; }).concat([1]));
      v.innerHTML = '<div class="ceo-grid"><section class="ceo-card ceo-span-5 ceo-rise"><div class="ceo-card-h"><div><h2>Network</h2><p>Select a centre to focus the dashboard</p></div></div><div id="ceo-net"></div></section>'
        + '<section class="ceo-card ceo-span-7 ceo-rise" style="--i:2"><div class="ceo-card-h"><div><h2>Side by side</h2><p>Choose a measure</p></div><label class="ceo-f"><span class="sr">Measure</span><select id="ceo-sk">' + cols.map(function (c) { return '<option value="' + esc(c.key) + '"' + (c.key === pick ? ' selected' : '') + '>' + esc(c.label) + '</option>'; }).join('') + '</select></label></div>'
        + hub.spokes.map(function (s, i) { var x = s.values[pick]; return '<div class="ceo-bar" style="--i:' + i + '"><span class="lb">' + esc(s.name) + '</span><span class="tr"><i style="--w:' + ((+x || 0) / maxV) + ';--bc:' + col(pc ? moduleColor(pc.module) : 1) + '"></i></span><span class="vl">' + (x === undefined ? '—' : esc(fmt(x, pc.unit))) + '</span></div>'; }).join('')
        + '<p class="ceo-sub" style="margin-top:10px">Items without a spoke: ' + num(hub.unassigned) + ' (their module does not record one).</p></section></div>'
        + '<section class="ceo-card ceo-rise" style="--i:3;margin-bottom:14px"><div class="ceo-card-h"><div><h2>Comparison</h2><p>Figures recorded by spoke. Best in each column is highlighted.</p></div></div><div class="ceo-scroll"><table class="ceo-table"><thead><tr><th>Hub / spoke</th>' + cols.map(function (c) { return '<th class="num">' + esc(c.label) + '</th>'; }).join('') + '<th class="num">Open</th><th class="num">Overdue</th></tr></thead><tbody>'
        + hub.spokes.map(function (s) { return '<tr><td><strong>' + esc(s.name) + '</strong>' + (s.kind === 'hub' ? ' <span class="tag">Hub</span>' : '') + '</td>' + cols.map(function (c) { var x = s.values[c.key]; return '<td class="num' + (x !== undefined && x === best[c.key] ? ' best' : '') + '">' + (x === undefined || x === null ? '—' : esc(fmt(x, c.unit))) + '</td>'; }).join('') + '<td class="num">' + num(s.open) + '</td><td class="num">' + num(s.overdue) + '</td></tr>'; }).join('') + '</tbody></table></div></section>'
        + '<div class="ceo-grid"><section class="ceo-card ceo-span-6 ceo-rise" style="--i:4"><div class="ceo-card-h"><div><h2>Reporting compliance</h2><p>Event results submitted against those that fell due</p></div></div>'
        + hub.spokes.map(function (s, i) { var c = s.compliance || {}; var has = c.rate !== null && c.rate !== undefined; return '<div class="ceo-bar" style="--i:' + i + '"><span class="lb">' + esc(s.name) + '</span><span class="tr"><i style="--w:' + (has ? c.rate / 100 : 0) + ';--bc:var(--c3)"></i></span><span class="vl">' + (has ? c.rate + '%' : '—') + '</span></div>'; }).join('')
        + '<p class="ceo-sub" style="margin-top:10px">The CRM has no daily or weekly report submission of its own yet, so this is the only compliance it can measure. See the handover notes.</p></section>'
        + '<section class="ceo-card ceo-span-6 ceo-rise" style="--i:5"><div class="ceo-card-h"><div><h2>Targets by spoke</h2><p>Targets scoped to one centre</p></div></div>'
        + (d.targets.filter(function (t) { return t.spoke; }).map(targetRow).join('') || emptyBox('No spoke targets set')) + '</section></div>';
      constellation($('#ceo-net'), hub);
      var sel = $('#ceo-sk'); sel.onchange = function () { S.view.spokeKpi = sel.value; route(false); };
    });
  };

  VIEWS.actions = function (fresh, force) {
    var v = $('#ceo-view');
    var u = new URLSearchParams(location.search);
    if (S.view.act === undefined) { S.view.act = { type: u.get('type') || '', status: '', assignee: '', manager: '', page: 1 }; }
    var a = S.view.act;
    if (fresh) { v.innerHTML = '<div class="ceo-sk" style="height:480px"></div>'; }
    return Promise.all([api('actions', { type: a.type, status: a.status, assignee: a.assignee, manager: a.manager, page: a.page, force: force ? 1 : '' }), S.view.people ? Promise.resolve({ people: S.view.people }) : api('assignees')]).then(function (r) {
      var d = r[0]; S.view.people = r[1].people; S.data = d;
      strip({ actions_total: d.total, action_counts: d.counts, coverage: d.coverage });
      var types = S.boot.action_types;
      var people = '<option value="">Anyone</option>' + S.view.people.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');
      v.innerHTML = '<section class="ceo-card"><div class="ceo-tabs" role="group" aria-label="Kind of item"><button type="button" data-t="" aria-pressed="' + (!a.type) + '">All <em>' + num(Object.keys(d.counts).reduce(function (s, k) { return s + d.counts[k]; }, 0)) + '</em></button>'
        + Object.keys(types).map(function (k) { return '<button type="button" data-t="' + k + '" aria-pressed="' + (a.type === k) + '">' + esc(types[k]) + ' <em>' + num(d.counts[k] || 0) + '</em></button>'; }).join('') + '</div>'
        + (can('filter') ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"><label class="ceo-f">Status <select id="ceo-as"><option value="">Any</option>' + ['Overdue', 'Pending', 'Delayed', 'Escalated', 'Due', 'Upcoming', 'Open', 'New', 'In progress', 'Waiting'].map(function (s) { return '<option' + (a.status === s ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select></label>'
          + '<label class="ceo-f">Assigned to <select id="ceo-aa">' + people + '</select></label><label class="ceo-f">Responsible manager <select id="ceo-am">' + people + '</select></label></div>' : '')
        + '<div>' + (d.items.length ? d.items.map(function (i) { return itemRow(i); }).join('') : emptyBox('Nothing here', 'No open items match these filters.')) + '</div>'
        + (d.pages > 1 ? '<div class="ceo-pager"><button class="ceo-btn sm" id="ceo-pp"' + (d.page < 2 ? ' disabled' : '') + '>Previous</button>Page ' + d.page + ' of ' + d.pages + '<button class="ceo-btn sm" id="ceo-pn"' + (d.page >= d.pages ? ' disabled' : '') + '>Next</button></div>' : '')
        + '<p class="ceo-sub" style="margin-top:10px">Items open in their own module, where its approval rules apply. Nothing is approved from here.</p></section>';
      $$('[data-t]', v).forEach(function (b) { b.onclick = function () { a.type = b.dataset.t; a.page = 1; route(true); }; });
      [['#ceo-as', 'status'], ['#ceo-aa', 'assignee'], ['#ceo-am', 'manager']].forEach(function (x) { var el = $(x[0]); if (el) { el.value = a[x[1]]; el.onchange = function () { a[x[1]] = el.value; a.page = 1; route(true); }; } });
      var pp = $('#ceo-pp'), pn = $('#ceo-pn');
      if (pp) { pp.onclick = function () { a.page--; route(true); }; pn.onclick = function () { a.page++; route(true); }; }
    });
  };

  VIEWS.activity = function (fresh, force) {
    var v = $('#ceo-view');
    if (fresh) { S.feedSeen = {}; v.innerHTML = '<div class="ceo-sk" style="height:480px"></div>'; }
    return api('activity', { force: force ? 1 : '' }).then(function (d) {
      S.data = d;
      if (!$('#ceo-feed')) { v.innerHTML = '<section class="ceo-card"><div class="ceo-card-h"><div><h2>Latest across every module</h2><p>Last 45 days. Details appear only where your access allows.</p></div></div><div id="ceo-feed"></div></section>'; }
      $('#ceo-feed').innerHTML = feedHtml(d.items);
    });
  };

  VIEWS.targets = function (fresh) {
    var v = $('#ceo-view');
    if (fresh) { v.innerHTML = '<div class="ceo-sk" style="height:420px"></div>'; }
    return api('targets').then(function (d) {
      S.data = d; S.view.tk = d.kpis;
      var counts = {}; d.targets.forEach(function (t) { counts[t.status] = (counts[t.status] || 0) + 1; });
      v.innerHTML = '<section class="ceo-card" style="margin-bottom:14px"><div class="ceo-card-h"><div><h2>Every target</h2><p>' + d.targets.length + ' set · ' + (counts.achieved || 0) + ' achieved · ' + (counts.on_track || 0) + ' on track · ' + (counts.at_risk || 0) + ' at risk · ' + (counts.behind || 0) + ' behind</p></div>'
        + (can('targets') ? '<button class="ceo-btn pri" id="ceo-tadd">Set a target</button>' : '') + '</div>'
        + (d.targets.length ? '<div class="ceo-scroll"><table class="ceo-table"><thead><tr><th>KPI</th><th>Owner</th><th>Scope</th><th>Period</th><th class="num">Target</th><th class="num">Actual</th><th class="num">Achieved</th><th class="num">Gap</th><th class="num">Previous</th><th>Status</th>' + (can('targets') ? '<th></th>' : '') + '</tr></thead><tbody>'
          + d.targets.map(function (t) {
            return '<tr><td><strong>' + esc(t.label) + '</strong><br><span class="ceo-sub">' + esc(t.module_label) + '</span></td><td>' + esc(t.owner) + '</td><td>' + esc(t.spoke_name) + '</td><td>' + esc(t.period_type) + '<br><span class="ceo-sub">' + esc(shortDate(t.from)) + ' – ' + esc(shortDate(t.to)) + ', ' + t.elapsed + '% gone</span></td>'
              + '<td class="num">' + esc(fmt(t.target, t.unit)) + '</td><td class="num">' + esc(fmt(t.actual, t.unit)) + '</td><td class="num">' + (t.pct === null ? '—' : t.pct + '%') + '</td><td class="num">' + esc(fmt(t.gap, t.unit)) + '</td><td class="num">' + esc(fmt(t.prev, t.unit)) + '</td>'
              + '<td><span class="ceo-status ' + esc(t.status) + '">' + esc(T_WORDS[t.status]) + '</span></td>' + (can('targets') ? '<td><button class="ceo-btn sm dng" data-del="' + t.id + '">Remove</button></td>' : '') + '</tr>';
          }).join('') + '</tbody></table></div>' : emptyBox('No targets set yet', can('targets') ? 'Set the first one, for the period that suits the measure.' : 'Management sets these.')) + '</section>';
      var add = $('#ceo-tadd'); if (add) { add.onclick = targetDialog; }
      $$('[data-del]', v).forEach(function (b) { b.onclick = function () { post('target_delete', { id: +b.dataset.del }).then(function () { toast('Target removed.'); route(false); }).catch(function (e) { toast(e.message, true); }); }; });
    });
  };
  function targetDialog() {
    var k = S.view.tk;
    var body = '<div class="ceo-form"><label>KPI<select id="t-k">' + k.map(function (x) { return '<option value="' + esc(x.key) + '">' + esc(x.module + ' — ' + x.label) + '</option>'; }).join('') + '</select></label>'
      + '<div class="two"><label>Reporting period<select id="t-p"></select></label><label>Scope<select id="t-s"></select></label></div>'
      + '<div class="two"><label><span id="t-ul">Target</span><input type="number" id="t-v" min="0" step="any" required></label><label>Responsible owner<input type="text" id="t-o" maxlength="160" placeholder="Department or person"></label></div></div>';
    var d = dialog('Set a target', body, [['Cancel', null], ['Save target', function () {
      return post('target_save', { kpi: $('#t-k').value, period: $('#t-p').value, spoke: $('#t-s').value, target: $('#t-v').value, owner: $('#t-o').value }).then(function () { toast('Target saved.'); route(false); });
    }, 'pri']]);
    var sync = function () {
      var x = k.filter(function (y) { return y.key === $('#t-k').value; })[0];
      $('#t-p').innerHTML = x.periods.map(function (p) { return '<option value="' + p + '">' + p.charAt(0).toUpperCase() + p.slice(1) + '</option>'; }).join('');
      var spokes = x.spoke ? S.boot.spokes : [];
      $('#t-s').innerHTML = (S.boot.access.limited ? '' : '<option value="">Whole organisation</option>') + spokes.map(function (s) { return '<option value="' + esc(s.key) + '">' + esc(s.name) + '</option>'; }).join('');
      $('#t-ul').textContent = 'Target' + (x.unit === 'pct' ? ' (%)' : x.unit === 'money' ? ' (₹)' : '');
    };
    $('#t-k', d).onchange = sync; sync();
  }

  /* ---- reports */
  VIEWS.reports = function (fresh) {
    var v = $('#ceo-view');
    if (!fresh && $('#ceo-rep-out')) { return Promise.resolve(); }
    if (fresh) { v.innerHTML = '<div class="ceo-sk" style="height:320px"></div>'; }
    return api('reports').then(function (d) {
      S.data = d;
      var u = new URLSearchParams(location.search);
      var R = S.view.rep = S.view.rep || { kind: u.get('kind') || 'monthly', from: u.get('from') || S.f.from, to: u.get('to') || S.f.to, spoke: u.get('spoke') || '', module: '' };
      v.innerHTML = '<section class="ceo-card" style="margin-bottom:14px"><div class="ceo-card-h"><div><h2>Compile a report</h2><p>Built live from the CRM, within your access</p></div></div>'
        + '<div class="ceo-kinds" role="group" aria-label="Report">' + Object.keys(d.kinds).map(function (k) { return '<button type="button" class="ceo-kind" data-k="' + k + '" aria-pressed="' + (R.kind === k) + '">' + esc(d.kinds[k]) + '</button>'; }).join('') + '</div>'
        + '<div class="ceo-form" style="margin-top:12px"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">'
        + '<label style="width:150px">From<input type="date" id="r-f" value="' + esc(R.from) + '"></label><label style="width:150px">To<input type="date" id="r-t" value="' + esc(R.to) + '"></label>'
        + '<label style="width:190px">Hub or spoke<select id="r-s"><option value="">' + (S.boot.access.limited ? 'All my spokes' : 'All') + '</option>' + S.boot.spokes.map(function (s) { return '<option value="' + esc(s.key) + '"' + (R.spoke === s.key ? ' selected' : '') + '>' + esc(s.name) + '</option>'; }).join('') + '</select></label>'
        + '<label style="width:190px">Department (module)<select id="r-m"><option value="">All</option>' + S.boot.modules.map(function (m) { return '<option value="' + esc(m.key) + '">' + esc(m.label) + '</option>'; }).join('') + '</select></label>'
        + '<button class="ceo-btn pri" id="r-go">Preview report</button>'
        + (d.can_export ? '<button class="ceo-btn" id="r-x">Excel (.xlsx)</button><button class="ceo-btn" id="r-p">PDF</button>' : '') + '</div></div></section>'
        + '<section class="ceo-card" id="ceo-rep-out" style="margin-bottom:14px" hidden></section>'
        + '<div class="ceo-grid"><section class="ceo-card ceo-span-7"><div class="ceo-card-h"><div><h2>Scheduled reports</h2><p>' + (d.mail_ready ? 'Sent by email to people authorised for reports. The mail carries their own figures and a sign-in link, not a file.' : 'Email is not configured, so schedules will not send.') + '</p></div><button class="ceo-btn" id="s-add">Schedule a report</button></div>'
        + (d.schedules.length ? '<div class="ceo-scroll"><table class="ceo-table"><thead><tr><th>Report</th><th>How often</th><th>Recipients</th><th>Next</th><th>Last result</th><th></th></tr></thead><tbody>' + d.schedules.map(function (s) { return '<tr><td>' + esc(s.kind_label) + '</td><td>' + esc(s.frequency) + '</td><td>' + esc(s.recipient_names.join(', ')) + '</td><td>' + esc(shortDate(s.next_run)) + '</td><td>' + esc(s.last_result || '—') + '</td><td><button class="ceo-btn sm dng" data-sd="' + s.id + '">Remove</button></td></tr>'; }).join('') + '</tbody></table></div>' : emptyBox('Nothing scheduled')) + '</section>'
        + '<section class="ceo-card ceo-span-5"><div class="ceo-card-h"><div><h2>Recently generated</h2></div></div>' + (d.history.length ? d.history.slice(0, 8).map(function (h) { return '<div class="ceo-item" style="grid-template-columns:1fr auto"><span><div class="ti" style="font-size:13px">' + esc(d.kinds[h.kind] || h.kind) + '</div><div class="me">' + esc(h.actor) + ' · ' + esc(h.format) + ' · ' + esc(shortDate(h.date_from)) + ' – ' + esc(shortDate(h.date_to)) + '</div></span><span class="me">' + esc(ago(h.created_at)) + '</span></div>'; }).join('') : emptyBox('None yet')) + '</section></div>';
      var read = function () { R.from = $('#r-f').value; R.to = $('#r-t').value; R.spoke = $('#r-s').value; R.module = $('#r-m').value; };
      $$('.ceo-kind', v).forEach(function (b) { b.onclick = function () { R.kind = b.dataset.k; $$('.ceo-kind', v).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); }; });
      $('#r-go').onclick = function () { read(); preview(R); };
      var file = function (fmtName) { read(); var p = new URLSearchParams({ r: 'export', format: fmtName, kind: R.kind, from: R.from, to: R.to }); if (R.spoke) { p.set('spoke', R.spoke); } if (R.module) { p.set('module', R.module); } if (conf.admin) { p.set('as', 'admin'); p.set('org', conf.org); } location.href = conf.api + '?' + p.toString(); toast('Preparing your download…'); };
      if ($('#r-x')) { $('#r-x').onclick = function () { file('xlsx'); }; $('#r-p').onclick = function () { file('pdf'); }; }
      $('#s-add').onclick = function () { scheduleDialog(d); };
      $$('[data-sd]', v).forEach(function (b) { b.onclick = function () { post('schedule_delete', { id: +b.dataset.sd }).then(function () { toast('Schedule removed.'); route(true); }).catch(function (e) { toast(e.message, true); }); }; });
      if (u.get('kind')) { preview(R); }
    });
  };
  function preview(R) {
    var out = $('#ceo-rep-out');
    out.hidden = false; out.innerHTML = '<div class="ceo-sk" style="height:240px"></div>';
    var p = new URLSearchParams({ r: 'report', kind: R.kind, from: R.from, to: R.to }); if (R.spoke) { p.set('spoke', R.spoke); } if (R.module) { p.set('module', R.module); } if (conf.admin) { p.set('as', 'admin'); p.set('org', conf.org); }
    fetch(conf.api + '?' + p.toString(), { credentials: 'same-origin' }).then(handle).then(function (j) {
      var r = j.report;
      var rows = function (list, cols) { return '<div class="ceo-scroll"><table class="ceo-table"><thead><tr>' + cols.map(function (c) { return '<th' + (c[2] ? ' class="num"' : '') + '>' + esc(c[0]) + '</th>'; }).join('') + '</tr></thead><tbody>' + list.map(function (x) { return '<tr>' + cols.map(function (c) { return '<td' + (c[2] ? ' class="num"' : '') + '>' + esc(c[1](x)) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>'; };
      out.innerHTML = '<div class="ceo-rep ceo-rise"><div class="ceo-rephead"><h2>' + esc(r.title) + '</h2><p>' + esc(r.org) + ' · ' + esc(r.from) + ' to ' + esc(r.to) + ' · ' + esc(r.scope) + (r.module ? ' · ' + esc(r.module) : '') + ' · Generated ' + esc(r.generated_at) + '</p></div>'
        + '<h3><span>1</span>KPI summary</h3>' + rows(r.kpis, [['Module', function (k) { return k.module; }], ['KPI', function (k) { return k.label; }], ['Value', function (k) { return k.state === 'ok' ? fmt(k.value, k.unit) : k.state_label; }, 1], ['Previous', function (k) { return fmt(k.prev, k.unit); }, 1], ['Change', function (k) { return k.delta === null ? '—' : (k.delta > 0 ? '+' : '') + k.delta + (k.unit === 'pct' ? ' pts' : '%'); }, 1]])
        + '<h3><span>2</span>Department-wise performance</h3>' + deptTable(r.departments)
        + '<h3><span>3</span>Hub-and-spoke comparison</h3>' + (r.hub.spokes.length ? rows(r.hub.spokes, [['Hub / spoke', function (s) { return s.name; }]].concat(r.hub.columns.slice(0, 6).map(function (c) { return [c.label, function (s) { return fmt(s.values[c.key], c.unit); }, 1]; })).concat([['Open', function (s) { return s.open; }, 1], ['Overdue', function (s) { return s.overdue; }, 1]])) : emptyBox('None authorised'))
        + '<h3><span>4</span>Completed activities</h3>' + (r.completed.length ? rows(r.completed, [['Measure', function (c) { return c.label; }], ['Module', function (c) { return c.module; }], ['In period', function (c) { return fmt(c.value, c.unit); }, 1]]) : emptyBox('Nothing measurable completed'))
        + '<h3><span>5</span>Pending and overdue tasks</h3><p class="ceo-sub">' + num(r.pending_total) + ' open' + (r.pending_total > 30 ? '; 30 most urgent shown, exports carry more' : '') + '</p>' + r.pending.map(function (i) { return itemRow(i); }).join('')
        + '<h3><span>6</span>Significant changes and exceptions</h3>' + (r.exceptions.length ? r.exceptions.map(function (e) { return '<div class="ceo-item" style="grid-template-columns:auto 1fr"><span class="ceo-status ' + (e.severity === 'High' ? 'bad' : '') + '">' + esc(e.severity) + '</span><span style="font-size:13px">' + esc(e.text) + '</span></div>'; }).join('') : emptyBox('No exceptions'))
        + '<h3><span>7</span>Targets versus actual</h3>' + (r.targets.length ? r.targets.map(targetRow).join('') : emptyBox('No targets for this scope'))
        + '<h3><span>8</span>Data coverage and unavailable metrics</h3>' + covList(r.coverage) + '</div>';
      out.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
    }).catch(function (e) { out.innerHTML = '<div class="ceo-error">' + esc(e.message) + '</div>'; });
  }
  function scheduleDialog(d) {
    if (!d.recipients.length) { dialog('Schedule a report', '<p>No one is authorised for reports yet. Give someone a profile with Generate reports first.</p>', [['Close', null]]); return; }
    var kinds = ['daily', 'weekly', 'monthly', 'quarterly', 'annual', 'pending', 'targets'];
    dialog('Schedule a report', '<div class="ceo-form"><div class="two"><label>Report<select id="s-k">' + kinds.map(function (k) { return '<option value="' + k + '">' + esc(d.kinds[k]) + '</option>'; }).join('') + '</select></label>'
      + '<label>How often<select id="s-f"><option value="daily">Every day</option><option value="weekly" selected>Every Monday</option><option value="monthly">First of each month</option><option value="quarterly">Each quarter</option></select></label></div>'
      + '<label>Hub or spoke<select id="s-s"><option value="">' + (S.boot.access.limited ? 'All my spokes' : 'All') + '</option>' + S.boot.spokes.map(function (s) { return '<option value="' + esc(s.key) + '">' + esc(s.name) + '</option>'; }).join('') + '</select></label>'
      + '<fieldset class="ceo-fieldset"><legend>Recipients (people authorised for reports)</legend><div class="ceo-checks">' + d.recipients.map(function (r) { return '<label><input type="checkbox" value="' + r.id + '" class="s-r"><span>' + esc(r.name) + '<small>' + esc(r.email || 'No email on file') + '</small></span></label>'; }).join('') + '</div></fieldset>'
      + '<p class="ceo-sub">Each recipient is checked again when it sends, and receives only what their own access allows.</p></div>',
      [['Cancel', null], ['Save schedule', function () {
        var r = $$('.s-r:checked').map(function (c) { return +c.value; });
        return post('schedule_save', { kind: $('#s-k').value, frequency: $('#s-f').value, spoke: $('#s-s').value, recipients: r }).then(function () { toast('Schedule saved.'); route(true); });
      }, 'pri']]);
  }

  /* ---- access */
  VIEWS.access = function (fresh) {
    var v = $('#ceo-view');
    if (fresh) { v.innerHTML = '<div class="ceo-sk" style="height:420px"></div>'; }
    return api('access').then(function (d) { S.view.acc = d; accessRender(); });
  };
  function accessRender() {
    var d = S.view.acc, v = $('#ceo-view'), rows = d.rows;
    var q = (S.view.accQ || '').toLowerCase(), showOld = S.view.accOld;
    var shown = rows.filter(function (r) { return (showOld || r.status === 'active') && (!q || [r.name, r.emp_code, r.position, r.department, r.profile].join(' ').toLowerCase().indexOf(q) >= 0); });
    var act = rows.filter(function (r) { return r.status === 'active'; });
    var yes = function (b) { return b ? '<span class="ceo-tick" aria-label="Yes">✓</span>' : '<span class="ceo-no" aria-label="No">—</span>'; };
    var tags = function (l, cls) { return l.length > 3 ? l.slice(0, 3).map(function (x) { return '<span class="tag ' + (cls || '') + '">' + esc(x) + '</span>'; }).join('') + '<span class="tag" title="' + esc(l.join(', ')) + '">+' + (l.length - 3) + '</span>' : l.map(function (x) { return '<span class="tag ' + (cls || '') + '">' + esc(x) + '</span>'; }).join(''); };
    strip2([[act.filter(function (r) { return r.subject === 'person'; }).length, 'People with access'], [act.filter(function (r) { return r.subject === 'position'; }).length, 'Positions with access'], [d.profiles.length, 'Access profiles'], [act.filter(function (r) { return r.sensitive.length; }).length, 'Hold sensitive access']]);
    v.innerHTML = '<section class="ceo-card" style="margin-bottom:14px"><div class="ceo-card-h"><div><h2>Who has access</h2><p>Nobody has the dashboard by belonging to the organisation. Changes apply on their next refresh.</p></div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="ceo-btn pri" id="g-p">Grant to a person</button><button class="ceo-btn" id="g-o">Grant to a position</button></div></div>'
      + '<div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap"><span class="ceo-form" style="flex:1 1 240px"><input type="search" id="a-q" placeholder="Search name, employee ID, position, department" aria-label="Search" value="' + esc(S.view.accQ || '') + '"></span><label class="ceo-f"><input type="checkbox" id="a-o"' + (showOld ? ' checked' : '') + '> Show revoked</label></div>'
      + (shown.length ? '<div class="ceo-scroll"><table class="ceo-table"><thead><tr><th>User</th><th>Employee ID</th><th>Job position</th><th>Department</th><th>Dashboard role</th><th>Modules</th><th>Spokes</th><th>View</th><th>Export</th><th>Reports</th><th>Configure</th><th>Status</th><th>Granted by</th><th>Granted</th><th></th></tr></thead><tbody>'
        + shown.map(function (r) {
          return '<tr><td><strong>' + esc(r.name) + '</strong>' + (r.subject === 'position' ? '<br><span class="ceo-sub">Position · ' + (r.holders || 0) + ' people</span>' : '') + '</td><td>' + esc(r.emp_code || '—') + '</td><td>' + esc(r.position || '—') + '</td><td>' + esc(r.department || '—') + '</td><td>' + esc(r.profile) + (r.sensitive.length ? '<br>' + tags(r.sensitive, 's') : '') + '</td><td>' + tags(r.modules) + '</td><td>' + tags(r.spokes) + '</td>'
            + '<td>' + yes(r.view) + '</td><td>' + yes(r.export) + '</td><td>' + yes(r.report) + '</td><td>' + yes(r.configure) + '</td><td><span class="ceo-status ' + (r.status === 'active' ? 'ok' : '') + '">' + esc(r.status === 'active' ? 'Active' : 'Revoked') + '</span></td><td>' + esc(r.granted_by) + '</td><td style="white-space:nowrap">' + esc(String(r.granted_at).slice(0, 16)) + '</td>'
            + '<td style="white-space:nowrap">' + (r.status === 'active' ? '<button class="ceo-btn sm dng" data-rv="' + r.id + '">Revoke</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table></div>' : emptyBox('No one has been given access yet', 'Grant it to a person or a position above.')) + '</section>'
      + '<div class="ceo-grid"><section class="ceo-card ceo-span-7"><div class="ceo-card-h"><div><h2>Access profiles</h2><p>What a role can see. Editing one applies to everyone holding it.</p></div><button class="ceo-btn" id="p-new">New profile</button></div>'
      + d.profiles.map(function (p) {
        var s = p.scope;
        return '<div class="ceo-item" style="grid-template-columns:1fr auto"><span><div class="ti">' + esc(p.name) + '</div><div class="me">' + esc(p.description || '') + '</div><div class="me">' + s.modules.length + ' modules · ' + (s.spokes === '*' ? 'all spokes' : s.spokes.length + ' spokes') + ' · ' + (s.sensitive.length ? s.sensitive.length + ' sensitive' : 'nothing sensitive') + ' · ' + s.actions.length + ' actions</div></span>'
          + '<span style="display:flex;gap:6px"><button class="ceo-btn sm" data-pe="' + p.id + '">Edit</button><button class="ceo-btn sm dng" data-pd="' + p.id + '">Delete</button></span></div>';
      }).join('') + '</section>'
      + '<section class="ceo-card ceo-span-5"><div class="ceo-card-h"><div><h2>Your own access</h2><p>You run this screen and see every module, but sensitive data only through a profile that includes it. Choosing one here is recorded in the audit log.</p></div></div>'
      + '<div class="ceo-form"><label>Your profile<select id="self-p"><option value="0">None: no sensitive data</option>' + d.profiles.map(function (p) { return '<option value="' + p.id + '"' + (p.id === d.self_profile ? ' selected' : '') + '>' + esc(p.name) + (p.scope.sensitive.length ? ' (' + p.scope.sensitive.length + ' sensitive)' : '') + '</option>'; }).join('') + '</select></label></div></section></div>';
    $('#g-p').onclick = grantPerson;
    $('#g-o').onclick = grantPosition;
    $('#p-new').onclick = function () { profileDialog(null); };
    var aq = $('#a-q'); aq.oninput = function () { S.view.accQ = aq.value; var pos = aq.selectionStart; accessRender(); var n = $('#a-q'); n.focus(); n.setSelectionRange(pos, pos); };
    $('#a-o').onchange = function () { S.view.accOld = this.checked; accessRender(); };
    $$('[data-rv]').forEach(function (b) {
      b.onclick = function () {
        var r = rows.filter(function (x) { return x.id === +b.dataset.rv; })[0];
        dialog('Revoke access', '<p style="margin:0">' + esc(r.name) + (r.subject === 'position' ? ' and the ' + (r.holders || 0) + ' people in it' : '') + ' will lose the dashboard on their next refresh.</p>', [['Cancel', null], ['Revoke', function () { return post('revoke', { id: r.id }).then(function (j) { d.rows = j.rows; toast('Access revoked.'); accessRender(); }); }, 'pri']]);
      };
    });
    $$('[data-pe]').forEach(function (b) { b.onclick = function () { profileDialog(d.profiles.filter(function (p) { return p.id === +b.dataset.pe; })[0]); }; });
    $$('[data-pd]').forEach(function (b) { b.onclick = function () { post('profile_delete', { id: +b.dataset.pd }).then(function (j) { d.profiles = j.profiles; toast('Profile deleted.'); accessRender(); }).catch(function (e) { toast(e.message, true); }); }; });
    $('#self-p').onchange = function () {
      var id = +this.value;
      var done = function () { toast('Your access was updated.'); route(true); };
      var mine = rows.filter(function (r) { return r.subject === 'person' && r.person_id === d.self_id && r.status === 'active'; })[0];
      if (!id) { if (mine) { post('revoke', { id: mine.id }).then(done).catch(function (e) { toast(e.message, true); }); } return; }
      post('grant', { person_id: d.self_id, profile_id: id }).then(done).catch(function (e) { toast(e.message, true); });
    };
  }
  function strip2(items) {
    var box = $('#ceo-strip'); if (!box) { return; }
    box.innerHTML = '<div class="ceo-strip">' + items.map(function (x) { return '<div><b>' + num(x[0]) + '</b><span>' + esc(x[1]) + '</span></div>'; }).join('') + '</div>';
  }
  function profileOptions() { return S.view.acc.profiles.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + (p.scope.sensitive.length ? ' (includes sensitive data)' : '') + '</option>'; }).join(''); }
  function grantPerson() {
    var chosen = null, timer;
    var dlg = dialog('Grant to a person', '<div class="ceo-form"><label>Find a CRM user<input type="search" id="gp-q" placeholder="Name, email, employee ID or position" autocomplete="off"></label><div class="ceo-suggest" id="gp-r" hidden></div><label>Access profile<select id="gp-p">' + profileOptions() + '</select></label></div>',
      [['Cancel', null], ['Grant access', function () {
        if (!chosen) { throw new Error('Choose a person first.'); }
        return post('grant', { person_id: chosen, profile_id: +$('#gp-p').value }).then(function (j) { S.view.acc.rows = j.rows; toast('Access granted.'); accessRender(); });
      }, 'pri']]);
    var q = $('#gp-q', dlg), r = $('#gp-r', dlg);
    var search = function () {
      api('people', { q: q.value }).then(function (j) {
        r.hidden = false;
        r.innerHTML = j.people.length ? j.people.map(function (p) { return '<button type="button" data-id="' + p.id + '"' + (+p.id === chosen ? ' class="on"' : '') + '>' + esc(p.name) + '<small>' + esc([p.emp_code, p.position, p.department, p.email].filter(Boolean).join(' · ')) + '</small></button>'; }).join('') : '<div class="ceo-empty">No one matches.</div>';
        $$('button', r).forEach(function (b) { b.onclick = function () { chosen = +b.dataset.id; $$('button', r).forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
      }).catch(function (e) { toast(e.message, true); });
    };
    q.oninput = function () { clearTimeout(timer); timer = setTimeout(search, 220); };
    search();
  }
  function grantPosition() {
    var d = S.view.acc;
    if (!d.positions.length) { dialog('Grant to a position', '<p style="margin:0">No positions are set up in HR for this organisation. Positions come from HR designations.</p>', [['Close', null]]); return; }
    var dlg = dialog('Grant to a position', '<div class="ceo-form"><label>Job position (HR designation)<select id="go-n">' + d.positions.map(function (p) { return '<option value="' + esc(p.name) + '">' + esc(p.name) + ' (' + p.holders + ')</option>'; }).join('') + '</select></label><label>Access profile<select id="go-p">' + profileOptions() + '</select></label><div id="go-list"></div></div>',
      [['Cancel', null], ['Confirm access for these people', function () {
        return post('grant', { position: $('#go-n').value, profile_id: +$('#go-p').value, confirm: S.view.posHash }).then(function (j) { S.view.acc.rows = j.rows; toast('Access granted to the position.'); accessRender(); }).catch(function (e) {
          if (e.status === 409) { load(); }
          throw e;
        });
      }, 'pri']]);
    var load = function () {
      api('position', { name: $('#go-n').value }).then(function (j) {
        S.view.posHash = j.confirm;
        var ok = j.people.filter(function (p) { return p.can_sign_in; });
        $('#go-list', dlg).innerHTML = '<fieldset class="ceo-fieldset warn"><legend>This reaches ' + ok.length + ' ' + (ok.length === 1 ? 'person' : 'people') + ' now</legend>'
          + (j.people.length ? j.people.map(function (p) { return '<div style="font-size:13px;padding:3px 0">' + esc(p.full_name) + ' <span class="ceo-sub">' + esc([p.emp_code, p.department].filter(Boolean).join(' · ')) + (p.can_sign_in ? '' : ' · no CRM login, gets nothing') + '</span></div>'; }).join('') : '<div style="font-size:13px">Nobody holds this position yet.</div>')
          + '<p class="ceo-sub">Anyone who later takes this position gets the same access, and anyone who leaves it loses it.</p></fieldset>';
      }).catch(function (e) { toast(e.message, true); });
    };
    $('#go-n', dlg).onchange = load; load();
  }
  function profileDialog(p) {
    var d = S.view.acc, s = p ? p.scope : { modules: [], spokes: '*', kpis: '*', sensitive: [], actions: ['view'] };
    var mods = Object.keys(d.modules);
    var chk = function (name, val, on, label, sub) { return '<label><input type="checkbox" name="' + name + '" value="' + esc(val) + '"' + (on ? ' checked' : '') + (val === 'view' ? ' disabled checked' : '') + '><span>' + esc(label) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span></label>'; };
    var dlg = dialog(p ? 'Edit profile' : 'New access profile', '<div class="ceo-form"><div class="two"><label>Name<input type="text" id="pf-n" maxlength="120" value="' + esc(p ? p.name : '') + '"></label><label>Description<input type="text" id="pf-d" maxlength="255" value="' + esc(p ? p.description || '' : '') + '"></label></div>'
      + '<fieldset class="ceo-fieldset"><legend>Modules (departments)</legend><div class="ceo-checks">' + mods.map(function (m) { return chk('m', m, s.modules.indexOf(m) >= 0, d.modules[m].label); }).join('') + '</div></fieldset>'
      + '<fieldset class="ceo-fieldset"><legend>Hub and spokes</legend><div class="ceo-checks"><label><input type="checkbox" id="pf-all"' + (s.spokes === '*' ? ' checked' : '') + '><span>All, and organisation-wide figures</span></label>' + d.spokes.map(function (x) { return chk('s', x.key, s.spokes === '*' || s.spokes.indexOf(x.key) >= 0, x.name); }).join('') + '</div><p class="ceo-sub">Limited to spokes: organisation-wide figures such as IT and Finance are hidden, because they cannot be cut by spoke.</p></fieldset>'
      + '<fieldset class="ceo-fieldset"><legend>Permitted actions</legend><div class="ceo-checks">' + Object.keys(d.actions).map(function (a) { return chk('a', a, s.actions.indexOf(a) >= 0, d.actions[a]); }).join('') + '</div></fieldset>'
      + '<fieldset class="ceo-fieldset warn"><legend>Sensitive data: explicit authorisation</legend><div class="ceo-checks">' + Object.keys(d.sensitive).map(function (k) { return chk('x', k, s.sensitive.indexOf(k) >= 0, d.sensitive[k][0], d.sensitive[k][1]); }).join('') + '</div></fieldset></div>',
      [['Cancel', null], ['Save profile', function () {
        var pick = function (n) { return $$('input[name="' + n + '"]:checked', dlg).map(function (c) { return c.value; }); };
        var scope = { modules: pick('m'), spokes: $('#pf-all', dlg).checked ? '*' : pick('s'), kpis: s.kpis, sensitive: pick('x'), actions: pick('a') };
        return post('profile_save', { id: p ? p.id : 0, name: $('#pf-n', dlg).value, description: $('#pf-d', dlg).value, scope: scope }).then(function (j) { d.profiles = j.profiles; d.rows = j.rows; toast('Profile saved.'); accessRender(); });
      }, 'pri']]);
    var all = $('#pf-all', dlg);
    var syncAll = function () { $$('input[name="s"]', dlg).forEach(function (c) { c.disabled = all.checked; }); };
    all.onchange = syncAll; syncAll();
  }

  /* ---- registry, layout, audit */
  VIEWS.setup = function (fresh) {
    var v = $('#ceo-view');
    if (fresh) { v.innerHTML = '<div class="ceo-sk" style="height:420px"></div>'; }
    return Promise.all([api('registry'), api('audit')]).then(function (r) {
      var d = r[0], audit = r[1].rows;
      var by = {}; d.kpis.forEach(function (k) { by[k.key] = k; });
      S.view.layout = d.cards.filter(function (k) { return by[k]; });
      strip2([[d.kpis.length, 'KPIs defined'], [d.kpis.filter(function (k) { return k.spoke; }).length, 'Recorded by spoke'], [d.kpis.filter(function (k) { return k.sensitive; }).length, 'Sensitive'], [d.coverage.filter(function (c) { return c.state === 'ok'; }).length, 'Modules connected']]);
      var ACT = { access_granted: 'Access granted', access_changed: 'Access changed', access_revoked: 'Access revoked', profile_saved: 'Profile saved', profile_deleted: 'Profile deleted', target_saved: 'Target saved', target_removed: 'Target removed', schedule_saved: 'Schedule saved', schedule_removed: 'Schedule removed', report_download: 'Report downloaded', report_emailed: 'Report emailed', layout_saved: 'Layout saved' };
      v.innerHTML = '<div class="ceo-grid"><section class="ceo-card ceo-span-5"><div class="ceo-card-h"><div><h2>KPI cards</h2><p>Drag to reorder, or use the arrows. Untick to hide.</p></div></div><div class="ceo-sort-list" id="ly"></div>'
        + '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap"><button class="ceo-btn pri" id="ly-s">Save my layout</button>' + (S.boot.access.owner ? '<button class="ceo-btn" id="ly-o">Make organisation default</button>' : '') + '</div></section>'
        + '<section class="ceo-card ceo-span-7"><div class="ceo-card-h"><div><h2>Data coverage</h2><p>What each module can and cannot report</p></div></div>' + covList(d.coverage) + '</section></div>'
        + '<section class="ceo-card" style="margin-bottom:14px"><div class="ceo-card-h"><div><h2>KPI registry</h2><p>The one definition behind every card, chart, report and export</p></div><span class="ceo-form" style="width:240px"><input type="search" id="k-q" placeholder="Search KPIs" aria-label="Search KPIs"></span></div><div class="ceo-scroll" style="max-height:480px"><table class="ceo-table"><thead><tr><th>KPI</th><th>Module</th><th>Calculation</th><th>Source</th><th>Type</th><th>By spoke</th><th>Sensitive</th><th class="num">Cached</th><th>Target periods</th></tr></thead><tbody id="k-b"></tbody></table></div></section>'
        + '<section class="ceo-card"><div class="ceo-card-h"><div><h2>Audit log</h2><p>Access, configuration and report downloads. Last 200, kept three years.</p></div></div><div class="ceo-scroll" style="max-height:420px"><table class="ceo-table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Subject</th><th>From</th></tr></thead><tbody>'
        + (audit.map(function (a) { return '<tr><td style="white-space:nowrap">' + esc(a.created_at) + '</td><td>' + esc(a.actor) + '</td><td>' + esc(ACT[a.action] || a.action) + '</td><td>' + esc(a.subject) + '</td><td>' + esc(a.ip || '') + '</td></tr>'; }).join('') || '<tr><td colspan="5">Nothing recorded yet.</td></tr>') + '</tbody></table></div></section>';
      var kb = function (q) {
        $('#k-b').innerHTML = d.kpis.filter(function (k) { return !q || (k.label + k.module + k.calc).toLowerCase().indexOf(q) >= 0; }).map(function (k) {
          return '<tr><td><strong>' + esc(k.label) + '</strong></td><td>' + esc(k.module) + '</td><td style="min-width:260px">' + esc(k.calc) + '</td><td class="ceo-sub">' + esc(k.source) + '</td><td>' + (k.kind === 'flow' ? 'Over a period' : 'Reading now') + '</td><td>' + (k.spoke ? 'Yes' : 'No') + '</td><td>' + esc(k.sensitive || '—') + '</td><td class="num">' + (k.refresh >= 60 ? k.refresh / 60 + ' min' : k.refresh + 's') + '</td><td>' + esc((k.periods || []).join(', ')) + '</td></tr>';
        }).join('');
      };
      kb(''); $('#k-q').oninput = function () { kb(this.value.toLowerCase()); };
      var draw = function () {
        var all = S.view.layout.concat(d.kpis.map(function (k) { return k.key; }).filter(function (k) { return S.view.layout.indexOf(k) < 0; }));
        $('#ly').innerHTML = all.map(function (k, i) {
          var on = S.view.layout.indexOf(k) >= 0;
          return '<div class="ceo-sort" draggable="' + on + '" data-key="' + esc(k) + '"><span class="grip" aria-hidden="true">⠿</span><input type="checkbox" aria-label="Show ' + esc(by[k].label) + '"' + (on ? ' checked' : '') + '><span class="sp">' + esc(by[k].label) + ' <span class="ceo-sub">' + esc(by[k].module) + '</span></span>' + (on ? '<button type="button" data-mv="-1" aria-label="Move up">↑</button><button type="button" data-mv="1" aria-label="Move down">↓</button>' : '') + '</div>';
        }).join('');
        $$('.ceo-sort').forEach(function (row) {
          var k = row.dataset.key;
          $('input', row).onchange = function () { var at = S.view.layout.indexOf(k); if (at >= 0) { S.view.layout.splice(at, 1); } else { S.view.layout.push(k); } draw(); };
          $$('[data-mv]', row).forEach(function (b) { b.onclick = function () { var at = S.view.layout.indexOf(k), to = at + (+b.dataset.mv); if (to < 0 || to >= S.view.layout.length) { return; } S.view.layout.splice(at, 1); S.view.layout.splice(to, 0, k); draw(); }; });
          row.ondragstart = function (e) { e.dataTransfer.setData('text/plain', k); row.classList.add('drag'); };
          row.ondragend = function () { row.classList.remove('drag'); };
          row.ondragover = function (e) { e.preventDefault(); };
          row.ondrop = function (e) { e.preventDefault(); var from = S.view.layout.indexOf(e.dataTransfer.getData('text/plain')), to = S.view.layout.indexOf(k); if (from < 0 || to < 0) { return; } var x = S.view.layout.splice(from, 1)[0]; S.view.layout.splice(to, 0, x); draw(); };
        });
      };
      draw();
      var save = function (org) { post('prefs_save', { cards: S.view.layout, org_default: org }).then(function () { toast(org ? 'Saved as the organisation default.' : 'Your layout was saved.'); }).catch(function (e) { toast(e.message, true); }); };
      $('#ly-s').onclick = function () { save(false); };
      if ($('#ly-o')) { $('#ly-o').onclick = function () { save(true); }; }
    });
  };

  /* ------------------------------------------------------------ dialog */
  function dialog(title, body, buttons) {
    var d = document.createElement('dialog');
    d.className = 'ceo-dlg';
    d.innerHTML = '<div class="ceo-dlg-h"><h3>' + esc(title) + '</h3><button type="button" class="ceo-x" aria-label="Close">×</button></div><div class="ceo-dlg-b">' + body + '<div class="ceo-error" hidden style="margin-top:10px" role="alert"></div></div><div class="ceo-dlg-f"></div>';
    root.appendChild(d);
    var close = function () { d.close(); d.remove(); };
    $('.ceo-x', d).onclick = close;
    d.addEventListener('cancel', function () { setTimeout(function () { d.remove(); }, 0); });
    buttons.forEach(function (b) {
      var el = document.createElement('button');
      el.type = 'button'; el.className = 'ceo-btn ' + (b[2] || ''); el.textContent = b[0];
      el.onclick = function () {
        if (!b[1]) { close(); return; }
        var err = $('.ceo-error', d);
        err.hidden = true; el.disabled = true;
        var r;
        try { r = b[1](); } catch (e) { r = Promise.reject(e); }
        Promise.resolve(r).then(close).catch(function (e) { err.textContent = e.message; err.hidden = false; el.disabled = false; });
      };
      $('.ceo-dlg-f', d).appendChild(el);
    });
    d.showModal();
    return d;
  }

  /* ------------------------------------------------------------ start */
  var resizeT;
  window.addEventListener('resize', function () { clearTimeout(resizeT); resizeT = setTimeout(function () { var b = $('#ceo-trend'); if (b && S.data && S.data.trend) { trendChart(b, S.data.trend); } }, 200); });
  app.innerHTML = '<div class="ceo-sk" style="height:170px;border-radius:20px;margin-bottom:14px"></div>' + skeleton();
  var bootP = new URLSearchParams({ r: 'boot' });
  if (conf.admin) { bootP.set('as', 'admin'); bootP.set('org', conf.org); }
  fetch(conf.api + '?' + bootP.toString(), { credentials: 'same-origin' }).then(handle).then(function (b) {
    S.boot = b;
    app.removeAttribute('aria-busy');
    loadFilters();
    frame();
    route(true).finally(schedule);
  }).catch(function (e) {
    app.innerHTML = '<div class="ceo-card"><div class="ceo-empty"><b>The dashboard could not start</b>' + esc(e.message) + '</div></div>';
  });
})();
