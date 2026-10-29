<?php
/**
 * Admin — Clients. Create a client, assign sheets, set validity, rotate or
 * revoke the link, and handle access requests.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/rules.php';
require_once __DIR__ . '/../lib/outbox.php';
require_once __DIR__ . '/../lib/work.php';
require_once __DIR__ . '/../lib/uploads.php';
require_once __DIR__ . '/../lib/notify.php';
require_once __DIR__ . '/../lib/people.php';
fabo_boot();
fabo_require_admin();

$viewer = fabo_admin_viewer();
$flash  = null;


/** Duration choices offered for a link's validity. */
function fabo_durations(): array
{
    return [
        '24h'   => '24 hours',
        '7d'    => '7 days',
        '30d'   => '30 days',
        '90d'   => '90 days',
        'never' => 'No expiry',
    ];
}

function fabo_valid_scope(string $v, array $allowed): string
{
    return in_array($v, $allowed, true) ? $v : 'both';
}

function fabo_expiry_for(string $choice): ?string
{
    switch ($choice) {
        case '24h': return date('Y-m-d H:i:s', strtotime('+24 hours'));
        case '7d':  return date('Y-m-d H:i:s', strtotime('+7 days'));
        case '30d': return date('Y-m-d H:i:s', strtotime('+30 days'));
        case '90d': return date('Y-m-d H:i:s', strtotime('+90 days'));
        default:    return null;
    }
}

/* ------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $flash = ['err', 'A client name is required.'];
        } else {
            $token = fabo_new_token();
            $newId = fabo_exec(
                'INSERT INTO clients (name, kind, email, organisation, address, contact_note,
                    token, expires_at)
                 VALUES (?,\'client\',?,?,?,?,?,?)',
                'sssssss',
                [
                    $name,
                    trim((string) ($_POST['email'] ?? '')),
                    trim((string) ($_POST['organisation'] ?? '')),
                    trim((string) ($_POST['address'] ?? '')),
                    trim((string) ($_POST['contact_note'] ?? '')),
                    $token,
                    fabo_expiry_for($_POST['duration'] ?? '30d'),
                ]
            );
            /* A committee member signs in to their Committee Dashboard, and
               opens whichever sheets they are given as well. */
            $isCm = !empty($_POST['is_committee']) ? 1 : 0;
            fabo_exec('UPDATE clients SET is_committee = ? WHERE id = ?', 'ii', [$isCm, $newId]);
            // Sheet and module access chosen on the create form lands in the
            // same step. fabo_grant_clamp() decides what each key may carry.
            foreach ((array) ($_POST['perm'] ?? []) as $key => $perm) {
                $perm = fabo_grant_clamp((string) $key, (string) $perm);
                if ($perm !== 'none') {
                    fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission)
                               VALUES (?,?,?)', 'iss', [$newId, $key, $perm]);
                }
            }
            fabo_save_person_image($newId, $_FILES['image'] ?? null);
            fabo_save_rights($newId, $_POST);
            // Our own CRMs shared with them, one at a time.
            require_once __DIR__ . '/../lib/crm.php';
            fabo_crm_save_access($newId, $_POST, fabo_crm_boards_owned(0));

            /* No password is invented for them. The welcome carries an
               invitation — a link that opens a page where they choose their
               own — so nothing the portal sends can sign anybody in on its
               own, and a second account on the same mailbox gets its own
            /* A password, issued now and printed in the message. The sign-in page
               makes them replace it the first time they use it, so what travels
               by email stops being usable the moment it has done its job. */
            $plain = fabo_set_password($newId);
            // Their own web address, from their organisation name (lib/orghost.php); the welcome carries it once it works.
            if (!$isCm) {                                    // a committee member is a person, not an organisation
                fabo_org_host_assign($newId);
                fabo_link_org($newId);
            }
            $fresh = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$newId]);
            $base  = fabo_portal_url();
            [$sent, $detail] = fabo_announce_welcome(
                $fresh, fabo_client_access($newId),
                $base . 'c.php?k=' . $fresh['token'], $base . 'login.php', $plain
            );
            fabo_session();
            $_SESSION['fabo_mail'] = $sent
                ? 'Client created and their sign-in details were emailed — ' . $detail . '.'
                : 'Client created. The email did not go (' . $detail
                  . ') — their password is ' . $plain . ', send it yourself.';
            // If the mail did not go, $plain above is what to pass on by hand.

            header('Location: clients.php?id=' . $newId . '&created=1');
            exit;
        }
    }

    if ($id > 0 && fabo_one('SELECT id FROM clients WHERE id = ?', 'i', [$id])) {
        // Mail sent from here (credentials, invitations) carries their organisation's own address when it has one.
        fabo_link_org(fabo_org_head($id));
        switch ($action) {
            case 'web_host_save':
            case 'web_host_check':
                [$ok, $msg] = $action === 'web_host_save'
                    ? fabo_org_host_save($id, (string) ($_POST['web_host'] ?? ''))
                    : fabo_org_host_check($id);
                $flash = [$ok ? 'ok' : 'err', $msg];
                break;

            case 'save':
            case 'save_send':
                $wasRow = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                fabo_exec(
                    'UPDATE clients SET name=?, email=?, organisation=?, address=?, contact_note=?
                     WHERE id=?',
                    'sssssi',
                    [
                        trim((string) ($_POST['name'] ?? '')),
                        trim((string) ($_POST['email'] ?? '')),
                        trim((string) ($_POST['organisation'] ?? '')),
                        trim((string) ($_POST['address'] ?? '')),
                        trim((string) ($_POST['contact_note'] ?? '')),
                        $id,
                    ]
                );
                $isCm = !empty($_POST['is_committee']) ? 1 : 0;
                fabo_exec('UPDATE clients SET is_committee = ? WHERE id = ?', 'ii', [$isCm, $id]);
                if (!$isCm && !empty($wasRow['is_committee'])) {
                    require_once __DIR__ . '/../lib/committees.php';
                    fabo_cm_drop_person($id, 'Admin');
                }
                // Sheet and module access arrive as perm[key] = none|view|edit
                $before = fabo_client_access($id);
                fabo_exec('DELETE FROM client_sheet_access WHERE client_id=?', 'i', [$id]);
                foreach ((array) ($_POST['perm'] ?? []) as $key => $perm) {
                    $perm = fabo_grant_clamp((string) $key, (string) $perm);
                    if ($perm !== 'none') {
                        fabo_exec('INSERT INTO client_sheet_access (client_id, sheet_key, permission)
                                   VALUES (?,?,?)', 'iss', [$id, $key, $perm]);
                    }
                }
                fabo_save_rights($id, $_POST);      // admin may grant anything
                // Whatever was taken away from them is taken from their sub-accounts too.
                fabo_recap_descendants($id);
                /* And whatever was given or raised reaches their whole team: the
                   sheets this save added, at the head's level. See
                   fabo_flow_sheets_to_team(). */
                $rankP  = ['none' => 0, 'view' => 1, 'edit' => 2];
                $raised = [];
                foreach (fabo_client_access($id) as $k => $p) {
                    if (($rankP[$p] ?? 0) > ($rankP[$before[$k] ?? 'none'] ?? 0)) {
                        $raised[] = $k;
                    }
                }
                $teamFlow = $raised ? fabo_flow_sheets_to_team($id, $raised) : [];
                require_once __DIR__ . '/../lib/crm.php';
                fabo_crm_save_access($id, $_POST, fabo_crm_boards_owned(0));
                fabo_save_mailbox($id, $_POST);
                $imgFlash = fabo_save_person_image($id, $_FILES['image'] ?? null,
                                                   !empty($_POST['remove_image']));

                $after = fabo_client_access($id);
                $note  = '';
                if ($before !== $after) {
                    $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                    $url    = fabo_portal_url() . 'c.php?k=' . $person['token'];
                    [$sent, $detail] = fabo_announce_access($person, $after, $url);
                    $note = $sent
                        ? ' Sheet access changed — email ' . $detail . ', and a notice is on their portal.'
                        : ' Sheet access changed — a notice is on their portal; email not sent (' . $detail . ').';
                }
                if ($teamFlow) {
                    $note .= ' The new sheet access was also given to ' . count($teamFlow) . ' of their team member'
                           . (count($teamFlow) === 1 ? '' : 's') . ', who have been told.';
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
                $flash = $imgFlash && $imgFlash[0] === 'err'
                       ? $imgFlash
                       : ['ok', 'Saved.' . $note];
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

            case 'validity':
                fabo_exec('UPDATE clients SET expires_at=?, revoked_at=NULL WHERE id=?', 'si',
                          [fabo_expiry_for($_POST['duration'] ?? '30d'), $id]);
                $flash = ['ok', 'Validity updated.'];
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

            case 'rotate':
                fabo_exec('UPDATE clients SET token=? WHERE id=?', 'si', [fabo_new_token(), $id]);
                $flash = ['ok', 'New link generated — the old one no longer works.'];
                break;

            case 'revoke':
                fabo_exec('UPDATE clients SET revoked_at=NOW() WHERE id=?', 'i', [$id]);
                $flash = ['ok', 'Link revoked.'];
                break;

            case 'reopen':
                fabo_exec('UPDATE clients SET expires_at=?, revoked_at=NULL,
                           access_requested_at=NULL, access_request_note=NULL WHERE id=?',
                          'si', [fabo_expiry_for($_POST['duration'] ?? '30d'), $id]);
                $flash = ['ok', 'Reopened — the same URL works again.'];
                break;

            case 'dismiss':
                fabo_exec('UPDATE clients SET access_requested_at=NULL,
                           access_request_note=NULL WHERE id=?', 'i', [$id]);
                $flash = ['ok', 'Request dismissed.'];
                break;
        }
    }
}

$editing = null;
if (!empty($_GET['id'])) {
    $editing = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $_GET['id']]);
    if ($editing && !empty($_GET['created'])) {
        fabo_session();
        $flash = ['ok', $_SESSION['fabo_mail'] ?? 'Client created.'];
        $newPassword = $_SESSION['fabo_pw'] ?? '';
        unset($_SESSION['fabo_mail'], $_SESSION['fabo_pw']);
    }
}

$newPassword = $newPassword ?? '';
// A create that failed re-opens the box, rather than hiding the reason.
$addOpen = is_array($flash) && $flash[0] === 'err' && ($_POST['action'] ?? '') === 'create';
$clients  = fabo_all("SELECT * FROM clients WHERE kind = 'client' ORDER BY access_requested_at IS NULL, created_at DESC");
$accessMap = fabo_access_map(array_column($clients, 'id'));
$childMap  = fabo_children_counts(array_column($clients, 'id'));
$requests = array_filter($clients, static fn($c) => !empty($c['access_requested_at']));

if ($editing) {
    // An address still waiting to work is tried again on opening, and switched on if it now does.
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($editing['web_host']) && empty($editing['web_host_ok_at'])) {
        fabo_org_host_check((int) $editing['id']);
        $editing = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [(int) $editing['id']]);
    }
    fabo_link_org(fabo_org_head((int) $editing['id']));     // their link and sign-in page, at their own address if they have one
}
$baseUrl = fabo_portal_url();

fabo_head($viewer, 'Clients', 'clients');   // lights the Clients tab in the top bar
?>
<div class="wrap-page">

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($requests): ?>
  <div class="card" style="border-color:#f0dcb4;background:#fffdf7">
    <h2>Access requests</h2>
    <?php foreach ($requests as $r): ?>
      <div style="padding:10px 0;border-top:1px solid var(--line-soft)">
        <strong><?= e($r['name']) ?></strong>
        <?php if ($r['organisation']): ?>
          <span style="color:var(--ink-soft)">· <?= e($r['organisation']) ?></span>
        <?php endif; ?>
        <div style="font-size:12px;color:var(--ink-mute);margin:3px 0 6px">
          Requested <?= e(date('d M Y, H:i', strtotime($r['access_requested_at']))) ?>
        </div>
        <?php if ($r['access_request_note']): ?>
          <div style="font-size:13px;margin-bottom:8px">“<?= e($r['access_request_note']) ?>”</div>
        <?php endif; ?>
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= fabo_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <select name="duration" style="font:inherit;font-size:13px;padding:6px 9px;border:1px solid var(--line);border-radius:6px">
            <?php foreach (fabo_durations() as $k => $lab): ?>
              <option value="<?= e($k) ?>"<?= $k === '30d' ? ' selected' : '' ?>><?= e($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" name="action" value="reopen">Reopen</button>
          <button class="btn btn-sm" name="action" value="dismiss">Dismiss</button>
          <button class="btn btn-danger btn-sm" name="action" value="revoke"
                  onclick="return confirm('Revoke this link permanently?')">Revoke</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($editing): ?>
  <?php
    $access = fabo_client_access((int) $editing['id']);
    $state  = fabo_client_state($editing);
    $url    = $baseUrl . 'c.php?k=' . $editing['token'];
  ?>
  <div class="card">
    <h2 style="display:flex;align-items:center;gap:12px">
      <?= fabo_avatar($editing) ?>
      <span><?= e($editing['name']) ?>
        <span class="pill <?= e($state) ?>"><?= e($state === 'ok' ? 'active' : $state) ?></span></span>
    </h2>

    <div class="field">
      <label>Client URL</label>
      <?php [$subj, $bodyTxt] = fabo_link_message($editing, $url); ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="tokenlink" id="clienturl"><?= e($url) ?></span>
        <button class="btn btn-sm" type="button"
                onclick="navigator.clipboard.writeText(document.getElementById('clienturl').textContent);this.textContent='Copied'">Copy link</button>
      </div>
      <?php
        /* One mailbox, several accounts: the address cannot say which one is
           signing in, so the page says so where somebody would otherwise sit
           trying passwords. */
        $shared = trim((string) ($editing['email'] ?? '')) === '' ? 0
                : (int) (fabo_one('SELECT COUNT(*) c FROM clients WHERE email = ?',
                                  's', [(string) $editing['email']])['c'] ?? 0);
      ?>
      <?php if ($shared > 1): ?>
        <div class="sharednote">
          <strong>This address is used by <?= (int) $shared ?> accounts.</strong>
          Signing in with the address alone will not do — the password has to be
          the one for this account. Send them the link above, which opens this
          account with no password, or have them sign in with the user ID below.
        </div>
      <?php endif; ?>
      <div class="signinbox">
        <strong>Their dashboard sign-in</strong>
        <div class="pair"><span>Page</span><code><?= e($baseUrl) ?>login.php</code></div>
        <div class="pair"><span>Email</span><code><?= e($editing['email'] ?: '— add an email below —') ?></code></div>
        <div class="pair"><span>User ID</span><code><?= e((string) ($editing['login_id'] ?? '')) ?: '—' ?></code></div>
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
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
        <a class="btn btn-primary btn-sm" href="mailto:<?= e(rawurlencode((string) $editing['email'])) ?>?subject=<?= e(rawurlencode($subj)) ?>&amp;body=<?= e(rawurlencode($bodyTxt)) ?>"
           title="<?= $editing['email'] ? 'Opens your mail app with the link filled in' : 'Add an email address below first' ?>">
          &#9993; Send by email<?= $editing['email'] ? '' : ' (no address yet)' ?></a>
        <a class="btn btn-sm" target="_blank" rel="noopener"
           href="https://wa.me/?text=<?= e(rawurlencode($bodyTxt)) ?>">Send on WhatsApp</a>
        <button class="btn btn-sm" type="button" id="copymsg"
                onclick="navigator.clipboard.writeText(this.getAttribute('data-msg'));this.textContent='Message copied'"
                data-msg="<?= e($bodyTxt) ?>">Copy message</button>
      </div>
      <div class="hint">
        <?php if ($editing['expires_at']): ?>
          Valid until <?= e(date('d M Y, H:i', strtotime($editing['expires_at']))) ?>.
        <?php else: ?>
          No expiry.
        <?php endif; ?>
        Opened <?= (int) $editing['open_count'] ?> time<?= (int) $editing['open_count'] === 1 ? '' : 's' ?><?php
          if ($editing['last_opened_at']) {
              echo ', last ' . e(date('d M Y, H:i', strtotime($editing['last_opened_at'])));
          }
        ?>.
      </div>
    </div>

    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:18px">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <select name="duration" style="font:inherit;font-size:13px;padding:6px 9px;border:1px solid var(--line);border-radius:6px">
        <?php foreach (fabo_durations() as $k => $lab): ?>
          <option value="<?= e($k) ?>"><?= e($lab) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm" name="action" value="validity">Set validity</button>
      <button class="btn btn-sm" name="action" value="rotate"
              onclick="return confirm('Generate a new URL? The current link stops working.')">Rotate token</button>
      <?php if ($state === 'revoked'): ?>
        <button class="btn btn-sm" name="action" value="reopen">Reopen</button>
      <?php else: ?>
        <button class="btn btn-danger btn-sm" name="action" value="revoke"
                onclick="return confirm('Revoke this link?')">Revoke</button>
      <?php endif; ?>
    </form>

    <?php if (($editing['kind'] ?? 'client') === 'client' && (int) ($editing['parent_id'] ?? 0) === 0):
        /* Their organisation's own web address (lib/orghost.php). */
        $wh = (string) ($editing['web_host'] ?? '');
        $whSuggest = fabo_org_host_normalise((string) ($editing['organisation'] ?: $editing['name'])); ?>
    <div class="signinbox" style="margin-bottom:18px">
      <strong>Their own web address</strong>
      <div class="hint">Their links, emails and sign-in page use this address instead of
        <?= e(fabo_main_host()) ?>. Links already shared keep working, and their people who open
        the old address are moved across, still signed in.</div>
      <?php if ($wh !== ''): ?>
        <div class="pair"><span>Address</span><code>https://<?= e($wh) ?></code></div>
        <div class="pair"><span>Status</span><?= $editing['web_host_ok_at']
            ? '<span class="pill ok">in use since ' . e(date('d M Y, H:i', strtotime((string) $editing['web_host_ok_at']))) . '</span>'
            : '<span class="pill expired">saved, not set up yet</span>' ?></div>
        <?php if (!$editing['web_host_ok_at']): ?>
          <div class="hint">Nothing changes for them until this works. In hPanel: <strong>Domains → Subdomains</strong>, create
            <code><?= e(explode('.', $wh, 2)[0]) ?></code> on <?= e(explode('.', $wh, 2)[1] ?? '') ?> with the folder
            <code>public_html/marketing-command-centre</code>, wait a few minutes for its SSL, then press Check again.
            (Another domain, e.g. portal.theirs.com: point it at this server in its DNS and add it in hPanel the same way.)</div>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
        <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <input name="web_host" value="<?= e($wh ?: $whSuggest) ?>" placeholder="e.g. <?= e($whSuggest ?: 'their-name.' . ORG_HOST_BASE) ?>"
               style="min-width:300px;flex:1 1 300px" aria-label="Web address">
        <button class="btn btn-primary btn-sm" name="action" value="web_host_save">Save &amp; check</button>
        <?php if ($wh !== ''): ?>
          <button class="btn btn-sm" name="action" value="web_host_check">Check again</button>
        <?php endif; ?>
      </form>
      <div class="hint">A name (e.g. <em>Ratan Tata Innovation Hub</em>) becomes <em>ratan-tata-innovation-hub.<?= e(ORG_HOST_BASE) ?></em>.
        Clear the box and save to go back to the main address.</div>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <div class="fieldrow">
        <div class="field">
          <label>Name</label>
          <input name="name" value="<?= e($editing['name']) ?>" required>
        </div>
        <div class="field">
          <label>Email</label>
          <input name="email" type="email" value="<?= e($editing['email']) ?>" placeholder="for sending the link">
        </div>
        <div class="field">
          <label>Organisation</label>
          <input name="organisation" value="<?= e($editing['organisation']) ?>">
        </div>
      </div>
      <div class="field">
        <label>Client image / logo</label>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <?= fabo_avatar($editing) ?>
          <div>
            <input type="file" name="image" accept="image/*" style="font-size:13px">
            <div class="hint">JPG, PNG, GIF or WebP, up to 25 MB.<?php if (!empty($editing['image_file'])): ?>
              <label style="margin-left:10px"><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
            <?php endif; ?></div>
          </div>
        </div>
      </div>
      <?php require_once __DIR__ . '/../lib/outbox.php'; fabo_outbox_block((int) $editing['id'], true); ?>
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

      <?php /* Right under the logo, because this is the button somebody
               actually came here to press: change a detail, send them their
               details. It sends every time, for every client, whether or not
               anything on the record moved and whether or not somebody else
               shares the address. */ ?>
      <div class="credbar">
        <div>
          <strong>Update &amp; send credentials</strong>
          <div class="hint">Saves the changes, issues a fresh password and emails it
            with their user ID and the sign-in link &mdash; every time, even if the
            address is shared with another client. They are asked to change it the
            first time they sign in.</div>
        </div>
        <button class="btn btn-primary" name="action" value="save_send">
          Update &amp; send credentials</button>
      </div>

      <div class="fieldrow two">
        <div class="field">
          <label>Billing address</label>
          <textarea name="address" placeholder="Appears on their invoices"><?= e($editing['address'] ?? '') ?></textarea>
        </div>
        <div class="field">
          <label>Contact note</label>
          <textarea name="contact_note"><?= e($editing['contact_note']) ?></textarea>
        </div>
      </div>

      <div class="field cm-tick">
        <label><input type="checkbox" name="is_committee" value="1"
                      <?= !empty($editing['is_committee']) ? 'checked' : '' ?>> Committee member</label>
        <div class="hint">A committee member gets a Committee Dashboard: the events sent to the
          committees they sit on, which they approve or reject, plus any sheets you give them below. Put them on a
          committee in Sheet Management &rarr; Committees.</div>
      </div>

      <div class="field">
        <label>Sheet and module access</label>
        <table class="data accesstable">
          <thead><tr><th>Sheet</th><th>No access</th><th>View only</th><th>Can edit</th></tr></thead>
          <tbody>
          <?php foreach (fabo_grantable_keys() as $key):
              $cur      = $access[$key] ?? 'none';
              $viewOnly = fabo_grant_view_only($key);
          ?>
            <tr>
              <td><?= e(fabo_grantable_label($key)) ?><?= $viewOnly ? ' <span class="pill view">read-only</span>' : '' ?></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="none" <?= $cur === 'none' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="view" <?= $cur === 'view' ? 'checked' : '' ?>></td>
              <td><?php if ($viewOnly): ?>&mdash;<?php else: ?>
                <input type="radio" name="perm[<?= e($key) ?>]" value="edit" <?= $cur === 'edit' ? 'checked' : '' ?>>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="hint">The Lists sheet is admin-only and is never offered to a client.
          Finance is read-only however it is shared &mdash; a bill is ours to change, not theirs.
          HR &mdash; their own covers their company only: Can edit lets them run it in full (employees, attendance,
          leave, payroll, and which of their team may use HR); View only lets them read and export it.
          Incubation Management &mdash; their own is a separate, empty Incubation Management module for their organisation (never yours):
          Can edit makes them its owner, View only lets them read and export it, and they may pass it on to their own team.</div>
      </div>
      <?php require_once __DIR__ . '/../lib/crm.php';
            echo fabo_crm_access_section(fabo_crm_boards_owned(0), (int) $editing['id']); ?>

      <div class="formsec">
        <h3>Account rights</h3>
        <p class="lead">Granted the same way as a sheet, and taken back the same way.</p>
        <?= fabo_rights_table($editing) ?>
      </div>

      <div class="formsec">
        <h3>Their mailbox</h3>
        <p class="lead">Used only while <strong>Send from their own mailbox</strong> is ticked above.
          Anyone they create sends through this same mailbox.</p>
        <?= fabo_mailbox_fields($editing) ?>
      </div>

      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="save_send">
          Update &amp; send credentials</button>
        <button class="btn" name="action" value="save">Save only</button>
        <a class="btn" href="clients.php">Done</a>
      </div>
      <p class="hint"><strong>Save only</strong> changes the record and sends nothing.</p>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Clients</h2>
  <?php if (!$clients): ?>
    <div class="empty">No clients yet. Use <strong>Add a new client</strong> below.</div>
  <?php else: ?>
    <div class="scrollx">
    <table class="data">
      <thead><tr>
        <th>Name</th><th>Email</th><th>Organisation</th><th>Sheets</th>
        <th>Status</th><th>Valid until</th><th>Opens</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($clients as $c):
        $acc = $accessMap[(int) $c['id']] ?? [];
        $st  = fabo_client_state($c);
      ?>
        <tr>
          <td><span style="display:flex;align-items:center;gap:8px"><?= fabo_avatar($c, 'sm') ?>
              <a href="clients.php?id=<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a>
              <?php if (!empty($c['is_committee'])): ?><span class="pill">Committee</span><?php endif; ?></span></td>
          <td style="color:var(--ink-soft)"><?= e($c['email']) ?></td>
          <td><?= e($c['organisation']) ?><?php
            if (fabo_may_make($c)) {
                $n    = $childMap[(int) $c['id']] ?? 0;
                /* Which of the two they hold, because "own people" stopped
                   meaning one thing the moment it became two rights. */
                $what = fabo_may_make($c, 'client') && fabo_may_make($c, 'team') ? 'own people'
                      : (fabo_may_make($c, 'client') ? 'own clients' : 'own team');
                echo ' <span class="pill view">' . $what . ($n ? ': ' . $n : '') . '</span>';
            }
            if (!empty($c['can_use_own_mail'])) {
                echo ' <span class="pill view">own mail</span>';
            } ?></td>
          <td><?php
            if (!$acc) {
                echo '<span style="color:var(--ink-mute)">none</span>';
            } else {
                $bits = [];
                foreach ($acc as $k => $p) {
                    $bits[] = '<span class="pill ' . e($p) . '">' . e(fabo_sheet_label($k)) . '</span>';
                }
                echo implode(' ', $bits);
            }
          ?></td>
          <td><span class="pill <?= e($st) ?>"><?= e($st === 'ok' ? 'active' : $st) ?></span></td>
          <td><?= $c['expires_at'] ? e(date('d M Y, H:i', strtotime($c['expires_at']))) : '—' ?></td>
          <td class="num"><?= (int) $c['open_count'] ?></td>
          <td><a class="btn btn-sm" href="clients.php?id=<?= (int) $c['id'] ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<details class="addbox"<?= $addOpen ? ' open' : '' ?>>
  <summary><span class="chev">&#43;</span> Add a new client</summary>
  <div class="card">
  <form method="post" enctype="multipart/form-data">
    <?= fabo_csrf_field() ?>

    <div class="formsec">
      <h3>Who they are</h3>
      <div class="fieldrow">
        <div class="field">
          <label>Name</label>
          <input name="name" required placeholder="e.g. Ravi Menon">
        </div>
        <div class="field">
          <label>Email</label>
          <input name="email" type="email" placeholder="where their sign-in details go">
        </div>
        <div class="field">
          <label>Organisation</label>
          <input name="organisation" placeholder="e.g. Menon Franchise Group">
          <div class="hint">Their portal and their emails carry this name, not ours.</div>
        </div>
      </div>
      <div class="fieldrow two">
        <div class="field">
          <label>Client image / logo</label>
          <input type="file" name="image" accept="image/*" style="font-size:13px">
          <div class="hint">Optional. JPG, PNG, GIF or WebP.</div>
        </div>
        <div class="field">
          <label>Billing address</label>
          <textarea name="address" placeholder="Appears on their invoices"></textarea>
        </div>
        <div class="field">
          <label>Contact note</label>
          <textarea name="contact_note" placeholder="Anything worth remembering about this contact"></textarea>
        </div>
      </div>
    </div>

    <div class="formsec">
      <h3>What they may see</h3>
      <p class="lead">Scope filters every sheet before they open it; validity controls how long their link lasts.</p>
      <div class="fieldrow">
        <div class="field">
          <label>Link valid for</label>
          <select name="duration">
            <?php foreach (fabo_durations() as $k => $lab): ?>
              <option value="<?= e($k) ?>"<?= $k === '30d' ? ' selected' : '' ?>><?= e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="field cm-tick">
      <label><input type="checkbox" name="is_committee" value="1"> Committee member</label>
      <div class="hint">Tick for a committee person: they get a Committee Dashboard with the events
        assigned to their committee, to approve or reject, plus the sheets you choose below. Only people ticked
        here can be put on a committee in Sheet Management &rarr; Committees.</div>
    </div>

    <div class="formsec">
      <h3>Sheet and module access</h3>
      <p class="lead">Change any of this later from their page. The Lists sheet is
         admin-only and is never offered. Finance covers their invoices and any
         filing we handle for them, and is read-only however it is shared.</p>
      <div class="sheetgrid">
      <?php foreach (fabo_grantable_keys() as $key):
          $viewOnly = fabo_grant_view_only($key); ?>
        <div class="row">
          <span class="name"><?= e(fabo_grantable_label($key)) ?></span>
          <select name="perm[<?= e($key) ?>]">
            <option value="none">No access</option>
            <option value="view">View only</option>
            <?php if (!$viewOnly): ?><option value="edit">Can edit</option><?php endif; ?>
          </select>
        </div>
      <?php endforeach; ?>
      </div>
    </div>

    <?php require_once __DIR__ . '/../lib/crm.php';
          echo fabo_crm_access_section(fabo_crm_boards_owned(0)); ?>

    <div class="formsec">
      <h3>Account rights</h3>
      <p class="lead">Granted the same way as a sheet, and revocable the same way.</p>
      <?= fabo_rights_table([]) ?>
      <div class="hint">Their mailbox details are filled in afterwards, on their own page.</div>
    </div>

    <div class="formfoot">
      <button class="btn btn-primary" name="action" value="create">Create client</button>
      <span class="note">Their link and password appear on the next screen.</span>
    </div>
  </form>
  </div>
</details>

</div>
<?php fabo_foot();
