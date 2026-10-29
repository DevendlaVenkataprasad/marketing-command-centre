<?php
/**
 * The Master Activity Log, kept by the portal rather than by hand.
 *
 * It was always meant to be the one place every activity appears, whichever
 * sheet the detail lives on — and the dashboard is built from it, so an empty
 * log means a dashboard of zeros however much work is recorded elsewhere.
 * Filling it by hand meant filling everything twice, so it stayed empty and
 * the dashboard stayed blank.
 *
 * So the portal keeps it. Every row on a detail sheet gets a line here, made
 * from that row and kept in step with it. A line whose detail row is deleted
 * goes with it.
 *
 * Two things this is careful about:
 *
 *   - A row somebody typed here themselves is never touched. Automatic lines
 *     carry `src_sheet` and `src_row_id`; a line without them is somebody's
 *     own and is left exactly alone.
 *
 *   - The figures are NOT copied. Budget, leads and revenue stay computed
 *     through `linked_detail_id` the way they always were — see
 *     fabo_resolve_link(). Copying them would mean two numbers that could
 *     disagree, and the wrong one showing on the dashboard.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';

/**
 * Which detail sheets become activities, and how each one reads.
 *
 * `function` has to be one of the Function dropdown's values, because that is
 * what the log is grouped and reported by, and what fabo_resolve_link() uses
 * to find the figures.
 *
 * Every field name is checked against the sheet before it is used, so a sheet
 * that is renamed or has a column taken off degrades to a blank rather than a
 * fatal — this runs on every save, and a save must not fail because a mapping
 * went stale.
 */
function fabo_master_map(): array
{
    return [
        'content' => [
            'function' => 'Content',
            'name'     => 'title_topic',
            'sub'      => 'content_type',
            'start'    => 'draft_due_date',
            'due'      => 'planned_publish_date',
            'done'     => 'actual_publish_date',
        ],
        'website' => [
            'function' => 'Website',
            'name'     => 'task_page',
            'sub'      => 'category',
            'start'    => 'start_date',
            'due'      => 'due_date',
            'done'     => 'completion_date',
        ],
        'design' => [
            'function' => 'Design',
            'name'     => 'project_title',
            'sub'      => 'design_type',
            'owner'    => 'designer',
            'location' => 'store_location',
            'start'    => 'request_date',
            'due'      => 'due_date',
            'done'     => 'completion_date',
        ],
        'campaigns' => [
            'function' => 'Digital Marketing',
            'name'     => 'campaign_name',
            'sub'      => 'objective',
            'location' => 'location',
            'start'    => 'start_date',
            'due'      => 'end_date',
        ],
        'influencers' => [
            'function' => 'Digital Marketing',
            'name'     => 'influencer_name',
            'sub'      => 'platform',
            'location' => 'location',
            'due'      => 'content_due_date',
            'done'     => 'content_live_date',
        ],
        'btl' => [
            'function' => 'BTL Activities',
            'name'     => 'title_description',
            'sub'      => 'activity_type',
            'owner'    => 'coordinator',
            'due'      => 'activity_date',
        ],
        'events' => [
            'function' => 'BTL Activities',
            'name'     => 'event_name',
            'sub'      => 'event_type',
            'start'    => 'start_date',
            'due'      => 'end_date',
        ],
        'calendar' => [
            'function' => 'Content',
            /* `occasion`, not `occasion_name`. Naming it wrongly meant every
               row here had no name, and a row with no name earns no line —
               so this sheet contributed nothing at all. */
            'name'     => 'occasion',
            'sub'      => 'category',
            'status'   => 'content_status',
            'due'      => 'occasion_date',
        ],
        'webcontent' => [
            'function' => 'Content',
            'name'     => 'content_topic',
            'sub'      => 'content_type',
            'brand'    => 'brand_name',
            'due'      => 'scheduled_date',
            'done'     => 'published_at',
        ],
        'requisitions' => [
            'function'      => 'BTL Activities',
            'name'          => 'event_name',
            'sub'           => 'req_type',
            /* A requisition records its hub or spoke as `spoke`, which is the
               same thing every other sheet calls its business unit. */
            'business_unit' => 'spoke',
            'location'      => 'venue',
            'start'         => 'promised_start',
            'due'           => 'promised_due',
            'done'          => 'delivered_on',
        ],
        'store' => [
            'function' => 'BTL Activities',
            'name'     => 'store_name',
            'sub'      => 'store_type',
            'location' => 'city',
            'due'      => 'opening_date',
        ],
    ];
}

/** The fields every mapping may take from its sheet, with sensible defaults. */
function fabo_master_common(): array
{
    return [
        'brand'         => 'brand',
        'business_unit' => 'business_unit',
        'owner'         => 'owner',
        'status'        => 'status',
        'priority'      => 'priority',
        'location'      => 'city',
    ];
}

/**
 * Make sure the log can say where a line came from.
 *
 * Added on demand rather than only in lib/db.php, because this file is asked
 * for the columns on the first save after an upload and the two files do not
 * arrive at the same moment.
 */
function fabo_master_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $have = [];
        foreach (fabo_all('SHOW COLUMNS FROM `master_log`') as $c) {
            $have[$c['Field']] = true;
        }
        if (!isset($have['src_sheet'])) {
            fabo_exec('ALTER TABLE `master_log` ADD COLUMN `src_sheet` VARCHAR(32) NULL');
        }
        if (!isset($have['src_row_id'])) {
            fabo_exec('ALTER TABLE `master_log` ADD COLUMN `src_row_id` INT UNSIGNED NULL');
            /* One line per detail row, enforced here rather than hoped for:
               two saves racing would otherwise leave two lines for one row. */
            try {
                fabo_exec('ALTER TABLE `master_log`
                           ADD UNIQUE KEY `u_src` (`src_sheet`, `src_row_id`)');
            } catch (Throwable $e) {
                error_log('FABO master u_src: ' . $e->getMessage());
            }
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('FABO master_log columns: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Read a field from a row, but only if the sheet actually has that column. */
function fabo_master_take(array $row, array $fields, ?string $name)
{
    if ($name === null || $name === '' || !isset($fields[$name])) {
        return null;
    }
    $v = $row[$name] ?? null;
    return ($v === '' ? null : $v);
}

/**
 * Put one detail row into the log, or bring its line up to date.
 *
 * Returns the id of the line, or 0 when this sheet is not one that becomes
 * activities. Quiet on failure: a save must not be lost because the log
 * could not be written.
 */
function fabo_master_sync(string $key, int $rowId): int
{
    $map = fabo_master_map()[$key] ?? null;
    if (!$map || $rowId <= 0 || !fabo_master_ready()) {
        return 0;
    }
    try {
        $sheet = fabo_sheet($key);
        if (!$sheet || empty($sheet['table'])) {
            return 0;
        }
        $row = fabo_one('SELECT * FROM `' . $sheet['table'] . '` WHERE id = ?', 'i', [$rowId]);
        if (!$row) {
            return 0;
        }
        $fields = [];
        foreach ($sheet['columns'] as $c) {
            $fields[$c[1]] = true;
        }
        $take   = static fn(?string $f) => fabo_master_take($row, $fields, $f);
        $common = fabo_master_common();
        /* A sheet may name its own column for any of the common ones: Design
           keeps its owner in `designer`, Website Content its brand in
           `brand_name`, Requisitions its unit in `spoke`. Falling back to the
           usual name only when the sheet has not said otherwise. */
        $of     = static fn(string $slot) => $map[$slot] ?? $common[$slot];

        $name = (string) ($take($map['name'] ?? null) ?? '');
        if (trim($name) === '') {
            /* A row with nothing in its name column is a blank line somebody
               has just added and not filled in. It becomes an activity when
               it says something. */
            fabo_master_forget($key, $rowId);
            return 0;
        }

        $idField = (string) ($sheet['id_field'] ?? '');
        $link    = $idField !== '' ? (string) ($row[$idField] ?? '') : '';

        $data = [
            'brand'            => $take($of('brand')),
            'business_unit'    => $take($of('business_unit')),
            'function_name'    => $map['function'],
            'sub_function'     => $take($map['sub'] ?? null),
            'activity_name'    => mb_substr($name, 0, 400),
            'description'      => 'From ' . ($sheet['label'] ?? $key)
                                . ($link !== '' ? ' · ' . $link : ''),
            'owner'            => $take($of('owner')),
            'linked_detail_id' => $link !== '' ? $link : null,
            'start_date'       => $take($map['start'] ?? null),
            'due_date'         => $take($map['due'] ?? null),
            'completion_date'  => $take($map['done'] ?? null),
            'status'           => $take($of('status')),
            'priority'         => $take($of('priority')),
            'location'         => $take($of('location')),
            'client_id'        => $row['client_id'] ?? null,
        ];

        $was = fabo_one('SELECT id FROM `master_log` WHERE src_sheet = ? AND src_row_id = ?',
                        'si', [$key, $rowId]);

        if ($was) {
            $set = [];
            $types = '';
            $args  = [];
            foreach ($data as $col => $val) {
                $set[]  = '`' . $col . '` = ?';
                $types .= 's';
                $args[] = $val;
            }
            $args[] = (int) $was['id'];
            fabo_exec('UPDATE `master_log` SET ' . implode(', ', $set) . ' WHERE id = ?',
                      $types . 'i', $args);
            return (int) $was['id'];
        }

        require_once __DIR__ . '/endpoint.php';
        $data['activity_id'] = fabo_next_id(fabo_sheet('master'));
        $data['src_sheet']   = $key;
        $data['src_row_id']  = $rowId;

        $cols = array_keys($data);
        return (int) fabo_exec(
            'INSERT INTO `master_log` (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')',
            str_repeat('s', count($cols)), array_values($data));
    } catch (Throwable $e) {
        error_log('FABO master sync ' . $key . '#' . $rowId . ': ' . $e->getMessage());
        return 0;
    }
}

/** Take a line out, because the row it was made from has gone. */
function fabo_master_forget(string $key, int $rowId): void
{
    if (!fabo_master_ready()) {
        return;
    }
    try {
        fabo_exec('DELETE FROM `master_log` WHERE src_sheet = ? AND src_row_id = ?',
                  'si', [$key, $rowId]);
    } catch (Throwable $e) {
        error_log('FABO master forget: ' . $e->getMessage());
    }
}

/**
 * Catch up a little on every page load, for portals with no cron.
 *
 * The sweep is what puts years of existing rows into the log, and it only ran
 * on the heartbeat. Where cron has not been set up that is never, so the only
 * rows that ever arrived were the ones saved through the grid since the day
 * this was installed — one sheet filling and the rest apparently ignored.
 *
 * A small slice, at most once per request, and only while something is
 * actually missing. Successive page loads work through the backlog; cron does
 * it far faster when it is there.
 */
function fabo_master_nudge(int $slice = 40): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (!fabo_master_ready()) {
            return;
        }
        /* Cheap enough to ask on every page: one indexed look per sheet,
           stopping at the first that has anything outstanding. */
        foreach (array_keys(fabo_master_map()) as $key) {
            $sheet = fabo_sheet($key);
            if (!$sheet || empty($sheet['table'])) {
                continue;
            }
            $missing = fabo_one(
                'SELECT d.id FROM `' . $sheet['table'] . '` d
                  LEFT JOIN `master_log` m ON m.src_sheet = ? AND m.src_row_id = d.id
                  WHERE m.id IS NULL LIMIT 1', 's', [$key]);
            if ($missing) {
                fabo_master_reconcile($slice);
                return;
            }
        }
    } catch (Throwable $e) {
        // A page load owes the log nothing.
    }
}

/**
 * Finish the log before it is shown.
 *
 * The page that displays the log must not display a half-filled one. A slice
 * per page load is right for every other page — it costs them nothing and the
 * backlog clears in the background — but on this one it meant the sheet
 * appeared to need refreshing two or three times before it showed everything,
 * because each load added its slice AFTER the rows had been read.
 *
 * Bounded all the same: a portal with a hundred thousand rows should not hang
 * on the first view. It sweeps until there is nothing left or the ceiling is
 * reached, and the rest arrives on the next view or from cron.
 */
function fabo_master_catch_up(int $ceiling = 5000): int
{
    $done = 0;
    while ($done < $ceiling) {
        [$added] = fabo_master_reconcile(min(500, $ceiling - $done));
        if ($added === 0) {
            break;                          // nothing left outstanding
        }
        $done += $added;
    }
    return $done;
}

/**
 * Sweep the detail sheets for anything the log has missed, and drop lines
 * whose row has gone.
 *
 * Saving keeps the log current row by row, but rows also arrive by import,
 * by a shared sheet being submitted, and from before any of this existed.
 * This is what makes the log right rather than merely up to date, and it is
 * cheap enough to run on the heartbeat.
 *
 * Returns [added, removed].
 */
function fabo_master_reconcile(int $limit = 500): array
{
    if (!fabo_master_ready()) {
        return [0, 0];
    }
    $added = 0;
    $gone  = 0;

    foreach (array_keys(fabo_master_map()) as $key) {
        if ($added >= $limit) {
            break;
        }
        try {
            $sheet = fabo_sheet($key);
            if (!$sheet || empty($sheet['table'])) {
                continue;
            }
            $rows = fabo_all(
                'SELECT d.id FROM `' . $sheet['table'] . '` d
                  LEFT JOIN `master_log` m ON m.src_sheet = ? AND m.src_row_id = d.id
                  WHERE m.id IS NULL
                  ORDER BY d.id DESC LIMIT ' . (int) max(1, $limit - $added),
                's', [$key]);
            foreach ($rows as $r) {
                if (fabo_master_sync($key, (int) $r['id']) > 0) {
                    $added++;
                }
            }
        } catch (Throwable $e) {
            error_log('FABO master reconcile ' . $key . ': ' . $e->getMessage());
        }
    }

    /* Lines whose detail row has gone. Done per sheet so one missing table
       cannot take the whole sweep down with it. */
    foreach (array_keys(fabo_master_map()) as $key) {
        try {
            $sheet = fabo_sheet($key);
            if (!$sheet || empty($sheet['table'])) {
                continue;
            }
            $gone += (int) fabo_exec(
                'DELETE m FROM `master_log` m
                  LEFT JOIN `' . $sheet['table'] . '` d ON d.id = m.src_row_id
                  WHERE m.src_sheet = ? AND d.id IS NULL', 's', [$key]);
        } catch (Throwable $e) {
            error_log('FABO master prune ' . $key . ': ' . $e->getMessage());
        }
    }

    return [$added, $gone];
}

/* ------------------------------------------------------------- narrowing */

/** The columns the log is worth cutting by, and what to call each. */
function fabo_master_filters(): array
{
    return [
        'function_name' => 'Function',
        'sub_function'  => 'Sub-Function',
        'brand'         => 'Brand',
        'business_unit' => 'Business Unit',
        'owner'         => 'Owner',
        'status'        => 'Status',
        'priority'      => 'Priority',
        'rag_status'    => 'RAG',
        'month_label'   => 'Month',
        'quarter_label' => 'Quarter',
    ];
}

/**
 * The values each filter could offer, from the rows in hand.
 *
 * A filter with one value narrows nothing, so it is not offered — the bar
 * should be the questions worth asking of THIS log, not a fixed row of
 * dropdowns most of which do nothing.
 */
function fabo_master_filter_values(array $rows): array
{
    $out = [];
    foreach (array_keys(fabo_master_filters()) as $field) {
        $seen = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$field] ?? ''));
            if ($v !== '') {
                $seen[$v] = true;
            }
        }
        if (count($seen) > 1) {
            $vals = array_keys($seen);
            sort($vals, SORT_NATURAL | SORT_FLAG_CASE);
            $out[$field] = $vals;
        }
    }
    return $out;
}

/** What the address is asking for. */
function fabo_master_chosen(): array
{
    $on = [];
    foreach (array_keys(fabo_master_filters()) as $field) {
        $v = trim((string) ($_GET['m_' . $field] ?? ''));
        if ($v !== '') {
            $on[$field] = $v;
        }
    }
    $q = trim((string) ($_GET['mq'] ?? ''));
    if ($q !== '') {
        $on['q'] = $q;
    }
    return $on;
}

/** Those rows, narrowed. */
function fabo_master_narrow(array $rows, array $on): array
{
    if (!$on) {
        return $rows;
    }
    $q = (string) ($on['q'] ?? '');
    unset($on['q']);

    return array_values(array_filter($rows, static function ($r) use ($on, $q) {
        foreach ($on as $field => $want) {
            if (trim((string) ($r[$field] ?? '')) !== $want) {
                return false;
            }
        }
        if ($q !== '') {
            /* Searched across the words somebody would remember a line by,
               rather than every column: matching an id or a date the reader
               cannot see is worse than not matching. */
            $hay = mb_strtolower(implode(' ', [
                (string) ($r['activity_name'] ?? ''),
                (string) ($r['description'] ?? ''),
                (string) ($r['owner'] ?? ''),
                (string) ($r['linked_detail_id'] ?? ''),
                (string) ($r['activity_id'] ?? ''),
            ]));
            if (!str_contains($hay, mb_strtolower($q))) {
                return false;
            }
        }
        return true;
    }));
}

/** The bar itself, above the grid. */
function fabo_master_filterbar(array $viewer, array $rows, array $on, int $shown): void
{
    $offer = fabo_master_filter_values($rows);
    if (!$offer && ($on['q'] ?? '') === '') {
        return;                             // nothing here is worth narrowing
    }
    $labels = fabo_master_filters();
    $base   = fabo_link($viewer, $viewer['is_admin'] ? 'sheet.php' : 'c.php',
                        ['sheet' => 'master']);
    ?>
  <form method="get" class="mfbar" action="<?= e(strtok($base, '?')) ?>">
    <?php /* A GET form drops everything it is not given, including the token
             that says who is asking on a client's portal. */ ?>
    <?php foreach (['k', 'client'] as $carry): ?>
      <?php if (isset($_GET[$carry]) && $_GET[$carry] !== ''): ?>
        <input type="hidden" name="<?= e($carry) ?>" value="<?= e((string) $_GET[$carry]) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <input type="hidden" name="sheet" value="master">

    <label class="mfsearch">Find
      <input type="search" name="mq" value="<?= e((string) ($on['q'] ?? '')) ?>"
             placeholder="a name, an owner, an id"></label>

    <?php foreach ($offer as $field => $vals): ?>
      <label><?= e($labels[$field]) ?>
        <select name="m_<?= e($field) ?>" onchange="this.form.submit()">
          <option value="">Any</option>
          <?php foreach ($vals as $v): ?>
            <option value="<?= e($v) ?>"<?= ($on[$field] ?? '') === $v ? ' selected' : '' ?>>
              <?= e($v) ?></option>
          <?php endforeach; ?>
        </select></label>
    <?php endforeach; ?>

    <button class="btn btn-sm btn-primary">Apply</button>
    <?php if ($on): ?>
      <a class="btn btn-sm" href="<?= e($base) ?>">Clear</a>
      <span class="mfcount"><strong><?= (int) $shown ?></strong> of <?= count($rows) ?></span>
    <?php else: ?>
      <span class="mfcount"><?= count($rows) ?> activities</span>
    <?php endif; ?>
  </form>
<?php
}
