<?php
/**
 * HR — an interviewer's own dashboard, opened from the link HR emails them.
 *
 * The link carries a 32-character token tied to one interviewer
 * (hr_panelists). It lists the shortlisted candidates assigned to them and
 * opens the Interview Assessment Sheet for each: ten competencies scored 1–5
 * with comments, the recommendation and remarks. A draft can be saved any
 * number of times; once submitted the sheet is locked until HR reopens it.
 * No sign-in: the interviewer may be from outside the organisation.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/hr/app.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['t'] ?? '');
$panelist = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    try {
        $panelist = fabo_one('SELECT * FROM hr_panelists WHERE token = ?', 's', [$token]);
    } catch (Throwable $e) {
        $panelist = null;
    }
}
$self = 'interview-panel.php?t=' . rawurlencode($token);

$sheet = null;
$aid = (int) ($_GET['a'] ?? $_POST['id'] ?? 0);
if ($panelist && $aid) {
    $sheet = hr_assess_list_one('a.id = ? AND a.panelist_id = ?', 'ii', [$aid, (int) $panelist['id']]);
}

// A résumé of a candidate assigned to this interviewer.
if ($panelist && $sheet && !empty($_GET['resume']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    hr_shortlist_send_resume($sheet);
}

$message = '';
if ($panelist && $sheet && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('panel-ip', fabo_client_ip(), 120, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('panel-ip', fabo_client_ip());
        $submit = ($_POST['do'] ?? '') === 'submit';
        try {
            hr_assess_save_sheet($sheet, $_POST, $submit);
            header('Location: ' . $self . ($submit ? '&done=' . $aid : '&a=' . $aid . '&saved=1'));
            exit;
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            error_log('FABO interview panel save: ' . $e->getMessage());
            $message = 'Your sheet could not be saved just now. Please try again in a minute.';
        }
    }
}

if ($panelist) {
    fabo_exec('UPDATE hr_panelists SET last_seen_at = NOW() WHERE id = ?', 'i', [(int) $panelist['id']]);
}
$brand = $panelist ? hr_org_label((int) $panelist['org_id']) : fabo_app_name();
$rounds = hr_assess_rounds();
$statuses = hr_assess_statuses();
fabo_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($sheet ? $sheet['full_name'] . ' — Assessment' : 'Interview dashboard') ?> &middot; <?= e($brand) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/inc.css')) ?>">
<?= hr_assess_css() ?>
<style>
.fm-wrap{max-width:1080px}
.ip-tiles{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px}
.ip-tile{flex:1 1 150px;padding:12px 14px;border:1px solid #dde6e3;border-radius:10px;background:#f7faf9}
.ip-tile b{display:block;font-size:24px;line-height:1.1}
.ip-tile span{font-size:12.5px;color:#4a5551}
.ip-list{width:100%;border-collapse:collapse;font-size:14px}
.ip-list th,.ip-list td{padding:9px 10px;border-bottom:1px solid #e6ece9;text-align:left;vertical-align:top}
.ip-list th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#4a5551}
.ip-pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600}
.ip-pending{background:#eef0f3;color:#4a5363}.ip-draft{background:#fdf3e0;color:#6b4600}.ip-submitted{background:#e3f4ea;color:#1d6b3a}
.ip-ok{padding:10px 14px;border-radius:8px;background:#e3f4ea;color:#1d6b3a;margin:0 0 14px}
.ip-pos{margin:22px 0 6px;font-size:15px}
.ias-form fieldset{border:0;padding:0;margin:0}
.ias-form .ias-recs label{display:flex;gap:6px;align-items:center;cursor:pointer}
.ias-form textarea.wide{width:100%;min-height:80px;box-sizing:border-box}
.ip-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
@media (max-width:720px){.ip-list thead{display:none}.ip-list tr{display:block;border-bottom:1px solid #dde6e3;padding:6px 0}.ip-list td{display:block;border:0;padding:3px 4px}}
</style>
</head>
<body class="fm-body">
<header class="fm-top"><div class="brandmark"><span><?= e($brand) ?></span></div></header>
<main class="fm-wrap" id="main"><div class="fm-card">
<?php if (!$panelist): ?>
  <div class="fm-head"><div class="fm-eyebrow">Interview dashboard</div><h1>This link is not valid</h1>
  <p class="fm-lead">Please use the full link from your email. If it still does not open, ask HR to send you a new one.</p></div>

<?php elseif ($sheet && ($sheet['status'] === 'submitted' || !empty($_GET['view']))): ?>
  <p class="no-print"><a href="<?= e($self) ?>">&larr; Back to my candidates</a></p>
  <?= hr_assess_sheet_html($sheet) ?>
  <div class="ip-actions no-print"><button type="button" class="btn" onclick="window.print()">Print / save as PDF</button></div>

<?php elseif ($sheet):
    $sc = hr_assess_scores($sheet);
    $src = $message !== '' ? $_POST : null;
    $val = static fn($k, $d) => $src !== null ? (string) ($src[$k] ?? '') : (string) $d; ?>
  <p><a href="<?= e($self) ?>">&larr; Back to my candidates</a></p>
  <div class="fm-head"><div class="fm-eyebrow">Interview Assessment Sheet · <?= e($rounds[$sheet['round']] ?? $sheet['round']) ?></div>
  <h1><?= e($sheet['full_name']) ?></h1>
  <p class="fm-lead"><?= e($sheet['position']) ?>. Score each competency from 1 to 5, add comments where useful, choose your recommendation, and submit. “Save draft” keeps your work without submitting.</p></div>
  <?php if (!empty($_GET['saved'])): ?><div class="ip-ok" role="status">Draft saved. You can come back to it from the same link.</div><?php endif; ?>
  <?php if ($message !== ''): ?><div class="inc-warn" role="alert"><?= e($message) ?></div><?php endif; ?>
  <form method="post" action="<?= e($self) ?>" class="ias ias-form" id="ias-form">
    <?= fabo_csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $sheet['id'] ?>">
    <table class="ias-kv">
      <tr><th>Candidate name</th><td><strong><?= e($sheet['full_name']) ?></strong></td></tr>
      <tr><th>Position applied for</th><td><?= e($sheet['position']) ?></td></tr>
      <tr><th>Rank</th><td><?= e($sheet['rank_no'] ?? '—') ?></td></tr>
      <tr><th>Résumé</th><td><?= $sheet['resume_file'] ? '<a href="' . e($self . '&a=' . (int) $sheet['id'] . '&resume=1') . '" target="_blank" rel="noopener"><strong>Open résumé</strong></a> <span class="ias-ind">(' . e($sheet['resume_name']) . ')</span>' : '<span class="ias-ind">Not uploaded</span>' ?></td></tr>
      <tr><th>Hall ticket number</th><td><?= e($sheet['hall_ticket'] ?? '') ?: '—' ?></td></tr>
      <tr><th>Marks in written exam</th><td><?= hr_assess_written_html($sheet) ?></td></tr>
      <?php if ($sheet['qualification'] || $sheet['experience']): ?><tr><th>Qualification / experience</th><td><?= e(implode(' · ', array_filter([$sheet['qualification'], $sheet['experience']]))) ?></td></tr><?php endif; ?>
      <?php if ($sheet['cand_notes']): ?><tr><th>Notes from HR</th><td><?= nl2br(e($sheet['cand_notes'])) ?></td></tr><?php endif; ?>
      <tr><th><label for="ia-when">Interview date &amp; time</label></th><td><input id="ia-when" type="datetime-local" name="interview_at" value="<?= e($val('interview_at', $sheet['interview_at'] ? date('Y-m-d\TH:i', strtotime($sheet['interview_at'])) : '')) ?>"></td></tr>
      <tr><th>Interview round</th><td><?= e($rounds[$sheet['round']] ?? $sheet['round']) ?></td></tr>
      <tr><th>Interviewer name</th><td><?= e($sheet['iv_name']) ?></td></tr>
      <tr><th>Interviewer designation</th><td><?= e($sheet['iv_designation'] ?? '—') ?></td></tr>
      <tr><th>Entity / Location</th><td><?= e($sheet['location'] ?: 'Amaravati') ?></td></tr>
    </table>
    <p class="ias-scale">Rating scale: 5 = Excellent | 4 = Good | 3 = Satisfactory | 2 = Below Average | 1 = Poor</p>

    <h3>Part A – Competency assessment</h3>
    <table class="ias-grid"><thead><tr><th>#</th><th>Competency / Parameter</th><th>Key indicators observed</th><th class="num">Score (1–5)</th><th>Comments</th></tr></thead><tbody>
    <?php foreach (hr_assess_params() as $n => [$title, $ind]): ?>
      <tr><td><?= $n ?></td><td><strong><?= e($title) ?></strong></td><td class="ias-ind"><?= e($ind) ?></td>
        <td class="num"><select name="s<?= $n ?>" aria-label="Score for <?= e($title) ?>" data-score>
          <option value="">–</option>
          <?php foreach (hr_assess_scale() as $k => $lab): ?><option value="<?= $k ?>"<?= $val('s' . $n, (string) $sc[$n]['s']) === (string) $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
        </select></td>
        <td><textarea name="c<?= $n ?>" maxlength="1000" aria-label="Comments on <?= e($title) ?>"><?= e($val('c' . $n, $sc[$n]['c'])) ?></textarea></td></tr>
    <?php endforeach; ?>
    </tbody><tfoot>
      <tr><th colspan="3">Total score (out of 50)</th><th class="num ias-tot"><span id="ias-total">0</span> / 50</th><td></td></tr>
      <tr><th colspan="3">Average score (total ÷ 10)</th><th class="num ias-tot"><span id="ias-avg">0.0</span> / 5</th><td></td></tr>
    </tfoot></table>

    <h3>Part B – Overall recommendation</h3>
    <fieldset><legend class="ias-ind" style="margin-bottom:6px">Choose one</legend><div class="ias-recs">
    <?php foreach (hr_assess_recs() as $k => $lab): ?>
      <label><input type="radio" name="recommendation" value="<?= e($k) ?>"<?= $val('recommendation', $sheet['recommendation']) === $k ? ' checked' : '' ?>> <?= e(mb_strtoupper($lab)) ?></label>
    <?php endforeach; ?>
    </div></fieldset>
    <table class="ias-kv" style="margin-top:12px">
      <tr><th><label for="ia-str">Key strengths of candidate</label></th><td><textarea id="ia-str" class="wide" name="strengths" maxlength="5000"><?= e($val('strengths', $sheet['strengths'])) ?></textarea></td></tr>
      <tr><th><label for="ia-con">Concerns / areas needing clarification</label></th><td><textarea id="ia-con" class="wide" name="concerns" maxlength="5000"><?= e($val('concerns', $sheet['concerns'])) ?></textarea></td></tr>
      <tr><th><label for="ia-obs">Any other observations</label></th><td><textarea id="ia-obs" class="wide" name="observations" maxlength="5000"><?= e($val('observations', $sheet['observations'])) ?></textarea></td></tr>
    </table>
    <div class="ip-actions">
      <button class="btn" name="do" value="draft">Save draft</button>
      <button class="btn btn-primary" name="do" value="submit" onclick="return confirm('Submit this assessment? You will not be able to change it afterwards.')">Submit assessment</button>
    </div>
  </form>
  <script>
  (function () {
    var f = document.getElementById('ias-form');
    function sum() {
      var t = 0;
      f.querySelectorAll('[data-score]').forEach(function (s) { t += parseInt(s.value || '0', 10); });
      document.getElementById('ias-total').textContent = t;
      document.getElementById('ias-avg').textContent = (t / 10).toFixed(1);
    }
    f.addEventListener('change', sum);
    sum();
  })();
  </script>

<?php else:
    $list = fabo_all('SELECT a.*, s.full_name, s.position, s.rank_no, s.hall_ticket, s.written_obj, s.written_desc, s.written_total, s.resume_file FROM hr_assessments a JOIN hr_shortlist s ON s.id = a.shortlist_id
                       WHERE a.panelist_id = ? ORDER BY s.position, a.round, s.rank_no IS NULL, s.rank_no, s.full_name', 'i', [(int) $panelist['id']]);
    $n = count($list);
    $sub = count(array_filter($list, static fn($r) => $r['status'] === 'submitted'));
    $doneId = (int) ($_GET['done'] ?? 0);
    $doneRow = $doneId ? current(array_filter($list, static fn($r) => (int) $r['id'] === $doneId)) : null; ?>
  <div class="fm-head"><div class="fm-eyebrow">Interview dashboard</div><h1>Hello <?= e($panelist['name']) ?></h1>
  <p class="fm-lead">These are the candidates assigned to you<?= $panelist['designation'] ? ' as ' . e($panelist['designation']) : '' ?>. Open a candidate to fill their Interview Assessment Sheet. This page is private to you — please do not share the link.</p></div>
  <?php if ($doneRow): ?><div class="ip-ok" role="status">Thank you — your assessment of <strong><?= e($doneRow['full_name']) ?></strong> has been submitted.</div><?php endif; ?>
  <div class="ip-tiles">
    <div class="ip-tile"><b><?= $n ?></b><span>Assigned to you</span></div>
    <div class="ip-tile"><b><?= $sub ?></b><span>Submitted</span></div>
    <div class="ip-tile"><b><?= $n - $sub ?></b><span>Still to do</span></div>
  </div>
  <?php if (!$list): ?>
    <p>No candidates are assigned to you at the moment.</p>
  <?php else:
    $pos = null;
    foreach ($list as $r):
      if ($r['position'] . $r['round'] !== $pos):
        if ($pos !== null) { echo '</tbody></table>'; }
        $pos = $r['position'] . $r['round']; ?>
        <h2 class="ip-pos"><?= e($r['position']) ?> · <?= e($rounds[$r['round']] ?? $r['round']) ?></h2>
        <table class="ip-list"><thead><tr><th>Rank</th><th>Hall ticket</th><th>Candidate</th><th>Résumé</th><th>Written (obj / desc / total)</th><th>Interview</th><th>Status</th><th>Score</th><th></th></tr></thead><tbody>
      <?php endif; ?>
        <tr><td><?= e($r['rank_no'] ?? '—') ?></td><td><?= e($r['hall_ticket'] ?? '') ?: '—' ?></td><td><strong><?= e($r['full_name']) ?></strong></td><td><?= $r['resume_file'] ? '<a href="' . e($self . '&a=' . (int) $r['id'] . '&resume=1') . '" target="_blank" rel="noopener">Open</a>' : '—' ?></td><td><?= e(hr_assess_fmt($r['written_obj'])) ?> / <?= e(hr_assess_fmt($r['written_desc'])) ?> / <strong><?= e(hr_assess_fmt($r['written_total'])) ?></strong></td>
          <td><?= e($r['interview_at'] ? date('D j M, g:i A', strtotime($r['interview_at'])) : '—') ?></td>
          <td><span class="ip-pill ip-<?= e($r['status']) ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span></td>
          <td><?= $r['total'] !== null ? (int) $r['total'] . ' / 50' : '—' ?></td>
          <td><a class="btn<?= $r['status'] === 'submitted' ? '' : ' btn-primary' ?>" href="<?= e($self . '&a=' . (int) $r['id'] . ($r['status'] === 'submitted' ? '&view=1' : '')) ?>"><?= $r['status'] === 'submitted' ? 'View' : ($r['status'] === 'draft' ? 'Continue' : 'Assess') ?></a></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
<?php endif; ?>
</div></main>
</body>
</html>
