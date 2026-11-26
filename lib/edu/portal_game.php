<?php
/**
 * Campus portal — rewards (Phase 3). Loaded by lib/edu/portal.php.
 *
 *   Student   Rewards (level, XP, coins, streak, badges, history, ranking privacy) · Rankings · Store
 *   Faculty / mentor   kudos to their own students
 *   Institution admin  top students of their institution
 */

require_once __DIR__ . '/game.php';

/** Student, faculty and mentor actions. Redirects when it handles one. */
function edu_portal_post_game(int $org, array $me, array $role, string $a): void
{
    $back = null;
    try {
        if ($role['role'] === 'student') {
            switch ($a) {
                case 'buy':
                    edu_store_buy($org, $me, (int) ($_POST['item'] ?? 0), (string) ($_POST['note'] ?? ''));
                    edu_pflash('ok', 'Done. ' . (($_POST['kind'] ?? '') === 'digital' ? 'It is yours already.' : 'Your institution will be in touch to give it to you; see "Your orders" below.'));
                    $back = ['v' => 'store'];
                    break;
                case 'order-cancel':
                    edu_order_decide($org, (int) ($_POST['order'] ?? 0), 'cancelled', $me['name'], '', (int) $me['id']);
                    edu_pflash('ok', 'Cancelled. Your coins are back.');
                    $back = ['v' => 'store'];
                    break;
                case 'board-privacy':
                    fabo_exec('UPDATE edu_people SET board_hidden = ? WHERE org_id = ? AND id = ?', 'iii', [!empty($_POST['hidden']) ? 1 : 0, $org, (int) $me['id']]);
                    edu_pflash('ok', !empty($_POST['hidden']) ? 'You are hidden from rankings: others see "A student" in your place.' : 'Your name shows on rankings again.');
                    $back = ['v' => 'rewards'];
                    break;
                default:
                    return;
            }
        } elseif (in_array($role['role'], ['faculty', 'mentor'], true) && $a === 'kudos') {
            $st = edu_person($org, (int) ($_POST['student'] ?? 0));
            if (!$st) {
                throw new InvalidArgumentException('Pick a student.');
            }
            edu_kudos($org, $me, $role['role'], (int) $st['id'], (string) ($_POST['note'] ?? ''));
            edu_pflash('ok', 'Kudos sent to ' . $st['name'] . '. ' . edu_kudos_left($org, (int) $me['id']) . ' left this month.');
            $back = array_filter(['v' => (string) ($_GET['v'] ?? '')]);
        } else {
            return;
        }
    } catch (InvalidArgumentException $e) {
        edu_pflash('err', $e->getMessage());
        $back ??= array_filter(['v' => (string) ($_GET['v'] ?? '')]);
    }
    header('Location: ' . edu_purl($back), true, 303);
    exit;
}

/** The strip on a student's home page: level, XP to next, coins, streak. */
function edu_pv_rewards_strip(int $org, array $me): void
{
    if (!edu_game_on($org)) {
        return;
    }
    $xp = edu_balance($org, (int) $me['id'], 'xp');
    $lv = edu_level($xp);
    $league = edu_league($lv['level']);
    echo '<a class="cp-xp" href="' . e(edu_purl(['v' => 'rewards'])) . '"><span class="cp-lvl lg-' . e($league['key']) . '"><b>' . $lv['level'] . '</b><em>Level</em></span>'
       . '<span class="cp-xp-mid"><strong>' . e($lv['name']) . ' · ' . e($league['name']) . ' league</strong><span class="cp-xpbar"><i style="width:' . $lv['pct'] . '%"></i></span>'
       . '<em>' . fabo_thousands($xp) . ' XP · ' . fabo_thousands(max(0, $lv['next'] - $xp)) . ' to level ' . ($lv['level'] + 1) . '</em></span>'
       . '<span class="cp-coins"><b>' . fabo_thousands(edu_balance($org, (int) $me['id'], 'coin')) . '</b><em>coins</em></span>'
       . '<span class="cp-streak"><b>' . edu_streak($org, (int) $me['id']) . '</b><em>day streak</em></span></a>';
}

function edu_pv_student_rewards(int $org, array $me, array $role): void
{
    $pid = (int) $me['id'];
    echo '<div class="cp-pagehead"><h1>Rewards</h1></div>';
    edu_pv_rewards_strip($org, $me);
    $have = edu_badges_of($org, $pid);
    echo '<section class="cp-card"><h2>Badges <span class="muted">' . count($have) . ' of ' . count(edu_badges_all()) . '</span></h2><div class="cp-badges">';
    foreach (edu_badges_all() as $k => [$n, $what, $emoji]) {
        $got = isset($have[$k]);
        echo '<div class="cp-badge' . ($got ? ' got' : '') . '"><i aria-hidden="true">' . $emoji . '</i><b>' . e($n) . '</b><em>' . ($got ? 'Earned ' . e(fabo_short_date($have[$k])) : e($what)) . '</em></div>';
    }
    echo '</div></section><div class="cp-grid"><section class="cp-card"><h2>How to earn</h2><ul class="cp-earn">';
    foreach (edu_game_rules($org) as $k => $r) {
        if ($r['xp'] || $r['coin']) {
            echo '<li><span>' . e($r['label']) . '</span><b>' . ($r['xp'] ? '+' . $r['xp'] . ' XP' : '') . ($r['coin'] ? ' · +' . $r['coin'] . ' 🪙' : '') . '</b></li>';
        }
    }
    echo '</ul><p class="muted">Up to ' . edu_game_daily_cap($org) . ' coins a day. Coins are for the Store only: they cannot be bought, sold, given to others or exchanged for money.</p></section>'
       . '<section class="cp-card"><h2>History</h2><ul class="edu-ledger">';
    $h = edu_ledger_lines($org, $pid, 30);
    foreach ($h as $l) {
        echo '<li><span>' . e(edu_reason_words($l['reason'])) . ($l['note'] !== '' ? ' <em>' . e($l['note']) . '</em>' : '') . '<small>' . e(fabo_short_date($l['at'])) . '</small></span>'
           . edu_line_amounts($l, '🪙') . '</li>';
    }
    if (!$h) {
        echo '<li class="edu-empty">Finish a lesson to earn your first XP.</li>';
    }
    echo '</ul></section></div><section class="cp-card"><h2>Rankings privacy</h2><form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="board-privacy">'
       . '<label class="edu-check"><input type="checkbox" name="hidden" value="1"' . ((int) ($me['board_hidden'] ?? 0) ? ' checked' : '') . '> Hide my name on rankings (others see "A student"; you still see your own place)</label>'
       . '<button class="btn btn-sm">Save</button></form></section>';
}

function edu_pv_student_rankings(int $org, array $me, array $role): void
{
    $pid = (int) $me['id'];
    $period = in_array($_GET['period'] ?? '', ['week', 'month', 'all'], true) ? $_GET['period'] : 'week';
    $scope = in_array($_GET['scope'] ?? '', ['dept', 'inst', 'org', 'league', 'colleges'], true) ? $_GET['scope'] : 'inst';
    if ($scope === 'dept' && !(int) $role['department_id']) {
        $scope = 'inst';
    }
    $q = static fn(array $x) => edu_purl(['v' => 'rankings', 'period' => $period, 'scope' => $scope] + $x);
    echo '<div class="cp-pagehead"><h1>Rankings</h1><nav class="cp-seg">';
    foreach (['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $k => $l) {
        echo '<a href="' . e($q(['period' => $k])) . '"' . ($period === $k ? ' class="on"' : '') . '>' . $l . '</a>';
    }
    echo '</nav></div><nav class="cp-seg cp-scope">';
    $scopes = ((int) $role['department_id'] ? ['dept' => $role['dept_name']] : []) + ['inst' => 'My institution', 'league' => 'My league', 'org' => 'Everyone', 'colleges' => 'Colleges'];
    foreach ($scopes as $k => $l) {
        echo '<a href="' . e($q(['scope' => $k])) . '"' . ($scope === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
    }
    echo '</nav>';
    if ($scope === 'colleges') {
        $ib = edu_institution_board($org, $period);
        echo '<section class="cp-card"><p class="muted">Average XP per student, so every college has a fair chance.</p><ol class="cp-board">';
        foreach ($ib as $i => $r) {
            $mine = (int) $r['id'] === (int) $role['institution_id'];
            echo '<li class="' . ($mine ? 'me' : '') . ($i < 3 ? ' top' . ($i + 1) : '') . '"><b>' . ($i + 1) . '</b><span><strong>' . e($r['name']) . '</strong><em>' . (int) $r['active'] . ' of ' . (int) $r['students'] . ' students active</em></span><strong>' . e((string) $r['avg']) . ' XP</strong></li>';
        }
        echo '</ol></section>';
        return;
    }
    $sc = $scope === 'dept' ? ['dept' => (int) $role['department_id']] : ($scope === 'inst' ? ['inst' => (int) $role['institution_id']] : []);
    $rows = edu_leaderboard($org, $period, $sc);
    if ($scope === 'league') {
        $myLeague = edu_league(edu_level(edu_balance($org, $pid, 'xp'))['level'])['key'];
        $rows = array_values(array_filter($rows, static fn($r) => edu_league(edu_level(edu_balance($org, (int) $r['person_id'], 'xp'))['level'])['key'] === $myLeague));
        foreach ($rows as $i => &$r) {
            $r['rank'] = $i > 0 && $rows[$i - 1]['xp'] === $r['xp'] ? $rows[$i - 1]['rank'] : $i + 1;
        }
        unset($r);
        echo '<p class="muted">You are in the ' . e(edu_league(edu_level(edu_balance($org, $pid, 'xp'))['level'])['name']) . ' league: everyone here is at a similar level.</p>';
    }
    $me_row = null;
    foreach ($rows as $r) {
        if ((int) $r['person_id'] === $pid) {
            $me_row = $r;
        }
    }
    echo '<section class="cp-card">';
    if ($me_row) {
        echo '<p class="cp-myrank">You are <b>#' . (int) $me_row['rank'] . '</b> of ' . count($rows) . ' with ' . fabo_thousands($me_row['xp']) . ' XP' . ($me_row['rank'] > 1 ? ' — ' . fabo_thousands($rows[max(0, $me_row['rank'] - 2)]['xp'] - $me_row['xp'] + 1) . ' XP to move up.' : '. Top of the table!') . '</p>';
    } else {
        echo '<p class="cp-myrank">You have no XP ' . ['week' => 'this week', 'month' => 'this month', 'all' => 'yet'][$period] . '. Finish a lesson to join the table.</p>';
    }
    echo '<ol class="cp-board">';
    $shown = 0;
    foreach ($rows as $i => $r) {
        $isMe = (int) $r['person_id'] === $pid;
        if ($i >= 10 && !$isMe) {
            continue;
        }
        if ($i >= 10 && $isMe && $shown === 10) {
            echo '<li class="gap" aria-hidden="true">⋯</li>';
        }
        $shown++;
        echo '<li class="' . ($isMe ? 'me' : '') . ($r['rank'] <= 3 ? ' top' . $r['rank'] : '') . '"><b>' . (int) $r['rank'] . '</b><span><strong>' . e($isMe ? $me['name'] . ' (you)' : $r['display']) . '</strong>'
           . ($scope === 'org' || $scope === 'league' ? '<em>' . e((string) $r['inst_name']) . '</em>' : '') . '</span><strong>' . fabo_thousands($r['xp']) . ' XP</strong></li>';
    }
    if (!$rows) {
        echo '<li class="edu-empty">Nobody on the table yet. Be the first.</li>';
    }
    echo '</ol></section>';
}

function edu_pv_student_store(int $org, array $me, array $role): void
{
    $pid = (int) $me['id'];
    $coins = edu_balance($org, $pid, 'coin');
    $insts = array_map(static fn($r) => (int) $r['institution_id'], fabo_all('SELECT institution_id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student"', 'ii', [$org, $pid]));
    $items = edu_store_items($org, $insts);
    $mine = edu_orders($org, ['person' => $pid]);
    $count = [];
    foreach ($mine as $o) {
        if (in_array($o['status'], ['pending', 'fulfilled'], true)) {
            $count[(int) $o['item_id']] = ($count[(int) $o['item_id']] ?? 0) + 1;
        }
    }
    $icons = edu_store_icons();
    echo '<div class="cp-pagehead"><h1>Store</h1><span class="cp-wallet">🪙 <b>' . fabo_thousands($coins) . '</b> coins</span></div><div class="cp-store">';
    foreach ($items as $it) {
        $out = $it['stock'] !== null && (int) $it['stock'] <= 0;
        $limit = (int) $it['per_person'] > 0 && ($count[(int) $it['id']] ?? 0) >= (int) $it['per_person'];
        $short = $coins < (int) $it['price'];
        echo '<article class="cp-item"><i aria-hidden="true">' . ($icons[$it['icon']] ?? '🎁') . '</i><span class="cp-item-k">' . e(edu_store_kinds()[$it['kind']]) . ($it['sponsor'] !== '' ? ' · ' . e($it['sponsor']) : '') . '</span>'
           . '<h2>' . e($it['title']) . '</h2><p>' . e($it['description']) . '</p><div class="cp-item-f"><span class="cp-price">🪙 ' . (int) $it['price'] . '</span>'
           . ($it['stock'] !== null && !$out ? '<span class="muted">' . (int) $it['stock'] . ' left</span>' : '') . '</div>';
        if ($out) {
            echo '<button class="btn" disabled>Sold out</button>';
        } elseif ($limit) {
            echo '<button class="btn" disabled>You have this</button>';
        } elseif ($short) {
            echo '<button class="btn" disabled>' . ((int) $it['price'] - $coins) . ' more coins needed</button>';
        } else {
            echo '<details class="cp-buy"><summary class="btn btn-primary">Get it</summary><form method="post" class="cp-form">' . fabo_csrf_field() . '<input type="hidden" name="a" value="buy">'
               . '<input type="hidden" name="item" value="' . (int) $it['id'] . '"><input type="hidden" name="kind" value="' . e($it['kind']) . '">'
               . ($it['kind'] !== 'digital' ? '<input name="note" maxlength="500" placeholder="Anything they should know (size, preferred date…)">' : '')
               . '<button class="btn btn-primary">Spend ' . (int) $it['price'] . ' coins</button></form></details>';
        }
        echo '</article>';
    }
    echo '</div>';
    if (!$items) {
        echo '<p class="edu-empty">The store is empty for now. Keep earning: rewards are coming.</p>';
    }
    if ($mine) {
        echo '<section class="cp-card"><h2>Your orders</h2><ul class="cp-sessions">';
        foreach ($mine as $o) {
            echo '<li><span class="cp-date"><b>' . date('j', strtotime($o['created_at'])) . '</b>' . date('M', strtotime($o['created_at'])) . '</span><span><strong>' . ($icons[$o['icon']] ?? '🎁') . ' ' . e($o['title']) . '</strong>'
               . '<em>' . (int) $o['price'] . ' coins' . ($o['staff_note'] !== '' ? ' · ' . e($o['staff_note']) : '') . '</em></span>'
               . ['pending' => '<span class="pill amber">Waiting</span>', 'fulfilled' => '<span class="pill green">Yours</span>', 'rejected' => '<span class="pill red">Not possible · refunded</span>', 'cancelled' => '<span class="pill grey">Cancelled · refunded</span>'][$o['status']];
            if ($o['status'] === 'pending') {
                echo '<form method="post" data-confirm="Cancel and get your coins back?">' . fabo_csrf_field() . '<input type="hidden" name="a" value="order-cancel"><input type="hidden" name="order" value="' . (int) $o['id'] . '"><button class="btn btn-sm">Cancel</button></form>';
            }
            echo '</li>';
        }
        echo '</ul></section>';
    }
}

/** Kudos form for faculty and mentors: their own students only. */
function edu_pv_kudos_form(int $org, array $me, array $students): void
{
    if (!edu_game_on($org) || !$students) {
        return;
    }
    $left = edu_kudos_left($org, (int) $me['id']);
    echo '<section class="cp-card cp-kudos"><div class="cp-card-h"><h2>🌟 Give kudos</h2><span class="muted">' . $left . ' left this month</span></div>';
    if ($left <= 0) {
        echo '<p class="muted">You have used this month\'s kudos. More on the 1st.</p></section>';
        return;
    }
    echo '<form method="post" class="cp-form cp-kudosform">' . fabo_csrf_field() . '<input type="hidden" name="a" value="kudos"><select name="student" required aria-label="Student"><option value="">Choose a student…</option>';
    foreach ($students as $s) {
        echo '<option value="' . (int) $s['id'] . '">' . e($s['name']) . ($s['roll_no'] !== '' ? ' · ' . e($s['roll_no']) : '') . '</option>';
    }
    echo '</select><input name="note" required maxlength="200" placeholder="What for? e.g. Great prototype demo"><button class="btn btn-primary">Send kudos</button></form>'
       . '<p class="muted">They get recognition XP and a few coins, and see your note.</p></section>';
}

/** Top students for staff views (faculty, institution admin): this month, in a scope. */
function edu_pv_top_students(int $org, array $scope, string $title): void
{
    if (!edu_game_on($org)) {
        return;
    }
    $rows = array_slice(edu_leaderboard($org, 'month', $scope, 10), 0, 10);
    echo '<section class="cp-card"><h2>' . e($title) . '</h2><ol class="cp-board">';
    foreach ($rows as $r) {
        echo '<li class="' . ($r['rank'] <= 3 ? 'top' . $r['rank'] : '') . '"><b>' . (int) $r['rank'] . '</b><span><strong>' . e($r['name']) . '</strong></span><strong>' . fabo_thousands($r['xp']) . ' XP</strong></li>';
    }
    if (!$rows) {
        echo '<li class="edu-empty">No XP earned this month yet.</li>';
    }
    echo '</ol></section>';
}
