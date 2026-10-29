<?php
/**
 * Meetings, on a client's or a team member's portal.
 *
 * A default desk: no grant is needed, because everybody has meetings. What
 * differs is which ones they can see, and lib/meetings.php decides that.
 * The page itself is shared with the admin side.
 */

require_once __DIR__ . '/lib/meetingpage.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

$viewer = fabo_portal_viewer((string) ($_GET['k'] ?? ''));
if (!$viewer) {
    $next = 'meetings.php' . (!empty($_GET['id']) ? '?id=' . (int) $_GET['id'] : '');
    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}
// Committee members have no meetings desk -- only their committee dashboard.
require_once __DIR__ . '/lib/committees.php';
if (fabo_cm_only($viewer)) {
    header('Location: ' . fabo_link($viewer, 'committee.php'));
    exit;
}
fabo_meeting_page($viewer);
