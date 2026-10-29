<?php
/**
 * Departments and media, on the Events sheet.
 *
 * Pick an event, give it departments, and hand each one a sealed link to a
 * live sheet. What they fill in comes back as ordinary event rows tagged
 * with their department, and the photographs land in one gallery.
 *
 * Only someone who may edit the sheet sees any of this: issuing a link that
 * lets a stranger write into the data is an edit, whatever else it looks
 * like.
 */

require_once __DIR__ . '/departments.php';
require_once __DIR__ . '/ui.php';

/** The event the block is working on: ?ev=, else the soonest one. */
/**
 * Which event this section is working on.
 *
 * The page's filters answer that: narrow to one event and it is that one.
 * There is deliberately no dropdown here — the filter bar above already
 * decides which events are in view, and a second control that also picked
 * an event was two answers to one question.
 *
 * ?ev= is still honoured, because links to a particular event's departments
 * were sent to people and should keep working, but it only ever picks from
 * the rows the filters left.
 */
function fabo_dept_pick(array $rows): ?array
{
    if (count($rows) === 1) {
        return $rows[0];
    }
    $want = (int) ($_GET['ev'] ?? 0);
    foreach ($rows as $r) {
        if ((int) $r['id'] === $want) {
            return $r;
        }
    }
    return null;                      // several match: the page says so below
}

/** How many departments each of these events has, in one query. */
/**
 * The event the create form starts on when the filters have not settled one.
 *
 * The next one coming up, because that is what somebody is most often about
 * to hand out sheets for; failing that, whatever is first. Only a default —
 * the form lets them choose.
 */
function fabo_dept_soonest(array $rows): ?array
{
    $today = fabo_today();
    $ahead = array_values(array_filter($rows,
        static fn($r) => !empty($r['start_date']) && $r['start_date'] >= $today));
    if ($ahead) {
        usort($ahead, static fn($a, $b) => strcmp((string) $a['start_date'], (string) $b['start_date']));
        return $ahead[0];
    }
    return $rows[0] ?? null;
}

function fabo_dept_counts_for(array $rows): array
{
    $ids = array_values(array_filter(array_map(
        static fn($r) => (int) ($r['id'] ?? 0), $rows)));
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $out  = [];
    try {
        foreach (fabo_all('SELECT event_row_id, COUNT(*) n FROM event_departments
                            WHERE event_row_id IN (' . $in . ') AND revoked_at IS NULL
                            GROUP BY event_row_id', str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['event_row_id']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        // No table yet. Nothing has departments, which is the honest answer.
    }
    return $out;
}

/**
 * The query the filter form has to carry forward.
 *
 * It is a GET form, so anything not put back as a hidden field is dropped the
 * moment it submits -- which on a client's portal includes the token that
 * says who they are.
 */
function fabo_dept_carry(): array
{
    $out = [];
    foreach (['k', 'sheet', 'client'] as $q) {
        if (isset($_GET[$q]) && $_GET[$q] !== '') {
            $out[$q] = (string) $_GET[$q];
        }
    }
    $out['view'] = 'departments';
    return $out;
}

function fabo_dept_block(array $viewer, array $rows, string $perm): void
{
    if ($perm !== 'edit') {
        return;
    }
    /* No guard on there being events in view. A department is not created
       against an event any more -- its sheet is its own -- so a portal with
       no programmes yet is exactly the portal that needs to share one. */
    /* Every department of every event in view, rather than one event at a
       time. Which event each belongs to is a column, not a question asked
       before anything is shown. */
    $eventById = [];
    foreach ($rows as $r) {
        $eventById[(int) $r['id']] = $r;
    }
    /* Every department, not those of one event: a department is no longer
       created against an event, and the ones that were still belong here. */
    /* Which hub or spoke the list is narrowed to, if any. Kept in the address
       so the view survives a reload and can be linked to. */
    $hubPick = trim((string) ($_GET['hs'] ?? ''));
    $hubAll  = fabo_hub_values();
    if ($hubPick !== '' && $hubAll && !in_array($hubPick, $hubAll, true)) {
        $hubPick = '';                // an option that no longer exists
    }
    $depts    = fabo_departments_all($viewer, $hubPick);
    $deptsAll = $hubPick !== '' ? fabo_departments_all($viewer) : $depts;
    $media = fabo_media_for(array_keys($eventById), 60, fabo_dept_portal($viewer));

    /* One event is still singled out, but only as the default on the create
       form: whichever the filters settled on, or the soonest that is coming
       up. Nothing else on the page depends on it. */
    $event   = fabo_dept_pick($rows) ?: fabo_dept_soonest($rows);
    $eventId = (int) ($event['id'] ?? 0);

    // A link just issued, passed through the session so a refresh cannot
    // re-show it. It is the only time the secret is readable.
    fabo_session();
    $fresh = $_SESSION['fabo_dept_link'] ?? null;
    unset($_SESSION['fabo_dept_link']);
    // What they typed, when a create was refused — see fabo_dept_handle().
    $keep = $_SESSION['fabo_dept_keep'] ?? [];
    unset($_SESSION['fabo_dept_keep']);

    $base = fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php', ['sheet' => 'events']);
    $sep  = str_contains($base, '?') ? '&' : '?';
    ?>
  <div class="block eventcal" id="depts">
    <h2>Departments &amp; collection</h2>

    <div class="deptwhich">
      <?= count($depts) ?> department<?= count($depts) === 1 ? '' : 's' ?> with a live sheet<?php
        if ($hubPick !== '') {
            echo ' in <strong>' . e($hubPick) . '</strong>, of ' . count($deptsAll);
        } ?>.
      <span class="deptpickhint">Each one fills in its own events. Their sheet carries the
        same columns as Programs Data, and follows any change made there.</span>
    </div>

    <?php /* Narrowing by hub or spoke. A plain GET form, so the view it leaves
             is in the address and can be bookmarked or sent to somebody. */ ?>
    <?php if ($hubAll): ?>
      <form method="get" class="depthsbar" action="<?= e(strtok($base, '?')) ?>">
        <?php foreach (fabo_dept_carry() as $ck => $cv): ?>
          <input type="hidden" name="<?= e($ck) ?>" value="<?= e($cv) ?>">
        <?php endforeach; ?>
        <label>Hub / Spoke
          <select name="hs" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach ($hubAll as $h): ?>
              <option value="<?= e($h) ?>"<?= $h === $hubPick ? ' selected' : '' ?>><?= e($h) ?></option>
            <?php endforeach; ?>
          </select></label>
        <noscript><button class="btn btn-sm">Filter</button></noscript>
        <?php if ($hubPick !== ''): ?>
          <a class="btn btn-sm" href="<?= e($base . $sep . 'view=departments') ?>">Clear</a>
        <?php endif; ?>
      </form>
    <?php endif; ?>

    <div class="deptfresh" id="deptfresh"<?= $fresh ? '' : ' hidden' ?>>
      <?php if ($fresh): ?>
        <strong id="deptfreshname">Link for <?= e($fresh['name']) ?></strong>
      <?php else: ?>
        <strong id="deptfreshname"></strong>
      <?php endif; ?>
      <p>Send it to the department. It is also kept in the Link column below, so it
         can be copied again at any time; New link replaces it and stops the old one.</p>
      <div class="deptlinkrow">
        <input class="deptlink" id="deptlink" value="<?= e($fresh['url'] ?? '') ?>" readonly
               onclick="this.select()" aria-label="Share link">
        <button type="button" class="btn btn-primary btn-sm js-copylink">Copy</button>
      </div>
    </div>
    <div class="deptmsg" id="deptmsg" hidden></div>

    <!-- ------------------------------------------------ the departments -->
    <?php if (!$depts): ?>
      <div class="empty" id="deptempty"><?= $hubPick !== ''
        ? 'No departments in ' . e($hubPick) . '. Clear the filter to see the rest.'
        : 'No departments yet. Add one below and you will get a link to send them.' ?></div>
    <?php else: ?>
      <div class="scrollx" id="depttablewrap">
        <table class="data">
          <thead><tr>
            <th>Department</th><th>Hub / Spoke</th><th>Contact</th><th>Link</th>
            <th class="num">Rows</th><th class="num">Photos</th><th>Opened</th><th></th>
          </tr></thead>
          <tbody id="deptbody">
          <?php foreach ($depts as $d):
              $state = fabo_dept_state($d);
              $c     = fabo_dept_counts((int) $d['id']);
              $tone  = ['ok' => 'good', 'expired' => 'warn', 'revoked' => 'risk'][$state];
              $word  = ['ok' => 'live', 'expired' => 'expired', 'revoked' => 'withdrawn'][$state];
          ?>
            <tr data-dept="<?= (int) $d['id'] ?>">
              <td><strong><?= e($d['name']) ?></strong></td>
              <td><?php if (!empty($d['hub_spoke'])): ?>
                    <span class="pill tone-info"><i class="dot"></i><?= e($d['hub_spoke']) ?></span>
                  <?php else: ?>
                    <span class="ro">not set</span>
                  <?php endif; ?></td>
              <td><?= e($d['contact_name'] ?: '—') ?>
                <?php if ($d['contact_email']): ?>
                  <div class="meta"><?= e($d['contact_email']) ?></div>
                <?php endif; ?></td>
              <td><?= fabo_dept_link_cell($d) ?></td>
              <td class="num"><?= (int) $c['rows'] ?></td>
              <td class="num"><?= (int) $c['media'] ?></td>
              <td><?= (int) $d['opened_count'] ?>&times;
                <?php if (!empty($d['last_opened_at'])): ?>
                  <div class="meta"><?= e(date('d M, H:i', strtotime((string) $d['last_opened_at']))) ?></div>
                <?php endif; ?></td>
              <td class="deptbtns">
                <form method="post" class="inline">
                  <?= fabo_csrf_field() ?>
                  <input type="hidden" name="dept_id" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm" name="dept_action" value="rotate"
                          title="Issue a new link; the old one stops working at once">New link</button>
                  <?php if ($state === 'revoked'): ?>
                    <button class="btn btn-sm" name="dept_action" value="restore">Restore</button>
                  <?php else: ?>
                    <button class="btn btn-sm" name="dept_action" value="revoke"
                            title="Stop the link working, keeping everything they sent">Withdraw</button>
                  <?php endif; ?>
                  <?php $sent = (int) fabo_dept_submit_counts((int) $d['id'])['sent']; if ($sent > 0): ?>
                    <button class="btn btn-sm" name="dept_action" value="reopen" data-sent="<?= $sent ?>"
                            title="Put the <?= $sent ?> submitted rows back on their sheet to edit and submit again"
                            >Send back to sheet</button>
                  <?php endif; ?>
                  <button class="btn btn-sm danger" name="dept_action" value="delete"
                          onclick="return confirm('Remove this department? Everything they sent is kept.');"
                          >Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- --------------------------------------------------- add one -->
    <form method="post" class="deptadd" id="deptadd">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="dept_action" value="create">
      <div class="deptaddrow">
        <?php /* No Event here. The sheet a department is sent is theirs to
                 fill in, and the events on it come from them — there is no
                 parent row to attach it to. */ ?>
        <?php
          /* A department is a business unit. Picking from the same list the
             sheet reports by means the rows a department sends land in the
             right column with no mapping and no second spelling of the same
             team. Anything already on the list is offered; adding to it is
             done where every other dropdown is managed. */
          require_once __DIR__ . '/lists.php';
          $deptUnits = fabo_list_values('Business Unit');
        ?>
        <label>Department (Business Unit)
          <select name="name" required>
            <option value="">Choose one&hellip;</option>
            <?php foreach ($deptUnits as $u): ?>
              <option value="<?= e($u) ?>"<?= (string) ($keep['name'] ?? '') === $u
                    ? ' selected' : '' ?>><?= e($u) ?></option>
            <?php endforeach; ?>
          </select></label>
        <?php if (!$deptUnits): ?>
          <div class="deptwarn">The Business Unit list is empty, so there is nothing to
            assign. Add the units on Sheet Management first.</div>
        <?php endif; ?>
        <?php /* Which hub or spoke the sheet is being shared for. Its own
                 question: the unit says who fills the sheet in, this says
                 where they sit, and one sheet per spoke is the usual shape. */ ?>
        <label>Hub / Spoke
          <select name="hub_spoke"<?= $hubAll ? ' required' : '' ?>>
            <option value="">Choose one&hellip;</option>
            <?php foreach ($hubAll as $h): ?>
              <option value="<?= e($h) ?>"<?= (string) ($keep['hub_spoke'] ?? $hubPick) === $h
                    ? ' selected' : '' ?>><?= e($h) ?></option>
            <?php endforeach; ?>
          </select></label>
        <?php if (!$hubAll): ?>
          <div class="deptwarn">The <?= e(FABO_HUB_LIST) ?> list is empty. Add your hubs and
            spokes on Sheet Management and they will be offered here.</div>
        <?php endif; ?>
        <label>Contact name<input name="contact_name" placeholder="who fills it in" maxlength="160"
               value="<?= e((string) ($keep['contact_name'] ?? '')) ?>"></label>
        <label>Contact email<input name="contact_email" type="email" placeholder="optional" maxlength="190"
               value="<?= e((string) ($keep['contact_email'] ?? '')) ?>"></label>
        <label>Link valid for
          <select name="days">
            <?php foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days',
                            '365' => 'a year'] as $dv => $dl):
                $sel = (string) ($keep['days'] ?? '30') === $dv ? ' selected' : ''; ?>
              <option value="<?= e($dv) ?>"<?= $sel ?>><?= e($dl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary">Create &amp; get link</button>
      </div>
      <p class="hint">The link is sealed: what travels in the URL is encrypted and
         signed, and is checked against a hash kept here. Each live link is listed
         above to copy again; withdraw or reissue it at any time.</p>
    </form>

    <!-- ----------------------------------------------- the media -->
    <h3 class="deptmediahead">Event media
      <span class="deptcount"><?= count($media) ?> image<?= count($media) === 1 ? '' : 's' ?></span>
    </h3>
    <?php if (!$media): ?>
      <div class="empty">No photographs yet. Departments can upload them from their
        live sheet, and every image lands here.</div>
    <?php else: ?>
      <div class="deptgallery wide">
        <?php foreach ($media as $m): ?>
          <figure>
            <a href="<?= e(fabo_root_link($viewer, 'download.php', ['f' => (int) $m['id']])) ?>" target="_blank" rel="noopener">
              <img src="<?= e(fabo_root_link($viewer, 'download.php', ['f' => (int) $m['id'], 'thumb' => 1])) ?>"
                   alt="<?= e($m['caption'] ?: $m['original_name']) ?>" loading="lazy">
            </a>
            <figcaption>
              <?= e($m['caption'] ?: $m['original_name']) ?>
              <small><?= e($m['dept_name'] ?: 'uploaded here') ?>
                · <?= e(fabo_human_size((int) $m['size_bytes'])) ?></small>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    var add = document.getElementById('deptadd');
    if (!add) { return; }
    var API  = <?= json_encode($viewer['is_admin'] ? '../api/' : 'api/') ?>;
    var CSRF = <?= json_encode(fabo_csrf_token()) ?>;
    var TOKEN = <?= json_encode(($viewer['is_admin'] || fabo_signed_in())
                                ? '' : (string) $viewer['client']['token']) ?>;
    /* Kept at 0: departments are not created against an event any more.
       The scripts still post it, harmlessly, and removing it from every
       fetch is a change with no benefit. */
    var EV = 0;

    var fresh = document.getElementById('deptfresh');
    var msg   = document.getElementById('deptmsg');

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    function say(text, bad) {
      if (!msg) { return; }
      msg.textContent = text;
      msg.className = 'deptmsg' + (bad ? ' bad' : '');
      msg.hidden = !text;
    }

    function showLink(name, url) {
      if (!fresh || !url) { return; }
      document.getElementById('deptfreshname').textContent = 'Link for ' + name;
      document.getElementById('deptlink').value = url;
      fresh.hidden = false;
      fresh.scrollIntoView({ block: 'nearest' });
    }

    function call(params) {
      var body = new URLSearchParams(params);
      body.set('csrf', CSRF);
      body.set('sheet', 'events');
      body.set('ev', EV);
      if (TOKEN) { body.set('k', TOKEN); }
      return fetch(API + 'dept.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                   'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        body: body.toString()
      }).then(function (r) { return r.json(); });
    }

    /* One row of the table, built from what the server sent back. */
    function rowHtml(d) {
      var dash = String.fromCharCode(8212), times = String.fromCharCode(215);
      return '<td><strong>' + esc(d.name) + '</strong></td>' +
        '<td>' + (d.hub
          ? '<span class="pill tone-info"><i class="dot"></i>' + esc(d.hub) + '</span>'
          : '<span class="ro">not set</span>') + '</td>' +
        '<td>' + esc(d.contact || dash) +
          (d.email ? '<div class="meta">' + esc(d.email) + '</div>' : '') + '</td>' +
        /* Drawn by the server (fabo_dept_link_cell), so it cannot drift
           from the row the page was first drawn with. */
        '<td>' + d.linkcell + '</td>' +
        '<td class="num">' + d.rows + '</td>' +
        '<td class="num">' + d.media + '</td>' +
        '<td>' + d.opened + times +
          (d.last ? '<div class="meta">' + esc(d.last) + '</div>' : '') + '</td>' +
        '<td class="deptbtns"><form method="post" class="inline">' +
          '<input type="hidden" name="csrf" value="' + esc(CSRF) + '">' +
          '<input type="hidden" name="dept_id" value="' + d.id + '">' +
          '<input type="hidden" name="ev" value="' + EV + '">' +
          '<button class="btn btn-sm" name="dept_action" value="rotate">New link</button>' +
          (d.state === 'revoked'
            ? '<button class="btn btn-sm" name="dept_action" value="restore">Restore</button>'
            : '<button class="btn btn-sm" name="dept_action" value="revoke">Withdraw</button>') +
          (d.sent > 0
            ? '<button class="btn btn-sm" name="dept_action" value="reopen" data-sent="' + d.sent + '">Send back to sheet</button>'
            : '') +
          '<button class="btn btn-sm danger" name="dept_action" value="delete">Remove</button>' +
        '</form></td>';
    }

    /* The table may not exist yet — the first department creates it. */
    function tbody() {
      var b = document.getElementById('deptbody');
      if (b) { return b; }
      var empty = document.getElementById('deptempty');
      if (!empty) { return null; }
      var wrap = document.createElement('div');
      wrap.className = 'scrollx';
      wrap.id = 'depttablewrap';
      wrap.innerHTML =
        '<table class="data"><thead><tr>' +
        '<th>Department</th><th>Hub / Spoke</th><th>Contact</th><th>Link</th>' +
        '<th class="num">Rows</th><th class="num">Photos</th><th>Opened</th><th></th>' +
        '</tr></thead><tbody id="deptbody"></tbody></table>';
      empty.parentNode.replaceChild(wrap, empty);
      return document.getElementById('deptbody');
    }

    function place(d) {
      var body = tbody();
      if (!body) { return; }
      var tr = body.querySelector('tr[data-dept="' + d.id + '"]');
      if (!tr) {
        tr = document.createElement('tr');
        tr.setAttribute('data-dept', d.id);
        body.appendChild(tr);
      }
      tr.innerHTML = rowHtml(d);
    }

    /* ---- adding, without taking the page down and back up again ---- */
    add.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var name = add.querySelector('[name=name]');
      if (!name.value.trim()) { name.focus(); say('Give the department a name.', true); return; }

      var btn = add.querySelector('button[type=submit], button:not([type])');
      if (btn) { btn.disabled = true; }
      say('Saving...');

      call({
        dept_action:   'create',
        name:          name.value.trim(),
        hub_spoke:     (add.querySelector('[name=hub_spoke]') || {}).value || '',
        contact_name:  (add.querySelector('[name=contact_name]') || {}).value || '',
        contact_email: (add.querySelector('[name=contact_email]') || {}).value || '',
        days:          (add.querySelector('[name=days]') || {}).value || '30'
      }).then(function (res) {
        if (btn) { btn.disabled = false; }
        if (!res.ok) { say(res.error || 'That did not save.', true); return; }
        /* Saved: clear the form so the next one starts fresh. It is left
           exactly as typed when the save fails, which is the whole point. */
        add.reset();
        place(res.dept);
        showLink(res.dept.name, res.link);
        say(res.message);
        name.focus();
      }).catch(function () {
        if (btn) { btn.disabled = false; }
        say('Could not reach the server.', true);
      });
    });

    /* ---- the per-row buttons, likewise in place ---- */
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.deptbtns button[name=dept_action]') : null;
      if (!b) { return; }
      ev.preventDefault();
      var act = b.value;
      var tr  = b.closest('tr');
      var id  = tr ? tr.getAttribute('data-dept') : 0;
      if (act === 'delete' &&
          !window.confirm('Remove this department? Everything they sent is kept.')) { return; }
      if (act === 'reopen' &&
          !window.confirm('Send the ' + b.getAttribute('data-sent') + ' submitted rows back to this sheet? ' +
                          'They leave the main sheet until submitted again.')) { return; }

      b.disabled = true;
      say(act === 'rotate' ? 'Issuing a new link...' : 'Saving...');
      call({ dept_action: act, dept_id: id }).then(function (res) {
        b.disabled = false;
        if (!res.ok) { say(res.error || 'That did not work.', true); return; }
        if (res.removed) { if (tr) { tr.remove(); } }
        else if (res.dept) { place(res.dept); }
        if (res.link && res.dept) { showLink(res.dept.name, res.link); }
        say(res.message);
      }).catch(function () { b.disabled = false; say('Could not reach the server.', true); });
    });

    /* ---- copying the link ---- */
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest || !ev.target.closest('.js-copylink')) { return; }
      var b = ev.target.closest('.js-copylink');
      /* The input beside the button: the fresh-link box, or a row's own. */
      var row = b.closest('.deptlinkrow');
      var i = row ? row.querySelector('input.deptlink') : document.getElementById('deptlink');
      if (!i || !i.value) { return; }
      copyText(i, b);
    });

    function copyText(i, b) {
      var label = b.getAttribute('data-label') || b.textContent;
      b.setAttribute('data-label', label);
      i.select();
      var ok = false;
      /* execCommand is deprecated but is the only copy that works without a
         secure context, which a local install may not have. */
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      if (!ok && navigator.clipboard) { navigator.clipboard.writeText(i.value); ok = true; }
      b.textContent = ok ? 'Copied' : 'Press Ctrl+C';
      setTimeout(function () { b.textContent = label; }, 2000);
    }

    /* ---- Copy URL on a department whose link was never kept ----
       There is nothing to copy until a link is issued, and issuing one
       retires whatever was sent before -- so it asks first. */
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('.js-copynew') : null;
      if (!b) { return; }
      ev.preventDefault();
      var tr = b.closest('tr');
      var id = tr ? tr.getAttribute('data-dept') : 0;
      if (!window.confirm('This link was issued before links were kept here, so it cannot be ' +
          'shown. Issue a new one and copy it? The old link stops working.')) { return; }
      b.disabled = true;
      say('Issuing a link...');
      call({ dept_action: 'rotate', dept_id: id }).then(function (res) {
        b.disabled = false;
        if (!res.ok) { say(res.error || 'That did not work.', true); return; }
        if (res.dept) { place(res.dept); }
        var row = document.querySelector('#deptbody tr[data-dept="' + id + '"]');
        var i = row ? row.querySelector('input.deptlink') : null;
        var c = row ? row.querySelector('.js-copylink') : null;
        if (i && c) { copyText(i, c); }
        say('Link issued and copied. The previous one no longer works.');
      }).catch(function () { b.disabled = false; say('Could not reach the server.', true); });
    });

    /* ---- changing the expiry date, in place ---- */
    document.addEventListener('submit', function (ev) {
      var form = ev.target.closest ? ev.target.closest('form.deptexp') : null;
      if (!form) { return; }
      ev.preventDefault();
      var tr = form.closest('tr');
      var input = form.querySelector('[name=expires]');
      if (!input.value) { input.focus(); say('Pick a date first.', true); return; }
      var btn = form.querySelector('button');
      btn.disabled = true;
      say('Saving...');
      call({ dept_action: 'expiry', dept_id: tr ? tr.getAttribute('data-dept') : 0,
             expires: input.value }).then(function (res) {
        btn.disabled = false;
        if (!res.ok) { say(res.error || 'That did not save.', true); return; }
        if (res.dept) { place(res.dept); }
        say(res.message);
      }).catch(function () { btn.disabled = false; say('Could not reach the server.', true); });
    });
  })();
  </script>
<?php
}

/**
 * Performs one department action. Returns [tone, message, extra].
 *
 * The single place the actions live, so the JSON endpoint and the plain
 * form post cannot come to different conclusions about who may do what.
 */
function fabo_dept_act(array $viewer, array $in): array
{
    $action = (string) ($in['dept_action'] ?? '');

    /* Whose departments these are. A department is no longer attached to an
       event, so the event can no longer be the thing that proves ownership —
       the portal it was made in is. */
    require_once __DIR__ . '/lists.php';
    $mine = fabo_dept_portal($viewer);

    if ($action === 'create') {
        try {
            /* Ours are stored as NULL rather than 0, which is how every
               department made here before portals existed is stored too. */
            [$id, $url] = fabo_dept_create(null, $mine > 0 ? $mine : null, $in);
        } catch (InvalidArgumentException $e) {
            return ['err', $e->getMessage(), []];
        }
        $d = fabo_department($id);
        return ['ok', 'Department added.', ['link' => $url, 'dept' => fabo_dept_payload($d)]];
    }

    $deptId = (int) ($in['dept_id'] ?? 0);
    $dept   = fabo_department($deptId);
    if (!$dept) {
        return ['err', 'That department was not found.', []];
    }
    /* A posted id is a claim, not a fact: an id from another portal must not
       be actionable just because somebody typed it. This applies to us as
       much as to a client -- a client's shared sheet is not ours to withdraw,
       reissue or delete. */
    if (!fabo_dept_is_ours($viewer, $dept)) {
        return ['err', 'That department was not found.', []];
    }

    switch ($action) {
        case 'rotate':
            $url = fabo_dept_rotate($deptId);
            return ['ok', 'A new link was issued. The previous one no longer works.',
                    ['link' => $url, 'dept' => fabo_dept_payload(fabo_department($deptId))]];
        case 'expiry':
            try {
                fabo_dept_set_expiry($deptId, (string) ($in['expires'] ?? ''));
            } catch (InvalidArgumentException $e) {
                return ['err', $e->getMessage(), []];
            }
            $d = fabo_department($deptId);
            return ['ok', 'The link now works until '
                    . date('d M Y', strtotime((string) $d['expires_at'])) . '.',
                    ['dept' => fabo_dept_payload($d)]];
        case 'revoke':
            fabo_dept_revoke($deptId);
            return ['ok', 'Link withdrawn. Everything they sent is kept.',
                    ['dept' => fabo_dept_payload(fabo_department($deptId))]];
        case 'restore':
            fabo_dept_restore($deptId);
            return ['ok', 'Link restored.',
                    ['dept' => fabo_dept_payload(fabo_department($deptId))]];
        case 'reopen':
            $n = fabo_dept_reopen($deptId);
            return ['ok', $n > 0
                    ? $n . ' submitted row' . ($n === 1 ? ' is' : 's are') . ' back on their sheet to edit and submit again.'
                    : 'Nothing has been submitted from this sheet.',
                    ['dept' => fabo_dept_payload(fabo_department($deptId))]];
        case 'delete':
            fabo_dept_delete($deptId);
            return ['ok', 'Department removed. Their rows and photographs are kept.',
                    ['removed' => $deptId]];
    }
    return ['err', 'Unknown action.', []];
}

/** One department as the page's script needs it. */
function fabo_dept_payload(?array $d): ?array
{
    if (!$d) {
        return null;
    }
    $state = fabo_dept_state($d);
    $c     = fabo_dept_counts((int) $d['id']);
    return [
        'id'      => (int) $d['id'],
        'name'    => (string) $d['name'],
        'hub'     => (string) ($d['hub_spoke'] ?? ''),
        'contact' => (string) ($d['contact_name'] ?? ''),
        'email'   => (string) ($d['contact_email'] ?? ''),
        'state'   => $state,
        'word'    => ['ok' => 'live', 'expired' => 'expired', 'revoked' => 'withdrawn'][$state],
        'tone'    => ['ok' => 'good', 'expired' => 'warn', 'revoked' => 'risk'][$state],
        'until'   => !empty($d['expires_at']) ? date('d M Y', strtotime((string) $d['expires_at'])) : '',
        'url'     => fabo_dept_link($d),
        'linkcell' => fabo_dept_link_cell($d),
        'rows'    => (int) $c['rows'],
        'sent'    => (int) fabo_dept_submit_counts((int) $d['id'])['sent'],
        'media'   => (int) $c['media'],
        'opened'  => (int) $d['opened_count'],
        'last'    => !empty($d['last_opened_at'])
                     ? date('d M, H:i', strtotime((string) $d['last_opened_at'])) : '',
    ];
}

/**
 * Handles a plain (non-script) department form post. Returns a flash, or
 * null when the post was not ours. Called before any output, because it
 * redirects.
 */
function fabo_dept_handle(array $viewer, string $perm): ?array
{
    $action = (string) ($_POST['dept_action'] ?? '');
    if ($action === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }
    fabo_csrf_check();
    if ($perm !== 'edit') {
        return ['err', 'You need edit access to this sheet to manage departments.'];
    }

    [$tone, $message, $extra] = fabo_dept_act($viewer, $_POST);
    fabo_session();

    if ($tone === 'err') {
        /* Hand back what they typed. Losing a filled-in form because one
           field was missed is the kind of small cruelty that makes people
           stop using a page. */
        $_SESSION['fabo_dept_keep'] = [
            'name'          => (string) ($_POST['name'] ?? ''),
            'hub_spoke'     => (string) ($_POST['hub_spoke'] ?? ''),
            'contact_name'  => (string) ($_POST['contact_name'] ?? ''),
            'contact_email' => (string) ($_POST['contact_email'] ?? ''),
            'days'          => (string) ($_POST['days'] ?? '30'),
        ];
    } elseif (!empty($extra['link'])) {
        $_SESSION['fabo_dept_link'] = [
            'name' => (string) ($extra['dept']['name'] ?? 'this department'),
            'url'  => (string) $extra['link'],
        ];
    }
    return [$tone, $message];
}

/**
 * The Link cell of a department's row: its state, the link with Copy URL,
 * and the date it runs until, which can be moved here. Drawn by the server
 * both for the page and for every in-place update, so the two always agree.
 */
function fabo_dept_link_cell(array $d): string
{
    $state = fabo_dept_state($d);
    $tone  = ['ok' => 'good', 'expired' => 'warn', 'revoked' => 'risk'][$state];
    $word  = ['ok' => 'live', 'expired' => 'expired', 'revoked' => 'withdrawn'][$state];
    $exp   = !empty($d['expires_at']) ? (int) strtotime((string) $d['expires_at']) : 0;

    $h = '<span class="pill tone-' . e($tone) . '"><i class="dot"></i>' . e($word) . '</span>';
    if ($exp) {
        $h .= '<div class="meta">until ' . e(date('d M Y', $exp)) . '</div>';
    }

    $link = fabo_dept_link($d);
    if ($link !== '') {
        $h .= '<div class="deptlinkrow deptlinkcell">'
            . '<input class="deptlink" value="' . e($link) . '" readonly onclick="this.select()"'
            . ' aria-label="Share link for ' . e((string) $d['name']) . '">'
            . '<button type="button" class="btn btn-sm btn-primary js-copylink">Copy URL</button>'
            . '<a class="btn btn-sm" href="' . e($link) . '" target="_blank" rel="noopener">Open</a>'
            . '</div>';
    } elseif ($state !== 'revoked' && empty($d['link_token'])) {
        /* Issued before links were kept: the copy that would let it be shown
           was never saved, so copying it means issuing a new one. */
        $h .= '<div class="deptlinkrow deptlinkcell">'
            . '<button type="button" class="btn btn-sm btn-primary js-copynew"'
            . ' title="Issues a new link and copies it; the old one stops working">Copy URL</button>'
            . '</div>';
    }

    /* The date, movable either way. Extending a lapsed link brings the same
       link back, so what was already sent starts working again. */
    if ($state !== 'revoked') {
        $h .= '<form method="post" class="deptexp">'
            . fabo_csrf_field()
            . '<input type="hidden" name="dept_id" value="' . (int) $d['id'] . '">'
            . '<input type="hidden" name="dept_action" value="expiry">'
            . '<label>Expires <input type="date" name="expires" required'
            . ' min="' . e(date('Y-m-d')) . '"'
            . ' value="' . e($exp > time() ? date('Y-m-d', $exp) : '') . '"></label>'
            . '<button class="btn btn-sm">Update</button>'
            . '</form>';
    }
    return $h;
}
