<?php
/**
 * Admin — HR Management.
 *
 * One front controller for every HR desk (?p=dashboard, employees, leave,
 * attendance, payroll, holidays, sheets, masters, schedules, reports,
 * settings). The administrator holds every HR right; the pages themselves
 * live in lib/hr/pages/ and are shared with hr.php at the root, which serves
 * people the administrator has granted an HR role.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/hr/app.php';
fabo_boot();
fabo_require_admin();

hr_app(fabo_admin_viewer());
