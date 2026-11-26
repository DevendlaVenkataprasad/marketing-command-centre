<?php
/**
 * Academic in the sidebar: one group, its pages nested under it while one is
 * open. Kept apart from lib/edu/app.php so lib/ui.php can draw the menu on
 * every page without loading the module.
 */

function edu_nav_pages(): array
{
    return ['overview' => 'Overview', 'institutions' => 'Institutions', 'people' => 'People', 'courses' => 'Courses', 'mentoring' => 'Mentoring', 'rewards' => 'Rewards', 'store' => 'Store', 'ideas' => 'Ideas & incubation', 'reports' => 'Reports', 'privacy' => 'Privacy & security', 'import' => 'Import', 'settings' => 'Campus portal & settings'];
}

function edu_nav_html(string $current): string
{
    $page = str_starts_with($current, 'edu-') ? substr($current, 4) : '';
    if ($page === 'person') {
        $page = 'people';
    }
    if ($page === 'course' || $page === 'lesson') {
        $page = 'courses';
    }
    $org = isset($_GET['org']) ? '&org=' . (int) $_GET['org'] : '';
    $h = '';
    foreach (edu_nav_pages() as $p => $label) {
        $on = $page === $p;
        $h .= '<a class="it-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="academic.php?p=' . $p . $org . '">' . e($label) . '</a>';
    }
    return fabo_nav_group('Academic', 'campus', $page !== '', $h);
}
