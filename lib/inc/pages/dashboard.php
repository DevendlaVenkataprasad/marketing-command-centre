<?php
/** Incubation Management — dashboard. Every figure is counted from saved records. */

$d = inc_dashboard_figures();
$stageLink = static fn(int $id) => inc_url('applications', ['view' => 'list', 'stage_id' => $id]);
$sid = static fn(string $pipe, string $code) => (int) (inc_stage_by_code($pipe, $code, false)['id'] ?? 0);
$list = static fn(array $q = []) => inc_url('applications', ['view' => 'list'] + $q);

$acts = '<a class="btn" href="' . e(inc_url('applications')) . '">Open the board</a>'
      . (inc_can('edit') ? ' <a class="btn btn-primary" href="' . e(inc_url('new')) . '">+ New application</a>' : '');
inc_layout_start('Incubation Overview', 'Live view of intake, selection and the incubation portfolio · updated ' . inc_fmt_datetime(date('Y-m-d H:i:s')), [], $acts);

if (!inc_forms_list() && inc_can('configure')) {
    echo '<div class="inc-note"><strong>Getting started.</strong> Create an application form in <a href="' . e(inc_url('settings', ['tab' => 'forms'])) . '">Settings → Forms</a>,
          add your spokes and sectors under <a href="' . e(inc_url('settings', ['tab' => 'lists'])) . '">Lists</a>, and set up
          <a href="' . e(inc_url('settings', ['tab' => 'teams'])) . '">departments</a> from your team members.</div>';
}

/* Small line icons, drawn in the current text colour. */
$icon = static function (string $k): string {
    $p = ['apps' => '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
          'screen' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.5-4.5"/>',
          'eval' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h11"/>',
          'rocket' => '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2"/><path d="M9 15l-3-3c1.5-5 6-9 12-9 0 6-4 10.5-9 12z"/><circle cx="14.5" cy="9.5" r="1.5"/>',
          'warn' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
          'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
          'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
          'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
          'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
          'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'][$k] ?? '';
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$pctText = static fn(float $pct) => $pct >= 1 || $pct == 0 ? (string) (int) round($pct) : '&lt;1';

/* A headline tile; the thin meter shows its share of all applications. */
$kpi = static function (string $label, int $n, string $href, string $foot, string $tone, string $ic, ?int $of = null) use ($icon): string {
    $meter = $of ? '<span class="m"><i style="width:' . max(1, (int) round(min($n, $of) * 100 / $of)) . '%"></i></span>' : '';
    return '<a class="incd-kpi ' . e($tone) . '" href="' . e($href) . '"><span class="top"><span class="k">' . e($label) . '</span><span class="ic">' . $icon($ic) . '</span></span>'
         . '<strong>' . number_format($n) . '</strong>' . $meter . '<span class="f">' . $foot . '</span></a>';
};

/* Ranked rows: label and count on one line, the share bar beneath. */
$flow = static function (array $data, string $empty, ?callable $link = null) use ($pctText): string {
    $data = array_filter($data, static fn($n) => $n > 0);
    if (!$data) {
        return '<p class="incd-none">' . e($empty) . '</p>';
    }
    $max = max($data);
    $sum = array_sum($data);
    $h = '<ol class="incd-flow">';
    $i = 0;
    foreach ($data as $label => $n) {
        $lab = $link ? '<a href="' . e($link((string) $label)) . '">' . e((string) $label) . '</a>' : e((string) $label);
        $h .= '<li class="c' . ($i++ % 6 + 1) . '"><div class="t"><span class="l">' . $lab . '</span><span class="n">' . number_format($n)
            . '<small>' . $pctText($n * 100 / $sum) . '%</small></span></div>'
            . '<div class="b"><i style="width:' . max(1, (int) round($n * 100 / $max)) . '%"></i></div></li>';
    }
    return $h . '</ol>';
};

/* A section heading inside a card: title, one line of context, an optional link. */
$head = static fn(string $title, string $sub, string $href = '', string $link = '') =>
    '<div class="incd-head"><div><h2>' . e($title) . '</h2><p>' . $sub . '</p></div>' . ($href !== '' ? '<a href="' . e($href) . '">' . e($link) . ' →</a>' : '') . '</div>';

echo '<div class="incd">';

/* ---- Headline figures. */
$total = (int) $d['total'];
$inEval = $d['incomplete'] + $d['in_review'];
echo '<div class="incd-kpis">'
   . $kpi('Total Applications', $total, inc_url('applications'), '<b>+' . number_format($d['new_30']) . '</b> in the last 30 days', 'blue', 'apps')
   . $kpi('Pending Screening', $d['awaiting_screen'], $list(['stage_id' => $sid('crm', 'new')]),
          $total ? $pctText($d['awaiting_screen'] * 100 / $total) . '% of all applications' : 'Nothing received yet', $d['awaiting_screen'] ? 'amber' : 'grey', 'screen', $total)
   . $kpi('Under Evaluation', $inEval, $list(['eval_status' => 'submitted']),
          number_format($d['approved']) . ' approved' . ($d['avg_score'] !== null ? ' · avg score ' . number_format((float) $d['avg_score'], 0) : ''), 'violet', 'eval', $total)
   . $kpi('Startups in Incubation', $d['active_inc'], inc_url('workspaces'), number_format($d['completed']) . ' graduated · ' . number_format($d['on_hold']) . ' on hold', 'green', 'rocket')
   . '</div>';

/* ---- Online applications, week by week (the last 12 weeks, this week last). */
$weeks = 12;
$mon = strtotime('monday this week');
$first = strtotime('-' . ($weeks - 1) . ' weeks', $mon);
$buckets = array_fill(0, $weeks, 0);
foreach ($d['online_daily'] as $r) {
    $i = (int) floor((strtotime($r['d']) - $first) / 604800);
    if ($i >= 0 && $i < $weeks) {
        $buckets[$i] += (int) $r['n'];
    }
}
$last4 = array_sum(array_slice($buckets, -4));
$prev4 = array_sum(array_slice($buckets, -8, 4));
$delta = $prev4 ? ($last4 - $prev4) * 100 / $prev4 : null;
$peak = max(1, max($buckets));
$scale = (int) (ceil($peak / 10) * 10) ?: 10;

$todo = array_filter([
    [$d['awaiting_screen'], 'Applications to screen', $list(['stage_id' => $sid('crm', 'new')]), 'amber', 'screen'],
    [$d['incomplete'], 'Evaluations incomplete or returned', $list(['eval_status' => 'incomplete']), 'red', 'warn'],
    [$d['in_review'], 'Evaluations awaiting review', $list(['eval_status' => 'submitted']), 'violet', 'clock'],
    [$d['awaiting_assignment'], 'Approved, awaiting incubation assignment', $list(['ws' => 'no', 'eval_status' => 'approved']), 'blue', 'user'],
    [$d['duplicates'], 'Possible duplicate applications', $list(['dup' => 'suspected']), 'blue', 'copy'],
    [inc_can('configure') ? $d['notify_failed'] : 0, 'Emails that failed to deliver', inc_url('settings', ['tab' => 'notifications']), 'red', 'mail'],
], static fn($r) => $r[0] > 0);

echo '<div class="incd-row">';
echo '<section class="card incd-card">'
   . $head('Online Applications', 'Submitted by applicants through public forms, per week')
   . '<div class="incd-hero"><strong>' . number_format($last4) . '</strong><span>in the last 4 weeks</span>'
   . ($delta !== null ? '<em class="' . ($delta >= 0 ? 'up' : 'down') . '">' . ($delta >= 0 ? '▲ ' : '▼ ') . number_format(abs($delta), 0) . '% vs previous 4 weeks</em>' : '')
   . '</div>'
   . '<div class="incd-chart" role="img" aria-label="Online applications per week for the last ' . $weeks . ' weeks">'
   . '<div class="ax"><span>' . $scale . '</span><span>' . (int) ($scale / 2) . '</span><span>0</span></div><div class="plot">';
foreach ($buckets as $i => $n) {
    $start = strtotime('+' . $i . ' weeks', $first);
    $lab = date('j M', $start);
    $tip = ($i === $weeks - 1 ? 'This week' : 'Week of ' . $lab) . ': ' . number_format($n) . ' application' . ($n === 1 ? '' : 's');
    echo '<div class="col' . ($i === $weeks - 1 ? ' now' : '') . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '">'
       . '<i style="height:' . ($n ? max(2, round($n * 100 / $scale, 1)) : 0) . '%"></i><span>' . e($i === $weeks - 1 ? 'This wk' : $lab) . '</span></div>';
}
echo '</div></div>';
if ($d['staff_entered']) {
    echo '<p class="incd-foot">Not charted: ' . number_format($d['staff_entered']) . ' applications entered or bulk-loaded by staff.</p>';
}
echo '</section>';

echo '<section class="card incd-card">' . $head('Action Required', $todo ? count($todo) . ' queue' . (count($todo) > 1 ? 's' : '') . ' waiting on the team' : 'Every queue is clear');
if (!$todo) {
    echo '<p class="inc-allclear">All clear — nothing is waiting on anyone.</p>';
} else {
    echo '<ul class="incd-todo">';
    foreach ($todo as [$n, $label, $href, $tone, $ic]) {
        echo '<li><a class="' . e($tone) . '" href="' . e($href) . '"><span class="ic">' . $icon($ic) . '</span><span class="l">' . e($label)
           . '</span><strong>' . number_format($n) . '</strong><span class="go" aria-hidden="true">›</span></a></li>';
    }
    echo '</ul>';
}
echo '</section></div>';

/* ---- Who is applying: the channel (the card's tag) and the applicant's profile. */
$came = [];
$cameHref = [];
foreach ($d['came_from'] as $r) {
    $label = inc_came_from($r);
    $came[$label] = ($came[$label] ?? 0) + (int) $r['n'];
    $cameHref[$label] ??= $list($r['source_id'] ? ['source_id' => (int) $r['source_id']] : ['purpose' => (string) $r['purpose']]);
}
arsort($came);
$types = [];
$typeHref = [];
$untyped = 0;
foreach ($d['by_type'] as $r) {
    if (!$r['id']) {
        $untyped += (int) $r['n'];
        continue;
    }
    $types[$r['name']] = (int) $r['n'];
    $typeHref[$r['name']] = $list(['applicant_type_id' => (int) $r['id']]);
}
/* One tile per value: the count, its share, and a link to those applications. */
$tiles = static function (string $title, string $sub, array $data, array $hrefs, string $note = '') use ($total, $pctText): string {
    $tones = ['blue', 'violet', 'green', 'amber', 'grey'];
    $h = '<section class="card incd-card"><div class="incd-head"><div><h2>' . e($title) . '</h2><p>' . e($sub) . '</p></div></div><div class="incd-tiles">';
    $i = 0;
    foreach (array_filter($data) as $label => $n) {
        $pct = $total ? $n * 100 / $total : 0;
        $open = isset($hrefs[$label]) ? '<a href="' . e($hrefs[$label]) . '"' : '<div';
        $h .= $open . ' class="incd-tile ' . $tones[min($i++, 4)] . '"><span class="k">' . e((string) $label) . '</span>'
            . '<strong>' . number_format($n) . '</strong>'
            . '<span class="m"><i style="width:' . max(1, (int) round($pct)) . '%"></i></span>'
            . '<span class="f">' . $pctText($pct) . '% of applications</span>' . (isset($hrefs[$label]) ? '</a>' : '</div>');
    }
    return $h . '</div>' . ($note !== '' ? '<p class="incd-foot">' . e($note) . '</p>' : '') . '</section>';
};
echo '<div class="incd-row even">'
   . $tiles('Intake Channels', 'Where applications come from', $came, $cameHref)
   . $tiles('Applicant Profile', 'Who is applying', $types, $typeHref,
            $untyped ? number_format($untyped) . ' application' . ($untyped === 1 ? ' has' : 's have') . ' no applicant type recorded.' : '')
   . '</div>';

/* ---- Where applications and startups stand. */
echo '<div class="incd-row even">';
$rows = [];
foreach ($d['by_stage'] as $s) {
    $rows[$s['name']] = (int) $s['n'];
}
$ids = array_column($d['by_stage'], 'id', 'name');
echo '<section class="card incd-card">' . $head('Application Stages', number_format($total) . ' applications by current stage', inc_url('applications'), 'Board')
   . $flow($rows, 'No applications yet.', static fn($l) => $stageLink((int) $ids[$l])) . '</section>';

$rows = [];
foreach ($d['by_lifecycle'] as $s) {
    $rows[$s['name']] = (int) $s['n'];
}
$lids = array_column($d['by_lifecycle'], 'id', 'name');
echo '<section class="card incd-card">' . $head('Incubation Portfolio', number_format(array_sum($rows)) . ' startups by incubation stage', inc_url('workspaces'), 'Portfolio')
   . $flow($rows, 'No startups in incubation yet.', static fn($l) => inc_url('workspaces', ['view' => 'list', 'stage_id' => (int) $lids[$l]])) . '</section>';
echo '</div>';

/* ---- Insights: one card with tabs instead of five cards. */
$purposes = [];
foreach ($d['by_purpose'] as $k => $n) {
    $purposes[inc_purposes()[$k] ?? $k] = $n;
}
$bands = [];
foreach (['80–100', '60–79', '40–59', 'Below 40'] as $b) {
    $bands[$b] = $d['score_bands'][$b] ?? 0;
}
$tabs = ['event' => ['Programs & Events', $flow($d['by_event'], 'No program or event applications yet.', static fn($l) => $list(['event' => $l]))],
         'purpose' => ['Application Type', $flow($purposes, 'No applications yet.')],
         'form' => ['Intake Forms', $flow($d['by_form'], 'No applications yet.')],
         'spoke' => ['Hub & Spokes', $flow($d['by_spoke'], 'No applications yet.')],
         'score' => ['Score Bands', $flow($bands, 'No approved evaluations yet.')]];
if (!$d['by_event']) {
    unset($tabs['event']);
}
echo '<section class="card incd-card" data-tabs>' . $head('Insights', 'Applications grouped by program, type, form, location and score') . '<div class="inc-tabs" role="tablist">';
foreach ($tabs as $k => [$label]) {
    echo '<button type="button" role="tab" data-tab="' . e($k) . '">' . e($label) . '</button>';
}
echo '</div>';
foreach ($tabs as $k => [$label, $html]) {
    echo '<div data-pane="' . e($k) . '" role="tabpanel"><h3 class="inc-panetitle">' . e($label) . '</h3>' . $html . '</div>';
}
echo '</section>';

/* ---- The latest hand-overs to an incubation owner. */
echo '<section class="card incd-card">' . $head('Latest Incubation Assignments', 'Startups most recently handed to an incubation owner', inc_url('workspaces'), 'All incubations');
if (!$d['recent_assign']) {
    echo inc_empty('No assignments yet.', 'Assign an approved application from its page, or tick several on the Applications board and choose "Assign for incubation".');
} else {
    echo '<div class="scrollx"><table class="data stack incd-table"><thead><tr><th>Date</th><th>Startup</th><th>Status</th><th>Owner</th><th>Team</th><th>Incubation ID</th></tr></thead><tbody>';
    foreach ($d['recent_assign'] as $a) {
        echo '<tr><td data-l="Date" class="muted">' . e(inc_fmt_datetime($a['created_at'])) . '</td><td data-l="Startup"><strong>' . e($a['startup_name'] ?: $a['applicant_name'])
           . '</strong> <span class="muted small">' . e($a['app_ref']) . '</span></td><td data-l="Status">' . ($a['kind'] === 'assign' ? inc_badge('Assigned', 'green') : inc_badge('Reassigned', 'blue'))
           . '</td><td data-l="Owner">' . e(inc_person_name((int) $a['owner_id'])) . ($a['prev_owner_id'] ? ' <span class="muted small">from ' . e(inc_person_name((int) $a['prev_owner_id'])) . '</span>' : '')
           . '</td><td data-l="Team">' . e(inc_team_name((int) $a['team_id'])) . '</td><td data-l="Incubation ID"><a class="incd-ref" href="' . e(inc_url('workspace', ['id' => $a['workspace_id']])) . '">' . e($a['ws_ref']) . '</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

echo '</div>';
inc_layout_end();
