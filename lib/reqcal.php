<?php
/**
 * The requisition calendar: what is due, when, and how the window looks.
 *
 * The grid above it answers "what has been asked for". This answers the
 * question a desk actually asks each morning — what lands today, what lands
 * this week, is anything past its date — and it answers it over whatever
 * stretch of time somebody picks: a day, a month, a quarter, a half-year, a
 * year, or two dates they type.
 *
 * Requisitions are placed by their promised delivery date, because that is
 * the date the queue committed to and the one that turns a row red when it
 * passes. A requisition with no promised date yet is listed apart rather
 * than dropped, since "not scheduled" is a thing a desk needs to see.
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/requisitions.php';

/** The stretches of time the calendar offers, in the order it offers them. */
function fabo_reqcal_periods(): array
{
    return [
        'today'     => 'Today',
        'tomorrow'  => 'Tomorrow',
        'week'      => 'This week',
        'month'     => 'This month',
        'quarter'   => 'This quarter',
        'half'      => 'This half-year',
        'year'      => 'This year',
        'fy'        => 'This financial year',
        'all'       => 'Everything',
        'custom'    => 'Between two dates',
    ];
}

/**
 * ['from', 'to', 'label'] for one of them.
 *
 * The month, quarter, half-year and financial year come from the programme
 * windows, so a quarter means the same thing here as it does on the report
 * and on an invoice. The short ones are worked out here because nothing
 * else in the portal needed a single day before.
 */
function fabo_reqcal_window(string $period, string $from = '', string $to = ''): array
{
    $today = fabo_today();
    switch ($period) {
        case 'today':
            return ['from' => $today, 'to' => $today,
                    'label' => date('D, d M Y', strtotime($today))];
        case 'tomorrow':
            $d = date('Y-m-d', strtotime($today . ' +1 day'));
            return ['from' => $d, 'to' => $d, 'label' => date('D, d M Y', strtotime($d))];
        case 'week':
            // Monday to Sunday: a working week as people say it.
            $mon = date('Y-m-d', strtotime('monday this week', strtotime($today)));
            $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
            return ['from' => $mon, 'to' => $sun,
                    'label' => date('d M', strtotime($mon)) . ' – ' . date('d M Y', strtotime($sun))];
        case 'year':
            return ['from' => date('Y-01-01', strtotime($today)),
                    'to'   => date('Y-12-31', strtotime($today)),
                    'label' => date('Y', strtotime($today))];
        case 'custom':
            $f = $from !== '' ? $from : $today;
            $t = $to !== '' ? $to : $today;
            if ($t < $f) { [$f, $t] = [$t, $f]; }
            return ['from' => $f, 'to' => $t,
                    'label' => date('d M Y', strtotime($f)) . ' – ' . date('d M Y', strtotime($t))];
        case 'all':
            return ['from' => '', 'to' => '', 'label' => 'Everything on the sheet'];
        default:
            // month / quarter / half / fy, straight from the programme windows.
            return fabo_prog_window($period, $from, $to);
    }
}

/**
 * A date as the calendar will treat it: '' when there isn't one.
 *
 * MySQL hands back '0000-00-00' for a date column that was written empty
 * rather than NULL, and that sorts before every real date — so without this
 * a requisition with no promised day quietly lands in January of year zero
 * instead of in the "waiting for a date" list where somebody would see it.
 */
function fabo_reqcal_date($v): string
{
    $d = trim((string) ($v ?? ''));
    return ($d === '' || $d === '0000-00-00' || str_starts_with($d, '0000-')) ? '' : $d;
}

/** Is this date inside the window? An open end means no limit that way. */
function fabo_reqcal_in(string $d, array $win): bool
{
    if ($d === '') {
        return false;
    }
    if (($win['from'] ?? '') !== '' && $d < $win['from']) {
        return false;
    }
    if (($win['to'] ?? '') !== '' && $d > $win['to']) {
        return false;
    }
    return true;
}

/** Where a requisition stands against its promise: on track, tight, or late. */
function fabo_reqcal_standing(array $r, string $today): array
{
    $due    = fabo_reqcal_date($r['promised_due'] ?? '');
    $status = trim((string) ($r['status'] ?? ''));
    if (in_array($status, fabo_req_closed_statuses(), true)) {
        return ['done', 'closed', 'rag-done'];
    }
    if ($due === '') {
        return ['none', 'not scheduled', ''];
    }
    if ($due < $today) {
        return ['late', 'past its date', 'rag-red'];
    }
    $need = fabo_reqcal_date($r['required_by'] ?? '');
    if ($need !== '' && $due > $need) {
        return ['tight', 'later than asked for', 'rag-amber'];
    }
    return ['ok', 'on track', 'rag-green'];
}

/**
 * The whole block: the picker, the counts, and the days.
 *
 * Rendered under the requisitions grid, from the rows the page already
 * loaded — so it shows exactly what the viewer is allowed to see, without
 * asking the database a second time.
 */
function fabo_req_calendar(array $viewer, array $rows): void
{
    $today  = fabo_today();
    $period = (string) ($_GET['rqp'] ?? 'month');
    if (!isset(fabo_reqcal_periods()[$period])) {
        $period = 'month';
    }
    $win = fabo_reqcal_window($period, (string) ($_GET['rqf'] ?? ''), (string) ($_GET['rqt'] ?? ''));

    /* Grouped by the day they are promised for. Anything without a promised
       date is held back and shown after, so it is visible without pretending
       it has a place in the diary. */
    $byDay    = [];
    $unplaced = [];
    $counts   = ['due' => 0, 'late' => 0, 'done' => 0, 'open' => 0];

    foreach ($rows as $r) {
        $due = fabo_reqcal_date($r['promised_due'] ?? '');
        [$state] = fabo_reqcal_standing($r, $today);

        if ($state !== 'done') {
            $counts['open']++;
        }
        if ($due === '') {
            if ($state !== 'done') { $unplaced[] = $r; }
            continue;
        }
        if (!fabo_reqcal_in($due, $win)) {
            continue;
        }
        $byDay[$due][] = $r;
        $counts['due']++;
        if ($state === 'late') { $counts['late']++; }
        if ($state === 'done') { $counts['done']++; }
    }
    ksort($byDay);

    ?>
    <section class="card rqcal" id="rqcal">
      <div class="rqcal-head">
        <div>
          <h2>Requisition calendar <span class="ro"><?= e($win['label']) ?></span></h2>
          <p class="rqcal-lead">Placed by the date the queue promised, which is the
            date a row turns red for missing.</p>
        </div>
        <form method="get" class="rqcal-pick">
          <?php if (!$viewer['is_admin'] && !empty($viewer['client']['token'])): ?>
            <input type="hidden" name="k" value="<?= e((string) $viewer['client']['token']) ?>">
          <?php endif; ?>
          <input type="hidden" name="sheet" value="requisitions">
          <label>Show
            <select name="rqp" onchange="this.form.submit()">
              <?php foreach (fabo_reqcal_periods() as $k => $lab): ?>
                <option value="<?= e($k) ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="rqcal-when"<?= $period === 'custom' ? '' : ' hidden' ?>>From
            <input type="date" name="rqf" value="<?= e((string) ($_GET['rqf'] ?? '')) ?>"></label>
          <label class="rqcal-when"<?= $period === 'custom' ? '' : ' hidden' ?>>To
            <input type="date" name="rqt" value="<?= e((string) ($_GET['rqt'] ?? '')) ?>"></label>
          <button class="btn btn-sm">Show</button>
        </form>
      </div>

      <div class="rqcal-tiles">
        <?php foreach ([
            ['Due in this window', $counts['due']],
            ['Past its date',      $counts['late']],
            ['Delivered or closed', $counts['done']],
            ['Still open, all time', $counts['open']],
            ['Not scheduled yet',  count($unplaced)],
        ] as [$lab, $n]): ?>
          <div class="rqcal-tile"><span><?= e($lab) ?></span><strong><?= number_format($n) ?></strong></div>
        <?php endforeach; ?>
      </div>

      <?php if (!$byDay): ?>
        <div class="empty">Nothing is promised for <?= e(strtolower($win['label'])) ?>.
          Choose a wider stretch of time above.</div>
      <?php else: ?>
        <div class="rqcal-days">
          <?php foreach ($byDay as $day => $set):
              $when  = strtotime($day);
              $isNow = $day === $today;
          ?>
            <div class="rqcal-day<?= $isNow ? ' now' : '' ?>">
              <div class="rqcal-date">
                <strong><?= e(date('d', $when)) ?></strong>
                <span><?= e(date('M', $when)) ?></span>
                <small><?= e(date('D', $when)) ?><?= $isNow ? ' · today' : '' ?></small>
              </div>
              <div class="rqcal-list">
                <?php foreach ($set as $r):
                    [$state, $word, $tone] = fabo_reqcal_standing($r, $today); ?>
                  <div class="rqcal-item <?= e($tone) ?>">
                    <div class="rqcal-what">
                      <strong><?= e((string) ($r['req_id'] ?? '')) ?></strong>
                      <?= e((string) ($r['req_type'] ?? 'no type')) ?>
                      <?php if ((int) ($r['extended_count'] ?? 0) > 0): ?>
                        <span class="rqcal-ext">extended <?= (int) $r['extended_count'] ?>&times;</span>
                      <?php endif; ?>
                    </div>
                    <div class="rqcal-who">
                      <?= e((string) ($r['event_name'] ?? '')) ?>
                      <?php if (trim((string) ($r['raised_by'] ?? '')) !== ''): ?>
                        &middot; <?= e((string) $r['raised_by']) ?>
                      <?php endif; ?>
                    </div>
                    <span class="rqcal-state"><?= e($word) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($unplaced): ?>
        <div class="rqcal-unplaced">
          <h3>Waiting for a date <span class="ro"><?= count($unplaced) ?></span></h3>
          <p class="hint">These have no promised delivery yet — recalculate the queue
            on Requisition Forms and they take their place above.</p>
          <div class="rqcal-list">
            <?php foreach ($unplaced as $r): ?>
              <div class="rqcal-item">
                <div class="rqcal-what">
                  <strong><?= e((string) ($r['req_id'] ?? '')) ?></strong>
                  <?= e((string) ($r['req_type'] ?? 'no type')) ?>
                </div>
                <div class="rqcal-who"><?= e((string) ($r['event_name'] ?? '')) ?></div>
                <span class="rqcal-state">not scheduled</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
    <script>
    (function () {
      /* The two date boxes only mean anything for "between two dates". */
      var pick = document.querySelector('#rqcal select[name=rqp]');
      if (!pick) { return; }
      pick.addEventListener('change', function () {
        document.querySelectorAll('#rqcal .rqcal-when').forEach(function (el) {
          el.hidden = pick.value !== 'custom';
        });
      });
    })();
    </script>
    <?php
}
