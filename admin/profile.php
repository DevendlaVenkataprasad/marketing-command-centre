<?php
/** The admin's own profile. */

require_once __DIR__ . '/../lib/profile.php';
require_once __DIR__ . '/../lib/uploads.php';
fabo_boot();
fabo_require_admin();

header('X-Robots-Tag: noindex, nofollow');

fabo_profile_page(fabo_admin_viewer());
