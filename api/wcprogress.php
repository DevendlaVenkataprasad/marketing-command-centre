<?php
/**
 * How far along, and a few seconds of work while you are asking.
 *
 * The Website Content page polls this. It does two things at once on purpose:
 *
 *   It reports progress, so the panel can show a bar instead of a stage that
 *   sits unchanged for minutes while somebody wonders whether it is broken.
 *
 *   It RUNS a slice of the work. That is the part that matters on a portal
 *   whose cron has not been set up yet: the queue would otherwise only move
 *   when somebody happened to load a page, which is exactly the situation
 *   where a person is sitting watching a website that never gets read. Here,
 *   watching it makes it happen.
 *
 * Each call is bounded to a few seconds so it cannot be killed half way
 * through by the host's execution limit, which is what left a job holding a
 * claim nobody could take.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/jobs.php';
require_once __DIR__ . '/../lib/webcontent.php';
require_once __DIR__ . '/../lib/lists.php';

/* The portal's own way in: a client's link token, a signed-in portal
   session, or the administrator. Using it rather than checking one of the
   three means this endpoint cannot disagree with the page that calls it —
   and it is where the sheet permission is enforced. */
[$viewer, $key, $sheet, $perm] = fabo_endpoint('view');

/* Who is asking is known now, and nothing below writes to the session. Let
   go of it, or PHP queues the page's parallel askers behind one another and
   the posts are written one at a time after all. */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/* A few seconds of whatever is due. Only the jobs this feature owns, so a
   page poll can never find itself sending somebody's mail. */
/* One step -- a model writing a post, a page drawn by the renderer -- can
   take most of a minute. Killed half way, it waits out a lease before anyone
   picks it up again, so the request is given room to finish what it began. */
@set_time_limit(150);
ignore_user_abort(true);

$ran = [0, 0];
try {
    $ran = fabo_job_tick(8, ['wc.crawl', 'wc.extract', 'wc.generate',
                             'wc.write', 'wc.creative']);
} catch (Throwable $e) {
    error_log('FABO wcprogress tick: ' . $e->getMessage());
}

/* Then say where things stand — scoped to what this viewer may see. */
$admin = !empty($viewer['is_admin']);
$me    = $admin ? 0 : fabo_customiser($viewer);

try {
    $sites = $admin
        ? fabo_all('SELECT * FROM website_sources
                     WHERE COALESCE(hidden, 0) = 0
                       AND (state <> "GENERATED"
                            OR last_crawled_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                     ORDER BY id DESC LIMIT 10')
        : fabo_all('SELECT * FROM website_sources
                     WHERE (client_id = ? OR client_id IN
                            (SELECT id FROM clients WHERE parent_id = ?))
                       AND COALESCE(hidden, 0) = 0
                       AND (state <> "GENERATED"
                            OR last_crawled_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                     ORDER BY id DESC LIMIT 10', 'ii', [$me, $me]);
} catch (Throwable $e) {
    $sites = [];
}

/* A website part way through with nothing left to move it is put back on
   its feet, or marked stopped with the reason. See fabo_wc_heal(). */
foreach ($sites as $i => $s) {
    try {
        if (fabo_wc_heal($s) === 'stopped') {
            $sites[$i] = fabo_one('SELECT * FROM website_sources WHERE id = ?', 'i',
                                  [(int) $s['id']]) ?: $s;
        }
    } catch (Throwable $e) {
        error_log('FABO wc heal: ' . $e->getMessage());
    }
}

$out    = [];
$busy   = false;
foreach ($sites as $s) {
    [$pc, $stage, $note] = fabo_wc_progress($s);
    if (!in_array((string) $s['state'], ['GENERATED', 'FAILED'], true)) {
        $busy = true;
    }
    $out[] = [
        'id'      => (int) $s['id'],
        'host'    => (string) $s['site_host'],
        'brand'   => (string) ($s['brand_name'] ?? ''),
        'state'   => (string) $s['state'],
        'percent' => $pc,
        'stage'   => $stage,
        'note'    => $note,
        'pages'   => (int) $s['pages_crawled'],
        'error'   => (string) ($s['last_error'] ?? ''),
    ];
}

/* Work still queued but not against a website the caller can see — a crawl
   that has not had its first slice yet, for instance — still means "keep
   polling", or the page would stop asking a second before anything moved. */
$queued = fabo_job_summary($admin ? 0 : $me);
if (($queued['waiting'] ?? 0) + ($queued['running'] ?? 0) > 0) {
    $busy = true;
}

echo json_encode([
    'ok'     => true,
    'sites'  => $out,
    'busy'   => $busy,
    'ran'    => $ran[0],
    'failed' => $ran[1],
    'queue'  => $queued,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
