<?php
/**
 * Admin — Team. Two halves:
 *   1. Team members: each gets a personal link (like a client, but internal),
 *      the sheets they may work in, and an Owner / Team value that ties rows
 *      to them. Assigning work = setting a row's Owner to that value.
 *   2. Monitoring: per member, how much is assigned, done, open, overdue —
 *      plus the workload view across every owner named on any sheet.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/outbox.php';
require_once __DIR__ . '/../lib/work.php';
require_once __DIR__ . '/../lib/charts.php';
require_once __DIR__ . '/../lib/notify.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/teamdash.php';
require_once __DIR__ . '/../lib/wm/access.php';
fabo_boot();
fabo_require_admin();

$viewer = fabo_admin_viewer();
$today  = fabo_today();
$flash  = null;

$sheetKeys = array_values(array_filter(fabo_sheet_order(),
    static fn($k) => empty(fabo_sheet($k)['admin_only'])));

/* The modules our own staff can be given. Both work on OUR company: HR with
   Can edit makes them its HR administrator (employees, attendance, leave,
   payroll, HR access); CRM with Can edit lets them create our CRMs and run
   every one of them, View only reads them all; Incubation Management with Owner runs
   the whole module, View only reads and exports it. The Finance Portal with Owner runs
   our books as the administrator does (lib/fin, fp_member_grant), View only reads them.
   Intake Forms are client-facing and stay off this page. */
$teamModules = [
    'hr'         => ['HR Management', 'Owner: runs HR for our company — employees, attendance, leave, payroll and who may use HR. View only: reads and exports it.'],
    'crm'        => ['CRM', 'Owner: creates CRMs and runs every one of ours. View only: reads them all. One CRM can still be shared on its own from that CRM\'s Access tab.'],
    'incubation' => ['Incubation Management', 'Owner: everything the administrator can do — applications, stages, assignment, evaluation, review, forms and settings. View only: reads applications, workspaces and reports, and exports them.'],
    'organizers' => ['Event Organizers', 'Owner: runs our approved events as the administrator does — forms, applications, public pages, tickets, check-in and settings. View only: reads and exports them.'],
    'esign'      => ['Digital Signatures', 'Owner: runs our Digital Signatures as the administrator does — every document, workflow, signatory, provider and setting. View only: reads every document and report. Without it they still sign what is sent to them.'],
    'academic'   => ['Academic', 'Owner: runs our Academic as the administrator does — institutions, departments, students, faculty, mentors, courses, imports and the Campus portal settings. View only: reads it.'],
    'ideaval'    => ['Startup Discovery', 'Owner: runs our Startup Discovery as the administrator does — HoD dashboards, students, rounds and timing, the pipeline and teams. View only: reads it.'],
    'finance'    => ['Finance Portal', 'Owner: runs our Finance Portal as the administrator does — ledger, bills, invoices, payments, budgets, settings and finance roles. View only: reads every desk and report. Without it they still claim expenses and raise requests; finer roles are given in Finance → Settings.'],
];
/* All three are granted the same way, under "Modules they can run". The
   per-CRM sharing section is not on this page: one CRM is shared from its own
   Access tab, and grants made there are left alone when this form is saved. */
$moduleRows = $teamModules;

/* ------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    $savePerms = static function (int $memberId) use ($sheetKeys, $teamModules) {
        // Our own CRMs, each shared on its own (lib/crm.php).
        require_once __DIR__ . '/../lib/crm.php';
        fabo_crm_save_access($memberId, $_POST, fabo_crm_boards_owned(0), true);
        fabo_exec('DELETE FROM client_sheet_access WHERE client_id = ?', 'i', [$memberId]);
        foreach ((array) ($_POST['perm'] ?? []) as $key => $perm) {
            if (!in_array($key, $sheetKeys, true) && !isset($teamModules[$key])) { continue; }
            if ($key === 'dashboard' && $perm === 'edit') { $perm = 'view'; }
            if ($perm === 'view' || $perm === 'edit') {
                fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission) VALUES (?,?,?)',
                          'iss', [$memberId, $key, $perm]);
            }
        }
    };

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $flash = ['err', 'A name is required.'];
        } else {
            /* A committee member signs in to their Committee Dashboard, and
               opens whichever sheets they are given as well. */
            $isCm  = !empty($_POST['is_committee']) ? 1 : 0;
            $newId = fabo_exec(
                "INSERT INTO clients (name, kind, email, owner_name, contact_note, token,
                    expires_at, is_committee)
                 VALUES (?,'team',?,?,?,?,NULL,?)",
                'sssssi',
                [$name, trim((string) ($_POST['email'] ?? '')), trim((string) ($_POST['owner_name'] ?? '')),
                 trim((string) ($_POST['contact_note'] ?? '')), fabo_new_token(), $isCm]
            );
            $savePerms($newId);
            fabo_save_person_image($newId, $_FILES['image'] ?? null);

            // Their own portal: sign-in password plus the private link.
            /* A password, issued now and printed in the message. The sign-in page
               makes them replace it the first time they use it, so what travels
               by email stops being usable the moment it has done its job. */
            $plain = fabo_set_password($newId);
            $fresh = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$newId]);
            $base  = fabo_portal_url();
            [$sent, $detail] = fabo_announce_welcome(
                $fresh, fabo_client_access($newId),
                $base . 'c.php?k=' . $fresh['token'], $base . 'login.php', $plain
            );
            fabo_session();
            $_SESSION['fabo_mail'] = $sent
                ? 'Member added and their sign-in details were emailed — ' . $detail . '.'
                : 'Member added. Email not sent (' . $detail . ') — the password is shown below, send it yourself.';
            // If the mail did not go, $plain above is what to pass on by hand.

            header('Location: team.php?id=' . $newId . '&created=1');
            exit;
        }
    // parent_id IS NULL throughout: a client's own staff belong to that
    // client's desk, and are not ours to edit, revoke or delete from here.
    } elseif ($id > 0 && fabo_one("SELECT id FROM clients
                                   WHERE id = ? AND kind = 'team' AND parent_id IS NULL",
                                  'i', [$id])) {
        switch ($action) {
            case 'save':
            case 'save_send':
                $wasRow = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                $isCm = !empty($_POST['is_committee']) ? 1 : 0;
                fabo_exec('UPDATE clients SET name=?, email=?, owner_name=?, contact_note=?, is_committee=? WHERE id=?',
                          'ssssii',
                          [trim((string) ($_POST['name'] ?? '')), trim((string) ($_POST['email'] ?? '')),
                           trim((string) ($_POST['owner_name'] ?? '')), trim((string) ($_POST['contact_note'] ?? '')),
                           $isCm, $id]);
                $before = fabo_client_access($id);
                $savePerms($id);
                /* No longer a committee member: off every committee, and
                   those committees' events are decided again without them. */
                if (!$isCm && !empty($wasRow['is_committee'])) {
                    require_once __DIR__ . '/../lib/committees.php';
                    fabo_cm_drop_person($id, 'Admin');
                }
                $after  = fabo_client_access($id);
                $imgFlash = fabo_save_person_image($id, $_FILES['image'] ?? null, !empty($_POST['remove_image']));
                /* Work Management keeps its own membership (wm_members), not a
                   client_sheet_access row. It is set here as well as on
                   Work Management -> Access; both edit the same row. */
                $wmNote = '';
                if (!empty($_POST['wm_present'])) {
                    try {
                        $wmNote = wm_team_member_set(wm_ctx($viewer), $id, ($_POST['wm_access'] ?? '') === 'team',
                                                     (array) ($_POST['wm_depts'] ?? []));
                    } catch (WmError $e) {
                        $wmNote = ' Work Management not changed: ' . $e->getMessage();
                    }
                }

                $note = '';
                if ($before !== $after) {
                    $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                    $url    = fabo_portal_url() . 'c.php?k=' . $person['token'];
                    [$sent, $detail] = fabo_announce_access($person, $after, $url);
                    $note = $sent
                        ? ' Sheets changed — emailed to their dashboard link (' . $detail . ').'
                        : ' Sheets changed — a notice is on their dashboard; email not sent (' . $detail . ').';
                }

                /* Their email is what they sign in with, so changing it hands
                   the account to a different address — one that has never had
                   a password. A fresh one goes out with the sign-in link. */
                /* Not when the credentials are being sent anyway: that
                   message carries the same password and the same link, and
                   two of them an hour apart is how somebody ends up typing
                   the older one. */
                [$moved, $idSent, $idWhy, $idPw] = $action === 'save_send'
                    ? [false, false, '', null]
                    : fabo_announce_identity_change($wasRow);
                if ($moved) {
                    $note .= $idSent
                        ? ' Their name or address changed — new sign-in details emailed.'
                        : ' Their name or address changed, but the email failed (' . $idWhy . ')'
                          . ($idPw !== null ? '. New password: ' . $idPw : '') . '.';
                }

                /* Saving and sending are one act here: an edit that changes a
                   name or an address is exactly the moment somebody needs
                   their details again, and remembering to press a second
                   button afterwards is how people end up locked out. */
                if ($action === 'save_send') {
                    /* A password, issued now and printed in the message. The sign-in page
                       makes them replace it the first time they use it, so what travels
                       by email stops being usable the moment it has done its job. */
                    $plain = fabo_set_password($id);
                    $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                    $base   = fabo_portal_url();
                    [$cSent, $cWhy] = fabo_announce_welcome(
                        $person, fabo_client_access($id),
                        $base . 'c.php?k=' . $person['token'], $base . 'login.php', $plain
                    );
                    /* Not an error when it is queued: the save worked, the
                       password is set, and the message goes out by itself.
                       What the reader needs is the password and the user ID,
                       so they can pass them on now if they want to. */
                    $note .= $cSent
                        ? ' Sign-in details emailed to ' . $person['email']
                          . ' (user ID ' . $person['login_id'] . ').'
                        : ' The email is queued and goes out by itself. To get them in '
                          . 'now: user ID ' . $person['login_id'] . ', password '
                          . $plain . '.';
                }
                $flash = ($imgFlash && $imgFlash[0] === 'err') ? $imgFlash : ['ok', 'Saved.' . $note . $wmNote];
                break;

            case 'mail_send':
                /* Offer the kept messages again. Stops at the first "not
                   now", because the rest of the queue will hear the same. */
                [$mSent, $mLeft, $mWhy] = fabo_outbox_flush($id, 10);
                $flash = [$mLeft > 0 ? 'err' : 'ok',
                    $mSent > 0 ? $mSent . ' message' . ($mSent === 1 ? '' : 's') . ' sent.'
                               : 'Nothing went out.'
                    . ($mLeft > 0 ? ' ' . $mLeft . ' still waiting — ' . $mWhy . '.' : '')];
                break;

            case 'mail_reset':
                $n = fabo_outbox_reset($id);
                $flash = ['ok', 'Tries reset'
                    . ($n > 0 ? ' on ' . $n . ' waiting message' . ($n === 1 ? '' : 's') : '')
                    . ', and the wait lifted. Press Send it again now to try straight away.'];
                break;



            case 'new_invite':
                /* A fresh link, which spends any older one. Handed over by
                   whatever means works — the point is that it does not wait
                   on a mail server. */
                if (!is_file(__DIR__ . '/../lib/invite.php')) {
                    $flash = ['err', 'Invitations need lib/invite.php, which is not on '
                                   . 'the server yet. Upload it and try again.'];
                    break;
                }
                require_once __DIR__ . '/../lib/invite.php';
                $made = fabo_invite_link($id);
                $flash = [$made !== '' ? 'ok' : 'err', $made !== ''
                    ? 'New invitation made. Copy it from the box above and send it however you like.'
                    : 'The invitation could not be made.'];
                break;
            case 'set_password':
                /* A password somebody types, set on the spot and shown once.
                   Everything else here generates one and emails it, which is
                   no use at all when the mail is queued or went to an inbox
                   nobody is reading: this is the way in that depends on
                   nothing but the two people talking to each other. */
                $typed = trim((string) ($_POST['new_password'] ?? ''));
                if (mb_strlen($typed) < 8) {
                    $flash = ['err', 'Choose a password of at least 8 characters.'];
                    break;
                }
                fabo_set_password($id, $typed);
                fabo_exec('UPDATE clients SET must_change = 0 WHERE id = ?', 'i', [$id]);
                $who = fabo_one('SELECT login_id, email FROM clients WHERE id = ?', 'i', [$id]);
                $flash = ['ok', 'Password set. They sign in with user ID '
                    . (string) ($who['login_id'] ?? '') . ' and the password you just typed. '
                    . 'Nothing was emailed — tell them yourself.'];
                break;

            case 'credentials':
                /* A password, issued now and printed in the message. The sign-in page
                   makes them replace it the first time they use it, so what travels
                   by email stops being usable the moment it has done its job. */
                $plain = fabo_set_password($id);
                $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                $base   = fabo_portal_url();
                [$sent, $detail] = fabo_announce_welcome(
                    $person, fabo_client_access($id),
                    $base . 'c.php?k=' . $person['token'], $base . 'login.php', $plain
                );
                $flash = [$sent ? 'ok' : 'err', $sent
                    ? 'New password set and emailed — ' . $detail . '.'
                    : 'New password set, but the email did not go (' . $detail
                      . '). Password: ' . $plain
                      . ', user ID ' . $person['login_id'] . '.'];
                break;

            case 'resend':
                $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                $url    = fabo_portal_url() . 'c.php?k=' . $person['token'];
                [$sent, $detail] = fabo_announce_access($person, fabo_client_access($id), $url);
                $flash = [$sent ? 'ok' : 'err',
                          $sent ? 'Access email ' . $detail . '. A notice is also on their dashboard.'
                                : 'Email not sent (' . $detail . '). A notice is on their dashboard — use the send buttons above.'];
                break;

            case 'rotate':
                fabo_exec('UPDATE clients SET token=? WHERE id=?', 'si', [fabo_new_token(), $id]);
                $flash = ['ok', 'New link generated — the old one no longer works.'];
                break;
            case 'revoke':
                fabo_exec('UPDATE clients SET revoked_at=NOW() WHERE id=?', 'i', [$id]);
                $flash = ['ok', 'Access revoked.'];
                break;
            case 'restore':
                fabo_exec('UPDATE clients SET revoked_at=NULL WHERE id=?', 'i', [$id]);
                $flash = ['ok', 'Access restored on the same link.'];
                break;
            case 'delete':
                fabo_exec('DELETE FROM client_sheet_access WHERE client_id=?', 'i', [$id]);
                fabo_exec('DELETE FROM clients WHERE id=?', 'i', [$id]);
                $flash = ['ok', 'Team member removed. Their rows keep their Owner value.'];
                break;
        }
    }
}

// A create that failed re-opens the box, rather than hiding the reason.
$addOpen = is_array($flash) && $flash[0] === 'err' && ($_POST['action'] ?? '') === 'create';

$editing = null;
if (!empty($_GET['id'])) {
    $editing = fabo_one("SELECT * FROM clients
                         WHERE id = ? AND kind = 'team' AND parent_id IS NULL",
                        'i', [(int) $_GET['id']]);
    if ($editing && !empty($_GET['created'])) {
        fabo_session();
        $flash = ['ok', $_SESSION['fabo_mail'] ?? 'Team member created.'];
        $newPassword = $_SESSION['fabo_pw'] ?? '';
        unset($_SESSION['fabo_mail'], $_SESSION['fabo_pw']);
    }
}

$newPassword = $newPassword ?? '';
$members  = fabo_team_members();
$allItems = fabo_work_items($viewer);
$owners   = fabo_list_values('Owner / Team');

$baseUrl = fabo_portal_url();

/* ------------------------------------------------- workload by owner */

$byOwner = [];
foreach ($allItems as $it) {
    $o = $it['owner'] !== '' ? $it['owner'] : '(unassigned)';
    $byOwner[$o][] = $it;
}
uksort($byOwner, static function ($a, $b) use ($byOwner) {
    if ($a === '(unassigned)') { return 1; }
    if ($b === '(unassigned)') { return -1; }
    return count(array_filter($byOwner[$b], static fn($i) => !$i['closed']))
       <=> count(array_filter($byOwner[$a], static fn($i) => !$i['closed'])) ?: strcmp($a, $b);
});
$teamOwners = array_filter(array_keys($byOwner), static fn($k) => $k !== '(unassigned)');
$openBy = [];
foreach ($byOwner as $o => $its) { $openBy[$o] = count(array_filter($its, static fn($i) => !$i['closed'])); }
$avgOpen = $teamOwners ? array_sum(array_intersect_key($openBy, array_flip($teamOwners))) / count($teamOwners) : 0;
$maxOpen = max(1, ...array_values($openBy ?: [0]));

function fabo_balance(int $open, float $avg): array
{
    if ($avg <= 0) { return ['muted', 'no load']; }
    $r = $open / $avg;
    if ($r >= 1.5) { return ['risk', 'Overloaded']; }
    if ($r >= 1.2) { return ['warn', 'Heavy']; }
    if ($r <= 0.5) { return ['muted', 'Spare capacity']; }
    return ['good', 'Balanced'];
}

fabo_head($viewer, 'Team Management', 'team');
?>
<div class="wrap-page">
  <div class="pagehead">
    <h1>TEAM MANAGEMENT</h1>
    <div class="sub">Members, their sheets and links · what is assigned to each, done and outstanding · as of <?= e(date('d-M-Y', strtotime($today))) ?></div>
  </div>

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($editing):
    $access = $editing['access'] ?? fabo_client_access((int) $editing['id']);
    $url    = $baseUrl . 'c.php?k=' . $editing['token'];
    [$subj, $bodyTxt] = fabo_link_message($editing, $url);
    $revoked = !empty($editing['revoked_at']);
?>
  <div class="card">
    <h2 style="display:flex;align-items:center;gap:12px"><?= fabo_avatar($editing) ?>
      <span><?= e($editing['name']) ?> <span class="pill <?= $revoked ? 'revoked' : 'ok' ?>"><?= $revoked ? 'revoked' : 'active' ?></span></span></h2>

    <div class="field">
      <label>Personal link</label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="tokenlink" id="murl"><?= e($url) ?></span>
        <button class="btn btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('murl').textContent);this.textContent='Copied'">Copy link</button>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
        <a class="btn btn-primary btn-sm" href="mailto:<?= e(rawurlencode((string) $editing['email'])) ?>?subject=<?= e(rawurlencode($subj)) ?>&amp;body=<?= e(rawurlencode($bodyTxt)) ?>">&#9993; Send by email<?= $editing['email'] ? '' : ' (no address yet)' ?></a>
        <a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode($bodyTxt)) ?>">Send on WhatsApp</a>
        <button class="btn btn-sm" type="button" onclick="navigator.clipboard.writeText(this.getAttribute('data-msg'));this.textContent='Message copied'" data-msg="<?= e($bodyTxt) ?>">Copy message</button>
      </div>
      <div class="hint">Opened <?= (int) $editing['open_count'] ?> time<?= (int) $editing['open_count'] === 1 ? '' : 's' ?><?= $editing['last_opened_at'] ? ', last ' . e(date('d M Y, H:i', strtotime($editing['last_opened_at']))) : '' ?>. No expiry on team links.</div>
    </div>

    <div class="signinbox">
      <strong>Their portal sign-in</strong>
      <div class="pair"><span>Page</span><code><?= e($baseUrl) ?>login.php</code></div>
      <div class="pair"><span>Email</span><code><?= e($editing['email'] ?: '— add an email below —') ?></code></div>
      <div class="pair"><span>Password</span><code><?= $newPassword !== '' ? e($newPassword)
          : ($editing['password_hash'] ? 'set — only they know it' : 'not set yet') ?></code></div>
      <?php if ($newPassword !== ''): ?>
        <div class="hint" style="color:var(--tone-warn-ink)">The email did not go out — copy this password now, it is not stored in readable form.</div>
      <?php endif; ?>
      <form method="post" style="margin-top:8px">
        <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <button class="btn btn-sm" name="action" value="credentials"
                onclick="return confirm('Set a new password and email it to them? Their current password stops working.')">
          Reset password &amp; e-mail the details</button>
      </form>
    </div>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button class="btn btn-sm" name="action" value="resend">Re-send access email</button>
      <button class="btn btn-sm" name="action" value="rotate" onclick="return confirm('Generate a new link? The current one stops working.')">Rotate link</button>
      <?php if ($revoked): ?>
        <button class="btn btn-sm" name="action" value="restore">Restore access</button>
      <?php else: ?>
        <button class="btn btn-danger btn-sm" name="action" value="revoke" onclick="return confirm('Revoke this member\'s access?')">Revoke</button>
      <?php endif; ?>
      <button class="btn btn-danger btn-sm" name="action" value="delete" onclick="return confirm('Remove this team member? Their rows keep their Owner value.')">Remove member</button>
    </form>

    <form method="post" enctype="multipart/form-data">
      <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <div class="fieldrow">
        <div class="field"><label>Name</label><input name="name" value="<?= e($editing['name']) ?>" required></div>
        <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($editing['email']) ?>"></div>
        <div class="field">
          <label>Owner / Team value (ties rows to this person)</label>
          <?php fabo_list_field('owner_name', 'Owner / Team',
                                (string) $editing['owner_name'], $viewer, '../api/',
                                '— not linked to rows —'); ?>
          <div class="hint">Rows whose Owner is this value count as this person's work. Add names to the Owner / Team dropdown from any sheet.</div>
        </div>
      </div>
      <div class="field">
        <label>Photo</label>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <?= fabo_avatar($editing) ?>
          <div><input type="file" name="image" accept="image/*" style="font-size:13px">
            <div class="hint">Optional.<?php if (!empty($editing['image_file'])): ?>
              <label style="margin-left:10px"><input type="checkbox" name="remove_image" value="1"> Remove</label><?php endif; ?></div>
          </div>
        </div>
      </div>
      <div class="field"><label>Note</label><textarea name="contact_note"><?= e($editing['contact_note']) ?></textarea></div>
      <div class="field cm-tick">
        <label><input type="checkbox" name="is_committee" value="1"
                      <?= !empty($editing['is_committee']) ? 'checked' : '' ?>> Committee member</label>
        <div class="hint">A committee member gets a Committee Dashboard: the events sent to the
          committees they sit on, which they approve or reject, plus any sheets you give them below. Put them on a
          committee in Sheet Management &rarr; Committees.</div>
      </div>
      <div class="field">
        <label>Sheets they can work in</label>
        <table class="data accesstable">
          <thead><tr><th>Sheet</th><th>No access</th><th>View only</th><th>Can edit</th></tr></thead>
          <tbody>
          <?php foreach ($sheetKeys as $key): $cur = $access[$key] ?? 'none'; $isDash = $key === 'dashboard'; ?>
            <tr>
              <td><?= e(fabo_sheet_label($key)) ?></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="none" <?= $cur === 'none' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="view" <?= $cur === 'view' ? 'checked' : '' ?>></td>
              <td><?= $isDash ? '—' : '<input type="radio" name="perm[' . e($key) . ']" value="edit" ' . ($cur === 'edit' ? 'checked' : '') . '>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="field">
        <label>Modules they can run</label>
        <table class="data accesstable">
          <thead><tr><th>Module</th><th>No access</th><th>View only</th><th>Owner</th></tr></thead>
          <tbody>
          <?php foreach ($moduleRows as $key => [$label, $hint]): $cur = $access[$key] ?? 'none'; ?>
            <tr>
              <td><?= e($label) ?><div class="hint"><?= e($hint) ?></div></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="none" <?= $cur === 'none' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="view" <?= $cur === 'view' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="edit" <?= $cur === 'edit' ? 'checked' : '' ?>></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="hint">They open these from their own dashboard menu after signing in.</div>
      </div>
      <?php
        $wmCtx  = wm_ctx($viewer);
        $wmRow  = wm_member_row((int) $editing['id']);
        $wmOn   = $wmRow && $wmRow['role'] === 'team' && (int) $wmRow['is_active'] === 1;
        $wmHave = $wmRow ? array_map(static fn($r) => (int) $r['department_id'],
            fabo_all('SELECT department_id FROM wm_user_department_access WHERE user_id = ?', 'i', [(int) $editing['id']])) : [];
        $wmDepts = wm_departments($wmCtx);
      ?>
      <div class="field">
        <label>Work Management</label>
        <input type="hidden" name="wm_present" value="1">
        <table class="data accesstable">
          <thead><tr><th>Module</th><th>No access</th><th>Team member</th></tr></thead>
          <tbody><tr>
            <td>Work Management<div class="hint">Team member: sees, creates and updates tasks in the departments ticked below, comments and logs time. Users, departments and access stay with the administrator.</div></td>
            <td><input type="radio" name="wm_access" value="none" <?= $wmOn ? '' : 'checked' ?>></td>
            <td><input type="radio" name="wm_access" value="team" <?= $wmOn ? 'checked' : '' ?>></td>
          </tr></tbody>
        </table>
        <?php if ($wmDepts): ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:8px">
            <?php foreach ($wmDepts as $d): ?>
              <label style="font-weight:normal"><input type="checkbox" name="wm_depts[]" value="<?= (int) $d['id'] ?>" <?= in_array((int) $d['id'], $wmHave, true) ? 'checked' : '' ?>> <?= e($d['name']) ?><?= (int) ($d['is_active'] ?? 1) === 1 ? '' : ' (inactive)' ?></label>
            <?php endforeach; ?>
          </div>
          <div class="hint">Departments whose tasks they work on. With none ticked they can open Work Management but see no tasks.</div>
        <?php else: ?>
          <div class="hint">No departments yet. Add them in <a href="work.php?p=departments">Work Management → Departments</a>, then tick them here.</div>
        <?php endif; ?>
      </div>
      <div class="formsec">
        <h3>Sending work out for approval</h3>
        <p class="lead">A team member can send any row on the sheets above to a client for
          approval &mdash; from the <strong>Send for Approval</strong> column, or the &#10003;
          review panel on the row. They do not need any extra right for that, and they are never
          asked to approve anything themselves.</p>
      </div>

      <?php /* The way in that needs no email at all. */ ?>
      <div class="setpw">
        <div class="setpw-head">
          <strong>Set their password yourself</strong>
          <div class="hint">Type one, press Set, and tell them. Nothing is emailed,
            nothing is queued, and they can sign in straight away with the user ID
            above &mdash; which works even when several clients share one address.</div>
        </div>
        <div class="setpw-row">
          <input type="text" name="new_password" minlength="8" autocomplete="off"
                 placeholder="at least 8 characters">
          <button class="btn" name="action" value="set_password"
                  formnovalidate>Set password</button>
        </div>
      </div>

      <?php
        /* The same softness as everywhere else: without lib/invite.php the
           box simply does not appear. */
        $liveInvite = null;
        $inviteUrl  = '';
        if (is_file(__DIR__ . '/../lib/invite.php')) {
            require_once __DIR__ . '/../lib/invite.php';
            $liveInvite = fabo_invite_live((int) $editing['id']);
            $inviteUrl  = $liveInvite ? fabo_invite_url((string) $liveInvite['token']) : '';
        }
      ?>
      <?php if (function_exists('fabo_invite_live')): ?>
      <div class="invitebox">
        <div class="invitebox-head">
          <strong>Set-a-password link (not emailed)</strong>
          <div class="hint">No email carries this &mdash; it is here for when you want to
            hand a link over yourself, by WhatsApp or in person. It opens a page where
            <?= e((string) $editing['name']) ?> chooses their own password, then takes
            them straight in. It works once and lasts <?= FABO_INVITE_DAYS ?> days.</div>
        </div>
        <?php if ($inviteUrl !== ''): ?>
          <div class="invitebox-link">
            <span class="tokenlink" id="inviteurl"><?= e($inviteUrl) ?></span>
            <button class="btn btn-sm" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('inviteurl').textContent).then(function(){},function(){});this.textContent='Copied'">Copy invitation</button>
            <button class="btn btn-sm" name="action" value="new_invite" formnovalidate>New link</button>
          </div>
        <?php else: ?>
          <div class="invitebox-link">
            <span class="ro">No live invitation &mdash; the last one was used or has expired.</span>
            <button class="btn btn-sm btn-primary" name="action" value="new_invite"
                    formnovalidate>Make one</button>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php fabo_outbox_block((int) $editing['id'], true); ?>

      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="save_send">
          Update &amp; send credentials</button>
        <button class="btn" name="action" value="save">Save only</button>
        <a class="btn" href="team.php">Done</a>
      </div>
      <p class="hint">
        <strong>Update &amp; send credentials</strong> saves the changes, issues a new
        password and emails it with their user ID and the sign-in link &mdash; use it
        whenever you change their name or address, or whenever they have lost the mail.
        <strong>Save only</strong> changes the record and sends nothing.
      </p>
    </form>
  </div>
<?php endif; ?>

<?php fabo_team_dashboard($viewer, $members, $allItems); ?>

  <div class="block">
    <h2>Team members — assigned, done, outstanding</h2>
    <?php if (!$members): ?>
      <div class="card"><div class="empty">No team members yet. Use <strong>Add a new team member</strong> below — they get a personal link and a work list.</div></div>
    <?php else: ?>
    <div class="scrollx"><table class="data">
      <thead><tr>
        <th>Member</th>
        <th>Sheets they may use</th>
        <th>What they are carrying</th>
        <th class="num">Open</th><th class="num">Done</th>
        <th class="num">Late</th><th class="num">Due&nbsp;7d</th>
        <th style="min-width:130px">Progress</th>
        <th>Entered</th>
        <th>Still at it?</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($members as $m):
          /* Theirs by assignment OR by having entered it. Matching the Owner
             cell alone left somebody who fills sheets in daily reading as
             carrying nothing, because labelling a row afterwards is
             bookkeeping and bookkeeping does not get done. */
          $mine = fabo_work_for_member($allItems, $m);
          $sm = fabo_work_summary($mine);
          $revoked = !empty($m['revoked_at']);
          $barTone = $sm['overdue'] ? 'risk' : ($sm['pct_done'] >= 70 ? '' : 'warn');
      ?>
        <tr>
          <td><span style="display:flex;align-items:center;gap:8px"><?= fabo_avatar($m, 'sm') ?><span>
              <a href="team.php?id=<?= (int) $m['id'] ?>"><strong><?= e($m['name']) ?></strong></a>
              <?php if ($revoked): ?> <span class="pill revoked">revoked</span><?php endif; ?>
              <?php if (!empty($m['is_committee'])): ?> <span class="pill">Committee</span><?php endif; ?>
              <?= $m['email'] ? '<br><span style="font-size:12px;color:var(--ink-soft)">' . e($m['email']) . '</span>' : '' ?>
              <div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px">
                <?= $m['owner_name']
                    ? 'rows owned by &ldquo;' . e($m['owner_name']) . '&rdquo;'
                    : '<span style="color:var(--ink-mute)">no Owner value &mdash; no work can be counted</span>' ?>
              </div></span></span></td>

          <?php /* Which sheets they may work in. Asked of a team list far more
                   often than it is of one person's page. */ ?>
          <td><?php
            $bits = [];
            if (!empty($m['is_committee'])) {
                $bits[] = '<span class="pill">Committee Dashboard only</span>';
            }
            foreach ($m['access'] as $k => $p) {
                $bits[] = '<span class="pill ' . e($p) . '">'
                        . e(isset($teamModules[$k]) ? $teamModules[$k][0] : fabo_sheet_label($k)) . '</span>';
            }
            echo $bits
                ? implode(' ', $bits)
                : '<span style="color:var(--ink-mute)">no sheets assigned</span>';
          ?></td>

          <?php /* And what they are carrying on them, which is a different
                   question from what they are allowed to open. */ ?>
          <td><?php
            $doing = fabo_work_by_sheet($mine);
            if (!$doing) {
                echo $m['owner_name']
                    ? '<span style="color:var(--ink-mute)">nothing open</span>'
                    : '<span style="color:var(--ink-mute)">&mdash;</span>';
            } else {
                $bits = [];
                foreach (array_slice($doing, 0, 3, true) as $label => $n) {
                    $bits[] = '<span class="pill">' . e($label) . ' <strong>' . (int) $n . '</strong></span>';
                }
                if (count($doing) > 3) {
                    $bits[] = '<span style="color:var(--ink-mute)">+' . (count($doing) - 3) . ' more</span>';
                }
                echo implode(' ', $bits);
            }
          ?></td>

          <td class="num"><?= $sm['open'] ?></td>
          <td class="num tone-good"><?= $sm['completed'] ?></td>
          <td class="num<?= $sm['overdue'] ? ' tone-risk' : '' ?>"><?= $sm['overdue'] ?></td>
          <td class="num<?= $sm['due_soon'] ? ' tone-warn' : '' ?>"><?= $sm['due_soon'] ?></td>
          <td><div style="display:flex;align-items:center;gap:8px"><div class="bar <?= $barTone ?>" style="flex:1"><i style="width:<?= $sm['pct_done'] ?>%"></i></div><span style="font-size:12px;color:var(--ink-soft);min-width:34px"><?= $sm['pct_done'] ?>%</span></div></td>

          <?php /* What they have actually put in, as against what they were
                   given. Ten jobs and no edits is a different conversation
                   from ten jobs and daily work. */ ?>
          <td><?php $act = fabo_team_activity((int) $m['id']); ?>
            <?php if ($act['edits'] === 0): ?>
              <span style="color:var(--ink-mute)">nothing yet</span>
            <?php else: ?>
              <strong><?= (int) $act['rows'] ?></strong> row<?= $act['rows'] === 1 ? '' : 's' ?>
              <div style="font-size:11.5px;color:var(--ink-soft)">
                <?= (int) $act['edits'] ?> edit<?= $act['edits'] === 1 ? '' : 's' ?>
                across <?= (int) $act['sheets'] ?> sheet<?= $act['sheets'] === 1 ? '' : 's' ?>
              </div>
            <?php endif; ?>
          </td>

          <td><?php [$word, $tone] = fabo_team_activity_words($act); ?>
            <span class="pill <?= e($tone === 'good' ? 'ok' : $tone) ?>"><?= e($word) ?></span>
            <?php if ($act['last']): ?>
              <div style="font-size:11.5px;color:var(--ink-soft)">
                <?= $act['days'] === 0 ? 'today'
                    : ($act['days'] === 1 ? 'yesterday' : (int) $act['days'] . ' days ago') ?>
              </div>
            <?php elseif (!empty($m['last_login_at'])): ?>
              <div style="font-size:11.5px;color:var(--ink-soft)">signed in, no edits</div>
            <?php elseif (!empty($m['open_count'])): ?>
              <div style="font-size:11.5px;color:var(--ink-soft)">opened the link</div>
            <?php else: ?>
              <div style="font-size:11.5px;color:var(--ink-mute)">never signed in</div>
            <?php endif; ?>
          </td>

          <td><a class="btn btn-sm" href="team.php?id=<?= (int) $m['id'] ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <details class="addbox"<?= $addOpen ? ' open' : '' ?>>
    <summary><span class="chev">&#43;</span> Add a new team member</summary>
    <div class="card">
    <form method="post" enctype="multipart/form-data">
      <?= fabo_csrf_field() ?>
      <div class="fieldrow">
        <div class="field"><label>Name</label><input name="name" required placeholder="e.g. Priya Nair"></div>
        <div class="field"><label>Email</label><input name="email" type="email" placeholder="to send the link"></div>
        <div class="field">
          <label>Owner / Team value (ties rows to this person)</label>
          <?php fabo_list_field('owner_name', 'Owner / Team', '', $viewer, '../api/',
                                '— not linked to rows —'); ?>
          <div class="hint">Rows whose Owner is this value count as this person's work and
            show on their My Work list. Add to this dropdown from any sheet.</div>
        </div>
      </div>
      <div class="fieldrow two">
        <div class="field"><label>Note</label>
          <input name="contact_note" placeholder="role, phone, anything useful"></div>
        <div class="field"><label>Photo</label>
          <input type="file" name="image" accept="image/*" style="font-size:13px">
          <div class="hint">Optional.</div></div>
      </div>

      <div class="field cm-tick">
        <label><input type="checkbox" name="is_committee" value="1"> Committee member</label>
        <div class="hint">Tick for a committee person: they get a Committee Dashboard with the events
          assigned to their committee, to approve or reject, plus the sheets you choose below. Only people ticked
          here can be put on a committee in Sheet Management &rarr; Committees.</div>
      </div>

      <div class="formsec">
        <h3>Sheets they can work in</h3>
        <p class="lead">Change any of this later from their page.</p>
        <div class="sheetgrid">
        <?php foreach ($sheetKeys as $key): $isDash = $key === 'dashboard'; ?>
          <div class="row">
            <span class="name"><?= e(fabo_sheet_label($key)) ?></span>
            <select name="perm[<?= e($key) ?>]">
              <option value="none">No access</option>
              <option value="view"<?= $isDash ? ' selected' : '' ?>>View only</option>
              <?php if (!$isDash): ?><option value="edit" selected>Can edit</option><?php endif; ?>
            </select>
          </div>
        <?php endforeach; ?>
        </div>
      </div>

      <div class="formsec">
        <h3>Modules they can run</h3>
        <p class="lead">For our own company. They open these from their own dashboard menu.</p>
        <div class="sheetgrid">
        <?php foreach ($moduleRows as $key => [$label, $hint]): ?>
          <div class="row" title="<?= e($hint) ?>">
            <span class="name"><?= e($label) ?></span>
            <select name="perm[<?= e($key) ?>]">
              <option value="none" selected>No access</option>
              <option value="view">View only</option>
              <option value="edit">Owner</option>
            </select>
          </div>
        <?php endforeach; ?>
        </div>
      </div>


      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="create">Create member &amp; link</button>
        <span class="note">Their link and password appear on the next screen.</span>
      </div>
    </form>
    </div>
  </details>

  <div class="block">
    <h2>Workload by owner — every sheet that names one (team average of open items: <?= e(number_format($avgOpen, 1)) ?>)</h2>
    <div class="scrollx"><table class="data">
      <thead><tr><th>Owner / Team</th><th>Balance</th><th style="min-width:150px">Open load</th>
        <th class="num">Assigned</th><th class="num">Completed</th><th class="num">Open</th><th class="num">Overdue</th>
        <th class="num">Due in 7 days</th><th>Where the work is</th></tr></thead>
      <tbody>
      <?php foreach ($byOwner as $o => $its):
          $sm = fabo_work_summary($its);
          [$tone, $label] = $o === '(unassigned)' ? ['muted', 'Needs an owner'] : fabo_balance($sm['open'], $avgOpen);
          $mix = [];
          arsort($sm['sheets']);
          foreach ($sm['sheets'] as $k => $n) { $mix[] = fabo_sheet_label($k) . ' ' . $n; }
      ?>
        <tr>
          <td><strong><?= e($o) ?></strong></td>
          <td><span class="pill tone-<?= e($tone) ?>"><i class="dot"></i><?= e($label) ?></span></td>
          <td><div class="bar <?= e($tone === 'good' ? '' : $tone) ?>"><i style="width:<?= (int) round($sm['open'] / $maxOpen * 100) ?>%"></i></div></td>
          <td class="num"><?= $sm['assigned'] ?></td>
          <td class="num"><?= $sm['completed'] ?></td>
          <td class="num"><?= $sm['open'] ?></td>
          <td class="num<?= $sm['overdue'] ? ' tone-risk' : '' ?>"><?= $sm['overdue'] ?></td>
          <td class="num<?= $sm['due_soon'] ? ' tone-warn' : '' ?>"><?= $sm['due_soon'] ?></td>
          <td style="font-size:12px;color:var(--ink-soft);white-space:normal"><?= e(implode(' · ', $mix)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="block">
    <h2>Charts</h2>
    <div class="chartgrid">
<?php
    $names = array_values($teamOwners);
    fabo_chart_bar('Open items by owner — overdue vs on schedule', $names, [
        ['name' => 'On schedule', 'color' => FABO_BLUE,
         'values' => array_map(static fn($n) => $openBy[$n] - count(array_filter($byOwner[$n], static fn($i) => $i['overdue'])), $names)],
        ['name' => 'Overdue', 'color' => FABO_ORANGE,
         'values' => array_map(static fn($n) => count(array_filter($byOwner[$n], static fn($i) => $i['overdue'])), $names)],
    ]);
    fabo_chart_bar('Assigned vs completed by owner', $names, [
        ['name' => 'Assigned',  'color' => FABO_BLUE, 'values' => array_map(static fn($n) => count($byOwner[$n]), $names)],
        ['name' => 'Completed', 'color' => FABO_AQUA, 'values' => array_map(static fn($n) => count(array_filter($byOwner[$n], static fn($i) => $i['closed'])), $names)],
    ]);
?>
    </div>
  </div>
</div>
<?php fabo_foot();
