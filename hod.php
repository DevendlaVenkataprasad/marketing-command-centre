<?php
/**
 * The Head of Department portal: each HoD signs in with their own email and
 * password and sees only their own department. Not a staff login. See
 * lib/iv/portal.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/iv/portal.php';
fabo_boot();

iv_portal_main();
