<?php
/**
 * My finance: what everyone in the organisation does with Finance — their
 * expense claims and travel advances, purchase requisitions and payment
 * requests, each with where it stands and why; and who approves for them
 * while they are away.
 */

$me = fp_me();
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'advance') {
        $flash = fp_action(static function () {
            fp_nonce_check();
            $id = fp_advance_save($_POST);
            inc_redirect(fp_url('advance', ['id' => $id]), ['ok', 'Advance requested. Your manager has been asked to approve it.']);
        }, fp_url('me'));
    } elseif ($a === 'delegate') {
        $flash = fp_action(static function () {
            fp_delegation_save($_POST + ['from_person_id' => fp_me()]);
            return 'Delegation saved. They will see your approvals for those dates.';
        }, fp_url('me'));
    } elseif ($a === 'undelegate') {
        $flash = fp_action(static function () {
            $id = (int) ($_POST['id'] ?? 0);
            $d = fp_one('SELECT * FROM fp_delegations WHERE id = ?', 'i', [$id]);
            if (!$d || ((int) $d['from_person_id'] !== fp_me() && !fp_can('settings.manage'))) {
                fabo_deny('That delegation is not yours.', 403);
            }
            fp_exec('UPDATE fp_delegations SET active = 0 WHERE id = ?', 'i', [$id]);
            fp_audit('delegation', $id, 'end', 'Delegation ended');
            return 'Delegation ended.';
        }, fp_url('me'));
    }
}

$claims = fp_all('SELECT * FROM fp_claims WHERE employee_person_id = ? ORDER BY id DESC LIMIT 50', 'i', [$me]);
$advances = fp_all('SELECT * FROM fp_advances WHERE employee_person_id = ? ORDER BY id DESC LIMIT 20', 'i', [$me]);
$prs = fp_all('SELECT * FROM fp_requisitions WHERE requester_person_id = ? ORDER BY id DESC LIMIT 30', 'i', [$me]);
$reqs = fp_all('SELECT * FROM fp_payments WHERE kind = "request" AND requester_person_id = ? ORDER BY id DESC LIMIT 30', 'i', [$me]);
$inbox = fp_wf_inbox(50);

$acts = fp_self_writable() && fp_me() > 0 ? '<a class="btn btn-primary" href="' . e(fp_url('claim', ['new' => 1])) . '">+ Expense claim</a> <a class="btn" href="' . e(fp_url('pr', ['new' => 1])) . '">+ Requisition</a> '
      . '<a class="btn" href="' . e(fp_url('payment', ['new' => 'request'])) . '">+ Payment request</a>' : '';
fp_layout_start('My finance', 'Your claims, advances and requests — and what is waiting for you to approve.', $acts, [], $flash);

if ($inbox) {
    echo '<div class="inc-note"><strong>' . count($inbox) . ' item' . (count($inbox) === 1 ? '' : 's') . ' waiting for your approval.</strong> <a href="' . e(fp_url('approvals')) . '">Open approvals →</a></div>';
}

echo '<section class="card"><div class="inc-cardhead"><h2>My expense claims</h2></div>';
if (!$claims) {
    echo inc_empty('No claims yet.', 'Start a claim for travel, meals, mileage or anything you paid for the organisation. Attach a receipt to each line.');
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Claim</th><th>Purpose</th><th class="num">Claimed</th><th class="num">To be paid</th><th>Status</th><th>Where it stands</th></tr></thead><tbody>';
    foreach ($claims as $c) {
        echo '<tr><td data-l="Claim"><a href="' . e(fp_url('claim', ['id' => $c['id']])) . '"><strong>' . e($c['number']) . '</strong></a></td><td data-l="Purpose">' . e($c['purpose']) . '</td>'
           . fp_money_td($c['total'], 'Claimed') . fp_money_td($c['net_payable'], 'To be paid') . '<td data-l="Status">' . fp_badge(fp_claim_statuses(), $c['status']) . '</td>'
           . '<td data-l="Where it stands" class="small">' . e(fp_claim_explain($c)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

echo '<div class="inc-grid2"><section class="card"><h2>Travel advances</h2>';
if ($advances) {
    echo '<table class="data inc-table stack"><thead><tr><th>Advance</th><th>Purpose</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>';
    foreach ($advances as $a) {
        echo '<tr><td data-l="Advance"><a href="' . e(fp_url('advance', ['id' => $a['id']])) . '">' . e($a['number']) . '</a></td><td data-l="Purpose">' . e($a['purpose']) . '</td>'
           . fp_money_td($a['amount'], 'Amount') . '<td data-l="Status">' . fp_badge(['submitted' => ['Waiting for approval', 'amber'], 'approved' => ['Approved — to be paid', 'blue'],
             'paid' => ['Paid — settle with a claim', 'amber'], 'settled' => ['Settled', 'green'], 'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', '']], $a['status']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
if (fp_self_writable()) {
    echo '<details' . ($advances ? '' : ' open') . '><summary class="btn btn-sm">Ask for an advance</summary>' . inc_form_open() . fp_nonce_field() . '<input type="hidden" name="action" value="advance">'
       . inc_field('What it is for', '<input name="purpose" maxlength="500" required placeholder="e.g. Client visit to Hyderabad, 3 days">', '', true)
       . '<div class="inc-row">' . inc_field('Amount', '<input name="amount" inputmode="decimal" required>', '', true)
       . inc_field('Cost centre', inc_select('cost_centre_id', fp_cc_options(), '', 'Choose…', 'required'), '', true)
       . inc_field('Needed by', '<input type="date" name="needed_by">') . '</div><button class="btn btn-primary">Request advance</button></form></details>';
}
echo '</section>';

echo '<section class="card"><h2>Requisitions and payment requests</h2>';
if (!$prs && !$reqs) {
    echo '<p class="muted small">Ask to buy something with a requisition; ask Finance to pay something that has no bill with a payment request.</p>';
}
if ($prs) {
    echo '<h3 class="fp-h3">Requisitions</h3><table class="data inc-table stack"><tbody>';
    foreach ($prs as $r) {
        echo '<tr><td data-l="Requisition"><a href="' . e(fp_url('pr', ['id' => $r['id']])) . '">' . e($r['number']) . '</a></td><td data-l="Purpose">' . e(mb_strimwidth($r['purpose'], 0, 60, '…')) . '</td>'
           . fp_money_td($r['est_total'], 'Estimate') . '<td data-l="Status">' . fp_badge(['draft' => ['Draft', ''], 'submitted' => ['Waiting for approval', 'amber'], 'approved' => ['Approved', 'green'],
             'ordered' => ['Ordered', 'blue'], 'rejected' => ['Rejected', 'red'], 'closed' => ['Closed', ''], 'cancelled' => ['Cancelled', '']], $r['status']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
if ($reqs) {
    echo '<h3 class="fp-h3">Payment requests</h3><table class="data inc-table stack"><tbody>';
    foreach ($reqs as $r) {
        echo '<tr><td data-l="Request"><a href="' . e(fp_url('payment', ['id' => $r['id']])) . '">' . e($r['number']) . '</a></td><td data-l="Payee">' . e($r['payee_name']) . '</td>'
           . fp_money_td($r['amount'], 'Amount') . '<td data-l="Status">' . fp_badge(fp_payment_statuses(), $r['status']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</section></div>';

// Delegation: someone approves for you while you are away.
$dels = fp_all('SELECT * FROM fp_delegations WHERE active = 1 AND (from_person_id = ? OR to_person_id = ?) AND to_date >= CURDATE() ORDER BY from_date', 'ii', [$me, $me]);
echo '<section class="card"><h2>Approvals while you are away</h2><p class="muted small">Name someone to approve for you for a few days. Their decisions are recorded as made on your behalf.</p>';
if ($dels) {
    echo '<ul class="fp-files">';
    foreach ($dels as $d) {
        $out = (int) $d['from_person_id'] === $me;
        echo '<li>' . ($out ? 'You → <strong>' . e(fp_person_name((int) $d['to_person_id'])) . '</strong>' : '<strong>' . e(fp_person_name((int) $d['from_person_id'])) . '</strong> → you')
           . ' · ' . e(fp_fmt_date($d['from_date'])) . ' to ' . e(fp_fmt_date($d['to_date'])) . ($d['doc_types'] ? ' · ' . e($d['doc_types']) : ' · everything')
           . ($out ? ' ' . inc_button('End', ['action' => 'undelegate', 'id' => $d['id']], 'btn btn-sm', 'End this delegation?') : '') . '</li>';
    }
    echo '</ul>';
}
if (fp_self_writable() && $me > 0) {
    $people = fp_people();
    unset($people[$me]);
    echo inc_form_open() . '<input type="hidden" name="action" value="delegate"><div class="inc-row">'
       . inc_field('Approver while away', inc_select('to_person_id', $people, '', 'Choose…', 'required'), '', true)
       . inc_field('From', '<input type="date" name="from_date" value="' . e(date('Y-m-d')) . '" required>', '', true)
       . inc_field('To', '<input type="date" name="to_date" required>', '', true)
       . inc_field('Reason', '<input name="reason" maxlength="255" placeholder="e.g. On leave">', '', false, 'grow') . '</div><button class="btn">Delegate</button></form>';
}
echo '</section>';

fp_layout_end();
