<?php
/**
 * A department's idea form for students (idea-form.php?f=<token>), shared by
 * its Head of Department. No sign-in. See lib/iv/form.php.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/iv/form.php';
fabo_boot();

iv_form_main();
