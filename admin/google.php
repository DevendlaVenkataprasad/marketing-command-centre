<?php
/**
 * Admin — the Google connection.
 *
 * Connecting is a one-off: the administrator signs in to the Google account
 * the meetings should live in, and Google hands back a refresh token that
 * the portal keeps. From then on every Meet link is created on that
 * account's calendar.
 *
 * This page is also the honest account of what the connection can and
 * cannot do, because the limits are Google's and are worth knowing before
 * anybody relies on them.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/google.php';
require_once __DIR__ . '/../lib/meetings.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_admin_viewer();
$flash  = null;

/* ------------------------------------------------- coming back from Google */

if (isset($_GET['code'])) {
    // The state carries the CSRF token, because Google's redirect is a GET
    // and cannot carry a form field.
    $sent = (string) ($_GET['state'] ?? '');
    if ($sent === '' || !hash_equals(fabo_csrf_token(), $sent)) {
        $flash = ['err', 'That sign-in did not come from this portal. Nothing was changed.'];
    } else {
        [$ok, $msg] = fabo_google_exchange_code((string) $_GET['code']);
        if ($ok) {
            // Meetings that were waiting for a Meet link get it now, with their invitations.
            require_once __DIR__ . '/../lib/meetingpage.php';
            $n = fabo_meeting_retry_pending(true);
            if ($n) {
                $msg .= ' ' . $n . ' waiting meeting' . ($n === 1 ? '' : 's')
                      . ' got a Google Meet link, and the invitations were emailed.';
            }
        }
        $flash = [$ok ? 'ok' : 'err', $msg];
    }
} elseif (isset($_GET['error'])) {
    $flash = ['err', 'Google returned: ' . (string) $_GET['error']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (($_POST['action'] ?? '') === 'disconnect') {
        fabo_google_disconnect();
        $flash = ['ok', 'Disconnected. Meetings and their links are untouched; the portal '
                      . 'just cannot create new ones until it is connected again.'];
    }
}

$configured = fabo_google_configured();
$connected  = fabo_google_connected();
$account    = fabo_google_account();
$gemini     = fabo_gemini_configured();

fabo_head($viewer, 'Google', 'google');
?>
<div class="wrap-page">

<div class="pagehead">
  <h1>GOOGLE</h1>
  <div class="sub">Calendar and Meet for the meetings desk, and Gemini for writing
    the minutes. All of it optional &mdash; meetings work without any of it.</div>
</div>

<?php if ($flash): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="band">
  <div class="tiles">
    <div class="tile <?= $configured ? 'green' : 'amber' ?>"><div class="k">OAuth client</div>
      <div class="v" style="font-size:15px;padding-top:6px"><?= $configured
          ? '<span class="pill green">set</span>' : '<span class="pill amber">missing</span>' ?></div>
      <div class="k">in config.local.php</div></div>
    <?php
      $days  = fabo_google_connected_days();
      // Seven days is the expiry Google applies while the consent screen is
      // in Testing, so day six is when it is worth saying something.
      $stale = $connected && (($days !== null && $days >= 6) || !fabo_google_ready());
    ?>
    <div class="tile <?= $stale ? 'amber' : ($connected ? 'green' : '') ?>">
      <div class="k">Connection</div>
      <div class="v" style="font-size:15px;padding-top:6px"><?= $connected
          ? ($stale ? '<span class="pill amber">may have expired</span>'
                    : '<span class="pill green">connected</span>')
          : '<span class="pill">not connected</span>' ?></div>
      <div class="k"><?= $account !== '' ? e($account) : 'no account yet' ?><?=
          $connected && $days !== null
            ? ' &middot; ' . ($days < 1 ? 'today' : round($days) . ' day' . (round($days) === 1.0 ? '' : 's') . ' ago')
            : '' ?></div></div>
    <div class="tile <?= $gemini ? 'green' : '' ?>"><div class="k">Gemini</div>
      <div class="v" style="font-size:15px;padding-top:6px"><?= $gemini
          ? '<span class="pill green">key set</span>' : '<span class="pill">no key</span>' ?></div>
      <div class="k"><?= e(fabo_env_or('GEMINI_MODEL', 'gemini-flash-latest')) ?></div></div>
  </div>
</div>

<?php if ($stale): ?>
  <div class="flash err">
    This connection was made <?= round($days) ?> days ago. While the OAuth consent screen is
    in <strong>Testing</strong>, Google expires the connection after seven days &mdash; so it
    has probably stopped working. Press <strong>Reconnect</strong> below. To stop it
    happening every week, publish the consent screen (see <em>Setting it up</em>).
  </div>
<?php endif; ?>

<div class="card">
  <h2>The connection</h2>
  <?php if (!$configured): ?>
    <p class="lead">There is no OAuth client yet, so there is nothing to connect to.
       Follow the setup below first.</p>
  <?php elseif ($connected): ?>
    <p class="lead">Connected<?= $account !== '' ? ' as <strong>' . e($account) . '</strong>' : '' ?>.
       Meet links are created on that account's calendar, and Google sends its own
       invitation to every guest alongside the portal's.</p>
    <div class="cols">
      <div>
        <p><a class="btn" href="<?= e(fabo_google_auth_url(fabo_csrf_token())) ?>">Reconnect</a>
           <span class="note">Use this to switch to a different Google account.</span></p>
      </div>
      <div>
        <form method="post" onsubmit="return confirm('Disconnect from Google?')">
          <?= fabo_csrf_field() ?>
          <button class="btn btn-danger" name="action" value="disconnect">Disconnect</button>
          <span class="note">Existing meetings keep their links.</span>
        </form>
      </div>
    </div>
  <?php else: ?>
    <p class="lead">The OAuth client is set. Sign in to the Google account the meetings
       should live in &mdash; the calendar the Meet links will be created on.</p>
    <p><a class="btn btn-primary" href="<?= e(fabo_google_auth_url(fabo_csrf_token())) ?>">
        Connect to Google</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>What the connection does, and what it cannot</h2>
  <table class="ledger">
    <thead><tr><th>Feature</th><th>Works?</th><th>What it needs</th></tr></thead>
    <tbody>
      <tr>
        <td class="ref">Calendar event with a Meet link</td>
        <td><span class="tag paid">Yes</span></td>
        <td>Any Google account, personal or Workspace. This is the reliable part.</td>
      </tr>
      <tr>
        <td class="ref">Google emails the guests too</td>
        <td><span class="tag paid">Yes</span></td>
        <td>Happens automatically when the event is created, on top of the portal's
            own invitation.</td>
      </tr>
      <tr>
        <td class="ref">Fetch the Meet transcript</td>
        <td><span class="tag draft">Sometimes</span></td>
        <td>Google Workspace on a plan that includes transcription, <em>and</em> somebody
            switched it on during the call. A personal @gmail.com account cannot.
            When it is not available you paste a transcript instead.</td>
      </tr>
      <tr>
        <td class="ref">Gemini's own "take notes for me"</td>
        <td><span class="tag cancelled">No API</span></td>
        <td>Google publishes no way to trigger it or to read the notes document it
            writes. The portal does the same job by a supported route: it takes the
            transcript and asks the Gemini API to write the minutes.</td>
      </tr>
      <tr>
        <td class="ref">Minutes written by Gemini</td>
        <td><span class="tag paid">Yes</span></td>
        <td>A Gemini API key, and a transcript to work from &mdash; fetched or pasted.</td>
      </tr>
    </tbody>
  </table>
</div>

<details class="card" <?= $configured ? '' : 'open' ?>>
  <summary><h2 style="display:inline">Setting it up</h2></summary>

  <h3 style="margin-top:14px">1. Make an OAuth client</h3>
  <ol>
    <li>Open <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google
        Cloud Console</a> and create a project, or pick an existing one.</li>
    <li><strong>APIs &amp; Services &rarr; Library</strong>: enable
        <strong>Google Calendar API</strong>, and <strong>Google Meet API</strong> if you
        want transcripts.</li>
    <li><strong>OAuth consent screen</strong>: fill it in. A Workspace account can set the
        user type to <strong>Internal</strong>, which needs no review and never expires.
        A personal @gmail.com has to use <strong>External</strong>.</li>
    <li><strong>Audience &rarr; Test users &rarr; Add users</strong>: add the Google account
        you will connect with. Miss this and Google answers
        <em>&ldquo;Error 403: access_denied &mdash; has not completed the Google verification
        process&rdquo;</em>, which reads like a fault but only means the account is not on
        the list.</li>
    <li><strong>Credentials &rarr; Create credentials &rarr; OAuth client ID</strong>,
        type <strong>Web application</strong>.</li>
    <li>Add this exact <strong>Authorised redirect URI</strong>:
        <pre><?= e(fabo_google_redirect()) ?></pre>
        It has to match character for character, https included.</li>
  </ol>

  <h3>2. Put the client in config.local.php</h3>
  <pre>putenv('GOOGLE_CLIENT_ID=...apps.googleusercontent.com');
putenv('GOOGLE_CLIENT_SECRET=...');</pre>

  <h3>3. For minutes, add a Gemini key</h3>
  <p>From <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google
     AI Studio</a>:</p>
  <pre>putenv('GEMINI_API_KEY=...');
putenv('GEMINI_MODEL=gemini-flash-latest');   // optional; an alias that tracks the current model</pre>

  <h3>4. Connect</h3>
  <p>Reload this page and press <strong>Connect to Google</strong>. The portal asks for
     these permissions and no others:</p>
  <ul>
    <?php foreach (fabo_google_scopes() as $s): ?>
      <li><code><?= e($s) ?></code></li>
    <?php endforeach; ?>
  </ul>
  <h3>5. Then decide whether to publish</h3>
  <p>While the consent screen stays in <strong>Testing</strong>, Google expires the
     connection <strong>every seven days</strong> on Calendar scopes, and somebody has to
     press Reconnect. Three ways out, in order of effort:</p>
  <ul>
    <li><strong>Leave it in Testing</strong> and reconnect weekly. Fine while you are
        trying the module out.</li>
    <li><strong>Publish the consent screen</strong> (Audience &rarr; Publish app). Google
        then asks you to verify the app for Calendar scopes &mdash; you will need a privacy
        policy page, a homepage on the same domain, and a short demo video. It takes days
        to a few weeks.</li>
    <li><strong>Use Google Workspace on mark-up.in</strong> and set the user type to
        Internal. No review, no expiry. This is the tidiest answer if the domain is going
        to have Workspace anyway.</li>
  </ul>

  <p class="muted">Google only hands over a long-lived token the first time you consent.
     If the connection ever refuses to renew, remove the portal at
     <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>
     and connect again.</p>
</details>

</div>
<?php fabo_foot();
