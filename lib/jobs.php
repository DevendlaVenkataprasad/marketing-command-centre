<?php
/**
 * Work that happens later.
 *
 * Crawling a website, asking an AI to read it and posting to LinkedIn are
 * all too slow to do while somebody waits for a page, and all three fail in
 * ways worth retrying. So they become rows: a queue in the database, run by
 * cron.php every few minutes, exactly like the mail outbox this is modelled
 * on — see lib/outbox.php, which has been doing this reliably for months.
 *
 * Three properties matter, and each one is here because of a way queues go
 * wrong:
 *
 *   A job runs once at a time.  Two cron runs overlapping, or a page load
 *   racing a cron run, must not both crawl the same site. A job is claimed
 *   with a conditional UPDATE, and the claim is what grants the right to
 *   run it; whoever does not change a row does not have the job.
 *
 *   A job that dies comes back.  A worker killed mid-job leaves the row
 *   marked running for ever unless something reclaims it, so a claim older
 *   than the lease is treated as abandoned.
 *
 *   A job that must not repeat cannot be queued twice.  Publishing is the
 *   case that matters: a unique key on the dedupe string means "post row 41
 *   to LinkedIn" can exist once, however many times it is asked for.
 */

require_once __DIR__ . '/db.php';

/**
 * How long a claim is good for before another worker may take the job.
 *
 * Short on purpose. A job is normally claimed, run and settled in seconds;
 * a claim outliving that means the worker died — usually the host cutting
 * off a page request that ran past its execution limit. Ten minutes of
 * waiting to find that out is ten minutes of a progress bar not moving, so
 * every slice of work is built to finish well inside this.
 */
const FABO_JOB_LEASE = 120;          // two minutes

/** How many times a failing job is retried before it is left alone. */
const FABO_JOB_TRIES = 5;

/** How long one cron run may spend, so it never overlaps the next. */
const FABO_JOB_BUDGET = 50;

/**
 * Put work on the queue.
 *
 * $dedupe, when given, makes the job unique: asking twice is not an error
 * and does not make a second job. Returns the job id, or the id of the job
 * that was already there.
 */
function fabo_job_add(string $kind, array $payload = [], int $clientId = 0,
                      string $dedupe = '', int $delay = 0): int
{
    $kind = trim($kind);
    if ($kind === '') {
        throw new InvalidArgumentException('A job needs a kind.');
    }
    $key  = $dedupe !== '' ? substr($kind . ':' . $dedupe, 0, 190) : '';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($key !== '') {
        /* Already queued or already running: that is the answer, not a
           second job. A job that has finished may be asked for again. */
        $have = fabo_one('SELECT id FROM job_queue
                          WHERE dedupe_key = ? AND state IN ("waiting","running")',
                         's', [$key]);
        if ($have) {
            return (int) $have['id'];
        }
        /* "A job that has finished may be asked for again" -- but the key is
           unique across every state, so a finished job's key would turn the
           new one away and nothing would run. Reading a website a second time
           stalled exactly here. Website work hands its finished keys back;
           publishing keeps them, because "post row 41" must happen once. */
        if (str_starts_with($kind, 'wc.')) {
            fabo_exec('UPDATE job_queue SET dedupe_key = NULL
                        WHERE dedupe_key = ? AND state IN ("done","failed")', 's', [$key]);
        }
    }
    try {
        return (int) fabo_exec(
            'INSERT INTO job_queue (kind, payload, client_id, dedupe_key, state, run_after)
             VALUES (?,?,?,?,"waiting",DATE_ADD(NOW(), INTERVAL ? SECOND))', 'ssisi',
            [$kind, $json, $clientId, $key !== '' ? $key : null, max(0, $delay)]);
    } catch (Throwable $e) {
        /* Two requests inserting the same dedupe key at the same instant:
           one wins on the unique index, and the loser wants the winner's id
           rather than an error. */
        if ($key !== '' && str_contains($e->getMessage(), 'Duplicate entry')) {
            $have = fabo_one('SELECT id FROM job_queue WHERE dedupe_key = ?', 's', [$key]);
            if ($have) {
                return (int) $have['id'];
            }
        }
        throw $e;
    }
}

/**
 * Take the next job that is due, if this worker can get it.
 *
 * The SELECT finds a candidate and the UPDATE claims it. Only the UPDATE
 * decides: it names the state the row must still be in, so two workers that
 * picked the same candidate produce one changed row and one zero, and the
 * one that changed nothing goes back for another candidate.
 */
function fabo_job_claim(array $kinds = []): ?array
{
    /* Every time here is the DATABASE's clock, never PHP's. The two are in
       different zones on the host (PHP in Asia/Kolkata, MySQL in UTC), and
       mixing them made every running job look abandoned the instant it was
       claimed -- so it was claimed again, and again, until its attempts ran
       out and it sat "running" for ever with the bar stuck at 5%.

       A job queued before that was fixed has its run_after written in PHP's
       clock, hours ahead; anything more than two hours out (no retry waits
       that long) is treated as due rather than left waiting. */
    $where = 'WHERE ((state = "waiting" AND (run_after <= NOW()
                                             OR run_after > DATE_ADD(NOW(), INTERVAL 2 HOUR)))
                  OR (state = "running" AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)))
                AND attempts < ?';
    $args  = [FABO_JOB_LEASE, FABO_JOB_TRIES];
    $types = 'ii';
    if ($kinds) {
        $where .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        $args   = array_merge($args, $kinds);
        $types .= str_repeat('s', count($kinds));
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $row = fabo_one('SELECT * FROM job_queue ' . $where
                      . ' ORDER BY id LIMIT 1', $types, $args);
        if (!$row) {
            return null;
        }
        $id    = (int) $row['id'];
        $token = bin2hex(random_bytes(8));
        $took  = fabo_exec(
            'UPDATE job_queue
                SET state = "running", locked_at = NOW(), lock_token = ?,
                    attempts = attempts + 1
              WHERE id = ? AND (state = "waiting"
                                OR (state = "running"
                                    AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)))',
            'sii', [$token, $id, FABO_JOB_LEASE]);
        if ($took > 0) {
            $row['lock_token'] = $token;
            $row['attempts']   = (int) $row['attempts'] + 1;
            return $row;
        }
        // Somebody else got it. Try the next one.
    }
    return null;
}

/** This job is finished and will not run again. */
function fabo_job_done(int $id, string $note = ''): void
{
    fabo_exec('UPDATE job_queue SET state = "done", finished_at = NOW(), last_error = ?
               WHERE id = ?', 'si', [mb_substr($note, 0, 500), $id]);
}

/**
 * This job did not work.
 *
 * It goes back on the queue with a widening gap between attempts, because
 * whatever refused it — a rate limit, a site that is down, an API having a
 * bad minute — is usually still refusing a second later. After the last
 * attempt it stops and waits for a person.
 */
function fabo_job_fail(int $id, string $why): void
{
    $row = fabo_one('SELECT attempts FROM job_queue WHERE id = ?', 'i', [$id]);
    $n   = (int) ($row['attempts'] ?? FABO_JOB_TRIES);
    if ($n >= FABO_JOB_TRIES) {
        fabo_exec('UPDATE job_queue SET state = "failed", finished_at = NOW(), last_error = ?
                   WHERE id = ?', 'si', [mb_substr($why, 0, 500), $id]);
        return;
    }
    $wait = min(3600, 30 * (2 ** ($n - 1)));      // 30s, 60s, 2m, 4m, 8m…
    fabo_exec('UPDATE job_queue
                  SET state = "waiting", lock_token = NULL, last_error = ?,
                      run_after = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?', 'sii',
              [mb_substr($why, 0, 500), $wait, $id]);
}

/** The handlers, by job kind. Registered by whoever owns the work. */
function fabo_job_handlers(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (['webcontent.php', 'social.php'] as $file) {
            if (is_file(__DIR__ . '/' . $file)) {
                require_once __DIR__ . '/' . $file;
            }
        }
        /* Each library registers itself by defining fabo_jobs_<library>(),
           so a library that has not been uploaded costs its own jobs and
           not the whole queue. */
        foreach (['fabo_jobs_webcontent', 'fabo_jobs_social'] as $fn) {
            if (function_exists($fn)) {
                $map = array_merge($map, $fn());
            }
        }
    }
    return $map;
}

/**
 * Run whatever is due, for as long as the budget allows.
 *
 * Returns [ran, failed]. Called by cron.php, and — in a much smaller way —
 * by a page load, so that a portal with no cron configured still makes
 * progress rather than silently doing nothing at all.
 */
function fabo_job_tick(int $budget = FABO_JOB_BUDGET, array $kinds = []): array
{
    $until    = time() + max(1, $budget);
    $handlers = fabo_job_handlers();
    $ran = 0;
    $bad = 0;

    while (time() < $until) {
        $job = fabo_job_claim($kinds);
        if (!$job) {
            break;
        }
        $id   = (int) $job['id'];
        $kind = (string) $job['kind'];
        $fn   = $handlers[$kind] ?? null;

        if (!$fn || !is_callable($fn)) {
            fabo_job_fail($id, 'No handler for "' . $kind . '".');
            $bad++;
            continue;
        }
        try {
            $payload = json_decode((string) $job['payload'], true) ?: [];
            $note    = (string) ($fn($payload, $job) ?? '');
            fabo_job_done($id, $note);
            $ran++;
        } catch (Throwable $e) {
            error_log('FABO job ' . $kind . ' #' . $id . ': ' . $e->getMessage());
            fabo_job_fail($id, $e->getMessage());
            $bad++;
        }
    }
    return [$ran, $bad];
}

/**
 * A small amount of queue work on an ordinary page load.
 *
 * A portal whose cron has not been set up still moves, just slowly and only
 * while somebody is using it. Kept to one job and one request so that the
 * person whose page load paid for it does not notice.
 */
function fabo_job_nudge(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        /* Anything a tick could actually claim, which is the same question
         * fabo_job_claim() asks: something waiting, OR something marked
         * running whose worker never came back.
         *
         * This used to ask only about waiting jobs, and that is how a site
         * could sit at the same percentage for days. A job is marked running
         * the moment it is claimed; if the request that claimed it was cut
         * short -- a PHP time limit, a closed connection, a restart -- it
         * stays marked running. The lease makes it reclaimable two minutes
         * later, but nothing reclaimed it, because the only thing that runs
         * on a portal without cron would not even look while nothing was
         * waiting. The queue knew how to recover and was never asked to. */
        $there = fabo_one('SELECT id FROM job_queue
                            WHERE ((state = "waiting"  AND (run_after <= NOW()
                                    OR run_after > DATE_ADD(NOW(), INTERVAL 2 HOUR)))
                                OR (state = "running"
                                    AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)))
                              AND attempts < ?
                            LIMIT 1', 'ii', [FABO_JOB_LEASE, FABO_JOB_TRIES]);
        if ($there) {
            fabo_job_tick(5);
        }
    } catch (Throwable $e) {
        // No table yet, or no database. A page load owes the queue nothing.
    }
    /* Posts whose time has come. cron.php does this every few minutes; this
       is the fallback for a portal without cron, so a scheduled post still
       goes out when somebody next opens a page rather than never. One at a
       time, so nobody's page waits on more than a single upload. */
    try {
        if (is_file(__DIR__ . '/social.php')) {
            require_once __DIR__ . '/social.php';
            fabo_social_sweep(1);
        }
    } catch (Throwable $e) {
        error_log('FABO nudge publish: ' . $e->getMessage());
    }
}

/**
 * Give up on jobs that have used every attempt and are still marked running.
 *
 * fabo_job_fail() marks a job failed after the last attempt, but it only runs
 * when the work returned or threw. A worker killed outright never reaches it,
 * so a job could use up all its attempts by being claimed and cut short, and
 * then match nothing: not claimable, not finished, and reported for ever as
 * in progress.
 *
 * Saying "stopped" is worth more than an honest-looking bar that will never
 * move. Whoever is waiting can then start it again.
 */
function fabo_job_reap(): int
{
    try {
        return (int) fabo_exec(
            'UPDATE job_queue
                SET state = "failed", finished_at = NOW(),
                    last_error = COALESCE(NULLIF(last_error, ""),
                                          "Stopped part way through and could not be resumed.")
              WHERE state = "running" AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND attempts >= ?',
            'ii', [FABO_JOB_LEASE, FABO_JOB_TRIES]);
    } catch (Throwable $e) {
        return 0;
    }
}

/** What the queue is doing, for the panel on the page. */
function fabo_job_summary(int $clientId = 0): array
{
    $where = $clientId > 0 ? ' WHERE client_id = ?' : '';
    $types = $clientId > 0 ? 'i' : '';
    $args  = $clientId > 0 ? [$clientId] : [];
    try {
        $rows = fabo_all('SELECT state, COUNT(*) c FROM job_queue' . $where
                       . ' GROUP BY state', $types, $args);
    } catch (Throwable $e) {
        return [];
    }
    $out = ['waiting' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
    foreach ($rows as $r) {
        $out[(string) $r['state']] = (int) $r['c'];
    }
    return $out;
}

/** Put a failed job back, from the page. */
function fabo_job_retry(int $id): void
{
    fabo_exec('UPDATE job_queue SET state = "waiting", attempts = 0, run_after = NOW(),
               lock_token = NULL, last_error = NULL WHERE id = ? AND state = "failed"',
              'i', [$id]);
}
