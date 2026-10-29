<?php
/**
 * Dropdown values. Every select cell reads from here, which reads the Lists
 * sheet — adding a value there needs no code change.
 */

require_once __DIR__ . '/db.php';

/**
 * Whose customisations apply to this viewer.
 *
 * 0 is the shared set: ours, and the default every client starts from. A
 * client — and anyone they created — gets their own, so one organisation
 * renaming a status or dropping an option never reaches another.
 */
function fabo_customiser(?array $viewer): int
{
    if (!$viewer || !empty($viewer['is_admin'])) {
        return 0;
    }
    $c   = $viewer['client'] ?? [];
    $org = (int) ($c['parent_id'] ?? 0);
    if ($org === 0 && ($c['kind'] ?? 'client') === 'client') {
        $org = (int) ($c['id'] ?? 0);
    }
    return $org;            // our own staff have no parent: shared set
}

/**
 * The viewer whose customisations this request should use.
 *
 * Set once where the viewer is known — the grid, an endpoint, a page head —
 * because fabo_sheet() is called from dozens of places that have no viewer
 * to hand and would otherwise all need one threaded through.
 */
function fabo_view_owner(?int $set = null): int
{
    static $owner = 0;
    if ($set !== null) {
        $owner = max(0, $set);
        // Whose page this is decides whose address its links carry too (lib/orghost.php).
        if (function_exists('fabo_link_org')) {
            fabo_link_org($owner);
        }
    }
    return $owner;
}

/**
 * May this viewer change what a dropdown offers and what a column is called?
 *
 * Us, and a client for their own organisation, on a sheet they can edit.
 * Their changes reach nobody else, which is what makes it safe to offer.
 *
 * The grid renders the controls from this, and the page hands the same
 * answer to the browser — because the two disagreeing is how you get a
 * dropdown that offers "Add option" and then refuses to add one.
 */
function fabo_may_customise(array $viewer, string $perm): bool
{
    if ($perm !== 'edit') {
        return false;
    }
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    /*
     * Anyone who may edit the sheet may shape its dropdowns — a team member
     * included. They are the people doing the work, so they are the ones who
     * hit a missing option, and making them ask the head of the organisation
     * for every new status is friction with nothing to show for it.
     *
     * What makes this safe is not who they are but where the change lands:
     * fabo_customiser() resolves them to their organisation and
     * fabo_list_adopt() gives that organisation its own copy of the list on
     * first edit. A change therefore cannot leave the hierarchy it was made
     * in. See fabo_list_scope_note() for what the panel tells them.
     */
    return !empty($viewer['client']);
}

/**
 * May this viewer rename a column?
 *
 * A narrower right than shaping a dropdown, deliberately. Adding a status is
 * additive and affects one cell's choices; renaming a column changes a
 * heading everybody in the organisation reads, on every row, and there is no
 * obvious way back for someone who did not mean to. So it stays with the
 * head of the organisation, as it always was.
 */
function fabo_may_rename_columns(array $viewer, string $perm): bool
{
    if ($perm !== 'edit') {
        return false;
    }
    require_once __DIR__ . '/deletions.php';
    return !empty($viewer['is_admin']) || fabo_may_delete($viewer);
}

/**
 * Whose set of options a viewer is about to change, in words.
 *
 * Shown in the manage panel, because "add an option" is a very different act
 * depending on the answer, and nobody should have to guess which one they are
 * performing. Returns '' when there is nothing worth saying.
 */
function fabo_list_scope_note(?array $viewer): string
{
    if (!$viewer) {
        return '';
    }
    if (!empty($viewer['is_admin'])) {
        return 'You are editing the shared defaults. Any client who has not '
             . 'customised this list will see the change.';
    }
    $owner = fabo_customiser($viewer);
    if ($owner > 0) {
        // Name the organisation that owns the list, which for a team member
        // is the client above them — not their own organisation field, which
        // is only what somebody typed on their record.
        $head = fabo_one('SELECT organisation, name FROM clients WHERE id = ?', 'i', [$owner]);
        $org  = trim((string) ($head['organisation'] ?? '')) ?: trim((string) ($head['name'] ?? ''));
        return 'This list belongs to ' . ($org !== '' ? $org : 'your organisation')
             . '. Changes here are seen by your team and nobody else.';
    }
    // Our own staff sit at the root, so their set IS the shared one.
    return 'You are editing the shared defaults, which every client starts '
         . 'from. Clients who have already customised this list are unaffected.';
}

/**
 * A sheet this viewer may edit that actually uses $list, or '' if there is
 * none.
 *
 * api/listopts.php asks for a sheet and checks the list belongs to it —
 * that is what proves the caller may touch the list at all. A form outside
 * the grid (the team page, say) has no sheet of its own, so it borrows one
 * the viewer already holds.
 */
function fabo_list_editor_sheet(array $viewer, string $list): string
{
    require_once __DIR__ . '/sheets.php';
    require_once __DIR__ . '/auth.php';

    if ($list === '' || fabo_is_dynamic_list($list)) {
        return '';
    }
    foreach (fabo_sheet_order() as $key) {
        if ($key === 'dashboard' || !fabo_may_customise($viewer, fabo_permission($viewer, $key))) {
            continue;
        }
        foreach ((fabo_sheet($key)['columns'] ?? []) as $c) {
            if (($c[2] ?? '') === 'select' && ($c[3]['list'] ?? '') === $list) {
                return $key;
            }
        }
    }
    return '';
}


/**
 * Every dropdown list, as one organisation sees it.
 *
 * A client's own rows win outright for a given list; where they have none,
 * they see the shared defaults. That is what makes "add" and "remove" mean
 * something for them without a new client starting with empty dropdowns.
 */
function fabo_lists_all(?int $owner = null): array
{
    static $cache = [];
    $owner = $owner === null ? fabo_view_owner() : max(0, $owner);
    if (isset($cache[$owner])) {
        return $cache[$owner];
    }

    $shared = [];
    $mine   = [];
    $rows = $owner > 0
        ? fabo_all('SELECT list_name, value, client_id FROM lists
                    WHERE active = 1 AND value IS NOT NULL AND value <> ""
                      AND client_id IN (0, ?)
                    ORDER BY list_name, sort_order, value', 'i', [$owner])
        : fabo_all('SELECT list_name, value, client_id FROM lists
                    WHERE active = 1 AND value IS NOT NULL AND value <> ""
                      AND client_id = 0
                    ORDER BY list_name, sort_order, value');

    foreach ($rows as $r) {
        if ((int) $r['client_id'] === 0) {
            $shared[$r['list_name']][] = $r['value'];
        } else {
            $mine[$r['list_name']][] = $r['value'];
        }
    }
    // Theirs replaces the default for that list, rather than adding to it —
    // otherwise a removed default would come straight back.
    $cache[$owner] = $mine + $shared;
    return $cache[$owner];
}

function fabo_list_values(string $name, ?int $owner = null): array
{
    return fabo_lists_all($owner)[$name] ?? [];
}

/**
 * May this value go into this dropdown column?
 *
 * Only what the column's list offers (for the organisation looking), a blank,
 * or the value the cell already holds -- so an old value is never forced out,
 * but nothing new that is not on the list can be put in. Before this, any
 * text posted to a dropdown column was stored, and every stray value then
 * showed up in that column's dropdown as if it were an option.
 *
 * Returns '' when it may, or the sentence to refuse it with. Columns that are
 * not dropdowns, and lists built from live data (@...), are not judged here.
 */
function fabo_list_refusal(array $col, $value, $current = null): string
{
    $list = (string) ($col[3]['list'] ?? '');
    if (($col[2] ?? '') !== 'select' || $list === '' || fabo_is_dynamic_list($list)) {
        return '';
    }
    $v = trim((string) ($value ?? ''));
    if ($v === '' || $v === trim((string) ($current ?? ''))) {
        return '';
    }
    $allowed = fabo_list_values($list);
    if (!$allowed || in_array($v, $allowed, true)) {
        return '';                   // an empty list judges nothing
    }
    return '"' . $v . '" is not one of the ' . $col[0] . ' options. Pick one from the list'
         . ' (or add it to the list first).';
}

/** Has this organisation taken over a list, or is it still on the default? */
function fabo_list_is_own(int $owner, string $list): bool
{
    if ($owner <= 0) {
        return true;
    }
    return fabo_one('SELECT id FROM lists WHERE client_id = ? AND list_name = ? LIMIT 1',
                    'is', [$owner, $list]) !== null;
}

/**
 * Copy-on-write: the first time an organisation edits a list, the shared
 * defaults are copied into their own set so they start from what they were
 * already seeing rather than from nothing.
 */
function fabo_list_adopt(int $owner, string $list): void
{
    if ($owner <= 0 || fabo_list_is_own($owner, $list)) {
        return;
    }
    foreach (fabo_all('SELECT value, sort_order, active FROM lists
                       WHERE client_id = 0 AND list_name = ?
                       ORDER BY sort_order, value', 's', [$list]) as $r) {
        fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id)
                   VALUES (?,?,?,?,?)', 'ssiii',
                  [$list, $r['value'], (int) $r['sort_order'], (int) $r['active'], $owner]);
    }
}

/**
 * A list whose name begins with @ is not kept on the Lists sheet — it is built
 * from live data, and cannot be edited from inside a cell.
 */
function fabo_is_dynamic_list(string $name): bool
{
    return $name !== '' && $name[0] === '@';
}

/**
 * How one person appears in a dropdown of people. The organisation is part of
 * the label so two Ravis in different companies are never the same option.
 */
function fabo_client_option_label(array $c): string
{
    $org = trim((string) ($c['organisation'] ?? ''));
    return trim((string) ($c['name'] ?? '')) . ($org !== '' ? ' · ' . $org : '');
}

/**
 * A list of people keyed by the label the dropdown shows, guaranteed unique.
 *
 * Two people can share a name and an organisation. Left alone, one would
 * silently shadow the other and a row would go to whichever happened to be
 * last — so a repeat carries its email address, and failing that its id.
 *
 * The grid and the save endpoint both build the map this way, so the label a
 * person picks is always resolved back to the person they picked.
 */
function fabo_client_option_map(array $clients): array
{
    $out = [];
    foreach ($clients as $c) {
        $label = fabo_client_option_label($c);
        if (isset($out[$label])) {
            $email = trim((string) ($c['email'] ?? ''));
            $label .= ' (' . ($email !== '' ? $email : '#' . (int) $c['id']) . ')';
            // Still taken? Two accounts share a name, an org and an address.
            if (isset($out[$label])) {
                $label .= ' #' . (int) $c['id'];
            }
        }
        $out[$label] = $c;
    }
    return $out;
}

/**
 * The values behind a dynamic list.
 *
 * '@clients' is everyone this viewer may send a row to on this sheet — the
 * clients and team members they created, or, for the admin, everyone who holds
 * the sheet. Empty when the viewer may not send rows at all, so the dropdown
 * simply has nothing to offer rather than offering something it would refuse.
 */
function fabo_dynamic_list(string $name, ?array $viewer, string $sheetKey): array
{
    // '@allclients' is every client on the books — the column that says which
    // client a row belongs to. It does not depend on who is looking, because
    // a row's owner is a fact about the row, not about the reader.
    if ($name === '@allclients') {
        return array_keys(fabo_client_option_map(fabo_all(
            "SELECT * FROM clients WHERE kind = 'client' AND revoked_at IS NULL
             ORDER BY organisation, name")));
    }
    /* '@committees' is the viewer's own organisation's approval committees
       -- never another's, so a client is never shown ours or anyone else's. */
    if ($name === '@committees') {
        require_once __DIR__ . '/committees.php';
        return fabo_cm_names($viewer);
    }
    if ($name !== '@clients' || !$viewer || $sheetKey === '') {
        return [];
    }
    // Loaded here rather than at the top: approvals.php reaches back to this
    // file through auth.php, and by call time everything is defined.
    require_once __DIR__ . '/approvals.php';
    if (!fabo_may_request($viewer, $sheetKey)) {
        return [];
    }
    return array_keys(fabo_client_option_map(fabo_request_candidates($viewer, $sheetKey)));
}

/** Every list a sheet's select columns need, ready for the client-side grid. */
function fabo_sheet_lists(array $sheet, ?array $viewer = null, string $sheetKey = ''): array
{
    $out = [];
    foreach ($sheet['columns'] as $c) {
        if ($c[2] !== 'select' || empty($c[3]['list'])) {
            continue;
        }
        $name = $c[3]['list'];
        $out[$name] = fabo_is_dynamic_list($name)
            ? fabo_dynamic_list($name, $viewer, $sheetKey)
            : fabo_list_values($name);
    }
    return $out;
}

/* ----------------------------------------------- an editable dropdown */

/**
 * A <select> backed by one of the portal's lists, with the same add, rename
 * and remove controls the sheet grid has.
 *
 * The grid gets this from grid.js, which only the sheet pages load. A form
 * elsewhere — the team page — needs the same thing, so it is rendered here
 * once and the small script below drives it.
 *
 * The controls only appear for someone who may actually change the list; a
 * viewer who may not gets a plain dropdown and no markers, because offering
 * an "Add option" that the server will refuse is worse than not offering it.
 *
 * $api is the path to api/ from the page doing the rendering.
 */
function fabo_list_field(string $field, string $list, string $current,
                         array $viewer, string $api, string $blank = '— none —'): void
{
    $values = fabo_list_values($list);
    $sheet  = fabo_list_editor_sheet($viewer, $list);
    // Unique per rendering: an edit form and a create form both carry this
    // field on the team page, and two elements cannot share an id.
    static $seq = 0;
    $id     = 'lf_' . preg_replace('~[^a-z0-9]+~i', '_', $field) . '_' . (++$seq);

    echo '<select name="' . e($field) . '" id="' . e($id) . '" class="js-listfield"'
       . ' data-list="' . e($list) . '" data-sheet="' . e($sheet) . '">';
    echo '<option value="">' . e($blank) . '</option>';
    $seen = false;
    foreach ($values as $v) {
        $sel = $v === $current ? ' selected' : '';
        if ($sel !== '') { $seen = true; }
        echo '<option value="' . e($v) . '"' . $sel . '>' . e($v) . '</option>';
    }
    // A value set before it was on the list stays selectable, so opening a
    // record and saving it does not quietly change what it says.
    if ($current !== '' && !$seen) {
        echo '<option value="' . e($current) . '" selected>' . e($current)
           . ' (not on the list)</option>';
    }
    if ($sheet !== '') {
        echo '<option disabled>──────────</option>';
        echo '<option value="__add__">＋ Add option…</option>';
        echo '<option value="__manage__">－ Remove / edit options…</option>';
    }
    echo '</select>';

    if ($sheet === '') {
        return;
    }
    echo '<div class="listfield-panel" hidden></div>';
    fabo_list_field_script($api);
}

/**
 * The script, printed once however many fields are on the page.
 *
 * Deliberately standalone: these pages do not load grid.js, and pulling the
 * whole grid in for one dropdown would be a poor trade. The panel it draws
 * uses the sheet's own classes — optrow, optadd, optmsg — so managing a
 * dropdown here looks and behaves exactly like managing one from inside a
 * sheet, down to the reorder arrows and Hide.
 */
function fabo_list_field_script(string $api): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<script>
(function () {
  var API  = <?= json_encode($api) ?>;
  var CSRF = <?= json_encode(fabo_csrf_token()) ?>;
  var ADD = '__add__', MANAGE = '__manage__';

  function params(sel, extra) {
    var p = new URLSearchParams(extra || {});
    p.set('sheet', sel.getAttribute('data-sheet'));
    p.set('list',  sel.getAttribute('data-list'));
    /* A portal page reached by a private link carries ?k=; pass it on. */
    var k = new URLSearchParams(location.search).get('k');
    if (k) { p.set('k', k); }
    return p;
  }

  /* Reading is a GET; only a change is posted. The endpoint refuses an
     action it does not know, so "just show me the list" cannot be one. */
  function read(sel) {
    return fetch(API + 'listopts.php?' + params(sel).toString(), {
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  function call(sel, extra) {
    var body = params(sel, extra);
    body.set('csrf', CSRF);
    return fetch(API + 'listopts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g,
      function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  function panelFor(sel) { return sel.parentNode.querySelector('.listfield-panel'); }

  /* Rebuild the dropdown itself, keeping the chosen value if it survives. */
  function repaint(sel, active, keep) {
    var want  = keep != null ? keep : sel.value;
    var blank = sel.options[0] ? sel.options[0].textContent : '';
    sel.innerHTML = '';
    sel.appendChild(new Option(blank, ''));
    var found = false;
    (active || []).forEach(function (v) {
      sel.appendChild(new Option(v, v));
      if (v === want) { found = true; }
    });
    var sep = new Option('──────────', '');
    sep.disabled = true;
    sel.appendChild(sep);
    sel.appendChild(new Option('＋ Add option…', ADD));
    sel.appendChild(new Option('－ Remove / edit options…', MANAGE));
    sel.value = found ? want : '';
  }

  /* The same panel the sheet draws, in the same classes. */
  function draw(sel, res) {
    var p = panelFor(sel);
    if (!p) { return; }
    var opts = res.options || [];
    var h = '<div class="lf-head">' + esc(sel.getAttribute('data-list'))
          + '<button type="button" class="lf-x" title="Close">&times;</button></div>'
          + '<div class="lf-body">'
          + (res.scope ? '<div class="optscope">' + esc(res.scope) + '</div>' : '')
          + '<div class="optmsg">' + esc(res.message || '') + '</div>'
          + '<div class="optadd"><input placeholder="Add an option…" autocomplete="off">'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="add">Add</button></div>';
    if (!opts.length) { h += '<div class="empty">No options yet.</div>'; }
    opts.forEach(function (o, i) {
      h += '<div class="optrow' + (o.active ? '' : ' hidden') + '" data-id="' + o.id + '">'
         + '<input class="oval" value="' + esc(o.value) + '" data-was="' + esc(o.value) + '" '
         + 'title="Edit the name, then press Save">'
         + '<button type="button" data-act="rename" class="osave" hidden '
         + 'title="Save the new name">Save</button>'
         + '<button type="button" data-act="up" title="Move up"'
         + (i === 0 ? ' disabled' : '') + '>&uarr;</button>'
         + '<button type="button" data-act="down" title="Move down"'
         + (i === opts.length - 1 ? ' disabled' : '') + '>&darr;</button>'
         + '<button type="button" data-act="toggle" title="'
         + (o.active ? 'Hide from the dropdown (rows keep it)' : 'Show again') + '">'
         + (o.active ? 'Hide' : 'Show') + '</button>'
         + '<button type="button" data-act="remove" class="danger" '
         + 'title="Remove from the list (no row is changed)">Remove</button>'
         + '</div>';
    });
    h += '<div class="hint" style="margin-top:10px">Everything here saves as you do it. '
       + 'A renamed option needs its <b>Save</b> button — it appears once you change '
       + 'the text. <b>Hide</b> stops offering a value but keeps it on rows that have it. '
       + '<b>Remove</b> deletes it from the list only. Data rows are never altered.</div></div>';
    p.innerHTML = h;
    p.hidden = false;
    repaint(sel, res.active);
  }

  /* A refused change is reported in the panel, where the person is looking. */
  function after(sel, res) {
    if (!res.ok) {
      var p = panelFor(sel);
      var m = p && p.querySelector('.optmsg');
      if (m) { m.textContent = res.error || 'That did not work.'; }
      else { window.alert(res.error || 'That did not work.'); }
      return;
    }
    draw(sel, res);
  }

  /* ------------------------------------ the two markers in the dropdown */

  document.addEventListener('focusin', function (ev) {
    var t = ev.target;
    if (t.classList && t.classList.contains('js-listfield')) {
      t.setAttribute('data-prev', t.value);
    }
  });

  document.addEventListener('change', function (ev) {
    var sel = ev.target;
    if (!sel.classList || !sel.classList.contains('js-listfield')) { return; }
    if (sel.value !== ADD && sel.value !== MANAGE) { return; }
    var wanted = sel.value;
    sel.value = sel.getAttribute('data-prev') || '';      /* never save the marker */
    if (wanted === MANAGE) {
      read(sel).then(function (res) { after(sel, res); });
      return;
    }
    var v = window.prompt('New option for "' + sel.getAttribute('data-list') + '":');
    if (!v || !v.trim()) { return; }
    call(sel, { action: 'add', value: v.trim() }).then(function (res) {
      if (!res.ok) { window.alert(res.error || 'Could not add it.'); return; }
      repaint(sel, res.active, v.trim());
    });
  });

  /* ------------------------------------------------- inside the panel */

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t.closest) { return; }
    var p = t.closest('.listfield-panel');
    if (!p) { return; }
    var sel = p.parentNode.querySelector('.js-listfield');
    if (!sel) { return; }

    if (t.classList.contains('lf-x')) { p.hidden = true; return; }
    var act = t.getAttribute('data-act');
    if (!act) { return; }
    ev.preventDefault();

    if (act === 'add') {
      var box = p.querySelector('.optadd input');
      if (!box || !box.value.trim()) { return; }
      var v = box.value.trim();
      call(sel, { action: 'add', value: v }).then(function (res) {
        if (res.ok) { box.value = ''; }
        after(sel, res);
      });
      return;
    }
    var row = t.closest('.optrow');
    if (!row) { return; }
    if (act === 'remove'
        && !window.confirm('Remove this option? No row that already uses it is changed.')) {
      return;
    }
    // A rename is the one action that carries a value: the edited text.
    var args = { action: act, id: row.getAttribute('data-id') };
    if (act === 'rename') { args.value = row.querySelector('input.oval').value.trim(); }
    call(sel, args).then(function (res) { after(sel, res); });
  });

  /* The Save button only exists once there is something to save, so the
     panel does not sit there offering to save what has not been touched. */
  document.addEventListener('input', function (ev) {
    if (!ev.target.matches || !ev.target.matches('.listfield-panel input.oval')) { return; }
    var save = ev.target.closest('.optrow').querySelector('.osave');
    if (save) { save.hidden = ev.target.value.trim() === ev.target.getAttribute('data-was'); }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || !ev.target.closest) { return; }
    var p = ev.target.closest('.listfield-panel');
    if (!p) { return; }
    ev.preventDefault();                       /* never submit the form */
    var sel = p.parentNode.querySelector('.js-listfield');
    if (!sel) { return; }
    if (ev.target.classList.contains('oval')) {
      // Enter still works, for anyone who never reaches for the mouse.
      var save = ev.target.closest('.optrow').querySelector('.osave');
      if (save && !save.hidden) { save.click(); }   // nothing changed, nothing to send
    } else if (ev.target.closest('.optadd')) {
      p.querySelector('.optadd [data-act="add"]').click();
    }
  });
})();
</script>
    <?php
}
