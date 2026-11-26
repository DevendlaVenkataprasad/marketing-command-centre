/* Academic course builder (lib/edu/app_lms.php, app_blocks.php). Everything here
   improves a page that already works with plain forms: dialogs, the gallery's
   search and filter, drag-and-drop for lessons and for content blocks, blocks
   that save as you type, adding blocks in place, documents uploaded in pieces
   with progress, the formatting toolbar with Write / Preview, video previews,
   quiz answer rows, the cover preview, and Ctrl+S. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var csrf = function () { var i = $('input[name="csrf"]'); return i ? i.value : ''; };
  var here = location.pathname + location.search;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------------------- toast */
  var toast = function (msg, bad) {
    var t = document.createElement('div');
    t.className = 'eb-toast' + (bad ? ' bad' : '');
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('in'); });
    setTimeout(function () { t.classList.remove('in'); setTimeout(function () { t.remove(); }, 400); }, bad ? 5200 : 2200);
  };

  var postData = function (fd) {
    if (!fd.has('csrf')) { fd.append('csrf', csrf()); }
    fd.set('ajax', '1');
    return fetch(here, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent back something unexpected.' }; }); });
  };
  var post = function (data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); } else { fd.append(k, v); }
    });
    return postData(fd);
  };

  /* -------------------------------------------------------------- dialogs */
  $$('[data-dialog]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById(b.getAttribute('data-dialog'));
      if (!d || !d.showModal) { return; }
      var k = b.getAttribute('data-kind');
      if (k) {
        var r = d.querySelector('input[name="kind"][value="' + k + '"]');
        if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      d.showModal();
      var f = d.querySelector('input[name="title"]');
      if (f) { setTimeout(function () { f.focus(); }, 60); }
    });
  });
  $$('dialog.eb-dialog').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
    $$('[data-close]', d).forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
    var help = { text: 'Starts with a text block. Add videos, documents and more inside it.', video: 'Starts with a video block. Add text, documents and more around it.',
                 document: 'Drop the PDF or slides in next. Add text or video around it.', link: 'Starts with a link block. Add more inside it.', quiz: 'Questions with one right answer each, marked against the pass mark.' };
    d.addEventListener('change', function (e) {
      if (e.target.name !== 'kind') { return; }
      var ico = d.querySelector('[data-kind-ico]'), h = d.querySelector('[data-kind-help]');
      var lab = e.target.closest('label');
      if (ico && lab) { var svg = lab.querySelector('svg'); if (svg) { ico.innerHTML = svg.outerHTML; } }
      if (h) { h.textContent = help[e.target.value] || ''; }
    });
  });

  /* -------------------------------------------------------------- gallery */
  var grid = $('[data-eg-grid]');
  if (grid) {
    var q = $('[data-eg-search]'), status = 'all', none = $('[data-eg-none]');
    var apply = function () {
      var term = (q && q.value || '').trim().toLowerCase(), shown = 0;
      $$('.eg-card', grid).forEach(function (c) {
        var ok = (status === 'all' || c.getAttribute('data-status') === status) && (!term || c.getAttribute('data-text').indexOf(term) !== -1);
        c.hidden = !ok;
        shown += ok ? 1 : 0;
      });
      if (none) { none.hidden = shown > 0 || !grid.children.length; }
    };
    if (q) { q.addEventListener('input', apply); }
    $$('[data-eg-filter]').forEach(function (b) {
      b.addEventListener('click', function () {
        $$('[data-eg-filter]').forEach(function (x) { x.classList.toggle('on', x === b); });
        status = b.getAttribute('data-eg-filter');
        apply();
      });
    });
  }

  /* ----------------------------------------------- drag-and-drop (shared) */
  var sortable = function (list, itemSel, onDone) {
    var dragged = null;
    list.addEventListener('dragstart', function (e) {
      var it = e.target.closest(itemSel);
      if (!it) { return; }
      dragged = it;
      it.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', it.getAttribute('data-id') || it.getAttribute('data-block') || ''); } catch (x) {}
    });
    list.addEventListener('dragend', function () {
      if (dragged) { dragged.classList.remove('dragging'); if (dragged.matches('.ebk')) { dragged.draggable = false; } }
      $$('.over').forEach(function (l) { l.classList.remove('over'); });
      dragged = null;
    });
    list.addEventListener('dragover', function (e) {
      if (!dragged) { return; }
      e.preventDefault();
      var target = e.target.closest('[data-sortable], [data-blocks]') || list;
      if (dragged.matches('.ebk') && target !== dragged.parentNode) { return; }
      target.classList.add('over');
      var after = null;
      $$(itemSel + ':not(.dragging)', target).some(function (it) {
        if (it.parentNode !== target) { return false; }
        var r = it.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) { after = it; return true; }
        return false;
      });
      if (after) { target.insertBefore(dragged, after); } else { target.appendChild(dragged); }
    });
    list.addEventListener('dragleave', function (e) { if (!list.contains(e.relatedTarget)) { list.classList.remove('over'); } });
    list.addEventListener('drop', function (e) { if (!dragged) { return; } e.preventDefault(); list.classList.remove('over'); onDone(); });
  };

  /* lessons in the outline */
  var lists = $$('[data-sortable]');
  var renumber = function () {
    var n = 0;
    $$('.eb-list .eb-item').forEach(function (li) { n++; var i = li.querySelector('.eb-ititle i'); if (i) { i.textContent = n + '.'; } });
  };
  var saveLessonOrder = function () {
    renumber();
    var ids = [], secs = [], course = lists.length ? lists[0].getAttribute('data-course') : '';
    $$('.eb-list .eb-item').forEach(function (li) {
      ids.push(li.getAttribute('data-id'));
      var s = li.closest('.eb-sec');
      secs.push(s ? s.getAttribute('data-section') : '');
    });
    post({ a: 'lesson-order', id: course, ids: ids, sections: secs }).then(function (r) { toast(r.ok ? 'Order saved' : (r.error || 'Could not save the order'), !r.ok); });
  };
  lists.forEach(function (l) { sortable(l, '.eb-item', saveLessonOrder); });

  /* --------------------------------------------------------------- blocks */
  var blockList = $('[data-blocks]');
  var lessonId = blockList ? blockList.getAttribute('data-lesson') : '';
  var countBlocks = function () {
    if (!blockList) { return; }
    var n = blockList.children.length, c = $('[data-bcount]'), empty = $('[data-bempty]');
    if (c) { c.textContent = n; }
    if (empty) { empty.hidden = n > 0; }
  };
  var saveBlockOrder = function () {
    post({ a: 'block-order', lesson: lessonId, ids: $$('.ebk', blockList).map(function (b) { return b.getAttribute('data-block'); }) })
      .then(function (r) { toast(r.ok ? 'Order saved' : (r.error || 'Could not save the order'), !r.ok); });
  };
  if (blockList) {
    // A block is draggable only while its handle is held, so text and inputs inside it work as normal.
    blockList.addEventListener('mousedown', function (e) { var h = e.target.closest('.eb-handle'); if (h) { h.closest('.ebk').draggable = true; } });
    document.addEventListener('mouseup', function () { $$('.ebk[draggable="true"]:not(.dragging)').forEach(function (b) { b.draggable = false; }); });
    $$('.ebk', blockList).forEach(function (b) { b.draggable = false; });
    sortable(blockList, '.ebk', saveBlockOrder);
  }

  var md = function (src, k) {
    var s = src.selectionStart, e = src.selectionEnd, v = src.value, sel = v.slice(s, e);
    var lineStart = v.lastIndexOf('\n', s - 1) + 1, out, caret;
    var wrap = function (a, z, ph) { var t = sel || ph; out = v.slice(0, s) + a + t + z + v.slice(e); caret = [s + a.length, s + a.length + t.length]; };
    var prefix = function (p) {
      var block = v.slice(lineStart, e) || 'Item';
      var done = block.split('\n').map(function (ln, n) { return (p === '1. ' ? (n + 1) + '. ' : p) + ln; }).join('\n');
      out = v.slice(0, lineStart) + done + v.slice(e); caret = [lineStart, lineStart + done.length];
    };
    if (k === 'b') { wrap('**', '**', 'bold text'); } else if (k === 'i') { wrap('*', '*', 'italic text'); } else if (k === 'code') { wrap('`', '`', 'code'); }
    else if (k === 'link') { var u = window.prompt('Link address (https://…)', 'https://'); if (!u) { return; } wrap('[', '](' + u + ')', 'link text'); }
    else if (k === 'h') { prefix('## '); } else if (k === 'ul') { prefix('- '); } else if (k === 'ol') { prefix('1. '); }
    src.value = out; src.focus(); src.setSelectionRange(caret[0], caret[1]);
    src.dispatchEvent(new Event('input', { bubbles: true }));
  };
  var embedOf = function (u) {
    var m;
    if ((m = u.match(/^https:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/))) { return 'https://www.youtube-nocookie.com/embed/' + m[1] + '?rel=0'; }
    if ((m = u.match(/^https:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{5,12})/))) { return 'https://player.vimeo.com/video/' + m[1]; }
    return '';
  };

  var initBlock = function (li) {
    var form = li.querySelector('[data-block-form]'), state = li.querySelector('[data-state]'), timer = null;
    var setState = function (t, cls) { if (state) { state.textContent = t; state.className = 'ebk-state ' + (cls || ''); } };
    var save = function () {
      clearTimeout(timer);
      setState('Saving…', 'busy');
      postData(new FormData(form)).then(function (r) {
        if (r.ok) { setState('Saved', 'ok'); setTimeout(function () { if (state.textContent === 'Saved') { setState(''); } }, 1800); }
        else { setState(r.error || 'Not saved', 'bad'); }
      }).catch(function () { setState('Not saved — check the connection', 'bad'); });
    };
    if (form) {
      form.addEventListener('input', function () { setState('Editing…', 'busy'); clearTimeout(timer); timer = setTimeout(save, 900); });
      form.addEventListener('change', function (e) { if (e.target.tagName === 'SELECT') { save(); } });
      form.addEventListener('submit', function (e) { e.preventDefault(); save(); });
      // Formatting toolbar and Write / Preview
      var src = form.querySelector('[data-md-src]'), prev = form.querySelector('[data-md-prev]');
      $$('[data-md]', form).forEach(function (b) { b.addEventListener('click', function () { if (src) { md(src, b.getAttribute('data-md')); } }); });
      $$('[data-md-tab]', form).forEach(function (b) {
        b.addEventListener('click', function () {
          var p = b.getAttribute('data-md-tab') === 'preview';
          $$('[data-md-tab]', form).forEach(function (x) { x.classList.toggle('on', x === b); });
          if (!src || !prev) { return; }
          src.hidden = p; prev.hidden = !p;
          if (p) {
            prev.innerHTML = '<p class="muted">…</p>';
            post({ a: 'preview-md', text: src.value }).then(function (r) { prev.innerHTML = r.html || '<p class="muted">Nothing written yet.</p>'; });
          }
        });
      });
      // Video preview
      var vurl = form.querySelector('[data-video-url]'), vprev = form.querySelector('[data-video-prev]');
      if (vurl && vprev) {
        vurl.addEventListener('input', function () {
          var u = vurl.value.trim(), src2 = embedOf(u), cur = vprev.querySelector('iframe');
          if (src2 && (!cur || cur.getAttribute('src') !== src2)) { vprev.innerHTML = '<iframe src="' + src2 + '" title="Video preview" allowfullscreen loading="lazy"></iframe>'; }
          else if (!src2) { vprev.innerHTML = '<span>' + (u ? 'That does not look like a YouTube or Vimeo address yet.' : 'Paste an address: the video appears here.') + '</span>'; }
        });
      }
    }
    // Remove
    var del = li.querySelector('form[data-block-del]');
    if (del) {
      del.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!window.confirm('Remove this block' + (li.classList.contains('t-file') ? ' and its file' : '') + '?')) { return; }
        postData(new FormData(del)).then(function (r) {
          if (!r.ok) { toast(r.error || 'Could not remove it', true); return; }
          li.classList.add('gone');
          setTimeout(function () { li.remove(); countBlocks(); }, reduce ? 0 : 280);
          toast('Removed');
        });
      });
    }
    // Move up / down
    $$('[data-bmove]', li).forEach(function (b) {
      b.addEventListener('click', function () {
        var up = b.getAttribute('data-bmove') === '-1', sib = up ? li.previousElementSibling : li.nextElementSibling;
        if (!sib) { return; }
        if (up) { li.parentNode.insertBefore(li, sib); } else { li.parentNode.insertBefore(sib, li); }
        li.classList.add('moved'); setTimeout(function () { li.classList.remove('moved'); }, 400);
        saveBlockOrder();
      });
    });
    wireAdders(li);
  };

  /* Adding blocks: the bar under the content, and the "+" under each block */
  var insertBlock = function (html, after) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var li = tmp.firstElementChild;
    if (!li) { return null; }
    var anchor = after && blockList.querySelector('[data-block="' + after + '"]');
    if (anchor) { anchor.after(li); } else { blockList.appendChild(li); }
    li.classList.add('fresh');
    initBlock(li);
    countBlocks();
    return li;
  };
  var wireAdders = function (root) {
    $$('form[data-block-add]', root).forEach(function (f) {
      if (f.dataset.wired) { return; }
      f.dataset.wired = '1';
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var after = f.querySelector('input[name=after]').value;
        postData(new FormData(f)).then(function (r) {
          if (!r.ok) { toast(r.error || 'Could not add it', true); return; }
          var det = f.closest('details'); if (det) { det.open = false; }
          var li = insertBlock(r.html, after);
          if (li) {
            li.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
            var first = li.querySelector('textarea, input:not([type=hidden]), select');
            if (first) { setTimeout(function () { first.focus(); }, reduce ? 0 : 250); }
          }
        });
      });
    });
    $$('[data-pick-file]', root).forEach(function (b) {
      if (b.dataset.wired) { return; }
      b.dataset.wired = '1';
      b.addEventListener('click', function () {
        var zone = $('[data-drop][data-blocks-mode]');
        if (!zone) { return; }
        var det = b.closest('details'); if (det) { det.open = false; }
        zone.dataset.after = b.getAttribute('data-after') || '0';
        zone.querySelector('input[type=file]').click();
      });
    });
  };

  if (blockList) { $$('.ebk', blockList).forEach(initBlock); wireAdders(document); }
  // One "+" menu open at a time.
  document.addEventListener('toggle', function (e) {
    if (e.target.matches && e.target.matches('details.ebk-insert') && e.target.open) {
      $$('details.ebk-insert[open]').forEach(function (d) { if (d !== e.target) { d.open = false; } });
    }
  }, true);

  /* ------------------------------------------------------------- uploads */
  var uid = function () { var s = '', c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'; for (var i = 0; i < 24; i++) { s += c.charAt(Math.floor(Math.random() * c.length)); } return s; };
  var human = function (b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
  var sendPiece = function (url, fd, onProgress) {
    return new Promise(function (ok, bad) {
      var x = new XMLHttpRequest();
      x.open('POST', url);
      x.setRequestHeader('X-Requested-With', 'fetch');
      x.upload.onprogress = function (e) { if (e.lengthComputable) { onProgress(e.loaded); } };
      x.onload = function () {
        var r;
        try { r = JSON.parse(x.responseText); } catch (e) { r = { ok: false, error: x.status === 413 ? 'That piece was too big for the server.' : 'The server did not answer properly (' + x.status + ').' }; }
        if (x.status >= 200 && x.status < 300 && r.ok !== false) { ok(r); } else { bad(new Error(r.error || 'Upload failed')); }
      };
      x.onerror = function () { bad(new Error('The connection dropped. Try again.')); };
      x.send(fd);
    });
  };
  var upload = function (zone, file) {
    var blocksMode = zone.hasAttribute('data-blocks-mode');
    var holder = blocksMode ? blockList : zone.parentNode.querySelector('[data-filelist]');
    var after = blocksMode ? (zone.dataset.after || '0') : '0';
    var ext = (file.name.split('.').pop() || '').toUpperCase().slice(0, 4);
    var li = document.createElement('li');
    li.className = (blocksMode ? 'ebk t-file ' : '') + 'eb-file uploading';
    li.innerHTML = '<span class="eb-fico"></span><span class="eb-fname"><strong></strong><em></em><span class="eb-prog"><i></i></span></span>';
    li.querySelector('.eb-fico').textContent = ext;
    li.querySelector('strong').textContent = file.name;
    li.querySelector('em').textContent = 'Uploading · ' + human(file.size);
    var anchor = after !== '0' && holder.querySelector('[data-block="' + after + '"]');
    if (anchor) { anchor.after(li); } else { holder.appendChild(li); }
    if (blocksMode) { countBlocks(); }
    var bar = li.querySelector('.eb-prog i');
    var size = parseInt(zone.getAttribute('data-chunk'), 10) || 4194304;
    var count = Math.max(1, Math.ceil(file.size / size)), id = uid(), i = 0;
    var url = zone.getAttribute('data-url') || here;
    var next = function () {
      var fd = new FormData();
      fd.append('csrf', csrf());
      fd.append('a', 'file-upload');
      fd.append('lesson', zone.getAttribute('data-lesson'));
      if (blocksMode) { fd.append('block', '1'); fd.append('after', after); }
      var blob = count > 1 ? file.slice(i * size, Math.min(file.size, (i + 1) * size)) : file;
      fd.append('file', blob, file.name);
      if (count > 1) {
        fd.append('chunk_id', id); fd.append('chunk_index', i); fd.append('chunk_count', count);
        fd.append('chunk_offset', i * size); fd.append('chunk_total', file.size); fd.append('chunk_name', file.name);
      }
      return sendPiece(url, fd, function (loaded) { bar.style.width = Math.min(100, ((i * size + loaded) / file.size) * 100) + '%'; })
        .then(function (r) {
          i++;
          if (i < count) { return next(); }
          var tmp = document.createElement('div');
          tmp.innerHTML = r.html || '';
          var card = tmp.firstElementChild;
          if (card) {
            card.classList.add('fresh');
            li.replaceWith(card);
            if (blocksMode) { initBlock(card); after = card.getAttribute('data-block'); zone.dataset.after = after; } else { wireDelete(card); }
          } else { li.remove(); }
          toast(file.name + ' added');
        });
    };
    return next().catch(function (e) {
      li.classList.remove('uploading');
      li.classList.add('failed');
      li.querySelector('em').textContent = e.message;
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'eb-ibtn'; x.textContent = '×'; x.title = 'Dismiss';
      x.addEventListener('click', function () { li.remove(); countBlocks(); });
      li.appendChild(x);
      toast(file.name + ': ' + e.message, true);
    });
  };
  $$('[data-drop]').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]');
    var queue = function (files) {
      Array.prototype.slice.call(files || []).reduce(function (p, f) { return p.then(function () { return upload(zone, f); }); }, Promise.resolve())
        .then(function () { zone.dataset.after = '0'; });
    };
    input.addEventListener('change', function () { queue(input.files); input.value = ''; });
    zone.addEventListener('click', function () { if (!zone.dataset.after) { zone.dataset.after = '0'; } });
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1) { e.preventDefault(); zone.classList.add('hot'); } }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.remove('hot'); }); });
    zone.addEventListener('drop', function (e) { e.preventDefault(); zone.dataset.after = '0'; queue(e.dataTransfer.files); });
  });
  // Files dropped anywhere on the content list go in too.
  if (blockList) {
    var bz = $('[data-drop][data-blocks-mode]');
    var wrap = blockList.closest('.ebk-wrap');
    if (bz && wrap) {
      wrap.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1) { e.preventDefault(); wrap.classList.add('filehot'); } });
      wrap.addEventListener('dragleave', function (e) { if (!wrap.contains(e.relatedTarget)) { wrap.classList.remove('filehot'); } });
      wrap.addEventListener('drop', function (e) {
        if (!e.dataTransfer || !e.dataTransfer.files.length || e.target.closest('[data-drop]')) { wrap.classList.remove('filehot'); return; }
        e.preventDefault(); wrap.classList.remove('filehot');
        bz.dataset.after = '0';
        Array.prototype.slice.call(e.dataTransfer.files).reduce(function (p, f) { return p.then(function () { return upload(bz, f); }); }, Promise.resolve());
      });
    }
  }
  var wireDelete = function (card) {
    var f = card.querySelector('form[data-file-del]');
    if (!f) { return; }
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!window.confirm('Remove this file?')) { return; }
      postData(new FormData(f)).then(function (r) {
        if (r.ok) { card.classList.add('gone'); setTimeout(function () { card.remove(); }, reduce ? 0 : 280); toast('File removed'); } else { toast(r.error || 'Could not remove it', true); }
      });
    });
  };
  $$('.eb-filelist .eb-file').forEach(wireDelete);

  /* ------------------------------------------------- lesson header: save */
  var lf = $('[data-lesson-form]'), dirtyFlag = $('[data-dirty]'), dirty = false;
  if (lf) {
    lf.addEventListener('input', function () { dirty = true; if (dirtyFlag) { dirtyFlag.hidden = false; } });
    lf.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        var inBlock = document.activeElement && document.activeElement.closest('[data-block-form]');
        if (inBlock) { inBlock.dispatchEvent(new Event('submit', { cancelable: true })); return; }
        dirty = false; if (lf.requestSubmit) { lf.requestSubmit(); } else { lf.submit(); }
      }
    });
  }

  /* ------------------------------------------------------ student view */
  // Drawn by the server, so it shows exactly what was last saved.
  var tg = $('[data-preview-toggle]'), sv = $('[data-studentview]');
  if (tg && sv) {
    var showing = location.hash === '#student';
    if (showing) {
      sv.hidden = false;
      tg.classList.add('on');
      tg.lastChild.textContent = ' Back to editing';
      $$('.eb-lform, .eb-block, .eb-foot').forEach(function (el) { el.hidden = true; });
    }
    tg.addEventListener('click', function () {
      if (showing) { history.replaceState(null, '', location.pathname + location.search); } else { location.hash = 'student'; }
      location.reload();
    });
  }

  /* --------------------------------------------------------- quiz answers */
  var renum = function (box) {
    $$('.eb-opt', box).forEach(function (row, n) {
      row.querySelector('input[type=radio]').value = n;
      row.querySelector('input[name="options[]"]').placeholder = 'Answer ' + (n + 1);
    });
  };
  $$('[data-qform]').forEach(function (f) {
    var box = f.querySelector('[data-opts]');
    var add = f.querySelector('[data-opt-add]');
    if (add) {
      add.addEventListener('click', function () {
        var rows = $$('.eb-opt', box);
        if (rows.length >= 8) { toast('Up to 8 answers', true); return; }
        var row = rows[rows.length - 1].cloneNode(true);
        row.querySelector('input[type=radio]').checked = false;
        row.querySelector('input[name="options[]"]').value = '';
        box.appendChild(row);
        renum(box);
        row.querySelector('input[name="options[]"]').focus();
      });
    }
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-opt-del]');
      if (!b) { return; }
      if ($$('.eb-opt', box).length <= 2) { toast('A question needs at least two answers', true); return; }
      b.closest('.eb-opt').remove();
      renum(box);
    });
  });

  /* ----------------------------------------------------------- cover image */
  var cd = $('[data-cover-drop]');
  if (cd) {
    var ci = cd.querySelector('input[type=file]'), img = cd.querySelector('[data-cover-img]');
    ci.addEventListener('change', function () {
      if (ci.files && ci.files[0] && img) { img.src = URL.createObjectURL(ci.files[0]); img.hidden = false; cd.classList.add('has'); }
    });
  }

  /* ---------------------------------------------------- tab underline slide */
  var tabs = $('.eb-tabs'), line = tabs && tabs.querySelector('.eb-tabline'), on = tabs && tabs.querySelector('a.on');
  if (line && on) { line.style.width = on.offsetWidth + 'px'; line.style.transform = 'translateX(' + on.offsetLeft + 'px)'; }
})();
