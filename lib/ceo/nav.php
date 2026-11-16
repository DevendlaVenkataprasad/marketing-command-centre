<?php
/**
 * CEO Dashboard in the sidebar: only for someone who holds access, and each
 * page only for someone who may use it. Light enough for every page.
 */

require_once __DIR__ . '/core.php';

function ceo_pages(): array
{
    return [
        'overview' => ['Executive overview', null],
        'spokes'   => ['Hub & spokes', null],
        'targets'  => ['Targets vs actual', null],
        'actions'  => ['Pending actions', null],
        'activity' => ['Live activity', null],
        'reports'  => ['Reports', 'report'],
        'access'   => ['Access management', 'owner'],
        'setup'    => ['KPI registry & audit', 'configure'],
    ];
}

function ceo_page_allowed(array $a, ?string $need = null): bool
{
    if ($need === null) {
        return true;
    }
    return $need === 'owner' ? !empty($a['owner']) : ceo_may($a, $need);
}

function ceo_nav_html(array $viewer, string $current): string
{
    try {
        $admin = !empty($viewer['is_admin']);
        $a = $admin ? ['owner' => true, 'actions' => array_keys(ceo_actions_all())] : ceo_access($viewer, ceo_org_of($viewer));
        if (!$a) {
            return '';
        }
    } catch (Throwable $e) {
        error_log('FABO ceo nav: ' . $e->getMessage());
        return '';
    }
    $page = str_starts_with($current, 'ceo-') ? substr($current, 4) : '';
    $h = '';
    foreach (ceo_pages() as $p => [$label, $need]) {
        if (!ceo_page_allowed($a, $need)) {
            continue;
        }
        $on = $page === $p;
        $h .= '<a class="hr-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="ceo.php?p=' . $p . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('CEO Dashboard', 'pulse', $page !== '', $h);
}
