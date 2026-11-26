<?php
/**
 * Academic — rewards (Phase 3): XP, levels, coins, badges, streaks, kudos,
 * rankings and the store.
 *
 * Two currencies, never mixed:
 *   XP     earned only, never spent. Drives levels, badges and rankings.
 *   Coins  earned, then spent in the store. Spending never lowers XP or rank.
 *
 * Every change to either is one row in edu_ledger, which is only ever added
 * to: a balance is always the sum of the ledger, and a correction is a new
 * row (an adjustment or a refund), never an edit. Each automatic award
 * carries a reference (lesson:12, course:3, day:2026-11-18…) that is unique
 * per person, so the same thing can never pay twice.
 *
 * Coins are a reward and nothing else: they cannot be bought, sold, sent to
 * another person or turned into money, and nothing here is left to chance.
 * Only students earn. Times are on PHP's clock (edu_now()).
 */

require_once __DIR__ . '/core.php';

const EDU_GAME_VERSION = 3;

function edu_game_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS edu_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  currency ENUM('xp','coin') NOT NULL,
  amount INT NOT NULL,
  reason VARCHAR(24) NOT NULL,
  ref VARCHAR(64) NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  actor VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_once (person_id, currency, reason, ref),
  KEY k_org (org_id, currency, created_at),
  KEY k_person (person_id, currency)
) $t",

"CREATE TABLE IF NOT EXISTS edu_badges (
  person_id INT UNSIGNED NOT NULL,
  badge VARCHAR(32) NOT NULL,
  org_id INT UNSIGNED NOT NULL,
  earned_at DATETIME NOT NULL,
  PRIMARY KEY (person_id, badge),
  KEY k_org (org_id, earned_at)
) $t",

"CREATE TABLE IF NOT EXISTS edu_store_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description VARCHAR(600) NOT NULL DEFAULT '',
  kind ENUM('digital','benefit','physical') NOT NULL DEFAULT 'benefit',
  price INT UNSIGNED NOT NULL,
  stock INT NULL,
  per_person TINYINT UNSIGNED NOT NULL DEFAULT 1,
  institution_id INT UNSIGNED NOT NULL DEFAULT 0,
  sponsor VARCHAR(120) NOT NULL DEFAULT '',
  icon VARCHAR(16) NOT NULL DEFAULT 'gift',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY k_org (org_id, active)
) $t",

"CREATE TABLE IF NOT EXISTS edu_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  price INT UNSIGNED NOT NULL,
  status ENUM('pending','fulfilled','rejected','cancelled') NOT NULL DEFAULT 'pending',
  note VARCHAR(500) NOT NULL DEFAULT '',
  staff_note VARCHAR(500) NOT NULL DEFAULT '',
  decided_by VARCHAR(190) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  KEY k_org (org_id, status, created_at),
  KEY k_person (person_id, created_at),
  KEY k_item (item_id, status)
) $t",
    ];
}

function edu_game_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/lms.php';
    edu_lms_install();
    if ((int) fabo_setting('edu_game_schema', '0') >= EDU_GAME_VERSION) {
        return;
    }
    foreach (edu_game_schema_sql() as $sql) {
        fabo_exec($sql);
    }
    // A student may keep their name off the rankings.
    if (!fabo_one("SHOW COLUMNS FROM edu_people LIKE 'board_hidden'")) {
        fabo_exec('ALTER TABLE edu_people ADD COLUMN board_hidden TINYINT(1) NOT NULL DEFAULT 0');
    }
    fabo_exec('INSERT INTO app_settings (k, v) VALUES ("edu_game_schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', 's', [(string) EDU_GAME_VERSION]);
}

/* ================================================================== rules */

/** What each thing earns, by default: key => [label, xp, coins]. Each organisation can change the numbers. */
function edu_game_rules_default(): array
{
    return [
        'lesson'   => ['Finishing a lesson', 10, 2],
        'quiz'     => ['Passing a quiz', 20, 5],
        'perfect'  => ['A perfect quiz score (extra)', 10, 5],
        'course'   => ['Finishing a course', 100, 25],
        'session'  => ['A mentoring session held', 30, 10],
        'feedback' => ['Rating a session', 5, 0],
        'day'      => ['Learning on a day (streak)', 5, 0],
        'streak7'  => ['A 7-day streak (extra)', 50, 20],
        'kudos'    => ['Kudos from faculty or a mentor', 15, 5],
        'idea'     => ['Writing down a startup idea', 25, 5],
        'apply'    => ['Applying to incubation with an idea', 75, 20],
    ];
}

/** The organisation's numbers: key => ['label', 'xp', 'coin']. */
function edu_game_rules(int $org): array
{
    $saved = json_decode(edu_get($org, 'game_rules', ''), true) ?: [];
    $out = [];
    foreach (edu_game_rules_default() as $k => [$label, $xp, $coin]) {
        $out[$k] = ['label' => $label, 'xp' => (int) ($saved[$k]['xp'] ?? $xp), 'coin' => (int) ($saved[$k]['coin'] ?? $coin)];
    }
    return $out;
}

function edu_game_rules_save(int $org, array $in): void
{
    $out = [];
    foreach (array_keys(edu_game_rules_default()) as $k) {
        $out[$k] = ['xp' => max(0, min(1000, (int) ($in[$k]['xp'] ?? 0))), 'coin' => max(0, min(500, (int) ($in[$k]['coin'] ?? 0)))];
    }
    edu_set($org, 'game_rules', (string) json_encode($out));
}

function edu_game_on(int $org): bool
{
    return edu_get($org, 'game_on', '1') === '1';
}

/** Most coins one student can earn in a day from everything automatic (staff adjustments aside). */
function edu_game_daily_cap(int $org): int
{
    return max(0, (int) edu_get($org, 'game_coin_cap', '60'));
}

function edu_game_kudos_cap(int $org): int
{
    return max(0, (int) edu_get($org, 'game_kudos_cap', '10'));
}

/** How names appear on rankings: 'initial' (Ananya R.) or 'full'. */
function edu_game_names(int $org): string
{
    return edu_get($org, 'game_names', 'initial') === 'full' ? 'full' : 'initial';
}

/* ================================================================= ledger */

function edu_is_student(int $org, int $pid): bool
{
    return (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student" LIMIT 1', 'ii', [$org, $pid]);
}

/** Adds one ledger row. False when that reference already paid. */
function edu_ledger_add(int $org, int $pid, string $currency, int $amount, string $reason, string $ref, string $note = '', string $actor = '', ?string $at = null): bool
{
    if ($amount === 0) {
        return false;
    }
    $n = fabo_exec('INSERT IGNORE INTO edu_ledger (org_id, person_id, currency, amount, reason, ref, note, actor, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                   'iisisssss', [$org, $pid, $currency, $amount, $reason, mb_substr($ref, 0, 64), mb_substr($note, 0, 255), mb_substr($actor, 0, 190), $at ?? edu_now()]);
    return $n > 0;
}

function edu_balance(int $org, int $pid, string $currency): int
{
    return (int) (fabo_one('SELECT COALESCE(SUM(amount), 0) AS s FROM edu_ledger WHERE org_id = ? AND person_id = ? AND currency = ?', 'iis', [$org, $pid, $currency])['s'] ?? 0);
}

/** Coins earned automatically today (what the daily cap counts). */
function edu_coins_today(int $org, int $pid): int
{
    return (int) (fabo_one('SELECT COALESCE(SUM(amount), 0) AS s FROM edu_ledger WHERE org_id = ? AND person_id = ? AND currency = "coin" AND amount > 0
                             AND reason NOT IN ("adjust","refund") AND created_at >= ?', 'iis', [$org, $pid, date('Y-m-d 00:00:00')])['s'] ?? 0);
}

/**
 * Pays a rule to a student, once per reference. XP always; coins up to the
 * daily cap. Then looks for new badges. Returns [xp, coins] actually paid.
 */
function edu_award(int $org, int $pid, string $rule, string $ref, string $note = '', ?string $at = null): array
{
    if (!edu_game_on($org) || !edu_is_student($org, $pid)) {
        return [0, 0];
    }
    edu_game_install();
    $r = edu_game_rules($org)[$rule] ?? null;
    if (!$r) {
        return [0, 0];
    }
    $xp = edu_ledger_add($org, $pid, 'xp', $r['xp'], $rule, $ref, $note, '', $at) ? $r['xp'] : 0;
    $coins = 0;
    if ($r['coin'] > 0 && ($xp > 0 || $r['xp'] === 0)) {
        $room = $at === null ? max(0, edu_game_daily_cap($org) - edu_coins_today($org, $pid)) : $r['coin'];
        $pay = min($r['coin'], $room);
        if ($pay > 0 && edu_ledger_add($org, $pid, 'coin', $pay, $rule, $ref, $note . ($pay < $r['coin'] ? ' (daily coin limit reached)' : ''), '', $at)) {
            $coins = $pay;
        }
    }
    if ($xp || $coins) {
        edu_badges_check($org, $pid);
    }
    return [$xp, $coins];
}

/** A staff correction: plus or minus, with a reason, in either currency. Coins never go below zero. */
function edu_adjust(int $org, int $pid, string $currency, int $amount, string $reason, string $actor): void
{
    if (!edu_person($org, $pid)) {
        throw new InvalidArgumentException('That person is not in this organisation.');
    }
    $currency = $currency === 'xp' ? 'xp' : 'coin';
    if ($amount === 0 || abs($amount) > 100000) {
        throw new InvalidArgumentException('Give an amount (plus or minus).');
    }
    $reason = edu_clean($reason, 200);
    if ($reason === '') {
        throw new InvalidArgumentException('Say why: the student sees it in their history.');
    }
    if ($currency === 'coin' && edu_balance($org, $pid, 'coin') + $amount < 0) {
        throw new InvalidArgumentException('That would take their coins below zero.');
    }
    edu_ledger_add($org, $pid, $currency, $amount, 'adjust', 'adj:' . bin2hex(random_bytes(6)), $reason, $actor);
}

/** A student's history, newest first. */
function edu_ledger_history(int $org, int $pid, int $limit = 50): array
{
    return fabo_all('SELECT * FROM edu_ledger WHERE org_id = ? AND person_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)), 'ii', [$org, $pid]);
}

/**
 * History with one line per event: the XP and the coins of the same award
 * (same reason and reference) together. Each line: reason, note, when, xp, coin.
 */
function edu_ledger_lines(int $org, int $pid, int $limit = 30): array
{
    $out = [];
    foreach (edu_ledger_history($org, $pid, $limit * 2) as $l) {
        $k = $l['reason'] . '|' . $l['ref'];
        if (!isset($out[$k])) {
            $out[$k] = ['reason' => $l['reason'], 'note' => $l['note'], 'at' => $l['created_at'], 'xp' => 0, 'coin' => 0];
        }
        $out[$k][$l['currency']] += (int) $l['amount'];
    }
    return array_slice(array_values($out), 0, $limit);
}

/** "+10 XP · +2 coins" for a history line. */
function edu_line_amounts(array $l, string $coinWord = 'coins'): string
{
    $p = [];
    foreach (['xp' => 'XP', 'coin' => $coinWord] as $c => $w) {
        if ($l[$c] !== 0) {
            $p[] = '<b class="' . ($l[$c] < 0 ? 'neg' : '') . '">' . ($l[$c] > 0 ? '+' : '') . $l[$c] . ' ' . $w . '</b>';
        }
    }
    return '<span class="edu-amts">' . implode('', $p) . '</span>';
}

function edu_reason_words(string $reason): string
{
    return ['lesson' => 'Lesson finished', 'quiz' => 'Quiz passed', 'perfect' => 'Perfect quiz', 'course' => 'Course finished', 'session' => 'Mentoring session',
            'feedback' => 'Session rated', 'day' => 'Learning day', 'streak7' => '7-day streak', 'kudos' => 'Kudos', 'adjust' => 'Adjustment',
            'store' => 'Store', 'refund' => 'Refund', 'idea' => 'Idea written', 'apply' => 'Applied to incubation'][$reason] ?? ucfirst($reason);
}

/* ================================================================= levels */

function edu_level_names(): array
{
    return ['Explorer', 'Learner', 'Builder', 'Maker', 'Innovator', 'Pathfinder', 'Trailblazer', 'Pioneer', 'Visionary', 'Luminary'];
}

/** XP needed to reach level $l (level 1 = 0). Each level asks 100 more than the last. */
function edu_level_floor(int $l): int
{
    return 50 * $l * ($l - 1);
}

/** ['level', 'name', 'floor', 'next', 'pct'] for an XP total. */
function edu_level(int $xp): array
{
    $l = 1;
    while (edu_level_floor($l + 1) <= max(0, $xp) && $l < 99) {
        $l++;
    }
    $names = edu_level_names();
    $floor = edu_level_floor($l);
    $next = edu_level_floor($l + 1);
    return ['level' => $l, 'name' => $names[min($l, count($names)) - 1], 'floor' => $floor, 'next' => $next,
            'pct' => (int) floor((max(0, $xp) - $floor) * 100 / max(1, $next - $floor))];
}

/** Leagues by level, so a student is compared with people near them. */
function edu_league(int $level): array
{
    return $level >= 7 ? ['key' => 'platinum', 'name' => 'Platinum'] : ($level >= 5 ? ['key' => 'gold', 'name' => 'Gold']
         : ($level >= 3 ? ['key' => 'silver', 'name' => 'Silver'] : ['key' => 'bronze', 'name' => 'Bronze']));
}

/* ================================================================ streaks */

/** Counts today as a learning day (once) and pays the 7-day bonus when a run reaches seven. */
function edu_game_day(int $org, int $pid): void
{
    if (!edu_game_on($org) || !edu_is_student($org, $pid)) {
        return;
    }
    $today = date('Y-m-d');
    if (fabo_one('SELECT id FROM edu_ledger WHERE person_id = ? AND currency = "xp" AND reason = "day" AND ref = ?', 'is', [$pid, 'day:' . $today])) {
        return;
    }
    edu_award($org, $pid, 'day', 'day:' . $today);
    $streak = edu_streak($org, $pid);
    if ($streak > 0 && $streak % 7 === 0) {
        edu_award($org, $pid, 'streak7', 'streak7:' . $today, $streak . ' days in a row');
    }
}

/** Days in a row with learning, ending today (or yesterday, so a streak is not lost before today's first visit). */
function edu_streak(int $org, int $pid): int
{
    $days = [];
    foreach (fabo_all('SELECT ref FROM edu_ledger WHERE org_id = ? AND person_id = ? AND currency = "xp" AND reason = "day" AND created_at >= ?',
                      'iis', [$org, $pid, date('Y-m-d 00:00:00', strtotime('-400 days'))]) as $r) {
        $days[substr((string) $r['ref'], 4)] = true;
    }
    $d = strtotime('today');
    if (!isset($days[date('Y-m-d', $d)])) {
        $d = strtotime('-1 day', $d);
    }
    $n = 0;
    while (isset($days[date('Y-m-d', $d)])) {
        $n++;
        $d = strtotime('-1 day', $d);
    }
    return $n;
}

/* ================================================================= badges */

/** key => [name, what earns it, emoji]. Earned once, kept for good. */
function edu_badges_all(): array
{
    return [
        'first_lesson'  => ['First step', 'Finish your first lesson', '👣'],
        'quiz_ace'      => ['Quiz ace', 'Score 100% on a quiz', '🎯'],
        'first_course'  => ['Course complete', 'Finish a course', '🎓'],
        'three_courses' => ['Hat-trick', 'Finish three courses', '🏅'],
        'streak_7'      => ['On a roll', 'Learn seven days in a row', '🔥'],
        'streak_30'     => ['Unstoppable', 'Learn thirty days in a row', '⚡'],
        'mentored'      => ['Mentored', 'Have your first mentoring session', '🤝'],
        'feedback'      => ['Helpful voice', 'Rate a mentoring session', '💬'],
        'kudos'         => ['Recognised', 'Receive kudos', '🌟'],
        'ideator'       => ['Ideator', 'Write down a startup idea', '💡'],
        'applicant'     => ['Founder in the making', 'Apply to incubation', '🧪'],
        'level_5'       => ['Innovator', 'Reach level 5', '🚀'],
        'xp_1000'       => ['Thousand club', 'Earn 1,000 XP', '💎'],
    ];
}

function edu_badges_of(int $org, int $pid): array
{
    $out = [];
    foreach (fabo_all('SELECT badge, earned_at FROM edu_badges WHERE org_id = ? AND person_id = ? ORDER BY earned_at', 'ii', [$org, $pid]) as $r) {
        $out[$r['badge']] = $r['earned_at'];
    }
    return $out;
}

/** Looks at what a student has done and gives any badge they have now earned. Returns the new ones. */
function edu_badges_check(int $org, int $pid): array
{
    $have = edu_badges_of($org, $pid);
    $count = static function (string $reason) use ($pid): int {
        return (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_ledger WHERE person_id = ? AND currency = "xp" AND reason = ?', 'is', [$pid, $reason])['n'] ?? 0);
    };
    $xp = edu_balance($org, $pid, 'xp');
    $streak = edu_streak($org, $pid);
    $earned = [
        'first_lesson'  => $count('lesson') >= 1,
        'quiz_ace'      => $count('perfect') >= 1,
        'first_course'  => $count('course') >= 1,
        'three_courses' => $count('course') >= 3,
        'streak_7'      => $streak >= 7,
        'streak_30'     => $streak >= 30,
        'mentored'      => $count('session') >= 1,
        'feedback'      => $count('feedback') >= 1,
        'kudos'         => $count('kudos') >= 1,
        'ideator'       => $count('idea') >= 1,
        'applicant'     => $count('apply') >= 1,
        'level_5'       => edu_level($xp)['level'] >= 5,
        'xp_1000'       => $xp >= 1000,
    ];
    $new = [];
    foreach ($earned as $b => $yes) {
        if ($yes && !isset($have[$b])) {
            fabo_exec('INSERT IGNORE INTO edu_badges (person_id, badge, org_id, earned_at) VALUES (?,?,?,?)', 'isis', [$pid, $b, $org, edu_now()]);
            $new[] = $b;
        }
    }
    return $new;
}

/* ================================================================== kudos */

/**
 * Faculty or a mentor recognises a student they teach or mentor. Each giver
 * has a monthly allowance; the same student once a day from the same giver.
 */
function edu_kudos(int $org, array $giver, string $asRole, int $studentId, string $note): void
{
    $gid = (int) $giver['id'];
    if (!edu_is_student($org, $studentId) || $gid === $studentId) {
        throw new InvalidArgumentException('Kudos go to a student.');
    }
    $mine = false;
    if ($asRole === 'mentor') {
        $mine = (bool) fabo_one('SELECT 1 AS y FROM edu_mentor_links WHERE org_id = ? AND mentor_id = ? AND student_id = ?', 'iii', [$org, $gid, $studentId]);
    } elseif ($asRole === 'faculty') {
        // A student in a department (or institution) where the giver teaches.
        $mine = (bool) fabo_one('SELECT 1 AS y FROM edu_roles f JOIN edu_roles s ON s.person_id = ? AND s.role = "student" AND s.institution_id = f.institution_id
                                  AND (f.department_id = 0 OR s.department_id = f.department_id)
                                WHERE f.org_id = ? AND f.person_id = ? AND f.role = "faculty" LIMIT 1', 'iii', [$studentId, $org, $gid]);
    }
    if (!$mine) {
        throw new InvalidArgumentException('You can give kudos to your own students.');
    }
    $note = edu_clean($note, 200);
    if ($note === '') {
        throw new InvalidArgumentException('Say in a few words what it is for: the student sees it.');
    }
    $used = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_ledger WHERE org_id = ? AND currency = "xp" AND reason = "kudos" AND actor = ? AND created_at >= ?',
                            'iss', [$org, 'person:' . $gid, date('Y-m-01 00:00:00')])['n'] ?? 0);
    if ($used >= edu_game_kudos_cap($org)) {
        throw new InvalidArgumentException('You have given all ' . edu_game_kudos_cap($org) . ' kudos for this month.');
    }
    $ref = 'kudos:' . $gid . ':' . date('Y-m-d');
    if (fabo_one('SELECT id FROM edu_ledger WHERE person_id = ? AND currency = "xp" AND reason = "kudos" AND ref = ?', 'is', [$studentId, $ref])) {
        throw new InvalidArgumentException('You have already given this student kudos today.');
    }
    $r = edu_game_rules($org)['kudos'];
    edu_ledger_add($org, $studentId, 'xp', $r['xp'], 'kudos', $ref, $giver['name'] . ': ' . $note, 'person:' . $gid);
    if ($r['coin'] > 0) {
        edu_ledger_add($org, $studentId, 'coin', $r['coin'], 'kudos', $ref, $giver['name'] . ': ' . $note, 'person:' . $gid);
    }
    edu_badges_check($org, $studentId);
}

function edu_kudos_left(int $org, int $gid): int
{
    $used = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_ledger WHERE org_id = ? AND currency = "xp" AND reason = "kudos" AND actor = ? AND created_at >= ?',
                            'iss', [$org, 'person:' . $gid, date('Y-m-01 00:00:00')])['n'] ?? 0);
    return max(0, edu_game_kudos_cap($org) - $used);
}

/* ================================================================ rankings */

/** When a period starts: 'week' (Monday), 'month', or 'all' (null). */
function edu_period_start(string $period): ?string
{
    return $period === 'week' ? date('Y-m-d 00:00:00', strtotime('monday this week')) : ($period === 'month' ? date('Y-m-01 00:00:00') : null);
}

function edu_board_name(array $r, string $mode): string
{
    if (!empty($r['board_hidden'])) {
        return 'A student';
    }
    $name = trim((string) $r['name']);
    if ($mode === 'full') {
        return $name;
    }
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    return count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $name;
}

/**
 * XP ranking of students. $scope: ['inst' => id] or ['dept' => id] or [] for
 * the organisation. Only students with XP in the period appear. Returns
 * rows (rank, person_id, name for display, xp, inst_name) — all of them, so
 * a caller can find "you" and show the top.
 */
function edu_leaderboard(int $org, string $period, array $scope = [], int $limit = 1000): array
{
    $where = 'r.org_id = ? AND r.role = "student"';
    $types = 'i';
    $p = [$org];
    if (!empty($scope['dept'])) {
        $where .= ' AND r.department_id = ?';
        $types .= 'i';
        $p[] = (int) $scope['dept'];
    } elseif (!empty($scope['inst'])) {
        $where .= ' AND r.institution_id = ?';
        $types .= 'i';
        $p[] = (int) $scope['inst'];
    }
    $since = edu_period_start($period);
    $lw = 'l.org_id = ? AND l.currency = "xp"' . ($since ? ' AND l.created_at >= ?' : '');
    $types2 = 'i' . ($since ? 's' : '');
    $p2 = $since ? [$org, $since] : [$org];
    $rows = fabo_all('SELECT p.id AS person_id, p.name, p.board_hidden, x.xp,
                             (SELECT i.name FROM edu_roles r2 JOIN edu_institutions i ON i.id = r2.institution_id WHERE r2.person_id = p.id AND r2.role = "student" LIMIT 1) AS inst_name
                        FROM (SELECT l.person_id, SUM(l.amount) AS xp FROM edu_ledger l WHERE ' . $lw . ' GROUP BY l.person_id) x
                        JOIN edu_people p ON p.id = x.person_id AND p.status = "active"
                       WHERE x.xp > 0 AND EXISTS (SELECT 1 FROM edu_roles r WHERE r.person_id = p.id AND ' . $where . ')
                    ORDER BY x.xp DESC, p.id ASC LIMIT ' . max(1, min(5000, $limit)), $types2 . $types, array_merge($p2, $p));
    $mode = edu_game_names($org);
    $rank = 0;
    $prev = null;
    foreach ($rows as $i => &$r) {
        if ((int) $r['xp'] !== $prev) {
            $rank = $i + 1;
            $prev = (int) $r['xp'];
        }
        $r['rank'] = $rank;
        $r['xp'] = (int) $r['xp'];
        $r['display'] = edu_board_name($r, $mode);
    }
    unset($r);
    return $rows;
}

/** Institutions ranked by average XP per student in the period (fair to small colleges), with how many are active. */
function edu_institution_board(int $org, string $period): array
{
    $since = edu_period_start($period);
    $rows = fabo_all('SELECT i.id, i.name, COUNT(DISTINCT r.person_id) AS students,
                             COUNT(DISTINCT x.person_id) AS active, COALESCE(SUM(x.xp), 0) AS xp
                        FROM edu_institutions i
                        JOIN edu_roles r ON r.institution_id = i.id AND r.role = "student"
                   LEFT JOIN (SELECT l.person_id, SUM(l.amount) AS xp FROM edu_ledger l WHERE l.org_id = ? AND l.currency = "xp"' . ($since ? ' AND l.created_at >= ?' : '') . ' GROUP BY l.person_id) x
                          ON x.person_id = r.person_id
                       WHERE i.org_id = ? AND i.status = "active"
                    GROUP BY i.id, i.name', $since ? 'isi' : 'ii', $since ? [$org, $since, $org] : [$org, $org]);
    foreach ($rows as &$r) {
        $r['avg'] = (int) $r['students'] ? round($r['xp'] / $r['students'], 1) : 0;
        $r['active_pct'] = (int) $r['students'] ? (int) round($r['active'] * 100 / $r['students']) : 0;
    }
    unset($r);
    usort($rows, static fn($a, $b) => $b['avg'] <=> $a['avg'] ?: $b['active'] <=> $a['active']);
    return $rows;
}

/* ================================================================== store */

function edu_store_kinds(): array
{
    return ['digital' => 'Digital', 'benefit' => 'Benefit', 'physical' => 'Physical'];
}

function edu_store_icons(): array
{
    return ['gift' => '🎁', 'ticket' => '🎟️', 'mentor' => '🧑‍🏫', 'desk' => '🪑', 'lab' => '🔬', 'shirt' => '👕', 'book' => '📚', 'badge' => '🏷️', 'star' => '⭐', 'coffee' => '☕', 'laptop' => '💻', 'trophy' => '🏆'];
}

function edu_store_item(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_store_items WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

/** Items, with how many have been taken. $forInst: only what a student of these institutions may see (active only). */
function edu_store_items(int $org, ?array $forInst = null): array
{
    edu_game_install();
    $rows = fabo_all('SELECT s.*, (SELECT COUNT(*) FROM edu_orders o WHERE o.item_id = s.id AND o.status IN ("pending","fulfilled")) AS taken
                        FROM edu_store_items s WHERE s.org_id = ?' . ($forInst !== null ? ' AND s.active = 1' : '') . ' ORDER BY s.active DESC, s.sort, s.price, s.id', 'i', [$org]);
    if ($forInst !== null) {
        $rows = array_values(array_filter($rows, static fn($r) => (int) $r['institution_id'] === 0 || in_array((int) $r['institution_id'], $forInst, true)));
    }
    return $rows;
}

function edu_store_item_save(int $org, array $d, ?int $id = null): int
{
    $title = edu_clean($d['title'] ?? '', 160);
    if ($title === '') {
        throw new InvalidArgumentException('Give the item a name.');
    }
    $price = (int) ($d['price'] ?? 0);
    if ($price < 1 || $price > 100000) {
        throw new InvalidArgumentException('Set a price of at least 1 coin.');
    }
    $stock = trim((string) ($d['stock'] ?? ''));
    $stock = $stock === '' ? null : max(0, (int) $stock);
    $inst = (int) ($d['institution_id'] ?? 0);
    if ($inst && !edu_institution($org, $inst)) {
        throw new InvalidArgumentException('That institution is not in this organisation.');
    }
    $kind = isset(edu_store_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'benefit';
    $icon = isset(edu_store_icons()[$d['icon'] ?? '']) ? $d['icon'] : 'gift';
    $per = max(0, min(50, (int) ($d['per_person'] ?? 1)));
    $vals = [$title, mb_substr(trim((string) ($d['description'] ?? '')), 0, 600), $kind, $price, $stock, $per, $inst, edu_clean($d['sponsor'] ?? '', 120), $icon, !empty($d['active']) ? 1 : 0];
    if ($id) {
        if (!edu_store_item($org, $id)) {
            throw new InvalidArgumentException('That item is not here.');
        }
        fabo_exec('UPDATE edu_store_items SET title=?, description=?, kind=?, price=?, stock=?, per_person=?, institution_id=?, sponsor=?, icon=?, active=? WHERE org_id = ? AND id = ?',
                  'sssiiiissiii', array_merge($vals, [$org, $id]));
        return $id;
    }
    return fabo_exec('INSERT INTO edu_store_items (title, description, kind, price, stock, per_person, institution_id, sponsor, icon, active, org_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                     'sssiiiissiis', array_merge($vals, [$org, edu_now()]));
}

/**
 * A student spends coins. One at a time per student (a database lock), so
 * two quick clicks cannot spend the same coins twice; the stock is taken in
 * one conditional update, so the last item cannot go to two people.
 */
function edu_store_buy(int $org, array $person, int $itemId, string $note = ''): int
{
    $pid = (int) $person['id'];
    if (!edu_game_on($org) || !edu_is_student($org, $pid)) {
        throw new InvalidArgumentException('The store is for students.');
    }
    $insts = array_map(static fn($r) => (int) $r['institution_id'], fabo_all('SELECT institution_id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "student"', 'ii', [$org, $pid]));
    $lock = 'edu_coin_' . $pid;
    if ((int) (fabo_one('SELECT GET_LOCK(?, 5) AS g', 's', [$lock])['g'] ?? 0) !== 1) {
        throw new InvalidArgumentException('Busy: try again in a moment.');
    }
    try {
        $it = edu_store_item($org, $itemId);
        if (!$it || !(int) $it['active'] || ((int) $it['institution_id'] && !in_array((int) $it['institution_id'], $insts, true))) {
            throw new InvalidArgumentException('That item is not available.');
        }
        if ((int) $it['per_person'] > 0) {
            $mine = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_orders WHERE item_id = ? AND person_id = ? AND status IN ("pending","fulfilled")', 'ii', [$itemId, $pid])['n'] ?? 0);
            if ($mine >= (int) $it['per_person']) {
                throw new InvalidArgumentException('You already have ' . ((int) $it['per_person'] === 1 ? 'this one' : 'as many of these as one person may') . '.');
            }
        }
        if (edu_balance($org, $pid, 'coin') < (int) $it['price']) {
            throw new InvalidArgumentException('Not enough coins yet: you need ' . (int) $it['price'] . '.');
        }
        if ($it['stock'] !== null && fabo_exec('UPDATE edu_store_items SET stock = stock - 1 WHERE id = ? AND stock > 0', 'i', [$itemId]) !== 1) {
            throw new InvalidArgumentException('Sold out.');
        }
        $oid = fabo_exec('INSERT INTO edu_orders (org_id, item_id, person_id, price, note, created_at) VALUES (?,?,?,?,?,?)',
                         'iiiiss', [$org, $itemId, $pid, (int) $it['price'], mb_substr(trim($note), 0, 500), edu_now()]);
        edu_ledger_add($org, $pid, 'coin', -(int) $it['price'], 'store', 'order:' . $oid, $it['title']);
        // A digital item is delivered at once.
        if ($it['kind'] === 'digital') {
            fabo_exec('UPDATE edu_orders SET status = "fulfilled", decided_at = ?, decided_by = "Automatic" WHERE id = ?', 'si', [edu_now(), $oid]);
        }
        return $oid;
    } finally {
        fabo_one('SELECT RELEASE_LOCK(?) AS r', 's', [$lock]);
    }
}

/** Staff decide an order: fulfilled, or rejected (coins back, stock back). A student may cancel their own pending order. */
function edu_order_decide(int $org, int $orderId, string $status, string $actor, string $note = '', ?int $byStudent = null): void
{
    $o = fabo_one('SELECT * FROM edu_orders WHERE org_id = ? AND id = ?', 'ii', [$org, $orderId]);
    if (!$o || $o['status'] !== 'pending') {
        throw new InvalidArgumentException('That order is not waiting for a decision.');
    }
    if ($byStudent !== null && ((int) $o['person_id'] !== $byStudent || $status !== 'cancelled')) {
        throw new InvalidArgumentException('That is not your order.');
    }
    if (!in_array($status, ['fulfilled', 'rejected', 'cancelled'], true)) {
        throw new InvalidArgumentException('Pick fulfilled or rejected.');
    }
    $n = fabo_exec('UPDATE edu_orders SET status = ?, staff_note = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = "pending"',
                   'ssssi', [$status, mb_substr(trim($note), 0, 500), mb_substr($actor, 0, 190), edu_now(), $orderId]);
    if ($n === 1 && $status !== 'fulfilled') {
        edu_ledger_add($org, (int) $o['person_id'], 'coin', (int) $o['price'], 'refund', 'order:' . $orderId, $status === 'cancelled' ? 'Order cancelled' : 'Order not possible' . ($note !== '' ? ': ' . $note : ''));
        fabo_exec('UPDATE edu_store_items SET stock = stock + 1 WHERE id = ? AND stock IS NOT NULL', 'i', [(int) $o['item_id']]);
    }
}

function edu_orders(int $org, array $f = []): array
{
    $w = 'o.org_id = ?';
    $types = 'i';
    $p = [$org];
    if (!empty($f['status'])) {
        $w .= ' AND o.status = ?';
        $types .= 's';
        $p[] = $f['status'];
    }
    if (!empty($f['person'])) {
        $w .= ' AND o.person_id = ?';
        $types .= 'i';
        $p[] = (int) $f['person'];
    }
    return fabo_all('SELECT o.*, s.title, s.kind, s.icon, p.name, p.email, p.roll_no FROM edu_orders o JOIN edu_store_items s ON s.id = o.item_id JOIN edu_people p ON p.id = o.person_id
                      WHERE ' . $w . ' ORDER BY o.status = "pending" DESC, o.created_at DESC LIMIT 300', $types, $p);
}

/** A starter shelf of rewards that cost the institution little. Inactive until staff switch them on. */
function edu_store_starter(int $org): int
{
    $n = 0;
    foreach ([
        ['One-to-one mentor slot (priority booking)', 'Jump the queue for a 30-minute session with a mentor of your choice.', 'benefit', 150, null, 2, 'mentor'],
        ['Free entry to the next hackathon', 'A registration for the next hackathon or bootcamp run by the hub.', 'benefit', 200, 50, 1, 'ticket'],
        ['A day of co-working desk', 'Work from the incubation centre for a day, with Wi-Fi and coffee.', 'benefit', 120, 20, 3, 'desk'],
        ['Makerspace / lab hour', 'An hour in the lab with a technician, to build your prototype.', 'benefit', 180, 15, 2, 'lab'],
        ['Profile badge: Early Innovator', 'A badge on your Campus profile for the first cohort.', 'digital', 40, null, 1, 'badge'],
        ['Innovation hub T-shirt', 'Collect from your institution office once approved.', 'physical', 300, 40, 1, 'shirt'],
    ] as [$t, $d, $k, $price, $stock, $per, $icon]) {
        edu_store_item_save($org, ['title' => $t, 'description' => $d, 'kind' => $k, 'price' => $price, 'stock' => $stock === null ? '' : $stock, 'per_person' => $per, 'icon' => $icon, 'active' => 0]);
        $n++;
    }
    return $n;
}

/* ============================================================== backfill */

/**
 * Pays what students already did before rewards existed (lessons, quizzes,
 * courses, sessions), once, dated when they did it. Safe to run again: the
 * ledger's references stop anything paying twice.
 */
function edu_game_backfill(int $org): int
{
    edu_game_install();
    $n = 0;
    foreach (fabo_all('SELECT d.*, l.kind FROM edu_lesson_done d JOIN edu_lessons l ON l.id = d.lesson_id WHERE d.org_id = ?', 'i', [$org]) as $d) {
        $pid = (int) $d['person_id'];
        if ($d['kind'] === 'quiz') {
            $n += (int) (edu_award($org, $pid, 'quiz', 'lesson:' . $d['lesson_id'], '', $d['done_at'])[0] > 0);
            if ((int) $d['score'] === 100) {
                edu_award($org, $pid, 'perfect', 'lesson:' . $d['lesson_id'], '', $d['done_at']);
            }
        } else {
            $n += (int) (edu_award($org, $pid, 'lesson', 'lesson:' . $d['lesson_id'], '', $d['done_at'])[0] > 0);
        }
    }
    foreach (fabo_all('SELECT * FROM edu_enrolments WHERE org_id = ? AND completed_at IS NOT NULL', 'i', [$org]) as $e) {
        $n += (int) (edu_award($org, (int) $e['person_id'], 'course', 'course:' . $e['course_id'], '', $e['completed_at'])[0] > 0);
    }
    foreach (fabo_all('SELECT * FROM edu_slots WHERE org_id = ? AND status = "done" AND student_id IS NOT NULL', 'i', [$org]) as $s) {
        $n += (int) (edu_award($org, (int) $s['student_id'], 'session', 'session:' . $s['id'], '', $s['starts_at'])[0] > 0);
        if ($s['rating']) {
            edu_award($org, (int) $s['student_id'], 'feedback', 'session:' . $s['id'], '', $s['starts_at']);
        }
    }
    edu_set($org, 'game_backfilled', edu_now());
    return $n;
}

/** Totals for the desk. */
function edu_game_totals(int $org): array
{
    $r = fabo_one('SELECT SUM(currency = "xp" AND amount > 0) AS xp_rows, COALESCE(SUM(IF(currency = "xp", amount, 0)), 0) AS xp,
                          COALESCE(SUM(IF(currency = "coin" AND amount > 0 AND reason <> "refund", amount, 0)), 0) AS coins_in,
                          COALESCE(-SUM(IF(currency = "coin" AND reason = "store", amount, 0)), 0) AS coins_spent,
                          COALESCE(SUM(IF(currency = "coin" AND reason = "refund", amount, 0)), 0) AS coins_refunded,
                          COUNT(DISTINCT IF(created_at >= ?, person_id, NULL)) AS active_7
                     FROM edu_ledger WHERE org_id = ?', 'si', [edu_now(-7 * 86400), $org]) ?: [];
    $r['pending'] = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_orders WHERE org_id = ? AND status = "pending"', 'i', [$org])['n'] ?? 0);
    $r['badges'] = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_badges WHERE org_id = ?', 'i', [$org])['n'] ?? 0);
    $r['coins_held'] = (int) $r['coins_in'] - (int) $r['coins_spent'] + (int) $r['coins_refunded'];
    return array_map('intval', $r);
}
