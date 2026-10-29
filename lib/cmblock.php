<?php
/**
 * The committee on the Events pages: the panel that shows where an event
 * stands with its committee (and where a member decides), and the dashboard
 * card that lists what is waiting on the person looking.
 *
 * The card is drawn only for somebody who sits on a committee -- everybody
 * else on the team never sees it. See lib/committees.php.
 */

require_once __DIR__ . '/committees.php';

/** The panel, and the script that drives it and the card. Drawn once per Events page. */
function fabo_cm_panel(array $viewer): void
{
    $q   = ['sheet' => 'events'] + (!empty($viewer['is_admin']) ? ['as' => 'admin'] : []);
    $api = fabo_link($viewer, (!empty($viewer['is_admin']) ? '../api/' : 'api/') . 'committee.php', $q);
    ?>
    <div class="pvmodal cmmodal" id="cmModal" hidden>
      <div class="pvcard cmcard" role="dialog" aria-modal="true" aria-labelledby="cmTitle">
        <div class="pvhead">
          <div>
            <div class="cmeyebrow" id="cmEyebrow">Committee approval</div>
            <h3 id="cmTitle">Event</h3>
            <div class="pvmeta" id="cmMeta"></div>
          </div>
          <span class="pvpill" id="cmPill"></span>
          <button type="button" class="pvx" id="cmClose" aria-label="Close">&times;</button>
        </div>
        <div class="cmbody" id="cmBody"><div class="pvwait">Loading…</div></div>
        <div class="cmvote" id="cmVote" hidden>
          <div class="cmvote-q">Your decision</div>
          <div class="cmreason" id="cmReasonBox" hidden>
            <label for="cmReason">Justification for rejecting <span class="cmreq">required</span></label>
            <textarea id="cmReason" rows="3" maxlength="2000"
                      placeholder="What would need to change for you to approve it?"></textarea>
          </div>
          <div class="cmbtns">
            <span class="cmnote" id="cmNote"></span>
            <button type="button" class="btn btn-sm" id="cmCancel" hidden>Cancel</button>
            <button type="button" class="btn btn-sm btn-danger" id="cmReject">✕ Reject</button>
            <button type="button" class="btn btn-sm pvgo" id="cmApprove">✓ Approve</button>
          </div>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var API = <?= json_encode($api) ?>, CSRF = <?= json_encode(fabo_csrf_token()) ?>;
      var $ = function (id) { return document.getElementById(id); };
      var modal = $('cmModal'), current = null, rejecting = false;

      function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
      }
      function initials(name) {
        return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
      }
      function day(d) {
        if (!d) { return ''; }
        var t = new Date(d + 'T00:00:00');
        return isNaN(t) ? d : t.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
      }

      function draw(res) {
        var s = res.status, ev = res.event;
        $('cmTitle').textContent = ev.name || ev.ref || 'Event';
        $('cmMeta').textContent = [ev.ref, ev.type, ev.hub, ev.starts ? 'starts ' + day(ev.starts) : '']
          .filter(Boolean).join(' · ');
        var body = $('cmBody');
        body.innerHTML = '';
        if (!s) {
          $('cmPill').textContent = '';
          body.appendChild(el('div', 'pvwait', 'This event has no committee. Choose one in its Committee column.'));
          $('cmVote').hidden = true;
          return;
        }
        $('cmEyebrow').textContent = s.committee.name + ' · ' + s.committee.rule_label;
        var pill = $('cmPill');
        pill.textContent = s.state === 'approved' ? 'Approved' : (s.state === 'rejected' ? 'Rejected' : 'Waiting');
        pill.className = 'pvpill ' + (s.state === 'approved' ? 'ok' : (s.state === 'rejected' ? 'bad' : 'wait'));

        /* The tally as a bar: approved, rejected, still to answer. */
        var bar = el('div', 'cmbar');
        [['yes', s.yes], ['no', s.no], ['wait', s.pending]].forEach(function (p) {
          if (!p[1]) { return; }
          var seg = el('span', 'cmseg ' + p[0]);
          seg.style.flex = String(p[1]);
          bar.appendChild(seg);
        });
        body.appendChild(bar);
        var sum = el('div', 'cmsum');
        sum.appendChild(el('span', 'cmk yes', '✓ ' + s.yes + ' approved'));
        sum.appendChild(el('span', 'cmk no', '✕ ' + s.no + ' rejected'));
        sum.appendChild(el('span', 'cmk wait', '⏳ ' + s.pending + ' pending'));
        body.appendChild(sum);

        var list = el('ul', 'cmlist');
        s.members.forEach(function (m) {
          var li = el('li', 'cmm ' + m.decision);
          li.appendChild(el('span', 'cmav', initials(m.name)));
          var who = el('div', 'cmwho');
          who.appendChild(el('strong', null, m.name + (res.mine && res.mine.id === m.id ? ' (you)' : '')));
          if (m.when) { who.appendChild(el('small', null, m.when)); }
          if (m.justification) { who.appendChild(el('div', 'cmjust', m.justification)); }
          li.appendChild(who);
          li.appendChild(el('span', 'cmchip ' + m.decision,
            m.decision === 'approved' ? '✓ Approved' : (m.decision === 'rejected' ? '✕ Rejected' : '⏳ Pending')));
          list.appendChild(li);
        });
        body.appendChild(list);
        if (res.message) { body.appendChild(el('div', 'cmdone', res.message)); }

        $('cmVote').hidden = !res.can_vote;
        reset();
      }
      function reset() {
        rejecting = false;
        $('cmReasonBox').hidden = true;
        $('cmReason').value = '';
        $('cmReason').classList.remove('cmneed');
        $('cmCancel').hidden = true;
        $('cmApprove').hidden = false;
        $('cmReject').textContent = '✕ Reject';
        $('cmNote').textContent = '';
      }
      function load(id) {
        current = id;
        return fetch(API + '&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res || !res.ok) { throw new Error((res && res.error) || 'It could not be read.'); }
            draw(res);
            return res;
          });
      }
      function open(id) {
        modal.hidden = false;
        $('cmBody').innerHTML = '<div class="pvwait">Loading…</div>';
        $('cmVote').hidden = true;
        load(id).catch(function (e) { $('cmBody').innerHTML = ''; $('cmBody').appendChild(el('div', 'pvwait', e.message)); });
      }
      /* After a vote, the grid row and the card follow without a reload. */
      function follow(res) {
        var s = res.status;
        var tr = document.querySelector('tr[data-id="' + current + '"]');
        if (tr && s) {
          var cell = tr.querySelector('td[data-field="committee_state"] .cell');
          if (cell) { cell.textContent = s.label; }
          var td = tr.querySelector('td[data-field="committee_state"]');
          if (td) { td.classList.remove('tone-good', 'tone-warn', 'tone-risk'); td.classList.add(s.tone); }
          var b = tr.querySelector('.js-committee');
          if (b) { b.className = 'js-committee cm-' + s.state; var n = b.querySelector('b'); if (n) { n.textContent = s.yes + '/' + s.total; } }
          if (s.state !== 'pending') {
            var ap = tr.querySelector('td[data-field="approval_state"] .cell');
            if (ap) { ap.textContent = s.state === 'approved' ? 'Approved' : 'Rejected'; }
          }
        }
        var card = document.querySelector('.cmw-item[data-id="' + current + '"]');
        if (card) {
          card.classList.add('done');
          var st = card.querySelector('.cmw-state');
          if (st) { st.textContent = res.mine && res.mine.decision === 'approved' ? '✓ You approved' : '✕ You rejected'; }
          card.querySelectorAll('button').forEach(function (b) { b.remove(); });
          var cnt = document.getElementById('cmwCount');
          if (cnt) { cnt.textContent = Math.max(0, parseInt(cnt.textContent, 10) - 1); }
        }
      }
      function vote(decision) {
        var why = $('cmReason').value.trim();
        if (decision === 'rejected' && why.length < 5) {
          $('cmReason').classList.add('cmneed');
          $('cmReason').focus();
          $('cmNote').textContent = 'Say why you are rejecting it.';
          return;
        }
        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'vote');
        fd.append('id', current);
        fd.append('decision', decision);
        fd.append('justification', why);
        ['cmApprove', 'cmReject'].forEach(function (b) { $(b).disabled = true; });
        $('cmNote').textContent = 'Saving…';
        fetch(API + '&id=' + encodeURIComponent(current), { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res || !res.ok) { throw new Error((res && res.error) || 'That did not go through.'); }
            draw(res);
            follow(res);
          })
          .catch(function (e) { $('cmNote').textContent = e.message; })
          .then(function () { ['cmApprove', 'cmReject'].forEach(function (b) { $(b).disabled = false; }); });
      }

      document.addEventListener('click', function (ev) {
        var b = ev.target.closest('.js-committee, [data-cm-open]');
        if (b) {
          var id = b.getAttribute('data-cm-open') || (b.closest('tr[data-id]') || {}).getAttribute && b.closest('tr[data-id]').getAttribute('data-id');
          if (id) { open(id); }
          return;
        }
        var q = ev.target.closest('[data-cm-approve]');
        if (q) {
          current = q.getAttribute('data-cm-approve');
          q.disabled = true;
          var fd = new FormData();
          fd.append('csrf', CSRF); fd.append('action', 'vote'); fd.append('id', current);
          fd.append('decision', 'approved'); fd.append('justification', '');
          fetch(API + '&id=' + encodeURIComponent(current), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (!res || !res.ok) { throw new Error((res && res.error) || 'That did not go through.'); }
              follow(res);
            })
            .catch(function (e) { q.disabled = false; alert(e.message); });
        }
      });
      $('cmApprove').addEventListener('click', function () { vote('approved'); });
      $('cmReject').addEventListener('click', function () {
        if (!rejecting) {
          rejecting = true;
          $('cmReasonBox').hidden = false;
          $('cmCancel').hidden = false;
          $('cmApprove').hidden = true;
          $('cmReject').textContent = '✕ Reject with this justification';
          $('cmReason').focus();
          return;
        }
        vote('rejected');
      });
      $('cmCancel').addEventListener('click', reset);
      $('cmClose').addEventListener('click', function () { modal.hidden = true; });
      modal.addEventListener('click', function (ev) { if (ev.target === modal) { modal.hidden = true; } });
      document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) { modal.hidden = true; } });
    })();
    </script>
    <?php
}

/**
 * "Committee events needing your approval" -- for committee members only.
 *
 * Nothing at all is drawn for somebody who sits on no committee. For a
 * member it lists the events waiting on their vote, soonest first, with a
 * quick Approve and a Review & decide that opens the full panel.
 */
function fabo_cm_card(array $viewer): void
{
    if (!fabo_cm_is_member($viewer)) {
        return;
    }
    $waiting = fabo_cm_waiting_for($viewer);
    ?>
    <div class="block cmw">
      <div class="cmw-head">
        <div>
          <h2>Committee events needing your approval</h2>
          <div class="hint">Events sent to a committee you sit on, waiting for your decision.</div>
        </div>
        <span class="cmw-count<?= $waiting ? '' : ' zero' ?>" id="cmwCount"><?= count($waiting) ?></span>
      </div>
      <?php if (!$waiting): ?>
        <div class="cmw-empty">✓ You are up to date — nothing is waiting for your decision.</div>
      <?php else: ?>
        <ul class="cmw-list">
          <?php foreach ($waiting as $r): $s = $r['_cm']; ?>
            <li class="cmw-item" data-id="<?= (int) $r['id'] ?>">
              <div class="cmw-main">
                <strong><?= e((string) ($r['event_name'] ?: $r['event_id'])) ?></strong>
                <span class="cmw-meta">
                  <?= e(implode(' · ', array_filter([
                      (string) $r['event_id'],
                      $r['start_date'] ? 'starts ' . date('d M Y', strtotime((string) $r['start_date'])) : '',
                      (string) ($r['hub_spoke'] ?? ''),
                      $s['committee']['name']]))) ?>
                </span>
                <span class="cmw-state"><?= e($s['label']) ?></span>
              </div>
              <div class="cmw-act">
                <button type="button" class="btn btn-sm" data-cm-open="<?= (int) $r['id'] ?>">Review &amp; decide</button>
                <button type="button" class="btn btn-sm pvgo" data-cm-approve="<?= (int) $r['id'] ?>"
                        title="Approve without opening it">✓ Approve</button>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * The View details pop-up: one programme in full -- its fields, its
 * attachments, its approval and, when it went to a committee, every
 * member's decision -- with Approve / Reject for a member still to decide
 * (a rejection always with remarks). Opened by any [data-cmd-open] button:
 * the committee dashboard's rows and the Programs Data grid's row icon.
 * See api/cmdesk.php.
 */
function fabo_cm_details_modal(array $viewer, int $open = 0): void
{
    $api = fabo_link($viewer, 'api/cmdesk.php');
    ?>
<div class="pvmodal" id="cmdModal" hidden>
  <div class="pvcard cmdcard" role="dialog" aria-modal="true" aria-labelledby="cmdTitle">
    <div class="pvhead">
      <div>
        <div class="cmeyebrow" id="cmdEyebrow">Committee approval</div>
        <h3 id="cmdTitle">Event</h3>
        <div class="pvmeta" id="cmdMeta"></div>
      </div>
      <span class="pvpill" id="cmdPill"></span>
      <button type="button" class="pvx" id="cmdClose" aria-label="Close">&times;</button>
    </div>
    <div class="cmbody" id="cmdBody"><div class="pvwait">Loading…</div></div>
    <div class="cmvote" id="cmdVote" hidden>
      <div class="cmvote-q">Your decision on this event and its budget</div>
      <?php /* Always open: a member notes what is wrong with the budget before
               choosing, and may add remarks to an approval too. */ ?>
      <div class="cmreason" id="cmdReasonBox">
        <label for="cmdReason">Remarks for the team <span class="cmreq">required to reject</span></label>
        <textarea id="cmdReason" rows="3" maxlength="2000"
                  placeholder="E.g. Venue quotation is higher than expected — get one more quote. Optional when you approve."></textarea>
      </div>
      <div class="cmbtns">
        <span class="cmnote" id="cmdNote"></span>
        <button type="button" class="btn btn-sm" id="cmdCancel" hidden>Cancel</button>
        <button type="button" class="btn btn-sm btn-danger" id="cmdReject">✕ Reject</button>
        <button type="button" class="btn btn-sm pvgo" id="cmdApprove">✓ Approve</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var API = <?= json_encode($api) ?>, CSRF = <?= json_encode(fabo_csrf_token()) ?>;
  var SEP = API.indexOf('?') < 0 ? '?' : '&';
  var $ = function (id) { return document.getElementById(id); };
  var modal = $('cmdModal'), current = null, rejecting = false, dirty = false;

  /* ---- the pop-up */
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }
  function initials(name) {
    return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
  }
  function day(d) {
    if (!d) { return ''; }
    var t = new Date(d + 'T00:00:00');
    return isNaN(t) ? d : t.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  }
  function section(body, title) {
    body.appendChild(el('h4', 'cmd-sec', title));
  }

  function draw(res) {
    var s = res.status, ev = res.event, body = $('cmdBody');
    $('cmdTitle').textContent = ev.name || ev.ref || 'Event';
    $('cmdMeta').textContent = [ev.ref, ev.type, ev.hub, ev.starts ? 'starts ' + day(ev.starts) : '']
      .filter(Boolean).join(' · ');
    $('cmdEyebrow').textContent = s ? s.committee.name + ' · ' + s.committee.rule_label : 'Programme';
    var a = res.approval || { state: 'pending' };
    var pill = $('cmdPill');
    pill.textContent = a.state === 'approved' ? 'Approved' : (a.state === 'rejected' ? 'Rejected' : 'Pending');
    pill.className = 'pvpill ' + (a.state === 'approved' ? 'ok' : (a.state === 'rejected' ? 'bad' : 'wait'));
    body.innerHTML = '';
    if (res.message) { body.appendChild(el('div', 'cmdone', res.message)); }

    /* The programme's approval, and why, when somebody has decided it. */
    if (a.state !== 'pending' && (a.remarks || a.by)) {
      body.appendChild(el('div', 'cmd-appr ' + a.state,
        (a.state === 'approved' ? '✓ Approved' : '✕ Rejected') + (a.by ? ' by ' + a.by : '')
        + (a.when ? ' · ' + a.when : '') + (a.remarks ? ' — ' + a.remarks : '')));
    }

    /* Event details */
    /* The budget first: it is what the committee mainly checks. Each head the
       team asked for, its amount, note and quotations, and where it stands. */
    section(body, 'Budget required' + (res.budget_total ? ' — ' + res.budget_total : ''));
    if (res.budget && res.budget.length) {
      var bt = el('table', 'data cmd-budget');
      var hd = el('tr');
      ['Budget head', 'Amount', 'Quotations', 'Status'].forEach(function (t) { hd.appendChild(el('th', null, t)); });
      var th = el('thead'); th.appendChild(hd); bt.appendChild(th);
      var tb = el('tbody');
      res.budget.forEach(function (b) {
        var tr = el('tr');
        var c1 = el('td');
        c1.appendChild(el('strong', null, b.head));
        if (b.note) { c1.appendChild(el('div', 'cmd-sub', b.note)); }
        tr.appendChild(c1);
        tr.appendChild(el('td', 'num', b.money));
        tr.appendChild(el('td', 'num', String(b.files || 0)));
        var c4 = el('td');
        c4.appendChild(el('span', 'cmchip ' + (b.state === 'draft' ? 'pending' : b.state), b.word));
        if (b.remarks) { c4.appendChild(el('div', 'cmd-sub', b.remarks)); }
        tr.appendChild(c4);
        tb.appendChild(tr);
      });
      bt.appendChild(tb);
      var wrap = el('div', 'scrollx'); wrap.appendChild(bt);
      body.appendChild(wrap);
      body.appendChild(el('div', 'hint', 'The quotations are listed under Documents below.'));
    } else {
      body.appendChild(el('div', 'hint', 'No budget has been entered for this event yet.'));
    }

    section(body, 'Event details');
    if (res.details.length) {
      var dl = el('dl', 'cmd-dl');
      res.details.forEach(function (d) {
        dl.appendChild(el('dt', null, d.label));
        dl.appendChild(el('dd', d.long ? 'long' : null, d.value));
      });
      body.appendChild(dl);
    } else {
      body.appendChild(el('div', 'hint', 'No details filled in.'));
    }

    /* Attachments, by what they are for. Before the event the committee
       verifies the budget's quotations; after it, it reviews the invoices
       (with the quotations they were planned on beside them). */
    var done = res.stage === 'completed';
    var groups = done
      ? [['invoice', 'Documents / Invoices — for review', 'No invoices attached yet.'],
         ['quotation', 'Quotations (from planning)', 'No quotations were attached.'],
         ['event', 'Other event attachments', 'No other attachments.']]
      : [['quotation', 'Documents / Quotation — for verification', 'No quotations attached yet.'],
         ['event', 'Other event attachments', 'No other attachments.']];
    groups.forEach(function (g) {
      var mine = res.files.filter(function (f) { return (f.kind || 'event') === g[0]; });
      section(body, g[1] + ' (' + mine.length + ')');
      fileList(body, mine, g[2]);
    });
    /* An invoice filed before the event ended is still shown, under its kind. */
    if (!done) {
      var early = res.files.filter(function (f) { return f.kind === 'invoice'; });
      if (early.length) {
        section(body, 'Invoices (' + early.length + ')');
        fileList(body, early, '');
      }
    }

    drawVotes(res, s, body);
  }

  function fileList(body, files, emptyText) {
    if (files.length) {
      var ul = el('ul', 'cmd-files');
      files.forEach(function (f) {
        var li = el('li');
        var a = el('a', 'cmd-file');
        a.href = f.url;
        a.target = '_blank';
        a.rel = 'noopener';
        if (f.is_image) {
          var img = el('img');
          img.src = f.url;
          img.alt = '';
          img.loading = 'lazy';
          a.appendChild(img);
        } else {
          a.appendChild(el('span', 'cmd-ficon', (f.name.split('.').pop() || 'file').slice(0, 4).toUpperCase()));
        }
        var t = el('span', 'cmd-fname');
        t.appendChild(el('strong', null, f.name));
        t.appendChild(el('small', null, [f.where, f.amount, f.approved_by, f.size, f.by, f.when]
          .filter(Boolean).join(' · ')));
        a.appendChild(t);
        li.appendChild(a);
        ul.appendChild(li);
      });
      body.appendChild(ul);
    } else if (emptyText) {
      body.appendChild(el('div', 'hint', emptyText));
    }
  }

  function drawVotes(res, s, body) {
    /* The committee, when the programme was sent to one. */
    $('cmdVote').hidden = !res.can_vote;
    reset();
    if (!s) { return; }
    section(body, 'Committee decisions');
    var bar = el('div', 'cmbar');
    [['yes', s.yes], ['no', s.no], ['wait', s.pending]].forEach(function (p) {
      if (!p[1]) { return; }
      var seg = el('span', 'cmseg ' + p[0]);
      seg.style.flex = String(p[1]);
      bar.appendChild(seg);
    });
    body.appendChild(bar);
    var sum = el('div', 'cmsum');
    sum.appendChild(el('span', 'cmk yes', '✓ ' + s.yes + ' approved'));
    sum.appendChild(el('span', 'cmk no', '✕ ' + s.no + ' rejected'));
    sum.appendChild(el('span', 'cmk wait', '⏳ ' + s.pending + ' pending'));
    body.appendChild(sum);
    var list = el('ul', 'cmlist');
    s.members.forEach(function (m) {
      var li = el('li', 'cmm ' + m.decision);
      li.appendChild(el('span', 'cmav', initials(m.name)));
      var who = el('div', 'cmwho');
      who.appendChild(el('strong', null, m.name + (res.mine && res.mine.id === m.id ? ' (you)' : '')));
      if (m.when) { who.appendChild(el('small', null, m.when)); }
      if (m.justification) { who.appendChild(el('div', 'cmjust', m.justification)); }
      li.appendChild(who);
      li.appendChild(el('span', 'cmchip ' + m.decision,
        m.decision === 'approved' ? '✓ Approved' : (m.decision === 'rejected' ? '✕ Rejected' : '⏳ Pending')));
      list.appendChild(li);
    });
    body.appendChild(list);
  }
  function reset() {
    rejecting = false;
    $('cmdReasonBox').hidden = false;   // remarks stay open: optional to approve, required to reject
    $('cmdReason').value = '';
    $('cmdReason').classList.remove('cmneed');
    $('cmdCancel').hidden = true;
    $('cmdApprove').hidden = false;
    $('cmdReject').textContent = '✕ Reject';
    $('cmdNote').textContent = '';
  }
  function call(opts) {
    return fetch(API + SEP + 'id=' + encodeURIComponent(current), opts || { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) { throw new Error((res && res.error) || 'It could not be read.'); }
        draw(res);
        return res;
      });
  }
  function open(id) {
    current = id;
    modal.hidden = false;
    $('cmdBody').innerHTML = '<div class="pvwait">Loading…</div>';
    $('cmdVote').hidden = true;
    call().catch(function (e) { $('cmdBody').innerHTML = ''; $('cmdBody').appendChild(el('div', 'pvwait', e.message)); });
  }
  function close() {
    modal.hidden = true;
    if (dirty) { location.reload(); }   // the counts and the row follow the vote
  }
  function vote(decision) {
    var why = $('cmdReason').value.trim();
    if (decision === 'rejected' && why.length < 5) {
      $('cmdReason').classList.add('cmneed');
      $('cmdReason').focus();
      $('cmdNote').textContent = 'Write your remarks in the box above — they are required to reject.';
      return;
    }
    if (decision === 'approved' && !confirm('Approve this event and its budget?')) { return; }
    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'vote');
    fd.append('id', current);
    fd.append('decision', decision);
    fd.append('justification', why);
    ['cmdApprove', 'cmdReject'].forEach(function (b) { $(b).disabled = true; });
    $('cmdNote').textContent = 'Saving…';
    call({ method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function () { dirty = true; $('cmdBody').scrollTop = 0; })
      .catch(function (e) { $('cmdNote').textContent = e.message; })
      .then(function () { ['cmdApprove', 'cmdReject'].forEach(function (b) { $(b).disabled = false; }); });
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-cmd-open]');
    if (b) { open(b.getAttribute('data-cmd-open')); }
  });
  $('cmdApprove').addEventListener('click', function () { vote('approved'); });
  // One click: the remarks are already in the box above (vote() insists on them).
  $('cmdReject').addEventListener('click', function () { vote('rejected'); });
  $('cmdCancel').addEventListener('click', reset);
  $('cmdClose').addEventListener('click', close);
  modal.addEventListener('click', function (ev) { if (ev.target === modal) { close(); } });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) { close(); } });

  <?php if ($open > 0): ?>open(<?= json_encode((string) $open) ?>);<?php endif; ?>
})();
</script>
    <?php
}
