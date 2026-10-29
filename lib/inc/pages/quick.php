<?php
/**
 * Incubation Management — an application at a glance, for the pop-up on the
 * board and the lists: who applied, how to reach them, and what they wrote on
 * their registration form. The Gem result shows only where the application's
 * stage shows it. Everything else (evaluation, assignment, notes) stays on
 * the full application page.
 */

$id = (int) ($_GET['id'] ?? 0);
$app = inc_app_need($id);
$name = $app['startup_name'] !== '' ? $app['startup_name'] : $app['applicant_name'];

inc_layout_start($name, e($app['ref']) . ' · submitted ' . e(inc_fmt_datetime($app['submitted_at'])) . ' · ' . inc_badge($app['stage_name'], 'blue'));

$contact = [
    'Applicant'       => e($app['applicant_name']),
    'Email'           => $app['applicant_email'] ? '<a href="mailto:' . e($app['applicant_email']) . '">' . e($app['applicant_email']) . '</a>' : '',
    'Phone'           => $app['applicant_phone'] ? '<a href="tel:' . e($app['applicant_phone']) . '">' . e($app['applicant_phone']) . '</a>' : '',
    'Startup'         => e($app['startup_name']),
    'Event / program' => e((string) ($app['event_name'] ?? '')),
    'Came from'       => e(inc_came_from($app)),
    // The department it sits with: its form's, or a desk it was handed to.
    'Department'      => e(implode(', ', array_filter(inc_dept_tags_for_rows([$app])[$id] ?? []))),
];
echo '<section class="card inc-quick"><dl class="inc-quickfacts">';
foreach (array_filter($contact, static fn($v) => $v !== '') as $label => $v) {
    echo '<div><dt>' . e($label) . '</dt><dd>' . $v . '</dd></div>';
}
echo '</dl></section>';

echo inc_gem_app_html($app, true);

echo '<section class="card"><h2>Registration details</h2>' . inc_app_answers_html($app) . '</section>';

inc_layout_end();
