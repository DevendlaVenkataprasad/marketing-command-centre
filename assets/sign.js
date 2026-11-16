/* Digital Signatures (lib/ds) — the page behaviour. Plain JS, no build step.
   Everything works without it except the PDF preview, the signature pad and the
   workflow builder; the server re-checks all of it. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  /* ---------------------------------------------------------------- small things */
  function countUp() {
    $$('[data-count]').forEach(function (el) {
      var to = parseInt(el.getAttribute('data-count'), 10) || 0;
      if (reduce || to < 2) { return; }
      var t0 = null, dur = Math.min(1200, 500 + to * 8);
      el.textContent = '0';
      function step(t) {
        if (!t0) { t0 = t; }
        var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(to * e).toLocaleString('en-IN');
        if (p < 1) { requestAnimationFrame(step); }
      }
      requestAnimationFrame(step);
    });
  }

  function toasts() {
    $$('.ds-toast').forEach(function (t) {
      var x = $('.ds-toast-x', t);
      var gone = function () { t.classList.add('is-gone'); setTimeout(function () { t.remove(); }, 320); };
      if (x) { x.addEventListener('click', gone); }
      if (t.classList.contains('ok')) { setTimeout(gone, 6500); }
    });
  }

  function dialogs() {
    document.addEventListener('click', function (e) {
      var o = e.target.closest('[data-open]');
      if (o) {
        var d = document.getElementById(o.getAttribute('data-open'));
        if (d && d.showModal) { e.preventDefault(); closeMenus(); d.showModal(); var f = $('input:not([type=hidden]),select,textarea', d); if (f) { setTimeout(function () { f.focus(); }, 60); } }
        return;
      }
      if (e.target.closest('[data-close]')) { var dl = e.target.closest('dialog'); if (dl) { dl.close(); } }
    });
    $$('dialog.ds-dlg').forEach(function (d) {
      d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
    });
  }

  function closeMenus(except) { $$('.ds-menu.open').forEach(function (m) { if (m !== except) { m.classList.remove('open'); var b = $('[data-menu]', m); if (b) { b.setAttribute('aria-expanded', 'false'); } } }); }
  function menus() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-menu]');
      if (b) { var m = b.closest('.ds-menu'); closeMenus(m); var on = m.classList.toggle('open'); b.setAttribute('aria-expanded', on ? 'true' : 'false'); e.preventDefault(); return; }
      if (!e.target.closest('.ds-menu-b')) { closeMenus(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenus(); } });
  }

  /* A confirm that looks like the portal, not the browser. */
  var confirmDlg = null;
  function askConfirm(msg, ok) {
    if (!confirmDlg) {
      confirmDlg = document.createElement('dialog');
      confirmDlg.className = 'ds-dlg';
      confirmDlg.innerHTML = '<header><h3>Please confirm</h3></header><div class="ds-dlg-b"><p data-m></p></div><footer><button type="button" class="ds-btn ds-btn-ghost" data-no>Cancel</button><button type="button" class="ds-btn ds-btn-primary" data-yes>Confirm</button></footer>';
      (document.querySelector('main.ds') || document.body).appendChild(confirmDlg);
      $('[data-no]', confirmDlg).addEventListener('click', function () { confirmDlg.close(); });
    }
    $('[data-m]', confirmDlg).textContent = msg;
    var yes = $('[data-yes]', confirmDlg), fresh = yes.cloneNode(true);
    yes.parentNode.replaceChild(fresh, yes);
    fresh.addEventListener('click', function () { confirmDlg.close(); ok(); });
    confirmDlg.showModal();
    fresh.focus();
  }
  function confirms() {
    document.addEventListener('submit', function (e) {
      var f = e.target, msg = f.getAttribute('data-confirm'), sub = e.submitter;
      if (sub && sub.getAttribute('data-confirm')) { msg = sub.getAttribute('data-confirm'); }
      if (!msg || f.dataset.confirmed) { return; }
      e.preventDefault();
      askConfirm(msg, function () {
        f.dataset.confirmed = '1';
        if (sub && sub.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = sub.name; h.value = sub.value; f.appendChild(h); }
        f.submit();
      });
    }, true);
  }

  function busy() {
    document.addEventListener('submit', function (e) {
      if (e.defaultPrevented) { return; }
      var f = e.target, b = e.submitter || $('button:not([type=button])', f);
      if (!b || f.dataset.sent) { if (f.dataset.sent) { e.preventDefault(); } return; }
      f.dataset.sent = '1';
      setTimeout(function () {
        b.classList.add('is-busy');
        if (b.getAttribute('data-busy')) { b.lastChild && b.lastChild.nodeType === 3 ? b.lastChild.textContent = b.getAttribute('data-busy') : b.append(' ' + b.getAttribute('data-busy')); }
      }, 0);
      setTimeout(function () { delete f.dataset.sent; b.classList.remove('is-busy'); }, 15000);
    });
  }

  function misc() {
    $$('select[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
    document.addEventListener('click', function (e) {
      var tr = e.target.closest('tr[data-href]');
      if (tr && !e.target.closest('a,button,input,select,label,form')) { window.location = tr.getAttribute('data-href'); }
      var c = e.target.closest('[data-copy]');
      if (c && navigator.clipboard) { navigator.clipboard.writeText(c.getAttribute('data-copy')).then(function () { c.classList.add('copied'); setTimeout(function () { c.classList.remove('copied'); }, 1600); }); }
    });
    // Monitor: select all + count
    var all = $('[data-checkall]');
    if (all) {
      var form = all.closest('form'), cnt = $('[data-bulkcount]', form);
      var upd = function () { var n = $$('input[name="req[]"]:checked', form).length; cnt.textContent = n + ' selected'; };
      all.addEventListener('change', function () { $$('input[name="req[]"]', form).forEach(function (x) { x.checked = all.checked; }); upd(); });
      form.addEventListener('change', upd);
    }
    // Client-side sort on report tables
    $$('table[data-sortable]').forEach(function (t) {
      $$('th', t).forEach(function (th, i) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
          var dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
          $$('th', t).forEach(function (x) { delete x.dataset.dir; });
          th.dataset.dir = dir;
          var rows = $$('tbody tr', t);
          rows.sort(function (a, b) {
            var x = a.children[i], y = b.children[i];
            var xv = x.getAttribute('data-v') !== null ? parseFloat(x.getAttribute('data-v')) : x.textContent.trim().toLowerCase();
            var yv = y.getAttribute('data-v') !== null ? parseFloat(y.getAttribute('data-v')) : y.textContent.trim().toLowerCase();
            return (xv > yv ? 1 : xv < yv ? -1 : 0) * (dir === 'asc' ? 1 : -1);
          });
          rows.forEach(function (r) { r.parentNode.appendChild(r); });
        });
      });
    });
    // Provider type switch
    $$('[data-provkind]').forEach(function (s) {
      var dlg = s.closest('dialog');
      var sw = function () { $$('[data-kind]', dlg).forEach(function (p) { p.hidden = p.getAttribute('data-kind') !== s.value; }); };
      s.addEventListener('change', sw); sw();
    });
    // Doc tabs without reload
    $$('[data-tabs]').forEach(function (box) {
      $$('[data-tab]', box).forEach(function (a) {
        a.addEventListener('click', function (e) {
          e.preventDefault();
          var k = a.getAttribute('data-tab');
          $$('[data-tab]', box).forEach(function (x) { x.classList.toggle('on', x === a); x.setAttribute('aria-selected', x === a ? 'true' : 'false'); });
          $$('[data-pane]', box).forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== k; });
          try { history.replaceState(null, '', a.href); } catch (err) { /* ignore */ }
        });
      });
    });
  }

  /* -------------------------------------------------------------- tooltips & charts */
  function tips() {
    var tip = document.getElementById('ds-tip');
    if (!tip) { return; }
    var show = function (el, x, y) { tip.textContent = el.getAttribute('data-tip'); tip.hidden = false; tip.style.left = x + 'px'; tip.style.top = y + 'px'; };
    document.addEventListener('mousemove', function (e) {
      var el = e.target.closest && e.target.closest('[data-tip]');
      if (!el) { tip.hidden = true; $$('.ds-line .xh').forEach(function (l) { l.setAttribute('hidden', ''); }); return; }
      if (el.classList.contains('hit')) {
        var svg = el.ownerSVGElement, xh = $('.xh', svg), x = el.getAttribute('data-x');
        xh.setAttribute('x1', x); xh.setAttribute('x2', x); xh.removeAttribute('hidden');
      }
      show(el, e.clientX, e.clientY);
    });
    document.addEventListener('focusin', function (e) {
      var el = e.target.closest && e.target.closest('[data-tip]');
      if (el) { var r = el.getBoundingClientRect(); show(el, r.left + r.width / 2, r.top); }
    });
  }

  /* ---------------------------------------------------------------------- drop zones */
  function drops() {
    $$('[data-drop]').forEach(function (z) {
      var inp = $('input[type=file]', z), lab = $('[data-drop-name]', z);
      ['dragenter', 'dragover'].forEach(function (t) { z.addEventListener(t, function (e) { e.preventDefault(); z.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { z.addEventListener(t, function () { z.classList.remove('is-over'); }); });
      z.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; inp.dispatchEvent(new Event('change', { bubbles: true })); }
      });
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        z.classList.toggle('has-file', !!f);
        if (lab) { lab.hidden = !f; lab.textContent = f ? '✓ ' + f.name + ' · ' + (f.size / 1048576).toFixed(2) + ' MB' : ''; }
      });
    });
  }

  /* ---------------------------------------------------------------------- pdf.js */
  var pdfjsP = null;
  function loadPdfJs() {
    if (pdfjsP) { return pdfjsP; }
    pdfjsP = new Promise(function (ok, bad) {
      if (window.pdfjsLib) { ok(window.pdfjsLib); return; }
      var s = document.createElement('script');
      s.src = PDFJS + 'pdf.min.js';
      s.onload = function () {
        var lib = window.pdfjsLib;
        try {
          // A worker must come from our own origin: wrap the CDN script in a blob that imports it.
          var blob = new Blob(['importScripts("' + PDFJS + 'pdf.worker.min.js");'], { type: 'application/javascript' });
          lib.GlobalWorkerOptions.workerPort = new Worker(URL.createObjectURL(blob));
        } catch (e) { lib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js'; }
        ok(lib);
      };
      s.onerror = bad;
      document.head.appendChild(s);
    });
    return pdfjsP;
  }

  /* Opens a PDF by byte ranges: the first page draws once its part has arrived,
     and the rest is fetched only as pages are reached. */
  function openPdf(lib, src) {
    var abs = new URL(src, window.location.href).href;
    return lib.getDocument({ url: abs, withCredentials: true, rangeChunkSize: 262144, disableAutoFetch: true, disableStream: true }).promise;
  }

  function viewers() {
    $$('[data-viewer]').forEach(function (v) {
      var box = $('[data-pages]', v), info = $('[data-pageinfo]', v), zoom = 1, doc = null, rendered = {};
      var placeable = v.getAttribute('data-placeable') === '1';
      var fields = JSON.parse(v.getAttribute('data-fields') || '[]'), nextAt = 0;
      var nxt = $('[data-nextfield]', v);
      if (nxt) {
        nxt.addEventListener('click', function () {
          if (!fields.length) { return; }
          var f = fields[nextAt % fields.length]; nextAt++;
          var holder = box.querySelector('.ds-page[data-n="' + (f.page + 1) + '"]');
          if (!holder) { return; }
          box.scrollTop = holder.offsetTop + holder.offsetHeight * f.y - box.clientHeight / 3;
          setTimeout(function () { var o = holder.querySelector('.ds-myfield[data-i="' + fields.indexOf(f) + '"]'); if (o) { o.classList.remove('flash'); void o.offsetWidth; o.classList.add('flash'); } }, 350);
        });
      }
      var fail = function (msg) { box.innerHTML = '<div class="ds-viewer-err">' + esc(msg) + ' <a href="' + esc(v.getAttribute('data-src')) + '" target="_blank" rel="noopener">Open the PDF</a></div>'; markRead(100); };
      var meter = $('[data-readmeter]', v);
      var maxRead = 0;
      function markRead(p) {
        maxRead = Math.max(maxRead, p);
        if (meter) { meter.querySelector('i').style.setProperty('--read', maxRead + '%'); }
        if (maxRead >= 98 && !v.classList.contains('is-read')) {
          v.classList.add('is-read');
          if (meter) { meter.querySelector('span').textContent = 'You have seen the whole document'; }
          document.dispatchEvent(new CustomEvent('ds:read'));
        }
      }
      box.addEventListener('scroll', function () {
        var p = (box.scrollTop + box.clientHeight) / Math.max(1, box.scrollHeight) * 100;
        markRead(Math.round(p));
      }, { passive: true });

      /* Pages come as images the server rendered (fast: a small picture per page,
         nothing to decode here) or, when it cannot render, through pdf.js. */
      var preview = v.getAttribute('data-preview') ? JSON.parse(v.getAttribute('data-preview')) : null;
      var pageW = function () { return Math.min(box.clientWidth - 36, 900) * zoom; };
      function decorate(holder, n) {
        clearTimeout(v._fit);
        // A document short enough to show whole without scrolling has been seen once its pages are drawn.
        v._fit = setTimeout(function () { if (box.scrollHeight <= box.clientHeight + 10) { markRead(100); } }, 700);
        if (!$('.ds-page-n', holder)) {
          var n2 = document.createElement('span'); n2.className = 'ds-page-n'; n2.textContent = n + ' / ' + doc.numPages; holder.appendChild(n2);
        }
        fields.forEach(function (f, i) {
          if (f.page !== n - 1 || holder.querySelector('.ds-myfield[data-i="' + i + '"]')) { return; }
          var o = document.createElement('div');
          o.className = 'ds-myfield k-' + f.kind; o.dataset.i = i;
          o.style.left = (f.x * 100) + '%'; o.style.top = (f.y * 100) + '%'; o.style.width = (f.w * 100) + '%'; o.style.height = (f.h * 100) + '%';
          o.innerHTML = '<span>' + esc(f.label) + '</span>';
          holder.appendChild(o);
        });
      }
      function renderPage(n, holder) {
        if (preview) {
          if (holder.querySelector('img')) { return; }
          var img = new Image();
          img.className = 'ds-pageimg'; img.alt = 'Page ' + n; img.decoding = 'async'; img.draggable = false;
          img.onload = function () { var sk = $('.ds-skel', holder); if (sk) { sk.remove(); } holder.classList.add('is-loaded'); };
          img.onerror = function () { if (!v._fell) { v._fell = 1; preview = null; start(); } };
          img.src = preview.img + n;
          holder.insertBefore(img, holder.firstChild);
          decorate(holder, n);
          return;
        }
        if (rendered[n + ':' + zoom]) { return; }
        rendered[n + ':' + zoom] = 1;
        doc.getPage(n).then(function (page) {
          var w = pageW();
          var vp1 = page.getViewport({ scale: 1 }), scale = w / vp1.width, dpr = Math.min(window.devicePixelRatio || 1, 1.5);
          var vp = page.getViewport({ scale: scale * dpr });
          var c = document.createElement('canvas');
          c.width = vp.width; c.height = vp.height; c.style.width = (vp.width / dpr) + 'px';
          holder.style.width = (vp.width / dpr) + 'px';
          holder.innerHTML = '';
          holder.style.minHeight = '';
          holder.appendChild(c);
          decorate(holder, n);
          page.render({ canvasContext: c.getContext('2d'), viewport: vp });
        });
      }
      function layout() {
        box.innerHTML = '';
        rendered = {};
        var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
          es.forEach(function (en) { if (en.isIntersecting) { renderPage(parseInt(en.target.dataset.n, 10), en.target); } });
        }, { root: box, rootMargin: preview ? '1400px 0px' : '600px 0px' }) : null;
        for (var i = 1; i <= doc.numPages; i++) {
          var h = document.createElement('div');
          h.className = 'ds-page'; h.dataset.n = i; h.style.setProperty('--i', Math.min(i, 6));
          var pw = pageW();
          h.style.width = pw + 'px';
          if (preview) {
            // The real page shape is known, so the layout never jumps as pictures arrive.
            var s = preview.sizes[i - 1] || [595, 842];
            h.style.aspectRatio = s[0] + ' / ' + s[1];
            h.innerHTML = '<div class="ds-skel abs"></div>';
          } else {
            // A placeholder of the page's size, so the scroll (and the read check) is real before it renders.
            h.style.minHeight = Math.round(pw * 1.3) + 'px';
            h.innerHTML = '<div class="ds-skel" style="width:100%"></div>';
          }
          box.appendChild(h);
          if (io) { io.observe(h); } else { renderPage(i, h); }
        }
      }
      function start() {
        if (preview) {
          doc = { numPages: preview.sizes.length };
          if (info) { info.textContent = '· ' + doc.numPages + ' page' + (doc.numPages === 1 ? '' : 's'); }
          layout();
          return;
        }
        loadPdfJs().then(function (lib) {
          return openPdf(lib, v.getAttribute('data-src'));
        }).then(function (d) {
          doc = d;
          if (info) { info.textContent = '· ' + d.numPages + ' page' + (d.numPages === 1 ? '' : 's'); }
          layout();
        }).catch(function () { fail('The preview could not be shown here.'); });
      }
      start();
      $$('[data-zoom]', v).forEach(function (b) {
        b.addEventListener('click', function () {
          zoom = Math.max(.5, Math.min(2.5, zoom + parseInt(b.getAttribute('data-zoom'), 10) * .2));
          if (!doc) { return; }
          if (preview) {
            $$('.ds-page', box).forEach(function (h) { h.style.width = pageW() + 'px'; });
          } else {
            layout();
          }
        });
      });
      if (placeable) { placement(v, box); }
    });
  }

  /* Choosing where the signature goes: a box dropped on the page and dragged. */
  function placement(v, box) {
    var sb = null, W = 0.28, H = 0.075;
    function setVal(page, x, y) {
      var val = JSON.stringify({ page: page - 1, x: +x.toFixed(4), y: +y.toFixed(4), w: W, h: H });
      $$('[data-placement]').forEach(function (i) { i.value = val; });
      v.classList.add('is-placed');
      var hint = $('[data-placehint] span', v);
      if (hint) { hint.innerHTML = '<strong>Placed on page ' + page + '.</strong> Drag the box to adjust, or click elsewhere to move it.'; }
    }
    function put(holder, fx, fy) {
      var r = holder.getBoundingClientRect();
      var x = Math.max(0, Math.min(1 - W, fx - W / 2)), y = Math.max(0, Math.min(1 - H, fy - H / 2));
      if (!sb) { sb = document.createElement('div'); sb.className = 'ds-sigbox'; sb.setAttribute('role', 'img'); sb.setAttribute('aria-label', 'Signature position'); drag(sb); }
      if (sb.parentNode !== holder) { holder.appendChild(sb); }
      sb.style.left = (x * 100) + '%'; sb.style.top = (y * 100) + '%'; sb.style.width = (W * 100) + '%'; sb.style.height = (H * 100) + '%';
      sb.dataset.x = x; sb.dataset.y = y;
      setVal(parseInt(holder.dataset.n, 10), x, y);
      void r;
    }
    function drag(el) {
      var start = null;
      el.addEventListener('pointerdown', function (e) {
        e.preventDefault(); e.stopPropagation();
        el.setPointerCapture(e.pointerId);
        start = { x: e.clientX, y: e.clientY, ox: parseFloat(el.dataset.x), oy: parseFloat(el.dataset.y) };
      });
      el.addEventListener('pointermove', function (e) {
        if (!start) { return; }
        var r = el.parentNode.getBoundingClientRect();
        var x = Math.max(0, Math.min(1 - W, start.ox + (e.clientX - start.x) / r.width));
        var y = Math.max(0, Math.min(1 - H, start.oy + (e.clientY - start.y) / r.height));
        el.style.left = (x * 100) + '%'; el.style.top = (y * 100) + '%'; el.dataset.x = x; el.dataset.y = y;
      });
      el.addEventListener('pointerup', function () { if (start) { setVal(parseInt(el.parentNode.dataset.n, 10), parseFloat(el.dataset.x), parseFloat(el.dataset.y)); } start = null; });
    }
    box.addEventListener('click', function (e) {
      var holder = e.target.closest('.ds-page');
      if (!holder || e.target.closest('.ds-sigbox') || !$('canvas,img', holder)) { return; }
      var r = holder.getBoundingClientRect();
      put(holder, (e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
    });
  }

  /* Signing is never locked behind scrolling: the reading meter only informs. */
  function readGate() {}

  /* ---------------------------------------------------------------- signing panel */
  function methods() {
    var radios = $$('input[name="ds-method"]');
    if (!radios.length) { return; }
    var show = function () {
      var m = (radios.filter(function (r) { return r.checked; })[0] || {}).value;
      $$('[data-mpane]').forEach(function (p) { p.hidden = p.getAttribute('data-mpane') !== m; });
    };
    radios.forEach(function (r) { r.addEventListener('change', show); });
    show();
  }

  function pads() {
    $$('[data-pad]').forEach(function (cv) {
      var wrap = cv.closest('.ds-pad'), form = cv.closest('form'), out = $('[data-pad-out]', form);
      var box = cv.closest('[data-padbox]') || wrap, optional = box.hasAttribute('data-pad-optional');
      var ctx = cv.getContext('2d'), strokes = [], cur = null, dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 3));
      var mode = 'draw', upload = null;
      function size() {
        var r = cv.getBoundingClientRect();
        if (!r.width) { return; }
        cv.width = Math.round(r.width * dpr); cv.height = Math.round(r.height * dpr);
        redraw();
      }
      function drawStroke(s) {
        if (s.length < 2) { ctx.beginPath(); ctx.arc(s[0].x * dpr, s[0].y * dpr, 1.4 * dpr, 0, 6.3); ctx.fill(); return; }
        ctx.beginPath(); ctx.moveTo(s[0].x * dpr, s[0].y * dpr);
        for (var i = 1; i < s.length - 1; i++) {
          var mx = (s[i].x + s[i + 1].x) / 2, my = (s[i].y + s[i + 1].y) / 2;
          ctx.lineWidth = Math.max(1.2, Math.min(3.4, 3.4 - (s[i].v || 0) * 1.2)) * dpr;
          ctx.quadraticCurveTo(s[i].x * dpr, s[i].y * dpr, mx * dpr, my * dpr);
          ctx.stroke(); ctx.beginPath(); ctx.moveTo(mx * dpr, my * dpr);
        }
        ctx.lineTo(s[s.length - 1].x * dpr, s[s.length - 1].y * dpr); ctx.stroke();
      }
      function redraw() {
        ctx.clearRect(0, 0, cv.width, cv.height);
        ctx.strokeStyle = '#0f2f73'; ctx.fillStyle = '#0f2f73'; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        strokes.forEach(drawStroke);
        wrap.classList.toggle('is-empty', !strokes.length);
      }
      function pt(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top, t: Date.now() }; }
      cv.addEventListener('pointerdown', function (e) { e.preventDefault(); cv.setPointerCapture(e.pointerId); cur = [pt(e)]; strokes.push(cur); redraw(); });
      cv.addEventListener('pointermove', function (e) {
        if (!cur) { return; }
        var p = pt(e), l = cur[cur.length - 1], d = Math.hypot(p.x - l.x, p.y - l.y);
        if (d < 1.2) { return; }
        p.v = Math.min(2, d / Math.max(1, p.t - l.t));
        cur.push(p); redraw();
      });
      ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { cv.addEventListener(t, function () { cur = null; }); });
      $('[data-pad-clear]', wrap).addEventListener('click', function () { strokes = []; redraw(); });
      $('[data-pad-undo]', wrap).addEventListener('click', function () { strokes.pop(); redraw(); });
      window.addEventListener('resize', size);
      size();

      // Draw | Upload image
      $$('input[data-padmode]', box).forEach(function (r) {
        r.addEventListener('change', function () {
          mode = r.value;
          $$('[data-padpane]', box).forEach(function (p) { p.hidden = p.getAttribute('data-padpane') !== mode; });
          if (mode === 'draw') { setTimeout(size, 0); }
        });
      });
      var file = $('[data-pad-file]', box), prev = $('[data-pad-preview]', box), note = $('[data-pad-note]', box);
      if (file) {
        file.addEventListener('change', function () {
          var f = file.files && file.files[0];
          upload = null;
          if (!f) { return; }
          if (f.type && !/^image\//i.test(f.type)) { note.textContent = 'Choose an image file (PNG or JPG).'; return; }
          var rd = new FileReader();
          rd.onload = function () {
            var im = new Image();
            im.onload = function () {
              // Scale down, find how bright the paper is, keep only clearly darker ink (photo shadows and
              // paper texture drop out), paint it one colour, and trim to it.
              var k = Math.min(1, 1200 / Math.max(im.width, 1), 900 / Math.max(im.height, 1)), w = Math.max(1, Math.round(im.width * k)), h = Math.max(1, Math.round(im.height * k));
              var c = document.createElement('canvas'); c.width = w; c.height = h;
              var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, w, h); x.drawImage(im, 0, 0, w, h);
              var d = x.getImageData(0, 0, w, h), px = d.data, n = w * h, lums = new Uint8Array(n), hist = new Uint32Array(256);
              for (var i = 0, j = 0; i < px.length; i += 4, j++) { var l = Math.round(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]); lums[j] = l; hist[l]++; }
              var acc = 0, paper = 255; for (var q = 0; q < 256; q++) { acc += hist[q]; if (acc >= n * 0.6) { paper = q; break; } }
              var cut = Math.max(40, paper - 70), soft = Math.max(cut + 1, paper - 35);
              var rows = new Uint32Array(h), cols = new Uint32Array(w);
              for (var p2 = 0; p2 < n; p2++) {
                var o = p2 * 4, lv = lums[p2];
                if (lv >= soft) { px[o + 3] = 0; continue; }
                var al = lv <= cut ? 255 : Math.round(255 * (soft - lv) / (soft - cut));
                px[o] = 15; px[o + 1] = 47; px[o + 2] = 115; px[o + 3] = al;
                if (al > 128) { rows[Math.floor(p2 / w)]++; cols[p2 % w]++; }
              }
              // Trim to rows / columns that really hold ink (ignores stray specks at the edges).
              var minC = Math.max(2, Math.round(Math.min(w, h) * 0.004)), minX = 0, maxX = w - 1, minY = 0, maxY = h - 1;
              while (minY < h && rows[minY] < minC) { minY++; } while (maxY > minY && rows[maxY] < minC) { maxY--; }
              while (minX < w && cols[minX] < minC) { minX++; } while (maxX > minX && cols[maxX] < minC) { maxX--; }
              if (minY >= h || minX >= w || maxX - minX < 10 || maxY - minY < 5) { note.textContent = 'No signature was found in that image. Use a dark signature on light paper, or draw it instead.'; prev.hidden = true; return; }
              x.putImageData(d, 0, 0);
              var m = 6, tw = maxX - minX + m * 2, th = maxY - minY + m * 2, s2 = Math.min(1, 800 / tw, 300 / th);
              var t = document.createElement('canvas');
              t.width = Math.max(60, Math.round(tw * s2)); t.height = Math.max(24, Math.round(th * s2));
              t.getContext('2d').drawImage(c, minX - m, minY - m, tw, th, 0, 0, Math.round(tw * s2), Math.round(th * s2));
              upload = t.toDataURL('image/png');
              prev.src = upload; prev.hidden = false;
              note.textContent = 'Background removed. This is how your signature will appear.';
            };
            im.onerror = function () { note.textContent = 'That image could not be read.'; };
            im.src = rd.result;
          };
          rd.readAsDataURL(f);
        });
      }

      form.addEventListener('submit', function (e) {
        var shake = function () {
          e.preventDefault(); e.stopImmediatePropagation();
          if (box.animate && !reduce) { box.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }], 300); }
        };
        if (mode === 'upload') {
          if (!upload) { if (optional) { out.value = ''; return; } note.textContent = 'Choose your signature image first.'; shake(); return; }
          out.value = upload;
          return;
        }
        var ink = strokes.reduce(function (n, s) { return n + s.length; }, 0);
        if (ink < 12) {
          if (optional && ink === 0) { out.value = ''; return; }
          wrap.classList.add('is-empty'); shake(); return;
        }
        // Trim to the ink, on a transparent background.
        var xs = [], ys = [];
        strokes.forEach(function (s) { s.forEach(function (p) { xs.push(p.x); ys.push(p.y); }); });
        var pad = 8, x0 = Math.max(0, Math.min.apply(null, xs) - pad), y0 = Math.max(0, Math.min.apply(null, ys) - pad);
        var x1 = Math.max.apply(null, xs) + pad, y1 = Math.max.apply(null, ys) + pad;
        var o = document.createElement('canvas'), k = 2;
        o.width = Math.max(80, Math.round((x1 - x0) * k)); o.height = Math.max(30, Math.round((y1 - y0) * k));
        o.getContext('2d').drawImage(cv, x0 * dpr, y0 * dpr, (x1 - x0) * dpr, (y1 - y0) * dpr, 0, 0, o.width, o.height);
        out.value = o.toDataURL('image/png');
      }, true);
    });
  }

  /* ---------------------------------------------------------------- wizard */
  function wizard() {
    var w = $('[data-wizard]');
    if (!w) { return; }
    var panes = $$('[data-step]', w), steps = $$('.ds-wsteps li', w), cur = 1;
    function go(n) {
      if (n > cur) {
        for (var s = cur; s < n; s++) { if (!valid(s)) { return; } }
      }
      cur = n;
      panes.forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-step'), 10) !== n; });
      steps.forEach(function (li, i) { li.classList.toggle('on', i + 1 === n); li.classList.toggle('done', i + 1 < n); });
      if (n === 4) { review(); }
      if (n === 3) { suggest(); }
      window.scrollTo({ top: w.getBoundingClientRect().top + window.scrollY - 90, behavior: reduce ? 'auto' : 'smooth' });
    }
    function valid(s) {
      var p = $('[data-step="' + s + '"]', w), bad = null;
      $$('input,select,textarea', p).forEach(function (f) { if (!bad && f.willValidate && !f.checkValidity()) { bad = f; } });
      if (bad) { bad.reportValidity(); return false; }
      return true;
    }
    $$('[data-next]', w).forEach(function (b) { b.addEventListener('click', function () { go(cur + 1); }); });
    $$('[data-prev]', w).forEach(function (b) { b.addEventListener('click', function () { go(cur - 1); }); });
    steps.forEach(function (li, i) { li.addEventListener('click', function () { go(i + 1); }); });
    var inp = $('[data-pdf-input]', w), title = $('[data-title]', w), mini = $('[data-pdf-mini]', w);
    inp.addEventListener('change', function () {
      var f = inp.files[0];
      if (!f) { return; }
      if (title && !title.value) { title.value = f.name.replace(/\.pdf$/i, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim(); }
      mini.innerHTML = '<div class="ds-skel" style="width:70%"></div>';
      f.arrayBuffer().then(function (buf) {
        return loadPdfJs().then(function (lib) { return lib.getDocument({ data: buf }).promise; });
      }).then(function (d) {
        return d.getPage(1).then(function (pg) {
          var vp1 = pg.getViewport({ scale: 1 }), sc = Math.min(1.4, (mini.clientWidth - 40) / vp1.width), vp = pg.getViewport({ scale: sc * 2 });
          var c = document.createElement('canvas'); c.width = vp.width; c.height = vp.height; c.style.width = (vp.width / 2) + 'px';
          mini.innerHTML = ''; mini.appendChild(c);
          var cap = document.createElement('div'); cap.className = 'ds-muted small'; cap.style.marginTop = '8px'; cap.textContent = d.numPages + ' page' + (d.numPages === 1 ? '' : 's');
          mini.appendChild(cap);
          return pg.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
        });
      }).catch(function () { mini.innerHTML = '<div class="ds-pdfmini-e">This file could not be previewed. Check it is a PDF.</div>'; });
    });
    function review() {
      var g = function (n) { var f = w.elements[n]; if (!f) { return ''; } return f.tagName === 'SELECT' ? (f.options[f.selectedIndex] || {}).text || '' : f.value; };
      var route = (w.querySelector('input[name=route]:checked') || {}).value, who = '';
      if (route === 'workflow') {
        var wf = w.querySelector('input[name=workflow_id]:checked');
        who = wf ? esc(wf.closest('label').querySelector('strong').textContent) : '<span class="ds-red">No workflow chosen</span>';
      } else {
        who = $$('.ds-signer', w).map(function (li) { var s = $('[data-f=person_id]', li); return s.value ? 'Step ' + esc($('[data-f=order]', li).value) + ': ' + (s.value === 'ext' ? esc(($('[data-f=ext_name]', li).value || 'External') + ' <' + $('[data-f=ext_email]', li).value + '>') + ' — external, e-mailed link' : esc(s.options[s.selectedIndex].text)) + ' (' + esc($('[data-f=action]', li).value) + ')' : ''; }).filter(Boolean).join('<br>') || '<span class="ds-red">No signatories</span>';
      }
      $('[data-review]', w).innerHTML = '<dl><dt>File</dt><dd>' + esc(inp.files[0] ? inp.files[0].name : '—') + '</dd><dt>Title</dt><dd>' + esc(g('title')) + '</dd><dt>Category</dt><dd>' + esc(g('category_id'))
        + '</dd><dt>Department</dt><dd>' + esc(g('department_id') || '—') + '</dd><dt>Owner</dt><dd>' + esc(g('owner_id')) + '</dd><dt>Priority · confidentiality</dt><dd>' + esc(g('priority')) + ' · ' + esc(g('confidentiality'))
        + '</dd><dt>Valid until · deadline</dt><dd>' + esc(g('valid_until') || '—') + ' · ' + esc(g('deadline') || '—') + '</dd><dt>Route</dt><dd>' + who + '</dd></dl>';
    }
    function suggest() { var r = $('[data-route]', w); if (r && r._suggest) { r._suggest(); } }
  }

  /* ---------------------------------------------------------------- routing picker */
  function routes() {
    $$('[data-route]').forEach(function (r) {
      var data = JSON.parse($('[data-workflows]', r).textContent), form = r.closest('form');
      var panes = { workflow: $('[data-pane=workflow]', r), adhoc: $('[data-pane=adhoc]', r) };
      $$('input[name=route]', r).forEach(function (x) { x.addEventListener('change', function () { panes.workflow.hidden = x.value !== 'workflow'; panes.adhoc.hidden = x.value !== 'adhoc'; }); });
      var prev = $('[data-wfpreview]', r), tpl = $('[data-chosen-tpl]', r);
      function docFields() {
        var get = function (k) { var f = form.querySelector('[data-cond=' + k + ']'); return f ? f.value : ''; };
        return { category: parseInt(get('category'), 10) || 0, department: parseInt(get('department'), 10) || 0, unit: get('unit'), confidentiality: get('confidentiality'), value: get('value') === '' ? null : parseFloat(get('value').replace(/,/g, '')) };
      }
      function match(c, d) {
        if (!c) { return true; }
        if (c.categories && c.categories.indexOf(d.category) < 0) { return false; }
        if (c.departments && c.departments.indexOf(d.department) < 0) { return false; }
        if (c.units && c.units.indexOf(d.unit) < 0) { return false; }
        if (c.confidentiality && c.confidentiality.indexOf(d.confidentiality) < 0) { return false; }
        if (c.min_value != null && (d.value == null || d.value < c.min_value)) { return false; }
        if (c.max_value != null && d.value != null && d.value > c.max_value) { return false; }
        return true;
      }
      function draw() {
        if (!prev) { return; }
        var sel = r.querySelector('input[name=workflow_id]:checked');
        if (!sel) { prev.innerHTML = ''; return; }
        var wf = data.workflows.filter(function (x) { return String(x.id) === sel.value; })[0], d = form.querySelector('[data-cond]') ? docFields() : null;
        prev.innerHTML = '';
        wf.stages.forEach(function (s, i) {
          var skip = d && s.condition && Object.keys(s.condition).length && !match(s.condition, d);
          var el = document.createElement('div');
          el.className = 'ds-wfprev-stage' + (skip ? ' is-skip' : ''); el.style.animationDelay = (i * 60) + 'ms';
          var html = '<span>' + (i + 1) + '</span><div><strong>' + esc(s.name) + '</strong>' + (s.cond_text ? ' <small class="ds-muted">· if ' + esc(s.cond_text) + (skip ? ' — skipped for this document' : '') + '</small>' : '')
            + (s.sigs.length > 1 ? ' <small class="ds-muted">· ' + (s.completion === 'any' ? 'any one' : 'in parallel') + '</small>' : '') + '<ul>';
          s.sigs.forEach(function (g) {
            html += '<li>' + esc(g.action) + ' · ' + (g.type === 'chosen' ? '<label>' + esc(g.label || 'Choose') + ': <span data-slot="' + esc(g.slot) + '"></span></label>' : '<strong>' + esc(g.who) + '</strong>')
              + ' · <span class="ds-muted">' + esc(g.method) + (g.mandatory ? '' : ' · optional') + '</span></li>';
          });
          el.innerHTML = html + '</ul></div>';
          prev.appendChild(el);
        });
        $$('[data-slot]', prev).forEach(function (sp) {
          var s = tpl.content.firstElementChild.cloneNode(true);
          s.name = 'chosen[' + sp.getAttribute('data-slot') + ']'; s.required = true;
          sp.appendChild(s);
        });
      }
      r._suggest = function () {
        var d = form.querySelector('[data-cond]') ? docFields() : null, pick = data.suggest;
        if (d) {
          pick = data.catDefaults[d.category] || null;
          if (pick) { var w0 = data.workflows.filter(function (x) { return x.id === pick; })[0]; if (!w0 || !match(w0.conditions, d)) { pick = null; } }
          if (!pick) { for (var i = 0; i < data.workflows.length; i++) { if (match(data.workflows[i].conditions, d)) { pick = data.workflows[i].id; break; } } }
        }
        $$('.ds-wf-sug', r).forEach(function (s) { s.hidden = true; });
        if (pick) {
          var inp = r.querySelector('input[name=workflow_id][value="' + pick + '"]');
          if (inp) { if (!r.querySelector('input[name=workflow_id]:checked') || r._auto) { inp.checked = true; r._auto = true; } $('.ds-wf-sug', inp.closest('label')).hidden = false; }
        }
        draw();
      };
      $$('input[name=workflow_id]', r).forEach(function (x) { x.addEventListener('change', function () { r._auto = false; draw(); }); });
      r._suggest();
      // Signatories built for this document
      var list = $('[data-signers]', r), stpl = $('[data-signer-tpl]', r);
      if (!list) { return; }
      function add(order) {
        var li = stpl.content.firstElementChild.cloneNode(true);
        $('[data-f=order]', li).value = order || (list.children.length ? Math.max.apply(null, $$('[data-f=order]', list).map(function (x) { return parseInt(x.value, 10) || 1; })) + 1 : 1);
        $('[data-remove]', li).addEventListener('click', function () { li.remove(); });
        // Someone outside the organisation: name and e-mail instead of a person; they sign on an e-mailed link.
        var who = $('[data-signer-who]', li), ext = $('[data-ext]', li), meth = $('[data-f=method]', li);
        if (who && ext) {
          who.addEventListener('change', function () {
            var on = who.value === 'ext';
            ext.hidden = !on; li.classList.toggle('is-ext', on);
            $('[data-f=ext_name]', ext).required = on; $('[data-f=ext_email]', ext).required = on;
            if (meth) { meth.hidden = on; }
            if (on) { $('[data-f=ext_name]', ext).focus(); }
          });
        }
        li.draggable = true;
        li.addEventListener('dragstart', function () { li.classList.add('dragging'); });
        li.addEventListener('dragend', function () { li.classList.remove('dragging'); renumber(); });
        list.appendChild(li);
        return li;
      }
      function renumber() {
        // Keep parallel groups together: dragging changes order, equal steps stay equal.
        var last = 0, prevVal = null;
        $$('.ds-signer', list).forEach(function (li) {
          var o = $('[data-f=order]', li), v = parseInt(o.value, 10) || 1;
          if (prevVal !== null && v === prevVal) { o.value = last; } else { last += 1; o.value = last; }
          prevVal = v;
        });
      }
      list.addEventListener('dragover', function (e) {
        e.preventDefault();
        var drag = $('.dragging', list), after = $$('.ds-signer:not(.dragging)', list).filter(function (li) { var b = li.getBoundingClientRect(); return e.clientY < b.top + b.height / 2; })[0];
        if (drag) { if (after) { list.insertBefore(drag, after); } else { list.appendChild(drag); } }
      });
      $('[data-add-signer]', r).addEventListener('click', function () { add(); });
      add(1);
      form.addEventListener('submit', function () {
        $$('.ds-signer', list).forEach(function (li, i) {
          $$('[data-f]', li).forEach(function (f) { f.name = 'signers[' + i + '][' + f.getAttribute('data-f') + ']'; });
        });
      }, true);
    });
  }

  /* ---------------------------------------------------------------- workflow builder */
  function builder() {
    var form = $('[data-wfbuilder]');
    if (!form) { return; }
    var st = JSON.parse($('[data-wf-state]', form).textContent), meta = JSON.parse($('[data-wf-meta]', form).textContent);
    var opts = function (map, sel, blank) {
      var h = blank !== undefined ? '<option value="">' + esc(blank) + '</option>' : '';
      Object.keys(map).forEach(function (k) { h += '<option value="' + esc(k) + '"' + (String(sel) === String(k) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; });
      return h;
    };
    var mopts = function (map, sel) { sel = (sel || []).map(String); return Object.keys(map).map(function (k) { return '<option value="' + esc(k) + '"' + (sel.indexOf(String(k)) >= 0 ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join(''); };
    var unitsMap = {}; (meta.units || []).forEach(function (u) { unitsMap[u] = u; }); var units = Object.keys(meta.units || {}).length ? meta.units : {};
    // meta.units arrives as an object (JSON_FORCE_OBJECT): index => name
    unitsMap = {}; Object.keys(units).forEach(function (k) { unitsMap[units[k]] = units[k]; });
    function condEditor(c, cls) {
      c = c || {};
      return '<div class="ds-condgrid ' + (cls || '') + '">'
        + '<label>Categories<select multiple data-c="categories">' + mopts(meta.categories, c.categories) + '</select></label>'
        + '<label>Departments<select multiple data-c="departments">' + mopts(meta.departments, c.departments) + '</select></label>'
        + (Object.keys(unitsMap).length ? '<label>Units / spokes<select multiple data-c="units">' + mopts(unitsMap, c.units) + '</select></label>' : '')
        + '<label>Confidentiality<select multiple data-c="confidentiality">' + mopts(meta.confidentiality, c.confidentiality) + '</select></label>'
        + '<div class="ds-row2"><label>Value from (₹)<input data-c="min_value" value="' + esc(c.min_value == null ? '' : c.min_value) + '" inputmode="decimal"></label>'
        + '<label>Value up to (₹)<input data-c="max_value" value="' + esc(c.max_value == null ? '' : c.max_value) + '" inputmode="decimal"></label></div></div>';
    }
    function readCond(el) {
      var c = {};
      $$('[data-c]', el).forEach(function (f) {
        var k = f.getAttribute('data-c');
        if (f.multiple) { var v = $$('option:checked', f).map(function (o) { return o.value; }); if (v.length) { c[k] = v; } } else if (f.value.trim() !== '') { c[k] = f.value.trim(); }
      });
      return c;
    }
    var stagesEl = $('[data-stages]', form), condsEl = $('[data-conds]', form);
    condsEl.innerHTML = '<p class="ds-hint">A document meeting all of these gets this workflow suggested. Leave empty for any document.</p>' + condEditor(st.conditions);
    $$('[data-k]', form).forEach(function (f) {
      var k = f.getAttribute('data-k'), v = st[k];
      if (f.type === 'checkbox') { f.checked = !!parseInt(v, 10); } else { f.value = v == null ? '' : v; }
    });
    var escSel = form.querySelector('[data-k=escalate_to]'), escP = form.querySelector('.ds-if-person');
    var escSw = function () { escP.hidden = escSel.value !== 'person'; };
    escSel.addEventListener('change', escSw); escSw();
    function sigRow(g) {
      var d = document.createElement('div');
      d.className = 'ds-wsig';
      d.innerHTML = '<select data-g="type" aria-label="Who">' + opts(meta.types, g.type) + '</select><span class="ds-who-slot" data-slot></span>'
        + '<select data-g="action" aria-label="Action">' + opts(meta.actions, g.action) + '</select><select data-g="method" aria-label="Method">' + opts(meta.methods, g.method) + '</select>'
        + '<label class="ds-check"><input type="checkbox" data-g="mandatory"' + (parseInt(g.mandatory, 10) ? ' checked' : '') + '> Mandatory</label>'
        + '<button type="button" class="ds-icobtn" data-del aria-label="Remove">✕</button>';
      var slot = $('[data-slot]', d), type = $('[data-g=type]', d);
      var fill = function () {
        var t = type.value;
        if (t === 'person') { slot.innerHTML = '<select data-g="person_id" aria-label="Person">' + opts(meta.people, g.person_id, 'Choose a person…') + '</select>'; }
        else if (t === 'authority') {
          slot.innerHTML = '<input data-g="authority" list="ds-auth-list" placeholder="e.g. Department Head" value="' + esc(g.authority || '') + '" aria-label="Authority"><select data-g="scope" aria-label="Scope"><option value="doc_dept"' + (g.scope !== 'any' ? ' selected' : '') + '>in its department</option><option value="any"' + (g.scope === 'any' ? ' selected' : '') + '>anywhere</option></select>';
        } else if (t === 'chosen') { slot.innerHTML = '<input data-g="label" placeholder="What to call this slot" value="' + esc(g.label || '') + '" aria-label="Label">'; }
        else { slot.innerHTML = '<span class="ds-muted small">' + esc(meta.types[t]) + '</span>'; }
      };
      type.addEventListener('change', fill); fill();
      $('[data-del]', d).addEventListener('click', function () { var box = d.parentNode; d.remove(); if (!box.children.length) { box.appendChild(sigRow({ type: 'person', action: 'sign', method: 'any', mandatory: 1 })); } });
      return d;
    }
    function stageEl(s) {
      var el = document.createElement('section');
      el.className = 'ds-stage';
      el.innerHTML = '<div class="ds-stage-h"><span class="ds-stage-n"></span><input data-s="name" value="' + esc(s.name) + '" maxlength="120" aria-label="Stage name" required>'
        + '<select data-s="completion" aria-label="Completes when"><option value="all"' + (s.completion !== 'any' ? ' selected' : '') + '>All mandatory act</option><option value="any"' + (s.completion === 'any' ? ' selected' : '') + '>Any one acts</option></select>'
        + '<input data-s="deadline_days" type="number" min="1" max="365" placeholder="Days" value="' + esc(s.deadline_days == null ? '' : s.deadline_days) + '" style="width:90px" aria-label="Days for this stage">'
        + '<button type="button" class="ds-icobtn" data-up aria-label="Move up">↑</button><button type="button" class="ds-icobtn" data-down aria-label="Move down">↓</button><button type="button" class="ds-icobtn" data-rm aria-label="Remove stage">✕</button></div>'
        + '<div class="ds-stage-sigs"></div><div class="ds-stage-f"><button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" data-addsig>+ Add a person (parallel)</button>'
        + '<details class="ds-stage-cond"' + (s.condition && Object.keys(s.condition).length ? ' open' : '') + '><summary>Only when…</summary>' + condEditor(s.condition) + '</details></div>';
      var sigs = $('.ds-stage-sigs', el);
      (s.signatories || []).forEach(function (g) { sigs.appendChild(sigRow(g)); });
      if (!sigs.children.length) { sigs.appendChild(sigRow({ type: 'person', action: 'sign', method: 'any', mandatory: 1 })); }
      $('[data-addsig]', el).addEventListener('click', function () { sigs.appendChild(sigRow({ type: 'person', action: 'sign', method: 'any', mandatory: 1 })); });
      $('[data-rm]', el).addEventListener('click', function () { if (stagesEl.children.length > 1) { el.remove(); number(); } });
      $('[data-up]', el).addEventListener('click', function () { if (el.previousElementSibling) { stagesEl.insertBefore(el, el.previousElementSibling); number(); } });
      $('[data-down]', el).addEventListener('click', function () { if (el.nextElementSibling) { stagesEl.insertBefore(el.nextElementSibling, el); number(); } });
      return el;
    }
    function number() { $$('.ds-stage', stagesEl).forEach(function (s, i) { $('.ds-stage-n', s).textContent = i + 1; }); }
    (st.stages || []).forEach(function (s) { stagesEl.appendChild(stageEl(s)); });
    number();
    $('[data-add-stage]', form).addEventListener('click', function () {
      var el = stageEl({ name: 'Stage ' + (stagesEl.children.length + 1), completion: 'all', signatories: [] });
      stagesEl.appendChild(el); number();
      el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    });
    var dl = document.createElement('datalist'); dl.id = 'ds-auth-list';
    (Object.keys(meta.authorities || {}).map(function (k) { return meta.authorities[k]; }).concat(['Department Head', 'Manager', 'Finance Controller', 'CEO', 'Director', 'Hub Head'])).forEach(function (a) { var o = document.createElement('option'); o.value = a; dl.appendChild(o); });
    form.appendChild(dl);
    form.addEventListener('submit', function () {
      var out = { conditions: readCond(condsEl), stages: [] };
      $$('[data-k]', form).forEach(function (f) { out[f.getAttribute('data-k')] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value; });
      $$('.ds-stage', stagesEl).forEach(function (s) {
        var st2 = { name: $('[data-s=name]', s).value, completion: $('[data-s=completion]', s).value, deadline_days: $('[data-s=deadline_days]', s).value, condition: readCond($('.ds-stage-cond', s)), signatories: [] };
        $$('.ds-wsig', s).forEach(function (g) {
          var o = {};
          $$('[data-g]', g).forEach(function (f) { o[f.getAttribute('data-g')] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value; });
          st2.signatories.push(o);
        });
        out.stages.push(st2);
      });
      $('[data-payload]', form).value = JSON.stringify(out);
    }, true);
  }


  /* ---------------------------------------------------------------- the field studio */
  function studio() {
    var root = $('[data-studio]');
    if (!root) { return; }
    var st = JSON.parse($('[data-studio-state]', root).textContent);
    var fields = {};
    Object.keys(st.fields || {}).forEach(function (k) { fields[k] = (st.fields[k] || []).slice(); });
    var recips = st.recipients, bySlot = {};
    recips.forEach(function (r) { bySlot[r.slot] = r; });
    var active = (recips.filter(function (r) { return !r.skip && r.action !== 'review'; })[0] || {}).slot;
    var box = $('[data-studio-pages]', root), list = $('[data-recips]', root), status = $('[data-studio-status]', root), out = $('[data-studio-out]');
    var armed = null, doc = null, zoom = 1, selected = null, saveT = null;
    var label = function (k) { return (st.kinds[k] || [k])[0]; };
    function count(slot) { return (fields[slot] || []).length; }
    function hasSig(slot) { return (fields[slot] || []).some(function (f) { return f.kind === 'signature'; }); }
    function drawList() {
      list.innerHTML = '';
      recips.forEach(function (r) {
        var li = document.createElement('li');
        var off = r.skip || r.action === 'review';
        li.className = 'ds-recip c' + r.color + (r.slot === active ? ' on' : '') + (off ? ' is-off' : '');
        li.innerHTML = '<span class="ds-recip-av">' + esc(r.initials) + '</span><span class="ds-recip-b"><strong>' + esc(r.name) + '</strong><small>Step ' + r.stage + ' · ' + esc(r.action_label)
          + (r.skip ? ' · stage skipped for this document' : '') + (r.action === 'review' ? ' · reviews only, no fields' : '') + (r.error ? ' · <em>' + esc(r.error) + '</em>' : '') + '</small></span>'
          + (off ? '' : '<span class="ds-recip-n' + (hasSig(r.slot) ? ' ok' : '') + '" title="Fields placed">' + count(r.slot) + '</span>');
        if (!off) {
          li.tabIndex = 0;
          li.addEventListener('click', function () { active = r.slot; drawList(); });
          li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { li.click(); } });
        }
        list.appendChild(li);
      });
      var a = bySlot[active];
      $('[data-active-name]', root).textContent = a ? 'for ' + a.name : '';
      $('[data-palette]', root).className = 'ds-palette c' + (a ? a.color : 1);
      var n = Object.keys(fields).reduce(function (t, k) { return t + fields[k].length; }, 0);
      $('[data-fieldcount]', root).textContent = n + ' field' + (n === 1 ? '' : 's');
    }
    function save() {
      clearTimeout(saveT);
      status.textContent = 'Saving…';
      status.className = 'ds-studio-save is-busy';
      saveT = setTimeout(function () {
        var fd = new FormData();
        fd.append('action', 'save_fields');
        fd.append('csrf', st.csrf);
        fd.append('fields', JSON.stringify(fields));
        fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
          status.textContent = j.ok ? 'All changes saved' : (j.error || 'Not saved');
          status.className = 'ds-studio-save ' + (j.ok ? 'is-ok' : 'is-err');
        }).catch(function () { status.textContent = 'Not saved — check the connection'; status.className = 'ds-studio-save is-err'; });
      }, 600);
    }
    function el(slot, i) {
      var f = fields[slot][i], r = bySlot[slot];
      var d = document.createElement('div');
      d.className = 'ds-sfield c' + r.color + ' k-' + f.kind;
      d.tabIndex = 0;
      d.dataset.slot = slot;
      d.dataset.i = i;
      d.style.left = (f.x * 100) + '%'; d.style.top = (f.y * 100) + '%'; d.style.width = (f.w * 100) + '%'; d.style.height = (f.h * 100) + '%';
      d.innerHTML = '<span class="ds-sfield-l">' + esc(label(f.kind)) + '<em>' + esc(r.name) + '</em></span><button type="button" class="ds-sfield-x" aria-label="Remove field">×</button><span class="ds-sfield-r" aria-hidden="true"></span>';
      var x = $('.ds-sfield-x', d);
      x.addEventListener('pointerdown', function (e) { e.stopPropagation(); });
      x.addEventListener('click', function (e) { e.stopPropagation(); remove(slot, i); });
      d.addEventListener('focus', function () { select(d); });
      var mode = null, s0 = null;
      d.addEventListener('pointerdown', function (e) {
        if (e.target.closest('.ds-sfield-x')) { return; }
        e.preventDefault();
        select(d);
        d.setPointerCapture(e.pointerId);
        mode = e.target.classList.contains('ds-sfield-r') ? 'size' : 'move';
        s0 = { x: e.clientX, y: e.clientY, f: Object.assign({}, fields[slot][i]) };
      });
      d.addEventListener('pointermove', function (e) {
        if (!mode) { return; }
        var pr = d.parentNode.getBoundingClientRect(), dx = (e.clientX - s0.x) / pr.width, dy = (e.clientY - s0.y) / pr.height, g = fields[slot][i];
        if (mode === 'move') {
          g.x = Math.max(0, Math.min(1 - g.w, s0.f.x + dx));
          g.y = Math.max(0, Math.min(1 - g.h, s0.f.y + dy));
          d.style.left = (g.x * 100) + '%'; d.style.top = (g.y * 100) + '%';
        } else {
          g.w = Math.max(0.03, Math.min(1 - g.x, s0.f.w + dx));
          g.h = Math.max(0.012, Math.min(1 - g.y, s0.f.h + dy));
          d.style.width = (g.w * 100) + '%'; d.style.height = (g.h * 100) + '%';
        }
      });
      d.addEventListener('pointerup', function () { if (mode) { mode = null; save(); } });
      return d;
    }
    function select(d) {
      $$('.ds-sfield.sel', box).forEach(function (x) { x.classList.remove('sel'); });
      selected = d;
      if (d) { d.classList.add('sel'); }
    }
    function remove(slot, i) {
      fields[slot].splice(i, 1);
      if (!fields[slot].length) { delete fields[slot]; }
      selected = null;
      paint(); drawList(); save();
    }
    function paint() {
      $$('.ds-sfield', box).forEach(function (x) { x.remove(); });
      Object.keys(fields).forEach(function (slot) {
        if (!bySlot[slot]) { return; }
        fields[slot].forEach(function (f, i) {
          var holder = box.querySelector('.ds-page[data-n="' + (f.page + 1) + '"]');
          if (holder) { holder.appendChild(el(slot, i)); }
        });
      });
    }
    function add(kind, page, fx, fy) {
      if (!active) { return; }
      var k = st.kinds[kind], w = k[1], h = k[2];
      var f = { kind: kind, page: page, x: Math.max(0, Math.min(1 - w, fx - w / 2)), y: Math.max(0, Math.min(1 - h, fy - h / 2)), w: w, h: h };
      (fields[active] = fields[active] || []).push(f);
      paint(); drawList(); save();
      var last = box.querySelector('.ds-sfield[data-slot="' + active + '"][data-i="' + (fields[active].length - 1) + '"]');
      if (last) { last.classList.add('is-new'); select(last); }
    }
    function disarm() {
      armed = null;
      $$('[data-kind]', root).forEach(function (x) { x.classList.remove('armed'); });
      box.classList.remove('is-armed');
    }
    // Palette: drag a chip onto a page, or click it and then click the page.
    $$('[data-kind]', root).forEach(function (c) {
      c.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', c.getAttribute('data-kind')); e.dataTransfer.effectAllowed = 'copy'; });
      c.addEventListener('click', function () {
        var k = c.getAttribute('data-kind');
        armed = armed === k ? null : k;
        $$('[data-kind]', root).forEach(function (x) { x.classList.toggle('armed', x.getAttribute('data-kind') === armed); });
        box.classList.toggle('is-armed', !!armed);
      });
    });
    box.addEventListener('dragover', function (e) { if (e.target.closest('.ds-page')) { e.preventDefault(); } });
    box.addEventListener('drop', function (e) {
      var holder = e.target.closest('.ds-page'), kind = e.dataTransfer.getData('text/plain');
      if (!holder || !st.kinds[kind]) { return; }
      e.preventDefault();
      var r = holder.getBoundingClientRect();
      add(kind, parseInt(holder.dataset.n, 10) - 1, (e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
    });
    box.addEventListener('click', function (e) {
      if (e.target.closest('.ds-sfield')) { return; }
      var holder = e.target.closest('.ds-page');
      if (!holder || !armed) { select(null); return; }
      var r = holder.getBoundingClientRect();
      add(armed, parseInt(holder.dataset.n, 10) - 1, (e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
      if (!e.shiftKey) { disarm(); }
    });
    document.addEventListener('keydown', function (e) {
      if ((e.key === 'Delete' || e.key === 'Backspace') && selected && document.activeElement === selected) {
        e.preventDefault();
        remove(selected.dataset.slot, parseInt(selected.dataset.i, 10));
      }
      if (e.key === 'Escape') { disarm(); }
    });
    $('[data-autoplace]', root).addEventListener('click', function () {
      if (!doc) { return; }
      var who = recips.filter(function (r) { return !r.skip && r.action !== 'review' && !hasSig(r.slot); });
      var sw = st.kinds.signature[1], sh = st.kinds.signature[2];
      who.forEach(function (r, n) {
        var x = 0.04 + (n % 3) * 0.32, y = Math.max(0.05, 0.82 - Math.floor(n / 3) * 0.13);
        (fields[r.slot] = fields[r.slot] || []).push({ kind: 'signature', page: doc.numPages - 1, x: x, y: y, w: sw, h: sh });
        fields[r.slot].push({ kind: 'name', page: doc.numPages - 1, x: x, y: y + sh + 0.004, w: sw, h: 0.022 });
        fields[r.slot].push({ kind: 'date', page: doc.numPages - 1, x: x, y: y + sh + 0.03, w: 0.15, h: 0.022 });
      });
      paint(); drawList(); save();
      var lastPage = box.querySelector('.ds-page:last-child');
      if (lastPage) { box.scrollTop = lastPage.offsetTop; }
    });
    $('[data-clearall]', root).addEventListener('click', function () {
      askConfirm('Remove every field from every page?', function () { fields = {}; paint(); drawList(); save(); });
    });
    // The pages
    var preview = st.preview || null;
    function renderP(holder) {
      var n = parseInt(holder.dataset.n, 10);
      if (preview) {
        var img = new Image();
        img.className = 'ds-pageimg'; img.alt = 'Page ' + n; img.decoding = 'async'; img.draggable = false;
        img.onload = function () { var sk = $('.ds-skel', holder); if (sk) { sk.remove(); } };
        img.onerror = function () { if (!root._fell) { root._fell = 1; preview = null; startStudio(); } };
        img.src = preview.img + n;
        holder.insertBefore(img, holder.firstChild);
        if (!$('.ds-page-n', holder)) { var t0 = document.createElement('span'); t0.className = 'ds-page-n'; t0.textContent = n + ' / ' + doc.numPages; holder.appendChild(t0); }
        return;
      }
      doc.getPage(n).then(function (page) {
        var w = Math.min(box.clientWidth - 36, 900) * zoom, vp1 = page.getViewport({ scale: 1 }), dpr = Math.min(window.devicePixelRatio || 1, 1.5);
        var vp = page.getViewport({ scale: w / vp1.width * dpr });
        var c = document.createElement('canvas');
        c.width = vp.width; c.height = vp.height; c.style.width = (vp.width / dpr) + 'px';
        holder.style.width = (vp.width / dpr) + 'px';
        holder.style.minHeight = '';
        var sk = $('.ds-skel', holder);
        if (sk) { sk.remove(); }
        holder.insertBefore(c, holder.firstChild);
        if (!$('.ds-page-n', holder)) { var t = document.createElement('span'); t.className = 'ds-page-n'; t.textContent = n + ' / ' + doc.numPages; holder.appendChild(t); }
        page.render({ canvasContext: c.getContext('2d'), viewport: vp });
      });
    }
    function layout() {
      box.innerHTML = '';
      var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
        es.forEach(function (en) { if (en.isIntersecting) { renderP(en.target); io.unobserve(en.target); } });
      }, { root: box, rootMargin: '800px 0px' }) : null;
      for (var i = 1; i <= doc.numPages; i++) {
        var h = document.createElement('div');
        h.className = 'ds-page';
        h.dataset.n = i;
        var pw = Math.min(box.clientWidth - 36, 900) * zoom;
        h.style.width = pw + 'px';
        if (preview) {
          var sz = preview.sizes[i - 1] || [595, 842];
          h.style.aspectRatio = sz[0] + ' / ' + sz[1];
          h.innerHTML = '<div class="ds-skel abs"></div>';
        } else {
          h.style.minHeight = Math.round(pw * 1.3) + 'px';
          h.innerHTML = '<div class="ds-skel" style="width:100%"></div>';
        }
        box.appendChild(h);
        if (io) { io.observe(h); } else { renderP(h); }
      }
      paint();
    }
    function startStudio() {
      if (preview) {
        doc = { numPages: preview.sizes.length };
        $('[data-pageinfo]', root).textContent = '· ' + doc.numPages + ' page' + (doc.numPages === 1 ? '' : 's');
        layout();
        return;
      }
      loadPdfJs().then(function (lib) {
        return openPdf(lib, st.src);
      }).then(function (d) {
        doc = d;
        $('[data-pageinfo]', root).textContent = '· ' + d.numPages + ' page' + (d.numPages === 1 ? '' : 's');
        layout();
      }).catch(function () { box.innerHTML = '<div class="ds-viewer-err">The document could not be shown.</div>'; });
    }
    startStudio();
    $$('[data-szoom]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        zoom = Math.max(0.6, Math.min(2.2, zoom + parseInt(b.getAttribute('data-szoom'), 10) * 0.2));
        if (!doc) { return; }
        if (preview) {
          $$('.ds-page', box).forEach(function (h) { h.style.width = Math.min(box.clientWidth - 36, 900) * zoom + 'px'; });
        } else {
          layout();
        }
      });
    });
    // Send: every signer and approver should have a signature field.
    var form = document.getElementById('ds-studio-send');
    form.addEventListener('submit', function (e) {
      out.value = JSON.stringify(fields);
      if (form.dataset.ok) { return; }
      var missing = recips.filter(function (r) { return !r.skip && r.action !== 'review' && !hasSig(r.slot); }).map(function (r) { return r.name; });
      if (missing.length) {
        e.preventDefault();
        e.stopImmediatePropagation();
        askConfirm('No signature field for ' + missing.join(', ') + '. Their signature will go at the foot of the last page. Send anyway?', function () {
          form.dataset.ok = '1';
          out.value = JSON.stringify(fields);
          form.submit();
        });
      }
    }, true);
    drawList();
  }


  /* ---------------------------------------------------------------- external signer's page */
  function guest() {
    $$('[data-guestform]').forEach(function (form) {
      var note = $('[data-guestnote]', form);
      var say = function (msg, ok) { if (note) { note.textContent = msg; note.className = 'ds-hint ' + (ok ? 'is-ok' : 'is-err'); } };
      function post(fd, btn) {
        fd.append('ajax', '1');
        var label = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.classList.add('is-busy'); if (!btn.hasAttribute('data-guestcode')) { btn.textContent = btn.value === 'reject' ? 'Declining…' : btn.value === 'changes' ? 'Sending…' : 'Signing… please wait'; } }
        return fetch(form.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .catch(function () { return { ok: false, msg: 'The connection failed. Check your internet and try again.' }; })
          .then(function (j) { if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); btn.innerHTML = label; } return j; });
      }
      $$('[data-guestcode]', form).forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          var fd = new FormData(); fd.append('t', form.querySelector('[name=t]').value); fd.append('action', 'code');
          post(fd, b).then(function (j) {
            say(j.msg, j.ok);
            if (j.ok) { b.innerHTML = b.innerHTML.replace('Email me a code', 'Send a new code'); var c = $('[name=code]', form); if (c) { c.focus(); } }
          });
        });
      });
      // After the signature box has filled its field (capture phase), send without leaving the page.
      form.addEventListener('submit', function (e) {
        if (e.defaultPrevented) { return; }
        e.preventDefault();
        var btn = e.submitter || $('button[type=submit]:not([data-guestcode])', form);
        var fd = new FormData(form);
        if (btn && btn.name) { fd.set(btn.name, btn.value); }
        post(fd, btn).then(function (j) {
          if (j.ok && j.go) { location.href = j.go; return; }
          say(j.msg, j.ok);
          if (!j.ok && form.animate && !reduce) { form.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-5px)' }, { transform: 'translateX(5px)' }, { transform: 'translateX(0)' }], 260); }
        });
      });
    });
  }

  function boot() {
    var ao = document.querySelector("[data-autoopen]"); if (ao) { setTimeout(function () { ao.click(); }, 300); }
    countUp(); toasts(); dialogs(); menus(); confirms(); busy(); misc(); tips(); drops(); viewers(); readGate(); methods(); pads(); wizard(); routes(); builder(); studio(); guest();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
