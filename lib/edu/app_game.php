<?php
/**
 * Academic desk — Rewards and Store (Phase 3). Loaded by lib/edu/app.php.
 *
 *   rewards  totals, institutions ranked, top students, the earning rules and settings
 *   store    the items students spend coins on, and the orders waiting for staff
 */

require_once __DIR__ . '/game.php';

function edu_post_game(int $org, string $a): void
{
    $id = (int) ($_POST['id'] ?? 0);
    switch ($a) {
        case 'game-settings':
            edu_game_rules_save($org, (array) ($_POST['rule'] ?? []));
            edu_set($org, 'game_on', !empty($_POST['game_on']) ? '1' : '0');
            edu_set($org, 'game_coin_cap', (string) max(0, min(10000, (int) ($_POST['coin_cap'] ?? 60))));
            edu_set($org, 'game_kudos_cap', (string) max(0, min(200, (int) ($_POST['kudos_cap'] ?? 10))));
            edu_set($org, 'game_names', ($_POST['names'] ?? '') === 'full' ? 'full' : 'initial');
            edu_audit($org, edu_actor(), 'game-settings', '');
            edu_flash('ok', 'Saved. New amounts apply from now on; what students already earned stays.');
            edu_go('rewards', ['tab' => 'settings']);
        case 'game-backfill':
            $n = edu_game_backfill($org);
            edu_audit($org, edu_actor(), 'game-backfill', (string) $n);
            edu_flash('ok', $n . ' earlier achievement' . ($n === 1 ? '' : 's') . ' paid out. Running it again pays nothing twice.');
            edu_go('rewards', ['tab' => 'settings']);
        case 'adjust':
            edu_adjust($org, $id, (string) ($_POST['currency'] ?? 'coin'), (int) ($_POST['amount'] ?? 0), (string) ($_POST['reason'] ?? ''), edu_actor());
            edu_audit($org, edu_actor(), 'adjust', $id . ' ' . $_POST['amount'] . ' ' . $_POST['currency']);
            edu_flash('ok', 'Adjusted. It shows in their history with your reason.');
            edu_go('person', ['id' => $id]);
        case 'item-save':
            $iid = edu_store_item_save($org, $_POST, $id ?: null);
            edu_audit($org, edu_actor(), $id ? 'item-edit' : 'item-add', (string) $_POST['title']);
            edu_flash('ok', $id ? 'Saved.' : 'Item added' . (!empty($_POST['active']) ? ' and on the shelf.' : '. Switch it on when it is ready.'));
            edu_go('store', ['item' => $iid]);
        case 'store-starter':
            $n = edu_store_starter($org);
            edu_flash('ok', $n . ' starter items added, switched off. Check prices and stock, then switch on the ones you will honour.');
            edu_go('store');
        case 'order':
            $st = (string) ($_POST['status'] ?? '');
            edu_order_decide($org, $id, $st, edu_actor(), (string) ($_POST['staff_note'] ?? ''));
            edu_audit($org, edu_actor(), 'order-' . $st, (string) $id);
            edu_flash('ok', $st === 'fulfilled' ? 'Marked as given.' : 'Rejected: the coins are back with the student.');
            edu_go('store');
    }
}

function edu_page_rewards(int $org): void
{
    $tab = (string) ($_GET['tab'] ?? 'board');
    $tab = in_array($tab, ['board', 'settings'], true) ? $tab : 'board';
    $period = in_array($_GET['period'] ?? '', ['week', 'month', 'all'], true) ? $_GET['period'] : 'month';
    $t = edu_game_totals($org);
    if (!edu_game_on($org)) {
        echo '<div class="edu-flash info">Rewards are switched off: students earn nothing and do not see Rewards, Rankings or the Store. Switch them on under Settings.</div>';
    }
    echo '<section class="edu-kpis">' . edu_kpi('XP earned', $t['xp'], 't-student') . edu_kpi('Coins earned', $t['coins_in'], 't-acc')
       . edu_kpi('Coins spent', $t['coins_spent'] - $t['coins_refunded'], 't-mentor') . edu_kpi('Coins held by students', $t['coins_held'], 't-inst')
       . edu_kpi('Badges earned', $t['badges'], 't-faculty') . edu_kpi('Store orders to handle', $t['pending'], 't-recent', edu_url('store')) . '</section>'
       . '<nav class="edu-tabs"><a class="' . ($tab === 'board' ? 'on' : '') . '" href="' . e(edu_url('rewards')) . '">Rankings</a>'
       . '<a class="' . ($tab === 'settings' ? 'on' : '') . '" href="' . e(edu_url('rewards', ['tab' => 'settings'])) . '">Earning rules &amp; settings</a></nav>';
    if ($tab === 'settings') {
        edu_rewards_settings($org);
        return;
    }
    echo '<nav class="edu-tabs small">';
    foreach (['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $k => $l) {
        echo '<a class="' . ($period === $k ? 'on' : '') . '" href="' . e(edu_url('rewards', ['period' => $k])) . '">' . $l . '</a>';
    }
    echo '</nav><div class="edu-split even"><section class="edu-card"><h2>Institutions</h2><p class="muted">Ranked by average XP per student, so a small college can lead a large one.</p>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th class="n">#</th><th>Institution</th><th class="n">Avg XP</th><th>Students active</th></tr></thead><tbody>';
    $ib = edu_institution_board($org, $period);
    $max = max(1, ...array_map(static fn($r) => (float) $r['avg'], $ib ?: [['avg' => 1]]));
    foreach ($ib as $i => $r) {
        echo '<tr><td class="n">' . ($i + 1) . '</td><td>' . e($r['name']) . '<span class="edu-bar"><i style="width:' . round($r['avg'] / $max * 100) . '%"></i></span></td>'
           . '<td class="n">' . e((string) $r['avg']) . '</td><td>' . (int) $r['active'] . ' / ' . (int) $r['students'] . ' <span class="muted">(' . (int) $r['active_pct'] . '%)</span></td></tr>';
    }
    if (!$ib) {
        echo '<tr><td colspan="4" class="edu-empty">No institutions with students yet.</td></tr>';
    }
    echo '</tbody></table></div></section><section class="edu-card"><h2>Top students</h2><p class="muted">Full names here; students see them the way Settings says.</p><ol class="edu-board">';
    $lb = edu_leaderboard($org, $period, [], 20);
    foreach ($lb as $r) {
        echo '<li><b>' . (int) $r['rank'] . '</b><a href="' . e(edu_url('person', ['id' => (int) $r['person_id']])) . '">' . e($r['name']) . '</a><span class="muted">' . e((string) $r['inst_name']) . '</span><strong>' . fabo_thousands($r['xp']) . ' XP</strong></li>';
    }
    if (!$lb) {
        echo '<li class="edu-empty">Nobody has earned XP in this period yet.</li>';
    }
    echo '</ol></section></div>';
}

function edu_rewards_settings(int $org): void
{
    $edit = edu_can_edit();
    $dis = $edit ? '' : ' disabled';
    echo '<form method="post" class="edu-card edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="game-settings">'
       . '<h2>Earning rules</h2><p class="muted">XP is earned and never spent: it decides levels, badges and rankings. Coins are earned and spent in the Store. '
       . 'Coins can never be bought, sold, moved between students or turned into money.</p>'
       . '<label class="edu-check"><input type="checkbox" name="game_on" value="1"' . (edu_game_on($org) ? ' checked' : '') . $dis . '> Rewards are on (students earn, and see Rewards, Rankings and the Store)</label>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When a student…</th><th class="n">XP</th><th class="n">Coins</th></tr></thead><tbody>';
    foreach (edu_game_rules($org) as $k => $r) {
        echo '<tr><td>' . e($r['label']) . '</td><td class="n"><input class="edu-num" type="number" min="0" max="1000" name="rule[' . $k . '][xp]" value="' . $r['xp'] . '"' . $dis . ' aria-label="XP for ' . e($r['label']) . '"></td>'
           . '<td class="n"><input class="edu-num" type="number" min="0" max="500" name="rule[' . $k . '][coin]" value="' . $r['coin'] . '"' . $dis . ' aria-label="Coins for ' . e($r['label']) . '"></td></tr>';
    }
    echo '</tbody></table></div><div class="edu-fields">'
       . '<label class="field"><span>Most coins a student can earn in a day</span><input type="number" name="coin_cap" min="0" max="10000" value="' . edu_game_daily_cap($org) . '"' . $dis . '></label>'
       . '<label class="field"><span>Kudos each faculty member or mentor may give a month</span><input type="number" name="kudos_cap" min="0" max="200" value="' . edu_game_kudos_cap($org) . '"' . $dis . '></label>'
       . '<label class="field"><span>Names on student rankings</span><select name="names"' . $dis . '><option value="initial">First name and initial (Ananya R.) — recommended</option><option value="full"'
       . (edu_game_names($org) === 'full' ? ' selected' : '') . '>Full names</option></select></label></div>'
       . '<p class="muted">Any student can also hide themselves from rankings in their own Rewards page.</p>' . ($edit ? '<button class="btn btn-primary">Save</button>' : '') . '</form>';
    echo '<section class="edu-card"><h2>Levels and badges</h2><div class="edu-levels">';
    foreach (edu_level_names() as $i => $n) {
        echo '<span><b>' . ($i + 1) . '</b>' . e($n) . '<em>' . fabo_thousands(edu_level_floor($i + 1)) . ' XP</em></span>';
    }
    echo '</div><p class="muted">Leagues: Bronze (levels 1–2), Silver (3–4), Gold (5–6), Platinum (7+).</p><div class="edu-badgelist">';
    foreach (edu_badges_all() as [$n, $what, $emoji]) {
        echo '<span><i aria-hidden="true">' . $emoji . '</i><b>' . e($n) . '</b><em>' . e($what) . '</em></span>';
    }
    echo '</div></section>';
    if ($edit) {
        echo '<section class="edu-card"><h2>Pay for earlier achievements</h2><p class="muted">Lessons, quizzes, courses and mentoring sessions finished before rewards existed are paid once, dated when they happened. Safe to run again.'
           . (edu_get($org, 'game_backfilled') !== '' ? ' Last run ' . e(date('d M Y, H:i', strtotime(edu_get($org, 'game_backfilled')))) . '.' : '') . '</p>'
           . '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="game-backfill"><button class="btn">Pay earlier achievements</button></form></section>';
    }
}

function edu_page_store(int $org): void
{
    $edit = edu_can_edit();
    $items = edu_store_items($org);
    $open = (int) ($_GET['item'] ?? 0);
    $orders = edu_orders($org, ['status' => ($_GET['orders'] ?? '') === 'all' ? '' : 'pending']);
    echo '<section class="edu-card"><div class="edu-card-h"><h2>Orders ' . (($_GET['orders'] ?? '') === 'all' ? '' : 'waiting') . ' <span class="muted">(' . count($orders) . ')</span></h2>'
       . '<a class="btn btn-sm" href="' . e(edu_url('store', ($_GET['orders'] ?? '') === 'all' ? [] : ['orders' => 'all'])) . '">' . (($_GET['orders'] ?? '') === 'all' ? 'Only waiting' : 'All orders') . '</a></div>'
       . '<div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>When</th><th>Student</th><th>Item</th><th class="n">Coins</th><th>Their note</th><th>Status</th>' . ($edit ? '<th></th>' : '') . '</tr></thead><tbody>';
    $icons = edu_store_icons();
    foreach ($orders as $o) {
        echo '<tr><td>' . e(date('d M, H:i', strtotime($o['created_at']))) . '</td><td><a href="' . e(edu_url('person', ['id' => (int) $o['person_id']])) . '">' . e($o['name']) . '</a><br><span class="muted">' . e($o['roll_no'] ?: (string) $o['email']) . '</span></td>'
           . '<td>' . ($icons[$o['icon']] ?? '🎁') . ' ' . e($o['title']) . '</td><td class="n">' . (int) $o['price'] . '</td><td>' . e($o['note']) . '</td>'
           . '<td>' . ['pending' => '<span class="pill amber">Waiting</span>', 'fulfilled' => '<span class="pill green">Given</span>', 'rejected' => '<span class="pill red">Rejected</span>',
                       'cancelled' => '<span class="pill grey">Cancelled</span>'][$o['status']] . ($o['staff_note'] !== '' ? '<br><span class="muted">' . e($o['staff_note']) . '</span>' : '') . '</td>';
        if ($edit) {
            echo '<td class="act">' . ($o['status'] === 'pending'
                ? '<form method="post" class="edu-orderform">' . fabo_csrf_field() . '<input type="hidden" name="a" value="order"><input type="hidden" name="id" value="' . (int) $o['id'] . '">'
                  . '<input name="staff_note" maxlength="500" placeholder="Note to the student (optional)" aria-label="Note"><button class="btn btn-sm btn-approve" name="status" value="fulfilled">Given</button>'
                  . '<button class="btn btn-sm btn-reject" name="status" value="rejected" data-confirm-btn="Reject and give the coins back?">Reject</button></form>' : '') . '</td>';
        }
        echo '</tr>';
    }
    if (!$orders) {
        echo '<tr><td colspan="7" class="edu-empty">Nothing waiting.</td></tr>';
    }
    echo '</tbody></table></div></section><section class="edu-card"><div class="edu-card-h"><h2>Items</h2></div><div class="edu-tablewrap"><table class="edu-table"><thead><tr><th>Item</th><th>Kind</th><th class="n">Price</th><th class="n">Stock</th><th class="n">Taken</th><th>For</th><th>On shelf</th></tr></thead><tbody>';
    foreach ($items as $it) {
        echo '<tr><td><a href="' . e(edu_url('store', ['item' => (int) $it['id']])) . '">' . ($icons[$it['icon']] ?? '🎁') . ' <strong>' . e($it['title']) . '</strong></a>' . ($it['sponsor'] !== '' ? '<br><span class="muted">Sponsored by ' . e($it['sponsor']) . '</span>' : '') . '</td>'
           . '<td>' . e(edu_store_kinds()[$it['kind']]) . '</td><td class="n">' . (int) $it['price'] . '</td><td class="n">' . ($it['stock'] === null ? '∞' : (int) $it['stock']) . '</td><td class="n">' . (int) $it['taken'] . '</td>'
           . '<td class="muted">' . e((int) $it['institution_id'] ? (string) (edu_institution($org, (int) $it['institution_id'])['name'] ?? '') : 'Everyone') . '</td>'
           . '<td>' . ((int) $it['active'] ? '<span class="pill green">On</span>' : '<span class="pill grey">Off</span>') . '</td></tr>';
    }
    if (!$items) {
        echo '<tr><td colspan="7" class="edu-empty">The shelf is empty.</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($edit && !$items) {
        echo '<form method="post" class="edu-inline">' . fabo_csrf_field() . '<input type="hidden" name="a" value="store-starter"><button class="btn">Add starter items</button>'
           . '<span class="muted">Mentor slots, hackathon entry, a co-working day, lab time, a profile badge and a T-shirt — added switched off for you to check.</span></form>';
    }
    echo '</section>';
    if ($edit) {
        $it = $open ? edu_store_item($org, $open) : null;
        echo '<details class="edu-card"' . ($it || !$items ? ' open' : '') . ' id="item"><summary><h2>' . ($it ? 'Edit: ' . e($it['title']) : 'Add an item') . '</h2></summary><form method="post" class="edu-form">' . fabo_csrf_field()
           . '<input type="hidden" name="a" value="item-save">' . ($it ? '<input type="hidden" name="id" value="' . (int) $it['id'] . '">' : '') . '<div class="edu-fields">'
           . '<label class="field wide"><span>Name</span><input name="title" required maxlength="160" value="' . e((string) ($it['title'] ?? '')) . '"></label>'
           . '<label class="field wide"><span>What they get, and how</span><textarea name="description" rows="2" maxlength="600">' . e((string) ($it['description'] ?? '')) . '</textarea></label>'
           . '<label class="field"><span>Kind</span><select name="kind">';
        foreach (['digital' => 'Digital (given at once)', 'benefit' => 'Benefit (staff confirm)', 'physical' => 'Physical (staff hand over)'] as $k => $l) {
            echo '<option value="' . $k . '"' . (($it['kind'] ?? 'benefit') === $k ? ' selected' : '') . '>' . $l . '</option>';
        }
        echo '</select></label><label class="field"><span>Price (coins)</span><input type="number" name="price" min="1" max="100000" required value="' . (int) ($it['price'] ?? 100) . '"></label>'
           . '<label class="field"><span>Stock (empty: no limit)</span><input type="number" name="stock" min="0" value="' . e($it && $it['stock'] !== null ? (string) $it['stock'] : '') . '"></label>'
           . '<label class="field"><span>Most per student (0: no limit)</span><input type="number" name="per_person" min="0" max="50" value="' . (int) ($it['per_person'] ?? 1) . '"></label>'
           . '<label class="field"><span>Offered to</span><select name="institution_id"><option value="0">Every institution</option>';
        foreach (edu_institutions($org) as $i) {
            echo '<option value="' . (int) $i['id'] . '"' . ((int) ($it['institution_id'] ?? 0) === (int) $i['id'] ? ' selected' : '') . '>' . e($i['name']) . '</option>';
        }
        echo '</select></label><label class="field"><span>Sponsor (optional)</span><input name="sponsor" maxlength="120" value="' . e((string) ($it['sponsor'] ?? '')) . '"></label>'
           . '<fieldset class="field edu-icons"><span>Icon</span><div>';
        foreach (edu_store_icons() as $k => $emoji) {
            echo '<label><input type="radio" name="icon" value="' . $k . '"' . (($it['icon'] ?? 'gift') === $k ? ' checked' : '') . '><i>' . $emoji . '</i></label>';
        }
        echo '</div></fieldset></div><label class="edu-check"><input type="checkbox" name="active" value="1"' . (!$it || (int) $it['active'] ? ' checked' : '') . '> On the shelf</label>'
           . '<button class="btn btn-primary">' . ($it ? 'Save' : 'Add item') . '</button></form></details>';
    }
}

/** The rewards card on a student's page. */
function edu_person_rewards(int $org, int $pid): void
{
    $xp = edu_balance($org, $pid, 'xp');
    $lv = edu_level($xp);
    $badges = edu_badges_of($org, $pid);
    $all = edu_badges_all();
    echo '<section class="edu-card"><h2>Rewards</h2><dl class="edu-dl"><dt>Level</dt><dd>' . $lv['level'] . ' · ' . e($lv['name']) . ' (' . e(edu_league($lv['level'])['name']) . ' league)</dd>'
       . '<dt>XP</dt><dd>' . fabo_thousands($xp) . '</dd><dt>Coins</dt><dd>' . fabo_thousands(edu_balance($org, $pid, 'coin')) . '</dd><dt>Streak</dt><dd>' . edu_streak($org, $pid) . ' days</dd>'
       . '<dt>Badges</dt><dd>' . ($badges ? implode(' ', array_map(static fn($b) => '<span title="' . e($all[$b][0] ?? $b) . '">' . ($all[$b][2] ?? '') . '</span>', array_keys($badges))) : '—') . '</dd></dl>';
    $h = edu_ledger_lines($org, $pid, 8);
    if ($h) {
        echo '<ul class="edu-ledger">';
        foreach ($h as $l) {
            echo '<li><span>' . e(edu_reason_words($l['reason'])) . ($l['note'] !== '' ? ' <em>' . e($l['note']) . '</em>' : '') . '<small>' . e(fabo_short_date($l['at'])) . '</small></span>' . edu_line_amounts($l) . '</li>';
        }
        echo '</ul>';
    }
    if (edu_can_edit()) {
        echo '<details><summary class="btn btn-sm">Adjust XP or coins</summary><form method="post" class="edu-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="adjust"><input type="hidden" name="id" value="' . $pid . '">'
           . '<div class="edu-fields"><label class="field"><span>Amount (minus to take away)</span><input type="number" name="amount" required></label><label class="field"><span>Of</span><select name="currency"><option value="coin">Coins</option><option value="xp">XP</option></select></label>'
           . '<label class="field wide"><span>Reason (the student sees it)</span><input name="reason" required maxlength="200"></label></div><button class="btn">Adjust</button></form></details>';
    }
    echo '</section>';
}
