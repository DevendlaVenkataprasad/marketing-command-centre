<?php
/**
 * Programs Data, split in two: Upcoming Events and Completed Events.
 *
 * One sheet with every column was too much to read. Before an event the
 * questions are about planning -- dates, budget, approvals -- and after it
 * they are about what came of it. So the same rows are offered twice more,
 * each with only the columns its question needs:
 *
 *   Upcoming Events   the planning columns, editable.
 *   Completed Events  the planning columns read-only, for reference, and the
 *                     results columns editable.
 *
 * Nothing here is a second copy. Both are views of the one `events` table,
 * so an edit made in either is in Programs Data the moment it is saved, and
 * no column is added to or taken off the sheet -- the shared live sheets and
 * the export see exactly what they did before.
 *
 * Which view a column belongs to is a default below, and can be changed per
 * column in Sheet Management -> Columns -> Events.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';
require_once __DIR__ . '/compute.php';

/**
 * The three places a column can sit.
 *
 *   plan    Upcoming (editable) and Completed (read-only)
 *   result  Completed only (editable)
 *   both    Upcoming and Completed, editable in both
 */
function fabo_evstage_choices(): array
{
    return [
        'plan'   => 'Upcoming · read-only in Completed',
        'result' => 'Completed only',
        'both'   => 'Both · editable in both',
    ];
}

/**
 * Where a column sits until somebody says otherwise.
 *
 * Results are what an event produced, so they wait for Completed. The
 * targets each result is measured against -- every Expected beside an
 * Achieved -- are on both and editable on both: set while planning, and
 * still fillable afterwards if nobody did, so Completed has every metric
 * whole. Status, remarks, files and the checklist are worked on before and
 * after too. Everything else -- including a column an organisation added
 * itself -- is planning.
 */
function fabo_evstage_default(string $field): string
{
    static $result = [
        'achieved_ideas', 'achieved_ip', 'achieved_funding', 'achieved_dpiit',
        'achieved_cin', 'achieved_participants', 'achieved_hours', 'actual_attendees',
        'leads_generated', 'budget_spent', 'duration_hours', 'case_studies', 'row_images',
    ];
    static $both = [
        'expected_ideas', 'expected_ip', 'expected_funding', 'expected_dpiit',
        'expected_cin', 'expected_participants', 'expected_hours', 'expected_attendees',
        'leads_expected', 'duration_hours_expected',
        'status', 'remarks', 'asset_count', 'checklist', 'results_state',
    ];

    if (in_array($field, $result, true)) {
        return 'result';
    }
    if (in_array($field, $both, true)) {
        return 'both';
    }
    return 'plan';
}

/** The table the choices are kept in. Safe to run every time. */
function fabo_evstage_ddl(): string
{
    return "CREATE TABLE IF NOT EXISTS `event_column_stage` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL DEFAULT 'events',
  `field` VARCHAR(64) NOT NULL,
  `stage` VARCHAR(10) NOT NULL DEFAULT 'plan',
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_stage` (`sheet_key`, `field`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/**
 * What has been chosen, as [field => stage].
 *
 * Ours and the organisation's together, theirs read last so it wins -- the
 * same rule the column order follows. Cached for the request.
 */
function fabo_evstage_saved(): array
{
    static $byOwner = [];
    require_once __DIR__ . '/lists.php';
    $owner = fabo_view_owner();
    if (isset($byOwner[$owner])) {
        return $byOwner[$owner];
    }
    $out = [];
    try {
        foreach (fabo_all("SELECT field, stage FROM event_column_stage
                            WHERE sheet_key = 'events' AND client_id IN (0, ?)
                            ORDER BY client_id ASC", 'i', [$owner]) as $r) {
            if (isset(fabo_evstage_choices()[$r['stage']])) {
                $out[(string) $r['field']] = (string) $r['stage'];
            }
        }
    } catch (Throwable $e) {
        // No table yet: every column keeps its default.
    }
    return $byOwner[$owner] = $out;
}

/** Where one column sits. */
function fabo_evstage_of(string $field): string
{
    return fabo_evstage_saved()[$field] ?? fabo_evstage_default($field);
}

/**
 * Put a column in a view, from Sheet Management.
 *
 * Choosing the default removes the saved line rather than storing it, so a
 * later change to the defaults reaches every column nobody has touched.
 */
function fabo_evstage_save(string $field, string $stage, int $owner = 0): void
{
    $field = mb_substr(trim($field), 0, 64);
    if ($field === '' || !isset(fabo_evstage_choices()[$stage])) {
        throw new InvalidArgumentException('Choose Upcoming, Completed or Both.');
    }
    $known = false;
    foreach ((fabo_sheet('events', true)['columns'] ?? []) as $c) {
        if ($c[1] === $field) { $known = true; break; }
    }
    if (!$known) {
        foreach ((fabo_sheet('events')['columns'] ?? []) as $c) {
            if ($c[1] === $field) { $known = true; break; }
        }
    }
    if (!$known) {
        throw new InvalidArgumentException('That column is not on the Events sheet.');
    }

    fabo_db()->query(fabo_evstage_ddl());
    if ($stage === fabo_evstage_default($field) && $owner === 0) {
        fabo_exec("DELETE FROM event_column_stage
                    WHERE sheet_key = 'events' AND field = ? AND client_id = 0",
                  's', [$field]);
        return;
    }
    fabo_exec("INSERT INTO event_column_stage (sheet_key, field, stage, client_id)
               VALUES ('events', ?, ?, ?)
               ON DUPLICATE KEY UPDATE stage = VALUES(stage)",
              'ssi', [$field, $stage, $owner]);
}

/**
 * Has this event happened?
 *
 * Done once its last day has arrived: the End Date, or the Start Date when
 * there is no End Date. That is exactly the day its Achieved cells open on
 * the grid (fabo_grid_cell_locked()), so every row on Completed has every
 * results column open to fill -- none shut behind a lock. Status is not
 * consulted: a future event marked Completed would land there with its
 * results still shut. A row with no dates at all is still being planned.
 */
function fabo_evstage_is_done(array $row): bool
{
    $last = trim((string) ($row['end_date'] ?? ''));
    if ($last === '') {
        $last = trim((string) ($row['start_date'] ?? ''));
    }
    if ($last === '' || !($t = strtotime($last))) {
        return false;
    }
    return $t <= strtotime(fabo_today());
}

/**
 * The dates of an event being added as Upcoming or Completed, checked.
 *
 * Used by + Add row on both live sheets (the shared sheet and a department's
 * sheet), so the two say the same thing. Returns $values, with a completed
 * event's missing End Date taken from its Start Date -- a one-day event is
 * usually given only the one. Throws InvalidArgumentException, in words for
 * whoever is filling the sheet in, when the dates do not fit the kind.
 */
function fabo_evstage_check_new(string $stage, array $values, bool $endEditable = true): array
{
    if ($stage !== 'upcoming' && $stage !== 'completed') {
        throw new InvalidArgumentException('Choose Upcoming or Completed first.');
    }
    $start = trim((string) ($values['start_date'] ?? ''));
    $end   = trim((string) ($values['end_date'] ?? ''));
    if ($stage === 'completed' && $end === '' && $start !== '' && $endEditable) {
        $values['end_date'] = $end = $start;
    }
    if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
        throw new InvalidArgumentException('The End Date is before the Start Date.');
    }
    $last  = $end !== '' ? $end : $start;
    $lastT = $last !== '' ? strtotime($last) : false;
    $today = strtotime(fabo_today());
    if ($stage === 'completed') {
        if ($lastT === false) {
            throw new InvalidArgumentException('A completed event needs its End Date (or Start Date).');
        }
        if ($lastT > $today) {
            throw new InvalidArgumentException('A completed event has to have ended by today. '
                . 'For one still to come, choose Upcoming.');
        }
    } elseif ($lastT !== false && $lastT < $today) {
        throw new InvalidArgumentException('That event has already ended. Choose Completed to '
            . 'add it with its results.');
    }
    require_once __DIR__ . '/eventdesign.php';
    return fabo_evdesign_check($values);
}

/** The rows for one view: 'upcoming' or 'completed'. */
function fabo_evstage_rows(array $rows, string $view): array
{
    $done = $view === 'completed';
    return array_values(array_filter($rows,
        static fn(array $r) => fabo_evstage_is_done($r) === $done));
}

/** How many of each, for the tab badges. */
function fabo_evstage_counts(array $rows): array
{
    $c = ['upcoming' => 0, 'completed' => 0];
    foreach ($rows as $r) {
        $c[fabo_evstage_is_done($r) ? 'completed' : 'upcoming']++;
    }
    return $c;
}

/**
 * The one order the Events sheet reads in, on every page.
 *
 * Programs Data, Upcoming Events, Completed Events and the shared live sheet
 * all draw in this order, so a column is in the same place wherever it is
 * looked at -- the views only leave columns out, they never move them.
 *
 * It is the order the programme team agreed on (Upcoming Events as they
 * read it, left to right): Committee Approval, Hub / Spoke, Event ID, Event
 * Name, Event Type, Programme Details, dates, funding and budget, Sector,
 * Department, the expected figures, audience and partners, durations,
 * place, month / quarter / days to go, the SPOC, committee, resources,
 * approvals, campaign, creative, attachments, status, remarks, checklist,
 * priority.
 *
 * Results columns (Completed Events only) sit straight after the target they
 * answer, so an Achieved is always beside its Expected; columns the agreed
 * list does not name come at the end.
 *
 * An entry starting with '@' is a column found by its heading rather than its
 * field -- Department and Duration Planned (days) are columns added on Sheet
 * Management, so their field names live in the database, not here. See
 * fabo_evstage_resolve_order().
 */
function fabo_evstage_master_order(): array
{
    return [
        'committee_state', 'hub_spoke', 'event_id', 'event_name', 'event_type',
        'programme_details', 'start_date', 'end_date', 'funding_source',
        'budget_required', 'budget_allocated', 'budget_spent', 'sector', '@Department',
        'expected_ideas', 'achieved_ideas', 'expected_ip', 'achieved_ip',
        'expected_participants', 'achieved_participants',
        'target_audience', 'partners', 'partner_details', 'partners_count',
        '@Duration Planned (days)', 'duration_hours_expected', 'duration_hours',
        'location', 'city', 'month_label', 'quarter_label', 'days_to_event',
        'spoc_name', 'spoc_contact', 'spoc_email', 'committee', 'local_resources',
        'approval_to', 'approval_requested', 'approval_state', 'approval_remarks',
        'linked_campaign_id', 'creative_due_date', 'asset_count', 'row_images',
        'status', 'results_state', 'remarks', 'case_studies', 'checklist', 'priority',

        // Not in the agreed list: after it, each result still by its target.
        'brand', 'business_unit', 'owner', 'approval_status', 'approval_note',
        'expected_funding', 'achieved_funding', 'expected_dpiit', 'achieved_dpiit',
        'expected_cin', 'achieved_cin', 'expected_hours', 'achieved_hours',
        'expected_attendees', 'actual_attendees', 'leads_expected', 'leads_generated',
    ];
}

/** A heading reduced for matching: case, dashes, spaces and the gear ignored. */
function fabo_evstage_norm(string $s): string
{
    $s = str_replace(['⚙', '–', '—'], ['', '-', '-'], mb_strtolower($s));
    return trim(preg_replace('~\s+~u', ' ', preg_replace('~\s*-\s*~u', ' - ', $s)));
}

/**
 * The master order as field names, for these columns.
 *
 * '@Heading' entries become the field of the column carrying that heading
 * (as renamed, or "Group – Heading" for a metric), and are dropped when no
 * column does. A field met twice keeps its first place.
 */
function fabo_evstage_resolve_order(array $columns): array
{
    $byLabel = [];
    foreach ($columns as $c) {
        $byLabel[fabo_evstage_norm((string) $c[0])] ??= (string) $c[1];
        $g = (string) ($c[3]['group'] ?? '');
        if ($g !== '') {
            $byLabel[fabo_evstage_norm($g . ' - ' . $c[0])] ??= (string) $c[1];
        }
    }
    $out = [];
    foreach (fabo_evstage_master_order() as $f) {
        if ($f[0] === '@') {
            $f = $byLabel[fabo_evstage_norm(substr($f, 1))] ?? '';
        }
        if ($f !== '' && !in_array($f, $out, true)) {
            $out[] = $f;
        }
    }
    return $out;
}

/** The two views, by key. */
function fabo_evstage_views(): array
{
    return ['upcoming' => 'Upcoming Events', 'completed' => 'Completed Events'];
}

/**
 * The recommended order for this sheet as it stands, as a list of fields.
 *
 * The master order, with every column it does not name slotted in straight
 * after the column it follows now -- so Department, sitting after Hub /
 * Spoke today, is still after Hub / Spoke. One with nothing known before it
 * goes after Hub / Spoke too: an added column is nearly always about what
 * the event is.
 */
function fabo_evstage_recommended(array $sheet): array
{
    $now = array_map(static fn($c) => (string) $c[1], $sheet['columns']);
    $order = array_values(array_intersect(fabo_evstage_resolve_order($sheet['columns']), $now));
    $prev = '';
    foreach ($now as $f) {
        if (!in_array($f, $order, true)) {
            $at = $prev !== '' ? array_search($prev, $order, true) : false;
            if ($at === false) {
                $at = array_search('hub_spoke', $order, true);
            }
            array_splice($order, $at === false ? min(1, count($order)) : $at + 1, 0, [$f]);
        }
        $prev = $f;
    }
    return $order;
}

/** Whether the sheet already reads in the recommended order. */
function fabo_evstage_in_order(array $sheet): bool
{
    return array_map(static fn($c) => (string) $c[1], $sheet['columns'])
        === fabo_evstage_recommended($sheet);
}

/**
 * The columns a view shows, and which are read-only there.
 *
 * Returns [fields => [field => true], readonly => [field => true]], in the
 * terms lib/grid.php reads from $GLOBALS['fabo_grid_fields'] and
 * $GLOBALS['fabo_grid_readonly']. In the sheet's own order -- the one
 * Programs Data shows -- so nothing moves between the tabs.
 */
function fabo_evstage_layout(array $sheet, string $view): array
{
    $fields = [];
    $ro     = [];
    foreach ($sheet['columns'] as $c) {
        $f = $c[1];
        if (($c[2] ?? '') === 'id') {
            $fields[$f] = true;
            continue;
        }
        $stage = fabo_evstage_of($f);
        if ($view === 'upcoming') {
            if ($stage !== 'result') {
                $fields[$f] = true;
            }
        } else {
            $fields[$f] = true;
            if ($stage === 'plan') {
                $ro[$f] = true;
            }
        }
    }
    return ['fields' => $fields, 'readonly' => $ro];
}

/**
 * The columns the Fill button asks about on a view: [field => label].
 *
 * Only what can be typed on that view -- not read-only, not worked out by the
 * portal, not an upload, not a tick box (unticked is an answer). Whether a
 * cell is actually empty, or still shut until the event ends, is decided per
 * row by the page, from the cells as drawn.
 */
function fabo_evstage_fillable(array $sheet, array $layout): array
{
    $byField = [];
    foreach ($sheet['columns'] as $c) {
        $byField[$c[1]] = $c;
    }
    $out = [];
    foreach (array_keys($layout['fields']) as $f) {
        $c = $byField[$f] ?? null;
        if (!$c || isset($layout['readonly'][$f])) {
            continue;
        }
        if (in_array($c[2], ['id', 'computed', 'file', 'image', 'images', 'checkbox'], true)
            || fabo_col_kept($c) !== '' || ($c[3]['calc'] ?? '') === 'budget') {
            continue;
        }
        $g = (string) ($c[3]['group'] ?? '');
        $out[$f] = $g !== '' ? $g . ' — ' . $c[0] : $c[0];
    }
    return $out;
}

/** The line under the tabs saying what this view is. */
function fabo_evstage_note(string $view): void
{
    if ($view === 'upcoming') {
        echo '<p class="evstage-note">Events still to come &mdash; planning, budget and '
           . 'approvals. Results columns wait for <strong>Completed Events</strong>. '
           . 'Every change here is saved to Programs Data.</p>';
    } else {
        echo '<p class="evstage-note">Events whose last day has arrived (End Date, or Start Date if there is none). '
           . 'The planning columns are shown for reference and cannot be changed here &mdash; '
           . 'use <strong>Upcoming Events</strong> or <strong>Programs Data</strong> for that. '
           . 'Record the results in the columns that are open.</p>';
    }
}
