<?php
/**
 * Work Management in the sidebar: the administrator always; a signed-in team
 * member or client only while their membership is active. Light enough to run
 * on every page.
 */

require_once __DIR__ . '/core.php';

/** page key => [label, roles allowed] */
function wm_pages(): array
{
    return [
        'overview'    => ['Overview', ['admin', 'team']],
        'tasks'       => ['Tasks', ['admin', 'team', 'client']],
        'departments' => ['Departments', ['admin']],
        'access'      => ['Access', ['admin']],
        'reports'     => ['Reports', ['admin', 'team', 'client']],
    ];
}

function wm_nav_html(array $viewer, string $current): string
{
    try {
        $ctx = wm_ctx($viewer);
    } catch (Throwable $e) {
        error_log('WM nav: ' . $e->getMessage());
        return '';
    }
    if (!$ctx) {
        return '';
    }
    $script = 'work.php';            // admin/work.php from admin pages, work.php from portal pages
    $page = str_starts_with($current, 'wm-') ? substr($current, 3) : '';
    $h = '';
    foreach (wm_pages() as $p => [$label, $roles]) {
        if (!in_array($ctx['role'], $roles, true)) {
            continue;
        }
        $on = $page === $p;
        $h .= '<a class="it-sub wm-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . $script . '?p=' . $p . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Work Management', 'check', $page !== '', $h);
}
