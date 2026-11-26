<?php
/**
 * The Campus portal: students, faculty, mentors and institution admins sign
 * in here with their own email and password. Not a staff login, and never the
 * other way round. See lib/edu/portal.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/edu/portal.php';
fabo_boot();

edu_portal_main();
