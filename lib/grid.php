<?php
/**
 * The one grid renderer every sheet uses. A view-only sheet renders the same
 * markup with every input read-only and no add or delete.
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/compute.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/checklist.php';

/** Columns that read as money / percent regardless of their stored type. */
function fabo_col_render(array $col): string
{
    [$label, $field, $type] = [$col[0], $col[1], $col[2]];
    $calc = $col[3]['calc'] ?? '';

    if ($type === 'money' || $calc === 'budget_remaining' || $calc === 'cpl'
        || ($calc === 'link_money')) {
        return 'money';
    }
    if ($calc === 'roi_spent' || $calc === 'roi_paid' || $calc === 'link_pct'
        || $calc === 'ctr' || $calc === 'content_er') {
        return 'pct';
    }
    if ($calc === 'store_roll') {
        return in_array($field, ['btl_spend', 'dm_spend', 'total_spend'], true) ? 'money' : 'num';
    }
    if ($type === 'int' || $calc === 'link_int') {
        return $field === 'week_number' ? 'text' : 'num';
    }
    if ($type === 'dec') {
        return 'dec';
    }
    return 'text';
}

/** What a cell shows. Server render and the live-sync endpoint share this. */
function fabo_display(array $col, array $row): string
{
    $field  = $col[1];
    $v      = $row[$field] ?? null;
    $render = fabo_col_render($col);

    if ($v === null || $v === '') {
        return '';
    }
    switch ($render) {
        case 'money': return fabo_money($v);
        case 'pct':   return fabo_pct($v);
        case 'num':   return fabo_thousands($v);
        case 'dec':   return rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');  // always has a '.'
        default:      return (string) $v;
    }
}

/** Raw value an editable input carries. */
function fabo_raw(array $col, array $row)
{
    $v = $row[$col[1]] ?? null;
    if ($v === null) {
        return '';
    }
    if ($col[2] === 'money' || $col[2] === 'dec') {
        // Trim only a fractional tail, so 45000.00 reads as 45000 and not 45.
        $s = (string) $v;
        return (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s) ?: '0';
    }
    return (string) $v;
}

/** Rows for a sheet, scoped to the viewer and with computed columns filled. */
function fabo_rows(string $key, array $viewer, array $opts = []): array
{
    $sheet = fabo_sheet($key);
    $scope = fabo_scope_where($viewer, $key);
    $order = $sheet['date_driver'] ? '`' . $sheet['date_driver'] . '` IS NULL, `'
                                   . $sheet['date_driver'] . '`, `id`' : '`id`';
    if ($key === 'lists') {
        $order = '`list_name`, `sort_order`, `value`';
    }
    $where  = $scope['sql'];
    $types  = $scope['types'];
    $params = $scope['params'];

    // A client only ever sees their own rows. The admin sees everything, or
    // one client's work when the picker in the toolbar says so.
    $owner = fabo_row_owner($viewer);
    if ($owner === null && ($opts['client'] ?? 0)) {
        $owner = (int) $opts['client'];
    }
    if ($owner !== null) {
        $where  .= ' AND `client_id` = ?';
        $types  .= 'i';
        $params[] = $owner;
    }

    /* Rows still being filled in on somebody's shared sheet are drafts and do
       not belong here yet -- that is the whole point of the Submit button on
       the shared sheet. They join this list the moment it is pressed.
     *
     * Skipped when there is no such column, which happens in the minutes
     * between uploading this file and uploading lib/db.php. Filtering on a
     * column that is not there took down every sheet on the portal; showing
     * the rows early is how it behaved before, and is survivable. */
    if ($key === 'events') {
        require_once __DIR__ . '/departments.php';
        if (fabo_dept_has_submitted_column()) {
            $where .= ' AND (`department_id` IS NULL OR `dept_submitted_at` IS NOT NULL)';
        }
    }

    $sql  = 'SELECT * FROM `' . $sheet['table'] . '` WHERE 1=1' . $where
          . ' ORDER BY ' . $order;
    $rows = fabo_all($sql, $types, $params);
    return fabo_compute_all($key, $rows);
}

/** The payload the live-sync endpoint and the first paint both use. */
function fabo_row_payload(array $sheet, array $row): array
{
    $raw = [];
    $disp = [];
    foreach ($sheet['columns'] as $c) {
        $raw[$c[1]]  = fabo_raw($c, $row);
        $disp[$c[1]] = fabo_display($c, $row);
    }
    $tone = [];
    foreach ($sheet['columns'] as $c) {
        $t = fabo_cell_tone($c[1], $row[$c[1]] ?? null, $row);
        if ($t !== '') {
            $tone[$c[1]] = $t;
        }
    }
    return ['_id' => (int) $row['id'], 'raw' => $raw, 'disp' => $disp, 'tone' => $tone];
}

/**
 * The columns this page draws.
 *
 * Every column, unless the page has narrowed them with
 * $GLOBALS['fabo_grid_fields'] -- which the Upcoming and Completed Events
 * views do (lib/eventstages.php). Narrowing is drawing only: the sheet, its
 * saving and its export keep every column.
 */
function fabo_grid_columns(array $columns): array
{
    $want = $GLOBALS['fabo_grid_fields'] ?? null;
    if (!is_array($want)) {
        return $columns;
    }
    /* In the order the view asks for, which is its own and not the sheet's. */
    $byField = [];
    foreach ($columns as $c) {
        $byField[$c[1]] = $c;
    }
    $out = [];
    foreach (array_keys($want) as $f) {
        if (isset($byField[$f])) {
            $out[] = $byField[$f];
        }
    }
    return $out;
}

/**
 * Renders the grid. $perm is 'view' or 'edit'.
 */
function fabo_render_grid(string $key, array $viewer, string $perm, array $rows): void
{
    $sheet    = fabo_sheet($key);
    $sheet['columns'] = fabo_grid_columns($sheet['columns']);
    if (is_array($GLOBALS['fabo_grid_fields'] ?? null)) {
        /* On a narrowed view (Upcoming / Completed Events) a grouped column
           can end up alone -- an Expected with its Achieved on the other
           view. A small banner over one narrow column is easy to miss, and
           a heading that just says "Expected" says nothing, so the metric's
           name goes into the heading itself. A pair kept together keeps its
           banner. */
        $cols = $sheet['columns'];
        foreach ($cols as $i => $c) {
            $g = (string) ($c[3]['group'] ?? '');
            if ($g === '') { continue; }
            $prevG = (string) ($cols[$i - 1][3]['group'] ?? '');
            $nextG = (string) ($cols[$i + 1][3]['group'] ?? '');
            if ($prevG !== $g && $nextG !== $g) {
                $sheet['columns'][$i][0] = $g . ' – ' . $c[0];
                unset($sheet['columns'][$i][3]['group']);
            }
        }
    }
    $editable = $perm === 'edit';
    $lists    = fabo_sheet_lists($sheet, $viewer, $key);
    $GLOBALS['fabo_grid_admin'] = !empty($viewer['is_admin']);
    // Appended to a download link so a client's upload is reachable on their
    // own link, where there is no session to fall back on.
    $GLOBALS['fabo_grid_token'] = (!$viewer['is_admin'] && !fabo_signed_in())
        ? '&k=' . rawurlencode((string) $viewer['client']['token']) : '';

    // Dropdowns and column wording belong to an organisation, so say whose
    // before any of either is read.
    require_once __DIR__ . '/lists.php';
    require_once __DIR__ . '/deletions.php';
    fabo_view_owner(fabo_customiser($viewer));

    $GLOBALS['fabo_grid_owner_may'] = fabo_may_customise($viewer, $perm);
    // Renaming a column is the narrower right — see fabo_may_rename_columns().
    $GLOBALS['fabo_grid_may_rename'] = fabo_may_rename_columns($viewer, $perm)
        // Not on Upcoming / Completed: a heading there may carry its group's name.
        && !is_array($GLOBALS['fabo_grid_fields'] ?? null);
    // Rows this viewer has been asked to decide, so the review tool can say so.
    $GLOBALS['fabo_grid_asked'] = (!$viewer['is_admin'] && !empty($sheet['approval']))
        ? fabo_requested_rows($key, (int) $viewer['client']['id'])
        : [];
    // Only someone who may send a row for approval gets the Send column as a
    // dropdown; for everyone else it is a plain record of what was sent.
    $GLOBALS['fabo_grid_may_request'] = !empty($sheet['approval'])
        && fabo_may_request($viewer, $key);
    ?>
  <?php
    $toned = false;
    foreach ($sheet['columns'] as $c) {
        if (fabo_tone_family($c[1]) !== null || in_array($c[1], ['days_to_deadline', 'days_to_event', 'performance_score', 'approval_state', 'checklist'], true)) {
            $toned = true;
            break;
        }
    }
    if ($toned) { echo fabo_tone_key(); }
  ?>
  <div class="gridbox">
    <table class="grid">
      <thead>
        <?php
          /* A banner above the headings wherever consecutive columns share a
             group. Seven numbers in a row mean nothing without the word
             "Expected" over them, and repeating it in every heading would
             make the grid unreadable. */
          $groups = [];
          foreach ($sheet['columns'] as $c) {
              $g    = $c[3]['group'] ?? '';
              $last = count($groups) - 1;
              if ($last >= 0 && $groups[$last]['name'] === $g) {
                  $groups[$last]['span']++;
              } else {
                  $groups[] = ['name' => $g, 'span' => 1];
              }
          }
          $hasGroups = (bool) array_filter($groups, static fn($g) => $g['name'] !== '');
        ?>
        <?php if ($hasGroups): ?>
          <tr class="grouprow">
            <th class="corner"></th>
            <?php foreach ($groups as $g): ?>
              <th colspan="<?= (int) $g['span'] ?>"<?= $g['name'] !== '' ? ' class="grouped"' : '' ?>>
                <?= $g['name'] !== '' ? e($g['name']) : '' ?></th>
            <?php endforeach; ?>
          </tr>
        <?php endif; ?>
        <tr>
          <th class="corner"><?= !empty($GLOBALS['fabo_grid_cmpick']) && $key === 'events'
              ? '<input type="checkbox" class="js-cmall" aria-label="Select all rows" title="Select all">' : '' ?><?= $editable ? 'Row' : '#' ?></th>
          <?php
            /* Each column's name as shipped, so the rename control can offer
               to put it back. Looked up by field, not by position: columns
               are reordered and removed by the people using them, so the
               two lists no longer line up index for index. */
            $rawSheet = fabo_sheet($key, true);
            $shipped  = [];
            foreach ($rawSheet['columns'] as $rc) { $shipped[$rc[1]] = $rc[0]; }
            foreach ($sheet['columns'] as $i => $c):
              $default = $shipped[$c[1]] ?? $c[0];
              $admin   = $viewer['is_admin'];
          ?>
            <th data-field="<?= e($c[1]) ?>" data-default="<?= e($default) ?>"<?= ($GLOBALS['fabo_grid_may_rename'] ?? false)
                    ? ' class="renamable" title="Click to rename this column"' : '' ?>>
              <span class="hlabel"><?= e($c[0]) ?></span><?php
              // The gear opens this dropdown's options in a side panel. Gated
              // on the same rule as the rest of the customising, not on being
              // the admin — a team member who may edit the sheet can manage
              // its options too, within their own organisation.
              if (($GLOBALS['fabo_grid_owner_may'] ?? false)
                  && !isset($GLOBALS['fabo_grid_readonly'][$c[1]])
                  && $c[2] === 'select' && !empty($c[3]['list'])
                  && !fabo_is_dynamic_list((string) $c[3]['list'])) {
                  echo ' <button type="button" class="colgear js-opts" data-list="' . e($c[3]['list'])
                     . '" title="Add, remove or edit the options of this dropdown">&#9881;</button>';
              }
            ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody id="gridbody">
      <?php foreach ($rows as $n => $row): ?>
        <?php fabo_render_row($sheet, $row, $n + 1, $editable, $lists, $key); ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?>
      <div class="empty"><?php
        if ($key === 'master') {
            echo 'Nothing here yet. This log fills itself from the other sheets '
               . 'as work is recorded on them.';
        } else {
            echo 'No rows' . ($editable ? ' yet — use “+ Add row”.' : '.');
        }
      ?></div>
    <?php endif; ?>
  </div>
<?php
}

function fabo_render_row(array $sheet, array $row, int $n, bool $editable, array $lists, string $key = ''): void
{
    $rid = (int) $row['id'];
    echo '<tr data-id="' . $rid . '">';
    echo '<th class="rowhead"><div class="rowtools">';
    if ($key === 'events' && !empty($GLOBALS['fabo_grid_cmpick'])) {
        // Ticked to give several events one committee at once (fabo_cm_bulk_bar()).
        echo '<input type="checkbox" class="js-cmpick" value="' . $rid . '" aria-label="Select row ' . $n . '">';
    }
    echo '<span class="rn">' . $n . '</span>';
    echo '<button type="button" class="js-history" title="Row history">&#8634;</button>';
    if ($key === 'events' && !empty($GLOBALS['fabo_grid_details'])) {
        /* The whole event in one pop-up, for a committee member -- every
           field, the attachments and the approvals, without scrolling the
           row sideways. Drawn by fabo_cm_details_modal() in lib/cmblock.php. */
        echo '<button type="button" class="js-evdetails" data-cmd-open="' . $rid
           . '" title="View details">&#128065;</button>';
    }
    if ($key === 'webcontent') {
        /* How the post will look once it is out. Drawn by the Website
           Content panel (lib/wcblock.php) from this row's cells as they are
           now, so an edit made a second ago is in the preview. */
        echo '<button type="button" class="js-preview" title="Preview the post">&#128065;</button>';
    }
    if (!empty($sheet['assets_label'])) {
        $n = (int) ($row['asset_count'] ?? 0);
        echo '<button type="button" class="js-files" title="Files on this row">&#128206;'
           . ($n ? '<b>' . $n . '</b>' : '') . '</button>';
    }
    if (!empty($sheet['approval'])) {
        $asked = !empty($GLOBALS['fabo_grid_asked'][$rid]);
        echo '<button type="button" class="js-review' . ($asked ? ' asked' : '') . '" title="'
           . ($asked ? 'Your approval was requested on this row' : 'Approval status')
           . '">&#10003;</button>';
    }
    if ($key === 'events' && !empty($row['_committee'])) {
        /* The committee: who has decided and who has not, and -- for a
           member -- where they decide. Drawn by lib/cmblock.php. */
        $cm = $row['_committee'];
        echo '<button type="button" class="js-committee cm-' . e((string) $cm['state']) . '" title="'
           . e($cm['committee']['name'] . ' — ' . $cm['label']) . '">&#128101;<b>'
           . (int) $cm['yes'] . '/' . (int) $cm['total'] . '</b></button>';
    }
    if (fabo_has_checklist($key)) {
        $p = $row['_checklist'] ?? ['done' => 0, 'total' => 0];
        echo '<button type="button" class="js-check" title="Checklist for this row">&#9744;'
           . ($p['total'] ? '<b>' . (int) $p['done'] . '/' . (int) $p['total'] . '</b>' : '')
           . '</button>';
    }
    if ($editable && !empty($GLOBALS['fabo_grid_fill'])) {
        /* Upcoming and Completed Events: open this event with only the
           columns of this view that are still empty. The count is what is
           left to fill; assets/evfill.js asks again from the cells as drawn. */
        $gaps = 0;
        foreach ($sheet['columns'] as $fc) {
            if (isset($GLOBALS['fabo_grid_fill'][$fc[1]])
                && trim((string) ($row[$fc[1]] ?? '')) === ''
                && !fabo_grid_cell_locked($fc, $row)
                && !($fc[1] === 'approval_to' && empty($GLOBALS['fabo_grid_may_request']))) {
                $gaps++;
            }
        }
        echo '<button type="button" class="js-evfill' . ($gaps ? '' : ' evfill-done')
           . '" title="' . ($gaps ? 'Fill the ' . $gaps . ' empty column' . ($gaps === 1 ? '' : 's')
                                     . ' of this event'
                                  : 'Nothing left to fill on this view') . '">&#9998;'
           . ($gaps ? '<b>' . $gaps . '</b>' : '') . '</button>';
    }
    if ($editable) {
        echo '<button type="button" class="js-del" title="Delete row">&#215;</button>';
    }
    echo '</div></th>';

    foreach ($sheet['columns'] as $c) {
        [$label, $field, $type] = [$c[0], $c[1], $c[2]];
        $render   = fabo_col_render($c);
        $classes  = [];
        if ($type === 'computed') { $classes[] = 'computed'; }
        if ($type === 'id')       { $classes[] = 'idcell'; }
        if (in_array($render, ['money', 'pct', 'num', 'dec'], true)) { $classes[] = 'num'; }
        if ($type === 'long')     { $classes[] = 'wide'; }
        $classes[] = 't-' . $type;
        if ($field === 'rag_status') {
            $classes[] = fabo_rag_class((string) ($row[$field] ?? ''));
        }
        $tone = fabo_cell_tone($field, $row[$field] ?? null, $row);
        if ($tone !== '') {
            $classes[] = $tone;
        }
        /* Shown for reference only on this page -- the plan, on Completed
           Events. Read like any other read-only cell; the same column stays
           editable in Programs Data. */
        $refOnly = isset($GLOBALS['fabo_grid_readonly'][$field]);
        if ($refOnly) { $classes[] = 'stage-ro'; }
        $listAttr = ($type === 'select' && !empty($c[3]['list'])) ? ' data-list="' . e($c[3]['list']) . '"' : '';
        echo '<td class="' . e(implode(' ', $classes)) . '" data-field="' . e($field) . '"' . $listAttr . '>';

        $locked = $field === 'approval_to' && empty($GLOBALS['fabo_grid_may_request']);
        // A results column that the event has not reached yet.
        $shut = fabo_grid_cell_locked($c, $row);

        if ($refOnly && ($c[3]['calc'] ?? '') === 'budget') {
            /* Completed Events: the amounts were settled while planning and
               stay read-only, but the budget still opens -- it is where each
               head's invoices are attached and where the committee reviews
               them. data-ro tells the drawer to keep the amounts shut. */
            $p = $row['_budget'] ?? ['total' => 0.0, 'filled' => 0, 'files' => 0];
            $lbl = !empty($p['filled'])
                ? fabo_money((float) $p['total'])
                  . (!empty($p['files']) ? '<i class="bqn">' . (int) $p['files'] . ' file'
                                           . ((int) $p['files'] === 1 ? '' : 's') . '</i>' : '')
                : '<i class="bqn">Open the budget</i>';
            echo '<button type="button" class="cell cellbudget js-budget" data-ro="1"'
               . ' title="Budget for this event — amounts are read-only here; attach invoices">'
               . $lbl . '</button>';
        } elseif ($refOnly) {
            if ($type === 'file' || $type === 'image' || $type === 'images') {
                $v = '';
            } elseif ($type === 'checkbox') {
                $v = !empty($row[$field]) ? 'Yes' : '';
            } else {
                $v = fabo_display($c, $row);
            }
            echo '<span class="cell" title="Read-only here. Change it in Upcoming Events or Programs Data.">'
               . e($v) . '</span>';
        } elseif (($keptNote = fabo_col_kept($c)) !== '') {
            /* The portal keeps this one. Shown as a value with an explanation
               rather than a box, because typing in it would be undone by the
               next recalculation. api/save.php refuses writes to it too. */
            echo '<span class="cell cellkept" title="' . e($keptNote) . '">'
               . e(fabo_display($c, $row)) . '<i class="keptmark">kept</i></span>';
        } elseif ($shut && $editable) {
            echo '<span class="cell celllocked" title="' . e(fabo_grid_cell_lock_note($c, $row)) . '">'
               . ($v = fabo_display($c, $row)) . ($v === '' ? '<i>&#128274;</i>' : '') . '</span>';
        } elseif (($c[3]['calc'] ?? '') === 'budget') {
            /* Not a figure to type into: the cell is the way in to the event's
               budget form, where each head the admin allows gets one box. What
               shows is the total asked for and how many heads it came from —
               never an allocation, because a team member reads this. */
            $p = $row['_budget'] ?? ['total' => 0.0, 'pending' => 0, 'rejected' => 0,
                                     'filled' => 0, 'files' => 0];
            $lbl = $p['filled']
                ? fabo_money((float) $p['total'])
                  . '<i class="bqn">' . (int) $p['filled'] . ' head'
                  . ($p['filled'] === 1 ? '' : 's')
                  . ($p['files'] ? ' &middot; ' . (int) $p['files'] . ' file'
                                   . ($p['files'] === 1 ? '' : 's') : '') . '</i>'
                : '<i class="bqn">Open the budget</i>';

            /* One badge, and the worse news wins: something sent back needs
               redoing, which outranks something merely waiting to be seen. */
            $flag = '';
            // On Events the committee decides the budget, not an owner (lib/committees.php).
            $byCm = $key === 'events';
            if (!empty($p['rejected'])) {
                $flag = '<b class="bqflag bqbad" title="'
                      . ($byCm ? 'Sent back by the committee — open for the remarks'
                               : (int) $p['rejected'] . ' not approved — open for the reason') . '">'
                      . (int) $p['rejected'] . ' sent back</b>';
            } elseif (!empty($p['pending'])) {
                $flag = $byCm
                      ? '<b class="bqflag bqwait" title="The committee is reviewing this budget">with the committee</b>'
                      : '<b class="bqflag bqwait" title="'
                      . 'Over the allowed limit, waiting on the budget owner">'
                      . (int) $p['pending'] . ' awaiting approval</b>';
            } elseif ($byCm && !empty($p['draft']) && !empty($p['filled'])) {
                $flag = '<b class="bqflag" title="Choose a Committee, or press Send to committee in the budget">not sent yet</b>';
            }
            echo '<button type="button" class="cell cellbudget js-budget"'
               . ' title="Budget for this event">' . $lbl . $flag . '</button>';
        } elseif ($type === 'computed' || $type === 'id' || !$editable || $locked) {
            echo '<span class="cell">' . e(fabo_display($c, $row)) . '</span>';
        } elseif ($type === 'select') {
            $vals = $lists[$c[3]['list']] ?? [];
            $cur  = (string) ($row[$field] ?? '');
            echo '<select class="js-cell">';
            echo '<option value=""></option>';
            $seen = false;
            foreach ($vals as $v) {
                $sel = $v === $cur ? ' selected' : '';
                if ($sel) { $seen = true; }
                echo '<option value="' . e($v) . '"' . $sel . '>' . e($v) . '</option>';
            }
            // Keep a stored value that is no longer in the list selectable.
            if ($cur !== '' && !$seen) {
                echo '<option value="' . e($cur) . '" selected>' . e($cur) . '</option>';
            }
            if (($GLOBALS['fabo_grid_owner_may'] ?? false) && !fabo_is_dynamic_list((string) $c[3]['list'])) {
                echo '<option disabled>──────────</option>';
                echo '<option value="__add__">＋ Add option…</option>';
                echo '<option value="__manage__">－ Remove / edit options…</option>';
            }
            echo '</select>';
        } elseif ($type === 'images') {
            /* A gallery in a cell. The pictures are attachments on the row,
               so the column stores nothing and api/rowimages.php owns them. */
            require_once __DIR__ . '/uploads.php';
            $tok  = $GLOBALS['fabo_grid_token'] ?? '';
            /* Not a picture filed as a document, and not the one in a
               single-file column: those have their own places. */
            $imgs = fabo_all("SELECT id, original_name, mime FROM attachments
                              WHERE sheet_key = ? AND row_id = ?
                                AND COALESCE(caption, '') <> 'file'
                                AND LEFT(COALESCE(caption, ''), 7) <> '__cell:'
                              ORDER BY id",
                             'si', [$key, (int) $row['id']]);
            echo '<div class="cellimgs" data-field="' . e($field) . '">';
            foreach ($imgs as $a) {
                if (!fabo_is_image((string) $a['mime'])) { continue; }
                $href = fabo_root_prefix() . 'download.php?f=' . (int) $a['id'] . '&thumb=1' . $tok;
                echo '<figure data-image="' . (int) $a['id'] . '">'
                   . '<img src="' . e($href) . '" alt="' . e($a['original_name']) . '" loading="lazy" decoding="async">'
                   . ($editable ? '<button type="button" class="cellimgx" '
                                . 'title="Remove this image">&times;</button>' : '')
                   . '</figure>';
            }
            if ($editable) {
                echo '<label class="cellimgadd" title="Any image type">+'
                   . '<input type="file" accept="image/*" multiple hidden class="js-rowimg"></label>';
            }
            echo '</div>';
        } elseif ($type === 'file' || $type === 'image') {
            /* A built sheet's upload column. The cell holds the attachment's
               id; what is shown is the file itself, with one button to
               replace it and one to take it off. */
            require_once __DIR__ . '/uploads.php';
            $aid = (int) ($row[$field] ?? 0);
            $att = $aid > 0
                ? fabo_one('SELECT * FROM attachments WHERE id = ?', 'i', [$aid])
                : null;
            echo '<div class="cellfile" data-kind="' . e($type) . '">';
            if ($att) {
                $href = fabo_root_prefix() . 'download.php?f=' . (int) $att['id']
                      . ($GLOBALS['fabo_grid_token'] ?? '');
                if ($type === 'image' && fabo_is_image((string) $att['mime'])) {
                    echo '<a href="' . e($href) . '" target="_blank" rel="noopener">'
                       . '<img src="' . e($href) . '" alt="' . e($att['original_name']) . '"></a>';
                } else {
                    echo '<a href="' . e($href) . '" target="_blank" rel="noopener" class="cellfilename">'
                       . e($att['original_name']) . '</a>';
                }
                echo '<button type="button" class="cellfilex js-cellfiledel" '
                   . 'title="Take this off the row">&times;</button>';
            }
            echo '<label class="cellfileadd">'
               . ($att ? 'Replace' : ($type === 'image' ? 'Add image' : 'Add file'))
               . '<input type="file" class="js-cellfile" hidden'
               . ($type === 'image' ? ' accept="image/*"' : '') . '></label>';
            echo '</div>';
        } elseif ($type === 'checkbox') {
            echo '<input class="js-cell js-cellbox" type="checkbox"'
               . (!empty($row[$field]) ? ' checked' : '') . '>';
        } elseif ($type === 'long') {
            echo '<input class="js-cell" type="text" value="' . e(fabo_raw($c, $row)) . '">';
        } elseif ($type === 'date') {
            echo '<input class="js-cell" type="date" value="' . e(fabo_raw($c, $row)) . '">';
        } elseif (in_array($type, ['int', 'money', 'dec'], true)) {
            $step = $type === 'int' ? '1' : 'any';
            echo '<input class="js-cell" type="number" step="' . $step . '" value="'
               . e(fabo_raw($c, $row)) . '">';
        } else {
            echo '<input class="js-cell" type="text" value="' . e(fabo_raw($c, $row)) . '">';
        }
        echo '</td>';
    }
    echo "</tr>\n";
}

/** Toolbar above the grid: add row, save state, export, filters. */
function fabo_render_toolbar(string $key, array $viewer, string $perm, string $extra = ''): void
{
    // The admin can work inside one client: the sheet shows their rows, a new
    // row is filed under them, and the download is theirs. A client has no
    // picker — they only ever have their own sheet.
    $picked  = (int) ($_GET['client'] ?? 0);
    $clients = $viewer['is_admin']
        ? fabo_all("SELECT id, name, organisation FROM clients
                    WHERE kind = 'client' AND revoked_at IS NULL
                    ORDER BY organisation, name")
        : [];
    $pickedName = '';
    foreach ($clients as $c) {
        if ((int) $c['id'] === $picked) {
            $pickedName = $c['name'] . ($c['organisation'] ? ' · ' . $c['organisation'] : '');
        }
    }
    if ($pickedName === '') {
        $picked = 0;
    }

    $q = ['scope' => 'sheet', 'sheet' => $key];
    if ($picked > 0) {
        $q['client'] = $picked;
    }
    $exportUrl = fabo_link($viewer, ($viewer['is_admin'] ? '../export.php' : 'export.php'), $q);
    $selfPage  = $viewer['is_admin'] ? 'sheet.php' : 'c.php';
    ?>
  <div class="toolbar">
    <?php /* No Add row on the Master Activity Log. It is written from the
             other sheets, and a line typed straight into it is one no detail
             sheet knows about — which is what this stopped being for. */ ?>
    <?php /* Nor on Completed Events: an event is added before it happens. */ ?>
    <?php if ($perm === 'edit' && $key !== 'master' && empty($GLOBALS['fabo_grid_no_add'])): ?>
      <button class="btn btn-primary btn-sm" id="addrow">+ Add row</button>
    <?php endif; ?>
    <?php if ($clients): ?>
      <select id="clientpick"
              data-base="<?= e(fabo_link($viewer, $selfPage, ['sheet' => $key])) ?>">
        <option value="">Everything</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $picked ? ' selected' : '' ?>>
            <?= e($c['name']) ?><?= $c['organisation'] ? ' · ' . e($c['organisation']) : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($picked > 0): ?>
        <span class="filternote">Working in <strong><?= e($pickedName) ?></strong> —
          new rows are filed under them</span>
      <?php endif; ?>
    <?php endif; ?>
    <?= $extra ?>
    <span class="spacer"></span>
    <span class="savestate" id="savestate"></span>
    <a class="btn btn-sm<?= $picked > 0 ? ' btn-primary' : '' ?>" href="<?= e($exportUrl) ?>">
      <?= $picked > 0 ? 'Download this client&rsquo;s sheet' : 'Export this sheet' ?></a>
  </div>
<?php
}

/** The drawers: row history, and (admin) the dropdown-options editor. */
function fabo_render_drawer(): void
{
    ?>
  <div class="drawer" id="drawer">
    <header>
      <h3>Row history</h3>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('drawer').classList.remove('on')">Close</button>
    </header>
    <div class="body" id="drawerbody"></div>
  </div>
  <div class="drawer" id="filesdrawer">
    <header>
      <h3 id="filestitle">Files</h3>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('filesdrawer').classList.remove('on')">Close</button>
    </header>
    <div class="body" id="filesbody"></div>
  </div>
  <div class="drawer" id="reviewdrawer">
    <header>
      <h3 id="reviewtitle">Review</h3>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('reviewdrawer').classList.remove('on')">Close</button>
    </header>
    <div class="body" id="reviewbody"></div>
  </div>
  <div class="drawer" id="checkdrawer">
    <header>
      <h3 id="checktitle">Checklist</h3>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('checkdrawer').classList.remove('on')">Close</button>
    </header>
    <div class="body" id="checkbody"></div>
  </div>
  <div class="drawer" id="budgetdrawer">
    <header>
      <h3 id="budgettitle">Budget</h3>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('budgetdrawer').classList.remove('on')">Close</button>
    </header>
    <div class="body" id="budgetbody"></div>
  </div>
  <div class="drawer" id="optsdrawer">
    <header>
      <h3 id="optstitle">Dropdown options</h3>
      <button class="btn btn-sm" type="button" onclick="document.getElementById('optsdrawer').classList.remove('on')">Close</button>
    </header>
    <div class="body" id="optsbody"></div>
  </div>
<?php
}
