<?php
/**
 * Department actions, answered as JSON so the Events page never reloads.
 *
 * The page this sits under is a heavy one — grid, calendar, six charts —
 * and reloading all of it to add a department meant a visible pause and a
 * form that came back empty. Everything here happens in place instead.
 *
 * The form still posts normally when scripting is off; lib/deptblock.php
 * keeps that path. This is the same set of actions, authorised identically.
 */

require_once __DIR__ . '/../lib/endpoint.php';
require_once __DIR__ . '/../lib/deptblock.php';

fabo_csrf_check();
[$viewer, $key, $sheet, $perm] = fabo_endpoint('edit');

if ($key !== 'events') {
    fabo_json_fail('Departments belong to the Events sheet.', 400);
}
if ($perm !== 'edit') {
    fabo_json_fail('You need edit access to this sheet to manage departments.', 403);
}
require_once __DIR__ . '/../lib/committees.php';
if (fabo_cm_only($viewer)) {
    fabo_json_fail('Department assigning is not available to committee members.', 403);
}

[$tone, $message, $extra] = fabo_dept_act($viewer, $_POST);

if ($tone === 'err') {
    fabo_json_fail($message, 400);
}
fabo_json(array_merge(['ok' => true, 'message' => $message], $extra));
