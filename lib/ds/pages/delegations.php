<?php
/**
 * Delegations: while a signatory is away, their new requests go to a
 * delegate — only if their profile allows delegating. The delegate signs
 * in their own name, "on behalf of"; nobody ever signs as someone else.
 * Signatories manage their own; Delegations managers manage everyone's.
 */

$all = ds_can('delegations.manage');
$me = ds_me();
$mayOwn = (int) (ds_profile($me)['may_delegate'] ?? 0) === 1;
if (!$all && !$mayOwn) {
    fabo_deny('Your profile does not allow delegating, and you do not manage delegations.', 403);
}
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ds_action(static function () use ($all, $me) {
        if (($_POST['action'] ?? '') === 'revoke') {
            $d = fabo_one('SELECT * FROM ds_delegations WHERE id = ? AND org_id = ?', 'ii', [(int) ($_POST['id'] ?? 0), ds_org()]);
            if (!$d || (!$all && (int) $d['from_person_id'] !== $me)) {
                throw new InvalidArgumentException('You cannot change that delegation.');
            }
            fabo_exec('UPDATE ds_delegations SET status = "revoked", revoked_at = NOW() WHERE id = ?', 'i', [(int) $d['id']]);
            ds_audit(null, 'delegation', (int) $d['id'], 'delegation_revoke', 'Ended the delegation from ' . ds_person_name((int) $d['from_person_id']) . ' to ' . ds_person_name((int) $d['to_person_id']));
            return 'Delegation ended.';
        }
        $from = $all ? (int) ($_POST['from_person_id'] ?? 0) : $me;
        $to = (int) ($_POST['to_person_id'] ?? 0);
        if (!ds_is_member($from) || !ds_is_member($to) || $from === $to || $to === 0) {
            throw new InvalidArgumentException('Choose two different people in this organisation.');
        }
        if ((int) (ds_profile($from)['may_delegate'] ?? 0) !== 1) {
            throw new InvalidArgumentException(ds_person_name($from) . '\'s signatory profile does not allow delegation. Allow it in the Signatory directory first.');
        }
        $s = ds_date($_POST['starts_on'] ?? '', true, 'Start date');
        $e = ds_date($_POST['ends_on'] ?? '', true, 'End date');
        if ($e < $s || $e < date('Y-m-d')) {
            throw new InvalidArgumentException('The end date must be after the start, and not in the past.');
        }
        if ((strtotime($e) - strtotime($s)) / 86400 > 180) {
            throw new InvalidArgumentException('A delegation can last at most 180 days.');
        }
        $cat = (int) ($_POST['category_id'] ?? 0) ?: null;
        $id = fabo_exec('INSERT INTO ds_delegations (org_id, from_person_id, to_person_id, starts_on, ends_on, category_id, reason, created_by) VALUES (?,?,?,?,?,?,?,?)', 'iiississ',
                        [ds_org(), $from, $to, $s, $e, $cat, ds_str($_POST['reason'] ?? '', 255, true, 'A reason'), ds_actor()]);
        ds_audit(null, 'delegation', $id, 'delegation_add', 'Delegated ' . ds_person_name($from) . '\'s signing to ' . ds_person_name($to) . ' from ' . ds_fmt_date($s) . ' to ' . ds_fmt_date($e) . ($cat ? ' (' . ds_category_name($cat) . ' only)' : ''));
        ds_notify([$to], 'delegation', 'You will sign on behalf of ' . ds_person_name($from), 'From ' . ds_fmt_date($s) . ' to ' . ds_fmt_date($e) . ', new requests for ' . ds_person_name($from) . ' come to you. You sign in your own name.', null, ['p' => 'inbox']);
        return 'Delegation set up.';
    }, ds_url('delegations'));
}
$rows = $all ? fabo_all('SELECT * FROM ds_delegations WHERE org_id = ? ORDER BY status, ends_on DESC LIMIT 200', 'i', [ds_org()])
             : fabo_all('SELECT * FROM ds_delegations WHERE org_id = ? AND (from_person_id = ? OR to_person_id = ?) ORDER BY ends_on DESC', 'iii', [ds_org(), $me, $me]);
ds_layout_start('Delegations', 'Requests that arrive during a delegation go to the delegate, marked "on behalf of". Requests already pending stay where they are.', '', $all ? ['Administration' => ds_url('admin'), 'Delegations' => null] : [], $flash);
echo '<div class="ds-grid g-2-1"><div class="ds-card ds-rise"><div class="ds-tablewrap"><table class="ds-table"><thead><tr><th>From</th><th>To</th><th>Period</th><th>Scope</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $d) {
    $live = $d['status'] === 'active' && $d['ends_on'] >= date('Y-m-d');
    echo '<tr><td><span class="ds-who">' . ds_avatar((int) $d['from_person_id'], 'sm') . e(ds_person_name((int) $d['from_person_id'])) . '</span></td><td><span class="ds-who">' . ds_avatar((int) $d['to_person_id'], 'sm') . e(ds_person_name((int) $d['to_person_id'])) . '</span></td>'
       . '<td>' . e(ds_fmt_date($d['starts_on']) . ' – ' . ds_fmt_date($d['ends_on'])) . '</td><td>' . e($d['category_id'] ? ds_category_name((int) $d['category_id']) : 'All documents') . '</td><td>' . e((string) $d['reason']) . '</td>'
       . '<td>' . ($live ? ($d['starts_on'] <= date('Y-m-d') ? ds_badge('In effect', 'green') : ds_badge('Scheduled', 'blue')) : ds_badge($d['status'] === 'revoked' ? 'Ended' : 'Expired', 'grey')) . '</td>'
       . '<td>' . ($live ? ds_button('End', ['action' => 'revoke', 'id' => $d['id']], 'ds-btn ds-btn-ghost ds-btn-sm', 'End this delegation now?') : '') . '</td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="7">' . ds_empty('No delegations', '', 'swap') . '</td></tr>';
}
echo '</tbody></table></div></div>' . ds_card_start('New delegation', '', '', 'swap') . ds_form() . '<input type="hidden" name="action" value="add">'
   . ($all ? ds_field('Signatory away', ds_select('from_person_id', ds_people(), '', 'Choose…', 'required'), '', true) : '')
   . ds_field('Delegate', ds_select('to_person_id', ds_people(), '', 'Choose…', 'required'), '', true)
   . '<div class="ds-row2">' . ds_field('From', '<input type="date" name="starts_on" required value="' . date('Y-m-d') . '">', '', true) . ds_field('To', '<input type="date" name="ends_on" required>', '', true) . '</div>'
   . ds_field('Only for', ds_select('category_id', ds_categories(), '', 'All documents')) . ds_field('Reason', '<input name="reason" required maxlength="255" placeholder="e.g. On leave">', '', true)
   . '<button class="ds-btn ds-btn-primary ds-btn-block">Set up delegation</button></form>' . ds_card_end() . '</div>';
ds_layout_end();
