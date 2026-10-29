<?php
/**
 * One journal entry: prepared and edited while a draft, submitted for
 * approval (debits must equal credits), posted on approval. A posted one is
 * read-only for ever; it is corrected by a reversal prepared here, or made
 * to repeat.
 */

$id = (int) ($_GET['id'] ?? 0);
$j = $id ? fp_journal($id) : null;
if ($id && !$j) {
    fabo_deny('That journal does not exist.', 404);
}
$back = $j ? fp_url('journal', ['id' => $id]) : fp_url('journals');
$editable = (!$j || ($j['status'] === 'draft' && in_array($j['source'], [null, 'manual'], true))) && fp_can('gl.prepare');
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if ($j && ($r = fp_wf_post('journal', $id, $back))) {
        $flash = $r;
    } elseif ($j && ($r = fp_files_post('journal', $id, $back, fp_can('gl.prepare')))) {
        $flash = $r;
    } elseif ($a === 'save' || $a === 'save_submit') {
        $flash = fp_action(static function () use ($j, $a) {
            if (!$j) {
                fp_nonce_check();
            }
            $nid = fp_journal_save($j ? (int) $j['id'] : null, $_POST);
            fp_attach_many('journal', $nid);
            if ($a === 'save_submit') {
                fp_submit_or_draft(static fn() => fp_journal_submit($nid, fp_journal($nid)['version']), fp_url('journal', ['id' => $nid]));
            }
            inc_redirect(fp_url('journal', ['id' => $nid]), ['ok', 'Draft saved.']);
        }, $back);
    } elseif ($j && $a === 'submit') {
        $flash = fp_action(static fn() => fp_journal_submit($id, $_POST['version'] ?? 0), $back);
    } elseif ($j && $a === 'cancel') {
        $flash = fp_action(static function () use ($id) {
            fp_journal_cancel($id, $_POST['version'] ?? 0);
            return 'Journal cancelled.';
        }, $back);
    } elseif ($j && $a === 'reverse') {
        $flash = fp_action(static function () use ($id) {
            $nid = fp_journal_reverse_draft($id, (string) ($_POST['rdate'] ?? ''), (string) ($_POST['reason'] ?? ''));
            inc_redirect(fp_url('journal', ['id' => $nid]), ['ok', 'Reversal prepared as a draft. Check it and submit it for approval.']);
        }, $back);
    } elseif ($j && $a === 'recurring') {
        $flash = fp_action(static function () use ($id) {
            fp_recurring_add($id, (string) ($_POST['frequency'] ?? ''), (string) ($_POST['next_date'] ?? ''), $_POST['end_date'] ?? null);
            return 'This journal will now be posted again on schedule.';
        }, $back);
    } elseif ($j && $a === 'stop_recurring') {
        $flash = fp_action(static function () use ($id) {
            fp_need('gl.prepare');
            fp_exec('UPDATE fp_recurring SET active = 0 WHERE id = ? AND journal_id = ?', 'ii', [(int) ($_POST['rid'] ?? 0), $id]);
            fp_audit('journal', $id, 'recurring_stop', 'Recurring schedule stopped');
            return 'Recurring schedule stopped.';
        }, $back);
    }
}

$sourceLink = static function (array $j): string {
    $map = ['bill' => 'bill', 'invoice' => 'invoice', 'receipt' => 'receipt', 'payment' => 'payment', 'claim' => 'claim', 'asset' => 'asset', 'depreciation' => 'assets',
            'recurring' => null, 'ic' => 'journal'];
    if (!$j['source'] || $j['source'] === 'manual') {
        return '';
    }
    $page = $map[$j['source']] ?? null;
    if ($j['source'] === 'recurring') {
        $r = fp_one('SELECT journal_id FROM fp_recurring WHERE id = ?', 'i', [(int) $j['source_id']]);
        return $r ? '<a href="' . e(fp_url('journal', ['id' => $r['journal_id']])) . '">Recurring from the template</a>' : 'Recurring';
    }
    return $page ? '<a href="' . e(fp_url($page, ['id' => $j['source_id']])) . '">' . e(ucfirst($j['source'])) . ' #' . (int) $j['source_id'] . '</a>' : e(ucfirst(str_replace('_', ' ', $j['source'])));
};

if ($editable) {
    $v = $j ?? ['jdate' => date('Y-m-d'), 'type' => 'manual', 'entity_id' => 1, 'currency' => fp_setting('base_currency'), 'fx_rate' => 1, 'description' => '', 'reference' => ''];
    $lines = $j ? array_map(static fn($l) => $l, fp_journal_lines($id)) : [];
    if ($flash && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = $_POST + $v;
        $lines = array_values((array) ($_POST['lines'] ?? []));
    }
    fp_layout_start($j ? 'Edit draft journal' : 'New journal entry', 'Debits must equal credits before it can be submitted. It is posted once approved.', '',
                    ['Journal entries' => fp_url('journals'), $j ? 'Draft #' . $id : 'New' => null], $flash);
    if ($j && $j['return_note']) {
        echo '<div class="inc-warn"><strong>Returned for correction:</strong> ' . e($j['return_note']) . '</div>';
    }
    $types = array_intersect_key(fp_journal_types(), array_flip(['manual', 'accrual', 'prepayment', 'adjustment', 'opening', 'intercompany'] + ($v['type'] === 'reversal' ? [6 => 'reversal'] : [])));
    echo '<section class="card">' . inc_form_open('data-fp-form', true) . ($j ? '<input type="hidden" name="version" value="' . (int) $j['version'] . '">' : fp_nonce_field())
       . (!empty($v['reversal_of']) ? '<input type="hidden" name="reversal_of" value="' . (int) $v['reversal_of'] . '">' : '')
       . '<div class="inc-row">' . inc_field('Accounting date', '<input type="date" name="jdate" value="' . e($v['jdate']) . '" required>', '', true)
       . inc_field('Type', inc_select('type', $types, $v['type'], '', 'data-fp-jtype'))
       . (count(fp_entity_options()) > 1 ? inc_field('Entity', inc_select('entity_id', fp_entity_options(), $v['entity_id'])) : '<input type="hidden" name="entity_id" value="' . (int) $v['entity_id'] . '">')
       . inc_field('Reference', '<input name="reference" maxlength="120" value="' . e($v['reference'] ?? '') . '" placeholder="Voucher or document no.">')
       . inc_field('Currency', '<input name="currency" maxlength="3" size="4" value="' . e($v['currency']) . '">')
       . inc_field('Rate to ' . fp_setting('base_currency'), '<input name="fx_rate" inputmode="decimal" size="8" value="' . e((string) (float) $v['fx_rate']) . '">', 'Only for foreign currency') . '</div>'
       . inc_field('Description', '<input name="description" maxlength="500" required value="' . e($v['description']) . '">', '', true)
       . '<div class="inc-row" data-fp-show="accrual">' . inc_field('Reverse automatically on', '<input type="date" name="auto_reverse_on" value="' . e($v['auto_reverse_on'] ?? '') . '">',
                                                                   'An accrual is reversed on this date, usually the first day of next month.') . '</div>'
       . (count(fp_entity_options()) > 1 ? '<div class="inc-row" data-fp-show="intercompany">' . inc_field('Counterpart entity', inc_select('ic_entity_id', fp_entity_options(), $v['ic_entity_id'] ?? '', 'Choose…'))
         . inc_field('Counterpart account (in the other entity)', inc_select('ic_account_id', fp_account_options(), $v['ic_account_id'] ?? '', 'Choose…'), 'Posted there against its intercompany payable or receivable.') . '</div>' : '')
       . fp_journal_editor($lines)
       . inc_field('Supporting documents', '<input type="file" name="files[]" multiple>')
       . '<div class="inc-formfoot"><button class="btn" name="action" value="save">Save draft</button> <button class="btn btn-primary" name="action" value="save_submit">Save and submit for approval</button> '
       . '<a class="btn" href="' . e($back) . '">Cancel</a></div></form></section>';
    if ($j) {
        echo '<div class="inc-row">' . inc_button('Cancel this draft', ['action' => 'cancel', 'version' => $j['version']], 'btn btn-danger btn-sm', 'Cancel this draft journal?') . '</div>';
        echo fp_audit_panel('journal', $id);
    }
    fp_layout_end();
    return;
}

if (!$j) {
    fabo_deny('You cannot prepare journals.', 403);
}
$lines = fp_journal_lines($id);
[$dr, $cr] = fp_journal_totals($id);
$acts = '';
if ($j['status'] === 'draft' && fp_can('gl.prepare') && $j['source'] === 'manual') {
    $acts .= inc_button('Submit for approval', ['action' => 'submit', 'version' => $j['version']], 'btn btn-primary');
}
fp_layout_start(($j['number'] ?: 'Draft journal #' . $id), e($j['description']), $acts, ['Journal entries' => fp_url('journals'), $j['number'] ?: 'Draft' => null], $flash);
if (in_array($j['status'], ['rejected'], true) && $j['return_note']) {
    echo '<div class="inc-warn"><strong>Rejected:</strong> ' . e($j['return_note']) . '</div>';
}
$ent = fp_one('SELECT * FROM fp_entities WHERE id = ?', 'i', [(int) $j['entity_id']]);
echo '<section class="card">' . fp_facts([
    'Status' => fp_badge(fp_journal_statuses(), $j['status']) . ($j['reversed_by'] ? ' ' . inc_badge('Reversed') . ' by <a href="' . e(fp_url('journal', ['id' => $j['reversed_by']])) . '">journal #' . (int) $j['reversed_by'] . '</a>' : ''),
    'Accounting date' => e(fp_fmt_date($j['jdate'])), 'Type' => e(fp_journal_types()[$j['type']] ?? $j['type']), 'Entity' => e($ent['code'] . ' · ' . $ent['name']),
    'Reference' => e($j['reference'] ?? ''), 'Source' => $sourceLink($j),
    'Reverses' => $j['reversal_of'] ? '<a href="' . e(fp_url('journal', ['id' => $j['reversal_of']])) . '">journal #' . (int) $j['reversal_of'] . '</a>' : '',
    'Intercompany with' => $j['ic_entity_id'] ? e(fp_one('SELECT name FROM fp_entities WHERE id = ?', 'i', [(int) $j['ic_entity_id']])['name'] ?? '') . ($j['ic_pair_id'] ? ' — <a href="' . e(fp_url('journal', ['id' => $j['ic_pair_id']])) . '">its entry</a>' : '') : '',
    'Currency' => $j['currency'] !== fp_setting('base_currency') ? e($j['currency'] . ' at ' . (float) $j['fx_rate']) : '',
    'Reverses automatically' => $j['auto_reverse_on'] ? e(fp_fmt_date($j['auto_reverse_on'])) : '',
    'Prepared by' => e($j['prepared_by'] . ' · ' . fp_fmt_dt($j['created_at'])), 'Approved by' => e($j['approved_by'] ?? ''),
    'Posted' => $j['posted_at'] ? e($j['posted_by'] . ' · ' . fp_fmt_dt($j['posted_at'])) : '',
]) . '</section>';

echo '<section class="card"><h2>Lines</h2><div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Account</th><th>Description</th><th>Cost centre</th><th>Project</th><th>Party</th>'
   . '<th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
foreach ($lines as $l) {
    $party = $l['party_type'] ? ($l['party_type'] === 'vendor' ? (fp_vendor((int) $l['party_id'])['name'] ?? '') : ($l['party_type'] === 'customer' ? (fp_customer((int) $l['party_id'])['name'] ?? '') : fp_person_name((int) $l['party_id']))) : '';
    echo '<tr><td data-l="Account"><a href="' . e(fp_url('ledger', ['account' => $l['account_id']])) . '">' . e($l['code'] . ' ' . $l['account_name']) . '</a></td><td data-l="Description">' . e($l['description'] ?? '') . '</td>'
       . '<td data-l="Cost centre">' . e($l['cc_code'] ?? '') . '</td><td data-l="Project">' . e($l['project_code'] ?? '') . '</td><td data-l="Party">' . e($party) . '</td>'
       . fp_money_td((float) $l['debit_base'] ?: null, 'Debit') . fp_money_td((float) $l['credit_base'] ?: null, 'Credit') . '</tr>';
}
echo '<tr class="fp-total"><td colspan="5">Totals</td>' . fp_money_td($dr / 100) . fp_money_td($cr / 100) . '</tr></tbody></table></div>';
if ($dr !== $cr) {
    echo '<div class="inc-warn">Out of balance by ' . e(fp_money(abs($dr - $cr) / 100)) . '. It cannot be submitted until debits equal credits.</div>';
}
echo '</section>';

echo fp_wf_panel('journal', $j, $back);

if ($j['status'] === 'posted' && fp_can('gl.prepare') && in_array($j['source'], ['manual', 'recurring'], true) && !$j['reversed_by'] && fp_writable()) {
    echo '<div class="inc-grid2"><section class="card"><h2>Reverse this entry</h2><p class="muted small">A posted journal is never changed. A reversal posts the same lines the other way round, after approval.</p>'
       . inc_form_open() . '<input type="hidden" name="action" value="reverse"><div class="inc-row">'
       . inc_field('Reversal date', '<input type="date" name="rdate" value="' . e(max(date('Y-m-d'), (string) $j['jdate'])) . '" required>', '', true)
       . inc_field('Reason', '<input name="reason" maxlength="400" required>', '', true, 'grow') . '</div><button class="btn">Prepare reversal</button></form></section>';
    $rec = fp_all('SELECT * FROM fp_recurring WHERE journal_id = ? AND active = 1', 'i', [$id]);
    echo '<section class="card"><h2>Repeat this entry</h2>';
    foreach ($rec as $r) {
        echo '<p>Repeats ' . e($r['frequency']) . '; next on <strong>' . e(fp_fmt_date($r['next_date'])) . '</strong>' . ($r['end_date'] ? ' until ' . e(fp_fmt_date($r['end_date'])) : '') . ' · ' . (int) $r['runs'] . ' run(s) so far. '
           . inc_button('Stop', ['action' => 'stop_recurring', 'rid' => $r['id']], 'btn btn-sm', 'Stop repeating this journal?') . '</p>';
    }
    if (!$rec && $j['source'] === 'manual') {
        echo '<p class="muted small">Rent, amortisation of a prepayment, a monthly accrual: each run is posted automatically from this approved template.</p>'
           . inc_form_open() . '<input type="hidden" name="action" value="recurring"><div class="inc-row">'
           . inc_field('Every', inc_select('frequency', ['monthly' => 'Month', 'quarterly' => 'Quarter', 'yearly' => 'Year'], 'monthly'))
           . inc_field('First run', '<input type="date" name="next_date" value="' . e(date('Y-m-d', strtotime($j['jdate'] . ' +1 month'))) . '" required>', '', true)
           . inc_field('Last run (optional)', '<input type="date" name="end_date">') . '</div><button class="btn">Make recurring</button></form>';
    }
    echo '</section></div>';
}
echo fp_files_panel('journal', $j, fp_can('gl.prepare'), $back);
echo fp_audit_panel('journal', $id);
fp_layout_end();
