<?php
/**
 * Bank & cash: the accounts and their ledger balances; statements imported
 * as CSV; and reconciliation — matching every statement line to the ledger,
 * by rule and by hand, with the reconciliation statement at a date.
 */

$bankId = (int) ($_GET['bank'] ?? 0);
$bank = $bankId ? fp_bank_account($bankId) : null;
$back = fp_url('banks', $bank ? ['bank' => $bankId] : []);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $flash = match ($a) {
        'save' => fp_action(static function () {
            fp_bank_account_save(inc_int_or_null($_POST['id'] ?? null), $_POST);
            return 'Account saved.';
        }, $back),
        'import' => fp_action(static function () use ($bankId) {
            [$added, $skipped] = fp_bank_import($bankId, $_FILES['statement'] ?? []);
            $auto = fp_can('bank.reconcile') ? fp_bank_automatch($bankId) : 0;
            return $added . ' statement line(s) imported' . ($skipped ? ', ' . $skipped . ' skipped (already imported or not transactions)' : '') . '. ' . $auto . ' matched automatically.';
        }, $back),
        'automatch' => fp_action(static fn() => fp_bank_automatch($bankId) . ' line(s) matched.', $back),
        'match' => fp_action(static function () {
            fp_bank_match((int) ($_POST['line'] ?? 0), (int) ($_POST['gl'] ?? 0));
            return 'Matched.';
        }, $back),
        'unmatch' => fp_action(static function () {
            fp_bank_unmatch((int) ($_POST['line'] ?? 0));
            return 'Unmatched.';
        }, $back),
        'ignore' => fp_action(static function () {
            fp_bank_ignore((int) ($_POST['line'] ?? 0), (string) ($_POST['reason'] ?? ''));
            return 'Ignored.';
        }, $back),
        'entry' => fp_action(static function () {
            fp_bank_entry_from_line((int) ($_POST['line'] ?? 0), (int) ($_POST['account_id'] ?? 0), (string) ($_POST['desc'] ?? ''));
            return 'Posted and matched.';
        }, $back),
        default => null,
    };
}

if (!$bank) {
    $pos = fp_cash_position();
    fp_layout_start('Bank & cash', 'Ledger balances to today. Open an account to import its statement and reconcile it.', fp_can('bank.manage') ? '<a class="btn btn-primary" href="' . e(fp_url('banks', ['edit' => 'new'])) . '">+ Add account</a>' : '', [], $flash);
    if (fp_can('bank.manage') && (($_GET['edit'] ?? '') !== '')) {
        $x = ctype_digit((string) $_GET['edit']) ? (fp_bank_account((int) $_GET['edit']) ?? []) : [];
        echo '<section class="card"><h2>' . ($x ? 'Edit ' . e($x['name']) : 'Add a bank, cash or card account') . '</h2>' . inc_form_open() . '<input type="hidden" name="action" value="save">' . ($x ? '<input type="hidden" name="id" value="' . (int) $x['id'] . '">' : '')
           . '<div class="inc-row">' . inc_field('Name', '<input name="name" maxlength="120" required value="' . e($x['name'] ?? '') . '">', '', true, 'grow')
           . inc_field('Kind', inc_select('kind', ['bank' => 'Bank account', 'cash' => 'Cash', 'card' => 'Corporate card'], $x['kind'] ?? 'bank', '', $x ? 'disabled' : ''))
           . inc_field('Bank', '<input name="bank" maxlength="120" value="' . e($x['bank'] ?? '') . '">') . inc_field('Account number', '<input name="account_no" maxlength="40" value="' . e($x['account_no'] ?? '') . '" autocomplete="off">')
           . inc_field('IFSC', '<input name="ifsc" maxlength="15" value="' . e($x['ifsc'] ?? '') . '">') . inc_field('Currency', '<input name="currency" maxlength="3" size="4" value="' . e($x['currency'] ?? fp_setting('base_currency')) . '"' . ($x ? ' readonly' : '') . '>')
           . (count(fp_entity_options()) > 1 ? inc_field('Entity', inc_select('entity_id', fp_entity_options(), $x['entity_id'] ?? 1)) : '') . '</div>'
           . ($x ? '<input type="hidden" name="kind" value="' . e($x['kind']) . '"><label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"' . ((int) $x['active'] === 1 ? ' checked' : '') . '> Active</label>' : '<p class="muted small">Its own ledger account is created under Cash and bank.</p>')
           . '<div class="inc-formfoot"><button class="btn btn-primary">Save</button> <a class="btn" href="' . e(fp_url('banks')) . '">Cancel</a></div></form></section>';
    }
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Account</th><th>Bank</th><th class="num">Ledger balance</th><th class="num">To reconcile</th><th>Last statement</th><th></th></tr></thead><tbody>';
    foreach (fp_all('SELECT * FROM fp_bank_accounts ORDER BY active DESC, kind, name') as $b) {
        $bal = 0.0;
        foreach ($pos as $pp) {
            if ((int) $pp['id'] === (int) $b['id']) {
                $bal = $pp['balance'];
            }
        }
        $un = (int) fp_val('SELECT COUNT(*) FROM fp_bank_lines WHERE bank_account_id = ? AND status = "unmatched"', 'i', [(int) $b['id']]);
        $last = fp_one('SELECT MAX(txn_date) d FROM fp_bank_lines WHERE bank_account_id = ?', 'i', [(int) $b['id']])['d'] ?? null;
        echo '<tr' . ((int) $b['active'] !== 1 ? ' class="muted"' : '') . '><td data-l="Account"><a href="' . e(fp_url('banks', ['bank' => $b['id']])) . '"><strong>' . e($b['name']) . '</strong></a> <span class="muted small">' . e($b['kind']) . '</span></td>'
           . '<td data-l="Bank" class="small">' . e(trim(($b['bank'] ?? '') . ' ' . ($b['account_no'] ? fp_mask($b['account_no']) : ''))) . '</td>' . fp_money_td($b['kind'] === 'card' ? -$bal : $bal, 'Balance')
           . '<td class="num" data-l="To reconcile">' . ($un ? '<span class="late">' . $un . '</span>' : '—') . '</td><td data-l="Last statement">' . e(fp_fmt_date($last)) . '</td>'
           . '<td>' . (fp_can('bank.manage') ? '<a class="btn btn-sm" href="' . e(fp_url('banks', ['edit' => $b['id']])) . '">Edit</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div><p class="muted small">A card account shows what is owed on the card.</p>';
    fp_layout_end();
    return;
}

$asOf = fp_safe_date($_GET['as_of'] ?? '') ?? date('Y-m-d');
$rec = fp_bank_rec($bankId, $asOf);
$acts = fp_can('bank.reconcile') ? inc_button('Match automatically', ['action' => 'automatch'], 'btn') : '';
fp_layout_start($bank['name'], 'Reconciliation at ' . fp_fmt_date($asOf), $acts, ['Bank & cash' => fp_url('banks'), $bank['name'] => null], $flash);
echo '<div class="inc-kpis">' . fp_kpi('Ledger balance', fp_money($rec['book'], false), fp_url('ledger', ['account' => $bank['account_id'], 'to' => $asOf]))
   . fp_kpi('Statement balance', $rec['statement'] !== null ? fp_money($rec['statement'], false) : '—', '', $rec['statement_date'] ? 'Last line ' . fp_fmt_date($rec['statement_date']) : 'No balance on the statement')
   . fp_kpi('In the books, not on the statement', fp_money($rec['not_in_bank_in'] - $rec['not_in_bank_out'], false), '', fp_money($rec['not_in_bank_in'], false) . ' in · ' . fp_money($rec['not_in_bank_out'], false) . ' out')
   . fp_kpi('On the statement, not in the books', (string) $rec['not_in_book_n'], '', fp_money($rec['not_in_book_in'], false) . ' in · ' . fp_money($rec['not_in_book_out'], false) . ' out', $rec['not_in_book_n'] ? 'amber' : '')
   . fp_kpi('Unexplained difference', $rec['difference'] === null ? '—' : fp_money($rec['difference'], false), '', $rec['difference'] === null ? 'Import a statement with balances' : '', $rec['difference'] !== null && fp_paise($rec['difference']) !== 0 ? 'red' : 'green')
   . '</div>';
echo '<section class="card"><form method="get" class="inc-row"><input type="hidden" name="p" value="banks"><input type="hidden" name="bank" value="' . $bankId . '">'
   . inc_field('Reconcile as at', '<input type="date" name="as_of" value="' . e($asOf) . '" data-autosubmit>') . '</form>';
if (fp_can('bank.manage') && fp_writable()) {
    echo inc_form_open('', true) . '<input type="hidden" name="action" value="import"><div class="inc-row">'
       . inc_field('Import a statement (CSV from your bank)', '<input type="file" name="statement" accept=".csv,.txt" required>', 'Needs a header row with Date, Description/Narration, Withdrawal/Debit and Deposit/Credit (or a signed Amount); Balance and Reference are used when present. Lines already imported are skipped.')
       . '</div><button class="btn btn-primary">Import</button></form>';
}
echo '</section>';

$lines = fp_all('SELECT * FROM fp_bank_lines WHERE bank_account_id = ? AND status = "unmatched" ORDER BY txn_date, id LIMIT 200', 'i', [$bankId]);
$gl = fp_bank_unmatched_gl($bankId);
echo '<section class="card"><h2>Statement lines to reconcile (' . count($lines) . ')</h2>';
if (!$lines) {
    echo '<p class="inc-allclear">Every imported statement line is matched or explained.</p>';
} else {
    $accts = fp_account_options();
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Date</th><th>Narration</th><th class="num">Out</th><th class="num">In</th><th>Match to the ledger</th><th>Or</th></tr></thead><tbody>';
    foreach ($lines as $s) {
        $isIn = (float) $s['deposit'] > 0;
        $amt = fp_paise($isIn ? $s['deposit'] : $s['withdrawal']);
        $cands = [];
        foreach ($gl as $g) {
            if (fp_paise($isIn ? $g['debit_base'] : $g['credit_base']) === $amt) {
                $cands[(int) $g['id']] = fp_fmt_date($g['jdate']) . ' ' . $g['number'] . ' — ' . mb_strimwidth((string) ($g['description'] ?: $g['jdesc']), 0, 50, '…');
            }
        }
        echo '<tr><td data-l="Date">' . e(fp_fmt_date($s['txn_date'])) . '</td><td data-l="Narration">' . e($s['description']) . ($s['ref'] ? '<div class="muted small">' . e($s['ref']) . '</div>' : '') . '</td>'
           . fp_money_td((float) $s['withdrawal'] ?: null, 'Out') . fp_money_td((float) $s['deposit'] ?: null, 'In') . '<td data-l="Match">';
        if (fp_can('bank.reconcile') && fp_writable()) {
            echo $cands ? inc_form_open('class="inc-inline"') . '<input type="hidden" name="action" value="match"><input type="hidden" name="line" value="' . (int) $s['id'] . '">' . inc_select('gl', $cands, '', '', 'aria-label="Ledger entry"') . ' <button class="btn btn-sm btn-primary">Match</button></form>'
                         : '<span class="muted small">No ledger entry of this amount</span>';
        }
        echo '</td><td data-l="Or">';
        if (fp_can('bank.reconcile') && fp_writable()) {
            echo '<details><summary class="btn btn-sm">Post it</summary>' . inc_form_open() . '<input type="hidden" name="action" value="entry"><input type="hidden" name="line" value="' . (int) $s['id'] . '">'
               . inc_select('account_id', $accts, $isIn ? fp_acct('interest_income') : fp_acct('bank_charges'), '', 'aria-label="Account"') . '<input name="desc" maxlength="255" value="' . e($s['description']) . '"><button class="btn btn-sm">Post and match</button></form></details>'
               . '<details><summary class="btn btn-sm">Ignore</summary>' . inc_form_open() . '<input type="hidden" name="action" value="ignore"><input type="hidden" name="line" value="' . (int) $s['id'] . '">'
               . '<input name="reason" maxlength="255" required placeholder="Why"><button class="btn btn-sm">Ignore</button></form></details>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

if ($rec['gl_open']) {
    echo '<section class="card"><details><summary><strong>In the books, not yet on a statement (' . count($rec['gl_open']) . ')</strong></summary><table class="data inc-table stack"><thead><tr><th>Date</th><th>Journal</th><th>Description</th><th class="num">In</th><th class="num">Out</th></tr></thead><tbody>';
    foreach (array_slice($rec['gl_open'], 0, 200) as $g) {
        echo '<tr><td>' . e(fp_fmt_date($g['jdate'])) . '</td><td>' . e($g['number']) . '</td><td>' . e($g['description'] ?: $g['jdesc']) . '</td>' . fp_money_td((float) $g['debit_base'] ?: null) . fp_money_td((float) $g['credit_base'] ?: null) . '</tr>';
    }
    echo '</tbody></table></details></section>';
}
$done = fp_all('SELECT s.*, j.number FROM fp_bank_lines s LEFT JOIN fp_journal_lines l ON l.id = s.matched_line_id LEFT JOIN fp_journals j ON j.id = l.journal_id
                 WHERE s.bank_account_id = ? AND s.status <> "unmatched" ORDER BY s.matched_at DESC LIMIT 50', 'i', [$bankId]);
if ($done) {
    echo '<section class="card"><details><summary><strong>Recently matched</strong></summary><table class="data inc-table stack"><tbody>';
    foreach ($done as $s) {
        echo '<tr><td>' . e(fp_fmt_date($s['txn_date'])) . '</td><td>' . e($s['description']) . '</td>' . fp_money_td((float) $s['deposit'] ?: -(float) $s['withdrawal']) . '<td>'
           . ($s['status'] === 'matched' ? 'Matched to ' . e($s['number'] ?? '') : 'Ignored') . ' <span class="muted small">' . e($s['matched_by']) . '</span></td><td>'
           . (fp_can('bank.reconcile') && fp_writable() ? inc_button('Undo', ['action' => 'unmatch', 'line' => $s['id']], 'btn btn-sm') : '') . '</td></tr>';
    }
    echo '</tbody></table></details></section>';
}
fp_layout_end();
