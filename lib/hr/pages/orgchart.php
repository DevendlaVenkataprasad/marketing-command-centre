<?php
/**
 * Org Chart — who reports to whom, drawn from each employee's reporting
 * manager. Branches open and close; a department filter keeps only the
 * branches that hold someone from it, with their managers above them.
 */

$dept = (int) ($_GET['department'] ?? 0);
[$by, $roots] = hr_org_tree($dept);

hr_layout_start('Org Chart', 'Reporting lines, from each employee\'s reporting manager. Change a manager on the employee\'s record.', ['Org Chart' => '']);

echo '<form method="get" class="card hr-filters"><input type="hidden" name="p" value="orgchart">'
   . '<div class="grp"><label for="od">Department</label><select id="od" name="department" data-autosubmit>' . hr_options(hr_departments_map(), $dept, 'Whole organisation') . '</select></div>'
   . '<div class="grp"><label>&nbsp;</label><span class="hr-acts"><button type="button" class="btn btn-sm" data-tree="open">Expand all</button>'
   . '<button type="button" class="btn btn-sm" data-tree="close">Collapse all</button></span></div>'
   . '<noscript><button class="btn btn-sm">Apply</button></noscript></form>';

/** One person and everyone below them. */
function hr_org_node(array $by, int $id, int $depth): string
{
    $r = $by[$id];
    $kids = $r['kids'];
    $span = $kids ? hr_org_span($by, $id) : 0;
    $card = '<div class="hr-node">' . hr_person_cell($r) . '<div class="role">' . e($r['designation'] ?? '') . ($r['department'] ? ' · ' . e($r['department']) : '') . '</div>'
          . ($kids ? '<div class="span muted small">' . count($kids) . ' direct · ' . $span . ' in team</div>' : '') . '</div>';
    if (!$kids || $depth > 15) {
        return '<li>' . $card . '</li>';
    }
    $h = '<li><details' . ($depth < 2 ? ' open' : '') . '><summary>' . $card . '</summary><ul>';
    foreach ($kids as $k) {
        $h .= hr_org_node($by, $k, $depth + 1);
    }
    return $h . '</ul></details></li>';
}

echo '<div class="card">';
if (!$roots) {
    echo hr_empty('Nobody to show.', ' Add employees and set each one\'s reporting manager.');
} else {
    $tops = array_filter($roots, static fn($id) => $by[$id]['kids']);
    $loose = array_diff($roots, $tops);
    echo '<ul class="hr-tree">';
    foreach ($tops as $id) {
        echo hr_org_node($by, $id, 0);
    }
    echo '</ul>';
    if ($loose) {
        echo '<details class="hr-loose"><summary><strong>' . count($loose) . ' without a reporting manager or team</strong></summary><div class="hr-loose-grid">';
        foreach ($loose as $id) {
            echo '<div class="hr-node">' . hr_person_cell($by[$id]) . '<div class="role">' . e($by[$id]['designation'] ?? '') . '</div></div>';
        }
        echo '</div></details>';
    }
}
echo '</div>';
hr_layout_end();
