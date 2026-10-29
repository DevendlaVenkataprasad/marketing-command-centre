/* CRM — admin/crm.php and crm.php. Plain script, no library. */
(function () {
  'use strict';
  var root = document.querySelector('.crm');
  if (!root) { return; }
  var csrf = root.getAttribute('data-csrf') || '';

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ------------------------------------------------ board: drag a card */
  var board = $('.crm-kanban.can-drag');
  if (board) {
    var dragging = null, from = null, moved = false;

    board.addEventListener('dragstart', function (e) {
      var card = e.target.closest('.crm-card');
      if (!card) { return; }
      dragging = card;
      from = card.closest('.crm-col');
      moved = false;
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.getAttribute('data-rec'));
    });
    board.addEventListener('dragend', function () {
      if (dragging) { dragging.classList.remove('dragging'); }
      $$('.crm-col.drop-on', board).forEach(function (c) { c.classList.remove('drop-on'); });
    });
    board.addEventListener('dragover', function (e) {
      var col = e.target.closest('.crm-col');
      if (!col || !dragging) { return; }
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      $$('.crm-col.drop-on', board).forEach(function (c) { if (c !== col) { c.classList.remove('drop-on'); } });
      col.classList.add('drop-on');
    });
    board.addEventListener('drop', function (e) {
      var col = e.target.closest('.crm-col');
      if (!col || !dragging) { return; }
      e.preventDefault();
      col.classList.remove('drop-on');
      if (col === from) { return; }
      var card = dragging, origin = from;
      moved = true;
      var list = $('.crm-cards', col);
      var none = $('.crm-none', list);
      if (none) { none.remove(); }
      list.insertBefore(card, list.firstChild);
      bump(col, 1); bump(origin, -1);
      card.classList.add('saving');

      var body = new FormData();
      body.append('csrf', csrf);
      body.append('action', 'record_stage');
      body.append('crm', board.getAttribute('data-crm'));
      body.append('rec', card.getAttribute('data-rec'));
      body.append('stage', col.getAttribute('data-stage'));
      fetch(board.getAttribute('data-post'), {
        method: 'POST', body: body, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j || !j.ok) { throw new Error((j && j.error) || 'Not saved'); }
        card.classList.remove('saving');
      }).catch(function (err) {
        // Put it back where it was, and say so.
        $('.crm-cards', origin).insertBefore(card, $('.crm-cards', origin).firstChild);
        bump(col, -1); bump(origin, 1);
        card.classList.remove('saving');
        alert('That move was not saved: ' + err.message);
      });
    });
    // A drag that ends on the card's own link must not also open it.
    board.addEventListener('click', function (e) {
      if (moved && e.target.closest('.crm-card')) { e.preventDefault(); moved = false; }
    }, true);

    function bump(col, d) {
      var n = $('.crm-count', col);
      if (n) {
        var v = parseInt(n.textContent.replace(/[^\d]/g, ''), 10) || 0;
        n.textContent = Math.max(0, v + d).toLocaleString('en-IN');
      }
    }
  }

  /* ---------------------------------------------- table: tick and bulk */
  var all = $('#crmAll');
  var bar = $('#crmBulkBar');
  function syncBulk() {
    var n = $$('.crm-table tbody input[name="ids[]"]:checked').length;
    if (bar) {
      bar.hidden = n === 0;
      $('#crmBulkN').textContent = n + ' selected';
    }
  }
  if (all) {
    all.addEventListener('change', function () {
      $$('.crm-table tbody input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; });
      syncBulk();
    });
  }
  $$('.crm-table tbody input[name="ids[]"]').forEach(function (c) { c.addEventListener('change', syncBulk); });
  $$('.crm-table tbody tr[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, input, button, label, select')) { return; }
      window.location = tr.getAttribute('data-href');
    });
  });

  /* ------------------------------------- field studio: type and preview */
  var ff = $('#crmFieldForm');
  if (ff) {
    var sample = {
      text: 'Priya Sharma', textarea: '', email: 'priya@example.com', phone: '98765 43210', url: '',
      number: '24', decimal: '8.75', currency: '125000', percent: '87.5'
    };
    function type() {
      var t = $('input[name=type]:checked', ff);
      return t ? t.value : 'text';
    }
    function opts() {
      var ta = $('textarea[name=options]', ff);
      var o = (ta ? ta.value : '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
      return o.length ? o : ['Option one', 'Option two', 'Option three'];
    }
    function preview() {
      var t = type();
      var label = ($('#crmFLabel').value || 'Field name').trim();
      var req = $('input[name=required]', ff).checked && t !== 'autonumber';
      var ph = ($('input[name=placeholder]', ff) || {}).value || '';
      var help = ($('input[name=help]', ff) || {}).value || '';
      var phA = ph ? ' placeholder="' + esc(ph) + '"' : '';
      var h = '';
      switch (t) {
        case 'textarea': h = '<textarea rows="3"' + phA + '></textarea>'; break;
        case 'email': case 'phone': case 'url': case 'number': case 'decimal':
          h = '<input type="' + ({ email: 'email', phone: 'tel', url: 'url' }[t] || 'number') + '"' + phA + '>'; break;
        case 'currency': h = '<div class="crm-affix"><span>₹</span><input type="number"' + phA + '></div>'; break;
        case 'percent': h = '<div class="crm-affix crm-affix-r"><input type="number"' + phA + '><span>%</span></div>'; break;
        case 'rating':
          h = '<div class="crm-rate">';
          for (var i = 5; i >= 1; i--) {
            h += '<input type="radio" name="pv_rate" id="pv_r' + i + '" value="' + i + '"' + (i === 4 ? ' checked' : '') +
                 '><label for="pv_r' + i + '">★</label>';
          }
          h += '</div>'; break;
        case 'date': h = '<input type="date">'; break;
        case 'datetime': h = '<input type="datetime-local">'; break;
        case 'time': h = '<input type="time">'; break;
        case 'select':
          h = '<select><option>—</option>' + opts().map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>';
          break;
        case 'radio':
          h = '<div class="crm-choices">' + opts().map(function (o) {
            return '<label><input type="radio" name="pv_radio"> ' + esc(o) + '</label>';
          }).join('') + '</div>'; break;
        case 'multiselect':
          h = '<div class="crm-choices">' + opts().map(function (o) {
            return '<label><input type="checkbox"> ' + esc(o) + '</label>';
          }).join('') + '</div>'; break;
        case 'checkbox': h = '<label class="crm-check"><input type="checkbox"> Yes</label>'; break;
        case 'file': h = '<input type="file" multiple>'; break;
        case 'autonumber':
          var pre = ($('input[name=prefix]', ff) || {}).value || '';
          var dig = parseInt(($('input[name=digits]', ff) || {}).value, 10) || 4;
          var nx = String(parseInt(($('input[name=next]', ff) || {}).value, 10) || 1);
          while (nx.length < dig) { nx = '0' + nx; }
          h = '<div class="crm-auto-in">' + esc(pre + nx) + '</div>'; break;
        default: h = '<input type="text"' + phA + ' value="">';
      }
      var pv = $('#crmPreview');
      pv.innerHTML = '<label>' + esc(label) + (req ? ' <b class="crm-req">*</b>' : '') + '</label>' + h +
                     (help ? '<small class="crm-help">' + esc(help) + '</small>' : '');
      if (sample[t] !== undefined) {
        var inp = $('input, textarea', pv);
        if (inp && !ph) { inp.placeholder = sample[t] ? 'e.g. ' + sample[t] : ''; }
      }
    }
    function toggle() {
      var t = type();
      $$('[data-show]', ff).forEach(function (el) {
        el.hidden = el.getAttribute('data-show').split(' ').indexOf(t) < 0;
      });
      $$('[data-hide]', ff).forEach(function (el) {
        var off = el.getAttribute('data-hide').split(' ').indexOf(t) >= 0;
        el.hidden = off;
        if (off) { var c = $('input', el); if (c) { c.checked = false; } }
      });
      var ta = $('textarea[name=options]', ff);
      if (ta) { ta.required = ['select', 'radio', 'multiselect'].indexOf(t) >= 0; }
      preview();
    }
    ff.addEventListener('change', toggle);
    ff.addEventListener('input', preview);
    toggle();
  }

  /* ------------------------------------------------- import: the mapping */
  var map = $('#crmMap');
  if (map) {
    var note = $('#crmMapNote');
    function checkMap() {
      var seen = {}, skipped = 0, dups = 0, made = 0;
      var sels = $$('.crm-mapsel', map);
      sels.forEach(function (s) { seen[s.value] = (seen[s.value] || 0) + 1; });
      sels.forEach(function (s) {
        var tr = s.closest('tr');
        var v = s.value;
        tr.classList.toggle('skip', v === '');
        var dup = v !== '' && v !== '__new' && seen[v] > 1;
        tr.classList.toggle('dup', dup);
        if (v === '') { skipped++; }
        if (v === '__new') { made++; }
        if (dup) { dups++; }
      });
      var bits = [];
      if (dups) { bits.push('<b style="color:var(--red)">Two columns point at the same field — each field can be filled from one column only.</b>'); }
      if (made) { bits.push(made + ' new field' + (made === 1 ? '' : 's') + ' will be created (the type is guessed from the column name; change it afterwards in Fields).'); }
      if (skipped) { bits.push(skipped + ' column' + (skipped === 1 ? '' : 's') + ' will not be imported.'); }
      note.innerHTML = bits.join(' ');
      var go = $('button[type=submit]', map);
      if (go) { go.disabled = dups > 0 || skipped === sels.length; }
    }
    map.addEventListener('change', checkMap);
    checkMap();

    var match = $('#crmMatch'), mode = $('#crmMode');
    function syncMode() { if (mode) { mode.hidden = !match.value; } }
    if (match) { match.addEventListener('change', syncMode); syncMode(); }
  }

  /* ----------------------------------------------- import: the drop zone */
  var drop = $('#crmDrop');
  if (drop) {
    var input = $('#crmFile'), lab = $('label', drop), orig = lab.innerHTML;
    function show() {
      if (input.files && input.files.length) {
        drop.classList.add('has');
        lab.innerHTML = '<b>' + esc(input.files[0].name) + '</b><small>' +
          Math.max(1, Math.round(input.files[0].size / 1024)).toLocaleString('en-IN') + ' KB · ready to upload</small>';
      } else {
        drop.classList.remove('has');
        lab.innerHTML = orig;
      }
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function () { drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer.files && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        show();
      }
    });
  }

  /* --------------------------- record: switch between reading and editing */
  var details = $('#crmDetails');
  if (details) {
    $$('[data-toggle-edit]', details).forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var on = details.classList.toggle('editing');
        if (on) {
          var first = $('.crm-editmode input:not([type=hidden]), .crm-editmode select, .crm-editmode textarea', details);
          if (first) { first.focus(); }
        } else {
          var btn = $('.crm-editbtn', details);
          if (btn) { btn.focus(); }
        }
      });
    });
  }

  /* ------------------------------------------- "New CRM" opens its panel */
  $$('[data-open]').forEach(function (a) {
    a.addEventListener('click', function () {
      var d = document.getElementById(a.getAttribute('data-open'));
      if (d && d.tagName === 'DETAILS') {
        d.open = true;
        var f = $('input[name=name]', d);
        if (f) { setTimeout(function () { f.focus(); }, 50); }
      }
    });
  });

  /* ------------------------------------- charts: one tooltip for every mark */
  var tip = $('#crmTip');
  if (tip) {
    var showTip = function (el, x, y) {
      tip.textContent = el.getAttribute('data-tip');
      tip.hidden = false;
      var w = tip.offsetWidth, h = tip.offsetHeight;
      tip.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, x + 12)) + 'px';
      tip.style.top = Math.max(8, y - h - 12) + 'px';
    };
    document.addEventListener('mousemove', function (e) {
      var el = e.target.closest && e.target.closest('[data-tip]');
      if (el) { showTip(el, e.clientX, e.clientY); } else { tip.hidden = true; }
    });
    document.addEventListener('focusin', function (e) {
      var el = e.target.closest && e.target.closest('[data-tip]');
      if (el) { var r = el.getBoundingClientRect(); showTip(el, r.left + r.width / 2, r.top); }
    });
    document.addEventListener('focusout', function () { tip.hidden = true; });
    window.addEventListener('scroll', function () { tip.hidden = true; }, true);
  }

  /* ---------------------------------------------- access: find a person */
  var find = $('#crmAccFind');
  if (find) {
    find.addEventListener('input', function () {
      var q = find.value.trim().toLowerCase();
      $$('.crm-acctable tr[data-name]').forEach(function (tr) {
        tr.hidden = q !== '' && tr.getAttribute('data-name').indexOf(q) < 0;
      });
    });
    // Enter in the search box must not submit the access form.
    find.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
  }

  /* --------------------------------- new CRM: the template names its records */
  var rl = $('#crmRecordLabel');
  if (rl) {
    $$('input[name=template]').forEach(function (r) {
      r.addEventListener('change', function () { rl.placeholder = r.getAttribute('data-record') || 'Record'; });
      if (r.checked) { rl.placeholder = r.getAttribute('data-record') || 'Record'; }
    });
  }

  /* -------------------------- emails: "Insert a detail" drops {{Tag}} at the cursor */
  var lastBox = new WeakMap();      // form -> the subject/message box used last in it
  document.addEventListener('focusin', function (e) {
    var t = e.target;
    if (t.form && (t.tagName === 'TEXTAREA' || (t.tagName === 'INPUT' && t.type === 'text') || t.hasAttribute('data-merge'))) {
      lastBox.set(t.form, t);
    }
  });
  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel.matches || !sel.matches('select[data-insert]') || !sel.value) { return; }
    var form = sel.form;
    var box = (form && lastBox.get(form)) || (form && form.querySelector('textarea'));
    if (box) {
      var tag = '{{' + sel.value + '}}';
      var a = box.selectionStart != null ? box.selectionStart : box.value.length;
      var b = box.selectionEnd != null ? box.selectionEnd : a;
      box.value = box.value.slice(0, a) + tag + box.value.slice(b);
      box.focus();
      box.setSelectionRange(a + tag.length, a + tag.length);
      box.dispatchEvent(new Event('input', { bubbles: true }));
    }
    sel.selectedIndex = 0;
  });
})();
