<?php
/**
 * The event report: every event and programme in a period, written up.
 *
 * Not one event — a period. You pick the window, and the report covers
 * everything that ran in it: the events themselves, the rows their
 * departments contributed, the figures promised against the figures
 * delivered, and the photographs.
 *
 * Laid out to be read on screen and printed to PDF from the browser, which
 * is how invoices already work here — no PDF library, no Composer, and the
 * page you read is exactly the page you get.
 *
 * The narrative is Gemini's, and only the narrative. Every number is added
 * up in PHP and handed to the model as fact, because a model asked to total
 * a column will occasionally get it wrong and will always sound certain.
 */  

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/grid.php';
require_once __DIR__ . '/lib/endpoint.php';
require_once __DIR__ . '/lib/departments.php';
require_once __DIR__ . '/lib/google.php';
require_once __DIR__ . '/lib/programs.php';
fabo_boot();

$token  = (string) ($_GET['k'] ?? '');
$viewer = $token !== '' ? fabo_client_viewer($token)
        : (fabo_signed_in() ? fabo_person_viewer(fabo_signed_in())
                            : (!fabo_admin_signed_in()
                               ? fabo_deny('Please sign in.', 401) : fabo_admin_viewer()));

fabo_require($viewer, 'events', 'view');

/* Whose column names and options this report carries. Set before the sheet is
   read: this page builds its own viewer rather than going through
   fabo_endpoint(), so nothing else settles it, and the report came out with
   the shipped names however the organisation had renamed its columns. */
require_once __DIR__ . '/lib/lists.php';
fabo_view_owner(fabo_customiser($viewer));

$sheet = fabo_sheet('events');

/* ------------------------------------------------- the window and the event */

$period = (string) ($_GET['period'] ?? 'fy');
if (!isset(fabo_prog_periods()[$period])) {
    $period = 'fy';
}
$win = fabo_prog_window($period, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));

$all = fabo_rows('events', $viewer);

/* Which event, if any. 0 is every one of them, which is the default: the
   report was asked for as a portfolio view first and a single-event view
   second. Picking one narrows the same report rather than opening another. */
$only = (int) ($_GET['ev'] ?? 0);

/* One status, or all of them. Narrowing to "Completed" turns the report
   into what actually happened; narrowing to "In Progress" turns it into
   what is still running. Both are things somebody asks for. */
$status = trim((string) ($_GET['status'] ?? ''));

/* The events worth offering in the picker: the real ones, not the rows a
   department contributed, newest first so the recent ones are at the top. */
$pickable = array_values(array_filter($all,
    static fn($r) => (int) ($r['department_id'] ?? 0) === 0));
usort($pickable, static function ($a, $b) {
    return strcmp((string) ($b['start_date'] ?? ''), (string) ($a['start_date'] ?? ''));
});
$statuses = [];
foreach ($all as $r) {
    $v = trim((string) ($r['status'] ?? ''));
    if ($v !== '') { $statuses[$v] = true; }
}
$statuses = array_keys($statuses);
sort($statuses, SORT_NATURAL | SORT_FLAG_CASE);
if ($status !== '' && !in_array($status, $statuses, true)) {
    $status = '';                   // nothing on the books has it
}

$onlyName = '';
if ($only > 0) {
    $picked = array_values(array_filter($pickable, static fn($r) => (int) $r['id'] === $only));
    if (!$picked) {
        $only = 0;                  // gone, or not theirs to see
    } else {
        $onlyName = (string) ($picked[0]['event_name'] ?: 'Untitled event');
    }
}

/* Everything in the window. A row whose run touches the period counts, not
   only one that starts inside it. */
$rows = fabo_prog_sort(fabo_prog_in_range($all, $win['from'], $win['to']));

/* Narrowed to one event, that event and the rows its departments sent in
   come through whatever the period says — somebody who picks an event wants
   that event, not the part of it that happens to fall in a window. */
if ($only > 0) {
    $mineIds = [$only];
    foreach (fabo_all('SELECT id FROM event_departments WHERE event_row_id = ?', 'i', [$only]) as $d) {
        $mineIds[] = (int) $d['id'];
    }
    $rows = array_values(array_filter($all, static function ($r) use ($only, $mineIds) {
        return (int) $r['id'] === $only
            || in_array((int) ($r['department_id'] ?? 0), array_slice($mineIds, 1), true);
    }));
    $rows = fabo_prog_sort($rows);
}

/* The status filter, applied to whatever the window and the event left.
   A department row is judged by its own status, so a filter on "Completed"
   shows the parts that are done even where the event as a whole is not. */
if ($status !== '') {
    $rows = array_values(array_filter($rows,
        static fn($r) => trim((string) ($r['status'] ?? '')) === $status));
}

/* Departments contribute rows of their own. Grouped under the event they
   belong to, so the report reads as events rather than a flat list. */
$deptOf = [];
foreach (fabo_all('SELECT id, name, event_row_id FROM event_departments') as $d) {
    $deptOf[(int) $d['id']] = ['name' => (string) $d['name'],
                               'event' => (int) $d['event_row_id']];
}

$events = [];                       // event row id => ['row' => …, 'parts' => […]]
foreach ($rows as $r) {
    $dept = (int) ($r['department_id'] ?? 0);
    if ($dept > 0 && isset($deptOf[$dept])) {
        continue;                   // filed under its parent below
    }
    $events[(int) $r['id']] = ['row' => $r, 'parts' => []];
}
foreach ($rows as $r) {
    $dept = (int) ($r['department_id'] ?? 0);
    if ($dept === 0 || !isset($deptOf[$dept])) {
        continue;
    }
    $parent = $deptOf[$dept]['event'];
    if (isset($events[$parent])) {
        $events[$parent]['parts'][] = ['row' => $r, 'dept' => $deptOf[$dept]['name']];
    } else {
        // Its parent fell outside the window; show it on its own rather
        // than dropping work somebody did.
        $events[(int) $r['id']] = ['row' => $r, 'parts' => [], 'orphan' => $deptOf[$dept]['name']];
    }
}

/* ------------------------------------------------- the figures, in PHP */

$sum = static function (array $set, string $f): float {
    $t = 0.0;
    foreach ($set as $r) { $t += (float) ($r[$f] ?? 0); }
    return $t;
};

$pairs = [
    ['Ideas generated',             'expected_ideas',        'achieved_ideas',        'num'],
    ['IP granted',                  'expected_ip',           'achieved_ip',           'num'],
    ['Funding',                     'expected_funding',      'achieved_funding',      'money'],
    ['Startups registered (DPIIT)', 'expected_dpiit',        'achieved_dpiit',        'num'],
    ['Companies registered (CIN)',  'expected_cin',          'achieved_cin',          'num'],
    ['Participants',                'expected_participants', 'achieved_participants', 'num'],
    ['Hours',                       'expected_hours',        'achieved_hours',        'num'],
    ['Attendees',                   'expected_attendees',    'actual_attendees',      'num'],
    ['Leads',                       'leads_expected',        'leads_generated',       'num'],
    ['Budget',                      'budget_allocated',      'budget_spent',          'money'],
];

/** The pairs that have something recorded, for any set of rows. */
$metricsFor = static function (array $set) use ($pairs, $sum): array {
    $out = [];
    foreach ($pairs as [$label, $ef, $af, $kind]) {
        $e = $sum($set, $ef);
        $a = $sum($set, $af);
        if ($e == 0.0 && $a == 0.0) {
            continue;
        }
        $out[] = ['label' => $label, 'exp' => $e, 'ach' => $a, 'kind' => $kind,
                  'pct' => $e > 0 ? (int) round($a / $e * 100) : null];
    }
    return $out;
};

$metrics = $metricsFor($rows);

$fmt = static fn(float $v, string $kind) => $kind === 'money'
    ? fabo_money($v) : rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.');

/* Pictures across every event in the window. */
$images   = [];
$imagesOf = [];                     // event row id => its pictures
foreach ($events as $id => $e) {
    $mine = fabo_event_media($id);
    foreach ($mine as $m) {
        $m['event'] = (string) ($e['row']['event_name'] ?? '');
        $images[]   = $m;
    }
    $imagesOf[$id] = $mine;
}

/* ------------------------------------------- the figures worth quoting
 *
 * Totals answer "how much"; these answer "how well", which is what gets
 * quoted in a review. Every one is a ratio between two totals the portal
 * has already added up, so none of them can disagree with the table above.
 * A ratio with nothing under the line is left out rather than shown as
 * zero, because an empty denominator is not a result of nought.
 */
$derivedFor = static function (array $set, int $eventCount, int $shots) use ($sum): array {
    $t = static fn(string $f) => $sum($set, $f);

    $spent      = $t('budget_spent');
    $allocated  = $t('budget_allocated');
    $attendees  = $t('actual_attendees');
    $planned    = $t('expected_attendees');
    $leads      = $t('leads_generated');
    $leadPlan   = $t('leads_expected');
    $people     = $t('achieved_participants');
    $hours      = $t('achieved_hours');
    $ideas      = $t('achieved_ideas');
    $ip         = $t('achieved_ip');
    $dpiit      = $t('achieved_dpiit');
    $cin        = $t('achieved_cin');
    $funding    = $t('achieved_funding');
    $partners   = $t('partners_count');
    $n          = max(1, $eventCount);

    /* [label, value, kind, hint] — kind drives the formatting only. */
    $maybe = [
        ['Budget utilisation', $allocated > 0 ? $spent / $allocated * 100 : null, 'pct',
         'Of every rupee set aside, this much was spent'],
        ['Budget remaining', $allocated > 0 ? $allocated - $spent : null, 'money',
         'Allocated less spent across the period'],
        ['Cost per attendee', $attendees > 0 ? $spent / $attendees : null, 'money',
         'Spend divided by the people who turned up'],
        ['Cost per participant', $people > 0 ? $spent / $people : null, 'money',
         'Spend divided by recorded participants'],
        ['Cost per lead', $leads > 0 ? $spent / $leads : null, 'money',
         'Spend divided by the leads generated'],
        ['Cost per idea generated', $ideas > 0 ? $spent / $ideas : null, 'money',
         'Spend divided by ideas on record'],
        ['Cost per hour delivered', $hours > 0 ? $spent / $hours : null, 'money',
         'Spend divided by hours actually run'],
        ['Attendance against plan', $planned > 0 ? $attendees / $planned * 100 : null, 'pct',
         'Who came, against who was expected'],
        ['Leads against plan', $leadPlan > 0 ? $leads / $leadPlan * 100 : null, 'pct',
         'Leads generated against leads expected'],
        ['Leads per 100 attendees', $attendees > 0 ? $leads / $attendees * 100 : null, 'num',
         'How many in a hundred became a lead'],
        ['Funding raised per rupee spent', $spent > 0 && $funding > 0 ? $funding / $spent : null, 'num',
         'Funding secured for each rupee of budget'],
        ['Ideas that became IP', $ideas > 0 ? $ip / $ideas * 100 : null, 'pct',
         'Ideas generated that reached a granted IP'],
        ['Ideas that became companies', $ideas > 0 ? ($dpiit + $cin) / $ideas * 100 : null, 'pct',
         'Ideas that ended in a DPIIT or CIN registration'],
        ['Average attendees per event', $attendees > 0 ? $attendees / $n : null, 'num',
         'Total attendance spread over the events'],
        ['Average spend per event', $spent > 0 ? $spent / $n : null, 'money',
         'Total spend spread over the events'],
        ['Average hours per event', $hours > 0 ? $hours / $n : null, 'num',
         'Hours delivered spread over the events'],
        ['Partners engaged', $partners > 0 ? $partners : null, 'num',
         'Counted across every event in scope'],
        ['Photographs on file per event', $shots > 0 ? $shots / $n : null, 'num',
         'Evidence recorded against each event'],
    ];

    $out = [];
    foreach ($maybe as [$label, $value, $kind, $hint]) {
        if ($value === null) { continue; }
        $out[] = ['label' => $label, 'value' => (float) $value, 'kind' => $kind, 'hint' => $hint];
    }
    return $out;
};

$derived = $derivedFor($rows, count($events), count($images));

/* How the events in scope are spread across the status list. Shown whether
   or not the status filter is on: with it on it is one bar, which is a fair
   reminder of what has been filtered away. */
$spread = [];
foreach ($rows as $r) {
    $v = trim((string) ($r['status'] ?? '')) ?: 'Not set';
    $spread[$v] = ($spread[$v] ?? 0) + 1;
}
arsort($spread);

$dfmt = static function (float $v, string $kind) use ($fmt): string {
    if ($kind === 'pct')   { return number_format($v, $v < 10 ? 1 : 0) . '%'; }
    if ($kind === 'money') { return fabo_money(round($v)); }
    return rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.');
};

$today   = fabo_today();
$done    = 0;
$ahead   = 0;
foreach ($events as $e) {
    $end = (string) ($e['row']['end_date'] ?? $e['row']['start_date'] ?? '');
    if ($end !== '' && $end < $today) { $done++; } else { $ahead++; }
}

/* ------------------------------------------------------ the narrative */

$narrative = '';
$aiError   = '';
$asked     = isset($_GET['write']);

if ($asked && $rows && fabo_gemini_configured()) {
    $forAi = [];
    foreach ($rows as $r) {
        $one = [];
        foreach ($sheet['columns'] as $c) {
            if ($c[2] === 'computed' || $c[2] === 'images') { continue; }
            $v = trim((string) ($r[$c[1]] ?? ''));
            if ($v !== '') {
                $one[($c[3]['group'] ?? '') !== '' ? $c[3]['group'] . ' ' . $c[0] : $c[0]] = $v;
            }
        }
        $forAi[] = $one;
    }
    $facts = [($only > 0 ? 'Events' : 'Events in the period') => count($events),
              'Rows recorded' => count($rows),
              'Finished'      => $done,
              'Still to come' => $ahead];
    foreach ($derived as $d) {
        $facts[$d['label']] = $dfmt($d['value'], $d['kind']);
    }
    foreach ($spread as $st => $cnt) {
        $facts['Rows marked ' . $st] = $cnt;
    }
    foreach ($metrics as $m) {
        $facts[$m['label']] = 'expected ' . $fmt($m['exp'], $m['kind'])
                            . ', achieved ' . $fmt($m['ach'], $m['kind'])
                            . ($m['pct'] !== null ? ' (' . $m['pct'] . '% of plan)' : '');
    }
    [$ok, $out] = fabo_gemini_event_report(
        ['event_name' => $only > 0 ? $onlyName
                       : count($events) . ' event' . (count($events) === 1 ? '' : 's'),
         'when'  => $only > 0 ? 'every date on record' : $win['label'],
         'where' => $only > 0 ? 'one event and its departments' : 'across the portfolio'],
        $forAi, $facts, count($images));
    if ($ok) { $narrative = $out; } else { $aiError = $out; }
} elseif ($asked && !$rows) {
    $aiError = 'There is nothing in this period to write about.';
} elseif ($asked) {
    $aiError = 'No Gemini API key is set, so the write-up cannot be generated. '
             . 'The figures and rows below are complete without it.';
}

$back = fabo_link($viewer, $viewer['is_admin'] ? 'admin/sheet.php' : 'c.php', ['sheet' => 'events']);
$mine = static function (array $over) use ($viewer, $period, $only, $status) {
    return fabo_link($viewer, 'report.php',
        array_merge(array_filter([
            'ev'     => $only ?: '',
            'status' => $status,
            'period' => $period,
            'from'   => (string) ($_GET['from'] ?? ''),
            'to'     => (string) ($_GET['to'] ?? ''),
        ]), $over));
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Event report &middot; <?= e($only > 0 ? ($onlyName ?: 'Event') : $win['label']) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/report.css')) ?>">
</head>
<body class="rp-body">

<!-- ------------------------------------------------------- the toolbar -->
<form class="rp-bar no-print" method="get" action="<?= e(strtok(fabo_link($viewer, 'report.php', []), '?')) ?>">
  <?php if (!$viewer['is_admin'] && !fabo_signed_in()): ?>
    <input type="hidden" name="k" value="<?= e((string) $viewer['client']['token']) ?>">
  <?php endif; ?>
  <a class="btn btn-sm" href="<?= e($back) ?>">&larr; Events</a>

  <label class="rp-pick">Event
    <select name="ev" id="rpevent">
      <option value="0">All events</option>
      <?php foreach ($pickable as $p): ?>
        <option value="<?= (int) $p['id'] ?>"<?= $only === (int) $p['id'] ? ' selected' : '' ?>>
          <?= e(mb_strimwidth((string) ($p['event_name'] ?: 'Untitled'), 0, 46, '…')
                . (!empty($p['start_date'])
                   ? ' · ' . date('d M Y', strtotime((string) $p['start_date'])) : '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="rp-pick">Status
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach ($statuses as $st): ?>
        <option value="<?= e($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= e($st) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="rp-pick rp-periodpick"<?= $only > 0 ? ' hidden' : '' ?>>Period
    <select name="period" id="rpperiod">
      <?php foreach (fabo_prog_periods() as $k => $lab): ?>
        <option value="<?= e($k) ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($lab) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="rp-pick rp-when"<?= ($period === 'custom' && $only === 0) ? '' : ' hidden' ?>>From
    <input type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></label>
  <label class="rp-pick rp-when"<?= ($period === 'custom' && $only === 0) ? '' : ' hidden' ?>>To
    <input type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></label>

  <button class="btn btn-sm">Show</button>
  <span class="rp-spacer"></span>
  <a class="btn btn-sm" href="<?= e($mine(['write' => 1])) ?>">
    <?= $narrative ? 'Write it again' : 'Write with AI' ?></a>
  <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Download as PDF</button>
</form>

<article class="rp">
  <header class="rp-head">
    <div>
      <span class="rp-eyebrow">Event report</span>
      <h1><?= e($only > 0 ? ($onlyName ?: 'Event') : $win['label']) ?></h1>
      <p><?= e(count($events)) ?> event<?= count($events) === 1 ? '' : 's' ?>
         &middot; <?= e(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?>
         <?php if ($only > 0): ?>
           &middot; every date on record
         <?php else: ?>
           &middot; <?= e(date('d M Y', strtotime($win['from']))) ?>
           to <?= e(date('d M Y', strtotime($win['to']))) ?>
         <?php endif; ?>
         <?php if ($status !== ''): ?>
           &middot; <strong><?= e($status) ?></strong> only
         <?php endif; ?></p>
    </div>
    <div class="rp-meta">
      <span>Prepared</span><strong><?= e(date('d M Y')) ?></strong>
      <span><?= e(fabo_app_name()) ?></span>
    </div>
  </header>

  <?php if ($aiError): ?>
    <div class="rp-note no-print"><?= e($aiError) ?></div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <section class="rp-sec">
      <div class="rp-none"><?= $only > 0
          ? 'Nothing has been recorded against this event yet.'
          : ($status !== ''
             ? 'Nothing in this period is marked “' . e($status) . '”.'
             : 'Nothing ran in this period. Choose a wider one above.') ?></div>
    </section>
  <?php else: ?>

    <!-- --------------------------------------------------- the headline -->
    <section class="rp-sec">
      <div class="rp-tiles">
        <?php foreach ([
            ['Events',        number_format(count($events))],
            ['Rows recorded', number_format(count($rows))],
            ['Finished',      number_format($done)],
            ['Still to come', number_format($ahead)],
            ['Photographs',   number_format(count($images))],
        ] as [$lab, $val]): ?>
          <div class="rp-tile"><span><?= e($lab) ?></span><strong><?= e($val) ?></strong></div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if ($narrative): ?>
      <section class="rp-sec rp-narrative">
        <?php
          /* Gemini is asked for plain lines under bare headings. A line in
             capitals with no full stop is one of those headings. */
          foreach (preg_split('/\R/', $narrative) as $line) {
              $line = rtrim($line);
              if ($line === '') { echo '<div class="rp-gap"></div>'; continue; }
              $isHead = preg_match('~^[A-Z][A-Z \'/&-]{3,}$~', trim($line)) === 1;
              echo $isHead ? '<h2>' . e(trim($line)) . '</h2>' : '<p>' . e($line) . '</p>';
          }
        ?>
        <p class="rp-ai">Written from the rows below by Gemini. Every figure in this
           report is totalled by the portal, not by the model.</p>
      </section>
    <?php endif; ?>

    <!-- ------------------------------------------------ expected v actual -->
    <?php if ($metrics): ?>
      <section class="rp-sec">
        <h2>Expected against achieved
          <span class="rp-count"><?= $only > 0 ? 'this event' : 'whole period' ?></span></h2>
        <table class="rp-table">
          <thead><tr><th>Metric</th><th class="num">Expected</th><th class="num">Achieved</th>
            <th class="num">Of plan</th><th>Standing</th></tr></thead>
          <tbody>
          <?php foreach ($metrics as $m):
              $tone = $m['pct'] === null ? '' : ($m['pct'] >= 100 ? 'good' : ($m['pct'] >= 70 ? 'warn' : 'risk'));
              $word = $m['pct'] === null ? 'no plan set'
                    : ($m['ach'] == 0.0 ? 'not reported yet'
                      : ($m['pct'] >= 100 ? 'met or beaten' : 'short of plan'));
          ?>
            <tr>
              <td><?= e($m['label']) ?></td>
              <td class="num"><?= e($fmt($m['exp'], $m['kind'])) ?></td>
              <td class="num"><?= e($fmt($m['ach'], $m['kind'])) ?></td>
              <td class="num"><?= $m['pct'] === null ? '—' : (int) $m['pct'] . '%' ?></td>
              <td><span class="rp-pill <?= e($tone) ?>"><?= e($word) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <!-- ----------------------------------------- performance and efficiency -->
    <?php if ($derived): ?>
      <section class="rp-sec">
        <h2>Performance and efficiency
          <span class="rp-count"><?= count($derived) ?></span></h2>
        <div class="rp-kpis">
          <?php foreach ($derived as $d): ?>
            <div class="rp-kpi">
              <span class="rp-kpilab"><?= e($d['label']) ?></span>
              <strong><?= e($dfmt($d['value'], $d['kind'])) ?></strong>
              <span class="rp-kpihint"><?= e($d['hint']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- --------------------------------------------------- status spread -->
    <?php if (count($spread) > 0): ?>
      <section class="rp-sec">
        <h2>Where the work stands
          <span class="rp-count"><?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></span></h2>
        <table class="rp-table rp-narrowtable">
          <thead><tr><th>Status</th><th class="num">Rows</th><th class="num">Share</th><th>Spread</th></tr></thead>
          <tbody>
          <?php foreach ($spread as $st => $cnt):
              $share = (int) round($cnt / max(1, count($rows)) * 100); ?>
            <tr>
              <td><?= e($st) ?></td>
              <td class="num"><?= number_format($cnt) ?></td>
              <td class="num"><?= $share ?>%</td>
              <td><span class="rp-share"><span style="width:<?= $share ?>%"></span></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <!-- ------------------------------------------------ events at a glance -->
    <?php if (count($events) > 1): ?>
      <section class="rp-sec">
        <h2>Events at a glance <span class="rp-count"><?= count($events) ?></span></h2>
        <table class="rp-table">
          <thead><tr><th>#</th><th>Event</th><th>When</th><th>Where</th><th>Status</th>
            <th class="num">Spent</th><th class="num">Attendees</th><th class="num">Leads</th>
            <th class="num">Photos</th></tr></thead>
          <tbody>
          <?php $g = 0; foreach ($events as $gid => $ge):
              $gr  = $ge['row'];
              $gs  = array_merge([$gr], array_column($ge['parts'], 'row'));
              $gw  = !empty($gr['start_date'])
                   ? date('d M Y', strtotime((string) $gr['start_date'])) : '—';
              $gp  = trim(((string) ($gr['location'] ?? '')) .
                          (($gr['city'] ?? '') ? ', ' . $gr['city'] : ''), ' ,');
              $g++;
          ?>
            <tr>
              <td class="num"><?= $g ?></td>
              <td><?= e($gr['event_name'] ?: 'Untitled event') ?></td>
              <td><?= e($gw) ?></td>
              <td><?= e($gp ?: '—') ?></td>
              <td><?= e((string) ($gr['status'] ?? '') ?: '—') ?></td>
              <td class="num"><?= e($sum($gs, 'budget_spent') > 0
                    ? fabo_money($sum($gs, 'budget_spent')) : '—') ?></td>
              <td class="num"><?= $sum($gs, 'actual_attendees') > 0
                    ? number_format($sum($gs, 'actual_attendees')) : '—' ?></td>
              <td class="num"><?= $sum($gs, 'leads_generated') > 0
                    ? number_format($sum($gs, 'leads_generated')) : '—' ?></td>
              <td class="num"><?= count($imagesOf[$gid] ?? []) ?: '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <td></td><td>Across the period</td><td></td><td></td><td></td>
            <td class="num"><?= e(fabo_money($sum($rows, 'budget_spent'))) ?></td>
            <td class="num"><?= number_format($sum($rows, 'actual_attendees')) ?></td>
            <td class="num"><?= number_format($sum($rows, 'leads_generated')) ?></td>
            <td class="num"><?= number_format(count($images)) ?></td>
          </tr></tfoot>
        </table>
      </section>
    <?php endif; ?>

    <!-- ---------------------------------------------- event by event -->
    <section class="rp-sec">
      <h2>Event by event <span class="rp-count"><?= count($events) ?></span></h2>

      <?php $n = 0; foreach ($events as $id => $e):
          $r    = $e['row'];
          $set  = array_merge([$r], array_column($e['parts'], 'row'));
          $em   = $metricsFor($set);
          $when = '';
          if (!empty($r['start_date'])) {
              $when = date('d M Y', strtotime((string) $r['start_date']));
              if (!empty($r['end_date']) && $r['end_date'] !== $r['start_date']) {
                  $when .= ' – ' . date('d M Y', strtotime((string) $r['end_date']));
              }
          }
          $where = trim(((string) ($r['location'] ?? '')) .
                        (($r['city'] ?? '') ? ', ' . $r['city'] : ''), ' ,');
          $n++;
      ?>
        <div class="rp-event">
          <div class="rp-eventhead">
            <h3><span class="rp-no"><?= $n ?></span>
              <?= e($r['event_name'] ?: 'Untitled event') ?></h3>
            <div class="rp-eventmeta">
              <?= e(implode(' · ', array_filter([
                    $when ?: 'no date recorded', $where,
                    (string) ($r['event_type'] ?? ''), (string) ($r['brand'] ?? ''),
                    (string) ($r['status'] ?? ''),
                  ]))) ?>
              <?php if (!empty($e['orphan'])): ?>
                <span class="rp-dept"><?= e($e['orphan']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php
            /* What this one event cost and returned, on its own terms. The
               same ratios as the portfolio section, computed over this
               event and the rows its departments contributed. */
            $eShots = $imagesOf[$id] ?? [];
            $eDer   = $derivedFor($set, 1, count($eShots));
            $eDays  = '';
            if (!empty($r['start_date'])) {
                $s0 = strtotime((string) $r['start_date']);
                $e0 = strtotime((string) ($r['end_date'] ?: $r['start_date']));
                $eDays = max(1, (int) round(($e0 - $s0) / 86400) + 1);
            }
            $eFacts = array_filter([
                'Runs for'      => $eDays === '' ? '' : $eDays . ' day' . ($eDays === 1 ? '' : 's'),
                'Departments'   => count($e['parts']) ?: '',
                'Photographs'   => count($eShots) ?: '',
                'Budget spent'  => (float) ($r['budget_spent'] ?? 0) > 0
                                   ? fabo_money((float) $r['budget_spent']) : '',
                'Attendees'     => (float) ($r['actual_attendees'] ?? 0) > 0
                                   ? number_format((float) $r['actual_attendees']) : '',
                'Leads'         => (float) ($r['leads_generated'] ?? 0) > 0
                                   ? number_format((float) $r['leads_generated']) : '',
                'Owner'         => (string) ($r['owner'] ?? ''),
                'Sign-off'      => (string) ($r['approval_status'] ?? ''),
            ], static fn($v) => $v !== '' && $v !== null);
          ?>
          <?php if ($eFacts): ?>
            <div class="rp-facts">
              <?php foreach ($eFacts as $lab => $v): ?>
                <div class="rp-fact"><span><?= e($lab) ?></span><strong><?= e((string) $v) ?></strong></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($em): ?>
            <table class="rp-table rp-mini">
              <thead><tr><th>Metric</th><th class="num">Expected</th>
                <th class="num">Achieved</th><th class="num">Of plan</th><th>Standing</th></tr></thead>
              <tbody>
              <?php foreach ($em as $m):
                  $tone = $m['pct'] === null ? '' : ($m['pct'] >= 100 ? 'good' : ($m['pct'] >= 70 ? 'warn' : 'risk'));
                  $word = $m['pct'] === null ? 'no plan set'
                        : ($m['ach'] == 0.0 ? 'not reported yet'
                          : ($m['pct'] >= 100 ? 'met or beaten' : 'short of plan'));
              ?>
                <tr>
                  <td><?= e($m['label']) ?></td>
                  <td class="num"><?= e($fmt($m['exp'], $m['kind'])) ?></td>
                  <td class="num"><?= e($fmt($m['ach'], $m['kind'])) ?></td>
                  <td class="num"><?= $m['pct'] === null ? '—' : (int) $m['pct'] . '%' ?></td>
                  <td><span class="rp-pill <?= e($tone) ?>"><?= e($word) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <?php if ($eDer): ?>
            <div class="rp-chips">
              <?php foreach ($eDer as $d): ?>
                <span class="rp-chip"><?= e($d['label']) ?>
                  <b><?= e($dfmt($d['value'], $d['kind'])) ?></b></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php
            /* The rest of the record, split by shape rather than by column
               order: short values read as a label-and-value list, and the
               long ones read as prose. Cramming a paragraph into a two-inch
               column is what made the old block hard to follow. */
            $detail = [];
            $prose  = [];
            foreach ($sheet['columns'] as $c) {
                if (in_array($c[2], ['computed', 'id', 'images'], true)) { continue; }
                if (in_array($c[1], ['event_name', 'start_date', 'end_date', 'location',
                                     'city', 'event_type', 'brand', 'status', 'owner',
                                     'budget_spent', 'actual_attendees', 'leads_generated',
                                     'approval_status'], true)) { continue; }
                if (($c[3]['group'] ?? '') !== '') { continue; }   // in the table above
                $v = trim((string) ($r[$c[1]] ?? ''));
                if ($v === '') { continue; }
                if ($c[2] === 'long' || mb_strlen($v) > 90) {
                    $prose[$c[0]] = $v;
                    continue;
                }
                $detail[$c[0]] = $c[2] === 'money' ? fabo_money($v)
                               : ($c[2] === 'date' ? date('d M Y', strtotime($v)) : $v);
            }
          ?>
          <?php if ($detail): ?>
            <dl class="rp-detail">
              <?php foreach ($detail as $lab => $v): ?>
                <div><dt><?= e($lab) ?></dt><dd><?= e($v) ?></dd></div>
              <?php endforeach; ?>
            </dl>
          <?php endif; ?>

          <?php if ($prose): ?>
            <div class="rp-prose">
              <?php foreach ($prose as $lab => $v): ?>
                <h5><?= e($lab) ?></h5>
                <?php foreach (preg_split('/\R{2,}/', $v) as $para): ?>
                  <p><?= nl2br(e(trim($para))) ?></p>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php $shots = $eShots; if ($shots): ?>
            <div class="rp-eventshots">
              <h4><?= count($shots) ?> photograph<?= count($shots) === 1 ? '' : 's' ?></h4>
              <div class="rp-gallery small">
                <?php foreach ($shots as $m): ?>
                  <figure>
                    <img src="<?= e(fabo_root_link($viewer, 'download.php', ['f' => (int) $m['id']])) ?>"
                         alt="<?= e($m['caption'] ?: $m['original_name']) ?>">
                    <?php if (!empty($m['dept_name'])): ?>
                      <figcaption><?= e($m['dept_name']) ?></figcaption>
                    <?php endif; ?>
                  </figure>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($e['parts']): ?>
            <div class="rp-parts">
              <h4><?= count($e['parts']) ?> department
                <?= count($e['parts']) === 1 ? 'entry' : 'entries' ?></h4>
              <?php foreach ($e['parts'] as $p):
                  $pr = $p['row'];
                  $bits  = [];
                  $notes = [];          // the long answers, set as prose
                  foreach ($sheet['columns'] as $c) {
                      if (in_array($c[2], ['computed', 'id', 'images'], true)) { continue; }
                      if ($c[1] === 'event_name') { continue; }
                      $v = trim((string) ($pr[$c[1]] ?? ''));
                      if ($v === '') { continue; }
                      $lab = ($c[3]['group'] ?? '') !== '' ? $c[3]['group'] . ' ' . $c[0] : $c[0];
                      if ($c[2] === 'long' || mb_strlen($v) > 90) {
                          $notes[$lab] = $v;
                          continue;
                      }
                      $bits[$lab] = $c[2] === 'money' ? fabo_money($v)
                                  : ($c[2] === 'date' ? date('d M Y', strtotime($v)) : $v);
                  }
              ?>
                <div class="rp-part">
                  <strong><?= e($p['dept']) ?></strong>
                  <span class="rp-ref"><?= e($pr['event_id'] ?? '') ?></span>
                  <?php if ($bits): ?>
                    <dl class="rp-detail">
                      <?php foreach ($bits as $lab => $v): ?>
                        <div><dt><?= e($lab) ?></dt><dd><?= e($v) ?></dd></div>
                      <?php endforeach; ?>
                    </dl>
                  <?php elseif (!$notes): ?>
                    <p class="rp-empty">Nothing filled in yet.</p>
                  <?php endif; ?>
                  <?php if ($notes): ?>
                    <div class="rp-prose">
                      <?php foreach ($notes as $lab => $v): ?>
                        <h5><?= e($lab) ?></h5>
                        <?php foreach (preg_split('/\R{2,}/', $v) as $para): ?>
                          <p><?= nl2br(e(trim($para))) ?></p>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>

  <?php endif; ?>

  <footer class="rp-foot">
    <?= e(fabo_app_name()) ?> &middot; <?= e($only > 0 ? ($onlyName ?: 'Event') : $win['label']) ?>
    &middot; prepared <?= e(date('d M Y')) ?>
  </footer>
</article>

<script>
(function () {
  var p = document.getElementById('rpperiod');
  var e = document.getElementById('rpevent');
  if (!p || !e) { return; }

  /* One event means every date it has, so the period picker has nothing to
     decide and is put away rather than left there looking meaningful. The
     From / To boxes only ever mean anything for a custom range. */
  function show() {
    var oneEvent = e.value !== '0';
    Array.prototype.forEach.call(document.querySelectorAll('.rp-periodpick'), function (el) {
      el.hidden = oneEvent;
    });
    Array.prototype.forEach.call(document.querySelectorAll('.rp-when'), function (el) {
      el.hidden = oneEvent || p.value !== 'custom';
    });
  }
  p.addEventListener('change', show);
  e.addEventListener('change', function () { show(); this.form.submit(); });
  show();
})();
</script>
</body>
</html>
