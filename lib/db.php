<?php
/**
 * Connection, self-install and shared helpers.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sheets.php';
// Sheets built in the portal. Loaded here so fabo_sheets() can find their
// definitions through function_exists() wherever it is called from.
require_once __DIR__ . '/sheetbuilder.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function fabo_db(): mysqli
{
    static $used = 0;
    $db = &$GLOBALS['fabo_db_link'];      // fabo_db_release() below can let it go
    if ($db instanceof mysqli) {
        // After a long wait on something else (a Gemini call can take minutes),
        // the server may have closed the connection: check, and reconnect.
        if (time() - $used > 20) {
            try {
                $db->query('SELECT 1');
            } catch (Throwable $e) {
                $db = null;
            }
        }
        if ($db instanceof mysqli) {
            $used = time();
            return $db;
        }
    }
    $used = time();
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $db->set_charset('utf8mb4');
    } catch (Throwable $e) {
        error_log('FABO db connect failed: ' . $e->getMessage());
        fabo_setup_page($e->getMessage(), (int) $e->getCode());
    }
    return $db;
}

/**
 * Hands the connection back before a long job that needs no more queries --
 * streaming a file to a slow phone, say. The host allows each account only a
 * few dozen connections at once, and a sheet opening a hundred pictures held
 * one per picture for as long as each took to arrive. A later fabo_db() call
 * simply connects again.
 */
function fabo_db_release(): void
{
    $db = $GLOBALS['fabo_db_link'] ?? null;
    $GLOBALS['fabo_db_link'] = null;
    if ($db instanceof mysqli) {
        try {
            $db->close();
        } catch (Throwable $e) {
        }
    }
}

/**
 * Renders the setup page and stops. Never a blank 500.
 *
 * The steps shown depend on what MySQL actually complained about, so the page
 * names the one thing that needs fixing instead of a generic checklist:
 *   1045  the user or password is wrong  2. 1049  the database does not exist
 *   2002/2003  the server is not reachable on that host and port.
 */
function fabo_setup_page(string $detail, int $code = 0): void
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    /* The diagnosis names the database host, its name, its user and the
       driver's own error -- useful to whoever runs the server, and a map for
       anyone else. A visitor gets one plain sentence; the detail goes to the
       log, and is shown in full only on the server itself. */
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
        error_log('FABO database unavailable: ' . $detail);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta name="robots" content="noindex,nofollow"><title>Temporarily unavailable</title>'
           . '<style>body{font:15px/1.6 system-ui,Segoe UI,sans-serif;background:#f6f7f9;color:#1d2330;'
           . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
           . 'div{background:#fff;border:1px solid #dfe3ea;border-radius:10px;padding:26px 30px;max-width:460px}</style>'
           . '</head><body><div><h1 style="font-size:19px;margin:0 0 8px">Temporarily unavailable</h1>'
           . '<p style="margin:0;color:#68707f">The portal cannot reach its database just now. Please try again '
           . 'in a few minutes. If it continues, let the administrator know.</p></div></body></html>';
        exit;
    }
    $host = htmlspecialchars(DB_HOST . ':' . DB_PORT, ENT_QUOTES);
    $name = htmlspecialchars(DB_NAME, ENT_QUOTES);
    $user = htmlspecialchars(DB_USER, ENT_QUOTES);
    $det  = htmlspecialchars($detail, ENT_QUOTES);
    $len  = strlen((string) DB_PASS);

    // Older mysqli builds report 0 and put the number in the message only.
    if ($code === 0 && preg_match('/(1045|1049|2002|2003|1044)/', $detail, $m)) {
        $code = (int) $m[1];
    }

    $cta = '';
    if (is_file(__DIR__ . '/../setup.php') && is_file(__DIR__ . '/../setup.enable')
        && in_array($code, [1045, 1044, 1049], true)) {
        $base = rtrim(APP_BASE, '/');
        $cta  = '<p class="cta"><a href="' . $base . '/setup.php">Fix this now &rarr;</a>'
              . ' Type the values from hPanel and the portal will test them before saving.</p>';
    }

    switch ($code) {
        case 1045:
        case 1044:
            $head  = 'The database rejected the sign-in';
            $sub   = 'The portal reached MySQL, but the user name or password is not accepted.';
            $steps = <<<STEPS
<li>In hPanel go to <strong>Databases &rarr; Management</strong> and check that a user
    named <code>{$user}</code> exists and is listed against the database
    <code>{$name}</code>. If the create form is still sitting there filled in,
    the database was never actually created &mdash; press <strong>Create</strong>.</li>
<li>If the user does exist, use <strong>Change password</strong> next to it and set a
    password you know.</li>
<li>Open <code>config.local.php</code> and make the <code>DB_USER</code> and
    <code>DB_PASS</code> lines in the <em>LIVE (Hostinger)</em> block match exactly
    &mdash; no spaces, no quotes around the value.
    The portal is currently sending a password of <strong>{$len} characters</strong>.</li>
<li>Reload this page. The tables build themselves on the first successful connection.</li>
STEPS;
            break;

        case 1049:
            $head  = 'The database does not exist yet';
            $sub   = 'The sign-in was accepted, but there is no database of that name.';
            $steps = <<<STEPS
<li>In hPanel go to <strong>Databases &rarr; Management</strong> and create a database
    called <code>{$name}</code>, or point <code>DB_NAME</code> in
    <code>config.local.php</code> at one that already exists.</li>
<li>Leave it empty &mdash; the portal creates all of its own tables on first use.</li>
<li>Reload this page.</li>
STEPS;
            break;

        case 2002:
        case 2003:
            $head  = 'The database server did not answer';
            $sub   = "Nothing is listening at {$host}.";
            $steps = <<<STEPS
<li>On Hostinger the host is always <code>localhost</code>. On a local machine start
    MySQL / MariaDB in XAMPP or Laragon first.</li>
<li>Check the <code>DB_HOST</code> and <code>DB_PORT</code> lines in
    <code>config.local.php</code>.</li>
<li>Reload this page.</li>
STEPS;
            break;

        default:
            $head  = 'Database setup needed';
            $sub   = 'The portal could not reach or initialise its database.';
            $steps = <<<STEPS
<li>Make sure MySQL / MariaDB is running and that the database <code>{$name}</code>
    exists. It can be completely empty.</li>
<li>Check the <em>LIVE (Hostinger)</em> block in <code>config.local.php</code> &mdash;
    the portal is using host <code>{$host}</code>, database <code>{$name}</code>,
    user <code>{$user}</code>.</li>
<li>Reload this page. The portal creates every table it needs by itself.</li>
STEPS;
    }

    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Setup needed</title>
<style>
 body{font:15px/1.6 system-ui,Segoe UI,sans-serif;background:#f6f7f9;color:#1d2330;margin:0;padding:40px 20px}
 .card{max-width:680px;margin:0 auto;background:#fff;border:1px solid #dfe3ea;border-radius:10px;padding:28px 32px}
 h1{margin:0 0 6px;font-size:21px}
 p.sub{margin:0 0 22px;color:#68707f}
 ol{padding-left:20px} li{margin:10px 0}
 code,pre{background:#f1f3f7;border:1px solid #e2e6ee;border-radius:5px;font:13px/1.5 ui-monospace,Consolas,monospace}
 code{padding:2px 6px} pre{padding:12px 14px;overflow:auto;white-space:pre-wrap}
 .det{margin-top:22px;padding:12px 14px;background:#fff6f6;border:1px solid #f0c8c8;border-radius:6px;color:#8a2020;font-size:13px}
 .cta{margin:0 0 20px;padding:12px 14px;background:#f4f2ff;border:1px solid #d9d2f5;border-radius:6px;font-size:14px}
 .cta a{color:#4b2ecc;font-weight:600;text-decoration:none}
</style></head><body><div class="card">
<h1>{$head}</h1>
<p class="sub">{$sub}</p>
{$cta}
<ol>
{$steps}
</ol>
<div class="det"><strong>Detail:</strong> {$det}</div>
</div></body></html>
HTML;
    exit;
}

/** Prepared query returning all rows. */
function fabo_all(string $sql, string $types = '', array $params = []): array
{
    $db = fabo_db();
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}

/**
 * A date window as a SQL fragment: ['sql' => ' AND col BETWEEN ? AND ?', ...].
 *
 * A window is ['from' => 'Y-m-d', 'to' => 'Y-m-d'], or null for all time.
 * Every finance figure takes one, so "this quarter" narrows the invoices,
 * the spend and the filing calendar in exactly the same way.
 */
function fabo_win_clause(?array $win, string $col): array
{
    if (!$win || empty($win['from']) || empty($win['to'])) {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    // "i.issue_date" is a table and a column, so each part is quoted on its
    // own — quoting the whole string makes MySQL look for one odd column.
    $quoted = implode('.', array_map(
        static fn($part) => '`' . str_replace('`', '', $part) . '`',
        explode('.', $col)
    ));
    return [
        'sql'    => ' AND ' . $quoted . ' BETWEEN ? AND ?',
        'types'  => 'ss',
        'params' => [$win['from'], $win['to']],
    ];
}

function fabo_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = fabo_all($sql, $types, $params);
    return $rows[0] ?? null;
}

function fabo_exec(string $sql, string $types = '', array $params = []): int
{
    $db = fabo_db();
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $id = $db->insert_id ?: $st->affected_rows;
    $st->close();
    return (int) $id;
}

/** MySQL column type for a spec column type. */
function fabo_sql_type(string $type): string
{
    switch ($type) {
        case 'id':    return "VARCHAR(24) NOT NULL DEFAULT ''";
        case 'date':  return 'DATE NULL';
        case 'int':   return 'INT NULL';
        case 'dec':   return 'DECIMAL(12,4) NULL';
        case 'money': return 'DECIMAL(14,2) NULL';
        case 'long':  return 'TEXT NULL';
        case 'url':   return 'VARCHAR(512) NULL';
        // A built sheet may carry an attachment per row; the cell holds its id.
        case 'file':
        case 'image': return 'INT UNSIGNED NULL';
        // 'images' is a gallery, not a value: the pictures are attachments
        // on the row, so the column itself stores nothing.
        case 'images': return 'TINYINT(1) NULL';
        case 'checkbox': return 'TINYINT(1) NULL';
        default:      return 'VARCHAR(255) NULL';
    }
}

/** CREATE TABLE IF NOT EXISTS for every sheet plus the internal tables. */
function fabo_install(): void
{
    $db = fabo_db();
    foreach (fabo_ddl() as $sql) {
        $db->query($sql);
    }
    fabo_migrate();
}

/** Columns added after first release; CREATE IF NOT EXISTS cannot add them. */
/**
 * A thrown database error, in words somebody can act on.
 *
 * A page that only catches InvalidArgumentException lets everything else
 * become a five-hundred with no message. The commonest of those by far is a
 * unique key: two things that cannot share a name were given one. That is
 * not a fault the reader can do anything about unless it is said, so it is
 * said, and everything else is logged and reported as itself.
 */
function fabo_db_words(Throwable $e): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'Duplicate entry')) {
        /* "Duplicate entry '2327-Brochure' for key 'u_owner_name'" — the
           part worth showing is what was typed, not the key it collided on. */
        $what = preg_match("~Duplicate entry '([^']*)'~", $msg, $m) ? $m[1] : '';
        if ($what !== '' && str_contains($what, '-')) {
            $what = substr($what, strpos($what, '-') + 1);
        }
        return $what !== ''
            ? '“' . $what . '” already exists here. Give it a different name, '
              . 'or edit the one that is already there.'
            : 'That already exists here. Give it a different name, or edit the '
              . 'one that is already there.';
    }
    error_log('FABO unexpected: ' . get_class($e) . ': ' . $msg);
    return 'That did not go through. Nothing was changed — try again, and if it '
         . 'keeps happening the detail is in the error log.';
}

/**
 * What a person may be told about a caught exception.
 *
 * InvalidArgumentException and our own RuntimeExceptions carry sentences
 * written for the reader. A database error is a RuntimeException too
 * (mysqli_sql_exception), and its text names tables and columns and quotes
 * SQL -- so anything that is not plainly ours is logged and replaced.
 */
function fabo_user_error(Throwable $e): string
{
    if ($e instanceof mysqli_sql_exception) {
        return fabo_db_words($e);
    }
    if ($e instanceof InvalidArgumentException
        || ($e instanceof RuntimeException && get_class($e) === RuntimeException::class)) {
        return $e->getMessage();
    }
    return fabo_db_words($e);
}

function fabo_migrate(): void
{
    $db   = fabo_db();
    // Checklist seeding relies on (list_name, value) being unique.
    $has = false;
    $res = $db->query("SHOW INDEX FROM `lists` WHERE Key_name = 'u_list_value'");
    if ($res && $res->num_rows) { $has = true; }
    if (!$has) {
        @$db->query('ALTER TABLE `lists` ADD UNIQUE KEY `u_list_value` (`list_name`,`value`)');
    }
    $have = [];
    $res  = $db->query('SHOW COLUMNS FROM `clients`');
    while ($r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
    $add = [
        'kind'        => "ENUM('client','team') NOT NULL DEFAULT 'client' AFTER `name`",
        'email'       => 'VARCHAR(190) NULL AFTER `kind`',
        'owner_name'  => 'VARCHAR(160) NULL AFTER `email`',
        'password_hash' => 'VARCHAR(255) NULL',
        'must_change'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'can_manage_sheets' => "TINYINT(1) NOT NULL DEFAULT 0",
        'can_manage_forms'  => "TINYINT(1) NOT NULL DEFAULT 0",
        // Sharing their own sheets as no-login intake forms (intake.php).
        'can_manage_intake' => "TINYINT(1) NOT NULL DEFAULT 0",
        'last_login_at' => 'DATETIME NULL',
        'password_hash' => 'VARCHAR(255) NULL',
        'must_change'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'last_login_at' => 'DATETIME NULL',
        'address'     => 'TEXT NULL',          // billing address, for invoices
        'image_file'  => 'VARCHAR(80) NULL',
        'image_name'  => 'VARCHAR(190) NULL',
        'image_mime'  => 'VARCHAR(120) NULL',
        // A client or team member may run their own people, and may send from
        // their own mailbox. Both are granted like sheet access. Accounts they
        // create point back at them.
        'parent_id'        => 'INT UNSIGNED NULL',
        'can_manage_people'=> 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_manage_clients'=> 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_manage_team'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_use_own_mail' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // A committee member: sees only the committee desk (committee.php)
        // and can sit on a committee. See lib/committees.php.
        'is_committee'     => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Their own mailbox, so invitations they send come from them, not us.
        'smtp_host'      => 'VARCHAR(190) NULL',
        'smtp_port'      => 'INT NULL',
        'smtp_user'      => 'VARCHAR(190) NULL',
        'smtp_pass'      => 'VARCHAR(255) NULL',
        'smtp_from'      => 'VARCHAR(190) NULL',
        'smtp_from_name' => 'VARCHAR(160) NULL',
        // A client organisation's own web address (lib/orghost.php): used for
        // its links only once a check has proved the address reaches us.
        'web_host'       => 'VARCHAR(190) NULL',
        'web_host_ok_at' => 'DATETIME NULL',
        'web_host_nonce' => 'VARCHAR(64) NULL',
    ];
    foreach ($add as $col => $def) {
        if (!isset($have[$col])) {
            $db->query('ALTER TABLE `clients` ADD COLUMN `' . $col . '` ' . $def);
        }
    }
    if (!isset($have['web_host'])) {
        @$db->query('ALTER TABLE `clients` ADD UNIQUE KEY `u_web_host` (`web_host`)');
    }
    if (!isset($have['parent_id'])) {
        @$db->query('ALTER TABLE `clients` ADD KEY `k_parent` (`parent_id`)');
    }
    // Requests recorded before this only knew the requester's name.
    $have = [];
    $res  = @$db->query('SHOW COLUMNS FROM `approval_requests`');
    while ($res && $r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
    if ($res && !isset($have['requested_by_id'])) {
        @$db->query('ALTER TABLE `approval_requests`
                     ADD COLUMN `requested_by_id` INT UNSIGNED NULL AFTER `requested_by`');
    }

    // Two calendar columns were named after the first two brands this portal
    // ever had. CHANGE keeps the data; only the column name moves.
    fabo_migrate_once('calendar_brand_columns', static function () use ($db): void {
        $have = [];
        $res  = @$db->query('SHOW COLUMNS FROM `social_calendar`');
        while ($res && $r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
        if (!$res) {
            return;
        }
        foreach (['fabo_angle' => 'brand1_angle', 'pristino_angle' => 'brand2_angle'] as $from => $to) {
            if (isset($have[$from]) && !isset($have[$to])) {
                @$db->query('ALTER TABLE `social_calendar` CHANGE `' . $from . '` `' . $to . '` TEXT NULL');
            }
        }
    });

    /* Website posts put on the schedule before approvals existed were put
       there by the claim check. Recorded as approved, once, so the grid, the
       post window and the publisher all agree on why they are going out. */
    fabo_migrate_once('webcontent_approvals', static function () use ($db): void {
        $have = @$db->query('SHOW TABLES LIKE "web_content"');
        if (!$have || $have->num_rows === 0) {
            return;
        }
        @$db->query('INSERT IGNORE INTO approvals (sheet_key, row_id, state, remarks, decided_by, decided_at)
                     SELECT "webcontent", id, "approved",
                            "On the schedule before approvals were switched on.",
                            "Auto (claims verified)", NOW()
                       FROM web_content
                      WHERE status IN ("APPROVED","SCHEDULED","PUBLISHING","PUBLISHED")');
    });

    // Rows created before ownership existed belong to nobody, which is right:
    // they are the agency's own working data, not any one client's.
    fabo_migrate_once('sheet_owner_column', static function () use ($db): void {
        foreach (fabo_sheets() as $sheet) {
            $res = @$db->query('SHOW COLUMNS FROM `' . $sheet['table'] . '`');
            if (!$res) {
                continue;
            }
            $have = [];
            while ($r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
            if (!isset($have['client_id'])) {
                @$db->query('ALTER TABLE `' . $sheet['table'] . '` ADD COLUMN `client_id` INT UNSIGNED NULL');
                @$db->query('ALTER TABLE `' . $sheet['table'] . '` ADD KEY `k_owner` (`client_id`)');
            }
        }
    });

    // A Client Success Partner is a role rows get assigned to, the same as
    // Brand Manager or Agency Partner beside it. It goes into the shared
    // set, so every organisation that has not customised the list sees it.
    $have = fabo_one('SELECT id FROM lists WHERE list_name = ? AND value = ? AND client_id = 0',
                     'ss', ['Owner / Team', 'Client Success Partner']);
    if (!$have) {
        $max = (int) (fabo_one('SELECT MAX(sort_order) m FROM lists
                                WHERE list_name = ? AND client_id = 0',
                               's', ['Owner / Team'])['m'] ?? 0);
        try {
            fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id)
                       VALUES (?,?,?,1,0)', 'ssi',
                      ['Owner / Team', 'Client Success Partner', $max + 10]);
        } catch (Throwable $e) {
            error_log('FABO seed Client Success Partner: ' . $e->getMessage());
        }
    }

    // The programme calendar's own dropdowns. Seeded with a sensible starting
    // set rather than left empty, because an empty dropdown reads as broken;
    // every one of them is editable from the sheet like any other list, and a
    // value removed here is not re-seeded (the check is per list, not per
    // value, so a deliberately emptied list stays empty).
    foreach ([
        'Sector'         => ['Retail', 'FMCG', 'Fashion & Lifestyle', 'Food & Beverage',
                             'Health & Wellness', 'Education', 'Technology', 'Real Estate',
                             'Automotive', 'Hospitality', 'Other'],
        'Target Audience'=> ['Existing Customers', 'New Customers', 'Walk-ins', 'Students',
                             'Working Professionals', 'Homemakers', 'Trade & Channel',
                             'Media & Press', 'Influencers', 'Internal Team'],
        'Partner'        => ['None', 'Co-branded Partner', 'Media Partner', 'Venue Partner',
                             'Sponsor', 'Agency', 'Influencer Agency', 'Channel Partner'],
        'Funding Source' => ['Marketing Budget', 'Brand Budget', 'Co-funded with Partner',
                             'Sponsored', 'Client Funded', 'Other'],
        'Sign-off'       => ['Pending', 'Approved', 'Rejected'],
        // Request Design & Publicity Support, on an event (lib/eventdesign.php).
        'Design Support' => ['Yes', 'No'],

        // The requisition form's own dropdowns. 'Requisition Type' is not
        // seeded here: it is kept in step with the turnaround table, which
        // owns it — see fabo_req_sync_list().
        'Requisition Purpose' => ['Event', 'Programme', 'Campaign', 'Announcement',
                                  'Recruitment', 'Internal', 'Other'],
        'Delivery Mode'       => ['Online', 'Offline', 'Hybrid'],
        'Requisition Status'  => ['Queued', 'In Progress', 'In Review', 'On Hold',
                                  'Delivered', 'Cancelled', 'Rejected'],

        /* The Website Content sheet's own dropdowns. These are the vocabulary
           the AI is told to choose from, so the two must stay in step: a
           value added here without adding it to the prompt in
           lib/webcontent.php will never be chosen, and a value the prompt
           offers that is missing here lands in a cell the dropdown cannot
           show. */
        'Content Focus'     => ['Brand Awareness', 'Product Awareness', 'Lead Generation',
                                'Education', 'Thought Leadership', 'Customer Success',
                                'Product Feature', 'Engagement', 'Trust Building',
                                'Conversion', 'Community', 'Event Promotion'],
        'Content Pillar'    => ['Educational', 'Product', 'Industry Insights',
                                'Thought Leadership', 'Customer Stories', 'Brand Story',
                                'Tips & Advice', 'Case Studies', 'Resources'],
        'Content Angle'     => ['Problem to Solution', 'How-to', 'Myth vs Reality',
                                'Before vs After', 'Data/Insight', 'Customer Story',
                                'Expert Advice', 'Product Demonstration', 'Checklist',
                                'Mistakes to Avoid', 'Industry Trend'],
        'Content Objective' => ['Awareness', 'Engagement', 'Education', 'Consideration',
                                'Conversion', 'Retention'],
        'Funnel Stage'      => ['TOFU', 'MOFU', 'BOFU'],
        'Social Platform'   => ['LinkedIn', 'Instagram'],
        /* LinkedIn's post kinds. Event, Job, Article and Newsletter go out as
           link posts: LinkedIn's API does not let an app create those
           natively. See fabo_linkedin_post_row() in lib/social.php. */
        'LinkedIn Post Type' => ['Text', 'Image', 'Multi-image', 'Carousel', 'Document',
                                 'Video', 'Poll', 'Article', 'Newsletter', 'Event', 'Job'],
        'Poll Duration'      => ['1 day', '3 days', '1 week', '2 weeks'],
        'CTA'               => ['Learn More', 'Read the Guide', 'Book a Demo', 'Contact Us',
                                'Start Free', 'Download', 'Register', 'Visit Website',
                                'Try Now'],
        /* The state machine, in order. Nothing publishes unless a person has
           moved it to APPROVED or SCHEDULED, which is the whole point of
           having states rather than a flag. */
        'Web Content Status' => ['DISCOVERED', 'EXTRACTED', 'READY_FOR_GENERATION',
                                 'GENERATING', 'GENERATED', 'REVIEW_REQUIRED', 'APPROVED',
                                 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'FAILED',
                                 'CANCELLED'],
    ] as $list => $values) {
        try {
            $any = fabo_one('SELECT id FROM lists WHERE list_name = ? AND client_id = 0 LIMIT 1',
                            's', [$list]);
            if ($any) {
                continue;                       // already seeded, or deliberately changed
            }
            $order = 10;
            foreach ($values as $v) {
                fabo_exec('INSERT INTO lists (list_name, value, sort_order, active, client_id)
                           VALUES (?,?,?,1,0)', 'ssi', [$list, $v, $order]);
                $order += 10;
            }
        } catch (Throwable $e) {
            error_log('FABO seed list ' . $list . ': ' . $e->getMessage());
        }
    }

    /* The turnaround each kind of requisition gets. Seeded once, then it is
       the desk's to change on the Sheet Management page; the dropdown on the
       sheet is kept in step with it rather than maintained separately. */
    try {
        require_once __DIR__ . '/requisitions.php';
        fabo_req_install_types();
    } catch (Throwable $e) {
        error_log('FABO requisition types: ' . $e->getMessage());
    }

    /* Budget approvals. The first version of the budget form judged an amount
       and stopped there; it now puts anything over the ceiling to the owner.
       A portal that already has budget_lines gets the four columns added
       rather than a rebuild, so nothing already asked for is lost. */
    foreach ([['budget_lines', 'decision',
               "ENUM('none','draft','pending','approved','rejected') NOT NULL DEFAULT 'none'"],
              ['budget_lines', 'remarks',     'VARCHAR(500) NULL'],
              ['budget_lines', 'decided_by',  'VARCHAR(160) NULL'],
              ['budget_lines', 'decided_at',  'DATETIME NULL']] as [$tbl, $col, $def]) {
        try {
            $res  = @$db->query('SHOW COLUMNS FROM `' . $tbl . '`');
            $have = [];
            while ($res && $r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
            if ($res && !isset($have[$col])) {
                @$db->query('ALTER TABLE `' . $tbl . '` ADD COLUMN `' . $col . '` ' . $def);
            }
        } catch (Throwable $e) {
            // The table is not there yet; the DDL above will build it whole.
        }
    }

    /* The enum gained 'draft' when entering an amount stopped being the same
       act as asking for it. MODIFY is safe to repeat and keeps every value. */
    try {
        $res = @$db->query("SHOW COLUMNS FROM `budget_lines` LIKE 'decision'");
        $r   = $res ? $res->fetch_assoc() : null;
        if ($r && !str_contains((string) $r['Type'], "'draft'")) {
            @$db->query("ALTER TABLE `budget_lines` MODIFY `decision`
                         ENUM('none','draft','pending','approved','rejected')
                         NOT NULL DEFAULT 'none'");
        }
    } catch (Throwable $e) {
        // Not built yet; the DDL above makes it with the full set.
    }

    // Event departments: a row can say which department contributed it, and
    // an image can say which one uploaded it. Both are plain nullable
    // columns — nothing that already exists is touched.
    foreach ([['events', 'department_id', 'INT UNSIGNED NULL'],
              ['attachments', 'department_id', 'INT UNSIGNED NULL']] as [$tbl, $col, $def]) {
        try {
            $res  = @$db->query('SHOW COLUMNS FROM `' . $tbl . '`');
            $have = [];
            while ($res && $r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
            if ($res && !isset($have[$col])) {
                @$db->query('ALTER TABLE `' . $tbl . '` ADD COLUMN `' . $col . '` ' . $def);
                @$db->query('ALTER TABLE `' . $tbl . '` ADD KEY `k_dept` (`' . $col . '`)');
            }
        } catch (Throwable $e) {
            error_log('FABO department column on ' . $tbl . ': ' . $e->getMessage());
        }
    }

    // Dropdown options and column wording used to be one global set, so a
    // client changing either changed it for everybody. Both are now per
    // organisation; existing rows keep client_id 0 and stay the shared
    // default, which is what they already were.
    //
    // Deliberately not wrapped in fabo_migrate_once: each step checks the
    // state it is about to change, so running it twice is harmless and a
    // half-finished run is finished on the next load. A once-only flag hid
    // exactly that failure while this was being written.
    $cl = [];
    $res = @$db->query('SHOW COLUMNS FROM `column_labels`');
    while ($res && $r = $res->fetch_assoc()) { $cl[$r['Field']] = true; }
    if ($res) {
        if (!isset($cl['client_id'])) {
            $db->query('ALTER TABLE `column_labels`
                        ADD COLUMN `client_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `label`');
        }
        // The unique key has to take the owner in, or two organisations
        // renaming the same column collide. Its name differs between
        // installs, so what it covers is read rather than assumed.
        $byName = [];
        $ix = @$db->query('SHOW INDEX FROM `column_labels` WHERE Non_unique = 0');
        while ($ix && $r = $ix->fetch_assoc()) {
            if ($r['Key_name'] !== 'PRIMARY') {
                $byName[$r['Key_name']][] = $r['Column_name'];
            }
        }
        $correct = false;
        foreach ($byName as $cols) {
            if (in_array('client_id', $cols, true)) { $correct = true; }
        }
        if (!$correct) {
            foreach (array_keys($byName) as $name) {
                try {
                    $db->query('ALTER TABLE `column_labels` DROP INDEX `'
                               . str_replace('`', '', $name) . '`');
                } catch (Throwable $e) {
                    error_log('FABO drop column_labels index: ' . $e->getMessage());
                }
            }
            try {
                $db->query('ALTER TABLE `column_labels`
                            ADD UNIQUE KEY `u_col` (`sheet_key`,`field`,`client_id`)');
            } catch (Throwable $e) {
                error_log('FABO add column_labels index: ' . $e->getMessage());
            }
        }
    }
    /* A department is no longer created against an event: the sheet it is
       sent is its own to fill in, and the events on it come from the
       department. The column stays, nullable, for the links issued before
       that changed — those still point at their event. */
    try {
        $res = @$db->query('SHOW COLUMNS FROM `event_departments` LIKE "event_row_id"');
        if ($res && ($r = $res->fetch_assoc()) && strtoupper((string) $r['Null']) === 'NO') {
            $db->query('ALTER TABLE `event_departments`
                        MODIFY COLUMN `event_row_id` INT UNSIGNED NULL');
        }
    } catch (Throwable $e) {
        error_log('FABO event_departments event_row_id: ' . $e->getMessage());
    }

    /* When a shared sheet's rows were handed in.
     *
     * A row on somebody's shared sheet is a draft: it is on the Events table
     * already, but it is not part of the main sheet until they press Submit.
     * NULL means still being filled in.
     *
     * Rows contributed before this existed are stamped as already handed in,
     * because they were already showing on the main sheet and must not
     * vanish from under anybody. */
    try {
        $res = @$db->query('SHOW COLUMNS FROM `events` LIKE "dept_submitted_at"');
        if ($res && $res->num_rows === 0) {
            $db->query('ALTER TABLE `events`
                        ADD COLUMN `dept_submitted_at` DATETIME NULL');
            $db->query('ALTER TABLE `events` ADD KEY `k_handed_in` (`dept_submitted_at`)');
            $db->query('UPDATE `events`
                           SET `dept_submitted_at` = COALESCE(`created_at`, NOW())
                         WHERE `department_id` IS NOT NULL');
        }
    } catch (Throwable $e) {
        error_log('FABO events dept_submitted_at: ' . $e->getMessage());
    }

    /* Which hub or spoke a department's sheet was shared for. Nullable, and
       added here as well as in the CREATE above: a portal that already has
       the table would never see a column added only there. The links issued
       before this carry none and go on working. */
    try {
        $res = @$db->query('SHOW COLUMNS FROM `event_departments` LIKE "hub_spoke"');
        if ($res && $res->num_rows === 0) {
            $db->query('ALTER TABLE `event_departments`
                        ADD COLUMN `hub_spoke` VARCHAR(120) NULL AFTER `name`');
            $db->query('ALTER TABLE `event_departments` ADD KEY `k_hub` (`hub_spoke`)');
        }
    } catch (Throwable $e) {
        error_log('FABO event_departments hub_spoke: ' . $e->getMessage());
    }

    /* The hub and its spokes, so the dropdown is not empty on a portal that
       has never set them. The hub is first; the spokes follow.
     *
     * Written only when the list is missing, or when it still holds exactly
     * the two words this shipped with before ("Hub" and "Spoke") -- those
     * named what a place is rather than which place it is, and nobody can
     * have meant to keep them. Anything else is somebody's own list and is
     * left alone.
     */
    try {
        $places = ['Amaravati', 'Vishakapatnam', 'Vijayawada',
                   'Rajamahendravaram', 'Tirupati', 'Ananthapuram'];

        $now = [];
        $res = @$db->query('SELECT value FROM lists WHERE list_name = "Hub / Spoke"
                             AND client_id = 0 ORDER BY sort_order');
        if ($res) { while ($r = $res->fetch_assoc()) { $now[] = (string) $r['value']; } }

        $untouched = $now === [] || $now === ['Hub', 'Spoke'];
        if ($untouched) {
            $db->query('DELETE FROM lists WHERE list_name = "Hub / Spoke" AND client_id = 0');
            $n = 0;
            foreach ($places as $v) {
                $n += 10;
                $st = $db->prepare('INSERT INTO lists (list_name, value, sort_order, active, client_id)
                                    VALUES ("Hub / Spoke", ?, ?, 1, 0)');
                $st->bind_param('si', $v, $n);
                $st->execute();
            }
        }
    } catch (Throwable $e) {
        error_log('FABO Hub / Spoke list: ' . $e->getMessage());
    }

    /* A crawl keeps its place between slices. Added here as well as in the
       CREATE above, because a portal that already ran the earlier version
       has the table and would never see the new columns otherwise. */
    try {
        $res = @$db->query('SHOW TABLES LIKE "website_sources"');
        if ($res && $res->num_rows > 0) {
            foreach (['frontier' => 'MEDIUMTEXT NULL',
                      'robots_rules' => 'TEXT NULL',
                      'plan' => 'MEDIUMTEXT NULL',
                      'posts_planned' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                      'posts_written' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                      // When the posts go out, how often, and whether by themselves.
                      'post_time' => 'VARCHAR(5) NULL',
                      'post_every' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                      'auto_post' => 'TINYINT(1) NOT NULL DEFAULT 0',
                      // Where the company's logo was found, best first (JSON).
                      'logo' => 'TEXT NULL',
                      // Taken off the Websites panel; its posts stay.
                      'hidden' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $col => $type) {
                $have = @$db->query('SHOW COLUMNS FROM `website_sources` LIKE "' . $col . '"');
                if ($have && $have->num_rows === 0) {
                    $db->query('ALTER TABLE `website_sources` ADD COLUMN `'
                               . $col . '` ' . $type);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('FABO website_sources frontier: ' . $e->getMessage());
    }

    /* An event's committee, by id: the Committee column shows its name, the
       votes hang on this. Not a sheet column -- nobody types an id -- so it is
       added here. See lib/committees.php. */
    try {
        $res = @$db->query('SHOW TABLES LIKE "events"');
        if ($res && $res->num_rows > 0) {
            $have = @$db->query('SHOW COLUMNS FROM `events` LIKE "committee_id"');
            if ($have && $have->num_rows === 0) {
                $db->query('ALTER TABLE `events` ADD COLUMN `committee_id` INT UNSIGNED NULL');
                $db->query('ALTER TABLE `events` ADD KEY `k_committee` (`committee_id`)');
            }
        }
    } catch (Throwable $e) {
        error_log('FABO events committee_id: ' . $e->getMessage());
    }

    /* How many members must approve, for a committee whose rule is 'count'
       ("a set number must approve"). See lib/committees.php. */
    try {
        $res = @$db->query('SHOW TABLES LIKE "committees"');
        if ($res && $res->num_rows > 0) {
            $have = @$db->query('SHOW COLUMNS FROM `committees` LIKE "required"');
            if ($have && $have->num_rows === 0) {
                $db->query('ALTER TABLE `committees` ADD COLUMN `required` INT UNSIGNED NULL AFTER `rule`');
            }
        }
    } catch (Throwable $e) {
        error_log('FABO committees required: ' . $e->getMessage());
    }

    /* A budget document is a quotation before the event and an invoice after
       it (attachments.doc_kind), and a budget line records whose approval it
       carries: CEO or Committee (budget_lines.approved_by). See lib/budget.php. */
    foreach ([['attachments', 'doc_kind', 'VARCHAR(16) NULL AFTER `caption`'],
              ['budget_lines', 'approved_by', 'VARCHAR(40) NULL AFTER `decided_at`']] as [$tbl, $col, $def]) {
        try {
            $res = @$db->query('SHOW TABLES LIKE "' . $tbl . '"');
            if ($res && $res->num_rows > 0) {
                $have = @$db->query('SHOW COLUMNS FROM `' . $tbl . '` LIKE "' . $col . '"');
                if ($have && $have->num_rows === 0) {
                    $db->query('ALTER TABLE `' . $tbl . '` ADD COLUMN `' . $col . '` ' . $def);
                }
            }
        } catch (Throwable $e) {
            error_log('FABO ' . $tbl . ' ' . $col . ': ' . $e->getMessage());
        }
    }

    /* web_content carries a pointer back to the website it was written from.
       It is not a column on the sheet — nobody wants a numeric id in the grid
       — so the table builder, which works from the sheet definition, does not
       know about it and it is added here. */
    try {
        $res = @$db->query('SHOW TABLES LIKE "web_content"');
        if ($res && $res->num_rows > 0) {
            $have = @$db->query('SHOW COLUMNS FROM `web_content` LIKE "site_id"');
            if ($have && $have->num_rows === 0) {
                $db->query('ALTER TABLE `web_content`
                            ADD COLUMN `site_id` INT UNSIGNED NOT NULL DEFAULT 0');
                $db->query('ALTER TABLE `web_content` ADD KEY `k_site` (`site_id`)');
            }
        }
    } catch (Throwable $e) {
        error_log('FABO web_content site_id: ' . $e->getMessage());
    }

    /* A form link belongs to whoever made it, so a client's public form
       feeds their queue and appears on their desk alone. */
    try {
        $res = @$db->query('SHOW COLUMNS FROM `form_links` LIKE "client_id"');
        if ($res && $res->num_rows === 0) {
            $db->query('ALTER TABLE `form_links`
                        ADD COLUMN `client_id` INT UNSIGNED NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        error_log('FABO form_links client_id: ' . $e->getMessage());
    }

    /* A live sheet can be shared for one hub or spoke as well as a
       department; its rows are stamped with it. See lib/shares.php. */
    try {
        $res = @$db->query('SHOW COLUMNS FROM `sheet_shares` LIKE "hub_spoke"');
        if ($res && $res->num_rows === 0) {
            $db->query('ALTER TABLE `sheet_shares` ADD COLUMN `hub_spoke` VARCHAR(120) NULL AFTER `department`');
        }
    } catch (Throwable $e) {
        error_log('FABO sheet_shares hub_spoke: ' . $e->getMessage());
    }

    /* Requisition types belong to an organisation now, so a client can run
       their own queue with their own turnarounds. Everything that existed
       before this stays at owner 0 — ours, and the set a client sees until
       they make one of their own. Same shape as the column_labels step
       above: add the column, then widen the unique key so two organisations
       can both have a type called "Video". */
    $rt = [];
    $res = @$db->query('SHOW COLUMNS FROM `requisition_types`');
    while ($res && $r = $res->fetch_assoc()) { $rt[$r['Field']] = true; }
    if ($res) {
        if (!isset($rt['client_id'])) {
            $db->query('ALTER TABLE `requisition_types`
                        ADD COLUMN `client_id` INT UNSIGNED NOT NULL DEFAULT 0');
        }
        $byName = [];
        $ix = @$db->query('SHOW INDEX FROM `requisition_types` WHERE Non_unique = 0');
        while ($ix && $r = $ix->fetch_assoc()) {
            if ($r['Key_name'] !== 'PRIMARY') {
                $byName[$r['Key_name']][] = $r['Column_name'];
            }
        }
        $correct = false;
        foreach ($byName as $cols) {
            if (in_array('client_id', $cols, true)) { $correct = true; }
        }
        if (!$correct) {
            foreach (array_keys($byName) as $name) {
                try {
                    $db->query('ALTER TABLE `requisition_types` DROP INDEX `'
                               . str_replace('`', '', $name) . '`');
                } catch (Throwable $e) {
                    error_log('FABO drop requisition_types index: ' . $e->getMessage());
                }
            }
            try {
                $db->query('ALTER TABLE `requisition_types`
                            ADD UNIQUE KEY `u_owner_name` (`client_id`,`name`)');
            } catch (Throwable $e) {
                error_log('FABO add requisition_types index: ' . $e->getMessage());
            }
        }
    }

    /* A user ID per account: an address can be shared, so it cannot be what
       identifies one. Backfilled for everybody who predates the column. */
    try {
        $res  = @$db->query('SHOW COLUMNS FROM `clients` LIKE "login_id"');
        if ($res && $res->num_rows === 0) {
            $db->query('ALTER TABLE `clients` ADD COLUMN `login_id` VARCHAR(24) NULL');
            $db->query('ALTER TABLE `clients` ADD UNIQUE KEY `u_login_id` (`login_id`)');
        }
        require_once __DIR__ . '/auth.php';
        foreach (fabo_all('SELECT id FROM clients
                           WHERE login_id IS NULL OR login_id = ""') as $r) {
            fabo_login_id((int) $r['id']);
        }
    } catch (Throwable $e) {
        error_log('FABO login id column: ' . $e->getMessage());
    }

    // Rows made before lists carried an owner are NULL, and NULL matches
    // nothing — they would vanish from every dropdown. They are the shared
    // defaults, so they become 0.
    @$db->query('UPDATE `lists` SET `client_id` = 0 WHERE `client_id` IS NULL');

    // And the same index problem: a unique key on (list_name, value) stops
    // two organisations from having the same option, which is exactly what
    // giving each its own copy of a list requires.
    $byName = [];
    $ix = @$db->query('SHOW INDEX FROM `lists` WHERE Non_unique = 0');
    while ($ix && $r = $ix->fetch_assoc()) {
        if ($r['Key_name'] !== 'PRIMARY') { $byName[$r['Key_name']][] = $r['Column_name']; }
    }
    if ($byName) {
        $correct = false;
        foreach ($byName as $cols) {
            if (in_array('client_id', $cols, true)) { $correct = true; }
        }
        if (!$correct) {
            foreach (array_keys($byName) as $name) {
                try {
                    $db->query('ALTER TABLE `lists` DROP INDEX `'
                               . str_replace('`', '', $name) . '`');
                } catch (Throwable $e) {
                    error_log('FABO drop lists index: ' . $e->getMessage());
                }
            }
            try {
                $db->query('ALTER TABLE `lists`
                            ADD UNIQUE KEY `u_list_value` (`list_name`,`value`,`client_id`)');
            } catch (Throwable $e) {
                error_log('FABO add lists index: ' . $e->getMessage());
            }
        }
    }

    fabo_migrate_once('delete_head', static function () use ($db): void {
        $have = [];
        $res  = @$db->query('SHOW COLUMNS FROM `delete_requests`');
        while ($res && $r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
        if ($res && !isset($have['head_id'])) {
            @$db->query('ALTER TABLE `delete_requests`
                         ADD COLUMN `head_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `requested_by_name`,
                         ADD KEY `k_head` (`head_id`,`state`)');
        }
    });

    fabo_migrate_once('invoice_address', static function () use ($db): void {
        $res = @$db->query('SHOW COLUMNS FROM `invoices`');
        if (!$res) {
            return;
        }
        $have = [];
        while ($r = $res->fetch_assoc()) { $have[$r['Field']] = true; }
        if (!isset($have['client_address'])) {
            @$db->query('ALTER TABLE `invoices` ADD COLUMN `client_address` TEXT NULL AFTER `client_email`');
        }
    });

    fabo_migrate_sheets();

    // Running your own clients and sending from your own mailbox became a
    // client-only pair. Anyone on our team who was given them loses them.
    fabo_migrate_once('team_rights', static function () use ($db): void {
        $db->query("UPDATE `clients` SET can_manage_people = 0, can_use_own_mail = 0
                    WHERE kind = 'team'");
    });

    /* Creating clients and creating team members were one checkbox. They are
       two decisions — plenty of accounts should run a client list and no team
       — so they became two rights.

       Everyone who held the old one gets both, because taking something away
       during an upgrade is a support call, and the person who granted it
       meant them to have it. */
    fabo_migrate_once('split_people_rights', static function () use ($db): void {
        $db->query("UPDATE `clients`
                       SET can_manage_clients = 1, can_manage_team = 1
                     WHERE can_manage_people = 1 AND kind = 'client'");
        $db->query("UPDATE `clients` SET can_manage_clients = 0, can_manage_team = 0
                     WHERE kind = 'team'");
    });
}

/**
 * Runs a one-off data fix exactly once, ever.
 *
 * Schema changes are safe to re-run; a data correction usually is not, and
 * doing it on every page load would be waste even when it is. The marker is
 * kept beside the schema state.
 */
function fabo_migrate_once(string $key, callable $fix): void
{
    fabo_schema_state_table();
    $seen = fabo_one('SELECT v FROM schema_state WHERE k = ?', 's', ['once_' . $key]);
    if ($seen) {
        return;
    }
    $fix();
    fabo_exec('INSERT INTO schema_state (k, v) VALUES (?,?)
               ON DUPLICATE KEY UPDATE v = VALUES(v)', 'ss', ['once_' . $key, date('c')]);
}

/** The little table that remembers what has already been migrated. */
function fabo_schema_state_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    fabo_db()->query('CREATE TABLE IF NOT EXISTS `schema_state` (
  `k` VARCHAR(40) NOT NULL,
  `v` VARCHAR(190) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * Adds columns that were given to a sheet after its table was built.
 *
 * CREATE TABLE IF NOT EXISTS silently leaves an existing table alone, so a new
 * column in lib/sheets.php would never appear. Rather than inspect a dozen
 * tables on every page load, the shape of the spec is hashed and the check runs
 * only when that hash changes — which is to say, only after a real edit.
 */
function fabo_migrate_sheets(): void
{
    $db = fabo_db();
    fabo_schema_state_table();

    $spec = [];
    foreach (fabo_sheets() as $key => $sheet) {
        foreach ($sheet['columns'] as $c) {
            if ($c[2] !== 'computed') {
                $spec[] = $sheet['table'] . '.' . $c[1] . ':' . $c[2];
            }
        }
    }
    $hash = md5(implode('|', $spec));

    $seen = fabo_one('SELECT v FROM schema_state WHERE k = ?', 's', ['sheet_columns']);
    if (($seen['v'] ?? '') === $hash) {
        return;                    // nothing has changed since the last check
    }

    foreach (fabo_sheets() as $key => $sheet) {
        $res = @$db->query('SHOW COLUMNS FROM `' . $sheet['table'] . '`');
        if (!$res) {
            continue;              // the table is about to be created anyway
        }
        $have = [];
        while ($r = $res->fetch_assoc()) {
            $have[$r['Field']] = true;
        }
        $after = null;
        foreach ($sheet['columns'] as $c) {
            if ($c[2] === 'computed') {
                continue;
            }
            if (!isset($have[$c[1]])) {
                $sql = 'ALTER TABLE `' . $sheet['table'] . '` ADD COLUMN `' . $c[1] . '` '
                     . fabo_sql_type($c[2]) . ($after ? ' AFTER `' . $after . '`' : '');
                @$db->query($sql);
            }
            $after = $c[1];
        }
    }

    fabo_exec('INSERT INTO schema_state (k, v) VALUES (?,?)
               ON DUPLICATE KEY UPDATE v = VALUES(v)', 'ss', ['sheet_columns', $hash]);
}

/** The full DDL, as statements. Shared by the self-installer and schema.sql. */
function fabo_ddl(): array
{
    $out = [];
    foreach (fabo_sheets() as $key => $sheet) {
        $cols = ["  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT"];
        foreach ($sheet['columns'] as $c) {
            if ($c[2] === 'computed') {
                continue; // never stored
            }
            $cols[] = '  `' . $c[1] . '` ' . fabo_sql_type($c[2]);
        }
        $cols[] = '  `client_id` INT UNSIGNED NULL';
        $cols[] = '  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $cols[] = '  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        $cols[] = '  PRIMARY KEY (`id`)';
        $cols[] = '  KEY `k_owner` (`client_id`)';
        if (!empty($sheet['id_field'])) {
            $cols[] = '  KEY `k_bizid` (`' . $sheet['id_field'] . '`)';
        }
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $sheet['table'] . "` (\n"
             . implode(",\n", $cols)
             . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $out[] = $sql;
    }

    $out[] = "CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `kind` ENUM('client','team') NOT NULL DEFAULT 'client',
  `email` VARCHAR(190) NULL,
  `login_id` VARCHAR(24) NULL,
  `owner_name` VARCHAR(160) NULL,
  `organisation` VARCHAR(160) NULL,
  `contact_note` TEXT NULL,
  `token` CHAR(32) NOT NULL,
  `brand_scope` VARCHAR(32) NOT NULL DEFAULT 'both',
  `business_unit_scope` VARCHAR(32) NOT NULL DEFAULT 'both',
  `expires_at` DATETIME NULL,
  `access_requested_at` DATETIME NULL,
  `access_request_note` TEXT NULL,
  `revoked_at` DATETIME NULL,
  `open_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_opened_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `client_sheet_access` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `sheet_key` VARCHAR(32) NOT NULL,
  `permission` ENUM('view','edit') NOT NULL DEFAULT 'view',
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_client_sheet` (`client_id`,`sheet_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `form_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `token` CHAR(32) NOT NULL,
  `preset_brand` VARCHAR(64) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at` DATETIME NULL,
  `submit_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_submitted_at` DATETIME NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `column_labels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `field` VARCHAR(64) NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  -- 0 is the shared default; a client id is that organisation's own
  -- wording, which only they see.
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_sheet_field` (`sheet_key`,`field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";


    $out[] = "CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(190) NOT NULL,
  `stored_name` VARCHAR(80) NOT NULL,
  `mime` VARCHAR(120) NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `caption` VARCHAR(255) NULL,
  `doc_kind` VARCHAR(16) NULL,
  `uploaded_by` VARCHAR(160) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `approvals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `state` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `remarks` TEXT NULL,
  `decided_by` VARCHAR(160) NULL,
  `decided_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /*
     * Who has been asked to decide on which row. Without a row in here a client
     * can see an approval state but cannot change it — approving is never open
     * on the whole sheet, only on the rows someone chose to send.
     */
    $out[] = "CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `note` TEXT NULL,
  `requested_by` VARCHAR(160) NOT NULL,
  `requested_by_id` INT UNSIGNED NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_row_client` (`sheet_key`,`row_id`,`client_id`),
  KEY `k_client` (`client_id`,`responded_at`),
  KEY `k_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /*
     * One-time codes for a forgotten password. Only the hash of the code is
     * kept, it expires, and it counts its own attempts — so a code that leaks
     * from a mailbox is still short-lived and cannot be brute-forced.
     */
    $out[] = "CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `verified_at` DATETIME NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_client` (`client_id`,`used_at`),
  KEY `k_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /*
     * Deleting is not something a client or a team member does. They ask, and
     * the portal's administrator decides — so a row or a document can never
     * leave the organisation's records on one person's say-so.
     */
    $out[] = "CREATE TABLE IF NOT EXISTS `delete_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` ENUM('row','file') NOT NULL DEFAULT 'row',
  `sheet_key` VARCHAR(32) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `attachment_id` INT UNSIGNED NULL,
  `label` VARCHAR(190) NOT NULL,
  `requested_by` INT UNSIGNED NULL,
  `requested_by_name` VARCHAR(160) NOT NULL,
  -- Who decides it: 0 is the administrator, otherwise the client who heads
  -- the organisation the requester belongs to.
  `head_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `reason` TEXT NULL,
  `state` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `decided_by` VARCHAR(160) NULL,
  `decision_note` TEXT NULL,
  `decided_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_state` (`state`,`created_at`),
  KEY `k_who` (`requested_by`),
  KEY `k_head` (`head_id`,`state`),
  KEY `k_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /*
     * Invoices. The header carries who it is for and where it stands; the
     * lines carry what is being charged. Totals are stored on the header so a
     * sent invoice keeps the figures it was sent with, whatever happens to the
     * lines afterwards.
     */
    $out[] = "CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(32) NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `client_name` VARCHAR(160) NOT NULL,
  `client_org` VARCHAR(160) NULL,
  `client_email` VARCHAR(190) NULL,
  `client_address` TEXT NULL,
  `issue_date` DATE NOT NULL,
  `due_date` DATE NULL,
  `notes` TEXT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_pct` DECIMAL(6,3) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `state` ENUM('draft','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
  `sent_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  `paid_note` VARCHAR(190) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_number` (`number`),
  KEY `k_client` (`client_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ------------------------------------------------------------------
    // Statutory filings: GST, income tax, TDS, PF/ESI, ROC.
    //
    // A filing belongs to a client when we file on their behalf, and to
    // nobody (client_id 0) when it is our own return. Both live here so one
    // compliance calendar covers the whole desk.
    $out[] = "CREATE TABLE IF NOT EXISTS `filings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `client_name` VARCHAR(160) NULL,
  `kind` VARCHAR(32) NOT NULL,
  `period_label` VARCHAR(40) NOT NULL,
  `period_start` DATE NULL,
  `period_end` DATE NULL,
  `due_date` DATE NOT NULL,
  `state` ENUM('pending','filed','paid','late','na') NOT NULL DEFAULT 'pending',
  `filed_on` DATE NULL,
  `ack_no` VARCHAR(80) NULL,
  `taxable_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `penalty` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `k_client` (`client_id`,`state`),
  KEY `k_due` (`due_date`,`state`),
  KEY `k_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // What we spend. Tied to a client where it is billable to them, otherwise
    // it is overhead — that split is what makes a per-client margin possible.
    $out[] = "CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `spent_on` DATE NOT NULL,
  `category` VARCHAR(60) NOT NULL,
  `vendor` VARCHAR(160) NULL,
  `description` VARCHAR(255) NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `gst_credit` TINYINT(1) NOT NULL DEFAULT 0,
  `billable` TINYINT(1) NOT NULL DEFAULT 0,
  `paid` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_client` (`client_id`,`spent_on`),
  KEY `k_when` (`spent_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ------------------------------------------------------------------
    // Meetings.
    //
    // A meeting belongs to a client when it is with them, and to nobody
    // (client_id 0) when it is our own. Whoever organised it is kept by id
    // and by name, because the name has to survive the account going away.
    //
    // The Google columns stay empty until a Calendar event is created for
    // it; a meeting with a link typed in by hand works exactly the same.
    // A small key/value store for things settled at runtime rather than in
    // config: chiefly the Google refresh token, which is handed over by
    // Google during the connect flow and cannot be written into a file
    // beforehand.
    /* Work that happens later: crawling, generating, publishing. Modelled
       on mail_outbox, and run by cron.php. See lib/jobs.php. */
    $out[] = "CREATE TABLE IF NOT EXISTS `job_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(60) NOT NULL,
  `payload` MEDIUMTEXT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `dedupe_key` VARCHAR(190) NULL,
  `state` ENUM('waiting','running','done','failed') NOT NULL DEFAULT 'waiting',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `run_after` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME NULL,
  `lock_token` CHAR(16) NULL,
  `last_error` VARCHAR(500) NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_dedupe` (`dedupe_key`),
  KEY `k_due` (`state`, `run_after`),
  KEY `k_owner` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* One crawled page, cleaned and classified. The evidence every generated
       claim is checked back against lives here. */
    $out[] = "CREATE TABLE IF NOT EXISTS `crawled_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `site_id` INT UNSIGNED NOT NULL,
  `source_url` VARCHAR(700) NOT NULL,
  `canonical_url` VARCHAR(700) NULL,
  `source_page_type` VARCHAR(40) NULL,
  `source_title` VARCHAR(400) NULL,
  `source_description` TEXT NULL,
  `source_content` MEDIUMTEXT NULL,
  `source_evidence` MEDIUMTEXT NULL,
  `structured_data` MEDIUMTEXT NULL,
  `images` MEDIUMTEXT NULL,
  `content_hash` CHAR(40) NULL,
  `previous_hash` CHAR(40) NULL,
  `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `fetched_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_page` (`site_id`, `source_url`(190)),
  KEY `k_owner` (`client_id`),
  KEY `k_type` (`site_id`, `source_page_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* A website somebody asked us to work from, and what the crawl found. */
    $out[] = "CREATE TABLE IF NOT EXISTS `website_sources` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `website_url` VARCHAR(700) NOT NULL,
  `site_host` VARCHAR(190) NOT NULL,
  `brand_name` VARCHAR(190) NULL,
  `brand_profile` MEDIUMTEXT NULL,
  `state` VARCHAR(30) NOT NULL DEFAULT 'DISCOVERED',
  `pages_found` INT UNSIGNED NOT NULL DEFAULT 0,
  `pages_crawled` INT UNSIGNED NOT NULL DEFAULT 0,
  `post_date` DATE NULL,
  `frontier` MEDIUMTEXT NULL,
  `robots_rules` TEXT NULL,
  `plan` MEDIUMTEXT NULL,
  `posts_planned` INT UNSIGNED NOT NULL DEFAULT 0,
  `posts_written` INT UNSIGNED NOT NULL DEFAULT 0,
  `post_time` VARCHAR(5) NULL,
  `post_every` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `auto_post` TINYINT(1) NOT NULL DEFAULT 0,
  `logo` TEXT NULL,
  `hidden` TINYINT(1) NOT NULL DEFAULT 0,
  `last_error` VARCHAR(500) NULL,
  `last_crawled_at` DATETIME NULL,
  `created_by` VARCHAR(190) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_owner` (`client_id`),
  KEY `k_host` (`site_host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* Every attempt to put one post on one platform. Separate from the row
       being published so a retry is a new record and never a silent second
       post: see the unique key, which is what makes publishing idempotent. */
    $out[] = "CREATE TABLE IF NOT EXISTS `publication_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `row_id` INT UNSIGNED NOT NULL,
  `platform` VARCHAR(20) NOT NULL,
  `idempotency_key` VARCHAR(190) NOT NULL,
  `publication_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `post_id` VARCHAR(190) NULL,
  `platform_post_url` VARCHAR(700) NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(500) NULL,
  `api_response_reference` VARCHAR(190) NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_once` (`idempotency_key`),
  KEY `k_row` (`row_id`, `platform`),
  KEY `k_owner` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* A connected social account. The token is here and never leaves the
       server: nothing in this table is rendered to a browser. */
    $out[] = "CREATE TABLE IF NOT EXISTS `social_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `platform` VARCHAR(20) NOT NULL,
  `account_name` VARCHAR(190) NULL,
  `account_urn` VARCHAR(190) NULL,
  `access_token` TEXT NULL,
  `refresh_token` TEXT NULL,
  `scopes` VARCHAR(500) NULL,
  `expires_at` DATETIME NULL,
  `connected_at` DATETIME NULL,
  `last_error` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_owner_platform` (`client_id`, `platform`),
  KEY `k_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* Columns somebody has taken off a sheet. The column and its data stay
       in the table; this only says not to show it. Per organisation, like
       every other sheet customisation. */
    $out[] = "CREATE TABLE IF NOT EXISTS `column_hidden` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `field` VARCHAR(64) NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_hidden` (`sheet_key`, `field`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* The order somebody has dragged a sheet's columns into. Per
       organisation, like every other sheet customisation. A column with no
       row here has never been moved and keeps its defined place. */
    $out[] = "CREATE TABLE IF NOT EXISTS `column_order` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `field` VARCHAR(64) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_order` (`sheet_key`, `field`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* Which of Upcoming and Completed Events a column of the Events sheet
       is shown on: plan, result or both. A column with no row here keeps
       its default (lib/eventstages.php). Per organisation, like every other
       sheet customisation. Kept in step with fabo_evstage_ddl(). */
    $out[] = "CREATE TABLE IF NOT EXISTS `event_column_stage` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL DEFAULT 'events',
  `field` VARCHAR(64) NOT NULL,
  `stage` VARCHAR(10) NOT NULL DEFAULT 'plan',
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_stage` (`sheet_key`, `field`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `app_settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `custom_sheets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(48) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `subtitle` VARCHAR(255) NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `archived_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_key` (`sheet_key`),
  KEY `k_owner` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `custom_columns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_id` INT UNSIGNED NOT NULL,
  `field` VARCHAR(64) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `list_name` VARCHAR(80) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_field` (`sheet_id`,`field`),
  KEY `k_sheet` (`sheet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `requisition_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `turnaround_hours` INT NOT NULL DEFAULT 24,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_owner_name` (`client_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* The budget heads an admin allows against an event, per organisation —
       the same client_id scope requisition_types uses, so a client keeps their
       own catalogue and never reads ours or another's. */
    $out[] = "CREATE TABLE IF NOT EXISTS `budget_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name` VARCHAR(120) NOT NULL,
  `allocated` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `threshold_pct` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_owner_name` (`client_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* What was asked for against one head on one row. It keeps its own copy of
       the head's name, allocation and threshold: a request is the record of a
       decision, and renaming or re-costing the head later must not rewrite
       what was already asked or the verdict it was given. */
    $out[] = "CREATE TABLE IF NOT EXISTS `budget_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL DEFAULT 'events',
  `row_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `item_name` VARCHAR(120) NOT NULL,
  `allocated` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `threshold_pct` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `required` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) NULL,
  /* An amount over the ceiling is not refused outright: it is put to whoever
     owns the budget. 'none' covers both an empty line and one inside its
     limit — neither needs a decision. */
  `decision` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
  `remarks` VARCHAR(500) NULL,
  `decided_by` VARCHAR(160) NULL,
  `decided_at` DATETIME NULL,
  `approved_by` VARCHAR(40) NULL,
  `updated_by` VARCHAR(160) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_line` (`sheet_key`,`row_id`,`item_id`),
  KEY `k_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `password_invites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `token` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_token` (`token`),
  KEY `k_owner` (`client_id`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `mail_outbox` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NULL,
  `kind` VARCHAR(40) NOT NULL DEFAULT 'message',
  `to_email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_text` MEDIUMTEXT NULL,
  `body_html` MEDIUMTEXT NULL,
  `tries` INT NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `last_try_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `state` VARCHAR(12) NOT NULL DEFAULT 'waiting',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_state` (`state`,`id`),
  KEY `k_owner` (`client_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `sheet_extras` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(48) NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `field` VARCHAR(64) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `list_name` VARCHAR(80) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_field` (`sheet_key`,`client_id`,`field`),
  KEY `k_sheet` (`sheet_key`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `sheet_labels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(48) NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `label` VARCHAR(120) NOT NULL,
  `subtitle` VARCHAR(255) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_sheet_owner` (`sheet_key`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* Dashboard charts chosen in Sheet Management (lib/dashwidgets.php). */
    $out[] = "CREATE TABLE IF NOT EXISTS `dashboard_widgets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(48) NOT NULL,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(120) NOT NULL DEFAULT '',
  `metric` VARCHAR(600) NOT NULL,
  `compare_metric` VARCHAR(80) NULL,
  `group_by` VARCHAR(80) NULL,
  `chart` VARCHAR(16) NOT NULL DEFAULT 'bar',
  `width` VARCHAR(8) NOT NULL DEFAULT 'third',
  `top_n` TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_sheet` (`sheet_key`,`client_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `event_departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_row_id` INT UNSIGNED NULL,
  `client_id` INT UNSIGNED NULL,
  `name` VARCHAR(160) NOT NULL,
  `hub_spoke` VARCHAR(120) NULL,
  `contact_name` VARCHAR(160) NULL,
  `contact_email` VARCHAR(190) NULL,
  `token_hash` CHAR(64) NOT NULL,
  `link_token` VARCHAR(400) NULL,
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `opened_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_opened_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_event` (`event_row_id`),
  KEY `k_owner` (`client_id`),
  KEY `k_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `meetings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `organiser_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `organiser_name` VARCHAR(160) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `agenda` TEXT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `location` VARCHAR(190) NULL,
  `meet_url` VARCHAR(400) NULL,
  `calendar_event_id` VARCHAR(190) NULL,
  `conference_id` VARCHAR(190) NULL,
  `state` ENUM('scheduled','held','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  `rating` TINYINT UNSIGNED NULL,
  `rating_note` VARCHAR(400) NULL,
  `outcome` TEXT NULL,
  `minutes` LONGTEXT NULL,
  `minutes_source` ENUM('manual','transcript','gemini') NULL,
  `minutes_at` DATETIME NULL,
  `transcript` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `k_when` (`starts_at`),
  KEY `k_client` (`client_id`,`starts_at`),
  KEY `k_state` (`state`,`starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Who was asked. A guest may be someone on the portal (client_id set) or
    // any address typed into the invite box; the email is what identifies
    // them either way, which is why it carries the unique key.
    $out[] = "CREATE TABLE IF NOT EXISTS `meeting_guests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meeting_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NULL,
  `name` VARCHAR(160) NULL,
  `email` VARCHAR(190) NOT NULL,
  `role` ENUM('host','guest') NOT NULL DEFAULT 'guest',
  `invited_at` DATETIME NULL,
  `invite_error` VARCHAR(190) NULL,
  `response` ENUM('yes','no','maybe') NULL,
  `responded_at` DATETIME NULL,
  `attended` TINYINT(1) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_guest` (`meeting_id`,`email`),
  KEY `k_person` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
  `rate` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `k_invoice` (`invoice_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `approval_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `remarks` TEXT NULL,
  `decided_by` VARCHAR(160) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* Approval committees for events -- see lib/committees.php. */
    $out[] = "CREATE TABLE IF NOT EXISTS `committees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name` VARCHAR(120) NOT NULL,
  `rule` VARCHAR(12) NOT NULL DEFAULT 'all',
  `required` INT UNSIGNED NULL,
  `created_by` VARCHAR(160) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_scope_name` (`client_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `committee_members` (
  `committee_id` INT UNSIGNED NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`committee_id`,`person_id`),
  KEY `k_person` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `committee_votes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `committee_id` INT UNSIGNED NOT NULL,
  `person_id` INT UNSIGNED NOT NULL,
  `decision` ENUM('approved','rejected') NOT NULL,
  `justification` TEXT NULL,
  `decided_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_vote` (`event_id`,`committee_id`,`person_id`),
  KEY `k_committee` (`committee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /* Shared live sheets: any sheet, shared by link with a department --
       see lib/shares.php. owner_scope is whose desk it was made on (0 ours),
       filed_under the organisation its rows are filed under. */
    $out[] = "CREATE TABLE IF NOT EXISTS `sheet_shares` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_scope` INT UNSIGNED NOT NULL DEFAULT 0,
  `filed_under` INT UNSIGNED NOT NULL DEFAULT 0,
  `sheet_key` VARCHAR(64) NOT NULL,
  `department` VARCHAR(160) NOT NULL,
  `hub_spoke` VARCHAR(120) NULL,
  `contact_name` VARCHAR(160) NOT NULL,
  `contact_email` VARCHAR(190) NULL,
  `sees` VARCHAR(8) NOT NULL DEFAULT 'own',
  `token` CHAR(40) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at` DATETIME NULL,
  `opened_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_opened_at` DATETIME NULL,
  `created_by` VARCHAR(160) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_token` (`token`),
  KEY `k_scope` (`owner_scope`),
  KEY `k_sheet` (`sheet_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `body` TEXT NULL,
  `link` VARCHAR(255) NULL,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_client` (`client_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `checklist_template` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `item` VARCHAR(190) NOT NULL,
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_sheet_item` (`sheet_key`,`item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `checklist_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sheet_key` VARCHAR(32) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `item` VARCHAR(190) NOT NULL,
  `done` TINYINT(1) NOT NULL DEFAULT 0,
  `note` TEXT NULL,
  `updated_by` VARCHAR(160) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_item` (`sheet_key`,`row_id`,`item`),
  KEY `k_row` (`sheet_key`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $out[] = "CREATE TABLE IF NOT EXISTS `activity_audit` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `who` VARCHAR(160) NOT NULL,
  `client_id` INT UNSIGNED NULL,
  `table_name` VARCHAR(64) NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `field` VARCHAR(64) NOT NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_row` (`table_name`,`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $out;
}

/** Run install once per request, and fall back to the setup page on failure. */
function fabo_boot(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        fabo_install();
    } catch (Throwable $e) {
        error_log('FABO install failed: ' . $e->getMessage());
        fabo_setup_page($e->getMessage(), (int) $e->getCode());
    }
}

/** Admin column renames, cached per request. Read by fabo_sheet(). */
/**
 * Column wording, as one organisation sees it.
 *
 * The shared set is loaded first and an organisation's own overrides are
 * laid on top, so a client renaming one column keeps the default wording
 * for every other — and never changes anybody else's.
 */
function fabo_load_label_overrides(): array
{
    require_once __DIR__ . '/lists.php';
    static $byOwner = [];
    $owner = fabo_view_owner();
    if (isset($byOwner[$owner])) {
        return $byOwner[$owner];
    }
    $ov = [];
    $rows = $owner > 0
        ? fabo_all('SELECT sheet_key, field, label, client_id FROM column_labels
                    WHERE client_id IN (0, ?) ORDER BY client_id', 'i', [$owner])
        : fabo_all('SELECT sheet_key, field, label, client_id FROM column_labels
                    WHERE client_id = 0');
    foreach ($rows as $r) {
        $ov[$r['sheet_key'] . '.' . $r['field']] = $r['label'];
    }
    $byOwner[$owner] = $ov;
    return $ov;
}
