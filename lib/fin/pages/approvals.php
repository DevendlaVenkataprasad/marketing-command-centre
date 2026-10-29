<?php
/**
 * Approvals: everything waiting for the viewer's decision, across every
 * kind of document, oldest first; and, for those who may see it, everything
 * waiting for anyone — so a stuck document is visible before it is late.
 * Decisions are made on each document's own page, where the whole of it can
 * be read first.
 */

$mine = fp_wf_inbox(200);
$type = (string) ($_GET['type'] ?? '');
if ($type !== '' && isset(fp_doc_types()[$type])) {
    $mine = array_values(array_filter($mine, static fn($m) => $m['type'] === $type));
}
fp_layout_start('Approvals', count($mine) . ' waiting for you');

$types = [];
foreach (fp_wf_inbox(200) as $m) {
    $types[$m['type']] = ($types[$m['type']] ?? 0) + 1;
}
if (count($types) > 1) {
    echo '<nav class="subtabs"><a href="' . e(fp_url('approvals')) . '"' . ($type === '' ? ' class="on"' : '') . '>All</a>';
    foreach ($types as $t => $n) {
        echo '<a href="' . e(fp_url('approvals', ['type' => $t])) . '"' . ($type === $t ? ' class="on"' : '') . '>' . e(fp_doc_types()[$t]['label']) . ' <span class="c">' . $n . '</span></a>';
    }
    echo '</nav>';
}

echo '<section class="card"><h2>Waiting for your decision</h2>';
if (!$mine) {
    echo '<p class="inc-allclear">Nothing is waiting for you.</p>';
} else {
    echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Document</th><th>Details</th><th class="num">Amount</th><th>Step</th><th>Waiting since</th><th></th></tr></thead><tbody>';
    foreach ($mine as $m) {
        $s = $m['step'];
        $age = (time() - strtotime((string) $m['since'])) / 3600;
        $flag = $m['type'] === 'bill' && ($m['doc']['match_status'] ?? '') === 'overridden' ? ' ' . inc_badge('Exception accepted', 'amber') : '';
        $flag .= !empty($m['doc']['policy_flags']) ? ' ' . inc_badge('Policy notes', 'amber') : '';
        echo '<tr><td data-l="Document"><strong>' . e(fp_doc_types()[$m['type']]['label']) . '</strong></td><td data-l="Details">' . e($m['title']) . $flag . '</td>'
           . fp_money_td($m['amount'] ?: null, 'Amount') . '<td data-l="Step">' . e($s['label'] ?: 'Level ' . $s['level']) . ($s['escalated_at'] ? ' ' . inc_badge('Escalated', 'red') : '') . '</td>'
           . '<td data-l="Waiting since" class="' . ($age > (float) fp_setting('escalate_hours') ? 'late' : '') . '">' . e(fp_fmt_dt($m['since'])) . '</td>'
           . '<td><a class="btn btn-sm btn-primary" href="' . e(fp_url(fp_doc_types()[$m['type']]['page'], ['id' => $m['doc']['id']])) . '">Review</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// The whole queue, for whoever runs Finance.
if (fp_can('reports.view') || fp_is_sysadmin()) {
    $all = fp_all('SELECT * FROM fp_wf_steps WHERE status = "pending" ORDER BY activated_at LIMIT 300');
    echo '<section class="card"><h2>Everything in approval</h2>';
    if (!$all) {
        echo '<p class="muted small">Nothing anywhere is waiting for approval.</p>';
    } else {
        echo '<div class="scrollx"><table class="data inc-table stack"><thead><tr><th>Document</th><th>Step</th><th>Approver</th><th>Waiting since</th></tr></thead><tbody>';
        foreach ($all as $s) {
            $doc = fp_doc((string) $s['doc_type'], (int) $s['doc_id']);
            if (!$doc) {
                continue;
            }
            [$people, $fb] = fp_wf_approvers($s);
            echo '<tr><td data-l="Document"><a href="' . e(fp_url(fp_doc_types()[$s['doc_type']]['page'], ['id' => $s['doc_id']])) . '">' . e(fp_doc_types()[$s['doc_type']]['label'] . ' ' . fp_doc_title((string) $s['doc_type'], $doc)) . '</a></td>'
               . '<td data-l="Step">' . e($s['label']) . '</td><td data-l="Approver">' . e($people ? implode(', ', array_map('fp_person_name', $people)) : '—') . ($fb ? ' <span class="muted small">(administrator)</span>' : '') . '</td>'
               . '<td data-l="Waiting since">' . e(fp_fmt_dt($s['activated_at'])) . ($s['escalated_at'] ? ' ' . inc_badge('Escalated', 'red') : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

fp_layout_end();
