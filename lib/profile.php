<?php
/**
 * Profile — what the portal knows about the person looking at it.
 *
 * Everything on this page is the viewer's own record. Nothing here reaches
 * into anybody else's: a client sees their own access and their own counts,
 * never another client's, and the admin sees the portal's own settings rather
 * than a list of everyone's details.
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/approvals.php';

/** Handles the password change a portal user can make here. */
function fabo_profile_action(array $viewer): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($viewer['is_admin'])) {
        return ['', ''];
    }
    fabo_csrf_check();
    $person = $viewer['client'];

    if (($_POST['do'] ?? '') === 'mailbox') {
        // The form is only drawn for those who hold the right; check it anyway,
        // because a hidden form is not a permission.
        if (empty($person['can_use_own_mail'])) {      // their own right, not one inherited
            fabo_deny('Your account cannot send from its own mailbox.', 403);
        }
        fabo_save_mailbox((int) $person['id'], $_POST);
        return ['ok', 'Mail settings saved.'];
    }
    if (($_POST['do'] ?? '') !== 'password') {
        return ['', ''];
    }
    $now    = (string) ($_POST['current_password'] ?? '');
    $new    = (string) ($_POST['new_password'] ?? '');
    $again  = (string) ($_POST['confirm_password'] ?? '');

    // A link holder never set a password, so there may be nothing to check.
    $hash = (string) ($person['password_hash'] ?? '');
    if ($hash !== '' && !password_verify($now, $hash)) {
        return ['err', 'That is not your current password.'];
    }
    if (mb_strlen($new) < 8) {
        return ['err', 'Choose a password of at least 8 characters.'];
    }
    if ($new !== $again) {
        return ['err', 'The two new passwords do not match.'];
    }
    /* Allowed even if somebody else on this address uses it: sign-in is by
       user ID, which means one account however many share the mailbox. */
    $shared = function_exists('fabo_password_taken')
            && fabo_password_taken((string) ($person['email'] ?? ''), $new, (int) $person['id']);

    fabo_exec('UPDATE clients SET password_hash = ?, must_change = 0 WHERE id = ?', 'si',
              [password_hash($new, PASSWORD_DEFAULT), (int) $person['id']]);
    fabo_session_refresh((int) $person['id']);   // this session stays; any other ends
    return ['ok', 'Your password has been changed.'
        . ($shared
           ? ' Somebody else on this email address uses the same password, so sign in '
             . 'with your user ID (' . (string) ($person['login_id'] ?? '') . ') rather '
             . 'than the address from now on.'
           : '')];
}

/** One labelled line in the details card. */
function fabo_profile_row(string $label, string $value, string $hint = ''): string
{
    return '<div class="prow"><span class="k">' . e($label) . '</span>'
         . '<span class="v">' . ($value !== '' ? $value : '<span class="none">—</span>')
         . ($hint !== '' ? '<div class="hint">' . $hint . '</div>' : '')
         . '</span></div>';
}

function fabo_profile_page(array $viewer, array $flash = ['', '']): void
{
    $isAdmin = !empty($viewer['is_admin']);
    $me      = $isAdmin ? null : $viewer['client'];
    $isTeam  = !$isAdmin && (($me['kind'] ?? 'client') === 'team');
    $brand   = fabo_viewer_brand($viewer);

    fabo_head($viewer, 'Profile', 'profile');
    ?>
<div class="wrap-page">

<?php if ($flash[0] !== ''): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="pagehead">
  <h1>PROFILE</h1>
  <div class="sub"><?= $isAdmin
      ? 'Your account and what this portal is set up to do.'
      : 'Your own details. Ask ' . e($isTeam ? 'the admin' : 'whoever set up your access')
        . ' if anything here needs changing.' ?></div>
</div>

<?php if ($isAdmin): ?>

  <div class="card">
    <h2>Administrator</h2>
    <div class="proftable">
      <?= fabo_profile_row('Signed in as', e((string) ADMIN_EMAIL)) ?>
      <?= fabo_profile_row('Portal name', e(fabo_app_name()),
            'Set with APP_NAME in config.local.php. Clients see their own organisation instead.') ?>
      <?= fabo_profile_row('Outgoing mail', SMTP_HOST !== ''
            ? e(SMTP_FROM_NAME) . ' &lt;' . e(SMTP_FROM !== '' ? SMTP_FROM : SMTP_USER) . '&gt;'
            : '<span class="none">not configured</span>',
            'Used for anything the portal sends itself.') ?>
      <?= fabo_profile_row('Sign-in', fabo_admin_locked()
            ? 'Password protected'
            : '<strong>Open</strong> — no admin password is set',
            fabo_admin_locked() ? '' : 'Set ADMIN_PASS_HASH in config.local.php to lock the admin area.') ?>
    </div>
  </div>

  <div class="card">
    <h2>What is on the portal</h2>
    <div class="proftable">
      <?php
        $c = fabo_one("SELECT COUNT(*) n FROM clients WHERE kind = 'client'")['n'] ?? 0;
        $t = fabo_one("SELECT COUNT(*) n FROM clients WHERE kind = 'team'")['n'] ?? 0;
        $p = fabo_one('SELECT COUNT(*) n FROM approval_requests
                       WHERE cancelled_at IS NULL AND responded_at IS NULL')['n'] ?? 0;
      ?>
      <?= fabo_profile_row('Clients', (string) (int) $c) ?>
      <?= fabo_profile_row('Team members', (string) (int) $t) ?>
      <?= fabo_profile_row('Approvals outstanding', (string) (int) $p,
            'Rows sent to a client that have not been answered.') ?>
    </div>
    <div class="formfoot">
      <a class="btn" href="clients.php">Manage clients</a>
      <a class="btn" href="team.php">Manage team</a>
      <a class="btn" href="notifications.php">Notifications</a>
      <div class="spacer" style="flex:1"></div>
      <?php if (fabo_admin_signed_in()): ?>
        <a class="btn btn-danger" href="../logout.php">Sign out</a>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <h2 style="display:flex;align-items:center;gap:12px">
      <?= function_exists('fabo_avatar') ? fabo_avatar($me, '', fabo_link($viewer, 'image.php', ['v' => substr(md5((string) ($me['image_file'] ?? '')), 0, 8)])) : '' ?>
      <span><?= e($me['name']) ?></span>
    </h2>
    <div class="proftable">
      <?= fabo_profile_row('Name', e($me['name'])) ?>
      <?= fabo_profile_row('Email', e((string) $me['email']),
            (string) $me['email'] === '' ? 'Without an address you only get notices here, not by email.' : '') ?>
      <?= fabo_profile_row($isTeam ? 'Portal' : 'Organisation', e($brand)) ?>
      <?php if ($isTeam): ?>
        <?= fabo_profile_row('Owner / Team value', e((string) $me['owner_name']),
              'Rows whose Owner is this value are counted as your work.') ?>
      <?php endif; ?>
      <?= fabo_profile_row('Access valid until', $me['expires_at']
            ? e(date('d M Y, H:i', strtotime($me['expires_at'])))
            : 'No expiry') ?>
      <?= fabo_profile_row('Last signed in', $me['last_login_at']
            ? e(date('d M Y, H:i', strtotime($me['last_login_at'])))
            : 'Not yet') ?>
      <?= fabo_profile_row('Your user ID', e((string) ($me['login_id'] ?? '')) ?: 'Not set',
            'Sign in with this instead of your address if somebody else shares it.') ?>
    </div>
    <?php /* Whether the portal's last message to them actually left. Read
             only here: it is their record, but not their queue to manage. */
          require_once __DIR__ . '/outbox.php';
          fabo_outbox_block((int) $me['id'], false); ?>
  </div>

  <div class="card">
    <h2>What you can open</h2>
    <?php $access = $viewer['access'] ?? []; ?>
    <?php if (!$access): ?>
      <div class="empty">No sheets have been shared with you yet.</div>
    <?php else: ?>
      <table class="data">
        <thead><tr><th>Sheet</th><th>You can</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($access as $key => $perm): ?>
          <tr>
            <?php $isInc = $key === 'incubation';   // our staff's module, kept out of fabo_modules() so clients are never offered it ?>
            <td><?= e(fabo_grantable_label($key)) ?></td>
            <td><span class="pill <?= e($perm) ?>"><?= $perm === 'edit' ? ($isInc ? 'owner' : 'add and edit rows') : 'view only' ?></span></td>
            <?php // A module keeps its own page; a sheet opens in the grid.
                    $href = $isInc ? 'incubation.php'
                          : (isset(fabo_modules()[$key])
                          ? fabo_link($viewer, fabo_modules()[$key]['page'])
                          : fabo_link($viewer, 'c.php', ['sheet' => $key])); ?>
              <td><a class="btn btn-sm" href="<?= e($href) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div class="hint" style="margin-top:10px">
      You see every row on the sheets above.
    </div>
  </div>

  <div class="card">
    <h2>Your account</h2>
    <div class="proftable">
      <?php $pending = fabo_pending_request_count((int) $me['id']); ?>
      <?= fabo_profile_row('Waiting on you',
            $pending
              ? '<strong>' . (int) $pending . '</strong> item' . ($pending === 1 ? '' : 's') . ' to approve or reject'
              : 'Nothing to approve right now') ?>
      <?= fabo_profile_row('Unread notifications', (string) fabo_unread_count((int) $me['id'])) ?>
      <?php if (!$isTeam): ?>
        <?php
          require_once __DIR__ . '/people.php';
          $mayC = fabo_may_make($me, 'client');
          $mayT = fabo_may_make($me, 'team');
        ?>
        <?= fabo_profile_row('Your own people',
              $mayC && $mayT ? 'You can create your own clients and your own team'
              : ($mayC ? 'You can create your own clients'
              : ($mayT ? 'You can create your own team members' : 'No'))) ?>
      <?php endif; ?>
    </div>
    <div class="formfoot">
      <a class="btn" href="<?= e(fabo_link($viewer, 'notifications.php')) ?>">Notifications</a>
      <?php if (!$isTeam && fabo_may_make($me, 'client')): ?>
        <a class="btn" href="<?= e(fabo_link($viewer, 'people.php')) ?>">My Clients</a>
      <?php endif; ?>
      <?php if (!$isTeam && fabo_may_make($me, 'team')): ?>
        <a class="btn" href="<?= e(fabo_link($viewer, 'people.php', ['kind' => 'team'])) ?>">My Team</a>
      <?php endif; ?>
      <div class="spacer" style="flex:1"></div>
      <a class="btn btn-danger" href="logout.php">Sign out</a>
    </div>
    <div class="hint" style="margin-top:8px">Signing out ends this session and forgets
      this device. Your private link still opens your portal.</div>
  </div>

  <?php if (!$isTeam): ?>
  <div class="card">
    <h2>Your mailbox</h2>
    <?php if (!empty($me['can_use_own_mail'])): ?>
      <p style="color:var(--ink-soft);font-size:13.5px;margin-top:0">
        Invitations and approval requests you send go out from here, so your people
        hear from <strong><?= e($brand) ?></strong> and not from the portal.
      </p>
      <form method="post">
        <?= fabo_csrf_field() ?>
        <input type="hidden" name="do" value="mailbox">
        <?= fabo_mailbox_fields($me) ?>
        <div class="formfoot">
          <button class="btn btn-primary">Save mail settings</button>
        </div>
      </form>
    <?php else: ?>
      <p style="color:var(--ink-soft);font-size:13.5px;margin:0">
        Your invitations and approval requests already go out under
        <strong><?= e($brand) ?></strong>, sent for you by the portal, with replies coming back to
        <strong><?= e($me['email'] ?: 'your address') ?></strong>. To send them from your own mail
        server instead, ask for the <strong>Send from their own mailbox</strong> right.
      </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Change your password</h2>
    <p style="color:var(--ink-soft);font-size:13.5px;margin-top:0">
      This is the password you sign in with at <code><?= e(fabo_portal_url()) ?>login.php</code>.
      Your private link keeps working either way.
    </p>
    <form method="post">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <div class="fieldrow">
        <?php if ((string) ($me['password_hash'] ?? '') !== ''): ?>
          <div class="field"><label>Current password</label>
            <input type="password" name="current_password" autocomplete="current-password" required></div>
        <?php endif; ?>
        <div class="field"><label>New password</label>
          <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
          <div class="hint">At least 8 characters.</div></div>
        <div class="field"><label>Repeat it</label>
          <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
      </div>
      <div class="formfoot">
        <button class="btn btn-primary">Change password</button>
      </div>
    </form>
  </div>

<?php endif; ?>

</div>
<?php
    fabo_foot();
}
