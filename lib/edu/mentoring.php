<?php
/**
 * Academic — mentoring sessions (Phase 2).
 *
 * A mentor offers time slots (once, or weekly for a few weeks). A student
 * linked to that mentor (edu_mentor_links) books an open slot with a topic.
 * Either may cancel: the student up to EDU_CANCEL_HOURS before, which opens
 * the slot again; the mentor at any time, which closes it and tells the
 * student. Afterwards the mentor records it as held (with notes only they and
 * the programme team see) or missed, and the student can rate it.
 *
 * Times are local (PHP's clock, edu_now()), never the database's.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/game.php';     // a session held and a session rated pay the student

const EDU_CANCEL_HOURS = 2;
const EDU_MAX_BOOKINGS = 3;

function edu_mentoring_schema_sql(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS edu_slots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  mentor_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  mode ENUM('online','in_person') NOT NULL DEFAULT 'online',
  place VARCHAR(300) NOT NULL DEFAULT '',
  status ENUM('open','booked','done','missed','cancelled') NOT NULL DEFAULT 'open',
  student_id INT UNSIGNED NULL,
  topic VARCHAR(300) NULL,
  booked_at DATETIME NULL,
  mentor_notes TEXT NULL,
  rating TINYINT UNSIGNED NULL,
  feedback TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY k_mentor (org_id, mentor_id, starts_at),
  KEY k_student (org_id, student_id, starts_at),
  KEY k_status (org_id, status, starts_at)
) $t",
    ];
}

function edu_slot_statuses(): array
{
    return ['open' => 'Open', 'booked' => 'Booked', 'done' => 'Held', 'missed' => 'Missed', 'cancelled' => 'Cancelled'];
}

/** Sends one message from the organisation. Never to a sample (.example) address. Returns [sent?, words]. */
function edu_send(int $org, string $to, string $subject, string $text): array
{
    $to = trim($to);
    if ($to === '' || str_ends_with($to, '.example')) {
        return [false, 'no address to send to'];
    }
    if (!empty($GLOBALS['edu_mail_hook']) && is_callable($GLOBALS['edu_mail_hook'])) {
        ($GLOBALS['edu_mail_hook'])($to, $subject, $text);
        return [true, 'sent'];
    }
    try {
        require_once __DIR__ . '/../outbox.php';
        $html = '<p>' . nl2br(htmlspecialchars($text)) . '</p>';
        [$sent, $why, $queued] = fabo_post_or_queue($to, $subject, $text, $html, null, 'edu mentoring', edu_org_actor($org));
        return [$sent || $queued, $sent ? 'sent' : (string) $why];
    } catch (Throwable $e) {
        error_log('FABO edu send: ' . $e->getMessage());
        return [false, 'not sent'];
    }
}

function edu_slot(int $org, int $id): ?array
{
    return fabo_one('SELECT * FROM edu_slots WHERE org_id = ? AND id = ?', 'ii', [$org, $id]);
}

function edu_is_mentor(int $org, int $pid): bool
{
    return (bool) fabo_one('SELECT id FROM edu_roles WHERE org_id = ? AND person_id = ? AND role = "mentor"', 'ii', [$org, $pid]);
}

function edu_slot_when(array $s): string
{
    $t = strtotime($s['starts_at']);
    return date('D j M Y, g:i a', $t) . ' – ' . date('g:i a', $t + 60 * (int) $s['minutes']);
}

/**
 * Offers time. $start 'Y-m-d H:i' (local). Weekly repeats make the same slot
 * for the following weeks. A slot that would overlap one already offered is
 * skipped. Returns how many were made.
 */
function edu_slot_add(int $org, int $mentorId, string $start, int $minutes, string $mode, string $place, int $weeks = 1): int
{
    if (!edu_is_mentor($org, $mentorId)) {
        throw new InvalidArgumentException('Only a mentor can offer sessions.');
    }
    $t = strtotime($start);
    if ($t === false || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $start)) {
        throw new InvalidArgumentException('Pick a date and time.');
    }
    if ($t < time() + 15 * 60) {
        throw new InvalidArgumentException('Pick a time at least 15 minutes from now.');
    }
    if ($t > time() + 180 * 86400) {
        throw new InvalidArgumentException('Offer sessions up to six months ahead.');
    }
    $minutes = in_array($minutes, [15, 20, 30, 45, 60, 90], true) ? $minutes : 30;
    $mode = $mode === 'in_person' ? 'in_person' : 'online';
    $place = edu_clean($place, 300);
    if ($mode === 'online' && $place !== '' && !preg_match('#^https://\S+$#', $place)) {
        throw new InvalidArgumentException('An online session\'s link must start with https://');
    }
    $weeks = max(1, min(12, $weeks));
    $made = 0;
    for ($w = 0; $w < $weeks; $w++) {
        $at = strtotime('+' . $w . ' week', $t);
        $from = date('Y-m-d H:i:s', $at);
        $to = date('Y-m-d H:i:s', $at + 60 * $minutes);
        $clash = fabo_one('SELECT id FROM edu_slots WHERE org_id = ? AND mentor_id = ? AND status IN ("open","booked")
                            AND starts_at < ? AND DATE_ADD(starts_at, INTERVAL minutes MINUTE) > ?', 'iiss', [$org, $mentorId, $to, $from]);
        if ($clash) {
            continue;
        }
        fabo_exec('INSERT INTO edu_slots (org_id, mentor_id, starts_at, minutes, mode, place, created_at) VALUES (?,?,?,?,?,?,?)',
                  'iisisss', [$org, $mentorId, $from, $minutes, $mode, $place, edu_now()]);
        $made++;
    }
    if ($made === 0) {
        throw new InvalidArgumentException('That time overlaps a session you already offer.');
    }
    return $made;
}

/** A mentor's sessions: upcoming (open and booked, soonest first) or past (latest first). */
function edu_mentor_slots(int $org, int $mentorId, bool $upcoming): array
{
    $sql = 'SELECT s.*, p.name AS student_name, p.email AS student_email FROM edu_slots s LEFT JOIN edu_people p ON p.id = s.student_id
             WHERE s.org_id = ? AND s.mentor_id = ? AND ';
    return $upcoming
        ? fabo_all($sql . 's.status IN ("open","booked") AND s.starts_at >= ? ORDER BY s.starts_at LIMIT 100', 'iis', [$org, $mentorId, edu_now(-3 * 3600)])
        : fabo_all($sql . '(s.status IN ("done","missed") OR (s.status = "booked" AND s.starts_at < ?)) ORDER BY s.starts_at DESC LIMIT 100', 'iis', [$org, $mentorId, edu_now(-3 * 3600)]);
}

/** Open slots from the mentors linked to this student, soonest first. */
function edu_open_slots_for(int $org, int $studentId): array
{
    return fabo_all('SELECT s.*, p.name AS mentor_name,
                            (SELECT title FROM edu_roles r WHERE r.person_id = s.mentor_id AND r.role = "mentor" LIMIT 1) AS mentor_title
                       FROM edu_slots s JOIN edu_mentor_links l ON l.mentor_id = s.mentor_id AND l.student_id = ? AND l.org_id = s.org_id
                       JOIN edu_people p ON p.id = s.mentor_id
                      WHERE s.org_id = ? AND s.status = "open" AND s.starts_at > ?
                   ORDER BY s.starts_at LIMIT 60', 'iis', [$studentId, $org, edu_now(15 * 60)]);
}

/** A student's sessions: upcoming bookings, then past ones. */
function edu_student_slots(int $org, int $studentId): array
{
    return fabo_all('SELECT s.*, p.name AS mentor_name FROM edu_slots s JOIN edu_people p ON p.id = s.mentor_id
                      WHERE s.org_id = ? AND s.student_id = ? AND s.status IN ("booked","done","missed","cancelled")
                   ORDER BY (s.status = "booked" AND s.starts_at >= ?) DESC, s.starts_at DESC LIMIT 60', 'iis', [$org, $studentId, edu_now(-3 * 3600)]);
}

function edu_slot_book(int $org, int $slotId, array $student, string $topic): array
{
    $s = edu_slot($org, $slotId);
    $sid = (int) $student['id'];
    if (!$s || !fabo_one('SELECT 1 AS y FROM edu_mentor_links WHERE org_id = ? AND mentor_id = ? AND student_id = ?', 'iii', [$org, (int) $s['mentor_id'], $sid])) {
        throw new InvalidArgumentException('You can book only your own mentors.');
    }
    $topic = edu_clean($topic, 300);
    if ($topic === '') {
        throw new InvalidArgumentException('Say in a line what you would like to talk about.');
    }
    $mine = (int) (fabo_one('SELECT COUNT(*) AS n FROM edu_slots WHERE org_id = ? AND student_id = ? AND status = "booked" AND starts_at > ?', 'iis', [$org, $sid, edu_now()])['n'] ?? 0);
    if ($mine >= EDU_MAX_BOOKINGS) {
        throw new InvalidArgumentException('You already have ' . EDU_MAX_BOOKINGS . ' sessions booked. Book more after one of them.');
    }
    // Only one student can win the slot: the update succeeds for whoever is first.
    $n = fabo_exec('UPDATE edu_slots SET status = "booked", student_id = ?, topic = ?, booked_at = ? WHERE org_id = ? AND id = ? AND status = "open" AND starts_at > ?',
                   'issiis', [$sid, $topic, edu_now(), $org, $slotId, edu_now(15 * 60)]);
    if ($n !== 1) {
        throw new InvalidArgumentException('Someone else has just booked that time, or it is too close to start. Pick another.');
    }
    $s = edu_slot($org, $slotId);
    $mentor = edu_person($org, (int) $s['mentor_id']);
    edu_send($org, (string) ($mentor['email'] ?? ''), 'Session booked: ' . edu_slot_when($s),
             'Hello ' . ($mentor['name'] ?? '') . ",\n\n" . $student['name'] . ' has booked your session on ' . edu_slot_when($s) . ".\nTopic: " . $topic
             . "\n\nSee it in the Campus portal:\n" . edu_portal_url($org) . "\n\n" . edu_org_name($org));
    return $s;
}

/** Cancels. A student their own booking (the slot opens again); a mentor their own slot (it closes). */
function edu_slot_cancel(int $org, int $slotId, array $person, string $as): void
{
    $s = edu_slot($org, $slotId);
    $pid = (int) $person['id'];
    if (!$s) {
        throw new InvalidArgumentException('That session is not here.');
    }
    if ($as === 'student') {
        if ((int) $s['student_id'] !== $pid || $s['status'] !== 'booked') {
            throw new InvalidArgumentException('That is not one of your bookings.');
        }
        if (strtotime($s['starts_at']) < time() + EDU_CANCEL_HOURS * 3600) {
            throw new InvalidArgumentException('It starts in under ' . EDU_CANCEL_HOURS . ' hours: message your mentor instead.');
        }
        fabo_exec('UPDATE edu_slots SET status = "open", student_id = NULL, topic = NULL, booked_at = NULL WHERE org_id = ? AND id = ? AND status = "booked"', 'ii', [$org, $slotId]);
        $mentor = edu_person($org, (int) $s['mentor_id']);
        edu_send($org, (string) ($mentor['email'] ?? ''), 'Booking cancelled: ' . edu_slot_when($s),
                 $person['name'] . ' cancelled the session on ' . edu_slot_when($s) . ". The time is open for booking again.\n\n" . edu_org_name($org));
        return;
    }
    if ((int) $s['mentor_id'] !== $pid || !in_array($s['status'], ['open', 'booked'], true)) {
        throw new InvalidArgumentException('That is not one of your open sessions.');
    }
    fabo_exec('UPDATE edu_slots SET status = "cancelled" WHERE org_id = ? AND id = ?', 'ii', [$org, $slotId]);
    if ($s['status'] === 'booked' && $s['student_id']) {
        $st = edu_person($org, (int) $s['student_id']);
        edu_send($org, (string) ($st['email'] ?? ''), 'Session cancelled: ' . edu_slot_when($s),
                 'Hello ' . ($st['name'] ?? '') . ",\n\n" . $person['name'] . ' had to cancel your session on ' . edu_slot_when($s)
                 . ".\nBook another time in the Campus portal:\n" . edu_portal_url($org) . "\n\n" . edu_org_name($org));
    }
}

/** The mentor records a booked session after its start: held or missed, with private notes. */
function edu_slot_finish(int $org, int $slotId, int $mentorId, string $outcome, string $notes): void
{
    $s = edu_slot($org, $slotId);
    if (!$s || (int) $s['mentor_id'] !== $mentorId || !in_array($s['status'], ['booked', 'done', 'missed'], true) || !$s['student_id']) {
        throw new InvalidArgumentException('That is not one of your booked sessions.');
    }
    if (strtotime($s['starts_at']) > time()) {
        throw new InvalidArgumentException('Record it once it has started.');
    }
    fabo_exec('UPDATE edu_slots SET status = ?, mentor_notes = ? WHERE org_id = ? AND id = ?', 'ssii',
              [$outcome === 'missed' ? 'missed' : 'done', mb_substr(trim($notes), 0, 4000), $org, $slotId]);
    if ($outcome !== 'missed') {
        edu_award($org, (int) $s['student_id'], 'session', 'session:' . $slotId);
    }
}

/** The student rates a session that was held. */
function edu_slot_rate(int $org, int $slotId, int $studentId, int $rating, string $feedback): void
{
    $s = edu_slot($org, $slotId);
    if (!$s || (int) $s['student_id'] !== $studentId || $s['status'] !== 'done') {
        throw new InvalidArgumentException('You can rate a session once your mentor has marked it held.');
    }
    if ($rating < 1 || $rating > 5) {
        throw new InvalidArgumentException('Pick one to five stars.');
    }
    fabo_exec('UPDATE edu_slots SET rating = ?, feedback = ? WHERE org_id = ? AND id = ?', 'isii', [$rating, mb_substr(trim($feedback), 0, 2000), $org, $slotId]);
    edu_award($org, $studentId, 'feedback', 'session:' . $slotId);
}

/** Sessions across the organisation, for the desk. $f: mentor, status, when (upcoming|past). */
function edu_org_sessions(int $org, array $f = []): array
{
    $w = ['s.org_id = ?'];
    $types = 'i';
    $p = [$org];
    if (!empty($f['mentor'])) {
        $w[] = 's.mentor_id = ?';
        $types .= 'i';
        $p[] = (int) $f['mentor'];
    }
    if (!empty($f['status']) && isset(edu_slot_statuses()[$f['status']])) {
        $w[] = 's.status = ?';
        $types .= 's';
        $p[] = $f['status'];
    }
    $past = ($f['when'] ?? 'upcoming') === 'past';
    $w[] = $past ? 's.starts_at < ?' : 's.starts_at >= ?';
    $types .= 's';
    $p[] = edu_now(-3 * 3600);
    return fabo_all('SELECT s.*, m.name AS mentor_name, st.name AS student_name FROM edu_slots s
                       JOIN edu_people m ON m.id = s.mentor_id LEFT JOIN edu_people st ON st.id = s.student_id
                      WHERE ' . implode(' AND ', $w) . ' ORDER BY s.starts_at ' . ($past ? 'DESC' : 'ASC') . ' LIMIT 300', $types, $p);
}

/** Headline numbers for mentoring. */
function edu_mentoring_totals(int $org): array
{
    $now = edu_now();
    $r = fabo_one('SELECT SUM(status = "open" AND starts_at > ?) AS open_slots, SUM(status = "booked" AND starts_at > ?) AS booked,
                          SUM(status = "done" AND starts_at >= ?) AS held_30, SUM(status = "missed" AND starts_at >= ?) AS missed_30,
                          ROUND(AVG(rating), 1) AS rating, COUNT(rating) AS ratings
                     FROM edu_slots WHERE org_id = ?', 'ssssi', [$now, $now, edu_now(-30 * 86400), edu_now(-30 * 86400), $org]);
    return array_map(static fn($v) => $v === null ? 0 : $v, $r ?: []);
}
