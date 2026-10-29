<?php
/**
 * One sheet page, rendered identically for admin and for a client link.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/budget.php';

function fabo_sheet_page(array $viewer, string $key): void
{
    fabo_require($viewer, $key, 'view');

    /* Whose columns and wording this page is drawn with, said BEFORE the
       sheet is read. It used to be said only inside the grid, so the
       Dashboard and Calendar views -- which draw no grid -- were built from
       the shared definition and missed every column a client or their team
       had added, and every heading they had renamed. */
    fabo_view_owner(fabo_customiser($viewer));

    $sheet = fabo_sheet($key);
    $perm  = fabo_permission($viewer, $key);

    /* A department form posts back to this page, so it is dealt with before
       a byte of the page exists — it redirects, and a redirect after output
       is not a redirect at all. */
    require_once __DIR__ . '/committees.php';
    if ($key === 'events' && ($_POST['dept_action'] ?? '') !== '') {
        if (fabo_cm_only($viewer)) {
            fabo_deny('Department assigning is not available to committee members.', 403);
        }
        require_once __DIR__ . '/deptblock.php';
        $flash = fabo_dept_handle($viewer, $perm);
        fabo_session();
        $_SESSION['fabo_dept_flash'] = $flash;
        header('Location: ' . fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
               /* Back to the department desk, not to whichever view is the
                  default: somebody who has just created a department is not
                  finished with it. */
               ['sheet' => 'events', 'view' => 'departments',
                'ev' => (int) ($_POST['ev'] ?? 0)]) . '#depts');
        exit;
    }

    /* The requisition forms desk lives on the requisition sheet now, and it
       posts back here. Handled before a byte of output, because the desk
       answers with a redirect and a redirect after output is not one. */
    if ($key === 'requisitions' && ($_POST['action'] ?? '') !== '' && $perm === 'edit') {
        $GLOBALS['fabo_forms_embed']  = true;
        $GLOBALS['fabo_forms_viewer'] = $viewer;
        $GLOBALS['fabo_forms_scope']  = fabo_customiser($viewer);
        $GLOBALS['fabo_forms_perm']   = $perm;
        $GLOBALS['fabo_forms_back']   = ($viewer['is_admin'] ? 'sheet.php' : 'c.php')
                                      . '?sheet=requisitions';
        require __DIR__ . '/../admin/forms.php';   // handles the post and exits
    }

    /* Asking for a site to be read and written again, or taking it off the
       panel. Handled here with the add below, and for the same reason: they
       redirect. */
    if ($key === 'webcontent'
        && in_array((string) ($_POST['action'] ?? ''),
                    ['wc_rewrite', 'wc_remove', 'wc_hide', 'wc_approve_all'], true)) {
        fabo_csrf_check();
        /* wcblock.php for fabo_wc_sites(), which is the list of sites this
           viewer may touch; webcontent.php for the work itself. */
        require_once __DIR__ . '/wcblock.php';
        require_once __DIR__ . '/webcontent.php';
        $act = (string) $_POST['action'];
        try {
            if ($perm !== 'edit') {
                throw new InvalidArgumentException('You can only read this sheet.');
            }
            /* The site has to be one of this portal's own. A site id posted
               from a browser is a claim; fabo_wc_sites() is the list of the
               ones this viewer may actually touch. */
            $siteId = (int) ($_POST['site_id'] ?? 0);
            $ok     = false;
            foreach (fabo_wc_sites($viewer) as $s) {
                if ((int) $s['id'] === $siteId) { $ok = true; break; }
            }
            if (!$ok) {
                throw new InvalidArgumentException('That website was not found.');
            }

            if ($act === 'wc_approve_all') {
                /* Approving is a decision, not an edit: the same right the
                   Approve button asks for, row by row. */
                require_once __DIR__ . '/approvals.php';
                require_once __DIR__ . '/endpoint.php';     // fabo_audit()
                $who = $viewer['is_admin'] ? 'Admin' : (string) $viewer['client']['name'];
                $n   = 0;
                foreach (fabo_wc_ready_to_approve($siteId) as $rowId) {
                    if (!fabo_may_decide($viewer, 'webcontent', $rowId)) {
                        continue;
                    }
                    [$ok] = fabo_set_approval('webcontent', $rowId, 'approved',
                                              'Approved with "Approve all ready".', $who);
                    if ($ok) {
                        fabo_audit($viewer, 'web_content', $rowId, '(approval)', null, 'approved (all ready)');
                        $n++;
                    }
                }
                $flash = ['ok', $n > 0
                    ? $n . ' post' . ($n === 1 ? '' : 's') . ' approved and put on the schedule.'
                    : 'Nothing was ready to approve: each post needs a clean claim check, a date, '
                      . 'and everything its kind needs.'];
            } elseif ($act === 'wc_hide') {
                /* Off the panel only. The posts are the work and stay put. */
                fabo_exec('UPDATE website_sources SET hidden = 1 WHERE id = ?', 'i', [$siteId]);
                $flash = ['ok', 'Hidden from the list. Its posts are still on the grid.'];
            } else {
                $flash = ['ok', $act === 'wc_remove' ? fabo_wc_remove_site($siteId)
                                                     : fabo_wc_rewrite($siteId)];
            }
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            error_log('FABO ' . $act . ': ' . $e->getMessage());
            $flash = ['err', 'That did not go through. Nothing was changed.'];
        }
        fabo_session();
        $_SESSION['fabo_wc_flash'] = $flash;
        header('Location: ' . fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
               ['sheet' => 'webcontent']));
        exit;
    }

    /* Adding a website posts back to this page, so it is handled before any
       output for the same reason the department form above is. */
    if ($key === 'webcontent' && ($_POST['action'] ?? '') === 'wc_add') {
        fabo_csrf_check();
        require_once __DIR__ . '/wcblock.php';
        try {
            if ($perm !== 'edit') {
                throw new InvalidArgumentException('You can only read this sheet.');
            }
            /* Whose it is, is not asked and not taken from the form: it is
               whoever is adding it. A client in their own portal is adding
               it for themselves, and an id posted from a browser is a claim
               rather than a fact. */
            $wanted = fabo_wc_owner($viewer);
            if ($wanted < 0) {
                throw new InvalidArgumentException('Your account has no organisation to add this to.');
            }
            [$id, $note] = fabo_wc_start(
                (string) ($_POST['website_url'] ?? ''),
                (string) ($_POST['post_date'] ?? ''),
                $wanted,
                (string) ($viewer['is_admin'] ? 'Admin' : ($viewer['client']['name'] ?? '')),
                (string) ($_POST['post_time'] ?? ''),
                (int) ($_POST['post_every'] ?? 1),
                !empty($_POST['auto_post']));
            $flash = ['ok', $note];
        } catch (InvalidArgumentException $e) {
            $flash = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            error_log('FABO wc_add: ' . $e->getMessage());
            $flash = ['err', 'That could not be started. Nothing was changed.'];
        }
        fabo_session();
        $_SESSION['fabo_wc_flash'] = $flash;
        header('Location: ' . fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
               ['sheet' => 'webcontent']));
        exit;
    }

    /* The Master Activity Log is brought fully up to date BEFORE its rows are
     * read, not afterwards.
     *
     * The catch-up used to run inside fabo_head(), which is further down this
     * file than the read below — so the lines it added were never in the page
     * being drawn. They appeared on the next load, and the one after that,
     * which is why this sheet looked like it needed refreshing two or three
     * times before it showed everything.
     *
     * Only on this sheet, and only while something is actually missing, so
     * every other page stays as quick as it was. */
    if ($key === 'master' && is_file(__DIR__ . '/masterlog.php')) {
        require_once __DIR__ . '/masterlog.php';
        fabo_master_catch_up();
    }

    $rows  = fabo_rows($key, $viewer, ['client' => (int) ($_GET['client'] ?? 0)]);

    /* The log is every activity across every sheet, which is unreadable
       without a way to cut it down. Narrowed here, before the grid is drawn,
       so the export and the counts agree with what is on screen. */
    $mAll = $rows;
    $mOn  = [];
    if ($key === 'master') {
        $mOn  = fabo_master_chosen();
        $rows = fabo_master_narrow($rows, $mOn);
    }

    $assets = $viewer['is_admin'] ? '../assets/' : 'assets/';
    $api    = $viewer['is_admin'] ? '../api/'    : 'api/';

    fabo_head($viewer, $sheet['label'], $key);
    ?>
<div class="wrap">
  <?php
    fabo_session();
    if (!empty($_SESSION['fabo_dept_flash'])) {
        [$tone, $msg] = $_SESSION['fabo_dept_flash'];
        unset($_SESSION['fabo_dept_flash']);
        echo '<div class="flash ' . e($tone) . '">' . e($msg) . '</div>';
    }
  ?>
  <div class="pagehead">
    <h1><?= e($sheet['title']) ?></h1>
    <?php if (!empty($sheet['subtitle'])): ?>
      <div class="sub"><?= e($sheet['subtitle']) ?></div>
    <?php endif; ?>
    <?php if ($key === 'master'): ?>
      <?php /* Said plainly, because the log looks like every other sheet and
               is not one: typing a row here that a detail sheet already
               covers is work done twice, and the second copy is the one that
               goes stale. */ ?>
      <div class="sub">This log fills itself. Every row on the other sheets appears
        here as an activity, and the dashboard is built from it. You can still add a
        line of your own &mdash; those are left alone.</div>
    <?php endif; ?>
    <?php if (!empty($sheet['note'])): ?>
      <div class="note"><?= e($sheet['note']) ?></div>
    <?php endif; ?>
  </div>

<?php
    $extra = '';
    if ($key === 'calendar') {
        $extra = fabo_calendar_filter();
    }
    if ($key === 'webcontent') {
        require_once __DIR__ . '/wcblock.php';
        fabo_session();
        if (!empty($_SESSION['fabo_wc_flash'])) {
            [$tone, $msg] = $_SESSION['fabo_wc_flash'];
            unset($_SESSION['fabo_wc_flash']);
            echo '<div class="flash ' . e($tone) . '">' . e($msg) . '</div>';
        }
        fabo_wc_block($viewer, $perm);
    }

    /*
     * Events is four rooms, not one scroll: a dashboard, a calendar, the rows
     * themselves, and the desk where units are handed their own sheet. Each is
     * a different job, and stacking them meant every job began by scrolling
     * past the other three.
     *
     * The filters are applied once, here, before any of them is drawn — which
     * is what makes "Q3, Amaravati Hub, workshops" mean the same thing on the
     * calendar as it does in the figures. See lib/eventviews.php.
     */
    $evView = '';
    if ($key === 'events') {
        require_once __DIR__ . '/eventviews.php';
        $evView  = fabo_event_view($viewer);
        $evOn    = fabo_event_chosen();
        $evAll   = $rows;
        $rows    = fabo_event_filter_rows($rows, $evOn);

        /* Upcoming and Completed are Programs Data cut in two by whether the
           event has happened. Counted after the filters, so the badges agree
           with the rows each tab will show. See lib/eventstages.php. */
        require_once __DIR__ . '/eventstages.php';
        $evStage = fabo_evstage_counts($rows);
        $evMatch = count($rows);

        fabo_event_tabs($viewer, $evView, ['programs'  => $evMatch,
                                           'upcoming'  => $evStage['upcoming'],
                                           'completed' => $evStage['completed']]);
        /* The committee panel, on every Events view: opened from the row
           button and from the dashboard card. See lib/cmblock.php. */
        require_once __DIR__ . '/cmblock.php';
        fabo_cm_panel($viewer);
        fabo_event_filterbar($viewer, $evAll, $evOn, $evMatch, count($evAll));

        if (!$rows && $evAll) {
            fabo_event_nothing($viewer, 'events');
        }

        if ($evView === 'upcoming' || $evView === 'completed') {
            $rows = fabo_evstage_rows($rows, $evView);
            fabo_evstage_note($evView);

            /* Only the columns this view needs, and in Completed the plan is
               for reading. lib/grid.php draws from these; the rows and the
               saving are exactly Programs Data's. */
            $evLay = fabo_evstage_layout($sheet, $evView);
            $GLOBALS['fabo_grid_fields']   = $evLay['fields'];
            $GLOBALS['fabo_grid_readonly'] = $evLay['readonly'];
            /* The Fill button on each row, and what it may ask about. Only for
               somebody who may edit: it is a way of typing, not of reading. */
            $evFill = $perm === 'edit' ? fabo_evstage_fillable($sheet, $evLay) : [];
            $GLOBALS['fabo_grid_fill'] = $evFill;
            /* A new event is planned before it happens, so it is added from
               Upcoming or Programs Data, never from Completed. */
            if ($evView === 'completed') {
                $GLOBALS['fabo_grid_no_add'] = true;
            }
        }
    }

    $evGrid = in_array($evView, ['programs', 'upcoming', 'completed'], true);
    if ($key !== 'events' || $evGrid) {
        /* A committee member reads an event from a View details icon on
           each row, rather than scrolling the row sideways. */
        if ($key === 'events' && fabo_cm_only($viewer)) {
            $GLOBALS['fabo_grid_details'] = true;
            fabo_cm_details_modal($viewer);
        }
        /* Several programmes given one committee at once: a checkbox on each
           row and the picker in the toolbar. Only for someone who may edit,
           on a view that shows an editable Committee column, when the
           organisation has a committee to give. */
        if ($key === 'events' && $perm === 'edit' && !fabo_cm_only($viewer)) {
            $cmFields = $GLOBALS['fabo_grid_fields'] ?? null;
            $cmNames  = fabo_cm_names($viewer);
            if ($cmNames && isset(fabo_editable_fields($sheet)['committee'])
                && (!is_array($cmFields) || !empty($cmFields['committee']))
                && !isset($GLOBALS['fabo_grid_readonly']['committee'])) {
                $GLOBALS['fabo_grid_cmpick'] = true;
                $extra .= fabo_cm_bulk_bar($cmNames);
            }
        }
        fabo_render_toolbar($key, $viewer, $perm, $extra);
        if ($key === 'master') {
            /* Between the toolbar and the grid, because it is about what the
               grid is showing rather than what can be done to it. */
            fabo_master_filterbar($viewer, $mAll, $mOn, count($rows));
        }
        fabo_render_grid($key, $viewer, $perm, $rows);
        /* Approvals are decided before an event, so the list of them is not
           repeated under the results. */
        if ($evView !== 'completed') {
            fabo_approval_activity_block($key, $viewer);
        }
    }

    if ($key !== 'lists' && ($key !== 'events' || $evView === 'dashboard')) {
        require_once __DIR__ . '/sheetdash.php';
        if ($key === 'events') {
            /* The fixed event figures, the charts chosen for this sheet in
               Sheet Management, and the figures split by owner, hub, type. */
            require_once __DIR__ . '/eventdash.php';
            fabo_event_dashboard($viewer, $sheet, $rows);
        } else {
            fabo_sheet_dashboard($key, $sheet, $rows, $viewer);
            /* The charts chosen for this sheet in Sheet Management. */
            require_once __DIR__ . '/dashwidgets.php';
            fabo_dw_render($viewer, $key, $sheet, $rows);
        }

        /* The picture above counts what is on the sheet. This says what wants
           doing, whether anybody has been near it, and who is carrying it —
           the three questions left over. */
        require_once __DIR__ . '/sheetbrief.php';
        fabo_sheet_brief_block($key, $sheet, $rows, $viewer);
    }
    if ($key === 'calendar') {
        require_once __DIR__ . '/calendar_blocks.php';
        fabo_calendar_blocks($rows);
    }
    if ($key === 'store') {
        require_once __DIR__ . '/store_blocks.php';
        fabo_store_blocks($viewer, $rows);
    }
    if ($key === 'requisitions') {
        require_once __DIR__ . '/reqcal.php';
        fabo_req_calendar($viewer, $rows);

        /* The forms desk, on the same page as the queue it fills. Access to
           this sheet is what grants it — see the note at the top of
           admin/forms.php. */
        $GLOBALS['fabo_forms_embed']  = true;
        $GLOBALS['fabo_forms_viewer'] = $viewer;
        $GLOBALS['fabo_forms_scope']  = fabo_customiser($viewer);
        $GLOBALS['fabo_forms_perm']   = $perm;
        $GLOBALS['fabo_forms_back']   = ($viewer['is_admin'] ? 'sheet.php' : 'c.php')
                                      . '?sheet=requisitions';
        require __DIR__ . '/../admin/forms.php';
    }
    if ($key === 'events' && $evView === 'calendar') {
        require_once __DIR__ . '/eventcal.php';
        fabo_event_calendar($viewer, $rows);
        fabo_creative_calendar_blocks($viewer, $rows);
    }
    if ($key === 'events' && $evView === 'departments') {
        require_once __DIR__ . '/deptblock.php';
        fabo_dept_block($viewer, $rows, $perm);
    }

    fabo_render_drawer();
?>
</div>
<script>
window.FABO_GRID = {
  sheet:   <?= json_encode($key) ?>,
  perm:    <?= json_encode($perm) ?>,
  token:   <?= json_encode(($viewer['is_admin'] || fabo_signed_in()) ? '' : $viewer['client']['token']) ?>,
  csrf:    <?= json_encode(fabo_csrf_token()) ?>,
  api:     <?= json_encode($api) ?>,
  admin:   <?= json_encode((bool) $viewer['is_admin']) ?>,
  <?php /* Whether the add-option and rename-column behaviour is wired up.
           It has to match what the grid actually rendered, or the markers
           sit in the dropdown with nothing listening for them — and the
           plain autosave posts "__add__" as if it were a value. */ ?>
  customise:<?= json_encode(fabo_may_customise($viewer, $perm)) ?>,
  <?php /* Renaming a heading is the narrower right, so it travels separately. */ ?>
  renameCols:<?= json_encode(fabo_may_rename_columns($viewer, $perm)) ?>,
  focusRow:<?= json_encode((int) ($_GET['row'] ?? 0)) ?>,
  <?php /* How many rows the live sync should expect from the server. On a
           filtered or split Events view the grid shows fewer than that, and
           comparing against the rows on screen reloaded the page every few
           seconds. Only a row added or removed elsewhere changes this. */ ?>
  total:   <?= json_encode($key === 'events' && isset($evAll) ? count($evAll) : null) ?>,
  <?php /* Which Events view is drawn: upcoming, completed, programs... */ ?>
  view:    <?= json_encode($evView) ?>,
  assets:  <?= json_encode(!empty($sheet['assets_label']) ? $sheet['assets_label'] : null) ?>,
  approval:<?= json_encode(!empty($sheet['approval'])) ?>,
  checklist:<?= json_encode(fabo_has_checklist($key)) ?>,
  <?php /* Whether this sheet has a budget form, and whether this viewer may be
           shown the allocations behind it. The second is repeated server-side
           on every request — it is here so the drawer knows which columns to
           draw, never as the thing that decides what is sent. */ ?>
  budget:<?= json_encode((bool) array_filter($sheet['columns'],
              static fn($c) => ($c[3]['calc'] ?? '') === 'budget')) ?>,
  budgetSeeAlloc:<?= json_encode(fabo_budget_may_see_allocated($viewer)) ?>,
  <?php /* The columns as drawn, which on Upcoming and Completed is fewer than
           the sheet has: the frozen panes and the new-row line count them. */ ?>
  columns: <?= json_encode(array_map(static fn($c) => ['field' => $c[1], 'type' => $c[2],
                                                        'list' => $c[3]['list'] ?? null],
                                     fabo_grid_columns($sheet['columns']))) ?>
};
</script>
<script>
  <?php /* The dropdown values, so the composer's selects can be built without
           a second request. The grid's own cells are rendered server-side. */ ?>
  window.FABO_LISTS = <?= json_encode(fabo_sheet_lists($sheet, $viewer, $key)) ?>;
</script>
<script src="<?= e(fabo_asset($assets . 'grid.js')) ?>"></script>
<?php if (!empty($evFill)): ?>
<script>
  <?php /* The columns the Fill button may ask about on this view, in its
           order, with the names they carry on the grid. */ ?>
  window.FABO_FILL = <?= json_encode(array_map(static fn($f, $l) => ['field' => $f, 'label' => $l],
                                     array_keys($evFill), array_values($evFill))) ?>;
</script>
<script src="<?= e(fabo_asset($assets . 'evfill.js')) ?>"></script>
<?php endif; ?>
<?php
    if ($key === 'calendar') {
        echo '<script>' . fabo_calendar_filter_js() . '</script>';
    }
    fabo_foot();
}

/**
 * What has been sent out on this sheet for approval, and what came back.
 *
 * Shown to the admin and to our own team: they are the people who need to know
 * a piece is waiting on a client, or has come back to be reworked. A client
 * never sees it — it would show them another client's business.
 */
function fabo_approval_activity_block(string $key, array $viewer): void
{
    if (!fabo_has_approval($key)) {
        return;
    }
    $isTeam = !$viewer['is_admin'] && (($viewer['client']['kind'] ?? 'client') === 'team');
    if (!$viewer['is_admin'] && !$isTeam) {
        return;
    }
    $items = fabo_sheet_approval_activity($key, $viewer);

    // A team member's own rows first: that is the part they have to act on.
    $me = trim((string) ($viewer['client']['owner_name'] ?? ''));

    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($items as $it) {
        $counts[$it['status']] = ($counts[$it['status']] ?? 0) + 1;
    }
    ?>
  <div class="block approvalblock">
    <h2>Approvals on this sheet
      <?php if ($items): ?>
        <span class="pill tone-warn"><i class="dot"></i><?= (int) $counts['pending'] ?> pending</span>
        <span class="pill tone-good"><i class="dot"></i><?= (int) $counts['approved'] ?> approved</span>
        <span class="pill tone-risk"><i class="dot"></i><?= (int) $counts['rejected'] ?> rejected</span>
      <?php endif; ?>
    </h2>
    <?php if (!$items): ?>
      <div class="card"><div class="empty">Nothing has been sent to a client for approval on this
        sheet yet. Use the <strong>Send for Approval</strong> column, or the &#10003; button on a row.</div></div>
    <?php else: ?>
      <div class="scrollx">
      <table class="data approvaltable">
        <thead><tr>
          <th>Item</th><th>Client</th><th>Status</th><th>Sent</th><th>Sent by</th>
          <th>Answered</th><th>Reason given</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it):
            $state = (string) $it['status'];
            $mine  = $me !== '' && trim((string) $it['requested_by']) === $me;
        ?>
          <tr<?= $mine ? ' class="mine"' : '' ?>>
            <td><strong><?= e($it['title'] !== '' ? $it['title'] : 'Row #' . (int) $it['row_id']) ?></strong></td>
            <td><?= e($it['client_name']) ?><?php if ($it['client_org']): ?>
                  <div class="meta"><?= e($it['client_org']) ?></div><?php endif; ?></td>
            <td><span class="pill tone-<?= e(fabo_approval_tone($state)) ?>"><i class="dot"></i><?= e(fabo_approval_label($state)) ?></span></td>
            <td><?= e(date('d M Y, H:i', strtotime($it['requested_at']))) ?></td>
            <td><?= e($it['requested_by']) ?><?= $mine ? ' <span class="ro">you</span>' : '' ?></td>
            <td><?= $it['responded_at']
                    ? e(date('d M Y, H:i', strtotime($it['responded_at'])))
                    : '<span style="color:var(--ink-mute)">waiting</span>' ?></td>
            <td class="reason"><?= $state === 'rejected' && $it['remarks']
                    ? e($it['remarks'])
                    : ($it['note'] ? '<span class="asked">Asked: ' . e($it['note']) . '</span>' : '—') ?></td>
            <td><a class="btn btn-sm" href="<?= e(fabo_link($viewer,
                    $viewer['is_admin'] ? 'sheet.php' : 'c.php',
                    ['sheet' => $key, 'row' => (int) $it['row_id']])) ?>">Open row</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
<?php
}

/** The Upcoming Flag filter that sits in the Social Media Calendar toolbar. */
function fabo_calendar_filter(): string
{
    $opts = ['' => 'All dates', 'Past' => 'Past', 'Today' => 'Today',
             'This Week' => 'This Week', 'Next 4 Weeks' => 'Next 4 Weeks', 'Later' => 'Later'];
    $h = '<select id="flagfilter"><option value="">Filter: Upcoming Flag</option>';
    foreach ($opts as $v => $lab) {
        if ($v === '') { continue; }
        $h .= '<option value="' . e($v) . '">' . e($lab) . '</option>';
    }
    return $h . '</select>';
}

function fabo_calendar_filter_js(): string
{
    return <<<'JS'
(function () {
  var sel = document.getElementById('flagfilter');
  var body = document.getElementById('gridbody');
  if (!sel || !body) { return; }
  sel.addEventListener('change', function () {
    var want = sel.value;
    Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
      var td = tr.querySelector('td[data-field="upcoming_flag"]');
      var v = td ? (td.textContent || '').trim() : '';
      tr.style.display = (!want || v === want) ? '' : 'none';
    });
  });
})();
JS;
}
