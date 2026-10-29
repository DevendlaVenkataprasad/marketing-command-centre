<?php
/**
 * Meetings, on the admin side. The body is shared with the portal page;
 * only the viewer differs, and with it what is visible and who can be
 * invited.
 */

require_once __DIR__ . '/../lib/meetingpage.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

fabo_meeting_page(fabo_admin_viewer());
