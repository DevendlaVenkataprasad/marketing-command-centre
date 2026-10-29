<?php
/**
 * Front door. Sends whoever arrives to the right place:
 * a signed-in portal user to their dashboard, everyone else to sign in.
 */

require_once __DIR__ . '/lib/ui.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

if (fabo_admin_signed_in()) {
    header('Location: admin/index.php');
} elseif (fabo_signed_in()) {
    header('Location: c.php');
} else {
    header('Location: login.php');
}
