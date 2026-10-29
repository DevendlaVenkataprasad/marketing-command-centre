<?php
/**
 * My Clients / My Team — a client's own desks.
 *
 * The same two screens the admin portal has, for a client who has been given
 * the run of their own account: ?kind=client lists the clients they created,
 * ?kind=team their own team. One controller, because the two differ only in
 * which half of their people they show and which fields that half needs.
 *
 * Everything they hand out is capped against what they hold themselves; see
 * lib/people.php for that rule.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/outbox.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/lib/teamdash.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    header('Location: login.php?next=' . rawurlencode('people.php'));
    exit;
}
if (!fabo_may_make($viewer['client'] ?? null)) {
    fabo_deny('Your account cannot manage other people.', 403);
}

$me    = $viewer['client'];
$meId  = (int) $me['id'];
$cap   = fabo_delegatable($viewer);
$modCap = fabo_delegatable_modules($me);   // the modules they hold that may pass to their own team (fabo_modules()['team'])
$modLabels = fabo_child_module_labels();   // name and description of each, from fabo_modules()
$flash = null;

// Which desk. When a particular person is open, the desk follows them, so a
// link to a team member never lands on the clients page.
$kind = (($_GET['kind'] ?? '') === 'team') ? 'team' : 'client';
if (!empty($_GET['id'])) {
    $open = fabo_child($meId, (int) $_GET['id']);
    if ($open) {
        $kind = ($open['kind'] === 'team') ? 'team' : 'client';
    }
}
$isTeamDesk = $kind === 'team';
/* Held separately, so checked separately. Somebody given clients and not team
   can type ?kind=team into the address bar, and the answer has to be the same
   as the answer the navigation gives. */
if (!fabo_may_make($viewer['client'] ?? null, $kind)) {
    fabo_deny($isTeamDesk
        ? 'Your account cannot create team members.'
        : 'Your account cannot create clients.', 403);
}

// The Owner / Team values this organisation offers. Read through their own
// customiser, so a client that has edited the list sees theirs and not ours.
//
// Settled here rather than left to the page header: the header runs further
// down, and this list is read now -- so it came out as ours however the
// organisation had edited theirs.
require_once __DIR__ . '/lib/lists.php';
fabo_view_owner(fabo_customiser($viewer));
$owners = fabo_list_values('Owner / Team');
/* Their organisation's own CRMs, offered on their team's pages only: a
   client's CRM data stays inside their organisation, so it is never offered
   to the clients they create. */
require_once __DIR__ . '/lib/crm.php';
/* Offered whenever the head runs CRMs at all -- even before the first one
   exists, so a team member can be given "All our CRMs" when they are added. */
$crmAllowed = $isTeamDesk && fabo_crm_is_head($viewer) && fabo_crm_permission($viewer) === 'edit';
$crmBoards  = $crmAllowed ? fabo_crm_boards_owned($meId) : [];
$deskTitle  = $isTeamDesk ? 'My Team' : 'My Clients';
$deskNav    = $isTeamDesk ? 'people-team' : 'people';
$one        = $isTeamDesk ? 'team member' : 'client';

/** A link that stays on this desk. */
function fabo_desk_link(array $viewer, string $kind, array $q = []): string
{
    if ($kind === 'team') {
        $q = array_merge(['kind' => 'team'], $q);
    }
    return fabo_link($viewer, 'people.php', $q);
}

/**
 * An expiry for one of my people that never runs past my own. A sub-account
 * that outlived its creator would keep the organisation's data open after the
 * creator's access ended.
 */
function fabo_people_clamp(?string $want, array $me): ?string
{
    $mine = $me['expires_at'] ?? null;
    if (empty($mine)) {
        return $want;
    }
    if ($want === null || strtotime($want) > strtotime((string) $mine)) {
        return (string) $mine;
    }
    return $want;
}

/** Validity choices, matching the admin's. */
function fabo_people_durations(): array
{
    return ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days',
            '90d' => '90 days', 'never' => 'No expiry'];
}

function fabo_people_expiry(string $choice): ?string
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
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $flash = ['err', 'A name is required.'];
        } else {
            $newId = fabo_exec(
                'INSERT INTO clients (name, kind, email, owner_name, organisation, contact_note,
                    token, expires_at, parent_id)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                'ssssssssi',
                [
                    $name,
                    $kind,
                    trim((string) ($_POST['email'] ?? '')),
                    trim((string) ($_POST['owner_name'] ?? '')),
                    trim((string) ($_POST['organisation'] ?? '')) ?: (string) $me['organisation'],
                    trim((string) ($_POST['contact_note'] ?? '')),
                    fabo_new_token(),
                    fabo_people_clamp(fabo_people_expiry((string) ($_POST['duration'] ?? '30d')), $me),
                    $meId,
                ]
            );
            /* A committee member signs in to their Committee Dashboard, and
               opens whichever sheets they are given as well. */
            $isCm = !empty($_POST['is_committee']) ? 1 : 0;
            fabo_exec('UPDATE clients SET is_committee = ? WHERE id = ?', 'ii', [$isCm, $newId]);
            $access = fabo_save_child_access($newId, (array) ($_POST['perm'] ?? []), $cap);
            fabo_save_rights($newId, $_POST, $me);   // capped at what I hold
            if ($kind === 'team') {                  // CRMs are shared with their own team only
                fabo_crm_save_access($newId, $_POST, $crmBoards, $crmAllowed);
                $access += fabo_save_child_modules($newId, (array) ($_POST['perm'] ?? []), $modCap);
            }

            /* A password, issued now and printed in the message. The sign-in page
               makes them replace it the first time they use it, so what travels
               by email stops being usable the moment it has done its job. */
            $plain = fabo_set_password($newId);
            $fresh = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$newId]);
            $base  = fabo_portal_url();
            [$sent, $detail] = fabo_announce_welcome(
                $fresh, $access, $base . 'c.php?k=' . $fresh['token'], $base . 'login.php', $plain, $me
            );
            fabo_session();
            $_SESSION['fabo_people_msg'] = $sent
                ? 'Added, and their sign-in details were emailed — ' . $detail . '.'
                : 'Added. Email not sent (' . $detail . ') — the password is below, send it yourself.';
            // If the mail did not go, $plain above is what to pass on by hand.
            header('Location: ' . fabo_desk_link($viewer, $kind, ['id' => $newId, 'created' => 1]));
            exit;
        }

    /* The person must be one of mine AND of the kind this desk manages: the
       desk comes from the address bar, and someone who has lost the right to
       run clients but kept the team right must not reach their clients by
       posting to the team desk. */
    } elseif ($id > 0 && ($child = fabo_child($meId, $id)) && $child['kind'] === $kind) {
        switch ($action) {
            case 'save':
            case 'save_send':
                $wasRow = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                fabo_exec('UPDATE clients SET name=?, email=?, owner_name=?, organisation=?, contact_note=?
                           WHERE id=? AND parent_id=?', 'sssssii',
                          [trim((string) ($_POST['name'] ?? '')), trim((string) ($_POST['email'] ?? '')),
                           trim((string) ($_POST['owner_name'] ?? '')),
                           trim((string) ($_POST['organisation'] ?? '')),
                           trim((string) ($_POST['contact_note'] ?? '')), $id, $meId]);
                $isCm = !empty($_POST['is_committee']) ? 1 : 0;
                fabo_exec('UPDATE clients SET is_committee = ? WHERE id = ? AND parent_id = ?',
                          'iii', [$isCm, $id, $meId]);
                $before = fabo_client_access($id);
                $after  = fabo_save_child_access($id, (array) ($_POST['perm'] ?? []), $cap);
                if ($child['kind'] === 'team') {        // CRMs are shared with their own team only
                    fabo_crm_save_access($id, $_POST, $crmBoards, $crmAllowed);
                    if ($modCap || array_intersect_key($before, array_flip(fabo_child_module_keys()))) {
                        $after += fabo_save_child_modules($id, (array) ($_POST['perm'] ?? []), $modCap);
                    }
                }
                /* No longer a committee member: off this organisation's
                   committees, which are decided again without them. */
                if (!$isCm && !empty($wasRow['is_committee'])) {
                    require_once __DIR__ . '/lib/committees.php';
                    fabo_cm_drop_person($id, (string) ($me['name'] ?? ''));
                }
                fabo_save_rights($id, $_POST, $me);
                fabo_recap_descendants($id);          // and everyone they created in turn
                $note   = '';
                if ($before !== $after) {
                    $person = fabo_one('SELECT * FROM clients WHERE id = ?', 'i', [$id]);
                    [$sent, $detail] = fabo_announce_access(
                        $person, $after, fabo_portal_url() . 'c.php?k=' . $person['token'], $me
                    );
                    $note = $sent
                        ? ' Access changed — email ' . $detail . '.'
                        : ' Access changed — a notice is on their dashboard; email not sent (' . $detail . ').';
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
                    : fabo_announce_identity_change($wasRow, $me);
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
                        $base . 'c.php?k=' . $person['token'], $base . 'login.php', $plain, $me
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
                $flash = ['ok', 'Saved.' . $note];
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
                    $base . 'c.php?k=' . $person['token'], $base . 'login.php', $plain, $me
                );
                $flash = [$sent ? 'ok' : 'err', $sent
                    ? 'New password set and emailed — ' . $detail . '.'
                    : 'New password set, but the email did not go (' . $detail
                      . '). Password: ' . $plain
                      . ', user ID ' . $person['login_id'] . '.'];
                break;


            case 'mail_send':
                /* Offer the kept messages again. Stops at the first "not
                   now", because the rest of the queue will hear the same. */
                [$mSent, $mLeft, $mWhy] = fabo_outbox_flush($id, 10, $me);
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
                if (!is_file(__DIR__ . '/lib/invite.php')) {
                    $flash = ['err', 'Invitations need lib/invite.php, which is not on '
                                   . 'the server yet. Upload it and try again.'];
                    break;
                }
                require_once __DIR__ . '/lib/invite.php';
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
                fabo_exec('UPDATE clients SET expires_at=?, revoked_at=NULL WHERE id=? AND parent_id=?',
                          'sii', [fabo_people_clamp(fabo_people_expiry((string) ($_POST['duration'] ?? '30d')), $me), $id, $meId]);
                $flash = ['ok', 'Validity updated.'];
                break;

            case 'revoke':
                fabo_exec('UPDATE clients SET revoked_at=NOW() WHERE id=? AND parent_id=?', 'ii', [$id, $meId]);
                $flash = ['ok', 'Access revoked.'];
                break;

            case 'reopen':
                fabo_exec('UPDATE clients SET revoked_at=NULL, expires_at=? WHERE id=? AND parent_id=?',
                          'sii', [fabo_people_clamp(fabo_people_expiry((string) ($_POST['duration'] ?? '30d')), $me), $id, $meId]);
                $flash = ['ok', 'Reopened.'];
                break;
        }
    }
}

// A create that failed re-opens the form, so the message and the typing are
// not hidden behind a closed box.
$addOpen = is_array($flash) && $flash[0] === 'err' && ($_POST['action'] ?? '') === 'create';

$editing    = null;
$newPassword = '';
if (!empty($_GET['id'])) {
    $editing = fabo_child($meId, (int) $_GET['id']);
    if ($editing && !empty($_GET['created'])) {
        fabo_session();
        $flash       = ['ok', $_SESSION['fabo_people_msg'] ?? 'Added.'];
        $newPassword = (string) ($_SESSION['fabo_people_pw'] ?? '');
        unset($_SESSION['fabo_people_msg'], $_SESSION['fabo_people_pw']);
    }
}

$people   = fabo_children($meId, $kind);
$accessMap = fabo_access_map(array_column($people, 'id'));
$baseUrl = fabo_portal_url();
$brand   = fabo_owner_brand($me);

fabo_head($viewer, $deskTitle, $deskNav);
?>
<div class="wrap-page">

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="pagehead">
  <h1><?= e(strtoupper($deskTitle)) ?></h1>
  <div class="sub"><?= $isTeamDesk
      ? 'Your own team, working under <strong>' . e($brand) . '</strong>. They see the sheets you
         give them and their own My Work list.'
      : 'The clients you have created under <strong>' . e($brand) . '</strong>. They see the sheets
         you share, and can be sent rows to approve.' ?>
    They can only ever open what you can open.</div>
</div>

<?php if ($editing): ?>
  <?php $acc = fabo_client_access((int) $editing['id']); $st = fabo_client_state($editing); ?>
  <div class="card">
    <h2><?= e($editing['name']) ?>
      <span class="pill <?= e($st) ?>"><?= e($st === 'ok' ? 'active' : $st) ?></span></h2>

    <div class="signinbox">
      <strong>Their sign-in</strong>
      <div class="pair"><span>Page</span><code><?= e($baseUrl) ?>login.php</code></div>
      <div class="pair"><span>Email</span><code><?= e($editing['email'] ?: '— add an email below —') ?></code></div>
      <div class="pair"><span>Password</span><code><?= $newPassword !== '' ? e($newPassword)
          : ($editing['password_hash'] ? 'set — only they know it' : 'not set yet') ?></code></div>
      <div class="pair"><span>Private link</span><code><?= e($baseUrl) ?>c.php?k=<?= e($editing['token']) ?></code></div>
      <?php if ($newPassword !== ''): ?>
        <div class="hint" style="color:var(--tone-warn-ink)">The email did not go out — copy this password now,
          it is not stored in readable form.</div>
      <?php endif; ?>
      <form method="post" style="margin-top:8px">
        <?= fabo_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
        <button class="btn btn-sm" name="action" value="credentials"
                onclick="return confirm('Set a new password and email it to them?')">
          Reset password &amp; e-mail the details</button>
      </form>
    </div>

    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0 18px">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <select name="duration" style="font:inherit;font-size:13px;padding:6px 9px;border:1px solid var(--line);border-radius:6px">
        <?php foreach (fabo_people_durations() as $k => $lab): ?>
          <option value="<?= e($k) ?>"<?= $k === '30d' ? ' selected' : '' ?>><?= e($lab) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm" name="action" value="validity">Set validity</button>
      <?php if ($st === 'revoked'): ?>
        <button class="btn btn-sm" name="action" value="reopen">Reopen</button>
      <?php else: ?>
        <button class="btn btn-danger btn-sm" name="action" value="revoke"
                onclick="return confirm('Revoke their access?')">Revoke</button>
      <?php endif; ?>
    </form>

    <form method="post">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <div class="fieldrow">
        <div class="field"><label>Name</label>
          <input name="name" value="<?= e($editing['name']) ?>" required></div>
        <div class="field"><label>Email</label>
          <input name="email" type="email" value="<?= e($editing['email']) ?>"></div>
        <?php if ($isTeamDesk): ?>
          <div class="field"><label>Owner / Team value (ties rows to this person)</label>
            <?php fabo_list_field('owner_name', 'Owner / Team',
                                  (string) $editing['owner_name'], $viewer, 'api/',
                                  '— not linked to rows —'); ?>
            <div class="hint">Rows whose Owner is this value count as their work and show
              on their My Work list.</div></div>
        <?php else: ?>
          <div class="field"><label>Organisation</label>
            <input name="organisation" value="<?= e($editing['organisation']) ?>"></div>
        <?php endif; ?>
      </div>
      <div class="field"><label>Note</label>
        <textarea name="contact_note"><?= e($editing['contact_note']) ?></textarea></div>

      <div class="field cm-tick">
        <label><input type="checkbox" name="is_committee" value="1"
                      <?= !empty($editing['is_committee']) ? 'checked' : '' ?>> Committee member</label>
        <div class="hint">A committee member gets a Committee Dashboard: the events sent to the
          committees they sit on, which they approve or reject, plus any sheets you give them below. Put them on a
          committee in Sheet Management &rarr; Committees.</div>
      </div>

      <div class="field">
        <label>Sheet access</label>
        <table class="data accesstable">
          <thead><tr><th>Sheet</th><th>No access</th><th>View only</th><th>Can edit</th></tr></thead>
          <tbody>
          <?php foreach ($cap as $key => $mine):
              $cur    = $acc[$key] ?? 'none';
              $canEdit = $mine === 'edit' && $key !== 'dashboard'; ?>
            <tr>
              <td><?= e(fabo_sheet_label($key)) ?>
                <?= $canEdit ? '' : '<span class="pill view">view only for you</span>' ?></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="none" <?= $cur === 'none' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="view" <?= $cur === 'view' ? 'checked' : '' ?>></td>
              <td><?php if ($canEdit): ?>
                <input type="radio" name="perm[<?= e($key) ?>]" value="edit" <?= $cur === 'edit' ? 'checked' : '' ?>>
              <?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="hint">You can only pass on what you hold yourself. Asking someone to approve a
          particular row is done from that row's review panel on the sheet.</div>
      </div>
      <?php if ($isTeamDesk && $modCap): ?>
      <div class="field">
        <label>Modules they can run</label>
        <table class="data accesstable">
          <thead><tr><th>Module</th><th>No access</th><th>View only</th><th>Owner</th></tr></thead>
          <tbody>
          <?php foreach ($modCap as $key => $mine): $cur = $acc[$key] ?? 'none'; ?>
            <tr>
              <td><?= e($modLabels[$key][0]) ?>
                <?= $mine === 'edit' ? '' : '<span class="pill view">view only for you</span>' ?>
                <div class="hint"><?= e($modLabels[$key][1]) ?></div></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="none" <?= $cur === 'none' ? 'checked' : '' ?>></td>
              <td><input type="radio" name="perm[<?= e($key) ?>]" value="view" <?= $cur === 'view' ? 'checked' : '' ?>></td>
              <td><?php if ($mine === 'edit'): ?>
                <input type="radio" name="perm[<?= e($key) ?>]" value="edit" <?= $cur === 'edit' ? 'checked' : '' ?>>
              <?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="hint">They open it from their own dashboard menu after signing in.</div>
      </div>
      <?php endif; ?>

      <?php if (!$isTeamDesk): ?>
        <div class="formsec">
          <h3>Account rights</h3>
          <p class="lead">The same rights you hold, if you want them to have them too.</p>
          <?= fabo_rights_table($editing, $me) ?>
        </div>
      <?php endif; ?>

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
        if (is_file(__DIR__ . '/lib/invite.php')) {
            require_once __DIR__ . '/lib/invite.php';
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
        <a class="btn" href="<?= e(fabo_desk_link($viewer, $kind)) ?>">Done</a>
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

<?php
  /* The same figures and charts our own desk gets, for a client running
     their own people. Only for a team desk: a list of client accounts has
     no work of its own to chart. */
  if ($isTeamDesk) {
      /* A company working only in Incubation Management has no sheet rows,
         so the sheet figures would read "no work assigned" — show its
         incubation figures instead, and the sheet ones only when there is
         sheet work to count. */
      require_once __DIR__ . '/lib/inc/nav.php';
      $teamItems = fabo_work_items($viewer);
      $teamInc   = inc_grant($viewer) !== 'none';
      if ($teamItems || !$teamInc) {
          fabo_team_dashboard($viewer, $people, $teamItems);
      }
      if ($teamInc) {
          fabo_team_inc_dashboard($viewer, $people);
      }
  }
?>

<div class="card">
  <h2><?= $isTeamDesk ? 'Your team' : 'Your clients' ?></h2>
  <?php if (!$people): ?>
    <div class="empty">No <?= e($one) ?>s yet. Use <strong>Add a <?= e($one) ?></strong> below.</div>
  <?php else: ?>
    <div class="scrollx">
    <table class="data">
      <thead><tr>
        <th>Name</th>
        <?php if ($isTeamDesk): ?><th>Owner / Team value</th><?php else: ?><th>Organisation</th><?php endif; ?>
        <th>Email</th><th>Sheets</th>
        <?php if ($isTeamDesk): ?>
          <th>What they are carrying</th><th>Entered</th><th>Still at it?</th>
        <?php endif; ?>
        <th>Status</th><th>Valid until</th><th></th>
      </tr></thead>
      <tbody>
      <?php
        /* Read once for the whole table rather than per person: each call
           walks every sheet, and a team of fifteen would walk them fifteen
           times for the same answer. */
        require_once __DIR__ . '/lib/work.php';
        $deskItems = $isTeamDesk ? fabo_work_items($viewer) : [];
      ?>
      <?php foreach ($people as $p):
        $acc = $accessMap[(int) $p['id']] ?? [];
        $st  = fabo_client_state($p); ?>
        <tr>
          <td><a href="<?= e(fabo_desk_link($viewer, $kind, ['id' => (int) $p['id']])) ?>"><?= e($p['name']) ?></a>
            <?php if (!empty($p['is_committee'])): ?> <span class="pill">Committee</span><?php endif; ?></td>
          <td style="color:var(--ink-soft)"><?= e($isTeamDesk ? ($p['owner_name'] ?: '—') : ($p['organisation'] ?: '—')) ?></td>
          <td style="color:var(--ink-soft)"><?= $p['email'] ? e($p['email']) : '<span class="ro">no email</span>' ?></td>
          <td><?php
            if (!$acc) { echo '<span style="color:var(--ink-mute)">none</span>'; }
            else {
                $bits = [];
                foreach ($acc as $k => $perm) {
                    $bits[] = '<span class="pill ' . e($perm) . '">' . e(isset($modLabels[$k]) ? $modLabels[$k][0] . ($perm === 'edit' ? ' (owner)' : '') : fabo_sheet_label($k)) . '</span>';
                }
                echo implode(' ', $bits);
            } ?></td>
          <?php if ($isTeamDesk): ?>
            <?php
              /* The same three questions the agency's own desk answers, on the
                 same terms: what is open on this person, what they have put in,
                 and whether they are still at it. */
              /* Theirs by assignment OR by having entered it — see
                 fabo_work_for_member(). */
              $theirs = fabo_work_for_member($deskItems, $p);
              $doing = fabo_work_by_sheet($theirs);
              $act   = fabo_team_activity((int) $p['id']);
              [$word, $tone] = fabo_team_activity_words($act);
            ?>
            <td><?php
              if (!$doing) {
                  echo ($p['owner_name'] ?? '') !== ''
                      ? '<span style="color:var(--ink-mute)">nothing open</span>'
                      : '<span style="color:var(--ink-mute)">no Owner value set</span>';
              } else {
                  $bits = [];
                  foreach (array_slice($doing, 0, 3, true) as $label => $n) {
                      $bits[] = '<span class="pill">' . e($label)
                              . ' <strong>' . (int) $n . '</strong></span>';
                  }
                  if (count($doing) > 3) {
                      $bits[] = '<span style="color:var(--ink-mute)">+'
                              . (count($doing) - 3) . ' more</span>';
                  }
                  echo implode(' ', $bits);
              }
            ?></td>
            <td><?php if ($act['edits'] === 0): ?>
                  <span style="color:var(--ink-mute)">nothing yet</span>
                <?php else: ?>
                  <strong><?= (int) $act['rows'] ?></strong> row<?= $act['rows'] === 1 ? '' : 's' ?>
                  <div style="font-size:11.5px;color:var(--ink-soft)">
                    <?= (int) $act['edits'] ?> edit<?= $act['edits'] === 1 ? '' : 's' ?>
                    across <?= (int) $act['sheets'] ?> sheet<?= $act['sheets'] === 1 ? '' : 's' ?>
                  </div>
                <?php endif; ?></td>
            <td><span class="pill <?= e($tone === 'good' ? 'ok' : $tone) ?>"><?= e($word) ?></span>
              <?php if ($act['last']): ?>
                <div style="font-size:11.5px;color:var(--ink-soft)">
                  <?= $act['days'] === 0 ? 'today'
                      : ($act['days'] === 1 ? 'yesterday' : (int) $act['days'] . ' days ago') ?></div>
              <?php elseif (!empty($p['last_login_at'])): ?>
                <div style="font-size:11.5px;color:var(--ink-soft)">signed in, no edits</div>
              <?php else: ?>
                <div style="font-size:11.5px;color:var(--ink-mute)">never signed in</div>
              <?php endif; ?></td>
          <?php endif; ?>
          <td><span class="pill <?= e($st) ?>"><?= e($st === 'ok' ? 'active' : $st) ?></span></td>
          <td><?= $p['expires_at'] ? e(date('d M Y', strtotime($p['expires_at']))) : '—' ?></td>
          <td><a class="btn btn-sm" href="<?= e(fabo_desk_link($viewer, $kind, ['id' => (int) $p['id']])) ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<details class="addbox"<?= $addOpen ? ' open' : '' ?>>
  <summary><span class="chev">&#43;</span> Add a <?= e($one) ?></summary>
  <div class="card">
    <form method="post">
      <?= fabo_csrf_field() ?>

      <div class="formsec">
        <h3>Who they are</h3>
        <div class="fieldrow">
          <div class="field"><label>Name</label>
            <input name="name" required placeholder="<?= $isTeamDesk ? 'e.g. Anita Rao' : 'e.g. Ravi Menon' ?>"></div>
          <div class="field"><label>Email</label>
            <input name="email" type="email" placeholder="where their sign-in details go"></div>
          <?php if ($isTeamDesk): ?>
            <div class="field"><label>Owner / Team value (ties rows to this person)</label>
              <?php fabo_list_field('owner_name', 'Owner / Team', '', $viewer, 'api/',
                                    '— not linked to rows —'); ?>
              <div class="hint">Rows whose Owner is this value count as their work and show
                on their My Work list. Add to this dropdown from any sheet.</div></div>
          <?php else: ?>
            <div class="field"><label>Organisation</label>
              <input name="organisation" value="<?= e($me['organisation']) ?>">
              <div class="hint">Their portal and their emails carry this name.</div></div>
          <?php endif; ?>
        </div>
        <div class="fieldrow two">
          <div class="field"><label>Access valid for</label>
            <select name="duration">
              <?php foreach (fabo_people_durations() as $k => $lab): ?>
                <option value="<?= e($k) ?>"<?= $k === '30d' ? ' selected' : '' ?>><?= e($lab) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Note</label>
            <input name="contact_note" placeholder="anything worth remembering"></div>
        </div>
      </div>

      <div class="field cm-tick">
        <label><input type="checkbox" name="is_committee" value="1"> Committee member</label>
        <div class="hint">Tick for a committee person: they get a Committee Dashboard with the events
          assigned to their committee, to approve or reject, plus the sheets you choose below. Only people ticked
          here can be put on a committee in Sheet Management &rarr; Committees.</div>
      </div>

      <div class="formsec">
        <h3>Sheet access</h3>
        <?php if (!$cap): ?>
          <div class="empty">You do not hold any sheets yet, so there is nothing to share.</div>
        <?php else: ?>
          <p class="lead">You can only offer what you hold yourself.</p>
          <div class="sheetgrid">
          <?php foreach ($cap as $key => $mine): ?>
            <div class="row">
              <span class="name"><?= e(fabo_sheet_label($key)) ?></span>
              <select name="perm[<?= e($key) ?>]">
                <option value="none">No access</option>
                <option value="view">View only</option>
                <?php if ($mine === 'edit' && $key !== 'dashboard'): ?>
                  <option value="edit">Can edit</option>
                <?php endif; ?>
              </select>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isTeamDesk && $modCap): ?>
      <div class="formsec">
        <h3>Modules they can run</h3>
        <p class="lead">For your own company. They open it from their own dashboard menu.</p>
        <div class="sheetgrid">
        <?php foreach ($modCap as $key => $mine): ?>
          <div class="row" title="<?= e($modLabels[$key][1]) ?>">
            <span class="name"><?= e($modLabels[$key][0]) ?></span>
            <select name="perm[<?= e($key) ?>]">
              <option value="none" selected>No access</option>
              <option value="view">View only</option>
              <?php if ($mine === 'edit'): ?><option value="edit">Owner</option><?php endif; ?>
            </select>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>


      <?php if (!$isTeamDesk): ?>
        <div class="formsec">
          <h3>Account rights</h3>
          <p class="lead">Pass on the rights you hold, if this client needs them too.</p>
          <?= fabo_rights_table([], $me) ?>
        </div>
      <?php endif; ?>

      <div class="formfoot">
        <button class="btn btn-primary" name="action" value="create">Add and send sign-in details</button>
        <span class="note">They are emailed a password and a private link straight away.</span>
      </div>
    </form>
  </div>
</details>

</div>
<?php fabo_foot();
