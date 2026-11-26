<?php
/**
 * Startup Discovery in the sidebar: one group, its pages nested under it while
 * one is open. Kept apart from lib/iv/app.php so lib/ui.php can draw the menu
 * on every page without loading the module.
 */

function iv_nav_pages(): array
{
    return ['overview' => 'Overview', 'dashboards' => 'HoD dashboards', 'students' => 'Students', 'rounds' => 'Rounds & timing', 'gems' => 'Gems',
            'ideas' => 'All ideas', 'pipeline' => 'Pipeline & teams', 'import' => 'Import', 'settings' => 'Settings & audit'];
}

function iv_nav_html(string $current): string
{
    $page = str_starts_with($current, 'iv-') ? substr($current, 3) : '';
    $page = ['dashboard' => 'dashboards', 'student' => 'students', 'idea' => 'ideas'][$page] ?? $page;
    $org = isset($_GET['org']) ? '&org=' . (int) $_GET['org'] : '';
    $h = '';
    foreach (iv_nav_pages() as $p => $label) {
        $on = $page === $p;
        $h .= '<a class="it-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="idea-validation.php?p=' . $p . $org . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Startup Discovery', 'bulb', $page !== '', $h);
}
