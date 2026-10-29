<?php
/**
 * Admin & Facilities for a client granted it on Clients (or a member of their
 * team, given it by the head): their organisation's own facilities desk, in
 * its own tables. Signed-in sessions only. The administrator's desk is
 * admin/facilities.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/it/app.php';
fabo_boot();
it_portal_main('fa');
