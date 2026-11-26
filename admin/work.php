<?php
/**
 * Admin — Work Management: departments, tasks, access for team members and
 * clients, and the Excel status report. Administrator only; team members and
 * clients given access open the same module at work.php after signing in.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/wm/app.php';
fabo_boot();
fabo_require_admin();

wm_main(fabo_admin_viewer());
