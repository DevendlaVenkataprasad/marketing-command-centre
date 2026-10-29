<?php
/**
 * IT Management for a client granted it on Clients (or a member of their
 * team, given it by the head): their organisation's own IT desk, in its own
 * tables — never ours, never another client's. Signed-in sessions only.
 * The administrator's desk is admin/it.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/it/app.php';
fabo_boot();
it_portal_main('it');
