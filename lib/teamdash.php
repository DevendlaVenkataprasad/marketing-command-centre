<?php
/**
 * How a team is doing: the figures and the charts behind the Team page.
 *
 * Rendered identically for our own desk (admin/team.php) and for a client
 * running their own people (people.php). Both get the same questions
 * answered — how much is assigned, how much is done, what is late, and who
 * is carrying it — because both are managing a team and the questions do not
 * change with who is asking.
 *
 * Everything here works on work items already gathered through
 * fabo_work_items(), which applies the viewer's scope. Nothing queries, so
 * one organisation's figures can never include another's.
 */

require_once __DIR__ . '/work.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/rules.php';

/**
 * The whole block.
 *
 * $members are the people to break the work down by; $items is every work
 * item the viewer can see. An item whose owner matches nobody on the team is
 * counted as unassigned, which is usually the most useful number on the page.
 */
/**
 * The team's Incubation Management: who on the team is carrying which
 * startups, tasks and evaluations, and the organisation's pipeline. Read only
 * for a signed-in session, as incubation.php is — applications carry
 * applicants' personal details.
 */
function fabo_team_inc_dashboard(array $viewer, array $members): void
{
    if (!fabo_signed_in()) {
        echo '<div class="block cmw"><div class="cmw-head"><div><h2>Incubation — how the team is doing</h2>'
           . '<div class="hint">Incubation figures are shown only when you are signed in.</div></div>'
           . '<div class="cmw-act"><a class="btn btn-primary" href="' . e('login.php?next=' . rawurlencode('people.php?kind=team')) . '">Sign in</a></div></div></div>';
        return;
    }
    require_once __DIR__ . '/inc/app.php';
    try {
        inc_start($viewer);
        $d = inc_dashboard_figures();
        $count = static function (string $sql): array {
            $out = [];
            foreach (inc_all($sql) as $r) {
                $out[(int) $r['k']] = (int) $r['n'];
            }
            return $out;
        };
        $owned   = $count('SELECT owner_id AS k, COUNT(*) AS n FROM inc_workspaces WHERE closed_at IS NULL AND owner_id IS NOT NULL GROUP BY owner_id');
        $tasks   = $count('SELECT assignee_id AS k, COUNT(*) AS n FROM inc_tasks WHERE status = "open" AND assignee_id IS NOT NULL GROUP BY assignee_id');
        $late    = $count('SELECT assignee_id AS k, COUNT(*) AS n FROM inc_tasks WHERE status = "open" AND assignee_id IS NOT NULL AND due_date < CURDATE() GROUP BY assignee_id');
        $evals   = $count('SELECT evaluator_id AS k, COUNT(*) AS n FROM inc_evaluations WHERE evaluator_id IS NOT NULL
                           AND status IN ("submitted","under_review","returned","draft","in_progress") GROUP BY evaluator_id');
    } catch (Throwable $e) {
        error_log('FABO team incubation: ' . get_class($e) . ': ' . $e->getMessage());
        echo '<div class="card"><div class="empty">Incubation figures could not be loaded just now.</div></div>';
        return;
    }
    $stageId = static fn(string $pipe, string $code) => (int) (inc_stage_by_code($pipe, $code, false)['id'] ?? 0);
    $n = static fn($v) => number_format((int) $v);
    $all = $members;
    $all[] = $viewer['client'];             // the head carries work too
    ?>
  <div class="block">
    <h2>Incubation — how the team is doing</h2>
    <div class="tiles inc-tiles">
      <?= inc_tile('Total applications', $n($d['total']), inc_url('applications'))
        . inc_tile('Awaiting screening', $n($d['awaiting_screen']), inc_url('applications', ['stage_id' => $stageId('crm', 'new')]), $d['awaiting_screen'] ? 'amber' : '')
        . inc_tile('Evaluation incomplete', $n($d['incomplete']), inc_url('applications', ['eval_status' => 'incomplete']), $d['incomplete'] ? 'amber' : '')
        . inc_tile('Awaiting review', $n($d['in_review']), inc_url('applications', ['eval_status' => 'submitted']))
        . inc_tile('Approved evaluations', $n($d['approved']), inc_url('applications', ['eval_status' => 'approved']), 'green',
                   $d['avg_score'] !== null ? 'Average score ' . number_format((float) $d['avg_score'], 1) : '')
        . inc_tile('Awaiting assignment', $n($d['awaiting_assignment']), inc_url('applications', ['ws' => 'no', 'eval_status' => 'approved']), $d['awaiting_assignment'] ? 'amber' : '')
        . inc_tile('Active incubations', $n($d['active_inc']), inc_url('workspaces', ['open' => 'open']), 'green')
        . inc_tile('Completed', $n($d['completed']), inc_url('workspaces', ['open' => 'closed'])) ?>
    </div>

    <div class="scrollx" style="margin-bottom:16px">
      <table class="data">
        <thead><tr><th>Member</th><th class="num">Startups owned</th><th class="num">Open tasks</th>
          <th class="num">Overdue tasks</th><th class="num">Evaluations open</th><th></th></tr></thead>
        <tbody>
        <?php
          usort($all, static fn($a, $b) => (($owned[(int) $b['id']] ?? 0) + ($tasks[(int) $b['id']] ?? 0))
                                           <=> (($owned[(int) $a['id']] ?? 0) + ($tasks[(int) $a['id']] ?? 0)));
          foreach ($all as $m):
            $id = (int) $m['id'];
            $lt = $late[$id] ?? 0; ?>
          <tr>
            <td><strong><?= e($m['name']) ?></strong><?= $id === (int) $viewer['client']['id'] ? ' <span class="ro">you</span>' : '' ?></td>
            <td class="num"><?= (int) ($owned[$id] ?? 0) ?></td>
            <td class="num"><?= (int) ($tasks[$id] ?? 0) ?></td>
            <td class="num<?= $lt ? ' tone-risk' : '' ?>"><?= $lt ?></td>
            <td class="num"><?= (int) ($evals[$id] ?? 0) ?></td>
            <td><a class="btn btn-sm" href="<?= e(inc_url('team', ['person' => $id])) ?>">Their incubation work</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
      $rows = static function (array $list): array {
          $out = [];
          foreach ($list as $s) {
              $out[$s['name']] = (int) $s['n'];
          }
          return $out;
      };
      $sids = array_column($d['by_stage'], 'id', 'name');
      $lids = array_column($d['by_lifecycle'], 'id', 'name');
      $load = [];
      foreach ($all as $m) {
          $load[(string) $m['name']] = $owned[(int) $m['id']] ?? 0;
      }
      arsort($load);
      echo '<div class="inc-grid2">'
         . '<section class="card"><h2>Applications by pipeline stage</h2>'
         . inc_bars($rows($d['by_stage']), 'No applications yet.', static fn($l) => inc_url('applications', ['stage_id' => (int) $sids[$l]])) . '</section>'
         . '<section class="card"><h2>Incubation lifecycle</h2>'
         . inc_bars($rows($d['by_lifecycle']), 'No incubation workspaces yet.', static fn($l) => inc_url('workspaces', ['stage_id' => (int) $lids[$l]])) . '</section>'
         . '<section class="card"><h2>Open startups by owner</h2>' . inc_bars($load, 'No startups assigned to anyone yet.') . '</section>'
         . '<section class="card"><h2>Applications by source</h2>' . inc_bars($d['by_source'], 'No applications yet.') . '</section>'
         . '</div>';
    ?>
  </div>
<?php
}

function fabo_team_dashboard(array $viewer, array $members, array $items): void
{
    $today = fabo_today();

    /*
     * Work indexed by owner value — but the page is indexed by MEMBER, which
     * is not the same thing and was the bug here. Keying the table by owner
     * value dropped anyone who had none, and silently collapsed two people
     * who shared one. Every member now gets a row whatever their Owner value
     * says, including nothing.
     */
    $byOwner = [];
    foreach ($items as $it) {
        $o = trim((string) $it['owner']);
        if ($o !== '') {
            $byOwner[$o][] = $it;
        }
    }

    // How many members answer to each owner value, so a shared one can say so.
    $sharing = [];
    foreach ($members as $m) {
        $o = trim((string) ($m['owner_name'] ?? ''));
        if ($o !== '') {
            $sharing[$o] = ($sharing[$o] ?? 0) + 1;
        }
    }

    // One entry per member, in the order the page will show them.
    $rowsFor = [];
    foreach ($members as $m) {
        $o = trim((string) ($m['owner_name'] ?? ''));
        $rowsFor[] = [
            'name'   => (string) $m['name'],
            'owner'  => $o,
            'items'  => $o !== '' ? ($byOwner[$o] ?? []) : [],
            'shared' => $o !== '' && ($sharing[$o] ?? 0) > 1,
        ];
    }
    // Busiest first: the person carrying the most is the one to look at.
    usort($rowsFor, static fn($a, $b) => count($b['items']) <=> count($a['items']));

    // Work that answers to no member here — an empty Owner, or a name that
    // belongs to nobody on this team.
    $mine  = array_filter(array_map(static fn($m) => trim((string) ($m['owner_name'] ?? '')), $members));
    $loose = array_values(array_filter($items, static function ($it) use ($mine) {
        $o = trim((string) $it['owner']);
        return $o === '' || !in_array($o, $mine, true);
    }));

    $unset = count(array_filter($rowsFor, static fn($r) => $r['owner'] === ''));

    $all = fabo_work_summary($items);
    $dueSoon = $all['due_soon'];

    /* ----------------------------------------------------------- tiles */

    $tiles = [
        ['Team members', number_format(count($members)),
         $unset ? $unset . ' with no Owner value yet' : 'all tied to an Owner value'],
        ['Work assigned', number_format($all['assigned']),
         count($loose) . ' not on anyone here'],
        ['Completed', number_format($all['completed']),
         $all['assigned'] ? $all['pct_done'] . '% of everything' : 'nothing assigned yet'],
        ['Still open', number_format($all['open']),
         $all['open'] ? 'in progress or not started' : 'nothing outstanding'],
        ['Overdue', number_format($all['overdue']),
         $all['overdue'] ? 'past their date and not closed' : 'nothing late'],
        ['Due in 7 days', number_format($dueSoon), 'coming up this week'],
    ];
    ?>
  <div class="block">
    <h2>How the team is doing</h2>
    <div class="teamtiles">
      <?php foreach ($tiles as $i => [$label, $value, $note]):
          $tone = '';
          if ($label === 'Overdue' && $all['overdue'] > 0)      { $tone = ' risk'; }
          if ($label === 'Completed' && $all['pct_done'] >= 70) { $tone = ' good'; }
          if ($label === 'Due in 7 days' && $dueSoon > 0)       { $tone = ' warn'; }
      ?>
        <div class="teamtile<?= $tone ?>">
          <span><?= e($label) ?></span>
          <strong><?= e($value) ?></strong>
          <small><?= e($note) ?></small>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
      <div class="empty">No work is assigned on any sheet yet. Once rows carry an
        Owner value, the figures and charts appear here.</div>
    </div>
    <?php return; endif; ?>


    <div class="scrollx">
      <table class="data">
        <thead><tr>
          <th>Member</th><th>Owner value</th>
          <th class="num">Assigned</th><th class="num">Completed</th>
          <th class="num">Open</th><th class="num">Overdue</th><th class="num">Due in 7d</th>
          <th style="min-width:150px">Progress</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rowsFor as $r):
            $s    = fabo_work_summary($r['items']);
            $tone = $s['overdue'] ? 'risk' : ($s['pct_done'] >= 70 ? 'good' : 'warn');
            $none = $r['owner'] === '';
        ?>
          <tr<?= $none ? ' class="noowner"' : '' ?>>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td>
              <?php if ($none): ?>
                <span class="nowt">no Owner value set</span>
                <div class="meta">Rows cannot be assigned to them until one is chosen</div>
              <?php else: ?>
                <?= e($r['owner']) ?>
                <?php if ($r['shared']): ?>
                  <div class="meta">shared with another member — these figures cover both</div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="num"><?= $none ? '—' : $s['assigned'] ?></td>
            <td class="num tone-good"><?= $none ? '—' : $s['completed'] ?></td>
            <td class="num"><?= $none ? '—' : $s['open'] ?></td>
            <td class="num<?= !$none && $s['overdue'] ? ' tone-risk' : '' ?>"><?= $none ? '—' : $s['overdue'] ?></td>
            <td class="num<?= !$none && $s['due_soon'] ? ' tone-warn' : '' ?>"><?= $none ? '—' : $s['due_soon'] ?></td>
            <td>
              <?php if ($none): ?>
                <span class="nowt">—</span>
              <?php else: ?>
                <div class="tbar" title="<?= $s['pct_done'] ?>% complete">
                  <i class="<?= e($tone) ?>" style="width:<?= max(2, $s['pct_done']) ?>%"></i>
                </div>
                <span class="tbarnum"><?= $s['pct_done'] ?>%</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($loose): $ls = fabo_work_summary($loose); ?>
          <tr class="loose">
            <td colspan="2"><strong>Not assigned to anyone here</strong>
              <div class="meta">Rows whose Owner is empty, or names somebody outside this team</div></td>
            <td class="num"><?= $ls['assigned'] ?></td>
            <td class="num"><?= $ls['completed'] ?></td>
            <td class="num"><?= $ls['open'] ?></td>
            <td class="num<?= $ls['overdue'] ? ' tone-risk' : '' ?>"><?= $ls['overdue'] ?></td>
            <td class="num"><?= $ls['due_soon'] ?></td>
            <td></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="block">
    <h2>Charts</h2>
    <div class="chartgrid">
<?php
    /* ------------------------------------------------------------ charts */

    // 1. The state of everyone's work, side by side. Only members who can
    //    actually hold work — a chart bar of nothing says nothing.
    $charted = array_values(array_filter($rowsFor, static fn($r) => $r['owner'] !== ''));
    if ($charted) {
        $labels = array_map(static fn($r) => $r['name'], $charted);
        $done   = [];
        $open   = [];
        $late   = [];
        foreach ($charted as $r) {
            $s = fabo_work_summary($r['items']);
            $done[] = $s['completed'];
            // Overdue is drawn on its own, so "open" here means open and not late.
            $open[] = $s['open'] - $s['overdue'];
            $late[] = $s['overdue'];
        }
        fabo_chart_bar('Work by member — completed, open and overdue', $labels, [
            ['name' => 'Completed', 'color' => FABO_AQUA,   'values' => $done],
            ['name' => 'Open',      'color' => FABO_BLUE,   'values' => $open],
            ['name' => 'Overdue',   'color' => FABO_ORANGE, 'values' => $late],
        ]);
    }

    // 2. Completed against everything still to do — the one-glance answer.
    fabo_chart_pie('Completed against outstanding', [
        ['label' => 'Completed',        'value' => $all['completed'],
         'color' => FABO_AQUA],
        ['label' => 'Open, on schedule','value' => max(0, $all['open'] - $all['overdue']),
         'color' => FABO_BLUE],
        ['label' => 'Overdue',          'value' => $all['overdue'],
         'color' => FABO_ORANGE],
    ]);

    // 3. Every status actually in use, biggest first.
    $byStatus = [];
    foreach ($items as $it) {
        $v = trim((string) $it['status']) ?: 'Not set';
        $byStatus[$v] = ($byStatus[$v] ?? 0) + 1;
    }
    arsort($byStatus);
    $palette = [FABO_BLUE, FABO_AQUA, FABO_ORANGE, '#7a3fb8', '#b4232c', '#8a6d1f', '#555d68'];
    $slices  = [];
    $i = 0;
    foreach ($byStatus as $label => $n) {
        $slices[] = ['label' => $label, 'value' => $n, 'color' => $palette[$i++ % count($palette)]];
    }
    fabo_chart_pie('Where the work stands, by status', $slices);

    // 4. Which sheets the work sits on.
    $bySheet = [];
    foreach ($items as $it) {
        $bySheet[$it['sheet']]['total'] = ($bySheet[$it['sheet']]['total'] ?? 0) + 1;
        if ($it['closed']) {
            $bySheet[$it['sheet']]['done'] = ($bySheet[$it['sheet']]['done'] ?? 0) + 1;
        }
    }
    uasort($bySheet, static fn($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
    fabo_chart_bar('Where the work sits, by sheet', array_keys($bySheet), [
        ['name' => 'Assigned',  'color' => FABO_BLUE,
         'values' => array_map(static fn($v) => $v['total'] ?? 0, array_values($bySheet))],
        ['name' => 'Completed', 'color' => FABO_AQUA,
         'values' => array_map(static fn($v) => $v['done'] ?? 0, array_values($bySheet))],
    ]);

    // 5. Completions over the last eight weeks, against what fell due.
    //    A team can look busy and still be slipping; this is where that shows.
    $weeks = [];
    for ($w = 7; $w >= 0; $w--) {
        $start = date('Y-m-d', strtotime('monday this week -' . $w . ' weeks', strtotime($today)));
        $weeks[$start] = ['label' => date('d M', strtotime($start)),
                          'end'   => date('Y-m-d', strtotime($start . ' +6 days')),
                          'done'  => 0, 'due' => 0];
    }
    foreach ($items as $it) {
        foreach ($weeks as $start => &$wk) {
            if (!empty($it['done']) && $it['done'] >= $start && $it['done'] <= $wk['end']) {
                $wk['done']++;
            }
            if (!empty($it['due']) && $it['due'] >= $start && $it['due'] <= $wk['end']) {
                $wk['due']++;
            }
        }
        unset($wk);
    }
    fabo_chart_line('Last 8 weeks — fell due against completed',
        array_map(static fn($w) => $w['label'], array_values($weeks)), [
            ['name' => 'Fell due',  'color' => FABO_BLUE,
             'values' => array_map(static fn($w) => $w['due'], array_values($weeks))],
            ['name' => 'Completed', 'color' => FABO_AQUA,
             'values' => array_map(static fn($w) => $w['done'], array_values($weeks))],
        ]);

    // 6. How far along each person is, as a percentage.
    if ($charted) {
        fabo_chart_bar('How far along each member is (%)',
            array_map(static fn($r) => $r['name'], $charted), [
                ['name' => 'Complete', 'color' => FABO_AQUA,
                 'values' => array_map(
                     static fn($r) => fabo_work_summary($r['items'])['pct_done'], $charted)],
            ]);
    }
?>
    </div>
  </div>
<?php
}
