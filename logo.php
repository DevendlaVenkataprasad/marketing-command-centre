<?php
/**
 * The logo whoever is asking should see.
 *
 * A client's own mark on their own portal; ours everywhere else. There is
 * deliberately NO id in the URL: the image served is the one belonging to the
 * viewer this request already is, so nobody can walk through the numbers and
 * collect other organisations' logos the way an ?id= would let them.
 *
 * Falls through to the portal's own mark rather than 404ing, because this is
 * asked for by a <link rel="icon"> and an <img> that should never be broken.
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/uploads.php';
fabo_boot();

/** Send the portal's own mark and stop. */
function fabo_logo_ours(): void
{
    $png  = is_file(__DIR__ . '/assets/logo.png');
    $file = __DIR__ . '/assets/' . ($png ? 'logo.png' : 'logo.webp');
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . ($png ? 'image/png' : 'image/webp'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

/* A public form link: the mark of the organisation that created that form.
   Still no id in the URL -- the form's own token, which only somebody holding
   that link has, decides whose logo this is. */
$formToken = (string) ($_GET['f'] ?? '');
$applyToken = (string) ($_GET['a'] ?? '');
$cwToken = (string) ($_GET['cw'] ?? '');
$eduKey = (string) ($_GET['edu'] ?? '');
if ($eduKey !== '') {
    // The Campus portal (campus.php): the organisation whose portal key it is.
    require_once __DIR__ . '/lib/edu/core.php';
    $org = edu_org_by_key($eduKey);
    if ($org === null || $org <= 0) {
        fabo_logo_ours();
    }
} elseif (!empty($_GET['h'])) {
    // An organisation's own address (lib/orghost.php): the mark of the organisation it belongs to.
    $org = fabo_request_org();
    if ($org <= 0) {
        fabo_logo_ours();
    }
} elseif ($cwToken !== '') {
    // The co-working application form (coworking-apply.php): the organisation whose form key it is.
    require_once __DIR__ . '/lib/cw/mail.php';
    $cwOrg = cw_form_org($cwToken);
    if ($cwOrg === null || $cwOrg <= 0) {
        fabo_logo_ours();
    }
    $org = $cwOrg;
} elseif ($applyToken !== '') {
    // An application form (apply.php): the organisation whose CRM it feeds.
    require_once __DIR__ . '/lib/crm_forms.php';
    $cf = fabo_cf_by_token($applyToken);
    if (!$cf) {
        fabo_logo_ours();
    }
    $org = fabo_org_brand((int) $cf['org_id'])[0];
} elseif ($formToken !== '') {
    require_once __DIR__ . '/lib/forms.php';
    $form = fabo_form_by_token($formToken);
    if (!$form) {
        fabo_logo_ours();
    }
    $org = fabo_org_brand((int) ($form['client_id'] ?? 0))[0];
} else {
    /* Who is asking. A bad or missing token is not an error here -- it just
       means this is not a client's portal, so the portal's own mark is the
       right answer. */
    $viewer = null;
    try {
        $token  = (string) ($_GET['k'] ?? '');
        $viewer = $token !== '' ? fabo_client_viewer($token)
                : (fabo_signed_in() ? fabo_person_viewer(fabo_signed_in()) : null);
    } catch (Throwable $e) {
        $viewer = null;
    }

    if (!$viewer || !empty($viewer['is_admin'])) {
        fabo_logo_ours();
    }

    /* The ORGANISATION's mark, not the person's. A team member's portal
       carries their organisation's name, so it has to carry that
       organisation's logo -- their own photograph belongs on their profile.
       fabo_brand_client_id() settles which account that is, the same way the
       name is settled. */
    $org = fabo_brand_client_id($viewer);
}
$row = $org > 0
    ? fabo_one('SELECT image_file, image_mime FROM clients WHERE id = ?', 'i', [$org])
    : null;

if (!$row || empty($row['image_file'])) {
    fabo_logo_ours();                 // they have not uploaded one
}

$path = fabo_upload_dir() . DIRECTORY_SEPARATOR . (string) $row['image_file'];
if (!is_file($path)) {
    fabo_logo_ours();                 // the record outlived the file
}

fabo_image_headers((string) $row['image_mime']);
header('Content-Length: ' . filesize($path));
/* Private: this is one organisation's mark, and a shared cache must not hand
   it to the next portal that asks. */
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile($path);
