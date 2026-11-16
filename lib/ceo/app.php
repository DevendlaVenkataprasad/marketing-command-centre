<?php
/**
 * CEO Dashboard — the page. The server decides who gets in and draws the frame;
 * assets/ceo.js fills it from api/ceo.php, which checks everything again.
 */

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/nav.php';

function ceo_url_base(array $viewer): string
{
    return (!empty($viewer['is_admin']) ? '../' : '') . 'api/ceo.php';
}

function ceo_main(array $viewer): void
{
    $org = ceo_pick_org($viewer);
    $a = ceo_access($viewer, $org);
    if (!$a) {
        fabo_deny('The CEO Dashboard is available only to people the owner has given access.', 403);
    }
    $page = (string) ($_GET['p'] ?? 'overview');
    $pages = ceo_pages();
    if (!isset($pages[$page]) || !ceo_page_allowed($a, $pages[$page][1])) {
        $page = 'overview';
    }
    fabo_head($viewer, $pages[$page][0] . ' · CEO Dashboard', 'ceo-' . $page);
    $root = fabo_root_prefix();
    echo '<link rel="stylesheet" href="' . e(fabo_asset($root . 'assets/ceo.css')) . '">';
    $nav = [];
    foreach ($pages as $p => [$label, $need]) {
        if (ceo_page_allowed($a, $need)) {
            $nav[$p] = $label;
        }
    }
    $conf = ['page' => $page, 'api' => ceo_url_base($viewer), 'csrf' => fabo_csrf_token(), 'org' => $org, 'admin' => !empty($viewer['is_admin']),
             'orgs' => !empty($viewer['is_admin']) ? ceo_orgs() : [$org => ceo_org_name($org)], 'orgName' => ceo_org_name($org), 'nav' => $nav];
    echo '<main class="wrap-page ceo" id="main" data-page="' . e($page) . '">'
       . '<noscript><p class="ceo-noscript">The CEO Dashboard needs JavaScript to load its figures.</p></noscript>'
       . '<div id="ceo-app" aria-busy="true"></div></main>'
       . '<script type="application/json" id="ceo-conf">' . json_encode($conf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
       . '<script src="' . e(fabo_asset($root . 'assets/ceo.js')) . '" defer></script>';
    fabo_foot();
}
