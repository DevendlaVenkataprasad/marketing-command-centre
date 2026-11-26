<?php
/**
 * Work Management page shell: the portal's own header and sidebar, then the
 * module's app (assets/work.js), which reads and writes through api/work.php.
 * Which pages a person gets is decided here and again by the API on every call.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/nav.php';

function wm_main(array $viewer): void
{
    $ctx = wm_ctx($viewer);
    if (!$ctx) {
        fabo_deny('Work Management is available only to people the administrator has given access.', 403);
    }
    $pages = [];
    foreach (wm_pages() as $p => [$label, $roles]) {
        if (in_array($ctx['role'], $roles, true)) {
            $pages[$p] = $label;
        }
    }
    $page = (string) ($_GET['p'] ?? '');
    if (!isset($pages[$page])) {
        $page = array_key_first($pages);
    }
    fabo_head($viewer, $pages[$page] . ' · Work Management', 'wm-' . $page);
    $root = fabo_root_prefix();
    $conf = [
        'page' => $page, 'pages' => $pages, 'role' => $ctx['role'], 'me' => $ctx['id'], 'name' => $ctx['name'],
        'canComment' => $ctx['can_comment'], 'api' => $root . 'api/work.php' . (!empty($viewer['is_admin']) ? '?as=admin' : ''),
        'csrf' => fabo_csrf_token(), 'today' => wm_today(), 'statuses' => WM_STATUSES, 'priorities' => WM_PRIORITIES,
    ];
    echo '<link rel="stylesheet" href="' . e(fabo_asset($root . 'assets/work.css')) . '">'
       . '<main class="wrap-page wm" id="main" data-page="' . e($page) . '" data-role="' . e($ctx['role']) . '">'
       . '<noscript><p class="wm-noscript">Work Management needs JavaScript.</p></noscript>'
       . '<div id="wm-app" aria-busy="true"></div>'
       . '<div class="wm-toasts" id="wm-toasts" role="status" aria-live="polite"></div></main>'
       . '<script type="application/json" id="wm-conf">' . json_encode($conf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
       . '<script src="' . e(fabo_asset($root . 'assets/work.js')) . '" defer></script>';
    fabo_foot();
}
