<?php
/**
 * The notification centre, shared by every portal.
 *
 * One inbox table serves the admin and every client and team member, so one
 * renderer serves all of their pages. Which inbox is read comes from the
 * viewer, never from the request — a notification id from someone else's inbox
 * simply matches nothing.
 */

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/notify.php';

/** Handles a posted inbox action. Returns a flash message, or ''. */
function fabo_inbox_action(array $viewer): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    fabo_csrf_check();
    $inbox = fabo_inbox_id($viewer);
    $id    = (int) ($_POST['notice'] ?? 0);

    switch ((string) ($_POST['do'] ?? '')) {
        case 'read':
            fabo_mark_one($inbox, $id, true);
            return 'Marked as read.';
        case 'unread':
            fabo_mark_one($inbox, $id, false);
            return 'Marked as unread.';
        case 'read_all':
            fabo_mark_read($inbox);
            return 'Everything is marked as read.';
        case 'delete':
            fabo_delete_notice($inbox, $id);
            return 'Notification deleted.';
        case 'clear_read':
            fabo_clear_notices($inbox, true);
            return 'Read notifications cleared.';
        case 'clear_all':
            fabo_clear_notices($inbox, false);
            return 'All notifications cleared.';
    }
    return '';
}

/**
 * Turns a stored link into one this viewer can follow.
 *
 * Notices are written with a portal-relative link ('c.php?sheet=…'), because
 * whoever wrote it did not know who would read it. The admin reads the same
 * notice from admin/, so the link is remapped rather than stored twice.
 */
function fabo_inbox_link(array $viewer, string $link): string
{
    $link = trim($link);
    if ($link === '' || fabo_safe_next($link) === '') {
        return '';
    }
    $path = explode('?', $link, 2)[0];
    $q    = [];
    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

    if (!empty($viewer['is_admin'])) {
        // An admin has no ?k= and reads sheets from admin/sheet.php.
        if ($path === 'c.php') {
            $path = isset($q['sheet']) && $q['sheet'] === 'dashboard' ? 'dashboard.php' : 'sheet.php';
        } elseif (str_starts_with($path, 'admin/')) {
            $path = substr($path, 6);
        }
        return $path . ($q ? '?' . http_build_query($q) : '');
    }
    if (str_starts_with($path, 'admin/')) {
        return '';                      // not theirs to open
    }
    return fabo_link($viewer, $path, $q);
}

/** The whole notification centre, for whoever is looking at it. */
function fabo_inbox_page(array $viewer, string $flash = ''): void
{
    $inbox  = fabo_inbox_id($viewer);
    $only   = ($_GET['show'] ?? '') === 'unread';
    $items  = fabo_notifications($inbox, $only, 200);
    $unread = fabo_unread_count($inbox);
    $total  = $only ? count(fabo_notifications($inbox, false, 200)) : count($items);
    $self   = $viewer['is_admin'] ? 'notifications.php' : fabo_link($viewer, 'notifications.php');
    $selfU  = $viewer['is_admin']
            ? 'notifications.php?show=unread'
            : fabo_link($viewer, 'notifications.php', ['show' => 'unread']);

    fabo_head($viewer, 'Notifications', 'notifications');
    ?>
<div class="wrap-page">

<?php if ($flash !== ''): ?>
  <div class="flash ok"><?= e($flash) ?></div>
<?php endif; ?>

<div class="pagehead">
  <h1>NOTIFICATIONS</h1>
  <div class="sub">Everything the portal has told you, newest first.
    <?= $unread ? '<strong>' . (int) $unread . ' unread.</strong>' : 'Nothing unread.' ?></div>
</div>

<div class="inboxbar">
  <a class="btn btn-sm<?= $only ? '' : ' btn-primary' ?>" href="<?= e($self) ?>">All<?= $total ? ' (' . (int) $total . ')' : '' ?></a>
  <a class="btn btn-sm<?= $only ? ' btn-primary' : '' ?>" href="<?= e($selfU) ?>">Unread<?= $unread ? ' (' . (int) $unread . ')' : '' ?></a>
  <div class="spacer"></div>
  <form method="post">
    <?= fabo_csrf_field() ?>
    <button class="btn btn-sm" name="do" value="read_all"<?= $unread ? '' : ' disabled' ?>>Mark all as read</button>
    <button class="btn btn-sm" name="do" value="clear_read"
            onclick="return confirm('Delete every notification you have already read?')">Clear read</button>
    <button class="btn btn-danger btn-sm" name="do" value="clear_all"
            onclick="return confirm('Delete every notification, read or not? This cannot be undone.')">Clear all</button>
  </form>
</div>

<?php if (!$items): ?>
  <div class="card"><div class="empty">
    <?= $only ? 'Nothing unread. ' : 'No notifications yet. ' ?>
    <?= $only ? '<a href="' . e($self) . '">See all of them</a>.' : 'You will hear about approvals, sheet access and sign-in details here.' ?>
  </div></div>
<?php else: ?>
  <div class="card inboxlist">
  <?php foreach ($items as $n):
      $href = fabo_inbox_link($viewer, (string) ($n['link'] ?? ''));
      $new  = $n['read_at'] === null;
  ?>
    <div class="notice<?= $new ? ' new' : '' ?>">
      <div class="body">
        <div class="t"><?= $new ? '<i class="dot"></i>' : '' ?><?= e($n['title']) ?></div>
        <?php if ($n['body']): ?><div class="b"><?= e($n['body']) ?></div><?php endif; ?>
        <div class="when"><?= e(date('d M Y, H:i', strtotime($n['created_at']))) ?>
          <?= $new ? '' : '· read ' . e(date('d M Y, H:i', strtotime($n['read_at']))) ?></div>
      </div>
      <div class="acts">
        <?php if ($href !== ''): ?>
          <a class="btn btn-sm btn-primary" href="<?= e($href) ?>">Open</a>
        <?php endif; ?>
        <form method="post">
          <?= fabo_csrf_field() ?>
          <input type="hidden" name="notice" value="<?= (int) $n['id'] ?>">
          <button class="btn btn-sm" name="do" value="<?= $new ? 'read' : 'unread' ?>"><?= $new ? 'Mark read' : 'Mark unread' ?></button>
          <button class="btn btn-danger btn-sm" name="do" value="delete" title="Delete this notification">&#215;</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>
<?php
    fabo_foot();
}
