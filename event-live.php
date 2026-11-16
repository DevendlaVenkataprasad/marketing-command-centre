<?php
/**
 * Event Organizers — the live room for a ticket holder: ask and upvote questions,
 * vote in polls. The ticket is the identity, so one ticket is one vote. The page
 * refreshes itself, and never while someone is typing (assets/eo-public.js).
 */

require_once __DIR__ . '/lib/eo/public.php';
$org = eo_public_start('ticket holder');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
$k = $org >= 0 ? eo_ticket((string) ($_GET['t'] ?? '')) : null;
$ev = $k ? eo_event((int) $k['event_id']) : null;
if (!$ev || $k['status'] !== 'issued') {
    eo_public_stop($org, 404, 'Live', 'This page is for ticket holders', 'Please open it from your ticket.');
}
$self = eo_here($org, 'event-live.php?t=' . $k['code']);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    if (fabo_throttle_blocked('ev-live', (string) $k['code'], 120, 3600)) {
        $message = fabo_throttle_words();
    } else {
        fabo_throttle_hit('ev-live', (string) $k['code']);
        try {
            switch ($_POST['action'] ?? '') {
                case 'ask':
                    eo_question_ask($k, (int) ($_POST['session'] ?? 0), (string) ($_POST['question'] ?? ''));
                    break;
                case 'up':
                    eo_question_upvote($k, (int) ($_POST['id'] ?? 0));
                    break;
                case 'vote':
                    eo_poll_vote($k, (int) ($_POST['poll'] ?? 0), (int) ($_POST['option'] ?? 0));
                    break;
            }
            header('Location: ' . $self);
            exit;
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
    }
}
eo_public_head($org, 'Live', '', true);
echo '<div id="ev-live" class="fm-head"><div class="fm-eyebrow">Live</div><h1>' . e($ev['name']) . '</h1></div>';
echo $message !== '' ? '<div class="inc-warn" role="alert">' . e($message) . '</div>' : '';
foreach (eo_sessions((int) $ev['id']) as $s) {
    echo '<section class="ev-sec"><h2>' . e($s['title']) . '</h2>';
    foreach (eo_polls((int) $s['id']) as $p) {
        $mine = inc_one('SELECT option_id FROM inc_ev_poll_votes WHERE poll_id = ? AND ticket_id = ?', 'ii', [(int) $p['id'], (int) $k['id']]);
        $total = array_sum(array_column($p['options'], 'votes'));
        echo '<div class="ev-poll"><strong>' . e($p['question']) . '</strong>';
        if ($mine || (int) $p['open_now'] !== 1) {
            foreach ($p['options'] as $o) {
                $pct = $total ? (int) round((int) $o['votes'] * 100 / $total) : 0;
                echo '<div class="ev-bar"><span>' . e($o['label']) . ' · ' . $pct . '%</span><i style="width:' . $pct . '%"></i></div>';
            }
        } else {
            echo '<form method="post">' . fabo_csrf_field() . '<input type="hidden" name="action" value="vote"><input type="hidden" name="poll" value="' . (int) $p['id'] . '">';
            foreach ($p['options'] as $o) {
                echo '<label class="ev-opt"><input type="radio" name="option" value="' . (int) $o['id'] . '" required> ' . e($o['label']) . '</label>';
            }
            echo '<p><button class="btn">Vote</button></p></form>';
        }
        echo '</div>';
    }
    echo '<ul class="ev-people">';
    foreach (eo_questions((int) $s['id']) as $q) {
        echo '<li>' . e($q['question']) . ((int) $q['answered'] === 1 ? ' <span class="pill green">Answered</span>' : '')
           . ' <form method="post" class="inc-inline">' . fabo_csrf_field() . '<input type="hidden" name="action" value="up"><input type="hidden" name="id" value="' . (int) $q['id'] . '"><button class="ev-up" aria-label="Upvote">▲ ' . (int) $q['votes'] . '</button></form></li>';
    }
    echo '</ul><form method="post" class="ev-row">' . fabo_csrf_field() . '<input type="hidden" name="action" value="ask"><input type="hidden" name="session" value="' . (int) $s['id'] . '">'
       . '<input type="text" name="question" maxlength="500" required placeholder="Ask a question" aria-label="Ask a question"><button class="btn">Ask</button></form></section>';
}
eo_public_foot($org, false);
