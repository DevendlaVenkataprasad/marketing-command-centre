<?php
/**
 * The portal's heartbeat.
 *
 * Everything slow happens here: crawling a website, asking Gemini to read
 * it, writing posts, sending queued mail, and publishing whatever is due to
 * go out. Set it to run every five minutes in hPanel — Advanced → Cron Jobs:
 *
 *   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron.php
 *
 * Without it the portal still works, but only while somebody is using it:
 * jobs creep forward one at a time on page loads, and a post scheduled for
 * nine on Sunday morning goes out whenever the first person opens the site
 * on Monday. With it, things happen when they were supposed to.
 *
 * Safe to run twice at once. Every job is claimed with a conditional update
 * before it runs (lib/jobs.php) and every publication is guarded by a unique
 * key (lib/social.php), so an overlapping run finds nothing to do rather
 * than doing it again.
 */

/* Runnable from the command line and from a browser. Hostinger's cron uses
   the CLI; some hosts only offer a URL fetcher, so both work — but a browser
   request has to prove it is allowed. */
$cli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/jobs.php';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    /* A URL anybody can hit is a way to make the server work on demand. The
       key is set in config.local.php; with none set, the web route is simply
       closed and the command line still works. */
    $want = trim((string) (getenv('CRON_KEY') ?: ''));
    $got  = trim((string) ($_GET['key'] ?? ''));
    if ($want === '') {
        http_response_code(404);
        echo "Not available.\n";
        exit;
    }
    if (!hash_equals($want, $got)) {
        http_response_code(403);
        echo "No.\n";
        /* Logged, because somebody guessing at this is worth knowing about. */
        error_log('FABO cron: bad key from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        exit;
    }
    /* A browser that gives up does not take the work with it. */
    ignore_user_abort(true);
}

@set_time_limit(180);
$started = microtime(true);

fabo_boot();

$out = [];

/* 1. Mail first. It is the cheapest thing here and the most time-sensitive:
      somebody is waiting on a sign-in link. */
try {
    require_once __DIR__ . '/lib/outbox.php';
    $sent = 0;
    for ($i = 0; $i < 10; $i++) {
        $n = fabo_outbox_tick(false);
        if ($n <= 0) {
            break;
        }
        $sent += $n;
    }
    $out[] = 'mail: ' . $sent;
} catch (Throwable $e) {
    $out[] = 'mail failed: ' . $e->getMessage();
    error_log('FABO cron mail: ' . $e->getMessage());
}

/* 1b. CRM bulk email, behind the portal's own mail: it sends nothing while
       the outbox above still has anything waiting, and leaves part of the
       hourly allowance free for it. */
try {
    require_once __DIR__ . '/lib/crm_mail.php';
    $bulk = 0;
    for ($i = 0; $i < 4 && microtime(true) - $started < 60; $i++) {
        $n = fabo_crm_mail_tick(10);
        if ($n <= 0) {
            break;
        }
        $bulk += $n;
    }
    $out[] = 'crm mail: ' . $bulk;
} catch (Throwable $e) {
    $out[] = 'crm mail failed: ' . $e->getMessage();
    error_log('FABO cron crm mail: ' . $e->getMessage());
}

/* 2. Anything due to be published. Done before the queue, because a post
      that is late is a visible failure and a crawl that is late is not. */
try {
    if (is_file(__DIR__ . '/lib/social.php')) {
        require_once __DIR__ . '/lib/social.php';
        $due = fabo_social_sweep();
        $out[] = 'published: ' . $due;
    }
} catch (Throwable $e) {
    $out[] = 'publishing failed: ' . $e->getMessage();
    error_log('FABO cron publish: ' . $e->getMessage());
}

/* 3. The rest of the queue, for whatever time is left. Bounded so this run
      finishes before the next one starts. */
try {
    /* Anything that has run out of attempts and is still marked running is
       finished with, one way or the other. Said before the tick so the run
       reports the truth about it. */
    fabo_job_reap();

    /* Rows also arrive by import and from shared sheets being submitted, and
       there are years of them from before the log kept itself. Saving keeps
       it current row by row; this is what makes it right. */
    try {
        require_once __DIR__ . '/lib/masterlog.php';
        [$mAdd, $mGone] = fabo_master_reconcile(2000);
        if ($mAdd || $mGone) {
            $out[] = 'activity log: ' . $mAdd . ' added, ' . $mGone . ' removed';
        }
    } catch (Throwable $e) {
        error_log('FABO cron master log: ' . $e->getMessage());
    }

    [$ran, $bad] = fabo_job_tick(FABO_JOB_BUDGET);
    $out[] = 'jobs: ' . $ran . ' run, ' . $bad . ' failed';
} catch (Throwable $e) {
    $out[] = 'jobs failed: ' . $e->getMessage();
    error_log('FABO cron jobs: ' . $e->getMessage());
}

/* 4. Requisition dates move when the queue moves. Cheap, and wrong the
      moment somebody extends something if nothing recalculates it. */
try {
    if (is_file(__DIR__ . '/lib/requisitions.php')) {
        require_once __DIR__ . '/lib/requisitions.php';
        $moved = fabo_req_reschedule();
        if ($moved) {
            $out[] = 'requisitions moved: ' . count($moved);
        }
    }
} catch (Throwable $e) {
    error_log('FABO cron requisitions: ' . $e->getMessage());
}

/* 5. Meetings waiting for a Google Meet link (the connection had lapsed):
      make the link and send the held invitations once Google works again. */
try {
    require_once __DIR__ . '/lib/meetingpage.php';
    if (fabo_google_connected()) {
        $n = fabo_meeting_retry_pending(true);
        if ($n) {
            $out[] = 'meet links made: ' . $n;
        }
    }
} catch (Throwable $e) {
    error_log('FABO cron meet links: ' . $e->getMessage());
}

/* 6. Meeting replies. Guests answer Yes / Maybe / No in their own mail app,
      and the answers arrive in the portal's mailbox; read them in. */
try {
    require_once __DIR__ . '/lib/imapreplies.php';
    if (fabo_imap_account()) {
        [$ok, $msg, $n] = fabo_meeting_poll_replies();
        if ($n || !$ok) {
            $out[] = 'meeting replies: ' . ($ok ? $n : $msg);
        }
    }
} catch (Throwable $e) {
    error_log('FABO cron meeting replies: ' . $e->getMessage());
}

/* 7. HR reminders: leave starting tomorrow, holidays in the next three days,
      and (on Mondays) last week's unmarked attendance. Each is sent once. */
try {
    if (is_file(__DIR__ . '/lib/hr/reminders.php')) {
        require_once __DIR__ . '/lib/ui.php';
        require_once __DIR__ . '/lib/hr/reminders.php';
        $hr = hr_reminders_run();
        if (array_sum($hr)) {
            $out[] = 'hr reminders: ' . $hr['leave'] . ' leave, ' . $hr['holidays'] . ' holiday, ' . $hr['missing'] . ' attendance';
        }
    }
} catch (Throwable $e) {
    error_log('FABO cron hr reminders: ' . $e->getMessage());
}

/* 7b. Event results: remind whoever owes the results of an event that has
       ended (day 1, 3 and 7), and tell the organisation on day 10. */
try {
    require_once __DIR__ . '/lib/evresults.php';
    [$evSent, $evTold] = fabo_evres_sweep(true);
    if ($evSent || $evTold) {
        $out[] = 'event results: ' . $evSent . ' reminded, ' . $evTold . ' escalated';
    }
} catch (Throwable $e) {
    error_log('FABO cron event results: ' . $e->getMessage());
}

/* 7c. Committee reminders: once a day, each member owing votes gets one email
       listing every event still waiting on them (lib/committees.php). */
try {
    require_once __DIR__ . '/lib/committees.php';
    $cmMailed = fabo_cm_autoremind_sweep();
    if ($cmMailed) {
        $out[] = 'committee reminders: ' . $cmMailed;
    }
} catch (Throwable $e) {
    error_log('FABO cron committee reminders: ' . $e->getMessage());
}

/* 7d. Upcoming events go to each organisation's automatic committee
       (lib/committees.php); members hear once per run. */
try {
    $cmSent = fabo_cm_autoassign_sweep(true);
    if ($cmSent) {
        $out[] = 'committee auto-assign: ' . $cmSent;
    }
} catch (Throwable $e) {
    error_log('FABO cron committee auto-assign: ' . $e->getMessage());
}

/* IT Management: resolved tickets close themselves; the IT team's daily reminder digest. */
try {
    require_once __DIR__ . '/lib/ui.php';
    require_once __DIR__ . '/lib/it/app.php';
    it_cron_all();
} catch (Throwable $e) {
    error_log('FABO cron IT: ' . $e->getMessage());
}

/* Finance Portal: approval reminders and escalation, recurring journals, accrual
   reversals, customer payment reminders and budget alerts — for every organisation
   with the portal. They also run hourly when someone opens Finance. */
try {
    require_once __DIR__ . '/lib/ui.php';
    require_once __DIR__ . '/lib/fin/app.php';
    fp_cron_all();
} catch (Throwable $e) {
    error_log('FABO cron finance: ' . $e->getMessage());
}

/* 8. Incubation Management reminders: open tasks and follow-ups due tomorrow, once each. */
try {
    if (is_file(__DIR__ . '/lib/inc/apps.php')) {
        require_once __DIR__ . '/lib/ui.php';
        require_once __DIR__ . '/lib/inc/apps.php';
        // Ours, then every client organisation that has opened its own and still holds the module.
        $n = 0;
        foreach (array_merge([0], inc_orgs_installed()) as $org) {
            if (!inc_org_enabled($org)) {
                continue;
            }
            try {
                inc_start(['is_admin' => false, 'client' => ['name' => 'system'], 'access' => null], $org);
                $n += inc_reminders_run();
                inc_notify_sync();  // mark queued emails the outbox has since delivered
                // Gem answers whose scoring did not finish when they came in.
                require_once __DIR__ . '/lib/inc/gems.php';
                inc_gem_score_pending(10);
            } catch (Throwable $e) {
                error_log('FABO cron incubation reminders (org ' . $org . '): ' . $e->getMessage());
            }
        }
        // Back to ours, so the jobs after this one do not write links with the last organisation's address.
        inc_use_org(0);
        // Client addresses saved but not answering yet: switched on as soon as they do (lib/orghost.php).
        $on = fabo_org_hosts_recheck();
        if ($on) {
            $out[] = 'client web addresses switched on: ' . $on;
        }
        if ($n) {
            $out[] = 'incubation reminders: ' . $n;
        }
    }
} catch (Throwable $e) {
    error_log('FABO cron incubation reminders: ' . $e->getMessage());
}

$took = round(microtime(true) - $started, 1);
$line = date('Y-m-d H:i:s') . '  ' . implode('; ', $out) . '  (' . $took . 's)';

/* The last run is kept where the portal can show it, so "is cron working?"
   is a question the Websites panel can answer rather than a guess. */
try {
    require_once __DIR__ . '/lib/google.php';
    fabo_setting_put('cron_last_run', $line);
} catch (Throwable $e) {
    // Not worth failing the run over.
}

echo $line . "\n";
