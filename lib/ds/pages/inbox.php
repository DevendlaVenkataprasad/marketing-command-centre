<?php
/**
 * My signatures: what waits for me, what I have done, and what I sent.
 */

$tab = in_array($_GET['tab'] ?? '', ['awaiting', 'done', 'sent', 'upcoming'], true) ? $_GET['tab'] : 'awaiting';
$me = ds_me();
$counts = [
    'awaiting' => ds_my_pending_count(),
    'upcoming' => (int) fabo_one('SELECT COUNT(*) n FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id WHERE r.org_id = ? AND r.person_id = ? AND r.status = "waiting" AND d.deleted_at IS NULL AND d.status IN ("submitted","under_review","pending_first","pending","partial")', 'ii', [ds_org(), $me])['n'],
    'done' => (int) fabo_one('SELECT COUNT(*) n FROM ds_signature_requests WHERE org_id = ? AND person_id = ? AND status IN ("signed","approved","reviewed","rejected","changes")', 'ii', [ds_org(), $me])['n'],
    'sent' => (int) fabo_one('SELECT COUNT(*) n FROM ds_documents WHERE org_id = ? AND (owner_id = ? OR created_by_id = ?) AND deleted_at IS NULL AND cycle > 0', 'iii', [ds_org(), $me, $me])['n'],
];
ds_layout_start('My signatures', 'Documents waiting for you, and the ones you have sent or signed.',
    ds_can('docs.create') ? '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('new')) . '">' . ds_icon('plus') . 'New document</a>' : '');
$labels = ['awaiting' => 'Awaiting me', 'upcoming' => 'Coming to me', 'done' => 'Completed by me', 'sent' => 'Sent by me'];
echo '<nav class="ds-tabs" aria-label="Views">';
foreach ($labels as $k => $l) {
    echo '<a href="' . e(ds_url('inbox', ['tab' => $k])) . '"' . ($k === $tab ? ' class="on" aria-current="page"' : '') . '>' . e($l) . ' <span>' . $counts[$k] . '</span></a>';
}
echo '</nav>';

if ($tab === 'sent') {
    $rows = fabo_all('SELECT * FROM ds_documents WHERE org_id = ? AND (owner_id = ? OR created_by_id = ?) AND deleted_at IS NULL AND cycle > 0 ORDER BY last_activity_at DESC LIMIT 200', 'iii', [ds_org(), $me, $me]);
    if (!$rows) {
        echo ds_empty('You have not sent anything yet', 'Upload a PDF and choose who signs it.', 'send', ds_can('docs.create') ? '<a class="ds-btn ds-btn-primary" href="' . e(ds_url('new')) . '">' . ds_icon('plus') . 'New document</a>' : '');
    } else {
        echo '<div class="ds-cards">';
        foreach ($rows as $i => $d) {
            $reqs = ds_doc_requests((int) $d['id'], (int) $d['cycle']);
            $done = count(array_filter($reqs, static fn($r) => in_array($r['status'], ds_done_statuses(), true)));
            $tot = count(array_filter($reqs, static fn($r) => !in_array($r['status'], ['skipped', 'cancelled'], true)));
            $now = array_values(array_filter($reqs, static fn($r) => $r['status'] === 'pending'));
            echo '<a class="ds-doccard ds-rise" style="--i:' . min($i, 10) . '" href="' . e(ds_url('doc', ['id' => $d['id']])) . '"><div class="ds-doccard-h"><span class="ds-fileic">' . ds_icon('file') . '</span>'
               . '<div><strong>' . e($d['title']) . '</strong><small>' . e($d['ref_no']) . ' · ' . e(ds_ago($d['last_activity_at'])) . '</small></div>' . ds_status_badge($d['status']) . '</div>'
               . '<div class="ds-progress" role="img" aria-label="' . $done . ' of ' . $tot . ' done"><i style="--w:' . ($tot ? round($done / $tot * 100) : 0) . '%"></i></div>'
               . '<div class="ds-doccard-f"><span>' . $done . ' of ' . $tot . ' done</span><span class="ds-avs">';
            foreach (array_slice($now, 0, 4) as $r) {
                echo ds_req_avatar($r, 'sm');
            }
            echo ($now ? '<em>waiting</em>' : '') . '</span></div></a>';
        }
        echo '</div>';
    }
    ds_layout_end();
    return;
}

$status = ['awaiting' => '("pending")', 'upcoming' => '("waiting")', 'done' => '("signed","approved","reviewed","rejected","changes")'][$tab];
$rows = fabo_all('SELECT r.*, d.ref_no, d.title, d.priority, d.owner_id, d.status AS doc_status, d.category_id FROM ds_signature_requests r JOIN ds_documents d ON d.id = r.doc_id
                  WHERE r.org_id = ? AND r.person_id = ? AND r.status IN ' . $status . ' AND d.deleted_at IS NULL'
                  . ($tab === 'upcoming' ? ' AND d.status IN ("submitted","under_review","pending_first","pending","partial")' : '')
                  . ' ORDER BY ' . ($tab === 'done' ? 'r.acted_at DESC' : 'FIELD(d.priority,"urgent","high","normal","low"), r.due_at IS NULL, r.due_at') . ' LIMIT 300', 'ii', [ds_org(), $me]);
if (!$rows) {
    echo ds_empty(['awaiting' => 'You are all caught up', 'upcoming' => 'Nothing on its way to you', 'done' => 'Nothing signed yet'][$tab],
        $tab === 'awaiting' ? 'When a document needs your signature or approval, it appears here and you are notified.' : '', $tab === 'awaiting' ? 'check-circle' : 'inbox');
    ds_layout_end();
    return;
}
echo '<div class="ds-reqs">';
foreach ($rows as $i => $r) {
    $over = $r['status'] === 'pending' && $r['due_at'] && ds_ts($r['due_at']) < time();
    $href = $r['status'] === 'pending' ? ds_url('sign', ['id' => $r['id']]) : ds_url('doc', ['id' => $r['doc_id']]);
    echo '<a class="ds-req ds-rise' . ($over ? ' is-over' : '') . '" style="--i:' . min($i, 12) . '" href="' . e($href) . '">'
       . '<span class="ds-req-ic a-' . e($r['action']) . '">' . ds_icon(['sign' => 'sign', 'approve' => 'check', 'review' => 'eye'][$r['action']] ?? 'pen') . '</span>'
       . '<span class="ds-req-b"><strong>' . e($r['title']) . '</strong><small>' . e($r['ref_no']) . ' · from ' . e(ds_person_name((int) $r['owner_id'])) . ' · ' . e((string) $r['stage_name'])
       . ($r['on_behalf_of_id'] ? ' · on behalf of ' . e(ds_person_name((int) $r['on_behalf_of_id'])) : '') . '</small></span>'
       . '<span class="ds-req-m">' . ds_priority_chip($r['priority']) . ($tab === 'done' ? ds_req_badge($r['status']) . ds_method_chip($r['used_method']) : ds_method_chip($r['method']))
       . ($r['due_at'] && $tab !== 'done' ? '<span class="ds-due' . ($over ? ' over' : '') . '">' . ds_icon('clock') . ($over ? 'Overdue · ' : 'Due ') . e(ds_fmt_dt($r['due_at'])) . '</span>' : '')
       . ($tab === 'done' ? '<span class="ds-muted">' . e(ds_fmt_dt($r['acted_at'])) . '</span>' : '') . '</span>'
       . ($r['status'] === 'pending' ? '<span class="ds-btn ds-btn-primary ds-btn-sm">' . e(['sign' => 'Sign', 'approve' => 'Approve', 'review' => 'Review'][$r['action']] ?? 'Open') . ds_icon('chev-r') . '</span>' : '')
       . '</a>';
}
echo '</div>';
ds_layout_end();
