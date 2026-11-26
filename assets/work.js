/* Work Management (lib/wm). Everything is read and written through api/work.php,
   which decides on every call what this person may see and do; nothing here is
   a permission, only a way of not offering what the server would refuse. */
(function () {
  'use strict';
  var conf = JSON.parse(document.getElementById('wm-conf').textContent);
  var main = document.getElementById('main');
  var app = document.getElementById('wm-app');
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var role = conf.role, isAdmin = role === 'admin', isClient = role === 'client';
  var S = { boot: null, tasks: [], f: {}, view: store('wm.view') || 'list', rep: null };
  var STATUS_COLORS = { 'To Do': '#8a93a3', 'In Progress': '#2a78d6', 'On Hold': '#eda100', 'Completed': '#1baf7a', 'Cancelled': '#c3c2b7' };
  var SWATCHES = ['#2a78d6', '#1baf7a', '#eb6834', '#eda100', '#e87ba4', '#4a3aa7', '#008300', '#e34948', '#0e7490', '#64748b'];

  /* ------------------------------------------------------------ helpers */
  function store(k, v) {
    try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) { return null; }
    return null;
  }
  function esc(v) { return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function dmy(s) {
    if (!s) { return ''; }
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? m[3] + '-' + MON[+m[2] - 1] + '-' + m[1] : String(s);
  }
  function dmyTime(s) { if (!s) { return ''; } var t = String(s).slice(11, 16); return dmy(s) + (t ? ' ' + t : ''); }
  function addDays(ymd, n) { var d = new Date(ymd + 'T00:00:00'); d.setDate(d.getDate() + n); return iso(d); }
  function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function initials(n) { var p = String(n || '?').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }
  function avColor(n) { var h = 0; String(n || '').split('').forEach(function (c) { h = (h * 31 + c.charCodeAt(0)) % 360; }); return 'hsl(' + h + ',45%,42%)'; }
  function avatar(n) { return n ? '<span class="wm-avatar" style="--av:' + avColor(n) + '" aria-hidden="true">' + esc(initials(n)) + '</span>' : ''; }
  function stKey(s) { return { 'To Do': 'todo', 'In Progress': 'progress', 'On Hold': 'hold', 'Completed': 'done', 'Cancelled': 'cancel' }[s] || 'none'; }
  function stPill(s) { return '<span class="wm-st s-' + stKey(s) + '">' + esc(s) + '</span>'; }
  function prPill(p) { return '<span class="wm-pr p-' + esc(String(p).toLowerCase()) + '"><i aria-hidden="true"><b></b><b></b><b></b><b></b></i>' + esc(p) + '</span>'; }
  function dept(id) { var d = null; (S.boot ? S.boot.departments : []).forEach(function (x) { if (x.id === id) { d = x; } }); return d; }
  function deptPill(id) { var d = dept(id); return d ? '<span class="wm-dept" style="--dc:' + esc(d.color) + '">' + esc(d.name) + '</span>' : ''; }
  function svg(name) {
    var p = {
      plus: '<path d="M12 5v14M5 12h14"/>', moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>', sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
      x: '<path d="M6 6l12 12M18 6L6 18"/>', check: '<path d="M5 12.5l4.5 4.5L19 7"/>', list: '<path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
      board: '<rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="10" y="4" width="5" height="11" rx="1.5"/><rect x="17" y="4" width="4" height="7" rx="1.5"/>',
      table: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>', folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
      task: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12l2.5 2.5 4.5-5"/>', users: '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6 6 0 0 1 3 5.5"/>',
      file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>', download: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
      copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>', alert: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'
    }[name] || '';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + p + '</svg>';
  }

  /* ------------------------------------------------------------ server */
  function url(route, q) {
    var u = conf.api + (conf.api.indexOf('?') >= 0 ? '&' : '?') + 'r=' + encodeURIComponent(route);
    Object.keys(q || {}).forEach(function (k) {
      var v = q[k];
      if (v === undefined || v === null || v === '') { return; }
      if (Array.isArray(v)) { v = v.join(','); if (!v) { return; } }
      u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
    });
    return u;
  }
  function handle(res) {
    return res.json().catch(function () { return { ok: false, error: 'The server answered in a way we could not read. Please try again.' }; }).then(function (j) {
      if (!res.ok || !j.ok) {
        var e = new Error(j.error || 'Something went wrong. Please try again.');
        e.code = res.status;
        if (res.status === 401) { setTimeout(function () { location.reload(); }, 1500); }
        throw e;
      }
      return j;
    });
  }
  function get(route, q) { return fetch(url(route, q), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(handle); }
  function post(route, payload) {
    var fd = new FormData();
    fd.append('csrf', conf.csrf);
    fd.append('payload', JSON.stringify(payload || {}));
    return fetch(url(route), { method: 'POST', credentials: 'same-origin', body: fd, headers: { Accept: 'application/json' } }).then(handle);
  }

  /* ------------------------------------------------------------ toast */
  function toast(msg, err) {
    var box = document.getElementById('wm-toasts');
    var t = document.createElement('div');
    t.className = 'wm-toast' + (err ? ' err' : '');
    t.innerHTML = svg(err ? 'alert' : 'check') + '<span>' + esc(msg) + '</span>';
    box.appendChild(t);
    while (box.children.length > 3) { box.removeChild(box.firstChild); }   // never a stack over the page
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, calm ? 0 : 200); }, err ? 5200 : 3200);
  }
  function fail(e) { toast(e && e.message ? e.message : 'Something went wrong.', true); }

  /* ------------------------------------------------------------ counters */
  function countUp(el) {
    var to = +el.getAttribute('data-to') || 0;
    if (calm || to === 0) { el.textContent = to.toLocaleString('en-IN'); return; }
    var t0 = null, dur = 650;
    function step(ts) {
      if (!t0) { t0 = ts; }
      var k = Math.min(1, (ts - t0) / dur), e = 1 - Math.pow(1 - k, 3);
      el.textContent = Math.round(to * e).toLocaleString('en-IN');
      if (k < 1) { requestAnimationFrame(step); }
    }
    requestAnimationFrame(step);
  }

  /* ------------------------------------------------------------ dialogs & panel */
  var layers = [];
  function trap(el) {
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); var top = layers[layers.length - 1]; if (top && top.el === el) { top.close(); } return; }
      if (e.key !== 'Tab') { return; }
      var f = $$('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])', el)
        .filter(function (x) { return x.offsetParent !== null; });
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }
  function layer(kind, html, label) {
    var back = document.activeElement;
    var scrim = document.createElement('div');
    scrim.className = 'wm-scrim';
    var el = document.createElement('div');
    el.className = kind === 'panel' ? 'wm-panel' : 'wm-dialog';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', label || 'Dialog');
    el.innerHTML = html;
    main.appendChild(scrim);
    main.appendChild(el);
    trap(el);
    var L = { el: el, closed: false, onclose: null, close: function () {
      if (L.closed) { return; }
      L.closed = true;
      layers.splice(layers.indexOf(L), 1);
      el.classList.add('out');
      scrim.classList.add('out');
      setTimeout(function () { el.remove(); scrim.remove(); }, calm ? 0 : 190);
      if (back && back.focus && document.contains(back)) { back.focus(); }
      if (L.onclose) { L.onclose(); }
    } };
    scrim.addEventListener('click', L.close);
    $$('[data-close]', el).forEach(function (b) { b.addEventListener('click', L.close); });
    layers.push(L);
    setTimeout(function () { var f = $('[autofocus]', el) || $('input:not([type=hidden]),select,textarea,button', el); if (f) { f.focus(); } }, 30);
    return L;
  }
  function dialog(title, sub, body, foot) {
    return layer('dialog', '<div class="wm-dialog-h"><h2>' + esc(title) + '</h2>' + (sub ? '<p>' + sub + '</p>' : '') + '</div>'
      + '<div class="wm-dialog-b">' + body + '</div>' + (foot ? '<div class="wm-dialog-f">' + foot + '</div>' : ''), title);
  }
  function confirmBox(title, text, ok, danger) {
    return new Promise(function (resolve) {
      var done = false;
      var d = dialog(title, esc(text), '', '<button type="button" class="wm-btn" data-close>Cancel</button><button type="button" class="wm-btn ' + (danger ? 'danger' : 'primary') + '" data-ok>' + esc(ok) + '</button>');
      d.onclose = function () { if (!done) { resolve(false); } };
      $('[data-ok]', d.el).addEventListener('click', function () { done = true; d.close(); resolve(true); });
    });
  }
  function busy(btn, on) { if (!btn) { return; } btn.disabled = on; if (on) { btn.dataset.l = btn.innerHTML; btn.textContent = 'Saving…'; } else if (btn.dataset.l) { btn.innerHTML = btn.dataset.l; } }

  /* ------------------------------------------------------------ theme */
  function theme(t) {
    t = t || store('wm.theme') || 'light';
    main.setAttribute('data-theme', t);
    store('wm.theme', t);
    var b = $('#wm-theme');
    if (b) { b.innerHTML = svg(t === 'dark' ? 'sun' : 'moon'); b.setAttribute('aria-label', t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'); b.title = b.getAttribute('aria-label'); }
  }

  /* ------------------------------------------------------------ shell */
  var TITLES = {
    overview: ['Overview', 'Where the work stands today, and what moved this week.'],
    tasks: [isClient ? 'Your tasks' : 'Tasks', isClient ? 'The work shared with you, read-only.' : 'Plan, assign and follow every task.'],
    departments: ['Departments', 'Group tasks by the team that owns them.'],
    access: ['Access', 'Give team members and clients their way in, and take it away.'],
    reports: ['Reports', 'Compare each task between two dates and download the Excel report.']
  };
  function shell(extra) {
    var t = TITLES[conf.page];
    var canNew = !isClient && conf.page !== 'access' && conf.page !== 'reports';
    return '<div class="wm-inner"><div class="wm-head"><div><div class="wm-eyebrow">Work Management</div><h1>' + esc(t[0]) + '</h1><p>' + esc(t[1]) + '</p></div>'
      + '<div class="wm-actions">' + (extra || '')
      + (canNew && conf.page !== 'departments' ? '<button type="button" class="wm-btn primary wm-newtask" data-newtask>' + svg('plus') + 'New task</button>' : '')
      + '<button type="button" class="wm-btn wm-icon-btn" id="wm-theme"></button></div></div><div id="wm-body" class="wm-view"></div></div>'
      + (!isClient ? '<button type="button" class="wm-fab" data-newtask aria-label="New task">' + svg('plus') + '</button>' : '');
  }
  function body() { return document.getElementById('wm-body'); }
  function empty(icon, title, text, action) {
    return '<div class="wm-empty"><div class="art">' + svg(icon) + '</div><h3>' + esc(title) + '</h3><p>' + esc(text) + '</p>' + (action || '') + '</div>';
  }
  function skel(kind) {
    if (kind === 'kpis') { var k = ''; for (var i = 0; i < 5; i++) { k += '<div class="wm-card wm-kpi"><div class="wm-sk" style="height:14px;width:60%"></div><div class="wm-sk" style="height:30px;width:40%;margin-top:10px"></div></div>'; } return '<div class="wm-kpis">' + k + '</div>'; }
    var r = '';
    for (var j = 0; j < 6; j++) { r += '<div style="padding:14px 16px;border-top:1px solid var(--w-line-2)"><div class="wm-sk" style="height:14px;width:' + (40 + (j * 9) % 45) + '%"></div><div class="wm-sk" style="height:10px;width:30%;margin-top:8px"></div></div>'; }
    return '<div class="wm-card">' + r + '</div>';
  }

  function boot() {
    return get('boot').then(function (b) { S.boot = b; return b; });
  }

  /* ================================================================ overview */
  function pageOverview() {
    var today = conf.today, d = new Date(today + 'T00:00:00');
    var monday = addDays(today, -((d.getDay() + 6) % 7));
    S.ov = S.ov || { dept: '', from: monday, to: today };
    var deptOpts = '<option value="">All departments</option>' + S.boot.departments.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + '</option>'; }).join('');
    body().innerHTML = '<div class="wm-filters"><label class="wm-field"><span>Department</span><select id="ov-dept">' + deptOpts + '</select></label>'
      + '<label class="wm-field"><span>From</span><input type="date" id="ov-from" value="' + S.ov.from + '"></label>'
      + '<label class="wm-field"><span>To</span><input type="date" id="ov-to" value="' + S.ov.to + '"></label></div><div id="ov-out">' + skel('kpis') + skel() + '</div>';
    $('#ov-dept').value = S.ov.dept;
    ['ov-dept', 'ov-from', 'ov-to'].forEach(function (id) { $('#' + id).addEventListener('change', function () { S.ov = { dept: $('#ov-dept').value, from: $('#ov-from').value, to: $('#ov-to').value }; loadOverview(); }); });
    loadOverview();
  }
  function loadOverview() {
    get('overview', S.ov).then(function (o) {
      var out = $('#ov-out');
      if (!o.total && !S.ov.dept) {
        out.innerHTML = '<div class="wm-card">' + (S.boot.departments.length
          ? empty('task', 'No tasks yet', 'Create your first task and it will show up here, with its progress and due date.', '<button type="button" class="wm-btn primary" data-newtask>' + svg('plus') + 'Create your first task</button>')
          : empty('folder', 'No departments yet', isAdmin ? 'Create your first department, then add tasks to it.' : 'The administrator has not given you any departments yet.',
            isAdmin ? '<a class="wm-btn primary" href="work.php?p=departments">' + svg('plus') + 'Create your first department</a>' : '')) + '</div>';
        return;
      }
      var K = [['open', 'Open', 'To do, in progress or on hold', '#2a78d6'], ['progress', 'In Progress', 'Being worked on', '#4a3aa7'], ['due_today', 'Due today', 'Still open', '#eda100'],
        ['overdue', 'Overdue', 'Past their due date', '#b3261e'], ['done_week', 'Completed this week', 'Since Monday', '#1baf7a']];
      var kp = '<div class="wm-kpis wm-stagger">' + K.map(function (k) {
        return '<div class="wm-card wm-kpi" style="--k:' + k[3] + '"><div class="lab">' + esc(k[1]) + '</div><div class="val" data-to="' + (o.kpis[k[0]] || 0) + '">0</div><div class="hint">' + esc(k[2]) + '</div></div>';
      }).join('') + '</div>';
      var maxD = Math.max.apply(null, [1].concat(o.per_dept.map(function (x) { return +x.n; })));
      var perDept = o.per_dept.length ? o.per_dept.map(function (x, i) {
        return '<div class="wm-hbar"><span class="nm">' + esc(x.name) + '</span><span class="tr"><i style="width:' + Math.max(4, Math.round(100 * x.n / maxD)) + '%;background:' + esc(x.color) + ';animation-delay:' + (i * 50) + 'ms"></i></span><b>' + x.n + '</b></div>';
      }).join('') : '<p style="color:var(--w-mute);margin:0">No open tasks.</p>';
      out.innerHTML = kp + '<div class="wm-grid split wm-stagger">'
        + '<div class="wm-card"><div class="wm-card-h"><h2>Status changes</h2><small>' + dmy(o.from) + ' – ' + dmy(o.to) + '</small></div><div class="wm-card-b">' + chart(o) + '</div></div>'
        + '<div class="wm-card"><div class="wm-card-h"><h2>Open tasks by department</h2></div><div class="wm-card-b">' + perDept + '</div></div></div>'
        + '<div class="wm-grid two wm-stagger" style="margin-top:16px">'
        + '<div class="wm-card"><div class="wm-card-h"><h2>Overdue</h2><small>' + o.overdue.length + (o.overdue.length >= 8 ? '+' : '') + '</small></div><div class="wm-card-b">' + mini(o.overdue, 'Nothing is overdue. Nicely done.') + '</div></div>'
        + '<div class="wm-card"><div class="wm-card-h"><h2>Upcoming</h2><small>Next 14 days</small></div><div class="wm-card-b">' + mini(o.upcoming, 'Nothing due in the next two weeks.') + '</div></div></div>';
      $$('.val[data-to]', out).forEach(countUp);
      growBars(out);
      $$('[data-task]', out).forEach(function (b) { b.addEventListener('click', function () { openTask(+b.getAttribute('data-task')); }); });
    }).catch(function (e) { $('#ov-out').innerHTML = '<div class="wm-card">' + empty('alert', 'Could not load the overview', e.message) + '</div>'; });
  }
  /* Bars rise from the baseline: their height and top are animated directly, which SVG
     renders the same everywhere (CSS transforms on SVG rects do not). */
  function growBars(root) {
    if (calm) { return; }
    var bars = $$('.wm-chart rect.wm-cbar', root).map(function (r) {
      var b = { r: r, y: +r.getAttribute('y'), h: +r.getAttribute('height'), d: +r.getAttribute('data-d') || 0 };
      b.base = b.y + b.h;
      return b;
    });
    var stacks = {};
    bars.forEach(function (b) { var x = b.r.getAttribute('x'); stacks[x] = Math.max(stacks[x] || 0, b.base); b.floor = 0; });
    bars.forEach(function (b) { b.floor = stacks[b.r.getAttribute('x')]; b.r.setAttribute('y', b.floor); b.r.setAttribute('height', 0); });
    var t0 = null;
    function step(ts) {
      if (!t0) { t0 = ts; }
      var running = false;
      bars.forEach(function (b) {
        var k = Math.min(1, Math.max(0, (ts - t0 - b.d) / 600)), e = 1 - Math.pow(1 - k, 3);
        if (k < 1) { running = true; }
        b.r.setAttribute('y', (b.floor - (b.floor - b.y) * e).toFixed(1));
        b.r.setAttribute('height', (b.h * e).toFixed(1));
      });
      if (running) { requestAnimationFrame(step); }
    }
    requestAnimationFrame(step);
  }
  function mini(list, none) {
    if (!list.length) { return '<p style="color:var(--w-mute);margin:0">' + esc(none) + '</p>'; }
    return '<div class="wm-mini">' + list.map(function (t) {
      return '<button type="button" data-task="' + t.id + '"><span><span class="t">' + esc(t.title) + '</span><br><small>' + esc(t.code) + ' · ' + esc(t.assignee || 'Unassigned') + ' · ' + (deptPill(t.department_id)) + '</small></span>'
        + '<span style="text-align:right"><span class="' + (t.overdue ? 'wm-od' : '') + '" style="font-size:12.5px">' + dmy(t.due_date) + '</span><br>' + stPill(t.status) + '</span></button>';
    }).join('') + '</div>';
  }
  function chart(o) {
    var days = [], d = o.from;
    while (d <= o.to && days.length < 400) { days.push(d); d = addDays(d, 1); }
    var byWeek = days.length > 31, buckets = {}, order = [];
    days.forEach(function (x) {
      var k = x;
      if (byWeek) { var dt = new Date(x + 'T00:00:00'); k = addDays(x, -((dt.getDay() + 6) % 7)); }
      if (!buckets[k]) { buckets[k] = {}; order.push(k); }
    });
    o.changes.forEach(function (c) {
      var k = c.d;
      if (byWeek) { var dt = new Date(c.d + 'T00:00:00'); k = addDays(c.d, -((dt.getDay() + 6) % 7)); }
      if (buckets[k]) { buckets[k][c.s] = (buckets[k][c.s] || 0) + (+c.n); }
    });
    var top = 1;
    order.forEach(function (k) { var s = 0; Object.keys(buckets[k]).forEach(function (st) { s += buckets[k][st]; }); top = Math.max(top, s); });
    var step = Math.max(1, Math.ceil(top / 4)), max = step * 4;          // whole-number ticks
    var W = 640, H = 220, padL = 28, padB = 26, n = order.length, gap = n > 20 ? 3 : 10, bw = Math.max(4, (W - padL - gap * n) / n);
    var g = '';
    for (var i = 0; i <= 4; i++) { var y = H - padB - (H - padB - 10) * i / 4; g += '<line class="gl" x1="' + padL + '" x2="' + W + '" y1="' + y + '" y2="' + y + '"/>' + '<text x="' + (padL - 6) + '" y="' + (y + 4) + '" text-anchor="end">' + step * i + '</text>'; }
    var total = 0;
    order.forEach(function (k, idx) {
      var x = padL + gap / 2 + idx * (bw + gap), base = H - padB;
      conf.statuses.forEach(function (st) {
        var v = buckets[k][st] || 0;
        if (!v) { return; }
        total += v;
        var h = (H - padB - 10) * v / max;
        g += '<rect class="wm-cbar" data-d="' + (idx * 30) + '" x="' + x.toFixed(1) + '" y="' + (base - h).toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="3" fill="' + STATUS_COLORS[st] + '"><title>' + esc(dmy(k) + ': ' + v + ' → ' + st) + '</title></rect>';
        base -= h;
      });
      if (n <= 14 || idx % Math.ceil(n / 10) === 0) { g += '<text x="' + (x + bw / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle">' + esc(dmy(k).slice(0, 6)) + '</text>'; }
    });
    var legend = '<div class="wm-legend">' + conf.statuses.map(function (s) { return '<span><i style="background:' + STATUS_COLORS[s] + '"></i>' + esc(s) + '</span>'; }).join('') + '</div>';
    return '<svg class="wm-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + total + ' status changes' + (byWeek ? ' by week' : ' by day') + '">' + g + '</svg>' + legend
      + (total ? '' : '<p style="color:var(--w-mute);margin:8px 0 0;font-size:13px">No status changes in this period.</p>');
  }

  /* ================================================================ tasks */
  function pageTasks() {
    S.f = JSON.parse(store('wm.f') || '{}') || {};
    var team = S.boot.team || [];
    var opt = function (v, l) { return '<option value="' + esc(v) + '">' + esc(l) + '</option>'; };
    body().innerHTML = '<div class="wm-filters" role="search">'
      + '<label class="wm-field grow"><span>Search</span><input type="search" id="f-q" placeholder="Title, description or task ID" autocomplete="off"></label>'
      + '<label class="wm-field"><span>Department</span><select id="f-dept">' + opt('', 'All') + S.boot.departments.map(function (d) { return opt(d.id, d.name); }).join('') + '</select></label>'
      + '<label class="wm-field"><span>Status</span><select id="f-status">' + opt('', 'All') + conf.statuses.map(function (s) { return opt(s, s); }).join('') + '</select></label>'
      + '<label class="wm-field"><span>Priority</span><select id="f-priority">' + opt('', 'All') + conf.priorities.map(function (s) { return opt(s, s); }).join('') + '</select></label>'
      + (isClient ? '' : '<label class="wm-field"><span>Assignee</span><select id="f-assignee">' + opt('', 'Anyone') + opt('none', 'Unassigned') + team.map(function (p) { return opt(p.id, p.name); }).join('') + '</select></label>')
      + '<label class="wm-field"><span>Due</span><select id="f-due">' + opt('', 'Any time') + opt('overdue', 'Overdue') + opt('today', 'Today') + opt('week', 'Next 7 days') + opt('none', 'No due date') + '</select></label>'
      + '<div class="wm-field"><span class="wm-label" id="viewlab">View</span><div class="wm-seg" role="group" aria-labelledby="viewlab">'
      + [['list', 'List'], ['board', 'Board'], ['table', 'Table']].map(function (v) { return '<button type="button" data-view="' + v[0] + '" aria-pressed="' + (S.view === v[0]) + '">' + svg(v[0]) .replace('<svg', '<svg width="14" height="14" style="vertical-align:-2px;margin-right:4px;stroke:currentColor;fill:none;stroke-width:2"') + v[1] + '</button>'; }).join('')
      + '</div></div></div><div id="t-out">' + skel() + '</div>';
    ['q', 'dept', 'status', 'priority', 'assignee', 'due'].forEach(function (k) {
      var el = $('#f-' + k);
      if (!el) { return; }
      if (S.f[k] !== undefined) { el.value = S.f[k]; }
      var t = null;
      el.addEventListener(k === 'q' ? 'input' : 'change', function () {
        S.f[k] = el.value;
        store('wm.f', JSON.stringify(S.f));
        clearTimeout(t);
        t = setTimeout(loadTasks, k === 'q' ? 250 : 0);
      });
    });
    $$('[data-view]').forEach(function (b) {
      b.addEventListener('click', function () {
        S.view = b.getAttribute('data-view'); store('wm.view', S.view);
        $$('[data-view]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        renderTasks();
      });
    });
    loadTasks();
  }
  function loadTasks() {
    return get('tasks', S.f).then(function (j) { S.tasks = j.tasks; renderTasks(); })
      .catch(function (e) { $('#t-out').innerHTML = '<div class="wm-card">' + empty('alert', 'Could not load tasks', e.message) + '</div>'; });
  }
  function filtered() { return Object.keys(S.f).some(function (k) { return S.f[k]; }); }
  function renderTasks(flipFrom) {
    var out = $('#t-out');
    if (!out) { return; }
    if (!S.tasks.length) {
      if (filtered()) {
        out.innerHTML = '<div class="wm-card">' + empty('task', 'No tasks match', 'Try a different search or clear the filters.', '<button type="button" class="wm-btn" id="clearf">Clear filters</button>') + '</div>';
        $('#clearf').addEventListener('click', function () { S.f = {}; store('wm.f', '{}'); pageTasks(); });
      } else if (!S.boot.departments.length) {
        out.innerHTML = '<div class="wm-card">' + empty('folder', 'No departments yet', isAdmin ? 'Tasks belong to a department. Create your first department to get started.' : (isClient ? 'Nothing has been shared with you yet.' : 'The administrator has not given you any departments yet.'),
          isAdmin ? '<a class="wm-btn primary" href="work.php?p=departments">' + svg('plus') + 'Create your first department</a>' : '') + '</div>';
      } else {
        out.innerHTML = '<div class="wm-card">' + empty('task', isClient ? 'Nothing shared yet' : 'No tasks yet', isClient ? 'Tasks shared with you will appear here.' : 'Create your first task. It takes a few seconds.',
          isClient ? '' : '<button type="button" class="wm-btn primary" data-newtask>' + svg('plus') + 'Create your first task</button>') + '</div>';
      }
      return;
    }
    if (S.view === 'board') { out.innerHTML = board(); wireBoard(out, flipFrom); }
    else if (S.view === 'table') { out.innerHTML = table(); }
    else { out.innerHTML = list(); }
    $$('[data-task]', out).forEach(function (b) {
      b.addEventListener('click', function (e) { if (b.dataset.dragged) { delete b.dataset.dragged; e.preventDefault(); return; } openTask(+b.getAttribute('data-task')); });
    });
    $$('tr[data-task]', out).forEach(function (r) { r.addEventListener('keydown', function (e) { if (e.key === 'Enter') { openTask(+r.getAttribute('data-task')); } }); });
  }
  function due(t) { return t.due_date ? '<span class="' + (t.overdue ? 'wm-od' : '') + '">' + (t.overdue ? 'Overdue · ' : 'Due ') + dmy(t.due_date) + '</span>' : ''; }
  function list() {
    return '<div class="wm-card"><div class="wm-list">' + S.tasks.map(function (t) {
      var done = t.status === 'Completed';
      return '<button type="button" class="wm-row" data-task="' + t.id + '"><span class="wm-check' + (done ? ' on' : '') + '" aria-hidden="true">' + (done ? svg('check') : '') + '</span>'
        + '<span style="min-width:0"><span class="t">' + esc(t.title) + '</span><span class="m"><span class="wm-code">' + esc(t.code) + '</span>' + deptPill(t.department_id) + due(t)
        + (t.assignee ? '<span style="display:inline-flex;gap:6px;align-items:center">' + avatar(t.assignee) + esc(t.assignee) + '</span>' : '') + '</span></span>'
        + '<span class="r">' + prPill(t.priority) + '<span style="width:80px" class="wm-bar' + (done ? ' done' : '') + '" title="' + t.progress + '% done"><i style="width:' + t.progress + '%"></i></span>' + stPill(t.status) + '</span></button>';
    }).join('') + '</div></div>';
  }
  function table() {
    return '<div class="wm-card"><div class="wm-tablewrap"><table class="wm-table"><thead><tr><th scope="col">ID</th><th scope="col">Task</th><th scope="col">Department</th><th scope="col">Assigned to</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">Progress</th><th scope="col">Start</th><th scope="col">Due</th></tr></thead><tbody>'
      + S.tasks.map(function (t) {
        return '<tr data-task="' + t.id + '" tabindex="0"><td class="nowrap wm-code">' + esc(t.code) + '</td><td class="wrap"><b>' + esc(t.title) + '</b></td><td class="nowrap">' + deptPill(t.department_id) + '</td><td class="nowrap">' + esc(t.assignee || '—')
          + '</td><td class="nowrap">' + prPill(t.priority) + '</td><td class="nowrap">' + stPill(t.status) + '</td><td class="nowrap">' + t.progress + '%</td><td class="nowrap">' + dmy(t.start_date) + '</td><td class="nowrap">' + (t.due_date ? '<span class="' + (t.overdue ? 'wm-od' : '') + '">' + dmy(t.due_date) + (t.overdue ? ' · Overdue' : '') + '</span>' : '—') + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';
  }
  function board() {
    return '<div class="wm-board" id="board" aria-label="Task board">' + conf.statuses.map(function (s) {
      var ts = S.tasks.filter(function (t) { return t.status === s; });
      return '<section class="wm-col" data-status="' + esc(s) + '" aria-label="' + esc(s) + '"><div class="wm-col-h">' + stPill(s) + '<b>' + ts.length + '</b></div>'
        + (ts.length ? ts.map(card).join('') : '<div class="wm-col-empty">' + (isClient ? 'Nothing here' : 'Drop a task here') + '</div>') + '</section>';
    }).join('') + '</div>';
  }
  function card(t) {
    return '<button type="button" class="wm-tcard" data-task="' + t.id + '" data-id="' + t.id + '" aria-label="' + esc(t.code + ' ' + t.title + ', ' + t.status) + (isClient ? '' : '. Shift and arrow keys move it between columns.') + '">'
      + '<div style="display:flex;justify-content:space-between;gap:8px"><span class="wm-code">' + esc(t.code) + '</span>' + prPill(t.priority) + '</div>'
      + '<div class="t">' + esc(t.title) + '</div><div class="wm-bar' + (t.status === 'Completed' ? ' done' : '') + '" style="margin-bottom:10px"><i style="width:' + t.progress + '%"></i></div>'
      + '<div class="f">' + deptPill(t.department_id) + '<span style="display:inline-flex;gap:6px;align-items:center">' + due(t) + avatar(t.assignee) + '</span></div></button>';
  }
  /* Rects of cards before a change, so they can glide to their new places (FLIP). */
  function rects(root) { var r = {}; $$('.wm-tcard[data-id]', root).forEach(function (c) { r[c.dataset.id] = c.getBoundingClientRect(); }); return r; }
  function flip(root, before) {
    if (!before || calm) { return; }
    $$('.wm-tcard[data-id]', root).forEach(function (c) {
      var a = before[c.dataset.id];
      if (!a) { return; }
      var b = c.getBoundingClientRect(), dx = a.left - b.left, dy = a.top - b.top;
      if (!dx && !dy) { return; }
      c.style.transition = 'none';
      c.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
      requestAnimationFrame(function () { c.style.transition = 'transform .28s cubic-bezier(.2,.8,.2,1)'; c.style.transform = ''; });
    });
  }
  function moveTask(id, status) {
    var t = S.tasks.filter(function (x) { return x.id === id; })[0];
    if (!t || t.status === status) { return; }
    var old = { status: t.status, progress: t.progress };
    var before = rects($('#t-out'));
    t.status = status;
    if (status === 'Completed') { t.progress = 100; }
    renderTasks(before);
    post('task_status', { id: id, status: status }).then(function (j) {
      Object.assign(t, j.task);
      toast(status === 'Completed' ? t.code + ' completed' : t.code + ' moved to ' + status);
      if (status === 'Completed') { celebrate(); }
    }).catch(function (e) { var b2 = rects($('#t-out')); t.status = old.status; t.progress = old.progress; renderTasks(b2); fail(e); });
  }
  function wireBoard(out, before) {
    var boardEl = $('#board', out);
    flip(out, before);
    if (isClient) { return; }
    $$('.wm-tcard', boardEl).forEach(function (c) {
      c.addEventListener('keydown', function (e) {
        if (!e.shiftKey || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) { return; }
        e.preventDefault();
        var id = +c.dataset.id, t = S.tasks.filter(function (x) { return x.id === id; })[0], i = conf.statuses.indexOf(t.status) + (e.key === 'ArrowRight' ? 1 : -1);
        if (i < 0 || i >= conf.statuses.length) { return; }
        moveTask(id, conf.statuses[i]);
        setTimeout(function () { var n = $('.wm-tcard[data-id="' + id + '"]'); if (n) { n.focus(); } }, 30);
      });
    });
    var drag = null;
    boardEl.addEventListener('pointerdown', function (e) {
      var c = e.target.closest('.wm-tcard');
      if (!c || e.button !== 0) { return; }
      drag = { card: c, x: e.clientX, y: e.clientY, on: false, id: +c.dataset.id };
    });
    function move(e) {
      if (!drag) { return; }
      if (!drag.on) {
        if (Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 6) { return; }
        var r = drag.card.getBoundingClientRect();
        drag.on = true; drag.dx = drag.x - r.left; drag.dy = drag.y - r.top;
        drag.lift = drag.card.cloneNode(true);
        drag.lift.classList.add('lifted');
        drag.lift.style.width = r.width + 'px';
        drag.lift.removeAttribute('data-id');
        main.appendChild(drag.lift);
        drag.card.classList.add('ghost');
        document.body.classList.add('wm-dragging');
      }
      e.preventDefault();
      drag.lift.style.left = (e.clientX - drag.dx) + 'px';
      drag.lift.style.top = (e.clientY - drag.dy) + 'px';
      var under = document.elementFromPoint(e.clientX, e.clientY), col = under && under.closest('.wm-col');
      $$('.wm-col.over', boardEl).forEach(function (x) { if (x !== col) { x.classList.remove('over'); } });
      if (col) { col.classList.add('over'); }
      drag.col = col;
      var br = boardEl.getBoundingClientRect();
      if (e.clientX > br.right - 40) { boardEl.scrollLeft += 12; } else if (e.clientX < br.left + 40) { boardEl.scrollLeft -= 12; }
    }
    function up() {
      if (!drag) { return; }
      var d = drag; drag = null;
      if (!d.on) { return; }
      d.card.dataset.dragged = '1';
      setTimeout(function () { delete d.card.dataset.dragged; }, 50);
      d.lift.remove();
      d.card.classList.remove('ghost');
      document.body.classList.remove('wm-dragging');
      $$('.wm-col.over', boardEl).forEach(function (x) { x.classList.remove('over'); });
      if (d.col) { moveTask(d.id, d.col.getAttribute('data-status')); }
    }
    document.addEventListener('pointermove', move, { passive: false });
    document.addEventListener('pointerup', up);
    document.addEventListener('pointercancel', up);
    var gone = new MutationObserver(function () { if (!document.contains(boardEl)) { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', up); gone.disconnect(); } });
    gone.observe(out, { childList: true });
  }
  function celebrate() {
    var el = document.createElement('div');
    el.setAttribute('aria-hidden', 'true');
    el.style.cssText = 'position:fixed;left:50%;top:40%;transform:translate(-50%,-50%);z-index:1500;pointer-events:none';
    el.innerHTML = '<span class="wm-check on pop" style="width:72px;height:72px;border-width:0;box-shadow:0 18px 40px -10px rgba(18,128,92,.6)"><svg viewBox="0 0 24 24" style="width:38px;height:38px"><path d="M5 12.5l4.5 4.5L19 7"/></svg></span>';
    main.appendChild(el);
    setTimeout(function () { el.style.transition = 'opacity .25s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 260); }, calm ? 500 : 700);
  }

  /* ---------------------------------------------------------------- task form */
  function deptSelect(cur, withNew) {
    var act = S.boot.departments.filter(function (d) { return d.is_active || d.id === cur; });
    return '<select name="department_id" required><option value="">Choose a department…</option>' + act.map(function (d) { return '<option value="' + d.id + '"' + (d.id === cur ? ' selected' : '') + '>' + esc(d.name) + (d.is_active ? '' : ' (inactive)') + '</option>'; }).join('')
      + (withNew && isAdmin ? '<option value="__new">+ New department</option>' : '') + '</select>';
  }
  function taskFields(t) {
    t = t || { status: 'To Do', priority: 'Medium', progress: 0 };
    var team = S.boot.team || [];
    return '<label class="wm-field"><span>Title</span><input name="title" required maxlength="200" value="' + esc(t.title || '') + '" autofocus></label>'
      + '<label class="wm-field"><span>Description</span><textarea name="description" maxlength="10000">' + esc(t.description || '') + '</textarea></label>'
      + '<div class="wm-form2"><label class="wm-field"><span>Department</span>' + deptSelect(t.department_id, true) + '</label>'
      + '<label class="wm-field"><span>Assignee</span><select name="assignee_id"><option value="">Unassigned</option>' + team.map(function (p) { return '<option value="' + p.id + '"' + (p.id === t.assignee_id ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('')
      + (t.assignee_id && !team.some(function (p) { return p.id === t.assignee_id; }) ? '<option value="' + t.assignee_id + '" selected>' + esc(t.assignee || 'Former member') + '</option>' : '') + '</select></label>'
      + '<label class="wm-field"><span>Priority</span><select name="priority">' + conf.priorities.map(function (p) { return '<option' + (p === t.priority ? ' selected' : '') + '>' + p + '</option>'; }).join('') + '</select></label>'
      + '<label class="wm-field"><span>Status</span><select name="status">' + conf.statuses.map(function (p) { return '<option' + (p === t.status ? ' selected' : '') + '>' + p + '</option>'; }).join('') + '</select></label>'
      + '<label class="wm-field"><span>Start date</span><input type="date" name="start_date" value="' + esc(t.start_date || '') + '"></label>'
      + '<label class="wm-field"><span>Due date</span><input type="date" name="due_date" value="' + esc(t.due_date || '') + '"></label></div>';
  }
  function readForm(form) {
    var o = {};
    $$('input,select,textarea', form).forEach(function (el) { if (el.name) { o[el.name] = el.type === 'checkbox' ? el.checked : el.value; } });
    return o;
  }
  function wireNewDept(form) {
    var sel = $('select[name=department_id]', form);
    if (!sel) { return; }
    var prev = sel.value;
    sel.addEventListener('change', function () {
      if (sel.value !== '__new') { prev = sel.value; return; }
      sel.value = prev;
      deptDialog(null, function (d) {
        var o = document.createElement('option');
        o.value = d.id; o.textContent = d.name;
        sel.insertBefore(o, sel.querySelector('option[value="__new"]'));
        sel.value = String(d.id); prev = sel.value;
      });
    });
  }
  function newTask() {
    if (isClient) { return; }
    if (!S.boot.departments.some(function (d) { return d.is_active; })) {
      if (isAdmin) { deptDialog(null, function () { newTask(); }); toast('Create a department first. Tasks belong to one.'); }
      else { toast('You have no active department to add tasks to.', true); }
      return;
    }
    var d = dialog('New task', 'Fill in what you know now; everything can be changed later.', '<form id="nt" class="wm-dialog-b" style="padding:0" novalidate>' + taskFields(S.f.dept ? { department_id: +S.f.dept, status: 'To Do', priority: 'Medium' } : null) + '<div class="wm-err" id="nt-err" role="alert"></div></form>',
      '<button type="button" class="wm-btn" data-close>Cancel</button><button type="submit" form="nt" class="wm-btn primary">' + svg('plus') + 'Create task</button>');
    var form = $('#nt', d.el);
    wireNewDept(form);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = readForm(form), err = $('#nt-err', d.el);
      if (!v.title.trim()) { err.textContent = 'Give the task a title.'; $('[name=title]', form).focus(); return; }
      if (!v.department_id) { err.textContent = 'Choose a department.'; $('[name=department_id]', form).focus(); return; }
      var btn = $('button[type=submit]', d.el);
      busy(btn, true);
      post('task_save', v).then(function (j) {
        d.close();
        toast(j.task.code + ' created');
        if (conf.page === 'tasks') { loadTasks(); } else if (conf.page === 'overview') { loadOverview(); }
      }).catch(function (e2) { err.textContent = e2.message; busy(btn, false); });
    });
  }

  /* ---------------------------------------------------------------- task panel */
  function openTask(id) {
    var P = layer('panel', '<div class="wm-panel-h"><div style="min-width:0"><div class="wm-code" id="tp-code">Loading…</div><h2 id="tp-title"><span class="wm-sk" style="display:block;height:20px;width:260px"></span></h2></div>'
      + '<button type="button" class="wm-btn ghost wm-icon-btn" data-close aria-label="Close">' + svg('x') + '</button></div><div class="wm-panel-b" id="tp-b">'
      + '<div class="wm-sk" style="height:120px"></div><div class="wm-sk" style="height:60px;margin-top:16px"></div></div>', 'Task');
    P.onclose = function () { if (P.dirty) { if (conf.page === 'tasks') { loadTasks(); } else if (conf.page === 'overview') { loadOverview(); } } };
    get('task', { id: id }).then(function (j) { renderPanel(P, j); }).catch(function (e) { $('#tp-b', P.el).innerHTML = empty('alert', e.code === 404 ? 'Task not found' : 'Could not open this task', e.message); $('#tp-code', P.el).textContent = ''; $('#tp-title', P.el).textContent = ''; });
  }
  function renderPanel(P, j) {
    var t = j.task, el = P.el;
    $('#tp-code', el).innerHTML = esc(t.code) + ' · ' + deptPill(t.department_id);
    $('#tp-title', el).textContent = t.title;
    var h = '';
    if (isClient) {
      h += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">' + stPill(t.status) + prPill(t.priority) + (t.overdue ? '<span class="wm-chip bad">Overdue</span>' : '') + '</div>'
        + (t.description ? '<p style="white-space:pre-wrap;margin:0 0 16px;overflow-wrap:anywhere">' + esc(t.description) + '</p>' : '')
        + '<div class="wm-form2"><div><div class="wm-label">Progress</div><div class="wm-progress-row"><div class="wm-bar' + (t.status === 'Completed' ? ' done' : '') + '"><i style="width:' + t.progress + '%"></i></div><output>' + t.progress + '%</output></div></div>'
        + '<div><div class="wm-label">Assigned to</div>' + esc(t.assignee || 'Unassigned') + '</div><div><div class="wm-label">Start</div>' + (dmy(t.start_date) || '—') + '</div><div><div class="wm-label">Due</div>' + (dmy(t.due_date) || '—') + '</div>'
        + (t.completed_at ? '<div><div class="wm-label">Completed on</div>' + dmy(t.completed_at) + '</div>' : '') + '</div>';
    } else {
      h += '<form id="tp-form" novalidate><div class="wm-dialog-b" style="padding:0">' + taskFields(t)
        + '<div><label class="wm-label" for="tp-prog">Progress</label><div class="wm-progress-row"><input type="range" class="wm-range" id="tp-prog" name="progress" min="0" max="100" step="5" value="' + t.progress + '"><output id="tp-pv">' + t.progress + '%</output></div></div>'
        + '<label class="wm-field"><span>Note for the status history (optional)</span><input name="note" maxlength="500" placeholder="e.g. Waiting on the client’s copy"></label>'
        + '<div class="wm-err" id="tp-err" role="alert"></div><div style="display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap">'
        + (isAdmin ? '<button type="button" class="wm-btn danger" id="tp-del">Delete task</button>' : '<span></span>')
        + '<button type="submit" class="wm-btn primary">Save changes</button></div></div></form>';
    }
    if (isAdmin) {
      var clients = S.boot.clients || [];
      h += '<div class="wm-sec"><h3>Shared with clients</h3>' + (clients.length
        ? '<div class="wm-picks" id="tp-shares">' + clients.map(function (c) { return '<label class="wm-pick"><input type="checkbox" value="' + c.id + '"' + (j.shares.indexOf(c.id) >= 0 ? ' checked' : '') + '>' + esc(c.name) + '</label>'; }).join('') + '</div><p style="font-size:12.5px;color:var(--w-mute);margin:8px 0 0">Clients also see every task in departments they have access to.</p>'
        : '<p style="color:var(--w-mute);font-size:13px;margin:0">No clients have access yet. Invite one from Access.</p>') + '</div>';
    }
    h += '<div class="wm-sec"><h3>Comments <span class="wm-chip">' + j.comments.length + '</span></h3><div id="tp-coms">' + (j.comments.length ? j.comments.map(comment).join('') : '<p id="tp-nocom" style="color:var(--w-mute);font-size:13px;margin:0 0 8px">No comments yet.</p>') + '</div>';
    if (conf.canComment) {
      h += '<form id="tp-cf" class="wm-dialog-b" style="padding:0;gap:8px"><label class="wm-field"><span class="sr">New comment</span><textarea name="body" maxlength="5000" placeholder="Write a comment…" style="min-height:72px" required></textarea></label>'
        + '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">' + (isClient ? '<span style="font-size:12.5px;color:var(--w-mute)">Your comment is visible to the team.</span>' : '<label class="wm-check-row"><input type="checkbox" name="internal" checked> Internal (hidden from clients)</label>')
        + '<button type="submit" class="wm-btn sm primary">Add comment</button></div></form>';
    }
    h += '</div>';
    if (!isClient) {
      h += '<div class="wm-sec"><h3>Time logged <span class="wm-chip" id="tp-total">' + hm(j.minutes) + '</span></h3><table class="wm-logs" id="tp-logs"><tbody>' + j.logs.map(logRow).join('') + '</tbody></table>'
        + (j.logs.length ? '' : '<p id="tp-nolog" style="color:var(--w-mute);font-size:13px;margin:0 0 8px">No time logged yet.</p>')
        + '<form id="tp-lf" class="wm-inline" style="margin-top:8px"><label class="wm-field"><span>Date</span><input type="date" name="date" value="' + conf.today + '" max="' + conf.today + '" required></label>'
        + '<label class="wm-field" style="flex:0 1 80px"><span>Hours</span><input type="number" name="h" min="0" max="24" step="1" value="1" inputmode="numeric"></label>'
        + '<label class="wm-field" style="flex:0 1 80px"><span>Minutes</span><input type="number" name="m" min="0" max="59" step="5" value="0" inputmode="numeric"></label>'
        + '<label class="wm-field" style="flex:2 1 160px"><span>What was done</span><input name="description" maxlength="500"></label><button type="submit" class="wm-btn sm">Log time</button></form></div>';
    }
    h += '<div class="wm-sec"><h3>Status history</h3><ol class="wm-tl" id="tp-hist">' + j.history.slice().reverse().map(hist).join('') + '</ol></div>';
    $('#tp-b', el).innerHTML = h;

    var form = $('#tp-form', el);
    if (form) {
      wireNewDept(form);
      var rng = $('#tp-prog', el), pv = $('#tp-pv', el), stSel = $('select[name=status]', form);
      rng.addEventListener('input', function () { pv.textContent = rng.value + '%'; });
      stSel.addEventListener('change', function () { if (stSel.value === 'Completed') { rng.value = 100; pv.textContent = '100%'; } });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var v = readForm(form), err = $('#tp-err', el), btn = $('button[type=submit]', form);
        v.id = t.id;
        if (!v.title.trim()) { err.textContent = 'Give the task a title.'; return; }
        busy(btn, true);
        post('task_save', v).then(function (j2) {
          P.dirty = true;
          toast(j2.task.status === 'Completed' && t.status !== 'Completed' ? j2.task.code + ' completed' : 'Saved');
          if (j2.task.status === 'Completed' && t.status !== 'Completed') { celebrate(); }
          renderPanel(P, j2);
        }).catch(function (e2) { err.textContent = e2.message; busy(btn, false); });
      });
      var del = $('#tp-del', el);
      if (del) {
        del.addEventListener('click', function () {
          confirmBox('Delete ' + t.code + '?', 'The task is removed from lists and reports. Its history is kept in the audit log.', 'Delete task', true).then(function (ok) {
            if (!ok) { return; }
            post('task_delete', { id: t.id }).then(function () { P.dirty = true; P.close(); toast(t.code + ' deleted'); }).catch(fail);
          });
        });
      }
    }
    var sh = $('#tp-shares', el);
    if (sh) {
      sh.addEventListener('change', function () {
        var ids = $$('input:checked', sh).map(function (x) { return +x.value; });
        post('task_shares', { task_id: t.id, clients: ids }).then(function () { toast('Sharing updated'); }).catch(fail);
      });
    }
    var cf = $('#tp-cf', el);
    if (cf) {
      cf.addEventListener('submit', function (e) {
        e.preventDefault();
        var v = readForm(cf);
        if (!v.body.trim()) { $('textarea', cf).focus(); return; }
        var btn = $('button[type=submit]', cf); busy(btn, true);
        post('comment_add', { task_id: t.id, body: v.body, internal: !!v.internal }).then(function (r) {
          var no = $('#tp-nocom', el); if (no) { no.remove(); }
          $('#tp-coms', el).insertAdjacentHTML('beforeend', comment(r.comment));
          $('textarea', cf).value = ''; busy(btn, false); toast('Comment added');
          // Back to internal after every comment, so nothing reaches a client by leftover choice.
          var intBox = $('[name=internal]', cf); if (intBox) { intBox.checked = true; }
        }).catch(function (e2) { busy(btn, false); fail(e2); });
      });
    }
    var lf = $('#tp-lf', el);
    if (lf) {
      lf.addEventListener('submit', function (e) {
        e.preventDefault();
        var v = readForm(lf), mins = (+v.h || 0) * 60 + (+v.m || 0);
        if (mins < 1) { toast('Enter the time spent.', true); return; }
        var btn = $('button[type=submit]', lf); busy(btn, true);
        post('log_add', { task_id: t.id, date: v.date, minutes: mins, description: v.description }).then(function (r) {
          var no = $('#tp-nolog', el); if (no) { no.remove(); }
          $('#tp-logs tbody', el).insertAdjacentHTML('afterbegin', logRow(r.log));
          j.minutes += r.log.minutes; $('#tp-total', el).textContent = hm(j.minutes);
          $('[name=description]', lf).value = ''; busy(btn, false); toast('Time logged'); wireLogs();
        }).catch(function (e2) { busy(btn, false); fail(e2); });
      });
      wireLogs();
    }
    function wireLogs() {
      $$('[data-dellog]', el).forEach(function (b) {
        if (b.dataset.w) { return; } b.dataset.w = '1';
        b.addEventListener('click', function () {
          post('log_delete', { id: +b.getAttribute('data-dellog') }).then(function () { j.minutes -= +b.getAttribute('data-min'); $('#tp-total', el).textContent = hm(j.minutes); b.closest('tr').remove(); toast('Time entry removed'); }).catch(fail);
        });
      });
    }
  }
  function hm(m) { m = +m || 0; var h = Math.floor(m / 60), r = m % 60; return (h ? h + 'h ' : '') + (r || !h ? r + 'm' : ''); }
  function comment(c) {
    return '<div class="wm-com' + (+c.is_internal ? ' int' : '') + '"><div class="h"><span><b>' + esc(c.author_name || 'Someone') + '</b>' + (+c.is_internal ? ' · <span class="wm-chip warn">Internal</span>' : (isClient ? '' : ' · <span class="wm-chip good">Visible to clients</span>')) + '</span><span>' + dmyTime(c.created_at) + '</span></div><p>' + esc(c.body) + '</p></div>';
  }
  function logRow(l) {
    var mine = isAdmin || +l.user_id === +conf.me;
    return '<tr><td style="white-space:nowrap">' + dmy(l.date) + '</td><td><b>' + hm(l.minutes) + '</b></td><td>' + esc(l.user_name) + (l.description ? '<br><span style="color:var(--w-mute)">' + esc(l.description) + '</span>' : '') + '</td><td style="text-align:right">'
      + (mine ? '<button type="button" class="wm-btn ghost sm" data-dellog="' + l.id + '" data-min="' + l.minutes + '" aria-label="Remove this time entry">' + svg('x') + '</button>' : '') + '</td></tr>';
  }
  function hist(x) {
    var what = x.old_status === null ? 'Created as ' + x.new_status : (x.old_status !== x.new_status ? x.old_status + ' → ' + x.new_status : 'Progress updated');
    var prog = x.old_progress === null ? x.new_progress + '%' : (+x.old_progress !== +x.new_progress ? x.old_progress + '% → ' + x.new_progress + '%' : x.new_progress + '%');
    return '<li style="--tc:' + (STATUS_COLORS[x.new_status] || '#2a78d6') + '"><b>' + esc(what) + '</b> · ' + esc(prog) + '<div class="w">' + dmyTime(x.changed_at) + ' · ' + esc(x.changed_by_name || '') + '</div>'
      + (x.note && x.note !== 'Created' ? '<div style="margin-top:2px">' + esc(x.note) + '</div>' : '') + '</li>';
  }

  /* ================================================================ departments */
  function pageDepartments() {
    var b = body();
    var head = $('.wm-head .wm-actions');
    if (!$('#nd', head)) { head.insertAdjacentHTML('afterbegin', '<button type="button" class="wm-btn primary" id="nd">' + svg('plus') + 'New department</button>'); $('#nd').addEventListener('click', function () { deptDialog(null, renderDepts); }); }
    b.innerHTML = '<div id="d-out"></div>';
    renderDepts();
  }
  function renderDepts() {
    return get('departments').then(function (j) {
      S.boot.departments = j.departments;
      var out = $('#d-out');
      if (!out) { return; }
      if (!j.departments.length) {
        out.innerHTML = '<div class="wm-card">' + empty('folder', 'No departments yet', 'Create your first department. Every task belongs to one, and access is given by department.', '<button type="button" class="wm-btn primary" id="nd2">' + svg('plus') + 'Create your first department</button>') + '</div>';
        $('#nd2').addEventListener('click', function () { deptDialog(null, renderDepts); });
        return;
      }
      out.innerHTML = '<div class="wm-dgrid wm-stagger">' + j.departments.map(function (d) {
        return '<div class="wm-card wm-dcard' + (d.is_active ? '' : ' off') + '" style="--dc:' + esc(d.color) + '"><h3><span style="overflow-wrap:anywhere">' + esc(d.name) + '</span>' + (d.is_active ? '<span class="wm-chip good">Active</span>' : '<span class="wm-chip">Inactive</span>') + '</h3>'
          + '<p>' + esc(d.description || '') + '</p><div class="n"><span><b>' + d.total + '</b>tasks</span><span><b>' + d.open + '</b>open</span><span><b>' + d.done + '</b>completed</span></div>'
          + '<div class="wm-actions"><button type="button" class="wm-btn sm" data-edit="' + d.id + '">Edit</button><button type="button" class="wm-btn sm" data-toggle="' + d.id + '">' + (d.is_active ? 'Deactivate' : 'Activate') + '</button>'
          + (d.total === 0 ? '<button type="button" class="wm-btn sm danger" data-del="' + d.id + '">Delete</button>' : '') + '</div></div>';
      }).join('') + '</div>';
      $$('[data-edit]', out).forEach(function (x) { x.addEventListener('click', function () { deptDialog(dept(+x.getAttribute('data-edit')), renderDepts); }); });
      $$('[data-toggle]', out).forEach(function (x) {
        x.addEventListener('click', function () {
          var d = dept(+x.getAttribute('data-toggle'));
          post('dept_save', { id: d.id, name: d.name, color: d.color, description: d.description, is_active: !d.is_active }).then(function () { toast(d.name + (d.is_active ? ' deactivated' : ' activated')); renderDepts(); }).catch(fail);
        });
      });
      $$('[data-del]', out).forEach(function (x) {
        x.addEventListener('click', function () {
          var d = dept(+x.getAttribute('data-del'));
          confirmBox('Delete ' + d.name + '?', 'This department has no tasks. Anyone with access to it loses that access.', 'Delete department', true).then(function (ok) {
            if (ok) { post('dept_delete', { id: d.id }).then(function () { toast(d.name + ' deleted'); renderDepts(); }).catch(fail); }
          });
        });
      });
    }).catch(function (e) { var o = $('#d-out'); if (o) { o.innerHTML = '<div class="wm-card">' + empty('alert', 'Could not load departments', e.message) + '</div>'; } });
  }
  function deptDialog(d, after) {
    d = d || { name: '', color: SWATCHES[(S.boot.departments.length) % SWATCHES.length], description: '', is_active: true };
    var sw = SWATCHES.indexOf(d.color) < 0 ? SWATCHES.concat([d.color]) : SWATCHES;
    var D = dialog(d.id ? 'Edit department' : 'New department', d.id ? '' : 'Tasks are grouped by department, and access is given by department.',
      '<form id="df" class="wm-dialog-b" style="padding:0" novalidate><label class="wm-field"><span>Name</span><input name="name" maxlength="120" required value="' + esc(d.name) + '" autofocus></label>'
      + '<fieldset style="border:0;padding:0;margin:0"><legend class="wm-label" style="margin-bottom:6px">Colour</legend><div class="wm-swatches">' + sw.map(function (c, i) {
        return '<label><input type="radio" name="color" value="' + c + '"' + (c === d.color ? ' checked' : '') + ' aria-label="Colour ' + (i + 1) + '"><span style="--c:' + c + '"></span></label>';
      }).join('') + '</div></fieldset>'
      + '<label class="wm-field"><span>Description (optional)</span><textarea name="description" maxlength="2000" style="min-height:72px">' + esc(d.description || '') + '</textarea></label>'
      + (d.id ? '<label class="wm-check-row"><input type="checkbox" name="is_active"' + (d.is_active ? ' checked' : '') + '> Active (new tasks can be added)</label>' : '')
      + '<div class="wm-err" id="df-err" role="alert"></div></form>',
      '<button type="button" class="wm-btn" data-close>Cancel</button><button type="submit" form="df" class="wm-btn primary">' + (d.id ? 'Save' : 'Create department') + '</button>');
    var f = $('#df', D.el);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = { id: d.id || 0, name: $('[name=name]', f).value, color: ($('[name=color]:checked', f) || {}).value, description: $('[name=description]', f).value,
        is_active: d.id ? $('[name=is_active]', f).checked : true };
      if (!v.name.trim()) { $('#df-err', D.el).textContent = 'Give the department a name.'; return; }
      var btn = $('button[type=submit]', D.el); busy(btn, true);
      post('dept_save', v).then(function (j) {
        S.boot.departments = j.departments;
        D.close(); toast(d.id ? 'Department saved' : j.department.name + ' created');
        if (after) { after(j.department); }
      }).catch(function (e2) { $('#df-err', D.el).textContent = e2.message; busy(btn, false); });
    });
  }

  /* ================================================================ access */
  function pageAccess() {
    var head = $('.wm-head .wm-actions');
    if (!$('#inv', head)) { head.insertAdjacentHTML('afterbegin', '<button type="button" class="wm-btn primary" id="inv">' + svg('plus') + 'Invite person</button>'); $('#inv').addEventListener('click', function () { inviteDialog(); }); }
    body().innerHTML = '<div id="a-out">' + skel() + '</div>';
    loadAccess();
  }
  function loadAccess() {
    return Promise.all([get('access'), get('audit')]).then(function (r) {
      S.acc = r[0];
      var out = $('#a-out'), ms = S.acc.members;
      var grp = function (roleKey, title) {
        var list = ms.filter(function (m) { return m.role === roleKey; });
        return '<div class="wm-card" style="margin-bottom:16px"><div class="wm-card-h"><h2>' + title + '</h2><small>' + list.length + '</small></div><div class="wm-card-b" style="padding:8px 0">'
          + (list.length ? '<div class="wm-people">' + list.map(person).join('') + '</div>'
            : empty('users', roleKey === 'team' ? 'No team members yet' : 'No clients yet', roleKey === 'team' ? 'Invite a team member. They will see tasks in the departments you give them.' : 'Invite a client. They get a read-only view of the departments and tasks you share.',
              '<button type="button" class="wm-btn primary" data-inv="' + roleKey + '">' + svg('plus') + 'Invite ' + (roleKey === 'team' ? 'a team member' : 'a client') + '</button>')) + '</div></div>';
      };
      out.innerHTML = '<div class="wm-stagger">' + grp('team', 'Team members') + grp('client', 'Clients')
        + '<div class="wm-card"><div class="wm-card-h"><h2>Audit log</h2><small>Latest 200</small></div><div class="wm-card-b" style="padding:0">' + (r[1].audit.length
          ? '<div class="wm-tablewrap" style="max-height:420px"><table class="wm-table static wm-audit"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Item</th><th>Detail</th></tr></thead><tbody>' + r[1].audit.map(function (a) {
            return '<tr><td>' + dmyTime(a.at) + '</td><td>' + esc(a.actor_name) + '</td><td>' + esc(String(a.action).replace(/_/g, ' ')) + '</td><td>' + esc(a.target) + '</td><td class="wrap">' + esc(a.detail || '') + '</td></tr>';
          }).join('') + '</tbody></table></div>' : '<p style="padding:16px;margin:0;color:var(--w-mute)">Nothing recorded yet.</p>') + '</div></div></div>';
      $$('[data-inv]', out).forEach(function (b) { b.addEventListener('click', function () { inviteDialog(b.getAttribute('data-inv')); }); });
      $$('[data-mact]', out).forEach(function (b) { b.addEventListener('click', function () { memberAction(+b.getAttribute('data-id'), b.getAttribute('data-mact')); }); });
    }).catch(function (e) { $('#a-out').innerHTML = '<div class="wm-card">' + empty('alert', 'Could not load access', e.message) + '</div>'; });
  }
  function person(m) {
    var depts = m.depts.map(function (id) { var d = dept(id); return d ? '<span class="wm-chip"><span class="wm-dept" style="--dc:' + esc(d.color) + '">' + esc(d.name) + '</span></span>' : ''; }).join('');
    var state = !m.is_active ? '<span class="wm-chip">Deactivated</span>' : (!m.account_ok ? '<span class="wm-chip bad">Portal account revoked</span>' : (m.has_password ? '<span class="wm-chip good">Active</span>' : '<span class="wm-chip warn">Invite pending</span>'));
    return '<div class="wm-person' + (m.is_active ? '' : ' off') + '">' + avatar(m.name).replace('wm-avatar', 'wm-avatar') + '<div style="min-width:0"><div class="nm">' + esc(m.name) + '</div><div class="em">' + esc(m.email || 'No email on file')
      + (m.last_login_at ? ' · last signed in ' + dmy(m.last_login_at) : '') + '</div><div class="tags">' + state + (m.role === 'client' && m.can_comment ? '<span class="wm-chip">Can comment</span>' : '')
      + (depts || '<span class="wm-chip">No departments</span>') + (m.role === 'client' && m.shares.length ? '<span class="wm-chip">' + m.shares.length + ' shared task' + (m.shares.length > 1 ? 's' : '') + '</span>' : '') + '</div></div>'
      + '<div class="act"><button type="button" class="wm-btn sm" data-mact="edit" data-id="' + m.id + '">Edit access</button>'
      + (m.is_active ? '<button type="button" class="wm-btn sm" data-mact="resend" data-id="' + m.id + '">' + (m.has_password ? 'Send sign-in link' : 'Resend invite') + '</button><button type="button" class="wm-btn sm" data-mact="off" data-id="' + m.id + '">Deactivate</button>'
        : '<button type="button" class="wm-btn sm" data-mact="on" data-id="' + m.id + '">Activate</button>')
      + '<button type="button" class="wm-btn sm danger" data-mact="revoke" data-id="' + m.id + '">Revoke</button></div></div>';
  }
  function deptPicks(sel) {
    if (!S.boot.departments.length) { return '<p style="margin:0;color:var(--w-mute);font-size:13px">No departments yet. <a href="work.php?p=departments">Create one</a> first, or share single tasks.</p>'; }
    return '<div class="wm-picks">' + S.boot.departments.map(function (d) { return '<label class="wm-pick"><input type="checkbox" name="dept" value="' + d.id + '"' + (sel.indexOf(d.id) >= 0 ? ' checked' : '') + '><span class="wm-dept" style="--dc:' + esc(d.color) + '">' + esc(d.name) + '</span></label>'; }).join('') + '</div>';
  }
  function sharePicks(sel) {
    var ts = S.acc.tasks;
    if (!ts.length) { return '<p style="margin:0;color:var(--w-mute);font-size:13px">No tasks yet.</p>'; }
    return '<input class="wm-input" type="search" placeholder="Filter tasks…" data-sf aria-label="Filter tasks" style="margin-bottom:6px"><div class="wm-sharelist">' + ts.map(function (t) {
      return '<label data-st="' + esc((t.code + ' ' + t.title).toLowerCase()) + '"><input type="checkbox" name="share" value="' + t.id + '"' + (sel.indexOf(t.id) >= 0 ? ' checked' : '') + '><span><span class="wm-code">' + esc(t.code) + '</span> ' + esc(t.title) + '</span></label>';
    }).join('') + '</div>';
  }
  function wireShareFilter(el) {
    var f = $('[data-sf]', el);
    if (f) { f.addEventListener('input', function () { var q = f.value.toLowerCase(); $$('.wm-sharelist label', el).forEach(function (l) { l.style.display = l.getAttribute('data-st').indexOf(q) >= 0 ? '' : 'none'; }); }); }
  }
  function inviteDialog(r0) {
    var cands = S.acc ? S.acc.candidates : [];
    var D = dialog('Invite a person', 'They sign in through the portal’s usual login. Anyone new gets a one-time link to choose their password.',
      '<form id="if" class="wm-dialog-b" style="padding:0" novalidate>'
      + '<fieldset style="border:0;padding:0;margin:0"><legend class="wm-label" style="margin-bottom:6px">Role</legend><div class="wm-seg" role="radiogroup">'
      + '<label class="wm-pick" style="border:0"><input type="radio" name="role" value="team"' + (r0 !== 'client' ? ' checked' : '') + '> Team member</label>'
      + '<label class="wm-pick" style="border:0"><input type="radio" name="role" value="client"' + (r0 === 'client' ? ' checked' : '') + '> Client (read-only)</label></div></fieldset>'
      + '<fieldset style="border:0;padding:0;margin:0"><legend class="wm-label" style="margin-bottom:6px">Who</legend><div class="wm-seg" role="radiogroup">'
      + '<label class="wm-pick" style="border:0"><input type="radio" name="mode" value="new" checked> Someone new</label>'
      + '<label class="wm-pick" style="border:0"><input type="radio" name="mode" value="existing"> An existing portal account</label></div></fieldset>'
      + '<div id="if-new" class="wm-form2"><label class="wm-field"><span>Name</span><input name="name" maxlength="160" autocomplete="off"></label><label class="wm-field"><span>Email</span><input type="email" name="email" maxlength="190" autocomplete="off"></label></div>'
      + '<label class="wm-field" id="if-ex" hidden><span>Account</span><select name="person_id"></select></label>'
      + '<div><div class="wm-label" style="margin-bottom:6px">Departments</div>' + deptPicks([]) + '</div>'
      + '<div id="if-client" hidden><div class="wm-label" style="margin-bottom:6px">Also share single tasks (optional)</div>' + (S.acc ? sharePicks([]) : '') + '<label class="wm-check-row" style="margin-top:10px"><input type="checkbox" name="can_comment"> Can comment on what is shared with them</label></div>'
      + '<div class="wm-err" id="if-err" role="alert"></div></form>',
      '<button type="button" class="wm-btn" data-close>Cancel</button><button type="submit" form="if" class="wm-btn primary">Send invite</button>');
    var f = $('#if', D.el);
    wireShareFilter(D.el);
    function sync() {
      var roleV = $('[name=role]:checked', f).value, mode = $('[name=mode]:checked', f).value;
      $('#if-client', f).hidden = roleV !== 'client';
      $('#if-new', f).hidden = mode !== 'new';
      $('#if-ex', f).hidden = mode !== 'existing';
      var list = cands.filter(function (c) { return c.kind === roleV; });
      $('[name=person_id]', f).innerHTML = list.length ? list.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + (c.email ? ' · ' + esc(c.email) : '') + '</option>'; }).join('')
        : '<option value="">No ' + (roleV === 'team' ? 'team members' : 'client accounts') + ' without access</option>';
    }
    $$('[name=role],[name=mode]', f).forEach(function (x) { x.addEventListener('change', sync); });
    sync();
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var err = $('#if-err', D.el), mode = $('[name=mode]:checked', f).value;
      var v = { role: $('[name=role]:checked', f).value, depts: $$('[name=dept]:checked', f).map(function (x) { return +x.value; }),
        shares: $$('[name=share]:checked', f).map(function (x) { return +x.value; }), can_comment: $('[name=can_comment]', f).checked };
      if (mode === 'new') {
        v.name = $('[name=name]', f).value.trim(); v.email = $('[name=email]', f).value.trim();
        if (!v.name) { err.textContent = 'Enter their name.'; return; }
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v.email)) { err.textContent = 'Enter a valid email address.'; return; }
      } else {
        v.person_id = +$('[name=person_id]', f).value;
        if (!v.person_id) { err.textContent = 'Choose an account.'; return; }
      }
      var btn = $('button[type=submit]', D.el); busy(btn, true);
      post('member_invite', v).then(function (j) { D.close(); sentDialog(j); loadAccess(); reboot(); }).catch(function (e2) { err.textContent = e2.message; busy(btn, false); });
    });
  }
  function sentDialog(j) {
    var m = j.member;
    var note = j.mailed ? '<div class="wm-note good">Email sent to <b>' + esc(m.email) + '</b>.</div>' : '<div class="wm-note warn">The email was not sent (' + esc(j.mail_detail) + '). Copy the link below and send it yourself.</div>';
    var D = dialog(j.kind === 'invite' ? 'Invitation ready' : 'Sign-in link ready', '', note
      + '<div><div class="wm-label" style="margin-bottom:6px">' + (j.kind === 'invite' ? 'One-time link to choose a password (expires in 7 days)' : 'Sign-in link') + '</div><div class="wm-linkbox"><input class="wm-input" readonly value="' + esc(j.link) + '" id="lk" aria-label="Link"><button type="button" class="wm-btn" id="cp">' + svg('copy') + 'Copy</button></div></div>',
      '<button type="button" class="wm-btn primary" data-close>Done</button>');
    $('#cp', D.el).addEventListener('click', function () {
      var i = $('#lk', D.el); i.select();
      (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).then(function () { toast('Link copied'); }, function () { try { document.execCommand('copy'); toast('Link copied'); } catch (e) { toast('Select the link and copy it.', true); } });
    });
  }
  function memberAction(id, act) {
    var m = S.acc.members.filter(function (x) { return x.id === id; })[0];
    if (!m) { return; }
    if (act === 'edit') { return editMember(m); }
    if (act === 'resend') { post('member_resend', { id: id }).then(function (j) { sentDialog(j); loadAccess(); }).catch(fail); return; }
    if (act === 'on') { post('member_active', { id: id, active: true }).then(function () { toast(m.name + ' activated'); loadAccess(); reboot(); }).catch(fail); return; }
    var revoke = act === 'revoke';
    confirmBox((revoke ? 'Revoke ' : 'Deactivate ') + m.name + '?', revoke ? 'Their Work access ends at once and their departments and shared tasks are removed. Their portal account for other modules is not touched.' : 'Their Work access ends at once. Their departments are kept, so you can activate them again later.', revoke ? 'Revoke access' : 'Deactivate', true).then(function (ok) {
      if (ok) { post('member_active', { id: id, active: false, revoke: revoke }).then(function () { toast(m.name + (revoke ? ': access revoked' : ' deactivated')); loadAccess(); reboot(); }).catch(fail); }
    });
  }
  function editMember(m) {
    var D = dialog('Access for ' + m.name, m.role === 'team' ? 'Team member: sees and works on tasks in these departments.' : 'Client: read-only view of these departments and the tasks shared with them.',
      '<form id="ef" class="wm-dialog-b" style="padding:0"><div><div class="wm-label" style="margin-bottom:6px">Departments</div>' + deptPicks(m.depts) + '</div>'
      + (m.role === 'client' ? '<div><div class="wm-label" style="margin-bottom:6px">Shared tasks</div>' + sharePicks(m.shares) + '</div><label class="wm-check-row"><input type="checkbox" name="can_comment"' + (m.can_comment ? ' checked' : '') + '> Can comment on what is shared with them</label>' : '')
      + '<div class="wm-err" id="ef-err" role="alert"></div></form>',
      '<button type="button" class="wm-btn" data-close>Cancel</button><button type="submit" form="ef" class="wm-btn primary">Save access</button>');
    wireShareFilter(D.el);
    var f = $('#ef', D.el);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('button[type=submit]', D.el); busy(btn, true);
      post('member_update', { id: m.id, depts: $$('[name=dept]:checked', f).map(function (x) { return +x.value; }), shares: $$('[name=share]:checked', f).map(function (x) { return +x.value; }),
        can_comment: !!($('[name=can_comment]', f) || {}).checked }).then(function () { D.close(); toast('Access saved'); loadAccess(); })
        .catch(function (e2) { $('#ef-err', D.el).textContent = e2.message; busy(btn, false); });
    });
  }
  function reboot() { return boot().catch(function () {}); }

  /* ================================================================ reports */
  function pageReports() {
    var today = conf.today;
    S.rf = S.rf || { compare: addDays(today, -7), current: today, depts: [], assignee: '', client: '', status: '' };
    var team = S.boot.team || [], clients = S.boot.clients || [];
    var opt = function (v, l, cur) { return '<option value="' + esc(v) + '"' + (String(cur) === String(v) ? ' selected' : '') + '>' + esc(l) + '</option>'; };
    body().innerHTML = '<div class="wm-card" style="margin-bottom:16px"><div class="wm-card-b"><form id="rf" novalidate><div class="wm-filters" style="margin-bottom:12px">'
      + '<label class="wm-field"><span>Compare date (last week)</span><input type="date" name="compare" value="' + S.rf.compare + '" max="' + today + '" required></label>'
      + '<label class="wm-field"><span>Current date (today)</span><input type="date" name="current" value="' + S.rf.current + '" max="' + today + '" required></label>'
      + (isClient ? '' : '<label class="wm-field"><span>Assignee</span><select name="assignee">' + opt('', 'Anyone', S.rf.assignee) + opt('none', 'Unassigned', S.rf.assignee) + team.map(function (p) { return opt(p.id, p.name, S.rf.assignee); }).join('') + '</select></label>')
      + (isAdmin ? '<label class="wm-field"><span>Client</span><select name="client">' + opt('', 'All', S.rf.client) + clients.map(function (c) { return opt(c.id, c.name, S.rf.client); }).join('') + '</select></label>' : '')
      + '<label class="wm-field"><span>Status (today)</span><select name="status">' + opt('', 'All', S.rf.status) + conf.statuses.map(function (s) { return opt(s, s, S.rf.status); }).join('') + '</select></label></div>'
      + (S.boot.departments.length > 1 ? '<div class="wm-label" style="margin-bottom:6px">Departments (none ticked = all)</div><div class="wm-deptpicks">' + S.boot.departments.map(function (d) { return '<label class="wm-pick"><input type="checkbox" name="dept" value="' + d.id + '"' + (S.rf.depts.indexOf(d.id) >= 0 ? ' checked' : '') + '><span class="wm-dept" style="--dc:' + esc(d.color) + '">' + esc(d.name) + '</span></label>'; }).join('') + '</div>' : '')
      + '<div class="wm-actions" style="margin-top:16px"><button type="submit" class="wm-btn primary">Preview report</button><a class="wm-btn" id="rx" href="#">' + svg('download') + 'Download Excel</a><span class="wm-err" id="rf-err" role="alert"></span></div></form></div></div><div id="r-out">' + skel() + '</div>';
    var f = $('#rf');
    function read() {
      S.rf = { compare: $('[name=compare]', f).value, current: $('[name=current]', f).value, depts: $$('[name=dept]:checked', f).map(function (x) { return +x.value; }),
        assignee: ($('[name=assignee]', f) || {}).value || '', client: ($('[name=client]', f) || {}).value || '', status: $('[name=status]', f).value };
      $('#rx').href = url('export', S.rf);
      return S.rf;
    }
    f.addEventListener('submit', function (e) { e.preventDefault(); runReport(read()); });
    f.addEventListener('change', read);
    $('#rx').addEventListener('click', function (e) {
      var r = read();
      if (!r.compare || !r.current || r.compare > r.current) { e.preventDefault(); $('#rf-err').textContent = 'The compare date must be on or before the current date.'; return; }
      toast('Preparing your Excel report…');
    });
    runReport(read());
  }
  function runReport(q) {
    var out = $('#r-out'), err = $('#rf-err');
    err.textContent = '';
    if (!q.compare || !q.current || q.compare > q.current) { err.textContent = 'The compare date must be on or before the current date.'; return; }
    out.innerHTML = skel();
    get('report', q).then(function (r) {
      S.rep = r;
      var sum = r.summary.counts.filter(function (c) { return c.compare || c.current; });
      var cards = '<div class="wm-sumgrid">' + (sum.length ? sum.map(function (c) {
        var d = c.current - c.compare;
        return '<div class="wm-sum">' + stPill(c.status) + '<div class="v" style="margin-top:6px">' + c.current + '</div><div class="d">' + c.compare + ' on ' + dmy(r.filters.compare) + ' · ' + (d === 0 ? 'no change' : (d > 0 ? '+' : '') + d) + '</div></div>';
      }).join('') : '<p style="margin:0;color:var(--w-mute)">No tasks.</p>') + '</div>';
      var depts = r.summary.per_dept.length ? '<div class="wm-tablewrap"><table class="wm-table static"><thead><tr><th>Department</th><th>Total</th><th>Completed</th><th>In Progress</th><th>Overdue</th></tr></thead><tbody>'
        + r.summary.per_dept.map(function (d) { return '<tr><td>' + esc(d.department) + '</td><td>' + d.total + '</td><td>' + d.completed + '</td><td>' + d.in_progress + '</td><td' + (d.overdue ? ' class="wm-od"' : '') + '>' + d.overdue + '</td></tr>'; }).join('') + '</tbody></table></div>' : '';
      var rows = r.rows.length ? '<div class="wm-tablewrap" style="max-height:640px"><table class="wm-table static"><thead><tr>' + r.columns.map(function (c) { return '<th scope="col">' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + r.rows.map(function (x) {
          return '<tr class="' + (x.changed ? 'changed' : '') + '"><td>' + x.sn + '</td><td class="nowrap wm-code">' + esc(x.code) + '</td><td class="wrap"><b>' + esc(x.title) + '</b></td><td class="wrap">' + esc(x.description) + '</td><td class="nowrap">' + esc(x.department)
            + '</td><td class="nowrap">' + esc(x.assignee || 'Unassigned') + '</td><td class="nowrap">' + prPill(x.priority) + '</td><td class="nowrap">' + dmy(x.start_date) + '</td><td class="nowrap' + (x.overdue_cur ? ' wm-od' : '') + '">' + dmy(x.due_date)
            + '</td><td class="nowrap">' + stPill(x.status_cmp) + (x.overdue_cmp ? ' <span class="wm-od">Overdue</span>' : '') + '</td><td class="nowrap">' + stPill(x.status_cur) + (x.overdue_cur ? ' <span class="wm-od">Overdue</span>' : '') + '</td><td>' + (x.progress_cmp === null ? '—' : x.progress_cmp + '%') + '</td><td>' + x.progress_cur + '%</td>'
            + '<td class="nowrap chg">' + esc(x.change) + '</td><td class="nowrap">' + dmy(x.completed_on) + '</td><td>' + (x.hours === null ? '—' : x.hours) + '</td><td class="wrap">' + esc(x.remarks) + '</td></tr>';
        }).join('') + '</tbody></table></div>'
        : empty('file', 'No tasks in this report', S.boot.departments.length ? 'Change the dates or filters, or add tasks first.' : 'Create a department and some tasks; they will show up here.');
      out.innerHTML = '<div class="wm-grid split wm-stagger" style="margin-bottom:16px"><div class="wm-card"><div class="wm-card-h"><h2>By status</h2><small>' + dmy(r.filters.compare) + ' → ' + dmy(r.filters.current) + '</small></div><div class="wm-card-b">' + cards + '</div></div>'
        + '<div class="wm-card"><div class="wm-card-h"><h2>By department</h2><small>' + r.summary.changed + ' changed</small></div><div class="wm-card-b" style="padding:' + (depts ? '8px 0 0' : '16px') + '">' + (depts || '<p style="margin:0;color:var(--w-mute)">No tasks.</p>') + '</div></div></div>'
        + '<div class="wm-card wm-view"><div class="wm-card-h" style="padding-bottom:12px"><h2>Task Status</h2><small>' + r.rows.length + ' task' + (r.rows.length === 1 ? '' : 's') + ' · ' + esc(r.filename) + '</small></div>' + rows + '</div>';
    }).catch(function (e) { out.innerHTML = '<div class="wm-card">' + empty('alert', 'Could not build the report', e.message) + '</div>'; });
  }

  /* ================================================================ start */
  app.innerHTML = shell();
  app.removeAttribute('aria-busy');
  theme();
  $('#wm-theme').addEventListener('click', function () { theme(main.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); });
  main.addEventListener('click', function (e) { if (e.target.closest('[data-newtask]')) { newTask(); } });
  body().innerHTML = conf.page === 'overview' ? skel('kpis') + skel() : skel();
  boot().then(function () {
    var pages = { overview: pageOverview, tasks: pageTasks, departments: pageDepartments, access: pageAccess, reports: pageReports };
    (pages[conf.page] || pageTasks)();
  }).catch(function (e) { body().innerHTML = '<div class="wm-card">' + empty('alert', 'Work Management could not load', e.message) + '</div>'; });
})();
