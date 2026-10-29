<?php
/**
 * HR — the CEO's approval page, opened from the link HR emails them.
 *
 * Lists the shortlisted candidates HR has sent for approval, each with the
 * written-exam total, the interview scores and recommendations, a link to
 * every submitted assessment sheet and to the résumé. The CEO approves, or
 * rejects with remarks (required). Decided candidates are listed below.
 * See lib/hr/approval.php. No sign-in: the token is the key.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/hr/app.php';
require_once __DIR__ . '/lib/throttle.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['t'] ?? '');
$ceo = null;
try {
    $ceo = hr_ceo_by_token($token);
} catch (Throwable $e) {
    $ceo = null;
}
$self = 'ceo-approval.php?t=' . rawurlencode($token);
$org = $ceo ? (int) $ceo['org'] : 0;

/** A candidate the CEO may look at: one HR has sent for approval. */
$visible = static function (int $sid) use ($org): ?array {
    return $sid > 0 ? fabo_one('SELECT * FROM hr_shortlist WHERE id = ? AND org_id = ? AND approval_status IS NOT NULL', 'ii', [$sid, $org]) : null;
};

if ($ceo && ($rid = (int) ($_GET['resume'] ?? 0))) {
    $s = $visible($rid);
    if (!$s || !$s['resume_file']) {
        http_response_code(404);
        exit('That résumé is not available.');
    }
    hr_shortlist_send_resume($s);
}

$sheet = null;
if ($ceo && ($shid = (int) ($_GET['sheet'] ?? 0))) {
    $sheet = hr_assess_list_one("a.id = ? AND a.org_id = ? AND a.status = 'submitted'", 'ii', [$shid, $org]);
    if ($sheet && !$visible((int) $sheet['shortlist_id'])) {
        $sheet = null;
    }
}

$message = '';
$errFor = 0;
if ($ceo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('ceo-ip', fabo_client_ip(), 120, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('ceo-ip', fabo_client_ip());
        $sid = (int) ($_POST['sid'] ?? 0);
        try {
            $s = hr_approval_decide($ceo, $sid, (string) ($_POST['decision'] ?? ''), (string) ($_POST['remarks'] ?? ''));
            header('Location: ' . $self . '&done=' . $sid . '&how=' . ($_POST['decision'] === 'approved' ? 'a' : 'r'));
            exit;
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $errFor = $sid;
        } catch (Throwable $e) {
            error_log('FABO CEO approval: ' . $e->getMessage());
            $message = 'Your decision could not be saved just now. Please try again in a minute.';
            $errFor = $sid;
        }
    }
}

$brand = $ceo ? hr_org_label($org) : fabo_app_name();
$rounds = hr_assess_rounds();
$recs = hr_assess_recs();
fabo_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($sheet ? $sheet['full_name'] . ' — Assessment' : 'Candidate approvals') ?> &middot; <?= e($brand) ?></title>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/inc.css')) ?>">
<?= hr_assess_css() ?>
<style>
.fm-wrap{max-width:1080px}
.ca-tiles{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px}
.ca-tile{flex:1 1 150px;padding:12px 14px;border:1px solid #dde6e3;border-radius:10px;background:#f7faf9}
.ca-tile b{display:block;font-size:24px;line-height:1.1}
.ca-tile span{font-size:12.5px;color:#4a5551}
.ca-card{border:1px solid #dde6e3;border-radius:12px;padding:16px 18px;margin:0 0 16px;background:#fff}
.ca-card h2{margin:0 0 2px;font-size:18px}
.ca-meta{color:#4a5551;font-size:13.5px;margin:0 0 10px}
.ca-card table{width:100%;border-collapse:collapse;font-size:13.5px;margin:6px 0 10px}
.ca-card th,.ca-card td{border-bottom:1px solid #e6ece9;padding:6px 8px;text-align:left;vertical-align:top}
.ca-card th{font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;color:#4a5551}
.ca-note{background:#f4f7fb;border-left:3px solid #8aa4c8;padding:8px 12px;border-radius:6px;font-size:13.5px;margin:0 0 10px}
.ca-form textarea{width:100%;min-height:70px;box-sizing:border-box;padding:8px;border:1px solid #cfd6df;border-radius:7px;font:inherit}
.ca-acts{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
.fm-body .btn-reject{border-color:#b4232c;color:#b4232c}
.ca-pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600}
.ca-approved{background:#e3f4ea;color:#1d6b3a}.ca-rejected{background:#fbe5e6;color:#9b1c24}.ca-pending{background:#fdf3e0;color:#6b4600}
.ca-ok{padding:10px 14px;border-radius:8px;background:#e3f4ea;color:#1d6b3a;margin:0 0 14px}
.ca-list{width:100%;border-collapse:collapse;font-size:14px}
.ca-list th,.ca-list td{padding:8px 10px;border-bottom:1px solid #e6ece9;text-align:left;vertical-align:top}
@media (max-width:720px){.ca-card table thead,.ca-list thead{display:none}.ca-card tr,.ca-list tr{display:block;padding:4px 0;border-bottom:1px solid #e6ece9}.ca-card td,.ca-list td{display:block;border:0;padding:2px 4px}}
</style>
</head>
<body class="fm-body">
<header class="fm-top"><div class="brandmark"><span><?= e($brand) ?></span></div></header>
<main class="fm-wrap" id="main"><div class="fm-card">
<?php if (!$ceo): ?>
  <div class="fm-head"><div class="fm-eyebrow">Candidate approvals</div><h1>This link is not valid</h1>
  <p class="fm-lead">Please use the full link from your email. If it still does not open, ask HR to send you a new one.</p></div>

<?php elseif ($sheet): ?>
  <p class="no-print"><a href="<?= e($self) ?>">&larr; Back to approvals</a></p>
  <?= hr_assess_sheet_html($sheet) ?>
  <div class="ca-acts no-print"><button type="button" class="btn" onclick="window.print()">Print / save as PDF</button></div>

<?php else:
    $pending = hr_approval_list($org, 'pending');
    $decided = array_filter(hr_approval_list($org), static fn($s) => $s['approval_status'] !== 'pending');
    $doneId = (int) ($_GET['done'] ?? 0);
    $doneRow = $doneId ? $visible($doneId) : null; ?>
  <div class="fm-head"><div class="fm-eyebrow">Candidate approvals</div><h1>Dear <?= e($ceo['name']) ?></h1>
  <p class="fm-lead">These shortlisted candidates have been interviewed and are sent to you by HR for approval. Review each one and approve, or reject with your remarks.
  HR can send an offer letter only to candidates you approve. This page is private to you — please do not share the link.</p></div>
  <?php if ($doneRow): ?><div class="ca-ok" role="status">Recorded: <strong><?= e($doneRow['full_name']) ?></strong> <?= ($_GET['how'] ?? '') === 'a' ? 'approved' : 'rejected' ?>. HR has been informed.</div><?php endif; ?>
  <?php if ($message !== '' && !$errFor): ?><div class="inc-warn" role="alert"><?= e($message) ?></div><?php endif; ?>
  <div class="ca-tiles">
    <div class="ca-tile"><b><?= count($pending) ?></b><span>Waiting for you</span></div>
    <div class="ca-tile"><b><?= count(array_filter($decided, static fn($s) => $s['approval_status'] === 'approved')) ?></b><span>Approved</span></div>
    <div class="ca-tile"><b><?= count(array_filter($decided, static fn($s) => $s['approval_status'] === 'rejected')) ?></b><span>Rejected</span></div>
  </div>

  <?php if (!$pending): ?><p>No candidates are waiting for your approval at the moment.</p><?php endif; ?>
  <?php foreach ($pending as $s):
      $sum = hr_approval_summary($org, (int) $s['id']); ?>
    <section class="ca-card" id="c<?= (int) $s['id'] ?>">
      <h2><?= e($s['full_name']) ?></h2>
      <p class="ca-meta"><?= e(implode(' · ', array_filter([$s['position'], $s['rank_no'] ? 'Rank ' . $s['rank_no'] : null,
            $s['hall_ticket'] ? 'Hall ticket ' . $s['hall_ticket'] : null,
            $s['written_total'] !== null ? 'Written exam: objective ' . hr_assess_fmt($s['written_obj']) . ', descriptive ' . hr_assess_fmt($s['written_desc']) . ', total ' . hr_assess_fmt($s['written_total']) : null,
            $sum['avg'] !== null ? 'Interview average ' . hr_assess_fmt($sum['avg']) . ' / 50' : null, $s['qualification'], $s['experience']]))) ?>
        <?= $s['resume_file'] ? ' · <a href="' . e($self . '&resume=' . (int) $s['id']) . '" target="_blank" rel="noopener"><strong>Résumé</strong></a>' : '' ?></p>
      <?php if ($s['approval_note']): ?><div class="ca-note"><strong>Note from HR:</strong> <?= nl2br(e($s['approval_note'])) ?></div><?php endif; ?>
      <table><thead><tr><th>Round</th><th>Interviewer</th><th>Score</th><th>Recommendation</th><th></th></tr></thead><tbody>
      <?php foreach ($sum['sheets'] as $a): ?>
        <tr><td><?= e($rounds[$a['round']] ?? $a['round']) ?></td><td><?= e($a['iv_name']) ?><?= $a['iv_designation'] ? '<div class="ias-ind">' . e($a['iv_designation']) . '</div>' : '' ?></td>
          <td><strong><?= (int) $a['total'] ?></strong> / 50 <span class="ias-ind">(<?= number_format($a['total'] / 10, 1) ?> / 5)</span></td>
          <td><?= e($recs[$a['recommendation']] ?? '—') ?></td><td><a href="<?= e($self . '&sheet=' . (int) $a['id']) ?>">View sheet</a></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php if ($message !== '' && $errFor === (int) $s['id']): ?><div class="inc-warn" role="alert"><?= e($message) ?></div><?php endif; ?>
      <form method="post" action="<?= e($self) ?>#c<?= (int) $s['id'] ?>" class="ca-form">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="sid" value="<?= (int) $s['id'] ?>">
        <label for="r<?= (int) $s['id'] ?>"><strong>Remarks</strong> <span class="ias-ind">(required if you reject)</span></label>
        <textarea id="r<?= (int) $s['id'] ?>" name="remarks" maxlength="5000"><?= e($errFor === (int) $s['id'] ? (string) ($_POST['remarks'] ?? '') : '') ?></textarea>
        <div class="ca-acts">
          <button class="btn btn-primary" name="decision" value="approved" onclick="return confirm('Approve <?= e(addslashes($s['full_name'])) ?>?')">Approve</button>
          <button class="btn btn-reject" name="decision" value="rejected" onclick="var t=this.form.remarks;if(!t.value.trim()){alert('Please give your remarks for rejecting.');t.focus();return false;}return confirm('Reject <?= e(addslashes($s['full_name'])) ?>?')">Reject</button>
        </div>
      </form>
    </section>
  <?php endforeach; ?>

  <?php if ($decided): ?>
    <h2 style="margin-top:26px;font-size:16px">Already decided</h2>
    <table class="ca-list"><thead><tr><th>Candidate</th><th>Position</th><th>Decision</th><th>Remarks</th><th>Date</th></tr></thead><tbody>
    <?php foreach ($decided as $s): ?>
      <tr><td><strong><?= e($s['full_name']) ?></strong></td><td><?= e($s['position']) ?></td>
        <td><span class="ca-pill ca-<?= e($s['approval_status']) ?>"><?= e(ucfirst($s['approval_status'])) ?></span></td>
        <td><?= nl2br(e($s['approval_remarks'] ?? '')) ?: '—' ?></td><td><?= e($s['approval_at'] ? date('j M Y', strtotime($s['approval_at'])) : '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
<?php endif; ?>
</div></main>
</body>
</html>
